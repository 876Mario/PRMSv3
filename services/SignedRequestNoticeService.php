<?php
/**
 * SignedRequestNoticeService
 * Handles configuration and audit logging for signed request handling notices.
 */
class SignedRequestNoticeService
{
    public const PRINT_NOTICE_KEY = 'signed_request_print_notice_enabled';
    public const UPLOAD_NOTICE_KEY = 'signed_document_upload_notice_enabled';

    private const VALID_REQUEST_TYPES = ['REGULAR', 'REIMBURSEMENT', 'PETTY_CASH'];
    private const VALID_CONTEXTS = ['PRINT', 'UPLOAD'];
    private const VALID_EVENTS = ['DISPLAYED', 'ACKNOWLEDGED'];

    public static function seedDefaultSettings(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO system_config (config_key, config_value, description, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE config_value = config_value"
        );

        $stmt->execute([
            self::PRINT_NOTICE_KEY,
            '1',
            'Enable/disable signed request document handling popup after printing (1=enabled, 0=disabled)'
        ]);

        $stmt->execute([
            self::UPLOAD_NOTICE_KEY,
            '1',
            'Enable/disable signed document upload confirmation popup (1=enabled, 0=disabled)'
        ]);
    }

    public static function isPrintNoticeEnabled(PDO $pdo): bool
    {
        return self::isNoticeEnabled($pdo, self::PRINT_NOTICE_KEY, true);
    }

    public static function isUploadNoticeEnabled(PDO $pdo): bool
    {
        return self::isNoticeEnabled($pdo, self::UPLOAD_NOTICE_KEY, true);
    }

    public static function isNoticeEnabled(PDO $pdo, string $configKey, bool $defaultValue = true): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
            $stmt->execute([$configKey]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') {
                return $defaultValue;
            }
            return (int)$value === 1;
        } catch (Throwable $e) {
            return $defaultValue;
        }
    }

    public static function logEvent(
        PDO $pdo,
        int $requestId,
        string $requestType,
        string $noticeContext,
        string $eventType,
        int $userId,
        string $userName,
        ?string $actionToken = null,
        ?string $eventNote = null
    ): void {
        $requestType = strtoupper(trim($requestType));
        $noticeContext = strtoupper(trim($noticeContext));
        $eventType = strtoupper(trim($eventType));

        if (
            $requestId <= 0 ||
            $userId <= 0 ||
            !in_array($requestType, self::VALID_REQUEST_TYPES, true) ||
            !in_array($noticeContext, self::VALID_CONTEXTS, true) ||
            !in_array($eventType, self::VALID_EVENTS, true)
        ) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO signed_request_notice_events
                    (request_id, request_type, notice_context, event_type, user_id, user_name, action_token, event_note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $requestId,
                $requestType,
                $noticeContext,
                $eventType,
                $userId,
                $userName !== '' ? $userName : null,
                $actionToken !== '' ? $actionToken : null,
                $eventNote !== '' ? $eventNote : null,
            ]);

            if (function_exists('logAudit')) {
                $auditAction = "SIGNED_REQUEST_{$noticeContext}_NOTICE_{$eventType}";
                $auditNotes = 'Signed request handling notice ' . strtolower($eventType)
                    . ' for request_type=' . $requestType
                    . ', user_id=' . $userId
                    . (!empty($actionToken) ? ', action_token=' . $actionToken : '')
                    . (!empty($eventNote) ? ', note=' . $eventNote : '');

                logAudit($pdo, 'procurement_requests', $requestId, $auditAction, $auditNotes);
            }
        } catch (Throwable $e) {
            error_log('SignedRequestNoticeService::logEvent failed: ' . $e->getMessage());
        }
    }
}
