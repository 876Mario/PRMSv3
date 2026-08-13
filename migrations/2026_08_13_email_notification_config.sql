-- ============================================================================
-- Migration: Centralized Automatic Email Notification Configuration
-- Date: 2026-08-13
-- Purpose:
--   Add an admin-managed configuration layer on top of the existing
--   notification/email infrastructure (config/notifications.php,
--   config/mailer.php) so administrators can, per workflow event:
--     - enable/disable the notification
--     - choose recipient roles dynamically (no hard-coded emails)
--     - customise subject/body templates (with placeholders)
--     - review a delivery history (notification log)
--   Configuration changes are recorded with old/new value, user, and time.
-- ============================================================================

-- 1. Catalogue of configurable notification events
CREATE TABLE IF NOT EXISTS `email_notification_events` (
    `event_key`        VARCHAR(64)  NOT NULL,
    `event_label`      VARCHAR(150) NOT NULL,
    `description`      VARCHAR(255) DEFAULT NULL,
    `default_subject`  VARCHAR(255) NOT NULL,
    `default_body`     TEXT         NOT NULL,
    `sort_order`       INT(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Per-event configuration (enable flag + template overrides)
CREATE TABLE IF NOT EXISTS `email_notification_settings` (
    `event_key`         VARCHAR(64)  NOT NULL,
    `is_enabled`        TINYINT(1)   NOT NULL DEFAULT 1,
    `subject_template`  VARCHAR(255) DEFAULT NULL COMMENT 'Overrides default_subject when set',
    `body_template`     TEXT         DEFAULT NULL COMMENT 'Overrides default_body when set',
    `updated_by`        INT(11)      DEFAULT NULL,
    `updated_by_name`   VARCHAR(255) DEFAULT NULL,
    `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`event_key`),
    CONSTRAINT `fk_ens_event` FOREIGN KEY (`event_key`) REFERENCES `email_notification_events` (`event_key`) ON DELETE CASCADE,
    CONSTRAINT `fk_ens_user`  FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Recipient roles per event (multi-select)
CREATE TABLE IF NOT EXISTS `email_notification_recipient_roles` (
    `id`         INT(11) NOT NULL AUTO_INCREMENT,
    `event_key`  VARCHAR(64) NOT NULL,
    `role_id`    INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_role` (`event_key`, `role_id`),
    CONSTRAINT `fk_enrr_event` FOREIGN KEY (`event_key`) REFERENCES `email_notification_events` (`event_key`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrr_role`  FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Recipient specific users per event (optional, in addition to roles)
CREATE TABLE IF NOT EXISTS `email_notification_recipient_users` (
    `id`         INT(11) NOT NULL AUTO_INCREMENT,
    `event_key`  VARCHAR(64) NOT NULL,
    `user_id`    INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_user` (`event_key`, `user_id`),
    CONSTRAINT `fk_enru_event` FOREIGN KEY (`event_key`) REFERENCES `email_notification_events` (`event_key`) ON DELETE CASCADE,
    CONSTRAINT `fk_enru_user`  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Configuration change history (old value, new value, user, timestamp)
CREATE TABLE IF NOT EXISTS `email_notification_config_history` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `event_key`       VARCHAR(64) NOT NULL,
    `field_changed`   VARCHAR(100) NOT NULL,
    `old_value`       TEXT DEFAULT NULL,
    `new_value`       TEXT DEFAULT NULL,
    `changed_by`      INT(11) DEFAULT NULL,
    `changed_by_name` VARCHAR(255) DEFAULT NULL,
    `changed_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enhc_event` (`event_key`),
    CONSTRAINT `fk_enhc_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Delivery / notification history (event, recipient, status, failure reason, timestamp)
--    Also used to prevent duplicate/excessive emails via dedup_key
--    (one active notification per outstanding action).
CREATE TABLE IF NOT EXISTS `email_notification_log` (
    `id`                INT(11) NOT NULL AUTO_INCREMENT,
    `event_key`         VARCHAR(64) NOT NULL,
    `request_id`        INT(11) DEFAULT NULL,
    `recipient_user_id` INT(11) DEFAULT NULL,
    `recipient_email`   VARCHAR(255) NOT NULL,
    `subject`           VARCHAR(255) DEFAULT NULL,
    `status`            ENUM('SENT','FAILED') NOT NULL,
    `failure_reason`    VARCHAR(500) DEFAULT NULL,
    `dedup_key`         VARCHAR(191) DEFAULT NULL COMMENT 'event_key+request_id+recipient, used to prevent duplicate pending reminders',
    `sent_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enl_event` (`event_key`),
    KEY `idx_enl_request` (`request_id`),
    KEY `idx_enl_dedup` (`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Permission for managing this configuration (Admin-only screen)
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('manage_email_notifications', 'Configure automated email notification events, recipients, and templates');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'SuperAdmin')
  AND p.name = 'manage_email_notifications';

INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
  ('/admin/email_notifications.php', 'manage_email_notifications', NOW());

-- 8. Seed the catalogue of configurable procurement workflow events
INSERT IGNORE INTO `email_notification_events`
    (`event_key`, `event_label`, `description`, `default_subject`, `default_body`, `sort_order`) VALUES
('REQUEST_SUBMITTED', 'New request submitted',
    'Sent when a requester submits a new procurement request for approval.',
    'New Request Submitted - {{request_number}}',
    '<p>A new procurement request <strong>{{request_number}}</strong> ({{request_description}}) has been submitted by {{requester_name}} and requires your action.</p><p>Current status: {{current_status}}</p><p><a href="{{action_link}}">{{required_action}}</a></p>',
    10),
('REQUEST_RETURNED_FOR_CORRECTION', 'Request returned for correction',
    'Sent when a request is sent back to the requester for edits.',
    'Action Required: Request {{request_number}} Returned For Correction',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) has been returned to {{requester_name}} for correction.</p><p>Required action: {{required_action}}</p><p><a href="{{action_link}}">View Request</a></p>',
    20),
('HOD_BRANCH_HEAD_ACTION_REQUIRED', 'HOD or Branch Head action required',
    'Sent when a request needs HOD or Branch Head review/approval.',
    'Approval Required: {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) from {{requester_name}} requires your approval as {{required_action}}.</p><p>Current status: {{current_status}}</p><p><a href="{{action_link}}">Review Request</a></p>',
    30),
('PROCUREMENT_REVIEW_REQUIRED', 'Procurement review required',
    'Sent when the Procurement Officer needs to review or process a request.',
    'Procurement Review Required - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) requires Procurement review.</p><p>Required action: {{required_action}}</p><p><a href="{{action_link}}">Review Request</a></p>',
    40),
('VENDOR_QUOTATION_ACTION_REQUIRED', 'Vendor or quotation action required',
    'Sent when a vendor quote requires review, selection, or upload.',
    'Vendor/Quotation Action Required - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> involving vendor <strong>{{vendor_name}}</strong> requires action: {{required_action}}.</p><p><a href="{{action_link}}">View Request</a></p>',
    50),
('FINANCE_ACTION_REQUIRED', 'Finance action required',
    'Sent when Finance needs to verify funds, review a commitment, or process payment.',
    'Finance Action Required - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) requires Finance action: {{required_action}}.</p><p><a href="{{action_link}}">Review Request</a></p>',
    60),
('PETTY_CASH_RECONCILIATION_REQUIRED', 'Petty cash reconciliation required',
    'Sent when a petty cash advance needs reconciliation documents.',
    'Petty Cash Reconciliation Required - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> requires petty cash reconciliation. Due date: {{due_date}}.</p><p><a href="{{action_link}}">Reconcile Now</a></p>',
    70),
('MISSING_DOCUMENT_REMINDER', 'Missing supporting-document reminder',
    'Reminder sent when required supporting documents have not been uploaded.',
    'Reminder: Missing Supporting Documents - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> is missing required supporting documents. Please upload them as soon as possible.</p><p><a href="{{action_link}}">Upload Documents</a></p>',
    80),
('REIMBURSEMENT_INVOICE_ACTION_REQUIRED', 'Reimbursement invoice action required',
    'Sent when a reimbursement invoice needs review or approval.',
    'Reimbursement Invoice Action Required - {{request_number}}',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) has a reimbursement invoice requiring action: {{required_action}}.</p><p><a href="{{action_link}}">Review Invoice</a></p>',
    90),
('REQUEST_APPROVED_REJECTED', 'Request approved or rejected',
    'Sent when a request is approved or declined.',
    'Request {{request_number}} - {{current_status}}',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) submitted by {{requester_name}} is now <strong>{{current_status}}</strong>.</p><p><a href="{{action_link}}">View Request</a></p>',
    100),
('FINAL_PAYMENT_COMPLETION', 'Final payment or completion status',
    'Sent when a request reaches final payment or completion.',
    'Request {{request_number}} - Payment/Completion Update',
    '<p>Request <strong>{{request_number}}</strong> ({{request_description}}) has reached status <strong>{{current_status}}</strong>.</p><p><a href="{{action_link}}">View Request</a></p>',
    110);

-- Seed default (enabled) settings rows so admins can immediately configure roles
INSERT IGNORE INTO `email_notification_settings` (`event_key`, `is_enabled`)
SELECT `event_key`, 1 FROM `email_notification_events`;
