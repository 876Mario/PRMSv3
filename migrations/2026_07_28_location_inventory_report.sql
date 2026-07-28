-- ============================================================================
-- Migration: Inventory Report by Location — Page Permission
-- Date: 2026-07-28
-- Purpose:
--   Register the new location_inventory report pages in page_permissions so
--   the route guard allows access for users with view_inventory_reports.
-- ============================================================================

-- 1. Register the report page and its export helpers in page_permissions
INSERT IGNORE INTO `page_permissions`
    (`page_path`, `permission_name`, `created_at`)
VALUES
    ('/inventory/reports/location_inventory.php',   'view_inventory_reports', NOW()),
    ('/inventory/reports/export_location_excel.php','view_inventory_reports', NOW());

-- ============================================================================
-- VERIFICATION
-- SELECT page_path, permission_name FROM page_permissions
-- WHERE page_path LIKE '%location_inventory%';
-- ============================================================================
