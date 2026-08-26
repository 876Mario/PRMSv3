<?php
/**
 * CronNotificationRoutingService
 * ==============================
 * Centralized recipient resolution for scheduled notification jobs.
 */
class CronNotificationRoutingService
{
    private const BRANCH_SCOPED_ROLES = ['HOD', 'Branch Head', 'Finance Officer'];

    /**
     * Inventory cron alerts must only go to active Property Management Officers.
     * If PMO-specific inventory_alert_recipients rows exist, honor their active
     * location/alert-type configuration; otherwise fall back to all PMO users.
     *
     * @return array<int,array{email:string,full_name:string,reason:string,location_id:?int}>
     */
    public static function getInventoryAlertRecipients(PDO $pdo, ?int $locationId = null, string $alertType = 'REORDER'): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id, u.email, u.full_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                  LEFT JOIN inventory_alert_recipients iar
                    ON iar.recipient_type = 'PROPERTY_MANAGEMENT_OFFICER'
                   AND iar.recipient_role_id = r.id
                   AND iar.is_active = 1
                   AND (iar.location_id IS NULL OR (? IS NULL OR iar.location_id = ?))
                   AND FIND_IN_SET(?, iar.alert_types) > 0
                 WHERE r.name = 'Property Management Officer'
                   AND u.is_active = 1
                   AND (
                       NOT EXISTS (
                           SELECT 1
                             FROM inventory_alert_recipients cfg
                            WHERE cfg.recipient_type = 'PROPERTY_MANAGEMENT_OFFICER'
                              AND cfg.is_active = 1
                       )
                       OR iar.id IS NOT NULL
                   )
            ");
            $stmt->execute([$locationId, $locationId, $alertType]);
            return self::formatRecipients(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'Property Management Officer (inventory alerts)',
                $locationId
            );
        } catch (Throwable $e) {
            error_log('CronNotificationRoutingService::getInventoryAlertRecipients config lookup failed: ' . $e->getMessage());
            return self::resolveActiveUsersByRole($pdo, 'Property Management Officer', null, 'Property Management Officer (inventory alerts)');
        }
    }

    /**
     * Resolve the active user(s) responsible for the current workflow action.
     *
     * @return array<int,array{email:string,full_name:string,reason:string,role:?string}>
     */
    public static function getOverdueActionRecipients(PDO $pdo, array $request): array
    {
        $requestId = (int)($request['request_id'] ?? 0);
        $status = strtoupper((string)($request['status'] ?? ''));
        $branchId = isset($request['branch_id']) ? (int)$request['branch_id'] : null;

        if ($requestId <= 0 || in_array($status, ['DRAFT', 'COMPLETED', 'DECLINED', 'CANCELLED', 'PAUSED'], true)) {
            return [];
        }

        $pendingRole = self::getNextPendingApprovalRole($pdo, $requestId);
        if ($pendingRole !== null) {
            return self::resolveActiveUsersByRole(
                $pdo,
                $pendingRole,
                self::isBranchScopedRole($pendingRole) ? $branchId : null,
                "Pending {$pendingRole} approval"
            );
        }

        if ($status === 'SUBMITTED') {
            $firstRole = self::getFallbackFirstApprovalRole($request);
            if ($firstRole !== null) {
                return self::resolveActiveUsersByRole(
                    $pdo,
                    $firstRole,
                    self::isBranchScopedRole($firstRole) ? $branchId : null,
                    "Pending {$firstRole} approval"
                );
            }
            return [];
        }

        return match ($status) {
            'QUOTE_REQUESTOR_REVIEW_PENDING' => self::isRequestorReviewPending($pdo, $requestId)
                ? self::resolveSpecificUser($pdo, (int)($request['created_by'] ?? 0), 'Requestor review pending')
                : [],
            'QUOTE_REQUESTOR_REVIEW_APPROVED',
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => self::isBranchHeadReviewPending($pdo, $requestId)
                ? self::resolveActiveUsersByRole($pdo, 'Branch Head', $branchId, 'Branch Head quote approval pending')
                : [],
            'HOD_APPROVED',
            'DIRECTOR_APPROVED',
            'GC_APPROVED',
            'RFQ_LETTER_AVAILABLE',
            'PROCUREMENT_STAGE',
            'EVALUATION_STAGE',
            'COMMITTEE_RECOMMENDED',
            'QUOTE_REVIEW_PENDING',
            'QUOTE_APPROVED',
            'FUNDS_VERIFIED',
            'COMMITMENT_APPROVED',
            'PO_PENDING' => self::resolveActiveUsersByRole($pdo, 'Procurement Officer', null, self::reasonForStatus($status)),
            'COMMITMENTS_PENDING',
            'INVOICE_RECEIVED' => self::resolveActiveUsersByRole($pdo, 'Finance Officer', $branchId, self::reasonForStatus($status)),
            default => [],
        };
    }

    /**
     * @return array<int,array{email:string,full_name:string,reason:string,role:string}>
     */
    public static function resolveActiveUsersByRole(PDO $pdo, string $roleName, ?int $branchId = null, ?string $reason = null): array
    {
        try {
            $params = [$roleName];
            $sql = "
                SELECT DISTINCT u.user_id, u.email, u.full_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                 WHERE r.name = ?
                   AND u.is_active = 1
            ";
            if ($branchId !== null && self::isBranchScopedRole($roleName)) {
                $sql .= " AND u.branch_id = ?";
                $params[] = $branchId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recipients = self::formatRecipients(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $reason ?? "Active {$roleName}",
                null
            );
            foreach ($recipients as &$recipient) {
                $recipient['role'] = $roleName;
            }
            unset($recipient);
            return $recipients;
        } catch (Throwable $e) {
            error_log("CronNotificationRoutingService::resolveActiveUsersByRole failed for {$roleName}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * @return array<int,array{email:string,full_name:string,reason:string,role:string}>
     */
    private static function resolveSpecificUser(PDO $pdo, int $userId, string $reason): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT user_id, email, full_name
                  FROM users
                 WHERE user_id = ?
                   AND is_active = 1
                 LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? self::formatRecipients([$row], $reason, null) : [];
        } catch (Throwable $e) {
            error_log("CronNotificationRoutingService::resolveSpecificUser failed for {$userId}: " . $e->getMessage());
            return [];
        }
    }

    private static function getNextPendingApprovalRole(PDO $pdo, int $requestId): ?string
    {
        try {
            $stmt = $pdo->prepare("
                SELECT role
                  FROM request_approvals
                 WHERE request_id = ?
                   AND status = 'pending'
                   AND (entity_type = 'REQUEST' OR entity_type IS NULL)
                 ORDER BY stage_order ASC, id ASC
                 LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $role = $stmt->fetchColumn();
            return $role !== false && $role !== '' ? (string)$role : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function getFallbackFirstApprovalRole(array $request): ?string
    {
        if (function_exists('getApprovalChain')) {
            $roles = getApprovalChain(
                (string)($request['request_type'] ?? 'REGULAR'),
                (float)($request['estimated_value'] ?? 0),
                isset($request['branch_id']) ? (int)$request['branch_id'] : null,
                $GLOBALS['pdo'] ?? null
            );
            return $roles[0] ?? null;
        }
        return null;
    }

    private static function isRequestorReviewPending(PDO $pdo, int $requestId): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT requestor_spec_review_status
                  FROM rfqs
                 WHERE request_id = ?
                 ORDER BY rfq_id DESC
                 LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $status = $stmt->fetchColumn();
            return $status !== false && strtoupper((string)$status) === 'PENDING';
        } catch (Throwable $e) {
            error_log('CronNotificationRoutingService::isRequestorReviewPending failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function isBranchHeadReviewPending(PDO $pdo, int $requestId): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT branch_head_approval_status
                  FROM rfqs
                 WHERE request_id = ?
                 ORDER BY rfq_id DESC
                 LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $status = $stmt->fetchColumn();
            return $status !== false && strtoupper((string)$status) === 'PENDING';
        } catch (Throwable $e) {
            error_log('CronNotificationRoutingService::isBranchHeadReviewPending failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function isBranchScopedRole(string $roleName): bool
    {
        return in_array($roleName, self::BRANCH_SCOPED_ROLES, true);
    }

    private static function reasonForStatus(string $status): string
    {
        return match ($status) {
            'RFQ_LETTER_AVAILABLE' => 'Procurement Officer to prepare RFQ letters',
            'PROCUREMENT_STAGE' => 'Procurement Officer procurement action pending',
            'EVALUATION_STAGE' => 'Procurement Officer evaluation coordination pending',
            'COMMITTEE_RECOMMENDED' => 'Procurement Officer post-committee action pending',
            'QUOTE_REVIEW_PENDING' => 'Procurement Officer quote review pending',
            'QUOTE_APPROVED' => 'Procurement Officer commitment action pending',
            'FUNDS_VERIFIED' => 'Procurement Officer commitment form action pending',
            'COMMITMENTS_PENDING' => 'Finance Officer commitment creation pending',
            'COMMITMENT_APPROVED' => 'Procurement Officer PO/invoice action pending',
            'PO_PENDING' => 'Procurement Officer invoice upload/action pending',
            'INVOICE_RECEIVED' => 'Finance Officer payment processing pending',
            default => 'Workflow action pending',
        };
    }

    /**
     * @return array<int,array{email:string,full_name:string,reason:string,location_id:?int}>
     */
    private static function formatRecipients(array $rows, string $reason, ?int $locationId): array
    {
        $recipients = [];
        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0 || empty($row['email'])) {
                continue;
            }
            $recipients[$userId] = [
                'email' => (string)$row['email'],
                'full_name' => (string)($row['full_name'] ?? 'User'),
                'reason' => $reason,
                'location_id' => $locationId,
            ];
        }
        return $recipients;
    }
}
