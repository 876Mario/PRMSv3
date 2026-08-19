<?php
/**
 * AdminEditService
 * Centralized service for administrative edits with comprehensive audit logging
 * Handles authorization, field validation, approval invalidation, and audit trail
 */

class AdminEditService {
    private $pdo;
    private $fieldsEditableByAdmin = [
        'REGULAR' => [
            'DRAFT' => ['description', 'estimated_value', 'procurement_method'],
            'SUBMITTED' => ['description', 'estimated_value'],
            'HOD_APPROVED' => [],
            'FUNDS_VERIFIED' => [],
            'DIRECTOR_APPROVED' => [],
            'GC_APPROVED' => ['procurement_method'], // Can adjust method only after GC approval
            'AWARDED' => [], // Typically locked
            'COMPLETED' => []
        ],
        'REIMBURSEMENT' => [
            'DRAFT' => ['description'],
            'SUBMITTED' => [],
            'INVOICE_RECEIVED' => [],
            'COMPLETED' => []
        ],
        'PETTY_CASH' => [
            'DRAFT' => ['description'],
            'SUBMITTED' => [],
            'DISBURSEMENT_SCHEDULED' => [],
            'DISBURSAL_COMPLETE' => [],
            'RECONCILIATION_COMPLETE' => []
        ]
    ];

    private $approvalCriticalFields = [
        'REGULAR' => ['estimated_value', 'procurement_method'],
        'REIMBURSEMENT' => [],
        'PETTY_CASH' => []
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verify if user has admin edit permission
     */
    public function isAdmin($role) {
        return in_array($role, ['Admin', 'SuperAdmin']);
    }

    /**
     * Check if a field is editable by admin for this request type and status
     */
    public function isFieldEditable($requestType, $currentStatus, $fieldName) {
        if (!isset($this->fieldsEditableByAdmin[$requestType])) {
            return false;
        }

        $statusConfig = $this->fieldsEditableByAdmin[$requestType];
        if (!isset($statusConfig[$currentStatus])) {
            return false;
        }

        return in_array($fieldName, $statusConfig[$currentStatus]);
    }

    /**
     * Check if editing this field would invalidate approvals
     */
    public function isApprovalCritical($requestType, $fieldName) {
        if (!isset($this->approvalCriticalFields[$requestType])) {
            return false;
        }
        return in_array($fieldName, $this->approvalCriticalFields[$requestType]);
    }

    /**
     * Perform admin edit with full audit trail
     */
    public function performEdit($requestId, $requestType, $fieldName, $newValue, $editReason = null, $editReasonCode = null) {
        // Verify current user is admin
        if (!$this->isAdmin($_SESSION['role_name'] ?? '')) {
            return ['success' => false, 'error' => 'Only Admin and SuperAdmin can perform edits'];
        }

        // Fetch current request
        $stmt = $this->pdo->prepare("
            SELECT * FROM procurement_requests 
            WHERE request_id = ? AND request_type = ?
        ");
        $stmt->execute([$requestId, $requestType]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return ['success' => false, 'error' => 'Request not found'];
        }

        // Verify field is editable
        if (!$this->isFieldEditable($requestType, $request['status'], $fieldName)) {
            return [
                'success' => false,
                'error' => "Field '{$fieldName}' cannot be edited at status '{$request['status']}' for {$requestType} requests"
            ];
        }

        // Get old value
        $oldValue = $request[$fieldName] ?? null;

        // Validate new value based on field type
        $validation = $this->validateField($requestType, $fieldName, $newValue);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        try {
            // Re-validate field name against whitelist before using in query (defense in depth)
            $allowedFields = [];
            foreach ($this->fieldsEditableByAdmin[$requestType][$request['status']] ?? [] as $field) {
                $allowedFields[] = $field;
            }
            if (!in_array($fieldName, $allowedFields)) {
                return [
                    'success' => false,
                    'error' => "Field '{$fieldName}' cannot be edited"
                ];
            }

            $this->pdo->beginTransaction();

            // Track if approval-critical field is being changed
            $isApprovalCritical = $this->isApprovalCritical($requestType, $fieldName);
            $affectedApprovals = [];

            // Update request (field name validated against whitelist above)
            $updateStmt = $this->pdo->prepare("
                UPDATE procurement_requests 
                SET {$fieldName} = ?, updated_at = NOW()
                WHERE request_id = ?
            ");
            $updateStmt->execute([$newValue, $requestId]);

            // If approval-critical field changed, invalidate related approvals
            if ($isApprovalCritical && $oldValue !== $newValue) {
                $affectedApprovals = $this->invalidateApprovals($requestId, $fieldName);
            }

            // Log the edit in admin_edits_log
            $logStmt = $this->pdo->prepare("
                INSERT INTO admin_edits_log 
                (request_id, request_type, table_name, field_name, old_value, new_value, 
                 changed_by_user_id, changed_by_role, change_ip, change_user_agent, 
                 affected_approvals, edit_notes, edit_reason_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $affectedApprovalsJson = empty($affectedApprovals) ? NULL : json_encode($affectedApprovals);

            $logStmt->execute([
                $requestId,
                $requestType,
                'procurement_requests',
                $fieldName,
                substr((string)$oldValue, 0, 5000), // Truncate large values
                substr((string)$newValue, 0, 5000),
                $_SESSION['user_id'] ?? null,
                $_SESSION['role_name'] ?? 'Unknown',
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                $affectedApprovalsJson,
                $editReason,
                $editReasonCode
            ]);

            // Also log to audit_log for backward compatibility
            logAudit(
                $this->pdo,
                'procurement_requests',
                $requestId,
                'ADMIN_EDIT',
                'Admin edit by ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                " ({$_SESSION['role_name']}): {$fieldName} changed from '" .
                substr((string)$oldValue, 0, 100) . "' to '" .
                substr((string)$newValue, 0, 100) . "'. " .
                ($editReason ? "Reason: " . htmlspecialchars($editReason) : '')
            );

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => 'Edit applied successfully',
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'approval_critical' => $isApprovalCritical,
                'affected_approvals' => $affectedApprovals
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Admin edit error for request {$requestId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error: ' . htmlspecialchars($e->getMessage())];
        }
    }

    /**
     * Validate field value based on type and field name
     */
    private function validateField($requestType, $fieldName, $value) {
        switch ($fieldName) {
            case 'estimated_value':
                if (!is_numeric($value) || $value <= 0) {
                    return ['valid' => false, 'error' => 'Estimated value must be a positive number'];
                }
                return ['valid' => true];

            case 'procurement_method':
                $validMethods = ['SINGLE_SOURCE', 'RESTRICTED_BIDDING', 'NATIONAL_COMPETITIVE', 'INTERNATIONAL_COMPETITIVE'];
                if (!in_array($value, $validMethods)) {
                    return ['valid' => false, 'error' => 'Invalid procurement method'];
                }
                return ['valid' => true];

            case 'description':
                if (strlen(trim($value)) === 0) {
                    return ['valid' => false, 'error' => 'Description cannot be empty'];
                }
                return ['valid' => true];

            default:
                return ['valid' => true]; // Allow other field edits without specific validation
        }
    }

    /**
     * Invalidate approvals when critical fields change
     * Returns array of affected approval IDs
     */
    private function invalidateApprovals($requestId, $changedField) {
        $affectedApprovals = [];

        try {
            // Find all non-rejected approvals that would be affected by this change
            $stmt = $this->pdo->prepare("
                SELECT id, role, status, approved_at
                FROM request_approvals
                WHERE request_id = ? AND status IN ('approved', 'pending')
            ");
            $stmt->execute([$requestId]);
            $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($approvals)) {
                return []; // No approvals to invalidate
            }

            // Clear/invalidate all approvals
            foreach ($approvals as $approval) {
                $invalidationReason = "Admin edit to '{$changedField}' requires re-approval";
                
                // For approved items, reset to pending; for pending, keep as pending
                if ($approval['status'] === 'approved') {
                    $newStatus = 'pending';
                } else {
                    $newStatus = $approval['status']; // Keep pending as pending
                }
                
                $updateStmt = $this->pdo->prepare("
                    UPDATE request_approvals
                    SET status = ?,
                        approved_at = NULL,
                        comments = CONCAT(
                            COALESCE(comments, ''),
                            CASE WHEN comments IS NOT NULL AND comments != '' THEN '; ' ELSE '' END,
                            ?
                        )
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $newStatus,
                    $invalidationReason,
                    $approval['id']
                ]);

                $affectedApprovals[] = $approval['id'];
                
                // Log the invalidation event
                if (function_exists('logAudit')) {
                    logAudit(
                        $this->pdo,
                        'request_approvals',
                        $approval['id'],
                        'APPROVAL_INVALIDATED',
                        "Approval for {$approval['role']} invalidated due to admin edit of {$changedField}"
                    );
                }
            }

            return $affectedApprovals;
        } catch (Exception $e) {
            error_log("Approval invalidation error for request {$requestId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get admin edit history for a request
     */
    public function getEditHistory($requestId) {
        $stmt = $this->pdo->prepare("
            SELECT ael.*, u.full_name as changed_by_name
            FROM admin_edits_log ael
            LEFT JOIN users u ON ael.changed_by_user_id = u.user_id
            WHERE ael.request_id = ?
            ORDER BY ael.change_timestamp DESC
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get audit trail for export or reporting
     */
    public function getAuditTrail($requestId, $requestType = null) {
        $query = "
            SELECT ael.*, u.full_name as changed_by_name
            FROM admin_edits_log ael
            LEFT JOIN users u ON ael.changed_by_user_id = u.user_id
            WHERE ael.request_id = ?
        ";

        $params = [$requestId];

        if ($requestType) {
            $query .= " AND ael.request_type = ?";
            $params[] = $requestType;
        }

        $query .= " ORDER BY ael.change_timestamp DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if edit would require re-review of approvals
     */
    public function requiresReApproval($requestType, $fieldName) {
        return $this->isApprovalCritical($requestType, $fieldName);
    }
}
?>
