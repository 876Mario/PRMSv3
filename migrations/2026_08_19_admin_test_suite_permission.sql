-- ============================================================
-- Migration: 2026_08_19_admin_test_suite_permission.sql
-- Purpose: Add 'access_test_suite' permission and assign it
--          to Admin and SuperAdmin roles only.
-- ============================================================

-- Step 1: Register the permission
INSERT IGNORE INTO `permissions` (`name`, `description`)
VALUES (
    'access_test_suite',
    'Access and run the admin PHPUnit test suite'
);

-- Step 2: Assign to Admin and SuperAdmin roles only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT
    r.id,
    p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE p.name = 'access_test_suite'
  AND r.name IN ('Admin', 'SuperAdmin');

-- Step 3: Register the page path so the guard can resolve it
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `is_active`)
VALUES ('/admin/test_suite.php', 'access_test_suite', 1);
