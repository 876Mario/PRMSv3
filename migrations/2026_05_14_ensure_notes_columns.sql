-- ============================================================
-- Migration: 2026_05_14_ensure_notes_columns.sql
-- Purpose  : Ensure legacy databases include `notes` columns
--            required by current INSERT statements.
-- ============================================================

-- 1) audit_log.notes (used widely by workflow/audit inserts)
ALTER TABLE `audit_log`
ADD COLUMN IF NOT EXISTS `notes` TEXT NULL AFTER `change_date`;

-- 2) request_documents.notes (used by procurement document upload)
ALTER TABLE `request_documents`
ADD COLUMN IF NOT EXISTS `notes` TEXT NULL AFTER `uploaded_at`;

-- 3) Log migration (only if audit_log table exists and has notes column)
INSERT IGNORE INTO `audit_log` (`table_name`, `record_id`, `action`, `changed_by`, `notes`)
VALUES ('MIGRATION', NULL, 'SCHEMA_FIX', 'system',
        '2026_05_14_ensure_notes_columns applied');
