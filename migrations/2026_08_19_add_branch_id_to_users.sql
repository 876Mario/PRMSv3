-- ============================================================
-- Migration: 2026_08_19_add_branch_id_to_users.sql
-- Purpose : Add branch_id column to users table to enable
--           HOD/Branch Head approval scope determination.
--           
-- Context : HOD and Branch Head roles need to be scoped
--           to their assigned branches to ensure they only
--           approve requests from their department/branch.
-- ============================================================

-- --------------------------------------------------------
-- Add branch_id column to users table
-- --------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `branch_id` INT(11) DEFAULT NULL
    COMMENT 'FK → branches.branch_id for HOD/Branch Head scope'
    AFTER `role_id`,
  ADD KEY `idx_users_branch_id` (`branch_id`),
  ADD CONSTRAINT `fk_users_branch_id`
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`branch_id`)
    ON DELETE SET NULL;

-- --------------------------------------------------------
-- Audit: List users without branch_id assignments
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
