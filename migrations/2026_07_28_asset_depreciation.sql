-- ============================================================================
-- Migration: Asset Depreciation Module
-- Date: 2026-07-28
-- Purpose: Add depreciation schedule and historical record tables to support
--   Straight-Line, Declining Balance, and Units of Production methods.
-- ============================================================================

-- 1. Add depreciation configuration fields to inv_asset_details
ALTER TABLE `inv_asset_details`
  ADD COLUMN IF NOT EXISTS `depreciation_method_type`
        ENUM('STRAIGHT_LINE','DECLINING_BALANCE','UNITS_OF_PRODUCTION')
        DEFAULT NULL
        COMMENT 'Depreciation calculation method'
        AFTER `depreciation_method`,
  ADD COLUMN IF NOT EXISTS `useful_life_years`
        DECIMAL(6,2) DEFAULT NULL
        COMMENT 'Expected useful life in years (Straight-Line / Declining Balance)'
        AFTER `depreciation_method_type`,
  ADD COLUMN IF NOT EXISTS `salvage_value`
        DECIMAL(15,2) DEFAULT 0.00
        COMMENT 'Estimated residual / salvage value at end of useful life'
        AFTER `useful_life_years`,
  ADD COLUMN IF NOT EXISTS `total_production_units`
        DECIMAL(15,2) DEFAULT NULL
        COMMENT 'Total expected units over asset lifetime (Units of Production method)'
        AFTER `salvage_value`,
  ADD COLUMN IF NOT EXISTS `declining_balance_rate`
        DECIMAL(7,4) DEFAULT NULL
        COMMENT 'Annual depreciation rate for Declining Balance (e.g. 0.2000 = 20%)'
        AFTER `total_production_units`;

-- 2. Depreciation schedules — one schedule per asset, regenerated when parameters change
CREATE TABLE IF NOT EXISTS `asset_depreciation_schedules` (
  `schedule_id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `item_id`                 INT(11)       NOT NULL COMMENT 'References inv_items.item_id',
  `asset_detail_id`         INT(11)       NOT NULL COMMENT 'References inv_asset_details.asset_detail_id',
  `method`                  ENUM('STRAIGHT_LINE','DECLINING_BALANCE','UNITS_OF_PRODUCTION')
                                          NOT NULL,
  `cost_basis`              DECIMAL(15,2) NOT NULL COMMENT 'Acquisition cost used for calculation',
  `salvage_value`           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `useful_life_years`       DECIMAL(6,2)  DEFAULT NULL,
  `total_production_units`  DECIMAL(15,2) DEFAULT NULL,
  `declining_balance_rate`  DECIMAL(7,4)  DEFAULT NULL,
  `start_date`              DATE          NOT NULL COMMENT 'Date placed in service',
  `end_date`                DATE          DEFAULT NULL COMMENT 'Calculated end of depreciation period',
  `is_active`               TINYINT(1)   NOT NULL DEFAULT 1,
  `generated_by`            INT(11)       DEFAULT NULL COMMENT 'User who generated the schedule',
  `created_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_dep_item`   (`item_id`),
  KEY `idx_dep_active` (`item_id`, `is_active`),
  CONSTRAINT `fk_dep_schedule_item`   FOREIGN KEY (`item_id`)         REFERENCES `inv_items`(`item_id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_dep_schedule_detail` FOREIGN KEY (`asset_detail_id`) REFERENCES `inv_asset_details`(`asset_detail_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Depreciation schedule per asset — one active schedule at a time';

-- 3. Depreciation period entries — one row per year/period per schedule
CREATE TABLE IF NOT EXISTS `asset_depreciation_periods` (
  `period_id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `schedule_id`            INT(11)       NOT NULL,
  `period_number`          INT(11)       NOT NULL COMMENT 'Year number (1, 2, … n) or sequential period',
  `period_start_date`      DATE          NOT NULL,
  `period_end_date`        DATE          NOT NULL,
  `units_consumed`         DECIMAL(15,2) DEFAULT NULL COMMENT 'Units produced this period (UoP only)',
  `depreciation_charge`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `accumulated_depreciation` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `book_value_end`         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `is_recorded`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = actual record posted',
  `recorded_by`            INT(11)       DEFAULT NULL,
  `recorded_at`            TIMESTAMP     NULL DEFAULT NULL,
  `notes`                  TEXT          DEFAULT NULL,
  PRIMARY KEY (`period_id`),
  UNIQUE KEY `uk_schedule_period` (`schedule_id`, `period_number`),
  KEY `idx_dep_period_schedule` (`schedule_id`),
  CONSTRAINT `fk_dep_period_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `asset_depreciation_schedules`(`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual depreciation period rows (typically annual) within a schedule';

-- 4. Historical depreciation records — actual posted charges
CREATE TABLE IF NOT EXISTS `asset_depreciation_records` (
  `record_id`           INT(11)       NOT NULL AUTO_INCREMENT,
  `item_id`             INT(11)       NOT NULL,
  `schedule_id`         INT(11)       DEFAULT NULL,
  `period_id`           INT(11)       DEFAULT NULL,
  `financial_year`      YEAR          NOT NULL COMMENT 'Financial year the charge belongs to',
  `charge_date`         DATE          NOT NULL,
  `depreciation_amount` DECIMAL(15,2) NOT NULL,
  `accumulated_total`   DECIMAL(15,2) NOT NULL,
  `book_value_after`    DECIMAL(15,2) NOT NULL,
  `method`              ENUM('STRAIGHT_LINE','DECLINING_BALANCE','UNITS_OF_PRODUCTION') NOT NULL,
  `units_consumed`      DECIMAL(15,2) DEFAULT NULL,
  `notes`               TEXT          DEFAULT NULL,
  `created_by`          INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`record_id`),
  KEY `idx_dep_record_item`  (`item_id`),
  KEY `idx_dep_record_year`  (`item_id`, `financial_year`),
  CONSTRAINT `fk_dep_record_item`     FOREIGN KEY (`item_id`)    REFERENCES `inv_items`(`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dep_record_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `asset_depreciation_schedules`(`schedule_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dep_record_period`   FOREIGN KEY (`period_id`)  REFERENCES `asset_depreciation_periods`(`period_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Posted / historical depreciation charge records';

-- 5. Workflow transition history (for Issue 2 audit trail)
CREATE TABLE IF NOT EXISTS `workflow_transition_history` (
  `transition_id`   INT(11)       NOT NULL AUTO_INCREMENT,
  `request_id`      INT(11)       NOT NULL,
  `from_status`     VARCHAR(60)   NOT NULL,
  `to_status`       VARCHAR(60)   NOT NULL,
  `is_backward`     TINYINT(1)   NOT NULL DEFAULT 0,
  `actor_user_id`   INT(11)       DEFAULT NULL,
  `actor_role`      VARCHAR(100)  DEFAULT NULL,
  `reason`          TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transition_id`),
  KEY `idx_wth_request` (`request_id`),
  CONSTRAINT `fk_wth_request` FOREIGN KEY (`request_id`) REFERENCES `procurement_requests`(`request_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail of all workflow status transitions, including backward reverts';

-- 6. Depreciation permissions
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('view_asset_depreciation',   'View asset depreciation schedules and records'),
  ('manage_asset_depreciation', 'Create and manage asset depreciation schedules');

-- 7. Assign permissions to roles
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Finance Officer', 'Admin', 'SuperAdmin', 'Property Management Officer')
  AND p.name IN ('view_asset_depreciation', 'manage_asset_depreciation');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'view_asset_depreciation'
  AND r.role_name NOT IN ('Finance Officer', 'Admin', 'SuperAdmin', 'Property Management Officer');

-- 8. Page permissions for depreciation module
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
  ('/inventory/depreciation/list.php',     'view_asset_depreciation',   NOW()),
  ('/inventory/depreciation/add.php',      'manage_asset_depreciation', NOW()),
  ('/inventory/depreciation/schedule.php', 'view_asset_depreciation',   NOW()),
  ('/inventory/depreciation/record.php',   'manage_asset_depreciation', NOW());
