-- =============================================================================
-- Migration 2026_07_29: Job Titles Master List, User Job Title FK,
--                        Asset Location FK, Department-Location Mapping
-- =============================================================================

-- ----------------------------------------------------------------------------
-- 1. JOB TITLES master table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_titles` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `title_name`  varchar(150) NOT NULL,
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order`  int(11)      NOT NULL DEFAULT 0,
  `created_at`  timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_job_title_name` (`title_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the master list (INSERT IGNORE so re-running is safe)
INSERT IGNORE INTO `job_titles` (`title_name`, `sort_order`) VALUES
  ('Government Chemist',                                         1),
  ('Deputy Government Chemist',                                  2),
  ('Director Finance & Accounts',                                3),
  ('Director, Public Procurement',                               4),
  ('Quality Assurance Manager',                                  5),
  ('Director Human Resource Management & Administration',        6),
  ('Senior Chemist',                                             7),
  ('Senior Human Resource Officer',                              8),
  ('Analyst',                                                    9),
  ('Technical and User Support Officer',                        10),
  ('Office / Property Management Officer',                      11),
  ('Records Officer',                                           12),
  ('Public Procurement Officer',                                13),
  ('Payroll Officer',                                           14),
  ('Accounts Payable Officer',                                  15),
  ('Administrator',                                             16),
  ('Administrative Assistant',                                  17),
  ('Watchman',                                                  18),
  ('Watchman (Day)',                                            19),
  ('Relief Watchman',                                           20),
  ('Messenger / Laboratory Attendant',                          21),
  ('Laboratory Attendant',                                      22),
  ('Office Attendant (Downstairs)',                             23),
  ('Office Attendant (Upstairs)',                               24),
  ('Relief Office Attendant',                                   25),
  ('Telephone Operator',                                        26),
  ('Caretaker / Groundsman (Caretaker Quarters)',               27),
  ('Relief Caretaker',                                          28);

-- ----------------------------------------------------------------------------
-- 2. Add job_title_id FK to users
-- ----------------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `job_title_id` int(11) DEFAULT NULL
    COMMENT 'FK → job_titles.id'
    AFTER `role_id`;

ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_users_job_title` (`job_title_id`);

-- Add FK only if not already present (check constraint name)
SET @fk_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME         = 'users'
    AND CONSTRAINT_NAME    = 'fk_users_job_title'
    AND CONSTRAINT_TYPE    = 'FOREIGN KEY'
);
-- We use a PROCEDURE to conditionally add the FK
DROP PROCEDURE IF EXISTS `_add_fk_users_job_title`;
DELIMITER $$
CREATE PROCEDURE `_add_fk_users_job_title`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME         = 'users'
      AND CONSTRAINT_NAME    = 'fk_users_job_title'
  ) THEN
    ALTER TABLE `users`
      ADD CONSTRAINT `fk_users_job_title`
      FOREIGN KEY (`job_title_id`) REFERENCES `job_titles` (`id`)
      ON UPDATE CASCADE ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;
CALL `_add_fk_users_job_title`();
DROP PROCEDURE IF EXISTS `_add_fk_users_job_title`;

-- ----------------------------------------------------------------------------
-- 3. Add location_id FK to inv_asset_details (for proper location filtering)
-- ----------------------------------------------------------------------------
ALTER TABLE `inv_asset_details`
  ADD COLUMN IF NOT EXISTS `location_id` int(11) DEFAULT NULL
    COMMENT 'FK → inv_locations.location_id – replaces free-text site/building/floor_room for filtering'
    AFTER `address`;

ALTER TABLE `inv_asset_details`
  ADD INDEX IF NOT EXISTS `idx_asset_detail_location` (`location_id`);

DROP PROCEDURE IF EXISTS `_add_fk_asset_detail_location`;
DELIMITER $$
CREATE PROCEDURE `_add_fk_asset_detail_location`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME         = 'inv_asset_details'
      AND CONSTRAINT_NAME    = 'fk_asset_detail_location'
  ) THEN
    ALTER TABLE `inv_asset_details`
      ADD CONSTRAINT `fk_asset_detail_location`
      FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`location_id`)
      ON UPDATE CASCADE ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;
CALL `_add_fk_asset_detail_location`();
DROP PROCEDURE IF EXISTS `_add_fk_asset_detail_location`;

-- Backfill location_id where site/building/room match an inv_locations record
UPDATE `inv_asset_details` ad
  JOIN `inv_locations` l
    ON  (ad.site     = l.site_campus        OR (ad.site     IS NULL AND l.site_campus IS NULL))
    AND (ad.building = l.building           OR (ad.building IS NULL AND l.building    IS NULL))
    AND (ad.floor_room = l.room_storage_area OR (ad.floor_room IS NULL AND l.room_storage_area IS NULL))
SET ad.location_id = l.location_id
WHERE ad.location_id IS NULL;

-- ----------------------------------------------------------------------------
-- 4. DEPARTMENT-LOCATION MAPPING
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `department_location_mappings` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `branch_id`   int(11) NOT NULL COMMENT 'FK → branches.branch_id',
  `location_id` int(11) NOT NULL COMMENT 'FK → inv_locations.location_id',
  `created_at`  timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dept_loc` (`branch_id`, `location_id`),
  KEY `idx_dlm_branch`   (`branch_id`),
  KEY `idx_dlm_location` (`location_id`),
  CONSTRAINT `fk_dlm_branch`
    FOREIGN KEY (`branch_id`)   REFERENCES `branches`      (`branch_id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_dlm_location`
    FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`location_id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
