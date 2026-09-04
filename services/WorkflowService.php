<?php

/**
 * WorkflowService - Centralized workflow logic for all request types
 * 
 * This service provides dynamic workflow resolution based on request type,
 * eliminating hardcoded mappings and enabling proper revert functionality
 * for REGULAR, PETTY_CASH, and REIMBURSEMENT requests.
 */
class WorkflowService
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get the workflow transitions map for a specific request type
     * 
     * @param string $requestType REGULAR, PETTY_CASH, or REIMBURSEMENT
     * @return array Map of current_status => [allowed_next_statuses]
     */
    public function getTransitionsForType(string $requestType): array
    {
        $requestType = strtoupper($requestType);
        
        return match ($requestType) {
            'PETTY_CASH' => $this->getPettyCashTransitions(),
            'REIMBURSEMENT' => $this->getReimbursementTransitions(),
            'REGULAR', 'SERVICE_CONTRACT' => $this->getRegularTransitions(),
            default => []
        };
    }

    /**
     * Get regular/procurement workflow transitions
     */
    private function getRegularTransitions(): array
    {
        return [
            'DRAFT'                  => ['SUBMITTED'],
            'SUBMITTED'              => ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'AWARDED', 'PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE', 'DECLINED'],
            'HOD_APPROVED'           => ['DIRECTOR_APPROVED', 'FUNDS_VERIFIED', 'GC_APPROVED', 'AWARDED', 'PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE', 'COMMITMENT_APPROVED', 'COMMITMENTS_PENDING',
                                         // ← backward
                                         'SUBMITTED'],
            'FUNDS_VERIFIED'         => ['DIRECTOR_APPROVED', 'PROCUREMENT_STAGE', 'AWARDED', 'RFQ_LETTER_AVAILABLE', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED',
                                         // ← backward
                                         'HOD_APPROVED', 'SUBMITTED'],
            'DIRECTOR_APPROVED'      => ['GC_APPROVED', 'AWARDED', 'PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE',
                                         // ← backward
                                         'HOD_APPROVED', 'SUBMITTED'],
            'GC_APPROVED'            => ['AWARDED', 'PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE',
                                         // ← backward
                                         'DIRECTOR_APPROVED', 'HOD_APPROVED'],
            'RFQ_LETTER_AVAILABLE'   => ['QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                         // ← backward
                                         'GC_APPROVED', 'DIRECTOR_APPROVED', 'HOD_APPROVED', 'SUBMITTED'],
            'QUOTE_REVIEW_PENDING'   => ['QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_APPROVED', 'PROCUREMENT_STAGE', 'AWARDED',
                                         // ← backward
                                         'RFQ_LETTER_AVAILABLE'],
            'QUOTE_REQUESTOR_REVIEW_PENDING' => ['QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                                // ← backward
                                                'RFQ_LETTER_AVAILABLE'],
            'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                                 // ← backward
                                                 'QUOTE_REQUESTOR_REVIEW_PENDING', 'RFQ_LETTER_AVAILABLE'],
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['QUOTE_APPROVED', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                                     // ← backward
                                                     'RFQ_LETTER_AVAILABLE'],
            'QUOTE_APPROVED'         => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'COMMITMENTS_PENDING', 'FUNDS_VERIFIED', 'PROCUREMENT_STAGE',
                                         // ← backward
                                         'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_REVIEW_PENDING', 'RFQ_LETTER_AVAILABLE'],
            'COMMITMENTS_PENDING'    => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'PROCUREMENT_STAGE',
                                         // ← backward
                                         'QUOTE_APPROVED', 'FUNDS_VERIFIED'],
            'COMMITMENT_APPROVED'    => ['PO_PENDING', 'INVOICE_RECEIVED', 'AWARDED',
                                         // ← backward
                                         'COMMITMENTS_PENDING', 'FUNDS_VERIFIED'],
            'COMMITMENT_DECLINED'    => ['QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'SUBMITTED'],
            'PO_PENDING'             => ['INVOICE_RECEIVED', 'AWARDED',
                                         // ← backward
                                         'COMMITMENT_APPROVED'],
            'INVOICE_RECEIVED'       => ['COMPLETED'],
            'PROCUREMENT_STAGE'      => ['EVALUATION_STAGE', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                         // ← backward
                                         'GC_APPROVED', 'HOD_APPROVED', 'SUBMITTED'],
            'EVALUATION_STAGE'       => ['COMMITTEE_RECOMMENDED', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                         // ← backward
                                         'PROCUREMENT_STAGE'],
            'COMMITTEE_RECOMMENDED'  => ['GC_APPROVED', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                         // ← backward
                                         'EVALUATION_STAGE'],
            'AWARDED'                => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'COMMITMENTS_PENDING', 'FUNDS_VERIFIED', 'PO_PENDING', 'INVOICE_RECEIVED',
                                         // ← controlled backward recovery
                                         'GC_APPROVED', 'COMMITTEE_RECOMMENDED', 'PROCUREMENT_STAGE'],
        ];
    }

    /**
     * Get petty cash workflow transitions
     */
    private function getPettyCashTransitions(): array
    {
        return [
            'DRAFT'                    => ['SUBMITTED'],
            'RETURNED_FOR_CORRECTION'  => ['SUBMITTED', 'DECLINED'],
            'SUBMITTED'                => ['FUNDS_VERIFIED', 'RETURNED_FOR_CORRECTION', 'DECLINED'],
            'FUNDS_VERIFIED'           => ['FINANCE_AUTHORIZED', 'DECLINED',
                                           // ← backward
                                           'SUBMITTED', 'RETURNED_FOR_CORRECTION'],
            'FINANCE_AUTHORIZED'       => ['DISBURSED',
                                           // ← backward
                                           'FUNDS_VERIFIED', 'SUBMITTED', 'RETURNED_FOR_CORRECTION'],
            'DISBURSED'                => ['PENDING_RECONCILIATION',
                                           // ← backward
                                           'FINANCE_AUTHORIZED'],
            'PENDING_RECONCILIATION'   => ['PROCUREMENT_VERIFIED', 'RECONCILIATION_DISCREPANCY',
                                           // ← backward
                                           'DISBURSED'],
            'PROCUREMENT_VERIFIED'     => ['COMPLETED',
                                           // ← backward
                                           'PENDING_RECONCILIATION'],
            'RECONCILIATION_DISCREPANCY' => ['REVIEWED',
                                             // ← backward
                                             'PENDING_RECONCILIATION'],
            'REVIEWED'                 => ['COMPLETED',
                                           // ← backward
                                           'RECONCILIATION_DISCREPANCY'],
            'COMPLETED'                => [],
            'DECLINED'                 => [],
        ];
    }

    /**
     * Get reimbursement workflow transitions
     */
    private function getReimbursementTransitions(): array
    {
        return [
            'DRAFT'                        => ['SUBMITTED'],
            'RETURNED_FOR_CORRECTION'      => ['SUBMITTED', 'DECLINED'],
            'SUBMITTED'                    => ['FUNDS_VERIFIED', 'RETURNED_FOR_CORRECTION', 'DECLINED'],
            'FUNDS_VERIFIED'               => ['INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'DECLINED',
                                               // ← backward
                                               'SUBMITTED', 'RETURNED_FOR_CORRECTION'],
            'INVOICE_SUBMITTED'            => ['INVOICE_VERIFIED', 'DECLINED',
                                               // ← backward
                                               'FUNDS_VERIFIED', 'SUBMITTED'],
            'INVOICE_VERIFIED'             => ['APPROVED', 'INVOICE_SUBMITTED', 'DECLINED',
                                               // ← backward
                                               'FUNDS_VERIFIED', 'SUBMITTED'],
            'APPROVED'                     => ['REIMBURSED',
                                               // ← backward
                                               'INVOICE_VERIFIED', 'INVOICE_SUBMITTED', 'FUNDS_VERIFIED'],
            'REIMBURSED'                   => ['COMPLETED',
                                               // ← backward
                                               'APPROVED'],
            'COMPLETED'                    => [],
            'DECLINED'                     => [],
        ];
    }

    /**
     * Get the workflow stage ordering for a request type
     * Used to determine if a transition is backward (revert)
     * 
     * @param string $requestType REGULAR, PETTY_CASH, or REIMBURSEMENT
     * @return array Ordered array of statuses for the workflow
     */
    public function getWorkflowOrder(string $requestType): array
    {
        $requestType = strtoupper($requestType);
        
        return match ($requestType) {
            'PETTY_CASH' => [
                'DRAFT', 'RETURNED_FOR_CORRECTION', 'SUBMITTED', 'FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'DISBURSED',
                'PENDING_RECONCILIATION', 'PROCUREMENT_VERIFIED', 'RECONCILIATION_DISCREPANCY',
                'REVIEWED', 'COMPLETED'
            ],
            'REIMBURSEMENT' => [
                'DRAFT', 'RETURNED_FOR_CORRECTION', 'SUBMITTED', 'FUNDS_VERIFIED', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED',
                'APPROVED', 'REIMBURSED', 'COMPLETED'
            ],
            'REGULAR', 'SERVICE_CONTRACT' => [
                'DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED',
                'FUNDS_VERIFIED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE',
                'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
                'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'EVALUATION_STAGE',
                'COMMITTEE_RECOMMENDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED',
                'PO_PENDING', 'INVOICE_RECEIVED', 'AWARDED', 'COMPLETED'
            ],
            default => []
        };
    }

    /**
     * Check if a transition is backward (revert) for the given request type
     * 
     * @param string $requestType REGULAR, PETTY_CASH, or REIMBURSEMENT
     * @param string $fromStatus Current status
     * @param string $toStatus Target status
     * @return bool True if this is a backward transition
     */
    public function isBackwardTransition(string $requestType, string $fromStatus, string $toStatus): bool
    {
        // Terminal statuses are never "backward" — they are always forward-only
        if (in_array(strtoupper($toStatus), ['DECLINED', 'CANCELLED', 'COMPLETED'], true)) {
            return false;
        }

        $order = $this->getWorkflowOrder($requestType);
        $fromIdx = array_search(strtoupper($fromStatus), $order);
        $toIdx = array_search(strtoupper($toStatus), $order);

        if ($fromIdx === false || $toIdx === false) {
            return false;
        }

        return $toIdx < $fromIdx;
    }

    /**
     * Get valid revert targets for a request
     * 
     * This is the core dynamic revert logic - it determines which previous stages
     * a request can be reverted to based on its type and current status.
     * 
     * @param string $requestType REGULAR, PETTY_CASH, or REIMBURSEMENT
     * @param string $currentStatus Current workflow status
     * @return array Array of valid target statuses for revert, with metadata
     */
    public function getValidRevertTargets(string $requestType, string $currentStatus): array
    {
        $requestType = strtoupper($requestType);
        $currentStatus = strtoupper($currentStatus);

        // Terminal states cannot be reverted
        if (in_array($currentStatus, ['DRAFT', 'COMPLETED', 'DECLINED', 'CANCELLED'], true)) {
            return [];
        }

        $transitions = $this->getTransitionsForType($requestType);
        $allowedNext = $transitions[$currentStatus] ?? [];

        $revertTargets = [];
        foreach ($allowedNext as $targetStatus) {
            if ($this->isBackwardTransition($requestType, $currentStatus, $targetStatus)) {
                $revertTargets[] = [
                    'status' => $targetStatus,
                    'label' => $this->getStatusLabel($requestType, $targetStatus),
                    'stage_owners' => $this->getStageOwners($requestType, $targetStatus)
                ];
            }
        }

        return $revertTargets;
    }

    /**
     * Get human-readable label for a status
     */
    private function getStatusLabel(string $requestType, string $status): string
    {
        $status = strtoupper($status);
        
        // Use type-specific labels where they differ
        if ($requestType === 'PETTY_CASH') {
            return match ($status) {
                'SUBMITTED' => 'Pending Finance Review',
                'FUNDS_VERIFIED' => 'Funds Verified',
                'FINANCE_AUTHORIZED' => 'Finance Authorized',
                'DISBURSED' => 'Disbursed',
                'PENDING_RECONCILIATION' => 'Reconciliation Due',
                'PROCUREMENT_VERIFIED' => 'Verified',
                'RECONCILIATION_DISCREPANCY' => 'Discrepancy Found',
                'REVIEWED' => 'Discrepancy Reviewed',
                default => str_replace('_', ' ', $status)
            };
        }

        if ($requestType === 'REIMBURSEMENT') {
            return match ($status) {
                'SUBMITTED' => 'Pending Finance Review',
                'FUNDS_VERIFIED' => 'Funds Verified',
                'INVOICE_SUBMITTED' => 'Invoices Submitted',
                'INVOICE_VERIFIED' => 'Invoices Verified',
                'APPROVED' => 'Approved',
                'REIMBURSED' => 'Reimbursed',
                default => str_replace('_', ' ', $status)
            };
        }

        // Regular/default labels
        return str_replace('_', ' ', $status);
    }

    /**
     * Get the roles/users responsible for a workflow stage
     * 
     * @param string $requestType REGULAR, PETTY_CASH, or REIMBURSEMENT
     * @param string $status Workflow status
     * @return array Array of role names
     */
    private function getStageOwners(string $requestType, string $status): array
    {
        $status = strtoupper($status);
        $requestType = strtoupper($requestType);

        // Petty Cash and Reimbursement stage owners
        if (in_array($requestType, ['PETTY_CASH', 'REIMBURSEMENT'])) {
            return match ($status) {
                'SUBMITTED' => ['Requestor'],
                'FUNDS_VERIFIED' => ['Finance Officer'],
                'FINANCE_AUTHORIZED' => ['Finance Officer'],
                'DISBURSED' => ['Finance Officer'],
                'PENDING_RECONCILIATION' => ['Requestor'],
                'PROCUREMENT_VERIFIED' => ['Procurement Officer'],
                'INVOICE_SUBMITTED' => ['Requestor'],
                'INVOICE_VERIFIED' => ['Procurement Officer', 'Finance Officer'],
                'APPROVED' => ['Finance Officer'],
                'REIMBURSED' => ['Finance Officer'],
                default => ['Requestor']
            };
        }

        // Regular procurement stage owners (from workflow.php stageOwner function)
        return match ($status) {
            'SUBMITTED' => ['Requestor'],
            'HOD_APPROVED' => ['HOD'],
            'FUNDS_VERIFIED' => ['Finance Officer'],
            'DIRECTOR_APPROVED' => ['Director HRM&A'],
            'GC_APPROVED' => ['Deputy Government Chemist'],
            'AWARDED' => ['Deputy Government Chemist'],
            'RFQ_LETTER_AVAILABLE' => ['Requestor', 'HOD', 'Branch Head', 'Procurement Officer', 'Director HRM&A', 'Deputy Government Chemist'],
            'QUOTE_REVIEW_PENDING' => ['Requestor', 'HOD', 'Branch Head', 'Procurement Officer'],
            'QUOTE_REQUESTOR_REVIEW_PENDING' => ['Requestor'],
            'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['Branch Head', 'HOD', 'Director HRM&A'],
            'PROCUREMENT_STAGE' => ['Procurement Officer', 'HOD'],
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['Branch Head', 'HOD', 'Director HRM&A'],
            'QUOTE_APPROVED' => ['Branch Head', 'HOD'],
            'COMMITMENTS_PENDING' => ['Finance Officer'],
            'COMMITMENT_APPROVED' => ['Finance Officer'],
            'COMMITMENT_DECLINED' => ['Finance Officer'],
            'PO_PENDING' => ['Procurement Officer', 'Accounts Officer'],
            'INVOICE_RECEIVED' => ['Accounts Officer', 'Finance Officer'],
            'EVALUATION_STAGE' => ['Procurement Officer'],
            'COMMITTEE_RECOMMENDED' => ['Procurement Committee'],
            default => ['Requestor']
        };
    }

    /**
     * Validate if a user can revert a request based on their role
     * 
     * @param string $role User's role
     * @param string $requestType Type of request
     * @return bool True if user can revert
     */
    public function canUserRevert(string $role, string $requestType): bool
    {
        $allowedRoles = ['HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist',
                        'Government Chemist', 'Finance Officer', 'Procurement Officer',
                        'Admin', 'SuperAdmin'];
        
        return in_array($role, $allowedRoles, true);
    }

    /**
     * Execute a workflow revert with full audit trail
     * 
     * @param int $requestId Request ID
     * @param string $requestType Request type
     * @param string $currentStatus Current status
     * @param string $targetStatus Target status to revert to
     * @param string $reason Reason for revert
     * @param int $userId User ID performing the revert
     * @param string $userRole User role
     * @param string $userName User name
     * @return bool Success
     * @throws Exception on failure
     */
    public function executeRevert(
        int $requestId,
        string $requestType,
        string $currentStatus,
        string $targetStatus,
        string $reason,
        int $userId,
        string $userRole,
        string $userName
    ): bool {
        if (!$this->pdo instanceof PDO) {
            throw new Exception('Database connection is required to execute workflow revert');
        }

        // Validate the transition
        if (!$this->isBackwardTransition($requestType, $currentStatus, $targetStatus)) {
            throw new Exception("Invalid revert: {$currentStatus} to {$targetStatus} is not a backward transition");
        }

        $transitions = $this->getTransitionsForType($requestType);
        if (!in_array($targetStatus, $transitions[strtoupper($currentStatus)] ?? [])) {
            throw new Exception("Transition not allowed in workflow configuration");
        }

        $this->pdo->beginTransaction();

        try {
            // Update request status
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                SET status = ?,
                    updated_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->execute([$targetStatus, $requestId]);

            // Clear pending approvals
            $stmt = $this->pdo->prepare("
                DELETE FROM request_approvals
                WHERE request_id = ?
                  AND status = 'pending'
            ");
            $stmt->execute([$requestId]);

            // Recreate approval chain if reverting to an approval stage
            if ($this->shouldRebuildApprovalChainOnRevert($requestType, $targetStatus)) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
                
                $reqStmt = $this->pdo->prepare("
                    SELECT request_type, estimated_value, branch_id
                    FROM procurement_requests
                    WHERE request_id = ?
                ");
                $reqStmt->execute([$requestId]);
                $reqDetails = $reqStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($reqDetails) {
                    createApprovalChain(
                        $this->pdo,
                        $requestId,
                        $reqDetails['request_type'] ?? 'REGULAR',
                        (float)($reqDetails['estimated_value'] ?? 0),
                        $reqDetails['branch_id']
                    );
                }

                private function shouldRebuildApprovalChainOnRevert(string $requestType, string $targetStatus): bool
                {
                    $requestType = strtoupper($requestType);
                    $targetStatus = strtoupper($targetStatus);

                    return match ($requestType) {
                        'PETTY_CASH', 'REIMBURSEMENT' => $targetStatus === 'SUBMITTED',
                        'REGULAR', 'SERVICE_CONTRACT' => in_array($targetStatus, ['SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED'], true),
                        default => false,
                    };
                }
            }

            // Create audit log entries
            $notes = "Workflow reverted from {$currentStatus} to {$targetStatus} by {$userName} ({$userRole}). Reason: {$reason}";
            
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
            logAudit($this->pdo, 'procurement_requests', $requestId, 'WORKFLOW_REVERT', $notes);
            logRequestTimeline($this->pdo, $requestId, 'WORKFLOW_REVERT', $notes);

            // Insert transition history
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO workflow_transition_history
                      (request_id, from_status, to_status, is_backward, actor_user_id, actor_role, reason, created_at)
                    VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
                ");
                $stmt->execute([$requestId, $currentStatus, $targetStatus, $userId, $userRole, $reason]);
            } catch (Throwable $e) {
                error_log('workflow_transition_history insert failed: ' . $e->getMessage());
            }

            $this->pdo->commit();
            return true;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
