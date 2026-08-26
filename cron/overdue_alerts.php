<?php
/**
 * Overdue Alerts Cron (FIXED - 2026-08-19)
 * ==========================================
 * Recommended schedule: 0 8 * * *  (daily at 08:00)
 *
 * Functionality:
 * 1. Reminds users with outstanding procurement workflow actions after
 *    the configured `reminder_interval_days` (default 3).
 * 2. Escalates to the responsible officer's supervisor / branch-head after
 *    `escalation_threshold_days` (default 7).
 * 3. Deduplicates via the reminder_log table (one reminder AND one escalation
 *    per request/user/type per calendar day).
 * 4. Retains the original overdue-invoice alert for finance.
 *
 * FIXES (2026-08-19):
 *   ✓ Recipients now filtered by branch context (NOT all users with role)
 *   ✓ Uses CronAuditService for execution locking and recipient tracking
 *   ✓ Configurable recipient selection via procurement_alert_recipients table
 *   ✓ Complete audit trail for compliance and debugging
 *   ✓ Deduplication at recipient-selection level (not just per-day)
 *   ✓ Prevents duplicate cron execution via lock mechanism
 *   ✓ Only notifies assigned officer + supervisor/Branch Head (not all)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/workflow.php';

if (!class_exists('NotificationService')) {
    $notificationServicePath = __DIR__ . '/../services/NotificationService.php';
    if (file_exists($notificationServicePath)) {
        require_once $notificationServicePath;
    } else {
        class NotificationService {
            public static function createNotification(int $userId, string $type, array $data): bool { return false; }
            public static function getUnreadCount(int $userId): int { return 0; }
            public static function getNotifications(int $userId, int $limit = 10): array { return []; }
            public static function markAsRead(int $notificationId): bool { return false; }
            public static function deleteNotification(int $notificationId): bool { return false; }
            public static function getUnread(int $userId): array { return []; }
            public static function getAll(int $userId, int $limit = 50): array { return []; }
            public static function countUnread(int $userId): int { return 0; }
            public static function markAllAsRead(int $userId): bool { return false; }
        }
        error_log("Warning: NotificationService.php not found. Using stub class.");
    }
}

if (!class_exists('CronAuditService')) {
    $cronAuditPath = __DIR__ . '/../services/CronAuditService.php';
    if (file_exists($cronAuditPath)) {
        require_once $cronAuditPath;
    } else {
        die("FATAL: CronAuditService.php not found at {$cronAuditPath}\n");
    }
}

if (!class_exists('CronNotificationRoutingService')) {
    $routingServicePath = __DIR__ . '/../services/CronNotificationRoutingService.php';
    if (file_exists($routingServicePath)) {
        require_once $routingServicePath;
    } else {
        die("FATAL: CronNotificationRoutingService.php not found at {$routingServicePath}\n");
    }
}

/* ─── Helper: fetch config values ─────────────────────────────────────── */
function cronConfig(PDO $pdo, string $key, string $default): string {
    try {
        $s = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v !== false && $v !== '') ? $v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/* ─── Helper: send a plain-text email ─────────────────────────────────── */
function cronSendEmail(string $to, string $subject, string $body): bool {
    $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $full = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif;color:#333;line-height:1.6;'>"
          . "<div style='max-width:600px;margin:0 auto;'>"
          . "<div style='background:linear-gradient(90deg,#0b5e2b,#c9a227);color:#fff;padding:16px 20px;border-radius:4px 4px 0 0;'>"
          . "<strong>DGC PRMS – Automated Alert</strong></div>"
          . "<div style='padding:20px;border:1px solid #ddd;border-radius:0 0 4px 4px;'>"
          . $html
          . "</div></div></body></html>";
    try {
        return sendMail($to, $subject, $full);
    } catch (Throwable $e) {
        error_log("cronSendEmail failed [{$to}]: " . $e->getMessage());
        return false;
    }
}

/* ─── Helper: has a reminder/escalation already been sent today? ───────── */
function reminderAlreadySent(PDO $pdo, int $requestId, int $userId, string $type): bool {
    try {
        $s = $pdo->prepare("
            SELECT COUNT(*) FROM reminder_log
            WHERE request_id = ?
              AND user_id    = ?
              AND reminder_type = ?
              AND DATE(sent_at) = CURDATE()
        ");
        $s->execute([$requestId, $userId, $type]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log("reminderAlreadySent check error: " . $e->getMessage());
        return false;
    }
}

function logReminder(PDO $pdo, int $requestId, int $userId, string $type): void {
    try {
        $pdo->prepare("
            INSERT IGNORE INTO reminder_log (request_id, user_id, reminder_type)
            VALUES (?, ?, ?)
        ")->execute([$requestId, $userId, $type]);
    } catch (Throwable $e) {
        error_log("logReminder insert error: " . $e->getMessage());
    }
}

/* ─── Helper: find supervisor email for escalation ────────────────────── */
function getSupervisorEmail(PDO $pdo, int $userId): ?string {
    try {
        // 1. supervisor_id on the user row (if set)
        $s = $pdo->prepare("
            SELECT u2.email, u2.user_id
            FROM users u
            JOIN users u2 ON u2.user_id = u.supervisor_id
            WHERE u.user_id = ? AND u2.is_active = 1
            LIMIT 1
        ");
        $s->execute([$userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['email'])) {
            return $row['email'];
        }

        // 2. Fallback: branch HOD/Branch Head (from SAME branch as the user)
        $s = $pdo->prepare("
            SELECT u2.email
            FROM users u
            INNER JOIN branches b   ON b.branch_id = u.branch_id
            INNER JOIN users u2     ON u2.branch_id = b.branch_id
            INNER JOIN roles r2     ON r2.id = u2.role_id AND r2.name IN ('HOD','Branch Head')
            WHERE u.user_id = ? AND u2.is_active = 1
            LIMIT 1
        ");
        $s->execute([$userId]);
        $fallback = $s->fetchColumn();
        return $fallback ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ─── Read config ─────────────────────────────────────────────────────── */
$reminderDays    = max(1, (int)cronConfig($pdo, 'reminder_interval_days',    '3'));
$escalationDays  = max(1, (int)cronConfig($pdo, 'escalation_threshold_days', '7'));
$appUrl          = defined('APP_URL') ? APP_URL : 'http://localhost';

echo "[" . date('Y-m-d H:i:s') . "] Overdue-alerts cron started. "
   . "Reminder: {$reminderDays}d  Escalation: {$escalationDays}d\n";

/* ─── Acquire execution lock (prevent concurrent runs) ──────────────────── */
$cronName = 'overdue_alerts';
$lockId = CronAuditService::acquireLock($cronName, 600);
if ($lockId === null) {
    echo "[" . date('H:i:s') . "] ERROR: Could not acquire lock for {$cronName}. "
       . "Another instance may be running. Exiting to prevent duplicate notifications.\n";
    exit(1);
}

/* ─── Start execution audit log ───────────────────────────────────────── */
$executionId = CronAuditService::startExecution($cronName);
if ($executionId === null) {
    echo "[" . date('H:i:s') . "] ERROR: Could not start execution audit log. Exiting.\n";
    CronAuditService::releaseLock($lockId);
    exit(1);
}

$requestsProcessed = 0;
$recipientsFound = 0;
$notificationsCreated = 0;
$notificationsFailed = 0;
$errorMessages = [];

try {
    /* ═══════════════════════════════════════════════════════════════════
       PART A – Workflow-stage reminders and escalations
       Find requests stuck at a pending-action stage for ≥ reminderDays
    ═══════════════════════════════════════════════════════════════════ */

    $actionableStatuses = [
        'SUBMITTED',
        'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED',
        'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
        'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING',
        'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
        'QUOTE_APPROVED',
        'COMMITTEE_RECOMMENDED',
        'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED',
        'PO_PENDING', 'INVOICE_RECEIVED',
    ];

    $placeholders = implode(',', array_fill(0, count($actionableStatuses), '?'));
    $stuckStmt = $pdo->prepare("
        SELECT
            pr.request_id,
            pr.request_number,
            pr.status,
            pr.updated_at,
            pr.estimated_value,
            pr.currency,
            pr.request_type,
            pr.created_by,
            pr.branch_id,
            b.branch_name,
            uc.full_name AS requestor_name,
            DATEDIFF(NOW(), pr.updated_at) AS days_idle
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users    uc ON pr.created_by = uc.user_id
        WHERE UPPER(pr.status) IN ({$placeholders})
          AND DATEDIFF(NOW(), pr.updated_at) >= ?
        ORDER BY days_idle DESC
    ");
    $stuckStmt->execute(array_merge($actionableStatuses, [$reminderDays]));
    $stuckRequests = $stuckStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "[" . date('H:i:s') . "] Found " . count($stuckRequests) . " stuck request(s).\n";

    foreach ($stuckRequests as $req) {
        $requestId  = (int)$req['request_id'];
        $branchId   = (int)($req['branch_id'] ?? 0);
        $daysIdle   = (int)$req['days_idle'];
        $statusUp   = strtoupper($req['status']);
        $requestsProcessed++;

        // ── Resolve only the current workflow action owner(s) ───────────────
        $recipients = CronAuditService::getOverdueActionRecipients($req);

        if (empty($recipients)) {
            // Log as skipped (no recipients configured)
            echo "[" . date('H:i:s') . "] SKIP: Request {$req['request_number']} — "
               . "no active pending-action owner resolved for status {$req['status']}\n";
            $errorMessages[] = "Request {$req['request_number']}: No pending-action owner for {$req['status']}";
            continue;
        }

        $recipientsFound += count($recipients);

        foreach ($recipients as $userId => $recipientInfo) {
            $userEmail = $recipientInfo['email'] ?? '';
            $userName  = $recipientInfo['full_name'] ?? 'User';
            $reason    = $recipientInfo['reason'] ?? 'Unknown';

            if (!$userEmail) continue;

            // ── Reminder ────────────────────────────────────────────────────
            if (!reminderAlreadySent($pdo, $requestId, (int)$userId, 'reminder')) {
                $subject = "Reminder: Pending Action Required — {$req['request_number']}";
                $body    = "Dear {$userName},\n\n"
                         . "The following procurement request has been waiting for your action for {$daysIdle} day(s).\n\n"
                         . "Request Ref  : {$req['request_number']}\n"
                         . "Requestor    : {$req['requestor_name']}\n"
                         . "Unit         : {$req['branch_name']}\n"
                         . "Current Stage: {$req['status']}\n"
                         . "Value        : {$req['currency']} " . number_format((float)$req['estimated_value'], 2) . "\n"
                         . "Days Idle    : {$daysIdle}\n"
                         . "Recipient    : {$reason}\n\n"
                         . "Please log in and take the required action:\n"
                         . "{$appUrl}/procurement/view.php?id={$requestId}\n\n"
                         . "This is an automated reminder. Please do not reply.";

                if (cronSendEmail($userEmail, $subject, $body)) {
                    logReminder($pdo, $requestId, (int)$userId, 'reminder');

                    // Create in-app notification
                    $notifCreated = NotificationService::createNotification((int)$userId, NotificationService::TYPE_APPROVAL_NEEDED, [
                        'title'          => "⏰ Reminder: Action Required — {$req['request_number']}",
                        'body'           => "Idle for {$daysIdle} day(s) at stage {$req['status']}.",
                        'request_id'     => $requestId,
                        'request_ref'    => $req['request_number'],
                        'action_url'     => "/procurement/view.php?id={$requestId}",
                        'stage'          => $req['status'],
                        'requestor_name' => $req['requestor_name'],
                        'priority'       => $daysIdle >= $escalationDays ? 'urgent' : 'high',
                    ]);

                    // Audit log
                    $auditId = CronAuditService::logRecipient(
                        $executionId, $requestId, 'PROCUREMENT', $req['request_number'],
                        $branchId, null, (int)$userId, $reason, false, null
                    );
                    if ($notifCreated && $auditId) {
                        $notificationsCreated++;
                    }

                    echo "[" . date('H:i:s') . "] Reminder sent → user {$userId} ({$userName}) for request {$requestId}\n";
                } else {
                    $notificationsFailed++;
                    echo "[" . date('H:i:s') . "] Reminder FAILED for user {$userId} ({$userName})\n";
                }
            }

            // ── Escalation ──────────────────────────────────────────────────
            if ($daysIdle >= $escalationDays && !reminderAlreadySent($pdo, $requestId, (int)$userId, 'escalation')) {
                $supervisorEmail = getSupervisorEmail($pdo, (int)$userId);
                if ($supervisorEmail) {
                    $subject = "ESCALATION: Overdue Action — {$req['request_number']}";
                    $body    = "Dear Supervisor,\n\n"
                             . "The following procurement request has exceeded the configured response threshold ({$escalationDays} days).\n"
                             . "The responsible officer has NOT yet completed their required action.\n\n"
                             . "Request Ref       : {$req['request_number']}\n"
                             . "Requestor         : {$req['requestor_name']}\n"
                             . "Unit              : {$req['branch_name']}\n"
                             . "Current Stage     : {$req['status']}\n"
                             . "Assigned Officer  : {$userName} ({$reason})\n"
                             . "Value             : {$req['currency']} " . number_format((float)$req['estimated_value'], 2) . "\n"
                             . "Days Idle         : {$daysIdle}\n\n"
                             . "Please follow up with the officer or take the necessary action:\n"
                             . "{$appUrl}/procurement/view.php?id={$requestId}\n\n"
                             . "This is an automated escalation notification.";

                    if (cronSendEmail($supervisorEmail, $subject, $body)) {
                        logReminder($pdo, $requestId, (int)$userId, 'escalation');
                        echo "[" . date('H:i:s') . "] Escalation sent → supervisor for user {$userId}\n";
                    }
                }
            }
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       PART B – Original overdue invoice alert (preserved)
    ═══════════════════════════════════════════════════════════════════ */

    $invoices = $pdo->query("
        SELECT i.invoice_number, i.invoice_date, po.po_number
        FROM invoices i
        JOIN purchase_orders po ON i.po_id = po.po_id
        WHERE i.status != 'Paid'
          AND i.invoice_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchAll();

    if ($invoices) {
        $message = "Overdue Invoices (unpaid > 30 days):\n\n";
        foreach ($invoices as $inv) {
            $message .= "Invoice {$inv['invoice_number']} (PO {$inv['po_number']}) — due {$inv['invoice_date']}\n";
        }
        $financeRecipients = class_exists('CronNotificationRoutingService')
            ? CronNotificationRoutingService::resolveActiveUsersByRole($pdo, 'Finance Officer', null, 'Finance Officer overdue invoice review')
            : [];

        if (empty($financeRecipients)) {
            $errorMessages[] = 'Overdue invoices found but no active Finance Officer recipients';
            echo "[" . date('H:i:s') . "] SKIP: No active Finance Officer recipients for overdue invoice alert.\n";
        } else {
            foreach ($financeRecipients as $financeUserId => $financeRecipient) {
                if (cronSendEmail($financeRecipient['email'], "Overdue Invoice Alert", $message)) {
                    $recipientsFound++;
                    $notificationsCreated++;
                    CronAuditService::logRecipient(
                        $executionId, null, 'OVERDUE_INVOICE', null,
                        null, null, (int)$financeUserId, $financeRecipient['reason'] ?? 'Finance Officer overdue invoice review', false, null
                    );
                } else {
                    $notificationsFailed++;
                }
            }
            echo "[" . date('H:i:s') . "] Invoice alert sent to " . count($financeRecipients) . " Finance Officer recipient(s) (" . count($invoices) . " overdue).\n";
        }
    }

    // Complete execution with success status
    $notes = "Processed {$requestsProcessed} requests, found {$recipientsFound} recipients, created {$notificationsCreated} notifications";
    if (!empty($errorMessages)) {
        $notes .= "; Issues: " . implode("; ", array_slice($errorMessages, 0, 3));
    }

    CronAuditService::completeExecution(
        $executionId,
        'SUCCESS',
        $requestsProcessed,
        $recipientsFound,
        $notificationsCreated,
        $notificationsFailed,
        null,
        $notes
    );

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    echo "[" . date('H:i:s') . "] FATAL ERROR: {$errorMsg}\n";
    CronAuditService::completeExecution(
        $executionId,
        'FAILED',
        $requestsProcessed,
        $recipientsFound,
        $notificationsCreated,
        $notificationsFailed,
        $errorMsg,
        "Exception during execution"
    );
} finally {
    // Release lock
    CronAuditService::releaseLock($lockId);
}

echo "[" . date('Y-m-d H:i:s') . "] Overdue-alerts cron completed. "
   . "Created {$notificationsCreated}/{$recipientsFound} notifications.\n";
