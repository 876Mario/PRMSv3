-- Signed request handling notice settings and audit trail

INSERT INTO system_config (config_key, config_value, description, created_at)
VALUES
    ('signed_request_print_notice_enabled', '1', 'Enable/disable signed request document handling popup after printing (1=enabled, 0=disabled)', NOW()),
    ('signed_document_upload_notice_enabled', '1', 'Enable/disable signed document upload confirmation popup (1=enabled, 0=disabled)', NOW())
ON DUPLICATE KEY UPDATE config_value = config_value;

CREATE TABLE IF NOT EXISTS `signed_request_notice_events` (
    `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT(11) NOT NULL,
    `request_type` VARCHAR(30) NOT NULL,
    `notice_context` ENUM('PRINT', 'UPLOAD') NOT NULL,
    `event_type` ENUM('DISPLAYED', 'ACKNOWLEDGED') NOT NULL,
    `user_id` INT(11) NOT NULL,
    `user_name` VARCHAR(100) DEFAULT NULL,
    `action_token` VARCHAR(80) DEFAULT NULL,
    `event_note` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_id`),
    KEY `idx_srne_request` (`request_id`),
    KEY `idx_srne_user` (`user_id`),
    KEY `idx_srne_context_event` (`notice_context`, `event_type`),
    KEY `idx_srne_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
