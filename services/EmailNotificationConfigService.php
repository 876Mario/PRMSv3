<?php
/**
 * EmailNotificationConfigService
 * ===============================
 * Centralized configuration layer for automated workflow email notifications.
 *
 * This service does NOT implement a parallel notification framework - it
 * reuses the existing mailer (config/mailer.php sendMail()) and the existing
 * users/roles/permissions tables. It adds a thin, admin-configurable layer
 * on top so that:
 *   - each workflow event can be enabled/disabled
 *   - recipients are resolved dynamically from configured roles/users
 *     (never hard-coded email addresses)
 *   - subject/body templates support placeholders and are safely escaped
 *   - every send attempt (success or failure) is recorded for audit
 *   - duplicate/excessive reminders for the same outstanding action are
 *     suppressed via a dedup key
 *
 * All public methods are static so callers don't need to instantiate the
 * class; a global $pdo is expected (same pattern as config/notifications.php
 * and services/NotificationService.php).
 */
class EmailNotificationConfigService
{
    /** How long (in hours) a previously-sent notification for the same
     *  event+request+recipient suppresses a duplicate before another one
     *  may be sent (e.g. for recurring reminders). */
    const DEDUP_WINDOW_HOURS = 24;

    /**
     * Available placeholders that admins may use in subject/body templates.
     */
    public static function availablePlaceholders(): array
    {
        return [
            '{{request_number}}',
            '{{request_description}}',
            '{{requester_name}}',
            '{{vendor_name}}',
            '{{current_status}}',
            '{{required_action}}',
            '{{action_link}}',
            '{{due_date}}',
        ];
    }

    /**
     * Fetch the full catalogue of events with their current settings.
     */
    public static function getEvents(): array
    {
        global $pdo;
        $stmt = $pdo->query("
            SELECT e.event_key, e.event_label, e.description,
                   e.default_subject, e.default_body, e.sort_order,
                   COALESCE(s.is_enabled, 1)  AS is_enabled,
                   s.subject_template, s.body_template,
                   s.updated_by_name, s.updated_at
            FROM email_notification_events e
            LEFT JOIN email_notification_settings s ON s.event_key = e.event_key
            ORDER BY e.sort_order ASC, e.event_label ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getEvent(string $eventKey): ?array
    {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT e.event_key, e.event_label, e.description,
                   e.default_subject, e.default_body,
                   COALESCE(s.is_enabled, 1) AS is_enabled,
                   s.subject_template, s.body_template
            FROM email_notification_events e
            LEFT JOIN email_notification_settings s ON s.event_key = e.event_key
            WHERE e.event_key = ?
        ");
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function isEventEnabled(string $eventKey): bool
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT is_enabled FROM email_notification_settings WHERE event_key = ?");
            $stmt->execute([$eventKey]);
            $value = $stmt->fetchColumn();
            return $value === false ? true : (bool)(int)$value;
        } catch (Throwable $e) {
            error_log("EmailNotificationConfigService::isEventEnabled error: {$e->getMessage()}");
            return true;
        }
    }

    /**
     * Role IDs currently configured as recipients for this event.
     */
    public static function getRecipientRoleIds(string $eventKey): array
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT role_id FROM email_notification_recipient_roles WHERE event_key = ?");
        $stmt->execute([$eventKey]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function getRecipientUserIds(string $eventKey): array
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT user_id FROM email_notification_recipient_users WHERE event_key = ?");
        $stmt->execute([$eventKey]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Resolve the active, email-eligible recipients configured for an event.
     * Only active users belonging to active/valid roles are returned -
     * inactive users and inactive roles can never remain configured as
     * recipients (roles are always "active" here since roles have no
     * disable flag; deleting a role cascades its recipient rows).
     *
     * @return array<int, array{user_id:int, email:string, full_name:string}>
     */
    public static function resolveRecipients(string $eventKey): array
    {
        global $pdo;
        $recipients = [];

        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id, u.email, u.full_name
            FROM email_notification_recipient_roles enrr
            INNER JOIN roles r ON r.id = enrr.role_id
            INNER JOIN users u ON u.role_id = r.id
            WHERE enrr.event_key = ? AND u.is_active = 1
        ");
        $stmt->execute([$eventKey]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[(int)$row['user_id']] = $row;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id, u.email, u.full_name
            FROM email_notification_recipient_users enru
            INNER JOIN users u ON u.user_id = enru.user_id
            WHERE enru.event_key = ? AND u.is_active = 1
        ");
        $stmt->execute([$eventKey]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[(int)$row['user_id']] = $row;
        }

        // Only keep recipients with a syntactically valid email address.
        return array_values(array_filter($recipients, function ($r) {
            return filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) !== false;
        }));
    }

    /**
     * Render a subject/body template, substituting {{placeholder}} tokens.
     *
     * @param bool $isHtml When true (body templates), placeholder values are
     *                      HTML-escaped to prevent markup/script injection into
     *                      the rendered HTML email body. When false (subject
     *                      templates), values are kept as plain text but
     *                      stripped of newlines/control characters to prevent
     *                      email header injection.
     */
    public static function renderTemplate(string $template, array $placeholders, bool $isHtml = true): string
    {
        $search = [];
        $replace = [];
        foreach (self::availablePlaceholders() as $token) {
            $key = trim($token, '{}');
            $value = (string)($placeholders[$key] ?? '');
            $search[] = $token;
            $replace[] = $isHtml
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : preg_replace('/[\r\n]+/', ' ', $value);
        }
        return str_replace($search, $replace, $template);
    }

    /**
     * Build the effective subject/body for an event, using the admin
     * override when present and falling back to the seeded default.
     *
     * @return array{subject:string, body:string}
     */
    public static function buildRenderedTemplate(string $eventKey, array $placeholders): ?array
    {
        $event = self::getEvent($eventKey);
        if (!$event) {
            return null;
        }
        $subjectTemplate = $event['subject_template'] ?: $event['default_subject'];
        $bodyTemplate    = $event['body_template'] ?: $event['default_body'];

        return [
            'subject' => self::renderTemplate($subjectTemplate, $placeholders, false),
            'body'    => self::renderTemplate($bodyTemplate, $placeholders, true),
        ];
    }

    /**
     * Has a notification for this event/request/recipient already been sent
     * within the dedup window? Prevents duplicate/excessive reminders while
     * an item remains pending.
     */
    private static function recentlyNotified(string $eventKey, ?int $requestId, string $recipientEmail): bool
    {
        global $pdo;
        $dedupKey = self::dedupKey($eventKey, $requestId, $recipientEmail);
        $threshold = date('Y-m-d H:i:s', time() - (self::DEDUP_WINDOW_HOURS * 3600));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM email_notification_log
            WHERE dedup_key = ? AND status = 'SENT'
              AND sent_at >= ?
        ");
        $stmt->execute([$dedupKey, $threshold]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function dedupKey(string $eventKey, ?int $requestId, string $recipientEmail): string
    {
        return $eventKey . ':' . ($requestId ?? 0) . ':' . strtolower($recipientEmail);
    }

    private static function logDelivery(
        string $eventKey,
        ?int $requestId,
        ?int $recipientUserId,
        string $recipientEmail,
        string $subject,
        string $status,
        ?string $failureReason = null
    ): void {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_notification_log
                    (event_key, request_id, recipient_user_id, recipient_email, subject, status, failure_reason, dedup_key, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $eventKey,
                $requestId,
                $recipientUserId,
                $recipientEmail,
                $subject,
                $status,
                $failureReason,
                self::dedupKey($eventKey, $requestId, $recipientEmail),
            ]);
        } catch (Throwable $e) {
            error_log("EmailNotificationConfigService::logDelivery error: {$e->getMessage()}");
        }
    }

    /**
     * Dispatch a configurable notification for an event.
     *
     * @param string   $eventKey     One of the event_key values in email_notification_events
     * @param array    $placeholders Values for {{placeholder}} tokens (see availablePlaceholders())
     * @param int|null $requestId    Related procurement request, if any (used for dedup + logging)
     * @return array{sent:int, failed:int, skipped_disabled:bool, skipped_no_recipients:bool}
     */
    public static function dispatch(string $eventKey, array $placeholders, ?int $requestId = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped_disabled' => false, 'skipped_no_recipients' => false];

        if (!self::isEventEnabled($eventKey)) {
            $result['skipped_disabled'] = true;
            return $result;
        }

        $rendered = self::buildRenderedTemplate($eventKey, $placeholders);
        if (!$rendered) {
            return $result;
        }

        $recipients = self::resolveRecipients($eventKey);
        if (empty($recipients)) {
            $result['skipped_no_recipients'] = true;
            return $result;
        }

        foreach ($recipients as $recipient) {
            if (self::recentlyNotified($eventKey, $requestId, $recipient['email'])) {
                continue; // Avoid duplicate/excessive emails for the same outstanding action
            }

            try {
                $sent = sendMail($recipient['email'], $rendered['subject'], $rendered['body']);
                if ($sent) {
                    $result['sent']++;
                    self::logDelivery($eventKey, $requestId, (int)$recipient['user_id'], $recipient['email'], $rendered['subject'], 'SENT');
                } else {
                    $result['failed']++;
                    self::logDelivery($eventKey, $requestId, (int)$recipient['user_id'], $recipient['email'], $rendered['subject'], 'FAILED', 'sendMail() returned false');
                }
            } catch (Throwable $e) {
                $result['failed']++;
                self::logDelivery($eventKey, $requestId, (int)$recipient['user_id'], $recipient['email'], $rendered['subject'], 'FAILED', $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Send a one-off test email (e.g. "send test to current admin") using
     * the effective template for an event, without touching dedup/history
     * semantics used for real recipients (still logged for traceability).
     */
    public static function sendTestEmail(string $eventKey, string $toEmail, array $placeholders): bool
    {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $rendered = self::buildRenderedTemplate($eventKey, $placeholders);
        if (!$rendered) {
            return false;
        }
        $subject = '[TEST] ' . $rendered['subject'];
        try {
            $sent = sendMail($toEmail, $subject, $rendered['body']);
            self::logDelivery($eventKey, null, null, $toEmail, $subject, $sent ? 'SENT' : 'FAILED', $sent ? null : 'sendMail() returned false');
            return $sent;
        } catch (Throwable $e) {
            self::logDelivery($eventKey, null, null, $toEmail, $subject, 'FAILED', $e->getMessage());
            return false;
        }
    }

    /**
     * Persist admin configuration changes (enable flag, templates, roles,
     * users) and record before/after values in the history table.
     */
    public static function recordHistory(string $eventKey, string $field, ?string $oldValue, ?string $newValue, int $userId, string $userName): void
    {
        global $pdo;
        if ($oldValue === $newValue) {
            return;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_notification_config_history
                    (event_key, field_changed, old_value, new_value, changed_by, changed_by_name, changed_at)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$eventKey, $field, $oldValue, $newValue, $userId, $userName]);
        } catch (Throwable $e) {
            error_log("EmailNotificationConfigService::recordHistory error: {$e->getMessage()}");
        }
    }
}
