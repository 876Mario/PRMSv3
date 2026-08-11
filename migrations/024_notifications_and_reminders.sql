-- =============================================================================
-- Migration 024: In-app notifications, reminder log, and config entries
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. In-app notifications table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `user_id`        INT          NOT NULL,
  `request_id`     INT          DEFAULT NULL,
  `type`           ENUM(
                     'approval_needed',
                     'return_correction',
                     'clarification',
                     'rejection',
                     'cancellation',
                     'draft_ready',
                     'submission'
                   )             NOT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `body`           TEXT         DEFAULT NULL,
  `request_ref`    VARCHAR(100) DEFAULT NULL,
  `action_url`     VARCHAR(500) DEFAULT NULL,
  `stage`          VARCHAR(100) DEFAULT NULL,
  `requestor_name` VARCHAR(255) DEFAULT NULL,
  `priority`       ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
  `is_read`        TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at`        DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notif_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  INDEX `idx_notif_user_unread` (`user_id`, `is_read`),
  INDEX `idx_notif_request`     (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Reminder / escalation deduplication log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reminder_log` (
  `id`            INT  NOT NULL AUTO_INCREMENT,
  `request_id`    INT  NOT NULL,
  `user_id`       INT  NOT NULL,
  `reminder_type` ENUM('reminder','escalation') NOT NULL,
  `sent_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `no_dup` (`request_id`, `user_id`, `reminder_type`, (DATE(`sent_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. System-config entries for reminder / escalation intervals
--    (INSERT IGNORE so re-running is safe)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `system_config` (`config_key`, `config_value`, `description`)
VALUES
  ('reminder_interval_days',   '3',
   'Days of inactivity before a pending-action reminder is sent.'),
  ('escalation_threshold_days','7',
   'Days of inactivity before an overdue-action escalation email is sent to supervisor.');

-- ---------------------------------------------------------------------------
-- 4. supervisor_id column on users (if not already present)
--    Used for escalation emails; NULL means "fall back to branch head".
-- ---------------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `supervisor_id` INT DEFAULT NULL
    COMMENT 'Optional supervisor user_id for escalation routing.',
  ADD CONSTRAINT `fk_user_supervisor`
    FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;
