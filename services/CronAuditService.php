<?php
/**
 * CronAuditService
 * =================
 * Provides utilities for cron jobs to:
 * 1. Acquire/release execution locks (prevent concurrent runs)
 * 2. Log execution start/completion with status
 * 3. Track recipient selection decisions for audit trail
 * 4. Deduplicate notifications across multiple runs
 *
 * All methods are static; global $pdo is expected.
 */

class CronAuditService
{
    const DEFAULT_LOCK_TIMEOUT_SECONDS = 600; // 10 minutes

    // -----------------------------------------------------------------------
    // Execution Lock Management
    // -----------------------------------------------------------------------

    /**
     * Attempt to acquire an exclusive lock for a named cron job.
     * Returns lock ID on success, or null if lock already held (by another process).
     * The caller must release the lock when done.
     *
     * @param string $cronName Name of the cron job (e.g., 'overdue_alerts')
     * @param int $timeoutSeconds How long the lock should be held
     * @return int|null Lock ID if acquired, null if lock exists
     */
    public static function acquireLock(string $cronName, int $timeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS): ?int
    {
        global $pdo;
        if (!$pdo) return null;

        try {
            // Clean up expired locks first
            $pdo->prepare("
                DELETE FROM cron_execution_locks
                WHERE cron_name = ?
                  AND TIMESTAMPDIFF(SECOND, locked_at, NOW()) > expected_duration_seconds
            ")->execute([$cronName]);

            // Attempt to insert new lock (will fail if one already exists due to UNIQUE constraint)
            $stmt = $pdo->prepare("
                INSERT INTO cron_execution_locks
                    (cron_name, expected_duration_seconds, executed_by)
                VALUES (?, ?, ?)
            ");

            $executedBy = (php_uname('n') ?? 'unknown') . ':' . (getmypid() ?? 'no-pid');
            $stmt->execute([$cronName, $timeoutSeconds, $executedBy]);

            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("CronAuditService::acquireLock failed for '{$cronName}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Release a cron execution lock.
     *
     * @param int $lockId Lock ID returned by acquireLock()
     * @return bool
     */
    public static function releaseLock(int $lockId): bool
    {
        global $pdo;
        if (!$pdo || $lockId <= 0) return false;

        try {
            $pdo->prepare("DELETE FROM cron_execution_locks WHERE id = ?")->execute([$lockId]);
            return true;
        } catch (Throwable $e) {
            error_log("CronAuditService::releaseLock error: " . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Execution Logging
    // -----------------------------------------------------------------------

    /**
     * Start logging a cron execution.
     * Returns execution_id to use in logRecipient() and completeExecution() calls.
     *
     * @param string $cronName
     * @return int|null Execution ID, or null on error
     */
    public static function startExecution(string $cronName): ?int
    {
        global $pdo;
        if (!$pdo) return null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO cron_execution_log (cron_name, status)
                VALUES (?, 'RUNNING')
            ");
            $stmt->execute([$cronName]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("CronAuditService::startExecution error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark cron execution as complete with final status and summary.
     *
     * @param int $executionId
     * @param string $status One of: SUCCESS, PARTIAL_FAILURE, FAILED
     * @param int $requestsProcessed
     * @param int $recipientsFound
     * @param int $notificationsCreated
     * @param int $notificationsFailed
     * @param string|null $errorMessage
     * @param string|null $notes
     */
    public static function completeExecution(
        int $executionId,
        string $status,
        int $requestsProcessed = 0,
        int $recipientsFound = 0,
        int $notificationsCreated = 0,
        int $notificationsFailed = 0,
        ?string $errorMessage = null,
        ?string $notes = null
    ): void {
        global $pdo;
        if (!$pdo || $executionId <= 0) return;

        try {
            $status = strtoupper($status);
            if (!in_array($status, ['SUCCESS', 'PARTIAL_FAILURE', 'FAILED'], true)) {
                $status = 'FAILED';
            }

            $durationMs = null;
            // Calculate duration if we can query the start time (expensive, skip for now)

            $stmt = $pdo->prepare("
                UPDATE cron_execution_log
                SET status = ?,
                    completed_at = NOW(),
                    requests_processed = ?,
                    recipients_found = ?,
                    notifications_created = ?,
                    notifications_failed = ?,
                    error_message = ?,
                    execution_notes = ?,
                    duration_ms = TIMESTAMPDIFF(MILLISECOND, started_at, NOW())
                WHERE id = ?
            ");
            $stmt->execute([
                $status,
                $requestsProcessed,
                $recipientsFound,
                $notificationsCreated,
                $notificationsFailed,
                $errorMessage,
                $notes,
                $executionId,
            ]);
        } catch (Throwable $e) {
            error_log("CronAuditService::completeExecution error: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Recipient Audit Trail
    // -----------------------------------------------------------------------

    /**
     * Record that a recipient was selected for an alert during this cron run.
     *
     * @param int $executionId From startExecution()
     * @param int|null $requestId The request being alerted on (procurement_request_id, inv_item_id, etc.)
     * @param string $requestType Type of request: PROCUREMENT, INVENTORY_ITEM, etc.
     * @param string|null $requestRef Reference number for human readability
     * @param int|null $branchId Branch context (for procurement)
     * @param int|null $locationId Location context (for inventory)
     * @param int $recipientUserId The user to be notified
     * @param string $reason Why this user was selected (for audit)
     * @param bool $deduped Whether this notification was deduplicated (already sent today)
     * @param int|null $duplicateOfAuditId If deduped, which audit entry it duplicates
     * @return int|null Audit ID for later reference
     */
    public static function logRecipient(
        int $executionId,
        ?int $requestId,
        string $requestType,
        ?string $requestRef,
        ?int $branchId,
        ?int $locationId,
        int $recipientUserId,
        string $reason,
        bool $deduped = false,
        ?int $duplicateOfAuditId = null
    ): ?int {
        global $pdo;
        if (!$pdo || $executionId <= 0 || $recipientUserId <= 0) return null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO cron_recipient_audit
                    (execution_id, request_id, request_type, request_ref, branch_id, location_id,
                     recipient_user_id, recipient_reason, deduped, duplicate_of_audit_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $executionId,
                $requestId,
                $requestType,
                $requestRef,
                $branchId,
                $locationId,
                $recipientUserId,
                substr($reason, 0, 255), // Truncate for safety
                $deduped ? 1 : 0,
                $duplicateOfAuditId,
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("CronAuditService::logRecipient error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Link a created notification to an audit entry.
     * Call after successfully creating a user_notification for traceability.
     *
     * @param int $auditId From logRecipient()
     * @param int $notificationId From user_notifications.id
     */
    public static function linkNotification(int $auditId, int $notificationId): void
    {
        global $pdo;
        if (!$pdo || $auditId <= 0 || $notificationId <= 0) return;

        try {
            $pdo->prepare("
                UPDATE cron_recipient_audit
                SET notification_id = ?
                WHERE id = ?
            ")->execute([$notificationId, $auditId]);
        } catch (Throwable $e) {
            error_log("CronAuditService::linkNotification error: " . $e->getMessage());
        }
    }

    /**
     * Link an email delivery log to an audit entry.
     * Call if email notification was also sent.
     *
     * @param int $auditId
     * @param int $emailLogId From email_notification_log.id
     * @param bool $sent Whether the email was successfully sent
     */
    public static function linkEmailLog(int $auditId, int $emailLogId, bool $sent = true): void
    {
        global $pdo;
        if (!$pdo || $auditId <= 0 || $emailLogId <= 0) return;

        try {
            $pdo->prepare("
                UPDATE cron_recipient_audit
                SET email_log_id = ?, email_sent = ?
                WHERE id = ?
            ")->execute([$emailLogId, $sent ? 1 : 0, $auditId]);
        } catch (Throwable $e) {
            error_log("CronAuditService::linkEmailLog error: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Recipient Resolution (Common patterns)
    // -----------------------------------------------------------------------

    /**
     * Resolve configured procurement alert recipients for a branch.
     * Returns array of [user_id => ['email' => ..., 'full_name' => ..., 'reason' => ...]]
     *
     * @param int $branchId
     * @return array
     */
    public static function getProcurementAlertRecipients(int $branchId): array
    {
        global $pdo;
        if (!$pdo) return [];

        $recipients = [];

        try {
            // Query configured recipients for this branch
            $stmt = $pdo->prepare("
                SELECT DISTINCT par.id as config_id, par.recipient_type,
                       u.user_id, u.email, u.full_name
                FROM procurement_alert_recipients par
                LEFT JOIN users u ON (
                    (par.recipient_type = 'USER' AND u.user_id = par.recipient_user_id)
                    OR (par.recipient_type IN ('ROLE', 'BRANCH_HEAD', 'HOD') 
                        AND u.role_id = par.recipient_role_id 
                        AND u.branch_id = par.branch_id)
                )
                WHERE par.branch_id = ? AND par.is_active = 1 AND u.is_active = 1
            ");
            $stmt->execute([$branchId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userId = (int)$row['user_id'];
                $reason = match ($row['recipient_type']) {
                    'USER' => "Configured procurement alert user",
                    'BRANCH_HEAD' => "Branch Head of Branch ID {$branchId}",
                    'HOD' => "HOD of Branch ID {$branchId}",
                    'ROLE' => "User with configured role for Branch ID {$branchId}",
                    default => "Configured recipient",
                };

                if (!isset($recipients[$userId])) {
                    $recipients[$userId] = [
                        'email' => $row['email'],
                        'full_name' => $row['full_name'],
                        'reason' => $reason,
                        'branch_id' => $branchId,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log("CronAuditService::getProcurementAlertRecipients error: " . $e->getMessage());
        }

        return $recipients;
    }

    /**
     * Resolve configured inventory alert recipients for a location (or all if location_id=null).
     * Returns array of [user_id => ['email' => ..., 'full_name' => ..., 'reason' => ...]]
     *
     * @param int|null $locationId Specific location, or null for all
     * @param string $alertType One of: REORDER, EXPIRING_30, EXPIRING_7, EXPIRED, PENDING_APPROVAL, OPEN_INCIDENT
     * @return array
     */
    public static function getInventoryAlertRecipients(?int $locationId = null, string $alertType = 'REORDER'): array
    {
        global $pdo;
        if (!$pdo) return [];

        $recipients = [];

        try {
            $query = "
                SELECT DISTINCT iar.id as config_id, iar.recipient_type,
                       u.user_id, u.email, u.full_name
                FROM inventory_alert_recipients iar
                LEFT JOIN users u ON (
                    (iar.recipient_type = 'USER' AND u.user_id = iar.recipient_user_id)
                    OR (iar.recipient_type IN ('ROLE', 'PROPERTY_MANAGEMENT_OFFICER') 
                        AND u.role_id = iar.recipient_role_id)
                )
                WHERE (iar.location_id IS NULL OR iar.location_id = ?)
                  AND iar.is_active = 1
                  AND u.is_active = 1
                  AND FIND_IN_SET(?, iar.alert_types) > 0
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$locationId, $alertType]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userId = (int)$row['user_id'];
                $reason = match ($row['recipient_type']) {
                    'USER' => "Configured inventory alert user",
                    'ROLE' => "User with configured role for inventory alerts",
                    'PROPERTY_MANAGEMENT_OFFICER' => "Property Management Officer (inventory alerts)",
                    default => "Configured recipient",
                };

                if (!isset($recipients[$userId])) {
                    $recipients[$userId] = [
                        'email' => $row['email'],
                        'full_name' => $row['full_name'],
                        'reason' => $reason,
                        'location_id' => $locationId,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log("CronAuditService::getInventoryAlertRecipients error: " . $e->getMessage());
        }

        return $recipients;
    }
}
