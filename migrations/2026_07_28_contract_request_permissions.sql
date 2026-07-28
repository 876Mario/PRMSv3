-- ============================================================================
-- Migration: Fix Contract Payment Request Permissions
-- Date: 2026-07-28
-- Purpose:
--   1. Grant create_service_request to roles that manage contracts so
--      Finance Officers and Procurement Officers can create payment requests.
--   2. Add page_permissions entry for /contracts/request.php.
-- ============================================================================

-- 1. Ensure the permission exists (idempotent)
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('create_service_request', 'Create service contract payment request');

-- 2. Grant create_service_request to Finance Officer and Procurement Officer
--    (they already have manage_contracts and should be able to create requests)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.role_name IN ('Finance Officer', 'Procurement Officer', 'HOD', 'Branch Head',
                      'Director HRM&A', 'Deputy Government Chemist', 'Admin', 'SuperAdmin')
  AND p.name = 'create_service_request';

-- 3. Register the payment request page in page_permissions
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
  ('/contracts/request.php', 'create_service_request', NOW());

-- ============================================================================
-- VERIFICATION
-- SELECT r.role_name, p.name FROM role_permissions rp
-- JOIN roles r ON r.role_id = rp.role_id
-- JOIN permissions p ON p.id = rp.permission_id
-- WHERE p.name = 'create_service_request';
-- ============================================================================
