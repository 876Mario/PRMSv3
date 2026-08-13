<?php
/**
 * NotificationService
 * ===================
 * Creates and manages in-app notifications stored in the user_notifications table.
 *
 * All public methods are static so callers don't need to instantiate the class;
 * a global $pdo is expected (same pattern as config/notifications.php).
 */
class NotificationService
{
    // -----------------------------------------------------------------------
    // Allowed notification types (must match ENUM in migration 024)
    // -----------------------------------------------------------------------
    const TYPE_APPROVAL_NEEDED     = 'approval_needed';
    const TYPE_RETURN_CORRECTION   = 'return_correction';
    const TYPE_CLARIFICATION       = 'clarification';
    const TYPE_REJECTION           = 'rejection';
    const TYPE_CANCELLATION        = 'cancellation';
    const TYPE_DRAFT_READY         = 'draft_ready';
    const TYPE_SUBMISSION          = 'submission';
    const TYPE_FINANCE_ACTION      = 'finance_action_required';

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Create an in-app notification for one user.
     *
     * @param int    $userId     Recipient user_id
     * @param string $type       One of the TYPE_* constants
     * @param array  $data {
     *   title          string  (required)
     *   body           string
     *   request_id     int
     *   request_ref    string
     *   action_url     string
     *   stage          string
     *   requestor_name string
     *   priority       'normal'|'high'|'urgent'
     * }
     * @return bool
     */
    public static function createNotification(int $userId, string $type, array $data): bool
    {
        global $pdo;
        if (!$pdo || $userId <= 0) {
            return false;
        }

        $title         = trim($data['title'] ?? '');
        if ($title === '') {
            return false;
        }

        $allowedTypes = [
            self::TYPE_APPROVAL_NEEDED, self::TYPE_RETURN_CORRECTION,
            self::TYPE_CLARIFICATION,   self::TYPE_REJECTION,
            self::TYPE_CANCELLATION,    self::TYPE_DRAFT_READY,
            self::TYPE_SUBMISSION,      self::TYPE_FINANCE_ACTION,
        ];
        if (!in_array($type, $allowedTypes, true)) {
            error_log("NotificationService: unknown type '{$type}'");
            return false;
        }

        $priority = $data['priority'] ?? 'normal';
        if (!in_array($priority, ['normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_notifications
                    (user_id, request_id, type, title, body, request_ref,
                     action_url, stage, requestor_name, priority)
                VALUES
                    (:uid, :rid, :type, :title, :body, :ref,
                     :url, :stage, :rname, :prio)
            ");
            $stmt->execute([
                ':uid'   => $userId,
                ':rid'   => $data['request_id']     ?? null,
                ':type'  => $type,
                ':title' => $title,
                ':body'  => $data['body']            ?? null,
                ':ref'   => $data['request_ref']     ?? null,
                ':url'   => $data['action_url']      ?? null,
                ':stage' => $data['stage']           ?? null,
                ':rname' => $data['requestor_name']  ?? null,
                ':prio'  => $priority,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log("NotificationService::createNotification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a single notification as read (only if it belongs to $userId).
     */
    public static function markRead(int $notifId, int $userId): void
    {
        global $pdo;
        if (!$pdo) return;
        try {
            $pdo->prepare("
                UPDATE user_notifications
                SET is_read = 1, read_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ? AND is_read = 0
            ")->execute([$notifId, $userId]);
        } catch (Throwable $e) {
            error_log("NotificationService::markRead error: " . $e->getMessage());
        }
    }

    /**
     * Mark all notifications for $userId as read.
     */
    public static function markAllRead(int $userId): void
    {
        global $pdo;
        if (!$pdo) return;
        try {
            $pdo->prepare("
                UPDATE user_notifications
                SET is_read = 1, read_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND is_read = 0
            ")->execute([$userId]);
        } catch (Throwable $e) {
            error_log("NotificationService::markAllRead error: " . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /**
     * Return unread notifications for $userId, newest first.
     *
     * @return array
     */
    public static function getUnread(int $userId): array
    {
        global $pdo;
        if (!$pdo) return [];
        try {
            $stmt = $pdo->prepare("
                SELECT id, request_id, type, title, body, request_ref,
                       action_url, stage, requestor_name, priority, created_at
                FROM user_notifications
                WHERE user_id = ? AND is_read = 0
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("NotificationService::getUnread error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Return all notifications for $userId (read + unread), newest first.
     *
     * @param int $limit Maximum rows (default 50)
     * @return array
     */
    public static function getAll(int $userId, int $limit = 50): array
    {
        global $pdo;
        if (!$pdo) return [];
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $pdo->prepare("
                SELECT id, request_id, type, title, body, request_ref,
                       action_url, stage, requestor_name, priority,
                       is_read, created_at, read_at
                FROM user_notifications
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("NotificationService::getAll error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Return the count of unread notifications for $userId.
     */
    public static function countUnread(int $userId): int
    {
        global $pdo;
        if (!$pdo) return 0;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM user_notifications
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("NotificationService::countUnread error: " . $e->getMessage());
            return 0;
        }
    }
}
