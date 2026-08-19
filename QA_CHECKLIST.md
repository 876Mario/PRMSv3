# Signed Request Management Extension - QA Checklist

## Pre-Testing Setup

- [ ] Database migration applied successfully
- [ ] All service files deployed
- [ ] Upload directories created with correct permissions
- [ ] Dompdf library installed and verified
- [ ] Document control settings initialized
- [ ] Test data created (sample requests)
- [ ] Test users created for different roles

---

## Module: Reimbursement Print-for-Approval

### Test Case 1: Basic Print Functionality
- [ ] User role: Finance Officer
- [ ] Request status: SUBMITTED
- [ ] Click "Print for Approval" button
- [ ] PDF generates without error
- [ ] PDF displays in browser without corruption
- [ ] Can save/download PDF locally
- [ ] PDF filename follows pattern: `REIMB_[REQUEST_NUMBER]_[DATE]_[TIME].pdf`

### Test Case 2: PDF Content Verification
- [ ] Request number displayed correctly
- [ ] Request date displayed correctly
- [ ] Branch name displayed correctly
- [ ] Requestor name displayed correctly
- [ ] Invoice ID displayed (if attached)
- [ ] Invoice amount displayed with currency
- [ ] Document control information visible
- [ ] Signature lines properly formatted
- [ ] Footer text present and readable
- [ ] No sensitive data exposed

### Test Case 3: Authorization - Valid Users
- [ ] Finance Officer can print
- [ ] Director HRM&A can print
- [ ] Admin can print
- [ ] SuperAdmin can print
- [ ] Requestor can print their own request

### Test Case 4: Authorization - Invalid Users
- [ ] Requestor cannot print other's reimbursement
- [ ] Regular Procurement Officer cannot print
- [ ] Unauthorized role gets 403 error
- [ ] Unauthorized access attempt logged in audit log

### Test Case 5: Request Type Validation
- [ ] Only REIMBURSEMENT type requests can be printed
- [ ] Attempting to print REGULAR request gives 404
- [ ] Attempting to print PETTY_CASH request gives 404

### Test Case 6: Status Constraints
- [ ] Can print when status is SUBMITTED
- [ ] Can print when status is FUNDS_VERIFIED
- [ ] Can print when status is INVOICE_SUBMITTED
- [ ] Can print when status is INVOICE_VERIFIED
- [ ] Can print when status is APPROVED

### Test Case 7: Document Control Snapshot
- [ ] On first print, form revision stored in database
- [ ] On first print, effective date stored in database
- [ ] On first print, DCR number stored in database
- [ ] On second print, same values used (historical accuracy)
- [ ] Subsequent prints show consistent document metadata

---

## Module: Reimbursement Signed Form Upload

### Test Case 8: Basic Upload Functionality
- [ ] User role: Finance Officer
- [ ] Request status: SUBMITTED
- [ ] Select valid PDF file
- [ ] Click "Upload Signed Form" button
- [ ] Upload completes without error
- [ ] Success message displayed
- [ ] Page redirects to request view

### Test Case 9: File Type Validation
- [ ] PDF files accepted (.pdf)
- [ ] JPEG images accepted (.jpg, .jpeg)
- [ ] PNG images accepted (.png)
- [ ] GIF images accepted (.gif)
- [ ] Word documents accepted (.doc, .docx)
- [ ] Non-conforming file rejected with error message
- [ ] Error message explains acceptable file types
- [ ] Executable files (.exe, .bat) rejected
- [ ] Invalid MIME type rejected

### Test Case 10: File Size Validation
- [ ] Files up to 25 MB accepted
- [ ] File > 25 MB rejected with error
- [ ] Error message explains size limit
- [ ] 100 KB file uploads successfully
- [ ] 24.9 MB file uploads successfully
- [ ] 25.1 MB file rejected

### Test Case 11: Upload Security
- [ ] Original filename not used in storage
- [ ] Safe filename generated (SIGNED_R_[ID]_[TIMESTAMP]_[UNIQID].[ext])
- [ ] File stored outside web-accessible directory (if possible)
- [ ] No path traversal possible (e.g., `../../../etc/passwd`)
- [ ] File extension validated server-side
- [ ] MIME type validated server-side

### Test Case 12: Version History Tracking
- [ ] First upload creates version 1
- [ ] Second upload creates version 2, marks version 1 inactive
- [ ] Version table shows upload timestamps
- [ ] Version table shows uploader user ID
- [ ] Current active version linked in procurement_requests
- [ ] Previous versions still accessible in history

### Test Case 13: Authorization - Upload
- [ ] Requestor can upload their own signed form
- [ ] Finance Officer can upload
- [ ] Director HRM&A can upload
- [ ] Admin can upload
- [ ] SuperAdmin can upload
- [ ] Requestor cannot upload others' forms
- [ ] Unauthorized users get error message

### Test Case 14: Audit Logging - Upload
- [ ] Upload logged in request_documents table
- [ ] Upload logged in admin_action_log table
- [ ] Audit record shows user ID
- [ ] Audit record shows user role
- [ ] Audit record shows request number
- [ ] Audit record shows original filename
- [ ] Audit record shows upload timestamp
- [ ] Audit record shows IP address (if available)

### Test Case 15: Database Persistence
- [ ] signed_request_document_path updated correctly
- [ ] signed_request_received_date set to current timestamp
- [ ] signed_by_user_id set to uploader's user ID
- [ ] Record visible in request_documents table
- [ ] Record visible in signed_request_versions table
- [ ] Transaction rolled back on database error

### Test Case 16: Notification Dispatch
- [ ] Upload triggers notification to Procurement Officers
- [ ] Notification contains request number
- [ ] Notification contains upload timestamp
- [ ] Notification contains uploader name
- [ ] In-app notification created (if available)
- [ ] Email notification sent (if configured)

---

## Module: Petty Cash Print-for-Approval

### Test Case 17: Basic Print Functionality
- [ ] User role: Procurement Officer
- [ ] Request status: PENDING_RECONCILIATION
- [ ] Click "Print for Reconciliation" button
- [ ] PDF generates without error
- [ ] PDF displays in browser
- [ ] Can save/download locally
- [ ] PDF filename follows pattern: `PETTY_CASH_[REQUEST_NUMBER]_[DATE]_[TIME].pdf`

### Test Case 18: PDF Content Verification
- [ ] Request number displayed
- [ ] Request date displayed
- [ ] Branch name displayed
- [ ] Disbursement amount displayed
- [ ] Reconciliation deadline displayed
- [ ] 24-hour deadline clearly stated
- [ ] Signature lines for procurement officer, finance officer, director
- [ ] Document control information visible
- [ ] Instructions for reconciliation clear

### Test Case 19: Authorization - Print Petty Cash
- [ ] Procurement Officer can print
- [ ] Finance Officer can print
- [ ] Director HRM&A can print
- [ ] Admin can print
- [ ] SuperAdmin can print
- [ ] Requestor can print their own request

### Test Case 20: Request Type Validation - Petty Cash
- [ ] Only PETTY_CASH type requests can print
- [ ] REGULAR request returns 404
- [ ] REIMBURSEMENT request returns 404

### Test Case 21: Status Constraints - Petty Cash
- [ ] Can print DISBURSED status
- [ ] Can print PENDING_RECONCILIATION status
- [ ] Can print RECONCILED status

---

## Module: Petty Cash Signed Form Upload

### Test Case 22: Basic Upload - Petty Cash
- [ ] User role: Procurement Officer
- [ ] Request status: PENDING_RECONCILIATION
- [ ] Select valid PDF file
- [ ] Upload completes successfully
- [ ] Success message displayed

### Test Case 23: File Upload Security - Petty Cash
- [ ] Same file type restrictions as reimbursement
- [ ] Same file size limit (25 MB)
- [ ] Same safe filename generation
- [ ] MIME type validated
- [ ] Extension validated

### Test Case 24: Version Tracking - Petty Cash
- [ ] Versions tracked correctly
- [ ] Previous version marked inactive on new upload
- [ ] Upload timestamp recorded
- [ ] Uploader user ID recorded

---

## Module: Admin Edit Functionality

### Test Case 25: Admin Permission Enforcement
- [ ] Admin can edit requests
- [ ] SuperAdmin can edit requests
- [ ] Non-admin users cannot edit
- [ ] Unauthorized attempt logged

### Test Case 26: Editable Fields by Status
- [ ] In DRAFT status:
  - [ ] Can edit description
  - [ ] Can edit estimated_value
  - [ ] Can edit currency
  - [ ] Can edit procurement_method
- [ ] In SUBMITTED status:
  - [ ] Can edit description (limited)
  - [ ] Can edit estimated_value (limited)
  - [ ] Can edit currency (limited)
  - [ ] Cannot edit procurement_method
- [ ] In later stages:
  - [ ] Can only edit administrative notes
  - [ ] Cannot edit core fields

### Test Case 27: Field Validation
- [ ] Negative estimated_value rejected
- [ ] Non-numeric estimated_value rejected
- [ ] Invalid currency codes rejected
- [ ] Invalid procurement methods rejected
- [ ] Overly long descriptions rejected

### Test Case 28: Approval Invalidation
- [ ] Editing estimated_value invalidates previous approvals
- [ ] Editing procurement_method invalidates approvals
- [ ] Editing description invalidates approvals
- [ ] Invalidation logged in approval_invalidation_log
- [ ] Approval status changed to INVALIDATED

### Test Case 29: Audit Logging - Edits
- [ ] Edit recorded in admin_edit_audit table
- [ ] Old value stored in database
- [ ] New value stored in database
- [ ] Admin user ID recorded
- [ ] Admin role recorded
- [ ] Edit timestamp recorded
- [ ] IP address recorded
- [ ] User-agent recorded
- [ ] Edit reason/change reason recorded

### Test Case 30: Edit History
- [ ] Can view all edits for a request
- [ ] Edit history shows chronological order
- [ ] Can identify which admin made each edit
- [ ] Can see before/after values
- [ ] Can see timestamps
- [ ] Can see reasons for edits

---

## Security Testing

### Test Case 31: Authorization Bypass Attempts
- [ ] Cannot bypass admin checks by modifying URL parameters
- [ ] Cannot bypass role checks through hidden form fields
- [ ] Cannot access unintended resources via path traversal
- [ ] Server-side authorization always enforced
- [ ] No client-side-only authorization

### Test Case 32: File Upload Injection Attacks
- [ ] Cannot upload malicious PHP file
- [ ] Cannot upload shell scripts
- [ ] Cannot execute uploaded files
- [ ] File extensions validated (not just MIME type)
- [ ] MIME type validation using finfo (not user-provided)

### Test Case 33: SQL Injection Prevention
- [ ] Parameterized queries used (not string concatenation)
- [ ] No direct SQL assembly from user input
- [ ] Prepared statements used for all database queries
- [ ] No error message reveals SQL structure

### Test Case 34: XSS Prevention
- [ ] User input escaped in HTML output
- [ ] Filenames sanitized in display
- [ ] No unescaped data in PDF
- [ ] JavaScript payloads in filenames not executed
- [ ] Comments not executed as code

### Test Case 35: CSRF Protection
- [ ] Upload forms have CSRF tokens (if using sessions)
- [ ] POST requests require valid tokens
- [ ] Token validated server-side
- [ ] Cross-site requests rejected

### Test Case 36: Information Disclosure
- [ ] No sensitive data in log files
- [ ] No SQL errors displayed to users
- [ ] No file paths revealed
- [ ] No usernames in URLs
- [ ] No tokens in query strings

---

## Audit Logging Verification

### Test Case 37: Comprehensive Audit Trail
- [ ] All print events logged
- [ ] All uploads logged
- [ ] All edits logged
- [ ] All failed authorization attempts logged
- [ ] Logs contain timestamp, user ID, action type
- [ ] Logs contain resource information
- [ ] Logs are append-only (cannot be modified)

### Test Case 38: Admin Action Log Completeness
- [ ] admin_action_log populated on each action
- [ ] Contains admin user ID
- [ ] Contains admin role
- [ ] Contains action type
- [ ] Contains resource type
- [ ] Contains resource ID
- [ ] Contains IP address
- [ ] Contains user-agent

### Test Case 39: Audit Log Queries
- [ ] Can query audit log by request ID
- [ ] Can query by admin user
- [ ] Can query by action type
- [ ] Can query by date range
- [ ] Can query by IP address

---

## Regression Testing (Procurement Module)

### Test Case 40: Existing Procurement Functionality
- [ ] Procurement print-for-signing still works
- [ ] Procurement signed request upload still works
- [ ] Procurement view displays signed requests correctly
- [ ] Procurement audit logging not affected
- [ ] Procurement notifications not affected

### Test Case 41: Backward Compatibility
- [ ] Existing signed request documents still accessible
- [ ] Existing request types not affected
- [ ] Existing workflows not interrupted
- [ ] Existing user permissions respected
- [ ] Database schema backward compatible

---

## Performance Testing

### Test Case 42: Large File Uploads
- [ ] 20 MB file uploads in reasonable time
- [ ] 25 MB file uploads in reasonable time
- [ ] Upload doesn't timeout
- [ ] Database transaction completes

### Test Case 43: PDF Generation Performance
- [ ] PDF generates within 5 seconds
- [ ] Complex requests don't cause timeouts
- [ ] Multiple concurrent prints don't interfere

### Test Case 44: Database Performance
- [ ] Audit log queries complete quickly
- [ ] Edit history queries complete quickly
- [ ] Version history queries complete quickly
- [ ] Indexes created for performance

---

## Usability Testing

### Test Case 45: UI/UX
- [ ] Print button visible and labeled clearly
- [ ] Upload form intuitive
- [ ] Error messages clear and actionable
- [ ] Success messages confirm action
- [ ] File input allows browsing
- [ ] Progress indication for uploads

### Test Case 46: Documentation
- [ ] Help text available for each field
- [ ] Instructions clear for print workflow
- [ ] Instructions clear for upload workflow
- [ ] Constraints documented (file size, types)
- [ ] Error messages explain solutions

---

## Post-Testing Sign-Off

- [ ] All tests completed
- [ ] All critical issues resolved
- [ ] Known issues documented
- [ ] Performance acceptable
- [ ] Security verified
- [ ] Audit logging complete
- [ ] Ready for production deployment

**QA Lead:** ___________________  
**Sign-Off Date:** ___________________  
**Environment:** ___________________  
**Known Issues:** 

---

## Issue Tracking Template

| Test Case | Issue Description | Severity | Status | Resolution |
|-----------|------------------|----------|--------|------------|
| | | | | |

---
