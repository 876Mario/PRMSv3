-- =============================================================================
-- Migration: Cron Alert Recipient Configuration & Execution Audit
-- Date: 2026-08-19
-- Purpose:
--   Add configuration tables for context-aware alert recipients (procurement,
--   inventory), cron execution locking/tracking, and audit logging for
--   the overdue and inventory alert cronjobs to prevent broadcasts to all users.
--
-- Resolves:
--   1. Procurement overdue alerts sent to all users with role (fix: branch filtering)
--   2. Inventory overdue alerts sent to hardcoded admin email (fix: query PMO role)
--   3. No deduplication at recipient-selection level (fix: audit trail)
--   4. Duplicate cron execution possible (fix: execution lock)
-- =============================================================================

-- 1. Cron Execution Locks (prevent duplicate execution)
--    Ensures a named cron job can only run once at a time
CREATE TABLE IF NOT EXISTS `cron_execution_locks` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `cron_name`      VARCHAR(100) NOT NULL UNIQUE COMMENT 'e.g., overdue_alerts, inventory_alerts',
    `locked_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expected_duration_seconds` INT(11) DEFAULT 600 COMMENT 'Timeout: release lock if older than this',
    `executed_by`    VARCHAR(255) DEFAULT NULL COMMENT 'PID or hostname of executing process',
    PRIMARY KEY (`id`),
    KEY `idx_cron_name` (`cron_name`),
    KEY `idx_locked_at` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Prevents concurrent execution of the same cron job';

-- 2. Cron Execution Audit Log
--    Tracks every cron execution with success/failure status and summary
CREATE TABLE IF NOT EXISTS `cron_execution_log` (
    `id`               INT(11)       NOT NULL AUTO_INCREMENT,
    `cron_name`        VARCHAR(100)  NOT NULL COMMENT 'e.g., overdue_alerts, inventory_alerts',
    `started_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`     TIMESTAMP     NULL COMMENT 'NULL = still running or failed to complete',
    `status`           ENUM('RUNNING', 'SUCCESS', 'PARTIAL_FAILURE', 'FAILED') NOT NULL DEFAULT 'RUNNING',
    `requests_processed` INT(11)     DEFAULT 0 COMMENT 'Number of requests checked',
    `recipients_found` INT(11)       DEFAULT 0 COMMENT 'Total distinct recipients identified',
    `notifications_created` INT(11)  DEFAULT 0 COMMENT 'Notifications successfully inserted',
    `notifications_failed` INT(11)   DEFAULT 0 COMMENT 'Notification creation failures',
    `error_message`    TEXT          DEFAULT NULL,
    `execution_notes`  TEXT          DEFAULT NULL,
    `duration_ms`      INT(11)       DEFAULT NULL COMMENT 'Milliseconds to complete',
    PRIMARY KEY (`id`),
    KEY `idx_cron_name` (`cron_name`),
    KEY `idx_started_at` (`started_at`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Audit trail of all cron executions';

-- 3. Procurement Overdue Alert Recipients Configuration
--    Defines which users/roles should receive procurement overdue alerts
--    Per-branch configuration: each branch can have different alert recipients
CREATE TABLE IF NOT EXISTS `procurement_alert_recipients` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `branch_id`        INT(11)      NOT NULL COMMENT 'Branch for which these recipients apply',
    `recipient_type`   ENUM('ROLE', 'USER', 'BRANCH_HEAD', 'HOD') NOT NULL
        COMMENT 'ROLE: all users with this role; USER: specific user; BRANCH_HEAD/HOD: role-based but single per branch',
    `recipient_role_id` INT(11)     DEFAULT NULL COMMENT 'If recipient_type=ROLE, which role_id',
    `recipient_user_id` INT(11)     DEFAULT NULL COMMENT 'If recipient_type=USER, which user_id',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`            VARCHAR(500) DEFAULT NULL,
    `created_by`       INT(11)      DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_branch_recipient` (`branch_id`, `recipient_type`, `recipient_role_id`, `recipient_user_id`),
    CONSTRAINT `fk_par_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_par_role` FOREIGN KEY (`recipient_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_par_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_par_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Configurable recipients for procurement overdue alerts, filtered by branch';

-- 4. Inventory Overdue Alert Recipients Configuration
--    Defines which users/roles should receive inventory-related alerts
--    Supports location-based, role-based, and specific user assignments
CREATE TABLE IF NOT EXISTS `inventory_alert_recipients` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `location_id`      INT(11)      DEFAULT NULL COMMENT 'If set, alert only for items in this location; NULL = all locations',
    `recipient_type`   ENUM('ROLE', 'USER', 'PROPERTY_MANAGEMENT_OFFICER') NOT NULL
        COMMENT 'ROLE: all users with role; USER: specific user; PROPERTY_MANAGEMENT_OFFICER: all PMO users',
    `recipient_role_id` INT(11)     DEFAULT NULL COMMENT 'If recipient_type=ROLE or PROPERTY_MANAGEMENT_OFFICER, role_id',
    `recipient_user_id` INT(11)     DEFAULT NULL COMMENT 'If recipient_type=USER, which user_id',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `alert_types`      SET('REORDER', 'EXPIRING_30', 'EXPIRING_7', 'EXPIRED', 'PENDING_APPROVAL', 'OPEN_INCIDENT')
        NOT NULL DEFAULT 'REORDER,EXPIRING_7,EXPIRED,PENDING_APPROVAL,OPEN_INCIDENT'
        COMMENT 'Which alert categories this recipient receives',
    `notes`            VARCHAR(500) DEFAULT NULL,
    `created_by`       INT(11)      DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_location_recipient` (`location_id`, `recipient_type`, `recipient_role_id`, `recipient_user_id`),
    CONSTRAINT `fk_iar_location` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`location_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iar_role` FOREIGN KEY (`recipient_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iar_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iar_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Configurable recipients for inventory alerts, filtered by location and alert type';

-- 5. Cron Recipient Audit Trail
--    Detailed record of every recipient identified and contacted for each cron execution
CREATE TABLE IF NOT EXISTS `cron_recipient_audit` (
    `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
    `execution_id`        INT(11)     NOT NULL COMMENT 'Reference to cron_execution_log.id',
    `request_id`          INT(11)     DEFAULT NULL COMMENT 'Procurement or inventory request being alerted on',
    `request_type`        VARCHAR(50) DEFAULT NULL COMMENT 'PROCUREMENT, INVENTORY_ITEM, INVENTORY_RETURN, etc.',
    `request_ref`         VARCHAR(100) DEFAULT NULL COMMENT 'Request number for traceability (e.g., PRC-001)',
    `branch_id`           INT(11)     DEFAULT NULL COMMENT 'Branch context for the request',
    `location_id`         INT(11)     DEFAULT NULL COMMENT 'Location context for inventory items',
    `recipient_user_id`   INT(11)     NOT NULL COMMENT 'Who is receiving the notification',
    `recipient_reason`    VARCHAR(255) NOT NULL
        COMMENT 'Why this recipient was selected: e.g., "Branch Head of Branch A", "Property Management Officer", "Configured PMO User"',
    `notification_id`     INT(11)     DEFAULT NULL COMMENT 'Reference to user_notifications.id if created',
    `email_sent`          TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Whether email was successfully sent',
    `email_log_id`        INT(11)     DEFAULT NULL COMMENT 'Reference to email_notification_log.id if email sent',
    `deduped`             TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Whether this notification was deduplicated',
    `duplicate_of_audit_id` INT(11)   DEFAULT NULL COMMENT 'If deduped, which earlier audit entry was a duplicate',
    `created_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_cra_execution` FOREIGN KEY (`execution_id`) REFERENCES `cron_execution_log` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cra_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cra_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cra_location` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`location_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cra_notification` FOREIGN KEY (`notification_id`) REFERENCES `user_notifications` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cra_email_log` FOREIGN KEY (`email_log_id`) REFERENCES `email_notification_log` (`id`) ON DELETE SET NULL,
    KEY `idx_execution` (`execution_id`),
    KEY `idx_request` (`request_id`, `request_type`),
    KEY `idx_recipient` (`recipient_user_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Detailed audit trail of recipients selected by each cron execution for complete traceability';

-- 6. Seed default procurement alert recipients (Branch Head + HOD for each branch)
--    Admins can modify these via configuration interface
INSERT IGNORE INTO `procurement_alert_recipients` 
    (`branch_id`, `recipient_type`, `recipient_role_id`, `is_active`, `notes`, `created_by`)
SELECT b.branch_id, 'BRANCH_HEAD', r.id, 1, CONCAT('Default: Branch Head of ', b.branch_name), NULL
FROM branches b
CROSS JOIN roles r
WHERE r.name = 'Branch Head'
AND b.is_active = 1;

-- 7. Seed default inventory alert recipients (all Property Management Officers)
--    Admins can add location-specific recipients to limit alerts by location
INSERT IGNORE INTO `inventory_alert_recipients`
    (`location_id`, `recipient_type`, `recipient_role_id`, `is_active`, `alert_types`, `notes`, `created_by`)
SELECT NULL, 'PROPERTY_MANAGEMENT_OFFICER', r.id, 1, 'REORDER,EXPIRING_7,EXPIRED,PENDING_APPROVAL,OPEN_INCIDENT', 
    'Default: All Property Management Officers receive all inventory alerts',
    NULL
FROM roles r
WHERE r.name = 'Property Management Officer'
LIMIT 1;

-- 8. Add permission for managing alert recipient configuration
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
    ('manage_cron_alert_recipients', 'Configure procurement and inventory overdue alert recipients'),
    ('view_cron_execution_logs', 'View cron execution logs and audit trails');

-- 9. Grant permissions to Admin and SuperAdmin roles
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'SuperAdmin')
  AND p.name IN ('manage_cron_alert_recipients', 'view_cron_execution_logs');
