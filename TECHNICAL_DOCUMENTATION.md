# Signed Request Management Extension - Technical Implementation

## Executive Summary

This document describes the architectural design and implementation details of extending the Signed Request Management feature from procurement requests to reimbursement and petty_cash request types in PRMS v3.

## Architecture Overview

### Design Principles

1. **Separation of Concerns** - Service classes handle business logic independently from controller logic
2. **DRY (Don't Repeat Yourself)** - Shared services reused across request types
3. **Request-Type Agnosticism** - Services work with all request types through polymorphic design
4. **Audit-First** - All sensitive operations logged comprehensively
5. **Authorization-Before-Action** - All permissions checked server-side before any state change
6. **Transactional Safety** - Database operations wrapped in transactions with rollback
7. **Secure File Handling** - Files validated, safely named, protected from traversal

### Component Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Request Modules                          │
│  (procurement/, reimbursement/, petty_cash/)                │
└────────────────────┬────────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────────┐
│                    Service Layer                            │
│  ┌──────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │RequestPrintSvc  │  │RequestDocSvc    │  │AdminEditSvc │ │
│  └──────────────────┘  └─────────────────┘  └─────────────┘ │
└────────────────────┬────────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────────┐
│                    Data Access Layer                        │
│  (PDO Database Connection)                                  │
└────────────────────┬────────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────────┐
│                  Database Schema                            │
│  (procurement_requests + audit tables)                      │
└─────────────────────────────────────────────────────────────┘
```

## Database Schema

### Core Tables

#### `procurement_requests`
Extended with signed request fields for all request types:

```sql
-- Existing fields + new fields
signed_request_document_path VARCHAR(255)  -- Current active signed document path
signed_request_received_date DATETIME      -- When signed doc uploaded
signed_by_user_id INT                      -- User who uploaded
doc_ctrl_form_revision VARCHAR(100)        -- Form revision snapshot
doc_ctrl_effective_date DATE               -- Effective date snapshot
doc_ctrl_dcr_number VARCHAR(100)           -- DCR number snapshot
```

#### `signed_request_versions` (NEW)
Maintains version history of signed documents:

```sql
version_id BIGINT PRIMARY KEY
request_id INT NOT NULL (FK to procurement_requests)
document_path VARCHAR(255)          -- Uploaded file path
file_name VARCHAR(255)              -- Original filename
file_size BIGINT                    -- File size in bytes
mime_type VARCHAR(100)              -- Validated MIME type
uploaded_by INT NOT NULL (FK to users)
uploaded_at DATETIME DEFAULT NOW()
is_active BOOLEAN DEFAULT 1         -- Current version?
replacement_reason TEXT             -- Why was it replaced?
replaced_at DATETIME                -- When replaced
replaced_by INT                     -- Who replaced it
```

**Purpose:** Track all document uploads, maintain audit history, enable rollback if needed.

**Indexes:**
- `idx_request_id (request_id)`
- `idx_is_active (is_active)`
- `idx_signed_version_composite (request_id, is_active, uploaded_at)`

#### `admin_edit_audit` (NEW)
Tracks administrative modifications to requests:

```sql
edit_id BIGINT PRIMARY KEY
request_id INT NOT NULL (FK)
request_type ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH')
request_number VARCHAR(50)
field_name VARCHAR(100)             -- What was edited
old_value LONGTEXT                  -- Before value
new_value LONGTEXT                  -- After value
change_reason TEXT                  -- Why the change
affected_approvals TEXT             -- Approvals invalidated
edited_by INT NOT NULL (FK)
editor_role VARCHAR(50)             -- Admin or SuperAdmin
editor_ip_address VARCHAR(45)
editor_user_agent VARCHAR(500)
edited_at DATETIME DEFAULT NOW()
requires_re_approval BOOLEAN        -- Does this need re-approval?
approval_stages_affected JSON       -- Which approval stages affected
```

**Purpose:** Comprehensive audit trail of admin edits, including before/after values and justification.

#### `admin_action_log` (NEW)
Tracks all sensitive admin actions:

```sql
action_id BIGINT PRIMARY KEY
admin_user_id INT NOT NULL (FK)
admin_role VARCHAR(50)              -- Admin or SuperAdmin
action_type VARCHAR(50)             -- EDIT, DELETE, APPROVE, etc.
resource_type VARCHAR(50)           -- REQUEST, DOCUMENT, APPROVAL
resource_id VARCHAR(100)            -- ID of affected resource
resource_identifier VARCHAR(100)    -- Human-readable (e.g., req #)
action_description TEXT             -- What was done
status_before VARCHAR(50)           -- Status before
status_after VARCHAR(50)            -- Status after
ip_address VARCHAR(45)
user_agent VARCHAR(500)
timestamp DATETIME DEFAULT NOW()
```

**Purpose:** High-level audit trail of all admin actions for security monitoring.

#### `approval_invalidation_log` (NEW)
Tracks when approvals are invalidated by edits:

```sql
invalidation_id BIGINT PRIMARY KEY
request_id INT NOT NULL (FK)
approval_id INT
approval_stage VARCHAR(50)
invalidated_by INT NOT NULL (FK to users)
invalidation_reason TEXT
fields_affected JSON                -- JSON array of fields that triggered
was_reinstated BOOLEAN DEFAULT 0
reinstated_at DATETIME
reinstated_by INT (FK)
created_at DATETIME DEFAULT NOW()
```

**Purpose:** Ensures approval workflow integrity when critical fields change.

#### `doc_ctrl_settings`
Document control configuration per request type:

```sql
id INT PRIMARY KEY
request_type ENUM('REGULAR', 'REIMBURSEMENT', 'PETTY_CASH') UNIQUE
form_revision VARCHAR(100)          -- Current form version
effective_date DATE                 -- When this version effective
dcr_number VARCHAR(100)             -- Design Control Record number
updated_at DATETIME DEFAULT NOW()
updated_by_id INT
updated_by_name VARCHAR(255)
```

**Records:** One per request type (REGULAR, REIMBURSEMENT, PETTY_CASH)

### File Storage

#### Directory Structure

```
/uploads/
├── signed_requests/
│   ├── SIGNED_S_127_1692547200_a1b2c3d4.pdf
│   ├── SIGNED_S_127_1692548100_e5f6g7h8.pdf
│   ├── SIGNED_R_45_1692549000_i9j0k1l2.pdf
│   ├── SIGNED_P_89_1692549900_m3n4o5p6.pdf
│   └── ...
```

**Filename Convention:** `SIGNED_{TYPE}_{REQUEST_ID}_{TIMESTAMP}_{UNIQID}.{ext}`
- TYPE: S (regular), R (reimbursement), P (petty cash)
- TIMESTAMP: Unix timestamp of upload
- UNIQID: Random string to prevent collisions
- Prevents original filename exposure

## Service Classes

### 1. RequestPrintService

**Purpose:** Generate request-type-specific approval forms as PDF

**Key Methods:**

```php
__construct($pdo, $request)
// Initialize with database connection and request data

generateFormHTML(): string
// Returns HTML content for the approval form
// - REGULAR: Procurement form with items, procurement method, signatures
// - REIMBURSEMENT: Invoice form with amounts, verification sections
// - PETTY_CASH: Reconciliation form with 24hr deadline, breakdown

validateDocumentControlSettings(): array
// Checks that form revision, effective date, DCR number configured
// Returns ['valid' => bool, 'missing' => array]

persistDocumentControlSnapshot(): bool
// Saves form configuration to request record for historical integrity
// Ensures future prints show same document control info
```

**Usage Pattern:**

```php
$service = new RequestPrintService($pdo, $request);
$validation = $service->validateDocumentControlSettings();
if (!$validation['valid']) {
    // Show error with missing fields
    exit('Missing: ' . implode(', ', $validation['missing']));
}

$html = $service->generateFormHTML();
$service->persistDocumentControlSnapshot();

// Generate PDF using Dompdf
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->render();
echo $dompdf->output();
```

### 2. RequestDocumentService

**Purpose:** Handle signed request document uploads and validation

**Key Methods:**

```php
__construct($pdo, $requestId, $userId, $userRole, $userName)
// Initialize with database, request, and current user info

loadRequest(): array
// Fetch request from database, verify type

checkUploadAuthorization($request): array
// Verify user has permission to upload
// Returns ['authorized' => bool, 'reason' => string]

checkWorkflowConstraints($request): array
// Verify request status allows uploads
// Returns ['allowed' => bool, 'reason' => string]

validateFile($file): array
// Validate uploaded file (type, size, MIME)
// Returns ['valid' => bool, 'error' => string, 'mimeType' => string]

processUpload($file, $request): array
// Move file to secure location, generate safe filename
// Returns ['success' => bool, 'path' => string, ...]

saveToDatabase($fileInfo, $request): array
// Persist to signed_request_versions and procurement_requests
// Handles version history and transaction management

logUploadAction($request, $fileInfo): void
// Log to audit_log and admin_action_log

sendNotifications($request): void
// Dispatch notifications to appropriate users

getActiveSignedDocument(): array
// Get current active signed document version

getVersionHistory(): array
// Get all versions including inactive ones
```

**Usage Pattern:**

```php
$service = new RequestDocumentService($pdo, $requestId, $_SESSION['user_id'], 
    $_SESSION['role_name'], $_SESSION['full_name']);

$request = $service->loadRequest();

$auth = $service->checkUploadAuthorization($request);
if (!$auth['authorized']) throw new Exception($auth['reason']);

$workflow = $service->checkWorkflowConstraints($request);
if (!$workflow['allowed']) throw new Exception($workflow['reason']);

$validation = $service->validateFile($_FILES['file']);
if (!$validation['valid']) throw new Exception($validation['error']);

$upload = $service->processUpload($_FILES['file'], $request);
if (!$upload['success']) throw new Exception($upload['error']);

$save = $service->saveToDatabase($upload, $request);
if (!$save['success']) throw new Exception($save['error']);

$service->logUploadAction($request, $upload);
$service->sendNotifications($request);
```

### 3. AdminEditService

**Purpose:** Secure admin editing with approval invalidation and comprehensive audit

**Key Methods:**

```php
__construct($pdo, $requestId, $adminUserId, $adminUserRole, $adminUserName)

checkAdminPermission(): array
// Verify only Admin or SuperAdmin

loadRequest(): array
// Fetch request for editing

getEditableFields($request): array
// Return fields editable at current status

canEditField($request, $fieldName): bool
// Check if specific field can be edited

validateEdit($request, $fieldName, $newValue): array
// Validate new value format and constraints

applyEdit($request, $fieldName, $newValue, $reason): array
// Apply edit, handle approvals invalidation, log everything
// Returns ['success' => bool, 'changed' => bool, 'affectedApprovals' => array]

applyBulkEdits($request, $edits, $reason): array
// Apply multiple edits in transaction

getEditHistory(): array
// Fetch all admin edits for this request

getInvalidatedApprovals(): array
// Fetch approvals invalidated by edits
```

**Authorization Model:**

```php
// Only these roles can edit:
['SuperAdmin', 'Admin']

// Editable fields by status:
'DRAFT' => ['description', 'estimated_value', 'currency', 'procurement_method', ...]
'SUBMITTED' => ['description', 'estimated_value', 'currency', ...]
'ALL_STAGES' => ['cancel_reason', 'decline_reason']

// Approval-critical fields (trigger invalidation if changed):
['description', 'estimated_value', 'currency', 'procurement_method', ...]
```

## Request Handlers

### Reimbursement: `reimbursement/print_for_approval.php`

**Flow:**
1. Validate request ID and user permission
2. Check request type = 'REIMBURSEMENT'
3. Load document control settings
4. Validate document control is configured
5. Fetch invoice details
6. Generate HTML form with reimbursement-specific sections
7. Create PDF using Dompdf
8. Log print event
9. Output PDF with appropriate headers

**Key Differences from Procurement:**
- No items table (invoices don't have line items)
- Invoice amount and stage displayed
- Finance Officer signature section (not Branch Head)
- Different approval routing

### Reimbursement: `reimbursement/upload_signed_form.php`

**Flow:**
1. Verify POST request
2. Load request and verify REIMBURSEMENT type
3. Check authorization (Finance Officer, Requestor, etc.)
4. Check workflow constraints
5. Validate file using RequestDocumentService
6. Process upload and move file
7. Save to database with transaction
8. Log action and send notifications
9. Return success/error message

### Petty Cash: `petty_cash/print_for_approval.php`

**Flow:**
Similar to reimbursement but with:
- Disbursement amount instead of invoice amount
- Reconciliation deadline clearly stated (24 hours)
- Reconciliation summary sections
- Three signature lines (Procurement Officer, Finance Officer, Director)

### Petty Cash: `petty_cash/upload_signed_form.php`

**Flow:**
Same as reimbursement upload, verifying PETTY_CASH type

## Security Implementation

### Authentication & Authorization

- All handlers require `$REQUIRE_PERMISSION = 'view_requests'`
- Server-side authorization checks in each service
- Role-based access control (Admin/SuperAdmin only for editing)
- Request ownership verified for requestors

### File Upload Security

```
Validation Chain:
├── File exists check (UPLOAD_ERR_NO_FILE)
├── Upload error check (UPLOAD_ERR_OK)
├── File size check (max 25 MB)
├── MIME type validation (finfo_file)
├── Allowed MIME list whitelist
└── Safe filename generation (not user input)

Storage:
├── Files outside document root if possible
├── No execution permissions
├── Safe naming prevents collisions
├── Path traversal impossible
└── Direct URL access blocked
```

### SQL Injection Prevention

- All database queries use prepared statements
- No string concatenation with user input
- PDO parameterized queries throughout

### XSS Prevention

- All user input escaped with `htmlspecialchars()`
- PDF content sanitized
- No inline JavaScript in generated forms

### CSRF Protection

- Forms use session-based CSRF tokens (if implemented)
- POST-only endpoints
- Referer validation (optional)

## Audit Logging

### Log Levels

**Comprehensive Audit Trail:**
```
┌─ Print Event
│  ├─ User ID
│  ├─ User Role
│  ├─ Request Number
│  ├─ Timestamp
│  └─ IP Address
│
├─ Upload Event
│  ├─ User ID / Role
│  ├─ File Name (original)
│  ├─ File Size
│  ├─ Timestamp
│  ├─ IP Address
│  └─ Stored in: request_documents + signed_request_versions
│
├─ Admin Edit
│  ├─ Admin ID / Role
│  ├─ Field Name
│  ├─ Old Value
│  ├─ New Value
│  ├─ Change Reason
│  ├─ Approvals Affected
│  ├─ Timestamp
│  └─ Stored in: admin_edit_audit
│
└─ Failed Authorization
   ├─ Attempted User ID
   ├─ Attempted Action
   ├─ Resource
   ├─ Timestamp
   └─ IP Address
```

### Audit Entry Example

```json
{
  "edit_id": 1234,
  "request_id": 127,
  "request_type": "REIMBURSEMENT",
  "request_number": "REIMB-2026-001",
  "field_name": "estimated_value",
  "old_value": "5000.00",
  "new_value": "5500.00",
  "change_reason": "Invoice amount correction per requestor",
  "affected_approvals": ["FINANCE_APPROVAL_1", "DIRECTOR_APPROVAL_1"],
  "edited_by": 999,
  "editor_role": "Admin",
  "editor_ip_address": "192.168.1.100",
  "edited_at": "2026-08-19 14:32:45"
}
```

## Testing Strategy

### Unit Tests

**RequestDocumentServiceTest.php:**
- File validation (type, size, MIME)
- Authorization checks
- Workflow constraints
- Version history tracking

**AdminEditServiceTest.php:**
- Permission checks (admin-only)
- Editable fields by status
- Field validation (range, format)
- Approval invalidation logic
- Audit logging

### Integration Tests

- End-to-end print-and-upload workflow
- Database transaction rollback on errors
- Notification dispatch
- Version history tracking across uploads

### Security Tests

- Unauthorized access attempts
- Path traversal attempts
- SQL injection attempts
- XSS payload testing
- File type spoofing

## Performance Considerations

### Database Indexes

```sql
-- signed_request_versions
idx_request_id (request_id)
idx_is_active (is_active)
idx_signed_version_composite (request_id, is_active, uploaded_at)

-- admin_edit_audit
idx_request_id (request_id)
idx_edited_by (edited_by)
idx_edited_at (edited_at)

-- admin_action_log
idx_admin_user_id (admin_user_id)
idx_timestamp (timestamp)
idx_action_type (action_type)

-- audit_log
idx_audit_table_record (table_name, record_id)
idx_audit_change_date (change_date)
```

### Query Optimization

- Limit audit log queries with date ranges
- Use pagination for large result sets
- Avoid full table scans on audit tables
- Pre-filter by request_id when possible

### File Management

- Regular cleanup of old uploaded files (policy-based)
- Soft delete for audit trail preservation
- Consider archival strategy for old versions
- Monitor disk usage

## Deployment Sequence

1. Run database migration
2. Deploy service files
3. Deploy handler files
4. Create upload directories
5. Initialize document control settings
6. Run unit tests
7. Integration testing
8. Security testing
9. Production deployment

## Error Handling

### User-Facing Errors

- Clear, actionable error messages
- No sensitive information revealed
- Redirect to appropriate page
- Log full error for debugging

### System Errors

- Log full stack trace
- Send alert to administrators
- Graceful degradation
- Rollback on database errors
- Clean up partial uploads

## Future Enhancements

1. **Document Versioning UI** - Display and manage version history
2. **Approval Status Dashboard** - Track approval progress
3. **Bulk Operations** - Process multiple requests at once
4. **Report Generation** - Audit trail reports
5. **Workflow Automation** - Auto-advance on certain conditions
6. **Digital Signatures** - Integration with digital signing APIs
7. **Archive Management** - Long-term storage strategy
8. **Analytics** - Upload/approval metrics

---

**Document Version:** 1.0  
**Last Updated:** 2026-08-19  
**Author:** Development Team  
