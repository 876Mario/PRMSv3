<?php
/**
 * AdminEditService - Secure admin editing with comprehensive audit trails
 * 
 * Provides:
 * - Server-side authorization checks
 * - Field-level edit restrictions
 * - Approval-critical field detection
 * - Audit logging of all edits
 * - Approval invalidation handling
 * - Before/after value tracking
 */

class AdminEditService {
    
    private $pdo;
    private $requestId;
    private $adminUserId;
    private $adminUserRole;
    private $adminUserName;
    
    // Define which fields can be edited at each workflow stage
    private $editableFieldsByStage = [
        'DRAFT' => [
            'description', 'estimated_value', 'currency', 'procurement_method',
            'external_approval_required', 'requires_rfq'
        ],
        'SUBMITTED' => [
            'description', 'estimated_value', 'currency',  // Limited edits after submission
            'change_reason'
        ],
        'ALL_STAGES' => [
            'cancel_reason', 'decline_reason'  // Administrative notes
        ]
    ];
    
    // Fields that invalidate approvals if changed
    private $approvalCriticalFields = [
        'description', 'estimated_value', 'currency', 'procurement_method',
        'external_approval_required', 'requires_rfq'
    ];
    
    public function __construct($pdo, $requestId, $adminUserId, $adminUserRole, $adminUserName) {
        $this->pdo = $pdo;
        $this->requestId = (int)$requestId;
        $this->adminUserId = (int)$adminUserId;
        $this->adminUserRole = $adminUserRole;
        $this->adminUserName = $adminUserName;
    }
    
    /**
     * Check if user has admin edit permission
     */
    public function checkAdminPermission() {
        // Only SuperAdmin and Admin roles can edit requests
        if (!in_array($this->adminUserRole, ['SuperAdmin', 'Admin'])) {
            return [
                'authorized' => false,
                'reason' => 'Only Admin and SuperAdmin users can edit requests administratively.'
            ];
        }
        
        return ['authorized' => true, 'reason' => ''];
    }
    
    /**
     * Load the current request
     */
    public function loadRequest() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM procurement_requests
                WHERE request_id = ?
            ");
            $stmt->execute([$this->requestId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception("Failed to load request: " . $e->getMessage());
        }
    }
    
    /**
     * Check which fields can be edited for current request status
     */
    public function getEditableFields($request) {
        $status = $request['status'] ?? 'DRAFT';
        $editableFields = [];
        
        // Get stage-specific fields
        if (isset($this->editableFieldsByStage[$status])) {
            $editableFields = array_merge($editableFields, $this->editableFieldsByStage[$status]);
        }
        
        // Add fields allowed at all stages
        if (isset($this->editableFieldsByStage['ALL_STAGES'])) {
            $editableFields = array_merge($editableFields, $this->editableFieldsByStage['ALL_STAGES']);
        }
        
        return array_unique($editableFields);
    }
    
    /**
     * Check if a field edit is allowed
     */
    public function canEditField($request, $fieldName) {
        $editableFields = $this->getEditableFields($request);
        return in_array($fieldName, $editableFields);
    }
    
    /**
     * Validate edit request
     */
    public function validateEdit($request, $fieldName, $newValue) {
        // Check if field is editable
        if (!$this->canEditField($request, $fieldName)) {
            return [
                'valid' => false,
                'error' => 'Field "' . $fieldName . '" cannot be edited at status "' . $request['status'] . '"'
            ];
        }
        
        // Field-specific validation
        switch ($fieldName) {
            case 'estimated_value':
                if (!is_numeric($newValue) || $newValue < 0) {
                    return ['valid' => false, 'error' => 'Estimated value must be a positive number'];
                }
                break;
            case 'currency':
                $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD'];
                if (!in_array($newValue, $validCurrencies)) {
                    return ['valid' => false, 'error' => 'Invalid currency code'];
                }
                break;
            case 'procurement_method':
                $validMethods = ['OPEN_TENDER', 'RESTRICTED_TENDER', 'DIRECT_PROCUREMENT', 'FRAMEWORK'];
                if (!in_array($newValue, $validMethods)) {
                    return ['valid' => false, 'error' => 'Invalid procurement method'];
                }
                break;
            case 'description':
                if (strlen($newValue) > 5000) {
                    return ['valid' => false, 'error' => 'Description cannot exceed 5000 characters'];
                }
                break;
        }
        
        return ['valid' => true, 'error' => ''];
    }
    
    /**
     * Apply an edit with comprehensive audit trail
     */
    public function applyEdit($request, $fieldName, $newValue, $editReason = '') {
        // Validate the edit
        $validation = $this->validateEdit($request, $fieldName, $newValue);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        // Get old value
        $oldValue = $request[$fieldName] ?? null;
        
        // Check if value actually changed
        if ($oldValue === $newValue) {
            return ['success' => true, 'changed' => false, 'error' => 'No change was made'];
        }
        
        // Start transaction
        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        try {
            // Update the field
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                SET $fieldName = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$newValue, $this->requestId]);
            
            // Log the edit in admin_edit_audit
            $this->logEditAudit($request, $fieldName, $oldValue, $newValue, $editReason);
            
            // Check if this field invalidates approvals
            $affectedApprovals = [];
            if (in_array($fieldName, $this->approvalCriticalFields)) {
                $affectedApprovals = $this->invalidateAffectedApprovals($request, [$fieldName]);
            }
            
            // Log to general audit trail
            $this->logGeneralAudit($request, $fieldName, $oldValue, $newValue);
            
            if ($startedTransaction) {
                $this->pdo->commit();
            }
            
            return [
                'success' => true,
                'changed' => true,
                'error' => '',
                'affectedApprovals' => $affectedApprovals
            ];
            
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Apply bulk edits
     */
    public function applyBulkEdits($request, $edits, $editReason = '') {
        $results = [];
        $startedTransaction = false;
        
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        try {
            foreach ($edits as $fieldName => $newValue) {
                $result = $this->applyEdit($request, $fieldName, $newValue, $editReason);
                $results[$fieldName] = $result;
                
                if (!$result['success']) {
                    // Rollback on first error
                    if ($startedTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    return ['success' => false, 'error' => 'Edit failed: ' . $result['error'], 'results' => $results];
                }
            }
            
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            
            return ['success' => true, 'error' => '', 'results' => $results];
            
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Error: ' . $e->getMessage(), 'results' => $results];
        }
    }
    
    /**
     * Invalidate approvals affected by an edit
     */
    private function invalidateAffectedApprovals($request, $fieldsChanged) {
        $affectedApprovals = [];
        
        try {
            // Find approvals that approved this request
            $stmt = $this->pdo->prepare("
                SELECT approval_id, approval_stage, approved_by, approved_at
                FROM request_approvals
                WHERE request_id = ? AND approval_status = 'APPROVED'
            ");
            $stmt->execute([$this->requestId]);
            $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($approvals as $approval) {
                // Mark approval as invalidated
                $invStmt = $this->pdo->prepare("
                    UPDATE request_approvals
                    SET approval_status = 'INVALIDATED',
                        invalidated_at = NOW(),
                        invalidated_reason = ?
                    WHERE approval_id = ?
                ");
                $invStmt->execute([
                    'Admin edit to critical field(s): ' . implode(', ', $fieldsChanged),
                    $approval['approval_id']
                ]);
                
                // Log invalidation
                $this->logApprovalInvalidation($approval, $fieldsChanged);
                
                $affectedApprovals[] = $approval;
            }
            
        } catch (Exception $e) {
            error_log("Warning: Failed to invalidate affected approvals: " . $e->getMessage());
        }
        
        return $affectedApprovals;
    }
    
    /**
     * Log edit to admin_edit_audit table
     */
    private function logEditAudit($request, $fieldName, $oldValue, $newValue, $editReason) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_edit_audit
                (request_id, request_type, request_number, field_name, old_value, new_value,
                 change_reason, edited_by, editor_role, editor_ip_address, editor_user_agent, edited_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->requestId,
                $request['request_type'],
                $request['request_number'],
                $fieldName,
                $oldValue,
                $newValue,
                $editReason,
                $this->adminUserId,
                $this->adminUserRole,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to log edit audit: " . $e->getMessage());
        }
    }
    
    /**
     * Log to general audit trail
     */
    private function logGeneralAudit($request, $fieldName, $oldValue, $newValue) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log
                (table_name, record_id, action, changed_by, change_date, notes)
                VALUES ('procurement_requests', ?, 'ADMIN_EDIT', ?, NOW(), ?)
            ");
            
            $notes = sprintf(
                'Admin edit: %s changed from "%s" to "%s"',
                $fieldName,
                $oldValue,
                $newValue
            );
            
            $stmt->execute([
                $this->requestId,
                $this->adminUserName,
                $notes
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to log general audit: " . $e->getMessage());
        }
    }
    
    /**
     * Log approval invalidation
     */
    private function logApprovalInvalidation($approval, $fieldsChanged) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO approval_invalidation_log
                (request_id, approval_stage, invalidated_by, invalidation_reason, fields_affected, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->requestId,
                $approval['approval_stage'],
                $this->adminUserId,
                'Admin edit triggered invalidation',
                json_encode($fieldsChanged)
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to log approval invalidation: " . $e->getMessage());
        }
    }
    
    /**
     * Get audit history for request
     */
    public function getEditHistory() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM admin_edit_audit
                WHERE request_id = ?
                ORDER BY edited_at DESC
            ");
            $stmt->execute([$this->requestId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get edit history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get invalidated approvals
     */
    public function getInvalidatedApprovals() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM approval_invalidation_log
                WHERE request_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$this->requestId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get invalidated approvals: " . $e->getMessage());
            return [];
        }
    }
}
?>
