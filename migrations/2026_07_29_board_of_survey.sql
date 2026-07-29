-- ============================================================================
-- Migration: Board of Survey Module
-- Date: 2026-07-29
-- Purpose:
--   1. Create inv_board_of_survey and inv_bos_items tables.
--   2. Register BOS-specific permissions.
--   3. Register BOS pages in page_permissions.
--   4. Assign BOS permissions to relevant roles.
-- ============================================================================

-- ── 1. Board of Survey master table ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inv_board_of_survey` (
  `bos_id`                int(11)        NOT NULL AUTO_INCREMENT,
  `bos_number`            varchar(30)    NOT NULL,
  `survey_date`           date           DEFAULT NULL,
  `location_id`           int(11)        DEFAULT NULL,
  `reason_for_survey`     text           NOT NULL,
  `board_recommendation`  enum(
                              'DISPOSE',
                              'REPAIR',
                              'TRANSFER',
                              'WRITE_OFF',
                              'RETAIN',
                              'AUCTION',
                              'DONATE',
                              'OTHER'
                          )              DEFAULT NULL,
  `recommendation_notes`  text           DEFAULT NULL,
  `status`                enum(
                              'DRAFT',
                              'SUBMITTED',
                              'UNDER_REVIEW',
                              'APPROVED',
                              'REJECTED',
                              'COMPLETED',
                              'CANCELLED'
                          )              NOT NULL DEFAULT 'DRAFT',
  `initiated_by`          int(11)        NOT NULL,
  `submitted_at`          datetime       DEFAULT NULL,
  `reviewed_by`           int(11)        DEFAULT NULL,
  `reviewed_at`           datetime       DEFAULT NULL,
  `review_notes`          text           DEFAULT NULL,
  `approved_by`           int(11)        DEFAULT NULL,
  `approved_at`           datetime       DEFAULT NULL,
  `approval_notes`        text           DEFAULT NULL,
  `completed_at`          datetime       DEFAULT NULL,
  `supporting_notes`      text           DEFAULT NULL,
  `created_at`            timestamp      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            timestamp      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bos_id`),
  UNIQUE KEY `uk_bos_number` (`bos_number`),
  KEY `fk_bos_location` (`location_id`),
  KEY `fk_bos_initiated_by` (`initiated_by`),
  KEY `fk_bos_reviewed_by` (`reviewed_by`),
  KEY `fk_bos_approved_by` (`approved_by`),
  CONSTRAINT `fk_bos_location`     FOREIGN KEY (`location_id`)  REFERENCES `inv_locations` (`location_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_bos_initiated`    FOREIGN KEY (`initiated_by`) REFERENCES `users`         (`user_id`),
  CONSTRAINT `fk_bos_reviewer`     FOREIGN KEY (`reviewed_by`)  REFERENCES `users`         (`user_id`),
  CONSTRAINT `fk_bos_approver`     FOREIGN KEY (`approved_by`)  REFERENCES `users`         (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Board of Survey requests for inventory assets/items';

-- ── 2. BOS line items ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `inv_bos_items` (
  `bos_item_id`           int(11)        NOT NULL AUTO_INCREMENT,
  `bos_id`                int(11)        NOT NULL,
  `item_id`               int(11)        NOT NULL,
  `asset_code`            varchar(100)   DEFAULT NULL  COMMENT 'Asset tag / code at time of survey',
  `serial_number`         varchar(100)   DEFAULT NULL  COMMENT 'Serial number at time of survey',
  `quantity`              decimal(14,4)  NOT NULL       DEFAULT 1,
  `condition_at_survey`   varchar(100)   DEFAULT NULL,
  `item_recommendation`   enum(
                              'DISPOSE',
                              'REPAIR',
                              'TRANSFER',
                              'WRITE_OFF',
                              'RETAIN',
                              'AUCTION',
                              'DONATE',
                              'OTHER'
                          )              DEFAULT NULL,
  `estimated_value`       decimal(14,2)  DEFAULT NULL,
  `surveyor_notes`        text           DEFAULT NULL,
  `created_at`            timestamp      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bos_item_id`),
  KEY `fk_bosi_bos` (`bos_id`),
  KEY `fk_bosi_item` (`item_id`),
  CONSTRAINT `fk_bosi_bos`  FOREIGN KEY (`bos_id`)  REFERENCES `inv_board_of_survey` (`bos_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bosi_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items`           (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Line items for Board of Survey requests';

-- ── 3. Permissions ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('manage_board_of_survey',  'Initiate and manage Board of Survey requests'),
  ('approve_board_of_survey', 'Review and approve Board of Survey requests');

-- ── 4. Page permissions ─────────────────────────────────────────────────────
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
  ('/inventory/board_of_survey/list.php', 'manage_board_of_survey',  NOW()),
  ('/inventory/board_of_survey/add.php',  'manage_board_of_survey',  NOW()),
  ('/inventory/board_of_survey/view.php', 'manage_board_of_survey',  NOW());

-- ── 5. Assign permissions to roles (property management officer role = 4) ───
-- Grant manage_board_of_survey to roles that already have dispose_stock
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
FROM `role_permissions` rp
JOIN `permissions` p ON p.name = 'manage_board_of_survey'
WHERE rp.permission_id = (SELECT id FROM `permissions` WHERE name = 'dispose_stock' LIMIT 1)
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant approve_board_of_survey to roles that already have approve_disposal
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT rp.role_id, p.id
FROM `role_permissions` rp
JOIN `permissions` p ON p.name = 'approve_board_of_survey'
WHERE rp.permission_id = (SELECT id FROM `permissions` WHERE name = 'approve_disposal' LIMIT 1)
ON DUPLICATE KEY UPDATE role_id = role_id;

-- ============================================================================
-- VERIFICATION
-- SELECT bos_id, bos_number FROM inv_board_of_survey LIMIT 5;
-- SELECT name FROM permissions WHERE name LIKE '%board_of_survey%';
-- ============================================================================
