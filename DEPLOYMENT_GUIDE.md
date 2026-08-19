# Signed Request Management Extension - Deployment Guide

## Overview
This document provides instructions for deploying the Signed Request Management feature extension to reimbursement and petty_cash request types in the PRMS v3 system.

## Pre-Deployment Checklist

- [ ] Backup database (`mysqldump prmsv2 > backup_$(date +%s).sql`)
- [ ] Backup application files (full /var/www/prmsv3 or equivalent)
- [ ] Review and understand all migration scripts
- [ ] Verify all service files are in place
- [ ] Confirm Dompdf library is installed (`vendor/autoload.php` exists)
- [ ] Set up test environment and run tests
- [ ] Notify users of maintenance window (if required)

## Step 1: Database Migration

### 1.1 Apply Main Migration

```bash
# Navigate to project directory
cd /path/to/prmsv3

# Run the migration script
mysql -u root -p prmsv2 < migrations/2026_08_19_signed_request_reimbursement_petty_cash.sql

# Verify migration completed without errors
# Check that the following tables exist:
#   - procurement_requests (with signed request fields)
#   - signed_request_versions
#   - admin_edit_audit
#   - admin_action_log
#   - approval_invalidation_log
#   - doc_ctrl_settings (with REIMBURSEMENT and PETTY_CASH records)
```

### 1.2 Rollback Instructions

If the migration needs to be rolled back:

```sql
-- Drop new tables (in reverse order of dependencies)
DROP TABLE IF EXISTS approval_invalidation_log;
DROP TABLE IF EXISTS admin_action_log;
DROP TABLE IF EXISTS admin_edit_audit;
DROP TABLE IF EXISTS signed_request_versions;

-- Remove columns from existing tables
ALTER TABLE procurement_requests 
DROP COLUMN IF EXISTS signed_request_document_path,
DROP COLUMN IF EXISTS signed_request_received_date,
DROP COLUMN IF EXISTS signed_by_user_id,
DROP COLUMN IF EXISTS doc_ctrl_form_revision,
DROP COLUMN IF EXISTS doc_ctrl_effective_date,
DROP COLUMN IF EXISTS doc_ctrl_dcr_number;

ALTER TABLE request_documents
DROP COLUMN IF EXISTS print_count,
DROP COLUMN IF EXISTS last_printed_at,
DROP COLUMN IF EXISTS last_printed_by;

-- Update doc_ctrl_settings to remove entries (or keep them if 
-- they should persist for future use)
DELETE FROM doc_ctrl_settings 
WHERE request_type IN ('REIMBURSEMENT', 'PETTY_CASH');
```

## Step 2: Deploy Service Files

```bash
# Copy service files to services directory
cp services/RequestPrintService.php /path/to/prmsv3/services/
cp services/RequestDocumentService.php /path/to/prmsv3/services/
cp services/AdminEditService.php /path/to/prmsv3/services/

# Verify file permissions (should be readable by web server)
chmod 644 /path/to/prmsv3/services/RequestPrintService.php
chmod 644 /path/to/prmsv3/services/RequestDocumentService.php
chmod 644 /path/to/prmsv3/services/AdminEditService.php
```

## Step 3: Deploy Request Module Files

```bash
# Deploy reimbursement module files
cp reimbursement/print_for_approval.php /path/to/prmsv3/reimbursement/
cp reimbursement/upload_signed_form.php /path/to/prmsv3/reimbursement/

# Deploy petty cash module files
cp petty_cash/print_for_approval.php /path/to/prmsv3/petty_cash/
cp petty_cash/upload_signed_form.php /path/to/prmsv3/petty_cash/

# Verify file permissions
chmod 644 /path/to/prmsv3/reimbursement/print_for_approval.php
chmod 644 /path/to/prmsv3/reimbursement/upload_signed_form.php
chmod 644 /path/to/prmsv3/petty_cash/print_for_approval.php
chmod 644 /path/to/prmsv3/petty_cash/upload_signed_form.php
```

## Step 4: Initialize Document Control Settings

```bash
# SSH into server and run PHP script to initialize document control settings
php -r "
require '/path/to/prmsv3/config/db.php';

// Initialize doc_ctrl_settings
\$stmt = \$pdo->query('SELECT COUNT(*) as count FROM doc_ctrl_settings');
\$result = \$stmt->fetch(PDO::FETCH_ASSOC);

if (\$result['count'] == 0) {
    \$insertStmt = \$pdo->prepare('
        INSERT INTO doc_ctrl_settings 
        (request_type, form_revision, effective_date, dcr_number, updated_at)
        VALUES (?, ?, ?, ?, NOW())
    ');
    
    \$insertStmt->execute(['REGULAR', '1.0', date('Y-m-d'), 'DCR-2026-001']);
    \$insertStmt->execute(['REIMBURSEMENT', '1.0', date('Y-m-d'), 'DCR-2026-002']);
    \$insertStmt->execute(['PETTY_CASH', '1.0', date('Y-m-d'), 'DCR-2026-003']);
    
    echo 'Document control settings initialized successfully.\n';
} else {
    echo 'Document control settings already exist.\n';
}
"
```

## Step 5: Create Upload Directories

```bash
# Create upload directories if they don't exist
mkdir -p /path/to/prmsv3/uploads/signed_requests/
mkdir -p /path/to/prmsv3/uploads/reimbursement_invoice_attachments/
mkdir -p /path/to/prmsv3/uploads/petty_cash_reconciliation_documents/

# Set proper permissions for web server write access
chmod 755 /path/to/prmsv3/uploads/signed_requests/
chmod 755 /path/to/prmsv3/uploads/reimbursement_invoice_attachments/
chmod 755 /path/to/prmsv3/uploads/petty_cash_reconciliation_documents/
```

## Step 6: Run Tests

```bash
# Run unit tests
php tests/RequestDocumentServiceTest.php
php tests/AdminEditServiceTest.php

# Check for any failures - if tests fail, do NOT proceed to production
```

## Step 7: Verify Composer Dependencies

```bash
# Ensure Dompdf is installed
cd /path/to/prmsv3
composer install

# Verify vendor/autoload.php exists
ls -la vendor/autoload.php
```

## Step 8: Integration Testing

### 8.1 Test Reimbursement Print

1. Log in as Finance Officer
2. Navigate to a SUBMITTED reimbursement request
3. Click "Print for Approval" button
4. Verify PDF generates successfully
5. Check PDF contains:
   - Request number and date
   - Invoice details
   - Document control information
   - Signature sections

### 8.2 Test Reimbursement Upload

1. Print the approval form
2. Sign it manually
3. Return to request view
4. Upload signed form
5. Verify success message
6. Check that version is recorded in signed_request_versions table

### 8.3 Test Petty Cash Print

1. Log in as Procurement Officer
2. Navigate to a SUBMITTED petty cash request
3. Click "Print for Approval" button
4. Verify PDF generates successfully
5. Check PDF contains:
   - Disbursement details
   - Reconciliation summary
   - Document control information
   - Signature sections

### 8.4 Test Petty Cash Upload

1. Print the reconciliation form
2. Sign it manually
3. Return to request view
4. Upload signed form
5. Verify success message

### 8.5 Test Admin Editing

1. Log in as Admin
2. Navigate to any request
3. Verify you can edit allowed fields based on status
4. Check that edits are recorded in admin_edit_audit table
5. Verify approval_invalidation_log is populated for critical fields

### 8.6 Test Audit Logging

1. Perform various actions (print, upload, edit)
2. Check admin_action_log table
3. Verify entries contain:
   - User ID and role
   - Action type
   - Resource information
   - IP address and user-agent
   - Timestamp

## Step 9: Security Verification

```bash
# Check file permissions
find /path/to/prmsv3 -name "*.php" -path "*/services/*" -type f -exec ls -la {} \;
find /path/to/prmsv3/uploads -type d -exec ls -ld {} \;

# Verify database permissions (no direct file access)
# Confirm uploads directory is outside document root if possible

# Check for hardcoded credentials (should find none)
grep -r "password\|secret\|token" /path/to/prmsv3/services/ | grep -v "// " || echo "No hardcoded credentials found"
```

## Post-Deployment Monitoring

### 10.1 Watch Logs

```bash
# Monitor error logs for any issues
tail -f /var/log/apache2/error.log | grep -i "prmsv3\|signed\|admin"

# Monitor application logs
tail -f /path/to/prmsv3/logs/app.log (if application logging is configured)
```

### 10.2 Database Monitoring

```bash
# Monitor audit tables for activity
mysql -u root -p prmsv2 -e "SELECT COUNT(*) as audit_count FROM admin_action_log;"
mysql -u root -p prmsv2 -e "SELECT COUNT(*) as edit_count FROM admin_edit_audit;"
```

### 10.3 File Upload Monitoring

```bash
# Monitor uploaded files
ls -lah /path/to/prmsv3/uploads/signed_requests/ | head -20
du -sh /path/to/prmsv3/uploads/signed_requests/
```

## Troubleshooting

### Issue: Dompdf not found

**Solution:**
```bash
cd /path/to/prmsv3
composer install
composer require dompdf/dompdf
```

### Issue: Permission denied on upload directory

**Solution:**
```bash
sudo chown www-data:www-data /path/to/prmsv3/uploads/signed_requests/
sudo chmod 755 /path/to/prmsv3/uploads/signed_requests/
```

### Issue: PDF generation fails

**Solution:**
1. Check Dompdf error log: `/tmp/dompdf.log`
2. Verify PHP temp directory is writable
3. Check for memory limits in php.ini

### Issue: Database migration fails

**Solution:**
1. Check MySQL error log: `/var/log/mysql/error.log`
2. Verify database user has ALTER TABLE permissions
3. Check for SQL syntax errors in migration script

## Rollback Procedure

If you need to rollback the deployment:

1. **Database Rollback:**
   ```bash
   mysql -u root -p prmsv2 < migrations/2026_08_19_signed_request_reimbursement_petty_cash.sql.rollback
   ```
   Or run the manual rollback SQL from Step 1.2

2. **File Rollback:**
   ```bash
   # Remove deployed files
   rm /path/to/prmsv3/services/RequestPrintService.php
   rm /path/to/prmsv3/services/RequestDocumentService.php
   rm /path/to/prmsv3/services/AdminEditService.php
   rm /path/to/prmsv3/reimbursement/print_for_approval.php
   rm /path/to/prmsv3/reimbursement/upload_signed_form.php
   rm /path/to/prmsv3/petty_cash/print_for_approval.php
   rm /path/to/prmsv3/petty_cash/upload_signed_form.php
   
   # Restore from backup if needed
   ```

3. **Notify Users:**
   Inform users that the feature has been rolled back and to retry their operations.

## Support and Contact

For issues or questions about this deployment:
- Review logs at `/var/log/apache2/error.log`
- Check database error logs
- Contact system administrator
- Refer to the Technical Documentation in `SIGNED_REQUEST_IMPLEMENTATION.md`

---

**Deployment Date:** ___________  
**Deployed By:** ___________  
**Verified By:** ___________  
