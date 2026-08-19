-- Migration: Add signed request support for reimbursement and petty_cash request types
-- Purpose: Extend Signed Request Management feature to reimbursement_requests and petty_cash_requests
-- Date: 2026-08-19

-- This migration extends the existing signed request fields to handle all request types

-- 1. Verify procurement_requests table has signed request fields
-- These should already exist from earlier migrations but we document them here
ALTER TABLE procurement_requests 
ADD COLUMN IF NOT EXISTS signed_request_document_path VARCHAR(255) DEFAULT NULL COMMENT 'Path to uploaded signed approval form',
ADD COLUMN IF NOT EXISTS signed_request_received_date DATETIME DEFAULT NULL COMMENT 'When signed form was received',
ADD COLUMN IF NOT EXISTS signed_by_user_id INT DEFAULT NULL COMMENT 'User ID of person who uploaded signed form',
ADD COLUMN IF NOT EXISTS doc_ctrl_form_revision VARCHAR(100) DEFAULT NULL COMMENT 'Form revision at time of generation',
ADD COLUMN IF NOT EXISTS doc_ctrl_effective_date DATE DEFAULT NULL COMMENT 'Form effective date at time of generation',
ADD COLUMN IF NOT EXISTS doc_ctrl_dcr_number VARCHAR(100) DEFAULT NULL COMMENT 'DCR number at time of generation';

-- 2. Ensure doc_ctrl_settings table has entries for all request types
-- This table is used to store document control settings for form generation
CREATE TABLE IF NOT EXISTS doc_ctrl_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_type ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH') UNIQUE NOT NULL COMMENT 'Request type these settings apply to',
    form_revision VARCHAR(100) NOT NULL COMMENT 'Current form revision number',
    effective_date DATE NOT NULL COMMENT 'Date this revision became effective',
    dcr_number VARCHAR(100) NOT NULL COMMENT 'DCR (Design Control Record) number',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_id INT DEFAULT NULL,
    updated_by_name VARCHAR(255) DEFAULT NULL,
    INDEX idx_request_type (request_type),
    INDEX idx_updated_at (updated_at)
);

-- Initialize doc_ctrl_settings for all request types if not exists
INSERT IGNORE INTO doc_ctrl_settings (id, request_type, form_revision, effective_date, dcr_number)
VALUES 
    (1, 'REGULAR', '1.0', CURDATE(), 'DCR-2026-001'),
    (2, 'REIMBURSEMENT', '1.0', CURDATE(), 'DCR-2026-002'),
    (3, 'PETTY_CASH', '1.0', CURDATE(), 'DCR-2026-003');

-- 3. Create admin_edits audit table for tracking administrative modifications
-- This provides a dedicated audit trail for admin-only operations
CREATE TABLE IF NOT EXISTS admin_edit_audit (
    edit_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL COMMENT 'Procurement request ID',
    request_type ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH') NOT NULL COMMENT 'Type of request edited',
    request_number VARCHAR(50) NOT NULL COMMENT 'Request number for easy reference',
    field_name VARCHAR(100) NOT NULL COMMENT 'Name of field that was edited',
    old_value LONGTEXT COMMENT 'Previous value (nullable for NULL-to-value changes)',
    new_value LONGTEXT COMMENT 'New value (nullable for value-to-NULL changes)',
    change_reason TEXT COMMENT 'Why admin made this change',
    affected_approvals TEXT COMMENT 'List of approvals affected/invalidated by this edit',
    edited_by INT NOT NULL COMMENT 'User ID of admin making the edit',
    editor_role VARCHAR(50) COMMENT 'Role of admin making edit (Admin or SuperAdmin)',
    editor_ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address of editing user',
    editor_user_agent VARCHAR(500) DEFAULT NULL COMMENT 'User-Agent of editing user',
    edited_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When edit was made',
    
    CONSTRAINT fk_admin_edit_request FOREIGN KEY (request_id) 
        REFERENCES procurement_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_edit_user FOREIGN KEY (edited_by) 
        REFERENCES users(user_id),
    
    INDEX idx_request_id (request_id),
    INDEX idx_request_type (request_type),
    INDEX idx_edited_by (edited_by),
    INDEX idx_edited_at (edited_at),
    INDEX idx_field_name (field_name),
    INDEX idx_admin_edit_composite (request_type, request_id, edited_at)
);

-- 4. Add columns to admin_edit_audit for tracking approval invalidations
ALTER TABLE admin_edit_audit
ADD COLUMN IF NOT EXISTS requires_re_approval BOOLEAN DEFAULT 0 COMMENT 'Whether this edit requires re-approval',
ADD COLUMN IF NOT EXISTS approval_stages_affected JSON DEFAULT NULL COMMENT 'JSON array of approval stages affected';

-- 5. Create admin_action_log table for tracking all sensitive admin actions
CREATE TABLE IF NOT EXISTS admin_action_log (
    action_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    admin_user_id INT NOT NULL COMMENT 'User ID of admin performing action',
    admin_role VARCHAR(50) NOT NULL COMMENT 'Role of admin (Admin or SuperAdmin)',
    action_type VARCHAR(50) NOT NULL COMMENT 'Type of action: EDIT, DELETE, APPROVE, REJECT, STATUS_CHANGE, DOCUMENT_UPLOAD, DOCUMENT_DELETE',
    resource_type VARCHAR(50) NOT NULL COMMENT 'Type of resource affected: REQUEST, DOCUMENT, APPROVAL, WORKFLOW',
    resource_id VARCHAR(100) COMMENT 'ID of affected resource',
    resource_identifier VARCHAR(100) COMMENT 'Human-readable identifier (e.g., request number)',
    action_description TEXT COMMENT 'Detailed description of action taken',
    status_before VARCHAR(50) COMMENT 'Status before action',
    status_after VARCHAR(50) COMMENT 'Status after action',
    ip_address VARCHAR(45) COMMENT 'IP address of admin',
    user_agent VARCHAR(500) COMMENT 'User-Agent header',
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When action occurred',
    
    CONSTRAINT fk_admin_action_user FOREIGN KEY (admin_user_id) 
        REFERENCES users(user_id) ON DELETE CASCADE,
    
    INDEX idx_admin_user_id (admin_user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_timestamp (timestamp),
    INDEX idx_resource_type (resource_type),
    INDEX idx_admin_action_composite (admin_user_id, timestamp)
);

-- 6. Create signed_request_versions table to track document replacement history
CREATE TABLE IF NOT EXISTS signed_request_versions (
    version_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL COMMENT 'Procurement request ID',
    document_path VARCHAR(255) NOT NULL COMMENT 'Path to the signed document file',
    file_name VARCHAR(255) NOT NULL COMMENT 'Original filename provided',
    file_size BIGINT COMMENT 'File size in bytes',
    mime_type VARCHAR(100) COMMENT 'MIME type of uploaded file',
    uploaded_by INT NOT NULL COMMENT 'User ID who uploaded',
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload timestamp',
    is_active BOOLEAN DEFAULT 1 COMMENT 'Whether this is the current active version',
    replacement_reason TEXT COMMENT 'Why this version was replaced (if applicable)',
    replaced_at DATETIME DEFAULT NULL COMMENT 'When this version was replaced',
    replaced_by INT DEFAULT NULL COMMENT 'User ID of person who replaced it',
    
    CONSTRAINT fk_signed_version_request FOREIGN KEY (request_id) 
        REFERENCES procurement_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_signed_version_uploader FOREIGN KEY (uploaded_by) 
        REFERENCES users(user_id),
    CONSTRAINT fk_signed_version_replacer FOREIGN KEY (replaced_by) 
        REFERENCES users(user_id),
    
    INDEX idx_request_id (request_id),
    INDEX idx_is_active (is_active),
    INDEX idx_uploaded_at (uploaded_at),
    INDEX idx_signed_version_composite (request_id, is_active, uploaded_at)
);

-- 7. Migrate existing signed request data to versioning table
INSERT INTO signed_request_versions 
  (request_id, document_path, file_name, uploaded_by, uploaded_at, is_active)
SELECT 
  request_id, 
  signed_request_document_path,
  COALESCE(SUBSTRING_INDEX(signed_request_document_path, '/', -1), 'signed_request.pdf'),
  signed_by_user_id,
  COALESCE(signed_request_received_date, NOW()),
  1
FROM procurement_requests 
WHERE signed_request_document_path IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM signed_request_versions 
    WHERE signed_request_versions.request_id = procurement_requests.request_id
  );

-- 8. Add indexes for performance on audit queries
ALTER TABLE audit_log
ADD INDEX IF NOT EXISTS idx_audit_table_record (table_name, record_id),
ADD INDEX IF NOT EXISTS idx_audit_action (action),
ADD INDEX IF NOT EXISTS idx_audit_changed_by (changed_by),
ADD INDEX IF NOT EXISTS idx_audit_change_date (change_date);

-- 9. Create approval_invalidation_log table
CREATE TABLE IF NOT EXISTS approval_invalidation_log (
    invalidation_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL COMMENT 'Request ID',
    approval_id INT COMMENT 'Related approval ID if applicable',
    approval_stage VARCHAR(50) COMMENT 'Approval stage affected',
    invalidated_by INT NOT NULL COMMENT 'User ID (usually admin making edit)',
    invalidation_reason TEXT COMMENT 'Why approval was invalidated',
    fields_affected JSON COMMENT 'JSON array of fields that triggered invalidation',
    was_reinstated BOOLEAN DEFAULT 0 COMMENT 'Whether approval was later reinstated',
    reinstated_at DATETIME COMMENT 'When reinstated if applicable',
    reinstated_by INT COMMENT 'User ID who reinstated',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_invalid_request FOREIGN KEY (request_id) 
        REFERENCES procurement_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_invalid_user FOREIGN KEY (invalidated_by) 
        REFERENCES users(user_id),
    CONSTRAINT fk_reinstated_user FOREIGN KEY (reinstated_by) 
        REFERENCES users(user_id),
    
    INDEX idx_request_id (request_id),
    INDEX idx_approval_stage (approval_stage),
    INDEX idx_created_at (created_at),
    INDEX idx_was_reinstated (was_reinstated)
);

-- 10. Update request_documents table to track document print events
ALTER TABLE request_documents
ADD COLUMN IF NOT EXISTS print_count INT DEFAULT 0 COMMENT 'Number of times document was printed',
ADD COLUMN IF NOT EXISTS last_printed_at DATETIME DEFAULT NULL COMMENT 'Last time document was printed',
ADD COLUMN IF NOT EXISTS last_printed_by INT DEFAULT NULL COMMENT 'User ID of last person who printed',
ADD INDEX IF NOT EXISTS idx_print_count (print_count);

-- Commit marker for this migration
-- This migration is idempotent and safe to run multiple times
