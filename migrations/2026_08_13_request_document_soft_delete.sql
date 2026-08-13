-- ============================================================================
-- Migration: Request Document Soft Deletion
-- Date: 2026-08-13
-- Purpose:
--   1. Add soft-delete columns to request_documents so uploaded supporting
--      documents can be removed from active view while retaining the
--      original file, uploader, upload date, and deletion history for audit.
--   2. Add permission(s) controlling who may delete request documents,
--      including an elevated permission required to delete documents that
--      are already attached to a finalized (COMPLETED) request.
--   3. Grant the standard delete permission to Admin, HOD, and Branch Head
--      roles (plus SuperAdmin), and the elevated permission to Admin/SuperAdmin.
-- ============================================================================

-- 1. Soft-delete columns (naming follows rfq_vendors / rfq_quotes convention)
ALTER TABLE `request_documents`
    ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `deleted_by` VARCHAR(100) DEFAULT NULL COMMENT 'Full name of user who deleted the record' AFTER `is_deleted`,
    ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `deleted_by`,
    ADD COLUMN IF NOT EXISTS `deletion_reason` TEXT DEFAULT NULL COMMENT 'Reason provided by the user when deleting the document' AFTER `deleted_at`;

CREATE INDEX IF NOT EXISTS idx_request_documents_is_deleted ON request_documents(is_deleted);
CREATE INDEX IF NOT EXISTS idx_request_documents_request_id ON request_documents(request_id);

-- 2. Permissions
INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES
  ('procurement_delete_request_document', 'Delete uploaded request documents (soft delete)'),
  ('procurement_delete_finalized_document', 'Delete request documents already attached to a finalized/completed request');

-- 3. Grant standard delete permission to Admin, HOD, Branch Head (+ SuperAdmin)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'HOD', 'Branch Head', 'SuperAdmin')
  AND p.name = 'procurement_delete_request_document';

-- Grant the elevated (finalized-document) permission to Admin/SuperAdmin only
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'SuperAdmin')
  AND p.name = 'procurement_delete_finalized_document';

-- Register the delete endpoint's default permission (admins can override via page_permissions UI)
INSERT IGNORE INTO `page_permissions` (`page_path`, `permission_name`, `created_at`) VALUES
  ('/procurement/delete_document.php', 'procurement_delete_request_document', NOW());
