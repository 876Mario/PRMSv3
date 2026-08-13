<?php
/**
 * FinanceNotificationService
 * ==========================
 * Manages notifications for Finance users when their action is required.
 * Tracks notification state and prevents duplicate notifications for outstanding actions.
 *
 * All public methods are static so callers don't need to instantiate the class;
 * a global $pdo is expected.
 */
class FinanceNotificationService
{
    // Finance action types that trigger notifications
    const ACTION_FUNDS_VERIFICATION = 'funds_verification';
    const ACTION_COMMITMENT_UPLOAD  = 'commitment_upload';
    const ACTION_COMMITMENT_APPROVAL = 'commitment_approval';
    const ACTION_COMMITMENT_DECLINE = 'commitment_decline';
    const ACTION_INVOICE_PAYMENT    = 'invoice_payment';
    const ACTION_PO_APPROVAL        = 'po_approval';

    /**
     * Trigger finance notification if action is required and no outstanding notification exists.
     *
     * @param int    $requestId     The procurement request ID
     * @param string $actionType    One of the ACTION_* constants
     * @param array  $requestData   Request details (request_number, description, estimated_value, currency, vendor_name, etc.)
     * @return bool True if notification was created or already exists
     */
    public static function triggerNotification(int $requestId, string $actionType, array $requestData = []): bool
    {
        global $pdo;
        if (!$pdo || $requestId <= 0) {
            return false;
        }

        // Get Finance users (Finance Officer, Accounts Officer roles)
        $financeUsers = self::getFinanceUsers();
        if (empty($financeUsers)) {
            error_log("FinanceNotificationService: No finance users found for notifications");
            return false;
        }

        // Check if an outstanding notification already exists for this request and action
        if (self::hasOutstandingNotification($requestId, $actionType)) {
            return true; // Notification already exists, prevent duplicate
        }

        // Prepare notification data
        $title = self::getNotificationTitle($actionType, $requestData);
        $body = self::getNotificationBody($actionType, $requestData);
        $priority = self::getNotificationPriority($actionType);

        // Create notification for each finance user
        $created = false;
        foreach ($financeUsers as $userId) {
            $result = \NotificationService::createNotification($userId, \NotificationService::TYPE_FINANCE_ACTION, [
                'title'          => $title,
                'body'           => $body,
                'request_id'     => $requestId,
                'request_ref'    => $requestData['request_number'] ?? 'N/A',
                'action_url'     => '/procurement/view.php?id=' . $requestId,
                'stage'          => $actionType,
                'requestor_name' => $requestData['requestor_name'] ?? 'Unknown',
                'priority'       => $priority,
            ]);
            
            if ($result) {
                $created = true;
                // Log notification event in audit trail
                self::logNotificationEvent($pdo, $requestId, $actionType, $userId, 'SENT');
            }
        }

        return $created;
    }

    /**
     * Get all Finance users (Finance Officer, Accounts Officer).
     *
     * @return array Array of user IDs
     */
    private static function getFinanceUsers(): array
    {
        global $pdo;
        if (!$pdo) return [];

        try {
            // Get users with Finance Officer or Accounts Officer role
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.user_id
                FROM users u
                INNER JOIN user_roles ur ON u.user_id = ur.user_id
                INNER JOIN roles r ON ur.role_id = r.role_id
                WHERE r.role_name IN ('Finance Officer', 'Accounts Officer')
                AND u.is_active = 1
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log("FinanceNotificationService::getFinanceUsers error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if an outstanding (unread) notification exists for this request and action.
     *
     * @param int    $requestId  The procurement request ID
     * @param string $actionType The action type (one of ACTION_* constants)
     * @return bool
     */
    private static function hasOutstandingNotification(int $requestId, string $actionType): bool
    {
        global $pdo;
        if (!$pdo) return false;

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM user_notifications
                WHERE request_id = ?
                AND type = ?
                AND stage = ?
                AND is_read = 0
            ");
            $stmt->execute([$requestId, \NotificationService::TYPE_FINANCE_ACTION, $actionType]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log("FinanceNotificationService::hasOutstandingNotification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notification title based on action type and request data.
     *
     * @param string $actionType
     * @param array  $requestData
     * @return string
     */
    private static function getNotificationTitle(string $actionType, array $requestData): string
    {
        $requestNum = $requestData['request_number'] ?? 'Request';
        
        return match ($actionType) {
            self::ACTION_FUNDS_VERIFICATION => "Funds Verification Required - $requestNum",
            self::ACTION_COMMITMENT_UPLOAD => "Commitment Form Needed - $requestNum",
            self::ACTION_COMMITMENT_APPROVAL => "Commitment Approval Needed - $requestNum",
            self::ACTION_COMMITMENT_DECLINE => "Funds Unavailable - $requestNum",
            self::ACTION_INVOICE_PAYMENT => "Invoice Ready for Payment - $requestNum",
            self::ACTION_PO_APPROVAL => "PO Approval Needed - $requestNum",
            default => "Finance Action Required - $requestNum",
        };
    }

    /**
     * Get notification body based on action type and request data.
     *
     * @param string $actionType
     * @param array  $requestData
     * @return string
     */
    private static function getNotificationBody(string $actionType, array $requestData): string
    {
        $vendor = $requestData['vendor_name'] ?? 'Vendor';
        $amount = isset($requestData['estimated_value']) ? 
            $requestData['currency'] . ' ' . number_format((float)$requestData['estimated_value'], 2) : 
            'Amount TBD';
        $description = $requestData['description'] ?? 'No description provided';

        return match ($actionType) {
            self::ACTION_FUNDS_VERIFICATION => "Request for $vendor ($amount) requires funds verification. Description: $description",
            self::ACTION_COMMITMENT_UPLOAD => "Commitment form must be uploaded for $vendor. Total: $amount. Description: $description",
            self::ACTION_COMMITMENT_APPROVAL => "Commitment for $vendor ($amount) is pending your approval. Description: $description",
            self::ACTION_COMMITMENT_DECLINE => "Insufficient funds for $vendor ($amount). Please review or decline commitment. Description: $description",
            self::ACTION_INVOICE_PAYMENT => "Invoice from $vendor ($amount) is ready for payment processing. Description: $description",
            self::ACTION_PO_APPROVAL => "Purchase Order for $vendor ($amount) requires your approval. Description: $description",
            default => "Action required for request involving $vendor. Amount: $amount. Description: $description",
        };
    }

    /**
     * Get notification priority based on action type.
     *
     * @param string $actionType
     * @return string
     */
    private static function getNotificationPriority(string $actionType): string
    {
        return match ($actionType) {
            self::ACTION_COMMITMENT_DECLINE => 'high',      // Blocked progress
            self::ACTION_FUNDS_VERIFICATION => 'high',      // Blocks workflow
            self::ACTION_INVOICE_PAYMENT => 'high',         // Time-sensitive payment
            self::ACTION_COMMITMENT_APPROVAL => 'normal',
            self::ACTION_COMMITMENT_UPLOAD => 'normal',
            self::ACTION_PO_APPROVAL => 'normal',
            default => 'normal',
        };
    }

    /**
     * Log notification event to audit trail.
     *
     * @param PDO    $pdo
     * @param int    $requestId
     * @param string $actionType
     * @param int    $userId      Recipient user ID
     * @param string $status      SENT, READ, COMPLETED, FAILED
     * @return void
     */
    private static function logNotificationEvent(PDO $pdo, int $requestId, string $actionType, int $userId, string $status): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log
                (table_name, record_id, action, changed_by, notes, change_date)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                'procurement_requests',
                $requestId,
                'FINANCE_NOTIFICATION_' . $status,
                $_SESSION['full_name'] ?? 'System',
                "Finance notification ($actionType) $status for user ID $userId"
            ]);
        } catch (Throwable $e) {
            error_log("FinanceNotificationService::logNotificationEvent error: " . $e->getMessage());
        }
    }

    /**
     * Mark outstanding notifications as completed when action is done.
     *
     * @param int    $requestId  The procurement request ID
     * @param string $actionType The action type that was completed
     * @return void
     */
    public static function completeAction(int $requestId, string $actionType): void
    {
        global $pdo;
        if (!$pdo) return;

        try {
            // Mark related outstanding notifications as read
            $stmt = $pdo->prepare("
                UPDATE user_notifications
                SET is_read = 1, read_at = CURRENT_TIMESTAMP
                WHERE request_id = ?
                AND type = ?
                AND stage = ?
                AND is_read = 0
            ");
            $stmt->execute([$requestId, \NotificationService::TYPE_FINANCE_ACTION, $actionType]);

            // Log completion in audit trail
            $auditors = $pdo->prepare("
                SELECT user_id FROM user_notifications
                WHERE request_id = ? AND type = ? AND stage = ?
            ");
            $auditors->execute([$requestId, \NotificationService::TYPE_FINANCE_ACTION, $actionType]);
            
            foreach ($auditors->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                self::logNotificationEvent($pdo, $requestId, $actionType, $userId, 'COMPLETED');
            }
        } catch (Throwable $e) {
            error_log("FinanceNotificationService::completeAction error: " . $e->getMessage());
        }
    }
}
