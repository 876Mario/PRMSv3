-- ============================================================
-- Migration: 2026_08_19_add_branch_id_to_users.sql
-- Purpose : Add branch_id column to users table to enable
--           HOD/Branch Head approval scope determination.
--           
-- Context : HOD and Branch Head roles need to be scoped
--           to their assigned branches to ensure they only
--           approve requests from their department/branch.
--           
--           Fixes: SQLSTATE[42S22] Unknown column 'branch_id' 
--           error in getApproverScope() function.
-- ============================================================

-- --------------------------------------------------------
-- Phase 1: Add branch_id column to users table
-- --------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `branch_id` INT(11) DEFAULT NULL
    COMMENT 'FK → branches.branch_id for HOD/Branch Head scope'
    AFTER `role_id`;

-- --------------------------------------------------------
-- Phase 2: Add index for efficient lookups
-- --------------------------------------------------------
CREATE INDEX IF NOT EXISTS `idx_users_branch_id` 
  ON `users`(`branch_id`);

-- --------------------------------------------------------
-- Phase 3: Add foreign key constraint
-- Uses a stored procedure pattern to safely check
-- if the constraint already exists before adding it
-- --------------------------------------------------------
DROP PROCEDURE IF EXISTS `_add_fk_users_branch_id`;

CREATE PROCEDURE `_add_fk_users_branch_id`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
    WHERE TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_branch_id'
  ) THEN
    ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_branch_id`
      FOREIGN KEY (`branch_id`) REFERENCES `branches`(`branch_id`)
      ON DELETE SET NULL;
  END IF;
END;

CALL `_add_fk_users_branch_id`();
DROP PROCEDURE IF EXISTS `_add_fk_users_branch_id`;

-- --------------------------------------------------------
-- Phase 4: Audit - List users without branch assignments
-- Run this manually to identify HOD/Branch Head users
-- needing branch assignments:
-- --------------------------------------------------------
-- SELECT 
--   u.user_id, 
--   u.full_name, 
--   r.name as role_name,
--   u.branch_id
-- FROM users u
-- LEFT JOIN roles r ON u.role_id = r.id
-- WHERE u.branch_id IS NULL
--   AND r.name IN ('HOD', 'Branch Head');
