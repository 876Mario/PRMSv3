<?php

/**
 * Workflow Transitions
 * ====================
 * Restructured for 3-approver model:
 *   HOD → Director HRM&A → Deputy Government Chemist
 *
 * RFQ Workflow:
 * - Upon approval → RFQ_LETTER_AVAILABLE (RFQ letter can be generated for vendors)
 * - Vendors submit quotes → QUOTE_REVIEW_PENDING (requestor/branch head reviews)
 * - Quote selected → QUOTE_APPROVED (selected quote meets requirements)
 * - Commitment created → COMMITMENT_PENDING (awaiting finance approval)
 * - PO created → PO_PENDING (awaiting HOD/Finance approval)
 * - Invoice uploaded → INVOICE_RECEIVED
 * - Final payment → COMPLETED
 *
 * Request Types: REGULAR, REIMBURSEMENT, PETTY_CASH
 * Direct procurement (under threshold) skips RFQ stage.
 */

function allowedTransitions(): array {
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
        // RFQ Workflow Stages
        'RFQ_LETTER_AVAILABLE'   => ['QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                     // ← backward
                                     'GC_APPROVED', 'DIRECTOR_APPROVED', 'HOD_APPROVED', 'SUBMITTED'],
        // Two-stage quote approval workflow
        'QUOTE_REVIEW_PENDING'   => ['QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_APPROVED', 'PROCUREMENT_STAGE', 'AWARDED',
                                     // ← backward
                                     'RFQ_LETTER_AVAILABLE'],
        'QUOTE_REQUESTOR_REVIEW_PENDING' => ['QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                        // ← backward (return for correction)
                                        'RFQ_LETTER_AVAILABLE'],
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                         // ← backward (return to spec review)
                                         'QUOTE_REQUESTOR_REVIEW_PENDING', 'RFQ_LETTER_AVAILABLE'],
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['QUOTE_APPROVED', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'AWARDED',
                                                 // ← backward (return to spec review)
                                                 'RFQ_LETTER_AVAILABLE'],
        'QUOTE_APPROVED'         => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'COMMITMENTS_PENDING', 'FUNDS_VERIFIED', 'PROCUREMENT_STAGE',
                                     // ← backward
                                     'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_REVIEW_PENDING', 'RFQ_LETTER_AVAILABLE'],
        'COMMITMENTS_PENDING'    => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'PROCUREMENT_STAGE',
                                     // ← backward
                                     'QUOTE_APPROVED', 'FUNDS_VERIFIED'],
        'COMMITMENT_APPROVED'    => ['PO_PENDING', 'INVOICE_RECEIVED', 'AWARDED',
                                     // ← backward (Finance can revert to re-check funds)
                                     'COMMITMENTS_PENDING', 'FUNDS_VERIFIED'],
        'COMMITMENT_DECLINED'    => ['QUOTE_REVIEW_PENDING', 'PROCUREMENT_STAGE', 'SUBMITTED'],
        'PO_PENDING'             => ['INVOICE_RECEIVED', 'AWARDED',
                                     // ← backward
                                     'COMMITMENT_APPROVED'],
        'INVOICE_RECEIVED'       => ['COMPLETED'],
        // Original stages (still supported for backward compatibility)
        'PROCUREMENT_STAGE'      => ['EVALUATION_STAGE', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                     // ← backward
                                     'GC_APPROVED', 'HOD_APPROVED', 'SUBMITTED'],
        'EVALUATION_STAGE'       => ['COMMITTEE_RECOMMENDED', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                     // ← backward
                                     'PROCUREMENT_STAGE'],
        'COMMITTEE_RECOMMENDED'  => ['GC_APPROVED', 'QUOTE_REVIEW_PENDING', 'AWARDED',
                                     // ← backward
                                     'EVALUATION_STAGE'],
        'AWARDED'                => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED', 'COMMITMENTS_PENDING', 'FUNDS_VERIFIED', 'PO_PENDING', 'INVOICE_RECEIVED'],
    ];
}

/**
 * Roles permitted to trigger backward (revert) workflow transitions.
 * These are roles with oversight authority — they may move a request
 * back to a prior stage for correction without fully rejecting it.
 */
function allowedRevertRoles(): array {
    return ['HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist',
            'Government Chemist', 'Finance Officer', 'Procurement Officer',
            'Admin', 'SuperAdmin'];
}

/**
 * Determine whether a transition is a backward (revert) move.
 * Uses the natural ordering of statuses defined in the standard pipeline.
 */
function isBackwardTransition(string $from, string $to): bool {
    // Terminal statuses are never "backward" — they are always forward-only
    if (in_array(strtoupper($to), ['DECLINED', 'CANCELLED', 'COMPLETED'], true)) {
        return false;
    }
    $order = [
        'DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED',
        'FUNDS_VERIFIED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE',
        'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'EVALUATION_STAGE',
        'COMMITTEE_RECOMMENDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED',
        'PO_PENDING', 'INVOICE_RECEIVED', 'AWARDED', 'COMPLETED',
    ];
    $fromIdx = array_search(strtoupper($from), $order);
    $toIdx   = array_search(strtoupper($to), $order);
    if ($fromIdx === false || $toIdx === false) {
        return false;
    }
    return $toIdx < $fromIdx;
}

function canTransition(string $current, string $next): bool {
    $current = strtoupper($current);
    $next = strtoupper($next);

    // Cancellation is allowed from any stage except final/terminal states
    if ($next === 'CANCELLED') {
        return !in_array($current, ['COMPLETED', 'DECLINED', 'CANCELLED', 'PAUSED']);
    }

    if ($next === 'PAUSED') {
        return !in_array($current, ['DRAFT', 'COMPLETED', 'DECLINED', 'CANCELLED', 'PAUSED']);
    }

    $map = allowedTransitions();
    return in_array($next, $map[$current] ?? []);
}

/**
 * Return the ordered list of statuses that indicate a request has passed the award
 * decision point — covering every stage from AWARDED through to COMPLETED.
 * COMPLETED is included so that a fully-finished skip-RFQ request is still
 * correctly identified as having used the "Proceed Without RFQ" path (e.g. for
 * pipeline display on a read-only completed view).
 *
 * NOTE: This array must be kept in sync with allowedTransitions() in this file.
 * If new post-award statuses are added to the transitions map, add them here too.
 *
 * Used by isSkipRfqPath() to detect skip-RFQ requests without relying on the
 * requires_rfq column, which is reset by the database trigger on every update.
 *
 * @return string[]
 */
function getAwardAndBeyondStatuses(): array {
    return ['AWARDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'INVOICE_RECEIVED', 'COMPLETED'];
}

/**
 * Determine whether a procurement request used the "Proceed Without RFQ" path.
 *
 * Detection heuristic: a REGULAR request at an AWARDED-or-beyond status with no
 * linked RFQ record is treated as a skip-RFQ request. The requires_rfq column
 * cannot be used reliably because the BEFORE UPDATE trigger resets it to 1 for
 * all REGULAR requests on every UPDATE (see trg_auto_update_requires_rfq).
 *
 * Known edge case: if an RFQ record is later deleted this would give a false
 * positive — that scenario is treated as an acceptable limitation.
 *
 * @param string   $requestType  Value of procurement_requests.request_type
 * @param int|bool $rfqId        ID of the linked rfqs row, or falsy if none exists
 * @param string   $currentStatus Current status of the request (uppercase)
 * @return bool
 */
/**
 * Determine whether a procurement request used the "Proceed Without RFQ" path.
 *
 * Accepts an optional fourth argument: the full procurement_requests row array.
 * When supplied and the row contains workflow_path = 'NON_PO_SKIP_RFQ', that flag
 * takes precedence and the function returns true regardless of the other arguments.
 * This covers cases where Finance explicitly chose "No PO Required" at commitment
 * creation time but the request may already have an RFQ row from a prior attempt.
 *
 * @param string      $requestType  Value of procurement_requests.request_type
 * @param int|bool    $rfqId        ID of the linked rfqs row, or falsy if none
 * @param string      $currentStatus Current status of the request (uppercase)
 * @param array|null  $requestRow   Optional full request row for workflow_path check
 * @return bool
 */
function isSkipRfqPath(string $requestType, $rfqId, string $currentStatus, ?array $requestRow = null): bool {
    // Explicit Non-PO path flag takes priority over the heuristic
    if ($requestRow !== null && ($requestRow['workflow_path'] ?? '') === 'NON_PO_SKIP_RFQ') {
        return true;
    }
    return $requestType === 'REGULAR'
        && !$rfqId
        && in_array(strtoupper($currentStatus), getAwardAndBeyondStatuses(), true);
}

/**
 * Return the human-readable guidance text for the post-award workflow steps.
 * Centralised here so that view.php and skip_rfq.php always present the
 * same wording; update this string in one place if steps ever change.
 *
 * @return string Plain-text description of the remaining steps after award.
 */
function getAwardedWorkflowGuidance(): string {
    return "Create a Commitment in GFMS, then a Purchase Order, upload the Vendor Invoice, and record payment to complete this request. Responsible: Finance Officer / Procurement Officer.";
}

/**
 * Signed request form gating
 * ==========================
 * Once a request is submitted, the Branch Head must not approve until the
 * requester has printed, signed, and uploaded the signed request form.
 *
 * @param array $request Row from procurement_requests
 * @return bool True when approval must be blocked pending signed request upload
 */
function signedRequestUploadPending(array $request): bool {
    // Gate applies to all request types at SUBMITTED status
    if (strtoupper($request['status'] ?? '') !== 'SUBMITTED') {
        return false;
    }
    // Check if signed request document is missing
    return empty($request['signed_request_document_path']);
}

/**
 * Determine which roles own each approval stage
 */
function stageOwner(string $stage): array {
    return [
        'HOD_APPROVED'           => ['HOD'],
        'FUNDS_VERIFIED'         => ['Finance Officer'],
        'DIRECTOR_APPROVED'      => ['Director HRM&A'],
        'GC_APPROVED'            => ['Deputy Government Chemist'],
        'AWARDED'                => ['Deputy Government Chemist'],
        // RFQ Workflow Stages (reachable through HOD approval)
        'RFQ_LETTER_AVAILABLE'   => ['Requestor', 'HOD', 'Branch Head', 'Procurement Officer', 'Director HRM&A', 'Deputy Government Chemist'],
        'QUOTE_REVIEW_PENDING'   => ['Requestor', 'HOD', 'Branch Head', 'Procurement Officer'], // For quote review & quote selection
        'QUOTE_REQUESTOR_REVIEW_PENDING' => ['Requestor'], // Original requestor confirms the selected quotation meets specifications
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['Branch Head', 'HOD', 'Director HRM&A'], // Auto-routes to Branch Head approval
        'PROCUREMENT_STAGE'      => ['Procurement Officer', 'HOD'], // HOD can approve and transition to this
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['Branch Head', 'HOD', 'Director HRM&A'], // Branch Head final approval stage
        'QUOTE_APPROVED'         => ['Branch Head', 'HOD'], // Branch Head approval completed; ready for commitment / award actions
        'COMMITMENTS_PENDING'    => ['Finance Officer'], // Finance uploads commitment after Procurement submits form
        'COMMITMENT_APPROVED'    => ['Finance Officer'], // Finance approval with funds verification
        'COMMITMENT_DECLINED'    => ['Finance Officer'], // Finance declined due to fund constraints
        'PO_PENDING'             => ['Procurement Officer', 'Accounts Officer'], // Creating PO from GFMS
        'INVOICE_RECEIVED'       => ['Accounts Officer', 'Finance Officer'], // Invoice creation/upload
        // Legacy
        'EVALUATION_STAGE'       => ['Procurement Officer'],
        'COMMITTEE_RECOMMENDED'  => ['Procurement Committee'],
    ][$stage] ?? [];
}

/**
 * Get the approval chain for a request based on branch and amount.
 * Returns array of approver roles in order.
 *
 * Amount-based approval thresholds:
 *   - Over 500,000 JMD: Requires HOD approval
 *   - Over 3,000,000 JMD: Requires committee review
 *
 * Branch-based approvals:
 *   - HRM&A branch (id=5)              → Director HRM&A
 *   - Analytical & Advisory branch (id=6) → Deputy Government Chemist
 *   - All other branches               → HOD
 *
 * Petty Cash / Reimbursement: Direct to Finance Officer for fund verification.
 */
function getApprovalChain(string $requestType, float $estimatedValue, ?int $branchId = null, ?PDO $pdo = null): array {
    // Petty cash / reimbursement: Finance Officer only (fund verification)
    if (in_array($requestType, ['PETTY_CASH', 'REIMBURSEMENT'])) {
        return ['Finance Officer'];
    }

    // Service contract: Branch Head approves, same as regular branch-based
    if ($requestType === 'SERVICE_CONTRACT') {
        return getServiceContractApprovalChain($estimatedValue, $branchId, $pdo);
    }

    // Get thresholds from database (if PDO provided)
    $hodThreshold = 500000.00;
    $committeeThreshold = 3000000.00;
    
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'hod_approval_threshold'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                $hodThreshold = (float)$val;
            }
        } catch (Exception $e) {
            // Use default if query fails
        }
        
        try {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'committee_review_threshold'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                $committeeThreshold = (float)$val;
            }
        } catch (Exception $e) {
            // Use default if query fails
        }
    }

    // Build approval chain based on amount thresholds
    $chain = [];
    
    // Over committee review threshold: Add committee review
    if ($estimatedValue > $committeeThreshold) {
        $chain[] = 'Procurement Committee';
    }
    
    // Over HOD threshold: Add HOD approval
    if ($estimatedValue > $hodThreshold) {
        $chain[] = 'HOD';
    }
    
    // Add branch-based primary approver
    if ($branchId === 6) {
        // Analytical & Advisory Branch → Deputy Government Chemist
        $chain[] = 'Deputy Government Chemist';
    } elseif ($branchId === 5) {
        // HRM&A Branch → Director HRM&A
        $chain[] = 'Director HRM&A';
    } else {
        // All other branches → HOD (if not already added by threshold)
        if (!in_array('HOD', $chain)) {
            $chain[] = 'HOD';
        }
    }

    return $chain;
}

/**
 * Resolve the complete workflow configuration for a request.
 * This is the single entry point for determining how a request moves
 * through the system based on its type, value, and originating branch.
 *
 * @param PDO    $pdo            Database connection (reads thresholds from system_config)
 * @param string $requestType    REGULAR | REIMBURSEMENT | PETTY_CASH
 * @param float  $estimatedValue The estimated monetary value of the request
 * @param int|null $branchId     The originating branch (affects under-threshold routing)
 * @return array {
 *   'request_type'        => string,
 *   'threshold'           => float,    // current system threshold
 *   'is_under_threshold'  => bool,
 *   'is_direct'           => bool,     // skip RFQ entirely?
 *   'approval_chain'      => string[], // ordered roles for initial approval
 *   'post_approval_status'=> string,   // status after final approval stage
 *   'workflow_label'      => string,   // human-readable workflow name
 * }
 */
function resolveWorkflow(PDO $pdo, string $requestType, float $estimatedValue, ?int $branchId = null): array {
    $threshold = getDirectProcurementThreshold($pdo);
    // Fetch currency and usd_rate if available (for correct threshold comparison)
    $currency = null;
    $usdRate = null;
    if (func_num_args() > 4) {
        $currency = func_get_arg(4);
        $usdRate = func_get_arg(5);
    }
    if (!$currency && isset($GLOBALS['request'])) {
        $currency = $GLOBALS['request']['currency'] ?? 'JMD';
        $usdRate = $GLOBALS['request']['usd_rate'] ?? null;
    }
    if (!$currency) $currency = 'JMD';
    if (!$usdRate) {
        // fallback to system rate
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
        $stmt->execute();
        $usdRate = (float)($stmt->fetchColumn() ?: 155.00);
    }
    $jmdValue = ($currency === 'USD') ? $estimatedValue * (float)$usdRate : $estimatedValue;
    $isUnderThreshold = $jmdValue <= $threshold;
    $isDirect = isDirectProcurement($requestType, $jmdValue);
    $approvalChain = getApprovalChain($requestType, $jmdValue, $branchId, $pdo);

    // Determine the status the request transitions to after its approval chain completes
    if ($isDirect) {
        if ($requestType === 'SERVICE_CONTRACT') {
            $postApprovalStatus = 'HOD_APPROVED';
            $workflowLabel      = 'Service Contract Payment';
        } elseif ($requestType === 'PETTY_CASH') {
            $postApprovalStatus = 'AWARDED';
            $workflowLabel      = 'Petty Cash (Direct)';
        } else {
            $postApprovalStatus = 'AWARDED';
            $workflowLabel      = 'Reimbursement (Direct)';
        }
    } elseif ($isUnderThreshold) {
        $postApprovalStatus = 'RFQ_LETTER_AVAILABLE';
        $workflowLabel      = 'Under-Threshold RFQ (Simplified)';
    } else {
        $postApprovalStatus = 'PROCUREMENT_STAGE';
        $workflowLabel      = 'Over-Threshold RFQ (Full Evaluation)';
    }

    return [
        'request_type'         => $requestType,
        'threshold'            => $threshold,
        'is_under_threshold'   => $isUnderThreshold,
        'is_direct'            => $isDirect,
        'approval_chain'       => $approvalChain,
        'post_approval_status' => $postApprovalStatus,
        'workflow_label'       => $workflowLabel,
    ];
}

/**
 * Build the commitment approval chain for a given request.
 * Centralises logic previously duplicated in commitments/approve.php,
 * commitments/upload.php, and commitments/add_supplementary.php.
 *
 * @param PDO $pdo              Database connection
 * @param float $estimatedValue The parent request estimated value
 * @param int   $branchId       The parent request branch ID
 * @return array Array of ['role' => string, 'stage_order' => int]
 */
function getCommitmentApprovalChain(PDO $pdo, float $estimatedValue, int $branchId): array {
    // No approval chain needed for commitments.
    // Finance verifies funds and uploads commitment directly (no multi-stage approval).
    return [];
}

/**
 * Get fallback approvers for a given stage.
 * Only the primary role can approve - no fallback chain.
 * Each branch has exactly ONE designated approver.
 *
 * @param string $primaryRole The primary approver role for this stage
 * @param float $estimatedValue The request amount (unused, kept for API compat)
 * @return array Roles that can approve this stage
 */
function getFallbackApprovers(string $primaryRole, float $estimatedValue): array {
    return [$primaryRole];
}

/**
 * Check if a user's role can approve at a given stage.
 * Only the exact designated approver role can approve.
 *
 * @param string $userRole The user's role
 * @param string $stageRole The required role for this stage
 * @param float $estimatedValue The request amount (unused, kept for API compat)
 * @return bool
 */
function canApproveStage(string $userRole, string $stageRole, float $estimatedValue): bool {
    // Direct match (case-insensitive)
    if (strcasecmp($userRole, $stageRole) === 0) {
        return true;
    }

    // Equivalent / interchangeable roles
    $equivalentRoles = [
        'HOD' => ['Branch Head'],
        'Branch Head' => ['HOD'],
        'Deputy Government Chemist' => ['Government Chemist'],
        'Government Chemist' => ['Deputy Government Chemist'],
    ];

    $equivalents = $equivalentRoles[$stageRole] ?? [];
    foreach ($equivalents as $eq) {
        if (strcasecmp($userRole, $eq) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Check if a request qualifies for direct procurement (skip RFQ)
 * 
 * IMPORTANT: As of Feb 2026, ALL REGULAR PROCUREMENT now requires RFQ,
 * even under-threshold. This function checks if a request can skip the
 * RFQ workflow entirely.
 * 
 * - Petty Cash: Always direct (immediate HOD approval → disbursement)
 * - Reimbursement: Always direct (already purchased, just needs authorization)
 * - Regular Procurement: NEVER direct anymore (all requests must go through RFQ)
 *   - Under-threshold (≤500K): RFQ without committee evaluation
 *   - Over-threshold (>500K): RFQ with committee evaluation
 */
function isDirectProcurement(string $requestType, float $estimatedValue): bool {
    // Petty cash is always direct
    if ($requestType === 'PETTY_CASH') {
        return true;
    }

    // Reimbursement is always direct (already purchased)
    if ($requestType === 'REIMBURSEMENT') {
        return true;
    }

    // Service contracts are direct (no RFQ needed, contract already in place)
    if ($requestType === 'SERVICE_CONTRACT') {
        return true;
    }

    // REGULAR PROCUREMENT: ALL amounts now require RFQ
    // (both under and over-threshold must use RFQ)
    // Under-threshold: Simplified RFQ, skip committee evaluation
    // Over-threshold: Full RFQ with committee evaluation
    return false;
}

/**
 * Get the petty cash limit from system config or return default
 */
function getPettyCashLimit(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'petty_cash_limit'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== false ? (float)$val : 5000.00;
}

/**
 * Get the direct procurement threshold from system config
 */
function getDirectProcurementThreshold(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'direct_procurement_threshold'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== false ? (float)$val : 500000.00;
}

/**
 * Get the HOD approval threshold from system config
 * Requests above this amount require HOD approval
 */
function getHODApprovalThreshold(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'hod_approval_threshold'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== false ? (float)$val : 500000.00;
}

/**
 * Get the committee review threshold from system config
 * Requests above this amount require committee review
 */
function getCommitteeReviewThreshold(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'committee_review_threshold'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== false ? (float)$val : 3000000.00;
}

function enforceTransition(array $request, string $nextStage) {

    if (!canTransition($request['status'], $nextStage)) {
        pop(
            "Invalid workflow transition from {$request['status']} to {$nextStage}",
            '/procurement/list.php',
            POP_DEFAULT_DELAY_MS,
            'error'
        );
        exit;
    }

    // Gate: Check if a signed request document is required before transitioning from SUBMITTED
    if ($request['status'] === 'SUBMITTED' && signedRequestUploadPending($request)) {
        pop(
            'A signed request document must be uploaded before proceeding. Please print the form, sign it, and upload the signed copy.',
            '/procurement/view.php?id=' . $request['request_id'],
            POP_DEFAULT_DELAY_MS,
            'warning'
        );
        exit;
    }

    // Terminal statuses (AWARDED, COMPLETED) don't need role checking
    // They can be reached by any role after their approval is complete
    if (in_array(strtoupper($nextStage), ['AWARDED', 'COMPLETED', 'REIMBURSED', 'DECLINED', 'CANCELLED'])) {
        return;
    }

    $allowedRoles = stageOwner($nextStage);
    $userRole = $_SESSION['role_name'];

    if (!in_array($userRole, $allowedRoles)) {
        pop(
            'You are not authorized to perform this stage action.',
            '/dashboard/index.php',
            POP_DEFAULT_DELAY_MS,
            'error'
        );
        exit;
    }
}

/**
 * Determine the next status to transition to based on approval chain
 * For under-threshold requests, returns AWARDED (direct procurement)
 * For over-threshold requests, returns PROCUREMENT_STAGE (requires RFQ)
 * For intermediate approvals, returns the intermediate stage
 *
 * @param PDO $pdo Database connection
 * @param int $requestId Request ID
 * @param string $approvingRole The role that is currently approving
 * @return string The next status to transition to
 */
function getNextStatusAfterApproval(PDO $pdo, int $requestId, string $approvingRole): string {
    // Get all pending approvals to see what's left
    $stmt = $pdo->prepare("
        SELECT role, stage_order
        FROM request_approvals
        WHERE request_id = ?
          AND status = 'pending'
        ORDER BY stage_order ASC
    ");
    $stmt->execute([$requestId]);
    $pendingApprovals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get the current approving stage info
    $currentStmt = $pdo->prepare("
        SELECT role, stage_order
        FROM request_approvals
        WHERE request_id = ?
          AND role = ?
          AND status = 'pending'
        LIMIT 1
    ");
    $currentStmt->execute([$requestId, $approvingRole]);
    $currentApproval = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    // If no pending approvals remain after this one, determine final status based on request type and threshold
    $remainingApprovals = array_filter($pendingApprovals, function($a) use ($currentApproval) {
        return (int)$a['stage_order'] > (int)$currentApproval['stage_order'];
    });
    
    // If this is the last approval, determine FINAL status based on request type and threshold
    if (empty($remainingApprovals)) {
        // Fetch request details to check type, estimated value, branch, and current status
        $reqStmt = $pdo->prepare("
            SELECT request_type, estimated_value, branch_id, status
            FROM procurement_requests
            WHERE request_id = ?
        ");
        $reqStmt->execute([$requestId]);
        $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reqData) {
            $currentStatus = $reqData['status'] ?? '';
            
            // If the request is already past PROCUREMENT_STAGE (in evaluation/committee stages),
            // don't regress back to PROCUREMENT_STAGE — advance to GC_APPROVED instead
            $evaluationStatuses = ['EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED'];
            if (in_array($currentStatus, $evaluationStatuses)) {
                return 'GC_APPROVED';
            }
            
            // Use the centralised workflow resolver for consistent threshold handling
            $wf = resolveWorkflow(
                $pdo,
                $reqData['request_type'] ?? 'REGULAR',
                (float)($reqData['estimated_value'] ?? 0),
                isset($reqData['branch_id']) ? (int)$reqData['branch_id'] : null
            );
            return $wf['post_approval_status'];
        }
        return 'AWARDED'; // Fallback
    }
    
    // Otherwise, map the approving role to its intermediate stage
    return match($approvingRole) {
        'HOD' => 'HOD_APPROVED',
        'Finance Officer' => 'FUNDS_VERIFIED',
        'Director HRM&A' => 'DIRECTOR_APPROVED',
        'Deputy Government Chemist' => 'GC_APPROVED',
        'Branch Head' => 'HOD_APPROVED',
        'Procurement Officer' => 'FUNDS_VERIFIED',
        default => 'HOD_APPROVED'
    };
}

/**
 * ========================================
 * REIMBURSEMENT WORKFLOW FUNCTIONS
 * ========================================
 */

/**
 * Get reimbursement approval chain
 * Flow: Direct to Finance for fund verification
 */
function getReimbursementApprovalChain(): array {
    return ['Finance Officer'];
}

/**
 * Get allowed status transitions for reimbursement requests
 * Simplified workflow: Submitted -> Finance Verifies -> Reimbursed
 */
/**
 * Get allowed status transitions for reimbursement requests.
 *
 * Note on FUNDS_VERIFIED → APPROVED / INVOICE_VERIFIED bypass: While the standard
 * pipeline includes INVOICE_SUBMITTED and INVOICE_VERIFIED stages, the system allows
 * direct bypasses from FUNDS_VERIFIED to APPROVED, and from FUNDS_VERIFIED straight
 * to INVOICE_VERIFIED. This is intentional and permits:
 * - Finance officers to approve without invoice submission in cases where
 *   invoices were already verified externally or are waived
 * - Expedited processing for small or pre-approved reimbursement requests
 * - Procurement/Finance to verify an invoice attached to a request that never had
 *   its status explicitly bumped to INVOICE_SUBMITTED (e.g. invoice uploaded before
 *   that intermediate step was recorded), so the pipeline is not stuck at
 *   FUNDS_VERIFIED once the invoice has actually been verified.
 *
 * The default workflow should guide users through INVOICE_SUBMITTED and INVOICE_VERIFIED,
 * but the bypasses remain available for exceptional cases requiring explicit authorization.
 */
function getReimbursementTransitions(): array {
    return [
        'DRAFT'                        => ['SUBMITTED'],
        'SUBMITTED'                    => ['FUNDS_VERIFIED', 'DECLINED'],
        'FUNDS_VERIFIED'               => ['INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'DECLINED'],
        'INVOICE_SUBMITTED'            => ['INVOICE_VERIFIED', 'DECLINED'],
        'INVOICE_VERIFIED'             => ['APPROVED', 'INVOICE_SUBMITTED', 'DECLINED'],
        'APPROVED'                     => ['REIMBURSED'],
        'REIMBURSED'                   => ['COMPLETED'],
        'COMPLETED'                    => [],
        'DECLINED'                     => [],
    ];
}

/**
 * Check if reimbursement request can transition to next status
 */
function canReimbursementTransition(string $current, string $next): bool {
    $map = getReimbursementTransitions();
    return in_array(strtoupper($next), $map[strtoupper($current)] ?? []);
}

/**
 * ========================================
 * PETTY CASH WORKFLOW FUNCTIONS
 * ========================================
 */

/**
 * Get petty cash approval chain
 * Flow: Direct to Finance for fund verification
 */
function getPettyCashApprovalChain(): array {
    return ['Finance Officer'];
}

/**
 * Get allowed status transitions for petty cash requests
 * Simplified workflow: Submitted -> Finance Authorizes -> Disbursed -> Reconciliation
 */
function getPettyCashTransitions(): array {
    return [
        'DRAFT'                    => ['SUBMITTED'],
        'SUBMITTED'                => ['FUNDS_VERIFIED', 'DECLINED'],
        'FUNDS_VERIFIED'           => ['FINANCE_AUTHORIZED', 'DECLINED'],
        'FINANCE_AUTHORIZED'       => ['DISBURSED'],
        'DISBURSED'                => ['PENDING_RECONCILIATION'],
        'PENDING_RECONCILIATION'   => ['PROCUREMENT_VERIFIED', 'RECONCILIATION_DISCREPANCY'],
        'PROCUREMENT_VERIFIED'     => ['COMPLETED'],
        'RECONCILIATION_DISCREPANCY' => ['REVIEWED'],
        'REVIEWED'                 => ['COMPLETED'],
        'COMPLETED'                => [],
        'DECLINED'                 => [],
    ];
}

/**
 * Check if petty cash request can transition to next status
 */
function canPettyCashTransition(string $current, string $next): bool {
    $map = getPettyCashTransitions();
    return in_array(strtoupper($next), $map[strtoupper($current)] ?? []);
}

/**
 * Calculate petty cash 24-hour deadline
 * Returns DateTime for the deadline and minutes remaining
 */
function getPettyCashDeadline(DateTime $disbursementTime, float $windowHours = 24.0): array {
    $deadline = clone $disbursementTime;
    $deadline->add(new DateInterval('PT' . intval($windowHours) . 'H'));
    
    $now = new DateTime();
    $interval = $now->diff($deadline);
    $minutesRemaining = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    $isOverdue = $now > $deadline;
    
    return [
        'deadline' => $deadline,
        'minutes_remaining' => $minutesRemaining,
        'is_overdue' => $isOverdue,
        'hours_text' => $interval->format('%h hours %i minutes')
    ];
}

/**
 * Get reimbursement status display label with icon
 */
function getReimbursementStatusLabel(string $status): string {
    return match($status) {
        'DRAFT' => '📝 Draft',
        'SUBMITTED' => '📤 Pending Finance Review',
        'FUNDS_VERIFIED' => '💰 Funds Verified',
        'INVOICE_SUBMITTED' => '📄 Invoices Submitted',
        'INVOICE_VERIFIED' => '✔️ Invoices Verified',
        'APPROVED' => '✅ Approved',
        'REIMBURSED' => '💳 Reimbursed',
        'COMPLETED' => '✓ Completed',
        'DECLINED' => '❌ Declined',
        default => htmlspecialchars($status)
    };
}

/**
 * Get petty cash status display label with icon
 */
function getPettyCashStatusLabel(string $status): string {
    return match($status) {
        'DRAFT' => '📝 Draft',
        'SUBMITTED' => '📤 Pending Finance Review',
        'FUNDS_VERIFIED' => '💰 Funds Verified',
        'FINANCE_AUTHORIZED' => '💰 Finance Authorized',
        'DISBURSED' => '💵 Disbursed',
        'PENDING_RECONCILIATION' => '⏱️ Reconciliation Due',
        'PROCUREMENT_VERIFIED' => '✔️ Verified',
        'RECONCILIATION_DISCREPANCY' => '⚠️ Discrepancy Found',
        'REVIEWED' => '👀 Discrepancy Reviewed',
        'COMPLETED' => '✓ Completed',
        'DECLINED' => '❌ Declined',
        default => htmlspecialchars($status)
    };
}

/**
 * ========================================
 * RFQ WORKFLOW FUNCTIONS (NEW)
 * ========================================
 */

/**
 * Check if RFQ letter can be generated at current stage
 * RFQ Letter should be available after HOD, Director, or GC approval
 * for over-threshold requests
 *
 * @param string $status Current request status
 * @param bool $isDirectProcurement Whether this is direct procurement
 * @return bool True if RFQ letter can be generated
 */
function canGenerateRFQLetterAtStage(string $status, bool $isDirectProcurement): bool {
    // RFQ letters not needed for direct procurement
    if ($isDirectProcurement) {
        return false;
    }
    
    // RFQ letter can be generated once approval is received
    $approvingStages = ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'INVOICE_RECEIVED', 'AWARDED'];
    return in_array(strtoupper($status), $approvingStages);
}

/**
 * Get the current step in RFQ workflow for display
 * 
 * @param string $status Current request status
 * @param bool $rfqExists Whether RFQ has been created
 * @return array Array with step_number, step_name, and step_description
 */
function getRFQWorkflowStep(string $status, bool $rfqExists = false): array {
    $stepMap = [
        'DRAFT' => ['number' => 0, 'name' => 'Draft', 'description' => 'Request being prepared'],
        'SUBMITTED' => ['number' => 1, 'name' => 'Submitted', 'description' => 'Awaiting approval'],
        'HOD_APPROVED' => ['number' => 2, 'name' => 'HOD Approved', 'description' => 'Ready for RFQ Letter generation'],
        'DIRECTOR_APPROVED' => ['number' => 2, 'name' => 'Director Approved', 'description' => 'Ready for RFQ Letter generation'],
        'GC_APPROVED' => ['number' => 2, 'name' => 'GC Approved', 'description' => 'Ready for RFQ Letter generation'],
        'FUNDS_VERIFIED' => ['number' => 2, 'name' => 'Funds Verified', 'description' => 'Ready for RFQ Letter generation'],
        'RFQ_LETTER_AVAILABLE' => ['number' => 3, 'name' => 'RFQ Letter Available', 'description' => 'Send RFQ to vendors'],
        'PROCUREMENT_STAGE' => ['number' => 3, 'name' => 'Procurement Stage', 'description' => 'RFQ process initiated'],
        'QUOTE_REVIEW_PENDING' => ['number' => 4, 'name' => 'Quotes Submitted', 'description' => 'Review vendor quotes and select the preferred offer'],
        'QUOTE_REQUESTOR_REVIEW_PENDING' => ['number' => 5, 'name' => 'Pending Requestor Review', 'description' => 'Original requestor must confirm the selected quotation meets specifications'],
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['number' => 6, 'name' => 'Requestor Review Approved', 'description' => 'Selected quotation confirmed by the original requestor'],
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['number' => 7, 'name' => 'Pending Branch Head Approval', 'description' => 'Branch Head must record the final award decision'],
        'QUOTE_APPROVED' => ['number' => 8, 'name' => 'Quote Fully Approved', 'description' => 'Both RFQ approval stages are complete'],
        'COMMITMENTS_PENDING' => ['number' => 9, 'name' => 'Commitment Form Submitted', 'description' => 'Procurement submitted commitment form, awaiting Finance upload'],
        'COMMITMENT_APPROVED' => ['number' => 10, 'name' => 'Commitment Approved', 'description' => 'Finance approved commitment'],
        'PO_PENDING' => ['number' => 11, 'name' => 'PO Created', 'description' => 'Purchase Order created, ready for invoice'],
        'INVOICE_RECEIVED' => ['number' => 12, 'name' => 'Invoice Received', 'description' => 'Vendor invoice uploaded'],
        'EVALUATION_STAGE' => ['number' => 4, 'name' => 'Evaluation Stage', 'description' => 'RFQ under evaluation'],
        'COMMITTEE_RECOMMENDED' => ['number' => 5, 'name' => 'Committee Recommended', 'description' => 'Evaluation complete'],
        'AWARDED' => ['number' => 11, 'name' => 'Awarded', 'description' => 'Contract awarded'],
        'COMPLETED' => ['number' => 12, 'name' => 'Completed', 'description' => 'All processes completed'],
    ];
    
    return $stepMap[strtoupper($status)] ?? ['number' => 0, 'name' => $status, 'description' => 'Status: ' . $status];
}

/**
 * Get the next required step after current status in RFQ workflow
 * 
 * @param string $status Current request status
 * @param bool $isDirectProcurement Whether this is direct procurement
 * @return array with 'status' and 'description' of next step
 */
function getNextRFQStep(string $status, bool $isDirectProcurement = false): array {
    if ($isDirectProcurement) {
        return ['status' => 'AWARDED', 'description' => 'Ready for direct procurement (skip RFQ)'];
    }

    /**
     * Admin-selectable workflow statuses for regular procurement requests.
     *
     * These labels intentionally mirror the visible pipeline so manual overrides
     * cannot introduce status values that the workflow UI does not understand.
     *
     * @return array<string, array{label: string, description: string, icon: string}>
     */
    function getAdminWorkflowStatusOptions(): array {
        return [
            'DRAFT' => [
                'label' => 'Draft',
                'description' => 'Request created but not submitted.',
                'icon' => 'bi-pencil-square',
            ],
            'SUBMITTED' => [
                'label' => 'Submitted',
                'description' => 'Request submitted for review.',
                'icon' => 'bi-send',
            ],
            'DIRECTOR_APPROVED' => [
                'label' => 'Director Approved',
                'description' => 'Request approved by Director.',
                'icon' => 'bi-briefcase-fill',
            ],
            'RFQ_LETTER_AVAILABLE' => [
                'label' => 'RFQ Letters',
                'description' => 'RFQ documents/letters stage.',
                'icon' => 'bi-envelope-open',
            ],
            'QUOTE_REVIEW_PENDING' => [
                'label' => 'Quote Review',
                'description' => 'Quotes available for evaluation.',
                'icon' => 'bi-chat-dots',
            ],
            'QUOTE_REQUESTOR_REVIEW_PENDING' => [
                'label' => 'Requestor Review',
                'description' => 'Requestor reviewing submitted quotations.',
                'icon' => 'bi-person-check',
            ],
            'QUOTE_REQUESTOR_REVIEW_APPROVED' => [
                'label' => 'Requestor Approved',
                'description' => 'Requestor confirmed selected quote meets requirements.',
                'icon' => 'bi-person-check-fill',
            ],
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => [
                'label' => 'Branch Head Approval',
                'description' => 'Pending Branch Head final approval.',
                'icon' => 'bi-shield-check',
            ],
            'QUOTE_APPROVED' => [
                'label' => 'Quote Selected',
                'description' => 'Supplier quote selected.',
                'icon' => 'bi-check-circle',
            ],
            'FUNDS_VERIFIED' => [
                'label' => 'Funds Verified',
                'description' => 'Budget/funding verification stage.',
                'icon' => 'bi-cash-coin',
            ],
            'COMMITMENTS_PENDING' => [
                'label' => 'Commitment Form',
                'description' => 'Commitment form preparation.',
                'icon' => 'bi-pencil-square',
            ],
            'COMMITMENT_APPROVED' => [
                'label' => 'Commitment Created',
                'description' => 'Commitment record generated.',
                'icon' => 'bi-file-earmark-check',
            ],
            'PO_PENDING' => [
                'label' => 'PO Created',
                'description' => 'Purchase order generated.',
                'icon' => 'bi-file-earmark-text',
            ],
            'INVOICE_RECEIVED' => [
                'label' => 'Invoice',
                'description' => 'Invoice processing stage.',
                'icon' => 'bi-receipt',
            ],
        ];
    }
    
    // FUNDS_VERIFIED can appear in two contexts:
    // 1) As initial approval stage (before RFQ) — next step is RFQ_LETTER_AVAILABLE
    // 2) As post-quote stage (after QUOTE_APPROVED) — next step is COMMITMENTS_PENDING
    // Since QUOTE_APPROVED always leads to FUNDS_VERIFIED in the commitment flow,
    // we use the post-quote context (COMMITMENTS_PENDING) as the default here.
    $nextStepMap = [
        'DRAFT' => 'SUBMITTED',
        'SUBMITTED' => 'HOD_APPROVED',
        'HOD_APPROVED' => 'RFQ_LETTER_AVAILABLE',
        'DIRECTOR_APPROVED' => 'RFQ_LETTER_AVAILABLE',
        'GC_APPROVED' => 'RFQ_LETTER_AVAILABLE',
        'RFQ_LETTER_AVAILABLE' => 'QUOTE_REVIEW_PENDING',
        'QUOTE_REVIEW_PENDING' => 'QUOTE_REQUESTOR_REVIEW_PENDING',
        'QUOTE_REQUESTOR_REVIEW_PENDING' => 'QUOTE_REQUESTOR_REVIEW_APPROVED',
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => 'QUOTE_APPROVED',
        'QUOTE_APPROVED' => 'FUNDS_VERIFIED',
        'FUNDS_VERIFIED' => 'COMMITMENTS_PENDING',
        'COMMITMENTS_PENDING' => 'COMMITMENT_APPROVED',
        'COMMITMENT_APPROVED' => 'PO_PENDING',
        'PO_PENDING' => 'INVOICE_RECEIVED',
        'INVOICE_RECEIVED' => 'COMPLETED',
        'PROCUREMENT_STAGE' => 'EVALUATION_STAGE',
        'EVALUATION_STAGE' => 'QUOTE_REVIEW_PENDING',
        'COMMITTEE_RECOMMENDED' => 'QUOTE_REVIEW_PENDING',
        'AWARDED' => 'COMMITMENTS_PENDING',
    ];
    
    $next = $nextStepMap[strtoupper($status)] ?? 'COMPLETED';
    $descMap = [
        'SUBMITTED' => 'Submit for approval',
        'HOD_APPROVED' => 'Get HOD approval',
        'RFQ_LETTER_AVAILABLE' => 'Generate RFQ letters and send to vendors',
        'QUOTE_REVIEW_PENDING' => 'Review vendor quotes and select the preferred quotation',
        'QUOTE_REQUESTOR_REVIEW_PENDING' => 'Requestor confirms the selected quotation meets specifications',
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => 'Route the confirmed quotation to the Branch Head',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => 'Branch Head records the final approval decision',
        'QUOTE_APPROVED' => 'Selected quotation fully approved; proceed to funds verification',
        'FUNDS_VERIFIED' => 'Finance verifies funds are available',
        'COMMITMENTS_PENDING' => 'Procurement fills commitment form, Finance creates commitment',
        'COMMITMENT_APPROVED' => 'Finance created commitment, ready for PO',
        'PO_PENDING' => 'Generate PO from GFMS',
        'INVOICE_RECEIVED' => 'Upload vendor invoice',
        'COMPLETED' => 'Process complete',
    ];
    
    return ['status' => $next, 'description' => $descMap[$next] ?? 'Next step'];
}

/**
 * Check if quote review and approval can proceed
 * Ensures all vendors have submitted quotes before review begins
 * 
 * @param PDO $pdo Database connection
 * @param int $rfqId RFQ ID
 * @return array Array with 'can_review', 'pending_vendors', 'submitted_vendors'
 */
function canProceedToQuoteReview(PDO $pdo, int $rfqId): array {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_vendors,
            SUM(CASE WHEN response_status IN ('SUBMITTED', 'SELECTED') THEN 1 ELSE 0 END) as submitted_count
        FROM rfq_vendors
        WHERE rfq_id = ?
    ");
    $stmt->execute([$rfqId]);
    $vendors = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalVendors = (int)$vendors['total_vendors'];
    $submittedCount = (int)$vendors['submitted_count'];
    
    return [
        'can_review' => $submittedCount > 0,
        'total_vendors' => $totalVendors,
        'submitted_vendors' => $submittedCount,
        'pending_vendors' => $totalVendors - $submittedCount,
        'message' => $submittedCount . ' of ' . $totalVendors . ' vendors submitted quotes'
    ];
}

/**
 * Get quote review comments for a quote
 * 
 * @param PDO $pdo Database connection
 * @param int $quoteId Quote ID
 * @return string Review comments or empty string
 */
function getQuoteReviewComments(PDO $pdo, int $quoteId): string {
    $stmt = $pdo->prepare("SELECT review_comments FROM rfq_quotes WHERE quote_id = ?");
    $stmt->execute([$quoteId]);
    return $stmt->fetchColumn() ?? '';
}

/**
 * Get human-readable status label and description
 * 
 * @param string $status Status code
 * @return array ['label' => string, 'description' => string, 'color' => string]
 */
function getStatusLabel(string $status): array {
    $labels = [
        'DRAFT' => ['label' => 'Draft', 'description' => 'Request has been created but not yet submitted', 'color' => 'secondary'],
        'SUBMITTED' => ['label' => 'Submitted', 'description' => 'Request submitted for approval', 'color' => 'info'],
        'HOD_APPROVED' => ['label' => 'HOD Approved', 'description' => 'Head of Department has approved', 'color' => 'success'],
        'DIRECTOR_APPROVED' => ['label' => 'Director Approved', 'description' => 'Director has approved', 'color' => 'success'],
        'FUNDS_VERIFIED' => ['label' => 'Funds Verified', 'description' => 'Finance has verified available funds', 'color' => 'success'],
        'GC_APPROVED' => ['label' => 'Government Chemist Approved', 'description' => 'Deputy Government Chemist has approved', 'color' => 'success'],
        'RFQ_LETTER_AVAILABLE' => ['label' => 'RFQ Letter Available', 'description' => 'RFQ letter can be generated for vendors', 'color' => 'info'],
        'QUOTE_REVIEW_PENDING' => ['label' => 'Quote Review Pending', 'description' => 'Waiting for quote review and preferred-quote selection', 'color' => 'warning'],
        'QUOTE_REQUESTOR_REVIEW_PENDING' => ['label' => 'Pending Requestor Review', 'description' => 'Original requestor must confirm the selected quotation meets specifications', 'color' => 'warning'],
        'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['label' => 'Requestor Review Approved', 'description' => 'Selected quotation confirmed by the original requestor', 'color' => 'success'],
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['label' => 'Pending Branch Head Approval', 'description' => 'Awaiting auto-routed Branch Head final approval', 'color' => 'info'],
        'QUOTE_APPROVED' => ['label' => 'Quote Approved', 'description' => 'Selected quotation fully approved and ready for commitment / funds verification', 'color' => 'info'],
        'COMMITMENTS_PENDING' => ['label' => 'Commitment Pending', 'description' => 'Procurement submitted commitment form. Awaiting Finance to upload commitment document.', 'color' => 'warning'],
        'COMMITMENT_APPROVED' => ['label' => 'Commitment Approved', 'description' => 'Finance has verified funds and created commitment. Ready for PO creation.', 'color' => 'success'],
        'COMMITMENT_DECLINED' => ['label' => 'Commitment Declined', 'description' => 'Finance declined commitment due to insufficient funds or issues. Request returned to Requestor.', 'color' => 'danger'],
        'PO_PENDING' => ['label' => 'PO Created', 'description' => 'Purchase Order created, ready for invoice upload', 'color' => 'success'],
        'INVOICE_RECEIVED' => ['label' => 'Invoice Received', 'description' => 'Vendor invoice has been uploaded', 'color' => 'info'],
        'PROCUREMENT_STAGE' => ['label' => 'Procurement Stage', 'description' => 'Request in procurement workflow', 'color' => 'info'],
        'EVALUATION_STAGE' => ['label' => 'Evaluation Stage', 'description' => 'Bids/quotes under evaluation', 'color' => 'warning'],
        'COMMITTEE_RECOMMENDED' => ['label' => 'Committee Recommended', 'description' => 'Evaluation committee has made recommendation', 'color' => 'success'],
        'AWARDED' => ['label' => 'Awarded', 'description' => 'Vendor selected — commitment and payment activities are required before this request can be closed.', 'color' => 'success'],
        'COMPLETED' => ['label' => 'Completed', 'description' => 'Procurement process completed', 'color' => 'dark'],
        'DECLINED' => ['label' => 'Declined', 'description' => 'Request has been declined', 'color' => 'danger'],
        'CANCELLED' => ['label' => 'Cancelled', 'description' => 'Request has been cancelled', 'color' => 'danger'],
    ];
    
    return $labels[$status] ?? [
        'label' => str_replace('_', ' ', $status),
        'description' => "Status: $status",
        'color' => 'secondary'
    ];
}

/**
 * Update quote review status
 * Called when requestor/branch head reviews a quote
 * 
 * @param PDO $pdo Database connection
 * @param int $quoteId Quote ID
 * @param string $status 'MEETS_REQUIREMENTS' or 'DOES_NOT_MEET'
 * @param string $comments Review comments
 * @param int $userId User ID (reviewer)
 * @return bool Success
 */
function updateQuoteReviewStatus(PDO $pdo, int $quoteId, string $status, string $comments, int $userId): bool {
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET review_status = ?, review_comments = ?
        WHERE quote_id = ?
    ");
    
    $result = $stmt->execute([$status, $comments, $quoteId]);
    
    if ($result) {
        // Log this review action
        $logStmt = $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, change_date, notes)
            VALUES ('rfq_quotes', ?, 'QUOTE_REVIEW', ?, NOW(), ?)
        ");
        $logStmt->execute([$quoteId, $_SESSION['full_name'] ?? 'System', "Quote review: {$status} - {$comments}"]);
    }
    
    return $result;
}

/**
 * Self-healing: ensure all SUBMITTED requests have approval chain rows
 * in request_approvals. If a request reached SUBMITTED status without
 * corresponding request_approvals rows (e.g. data migration, partial
 * failure), this function auto-seeds the missing approval chain so the
 * request appears on the correct dashboard and can be processed.
 *
 * Safe to call multiple times — only creates rows for requests that
 * truly have no REQUEST-type approval chain rows.
 */
function ensureApprovalChainsExist(PDO $pdo): void {
    // Find SUBMITTED requests with no approval chain
    $orphaned = $pdo->query("
        SELECT pr.request_id, pr.request_type, pr.estimated_value, pr.branch_id
        FROM procurement_requests pr
        WHERE UPPER(pr.status) = 'SUBMITTED'
          AND pr.request_id NOT IN (
              SELECT DISTINCT request_id
              FROM request_approvals
              WHERE entity_type = 'REQUEST'
                AND request_id IS NOT NULL
          )
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orphaned as $req) {
        $requestType  = $req['request_type'] ?? 'REGULAR';
        $estimatedVal = (float)($req['estimated_value'] ?? 0);
        $branchId     = $req['branch_id'] ? (int)$req['branch_id'] : null;

        // Determine approval chain based on request type
        if ($requestType === 'PETTY_CASH') {
            $roles = ['HOD', 'Procurement Officer', 'Finance Officer'];
        } elseif ($requestType === 'REIMBURSEMENT') {
            $roles = [];
            if ($estimatedVal >= 100000) {
                $roles[] = 'HOD';
            }
            $roles[] = 'Finance Officer';
        } else {
            // REGULAR — use the standard approval chain
            $roles = getApprovalChain($requestType, $estimatedVal, $branchId, $pdo);
        }

        $stageOrder = 1;
        foreach ($roles as $role) {
            $pdo->prepare("
                INSERT INTO request_approvals
                (entity_type, entity_id, request_id, role, stage_order, status)
                VALUES ('REQUEST', ?, ?, ?, ?, 'pending')
            ")->execute([$req['request_id'], $req['request_id'], $role, $stageOrder]);
            $stageOrder++;
        }

        logAudit($pdo, 'procurement_requests', $req['request_id'],
            'APPROVAL_CHAIN_CREATED',
            'Auto-seeded missing approval chain: ' . implode(' → ', $roles));
    }
}

/**
 * ========================================
 * SERVICE CONTRACT WORKFLOW FUNCTIONS
 * ========================================
 * Simplified workflow for contractor/service payments:
 * DRAFT → SUBMITTED → HOD_APPROVED → FUNDS_VERIFIED → COMMITMENT_APPROVED → INVOICE_RECEIVED → COMPLETED
 *
 * No RFQ, no PO required. Invoice links directly to commitment.
 */

/**
 * Get service contract approval chain
 * Same as regular: Branch Head / HOD approves, then Finance verifies funds
 */
function getServiceContractApprovalChain(float $estimatedValue, ?int $branchId = null, ?PDO $pdo = null): array {
    // Same branch-based approval as REGULAR requests
    if ($branchId === 6) {
        return ['Deputy Government Chemist'];
    } elseif ($branchId === 5) {
        return ['Director HRM&A'];
    }
    return ['HOD'];
}

/**
 * Get allowed status transitions for service contract requests
 * Simplified: no RFQ, no PO stages
 */
function getServiceContractTransitions(): array {
    return [
        'DRAFT'                => ['SUBMITTED'],
        'SUBMITTED'            => ['HOD_APPROVED', 'DECLINED'],
        'HOD_APPROVED'         => ['FUNDS_VERIFIED', 'DECLINED'],
        'FUNDS_VERIFIED'       => ['COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'],
        'COMMITMENT_APPROVED'  => ['INVOICE_RECEIVED'],
        'INVOICE_RECEIVED'     => ['COMPLETED'],
        'COMPLETED'            => [],
        'DECLINED'             => [],
        'COMMITMENT_DECLINED'  => ['SUBMITTED'], // Can resubmit
    ];
}

/**
 * Check if service contract request can transition to next status
 */
function canServiceContractTransition(string $current, string $next): bool {
    if ($next === 'CANCELLED') {
        return !in_array($current, ['COMPLETED', 'DECLINED', 'CANCELLED']);
    }
    $map = getServiceContractTransitions();
    return in_array(strtoupper($next), $map[strtoupper($current)] ?? []);
}

/**
 * Get service contract status display label with icon
 */
function getServiceContractStatusLabel(string $status): string {
    return match(strtoupper($status)) {
        'DRAFT' => '📝 Draft',
        'SUBMITTED' => '📤 Pending Approval',
        'HOD_APPROVED' => '✅ Branch Approved',
        'FUNDS_VERIFIED' => '💰 Funds Verified',
        'COMMITMENT_APPROVED' => '📋 Commitment Created',
        'COMMITMENT_DECLINED' => '❌ Commitment Declined',
        'INVOICE_RECEIVED' => '🧾 Invoice Received',
        'COMPLETED' => '✓ Completed (Paid)',
        'DECLINED' => '❌ Declined',
        'CANCELLED' => '🚫 Cancelled',
        default => htmlspecialchars($status)
    };
}

/**
 * Get the service contract workflow pipeline steps for display
 */
function getServiceContractPipeline(): array {
    return [
        ['status' => 'DRAFT', 'label' => 'Draft', 'icon' => '📝'],
        ['status' => 'SUBMITTED', 'label' => 'Submitted', 'icon' => '📤'],
        ['status' => 'HOD_APPROVED', 'label' => 'Branch Approved', 'icon' => '✅'],
        ['status' => 'FUNDS_VERIFIED', 'label' => 'Funds Verified', 'icon' => '💰'],
        ['status' => 'COMMITMENT_APPROVED', 'label' => 'Committed', 'icon' => '📋'],
        ['status' => 'INVOICE_RECEIVED', 'label' => 'Invoiced', 'icon' => '🧾'],
        ['status' => 'COMPLETED', 'label' => 'Paid', 'icon' => '✓'],
    ];
}

/**
 * Get petty cash workflow pipeline stages for UI display.
 * Returns stages in the correct order aligned with getPettyCashTransitions().
 * This is the single source of truth for petty cash stage configuration.
 *
 * @return array Array of pipeline stages with status, label, and icon
 */
function getPettyCashPipeline(): array {
    return [
        ['status' => 'DRAFT', 'label' => 'Draft', 'icon' => 'bi-pencil-square'],
        ['status' => 'SUBMITTED', 'label' => 'Submitted', 'icon' => 'bi-send'],
        ['status' => 'FUNDS_VERIFIED', 'label' => 'Funds Verified', 'icon' => 'bi-cash-coin'],
        ['status' => 'FINANCE_AUTHORIZED', 'label' => 'Finance Authorized', 'icon' => 'bi-shield-check'],
        ['status' => 'DISBURSED', 'label' => 'Disbursed', 'icon' => 'bi-wallet2'],
        ['status' => 'PENDING_RECONCILIATION', 'label' => 'Awaiting Purchase Documentation', 'icon' => 'bi-hourglass-split'],
        ['status' => 'PROCUREMENT_VERIFIED', 'label' => 'Documents Verified', 'icon' => 'bi-check-circle'],
        ['status' => 'COMPLETED', 'label' => 'Complete', 'icon' => 'bi-check-circle-fill'],
    ];
}

/**
 * Get reimbursement workflow pipeline stages for UI display.
 * Returns stages in the correct order aligned with getReimbursementTransitions().
 * This is the single source of truth for reimbursement stage configuration.
 *
 * @return array Array of pipeline stages with status, label, and icon
 */
function getReimbursementPipeline(): array {
    return [
        ['status' => 'DRAFT', 'label' => 'Draft', 'icon' => 'bi-pencil-square'],
        ['status' => 'SUBMITTED', 'label' => 'Submitted', 'icon' => 'bi-send'],
        ['status' => 'FUNDS_VERIFIED', 'label' => 'Funds Verified', 'icon' => 'bi-cash-coin'],
        ['status' => 'INVOICE_SUBMITTED', 'label' => 'Invoices Submitted', 'icon' => 'bi-file-earmark-text'],
        ['status' => 'INVOICE_VERIFIED', 'label' => 'Invoices Verified', 'icon' => 'bi-check-circle'],
        ['status' => 'APPROVED', 'label' => 'Approved', 'icon' => 'bi-briefcase-fill'],
        ['status' => 'REIMBURSED', 'label' => 'Reimbursed', 'icon' => 'bi-cash-coin'],
        ['status' => 'COMPLETED', 'label' => 'Complete', 'icon' => 'bi-check-circle-fill'],
    ];
}


/**
 * Resolve the workflow path for a request row.
 *
 * @param array $request Row from procurement_requests (must include workflow_path)
 * @return string 'STANDARD' or 'NON_PO_SKIP_RFQ'
 */
function getWorkflowPath(array $request): string {
    return ($request['workflow_path'] ?? 'STANDARD') === 'NON_PO_SKIP_RFQ'
        ? 'NON_PO_SKIP_RFQ'
        : 'STANDARD';
}

/**
 * Return a color-coded HTML badge describing the workflow path for a request.
 *
 * @param array $request Row from procurement_requests (must include workflow_path)
 * @return string Safe HTML badge string
 */
function getWorkflowBadgeHtml(array $request): string {
    if (getWorkflowPath($request) === 'NON_PO_SKIP_RFQ') {
        return '<span style="display:inline-flex;align-items:center;gap:0.3rem;background:#fff3cd;color:#856404;'
             . 'border:1px solid #ffc107;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.78rem;font-weight:600;">'
             . '⚡ Non-PO / Skip RFQ Path</span>';
    }
    return '<span style="display:inline-flex;align-items:center;gap:0.3rem;background:#cfe2ff;color:#084298;'
         . 'border:1px solid #b6d4fe;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.78rem;font-weight:600;">'
         . '📋 Standard Procurement Path</span>';
}

/**
 * Determine if commitment stages (COMMITMENTS_PENDING, COMMITMENT_APPROVED) should be shown
 * for a request in a Non-PO Skip-RFQ workflow path.
 *
 * Commitments and PO stages are ONLY required when po_required = 'YES'.
 * When po_required = 'NO', the workflow skips directly from AWARDED to INVOICE.
 *
 * @param array|null $originalCommitment The original commitment row, or null if none exists
 * @return bool True if commitment/PO stages should be included, false otherwise
 */
function shouldIncludeCommitmentStages(?array $originalCommitment): bool {
    if ($originalCommitment === null) {
        // No commitment exists yet, but may be created later; assume YES for now
        // (will be reassessed once commitment is created)
        return true;
    }
    // Remediated (soft-deleted/voided) commitments are treated as non-existent
    if (($originalCommitment['is_remediated'] ?? 0) === 1) {
        return true;  // Treat as if no commitment exists; assume YES for new commitment
    }
    // Include commitment stages only if po_required = 'YES'
    return ($originalCommitment['po_required'] ?? 'YES') === 'YES';
}

/**
 * Apply the Non-PO / Skip-RFQ workflow after a commitment is created with po_required = 'NO'.
 *
 * Business rules:
 *   1. Sets workflow_path = 'NON_PO_SKIP_RFQ' on the request.
 *   2. Keeps status = 'COMMITMENT_APPROVED' (maintain stage consistency with standard path).
 *   3. The workflow_path flag identifies this as a non-PO workflow; PO creation should be blocked.
 *   4. Ensures requires_rfq = 0 so no RFQ stages are triggered.
 *   5. Writes full audit entries for compliance tracing.
 *
 * IMPORTANT: Do NOT revert status to AWARDED. The stage transition must remain consistent:
 *   - Standard path: AWARDED → COMMITMENTS_PENDING → COMMITMENT_APPROVED → PO_PENDING → INVOICE_RECEIVED → COMPLETED
 *   - Non-PO path:   AWARDED → COMMITMENTS_PENDING → COMMITMENT_APPROVED →               INVOICE_RECEIVED → COMPLETED
 *
 * The workflow_path flag is used to determine whether PO creation is allowed, not the status.
 *
 * Must be called inside an active transaction; the caller is responsible for
 * commit/rollback.
 *
 * @param PDO $pdo       Active database connection (transaction already open)
 * @param int $requestId The procurement_requests.request_id to update
 * @return void
 */
function applyNonPoWorkflow(PDO $pdo, int $requestId): void {
    // Set workflow_path to identify non-PO workflow, but keep status at COMMITMENT_APPROVED
    // This ensures consistency with the standard workflow stage pipeline
    $pdo->prepare("
        UPDATE procurement_requests
        SET workflow_path = 'NON_PO_SKIP_RFQ',
            requires_rfq  = 0,
            updated_at    = NOW()
        WHERE request_id = ?
    ")->execute([$requestId]);

    logAudit(
        $pdo,
        'procurement_requests',
        $requestId,
        'NON_PO_WORKFLOW_APPLIED',
        'PO not required — request routed to Non-PO Skip-RFQ workflow. RFQ bypassed, PO creation blocked. Ready for invoice submission.'
    );
    logRequestTimeline(
        $pdo,
        $requestId,
        'NON_PO_WORKFLOW_APPLIED',
        'Finance Officer selected "No PO Required". RFQ and PO creation stages bypassed. Request will proceed directly from commitment approval to invoice submission.'
    );
}

// =============================================================================
// Draft-visibility helpers
// =============================================================================

/**
 * Roles that may see ALL draft requests regardless of who created them.
 * Monitoring roles (Director HRM&A) are included here so they can view
 * organisation-wide drafts without being able to act on them.
 */
function draftViewerRoles(): array {
    return ['HOD', 'Branch Head', 'Director HRM&A', 'Admin', 'SuperAdmin'];
}

/**
 * Return true if the currently authenticated user is allowed to view a
 * specific request that is still in DRAFT status.
 *
 * Access rules:
 *  1. The officer who created the draft (own draft).
 *  2. Designated oversight roles: HOD, Branch Head, Director HRM&A,
 *     Admin, SuperAdmin.
 * All other users (Procurement Officers, Finance Officers, etc.) are denied.
 *
 * @param array $request  Row from procurement_requests (must include 'status' and 'created_by').
 * @return bool
 */
function canViewDraft(array $request): bool {
    if (strtoupper($request['status'] ?? '') !== 'DRAFT') {
        // Not a draft — visibility is governed by other rules.
        return true;
    }

    $userRole = $_SESSION['role_name'] ?? '';
    $userId   = (int)($_SESSION['user_id'] ?? 0);

    // Rule 1 – own draft
    if ($userId > 0 && (int)$request['created_by'] === $userId) {
        return true;
    }

    // Rule 2 – oversight / monitoring roles
    if (in_array($userRole, draftViewerRoles(), true)) {
        return true;
    }

    return false;
}

// =============================================================================
// Monitoring-role helper
// =============================================================================

/**
 * Return true when the given role is a read-only monitoring role.
 *
 * Monitoring roles can VIEW all requests (including those from other units)
 * but must NOT be granted edit, approve, cancel, or action capabilities
 * solely because of this status.
 */
function isMonitoringRole(string $role): bool {
    return in_array($role, ['Director HRM&A'], true);
}

// =============================================================================
// Finalized-request helper (used to gate document deletion)
// =============================================================================

/**
 * Return true when a request has reached a finalized/completed status.
 *
 * Documents already attached to a finalized request may only be deleted by
 * a user holding the elevated 'procurement_delete_finalized_document'
 * permission (see procurement/delete_document.php).
 */
function isFinalizedRequestStatus(string $status): bool {
    return in_array(strtoupper($status), ['COMPLETED'], true);
}

// =============================================================================
// HOD/Branch Head Approval Helpers
// =============================================================================

/**
 * Determine if the current user has a HOD or Branch Head role.
 * Returns the role name if the user has such a role, false otherwise.
 *
 * @return string|false The role name ('HOD' or 'Branch Head'), or false if not applicable.
 */
function getCurrentApproverRole() {
    $userRole = $_SESSION['role_name'] ?? '';
    if (in_array($userRole, ['HOD', 'Branch Head'], true)) {
        return $userRole;
    }
    return false;
}

/**
 * Get the branches/departments that a HOD or Branch Head can approve requests from.
 * Both HOD and Branch Head approval scope is determined by the user's assigned branch.
 * Note: If the system needs to differentiate HOD (by department) and Branch Head (by location),
 *       separate lookup tables should be implemented (e.g., hod_assignments, branch_head_assignments).
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID of the HOD/Branch Head
 * @param string $approverRole 'HOD' or 'Branch Head'
 * @return array List of branch_ids this approver can approve for
 */
function getApproverScope(PDO $pdo, int $userId, string $approverRole): array {
    // Both HOD and Branch Head are scoped by their assigned branch_id in the users table
    $stmt = $pdo->prepare("
        SELECT DISTINCT branch_id 
        FROM users 
        WHERE user_id = ? AND branch_id IS NOT NULL
    ");
    $stmt->execute([$userId]);
    $branchId = $stmt->fetchColumn();
    return $branchId ? [(int)$branchId] : [];
}

/**
 * Fetch pending petty cash requests awaiting HOD/Branch Head approval.
 * Filters by the approver's scope (department/branch).
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID of the approver
 * @param string $approverRole 'HOD' or 'Branch Head'
 * @return array Array of pending petty cash requests
 */
function getPendingPettyCashApprovals(PDO $pdo, int $userId, string $approverRole): array {
    $branches = getApproverScope($pdo, $userId, $approverRole);
    if (empty($branches)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($branches), '?'));
    
    $stmt = $pdo->prepare("
        SELECT 
            pr.request_id,
            pr.request_number,
            pr.description,
            pr.estimated_value,
            pr.currency,
            pr.status,
            pr.created_at,
            pr.created_by,
            pr.branch_id,
            b.branch_name,
            u.full_name as requester_name,
            DATEDIFF(NOW(), pr.created_at) as days_pending,
            ra.role as approval_role,
            ra.status as approval_status
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.role = ?
        WHERE pr.request_type = 'PETTY_CASH'
            AND pr.status = 'SUBMITTED'
            AND pr.branch_id IN ($placeholders)
            AND (ra.status = 'pending' OR ra.status IS NULL)
        ORDER BY pr.created_at ASC
    ");
    
    $params = array_merge([$approverRole], $branches);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch pending reimbursement requests awaiting HOD/Branch Head approval.
 * Filters by the approver's scope (department/branch).
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID of the approver
 * @param string $approverRole 'HOD' or 'Branch Head'
 * @return array Array of pending reimbursement requests
 */
function getPendingReimbursementApprovals(PDO $pdo, int $userId, string $approverRole): array {
    $branches = getApproverScope($pdo, $userId, $approverRole);
    if (empty($branches)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($branches), '?'));
    
    $stmt = $pdo->prepare("
        SELECT 
            pr.request_id,
            pr.request_number,
            pr.description,
            pr.estimated_value,
            pr.currency,
            pr.status,
            pr.created_at,
            pr.created_by,
            pr.branch_id,
            b.branch_name,
            u.full_name as requester_name,
            DATEDIFF(NOW(), pr.created_at) as days_pending,
            ra.role as approval_role,
            ra.status as approval_status,
            (SELECT COUNT(*) FROM reimbursement_invoices WHERE request_id = pr.request_id) as invoice_count
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        LEFT JOIN request_approvals ra ON pr.request_id = ra.request_id AND ra.role = ?
        WHERE pr.request_type = 'REIMBURSEMENT'
            AND pr.status = 'SUBMITTED'
            AND pr.branch_id IN ($placeholders)
            AND (ra.status = 'pending' OR ra.status IS NULL)
        ORDER BY pr.created_at ASC
    ");
    
    $params = array_merge([$approverRole], $branches);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validate if a HOD/Branch Head is authorized to approve a petty cash/reimbursement request.
 * Checks:
 *  1. User has HOD or Branch Head role
 *  2. Request belongs to the approver's department/branch
 *  3. Request is in SUBMITTED status
 *  4. Request is awaiting the approver's decision
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID of the approver
 * @param string $approverRole 'HOD' or 'Branch Head'
 * @param int $requestId Request ID to authorize
 * @return bool True if authorized, false otherwise
 */
function isAuthorizedToApprovePettyCashReimbursement(PDO $pdo, int $userId, string $approverRole, int $requestId): bool {
    // Verify user has the correct role
    if (!in_array($approverRole, ['HOD', 'Branch Head'], true)) {
        return false;
    }

    // Get the request
    $stmt = $pdo->prepare("
        SELECT pr.request_id, pr.branch_id, pr.status, pr.request_type, pr.created_by
        FROM procurement_requests pr
        WHERE pr.request_id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        return false;
    }

    // Ensure it's a petty cash or reimbursement request
    if (!in_array($request['request_type'], ['PETTY_CASH', 'REIMBURSEMENT'], true)) {
        return false;
    }

    // Ensure request is in SUBMITTED status
    if (strtoupper($request['status']) !== 'SUBMITTED') {
        return false;
    }

    // Check if the request belongs to the approver's scope
    $approverBranches = getApproverScope($pdo, $userId, $approverRole);
    if (!in_array($request['branch_id'], $approverBranches)) {
        return false;
    }

    // Check if there's a pending approval record for this role
    $stmt = $pdo->prepare("
        SELECT id, status 
        FROM request_approvals 
        WHERE request_id = ? AND role = ? AND status = 'pending'
    ");
    $stmt->execute([$requestId, $approverRole]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no pending approval record found, not authorized
    if (!$approval) {
        return false;
    }

    return true;
}

/**
 * Log an approval decision (approve/reject/return) for petty cash or reimbursement request.
 * Records: approver_id, approver_role, branch_id, timestamp, action, comments, previous_status, new_status.
 *
 * @param PDO $pdo Database connection
 * @param int $requestId Request ID
 * @param int $approverId User ID of the approver
 * @param string $approverRole 'HOD', 'Branch Head', or 'Finance Officer'
 * @param string $action 'approve', 'decline', or 'return'
 * @param string $newStatus New status of the request (e.g., 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DECLINED', 'RETURNED_FOR_CORRECTION')
 * @param string $previousStatus Previous status of the request
 * @param ?string $comment Comment/reason for the decision
 * @return void
 */
function logApprovalDecision(
    PDO $pdo,
    int $requestId,
    int $approverId,
    string $approverRole,
    string $action,
    string $newStatus,
    string $previousStatus,
    ?string $comment = null
): void {
    // Get branch info
    $branchStmt = $pdo->prepare("
        SELECT branch_id FROM procurement_requests WHERE request_id = ?
    ");
    $branchStmt->execute([$requestId]);
    $branchId = $branchStmt->fetchColumn();

    $notes = sprintf(
        "Action: %s | Role: %s | Branch: %s | Comment: %s | Status: %s → %s",
        strtoupper($action),
        $approverRole,
        $branchId ?? 'N/A',
        $comment ?? '(none)',
        $previousStatus,
        $newStatus
    );

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (table_name, record_id, action, changed_by, notes)
        VALUES ('petty_cash_reimbursement_approval', ?, ?, ?, ?)
    ");
    $stmt->execute([$requestId, strtoupper($action), $approverId, $notes]);
}

/**
 * Determine whether a Purchase Order is required based on 
 * work_performed and goods_delivered flags.
 * 
 * Business Rule:
 * - If BOTH work has been performed AND goods have been delivered → NO PO required
 * - If either is false → PO IS required
 * - NULL/missing values default to requiring PO (conservative)
 * 
 * @param array $request Row from procurement_requests table
 * @return bool True if PO is required, false if not required
 */
function shouldRequirePoAtCreation(array $request): bool
{
    $workPerformed = $request['work_performed'] ?? 0;
    $goodsDelivered = $request['goods_delivered'] ?? 0;
    
    // Convert to boolean for safety
    $workPerformed = (bool)(int)$workPerformed;
    $goodsDelivered = (bool)(int)$goodsDelivered;
    
    // Both must be true to NOT require PO
    // If either is false, PO IS required
    return !($workPerformed && $goodsDelivered);
}

/**
 * Get the derived PO requirement as 'YES' or 'NO' string
 * for use in database and UI
 * 
 * @param array $request Row from procurement_requests table
 * @return string 'YES' if PO required, 'NO' if not required
 */
function getDerivedPoRequired(array $request): string
{
    return shouldRequirePoAtCreation($request) ? 'YES' : 'NO';
}

/**
 * Create or recreate approval task chain for a request.
 * 
 * This is the single centralized entry point for creating approval tasks.
 * Used during:
 *   - Initial request submission
 *   - Workflow revert/correction (when reverting to SUBMITTED)
 *   - Request resubmission
 * 
 * SAFETY: First deletes any orphaned pending approvals, then recreates fresh chain.
 *
 * @param PDO $pdo Database connection
 * @param int $requestId Request ID
 * @param string $requestType REGULAR | REIMBURSEMENT | PETTY_CASH | SERVICE_CONTRACT
 * @param float $estimatedValue Monetary value (for threshold-based routing)
 * @param int|null $branchId Branch ID (affects approver selection)
 * @return array Array of role names in approval chain
 * @throws Exception if insertion fails
 */
function createApprovalChain(
    PDO $pdo,
    int $requestId,
    string $requestType,
    float $estimatedValue,
    ?int $branchId = null
): array {
    
    if ($requestId <= 0) {
        throw new Exception('Invalid request ID');
    }
    
    // Step 1: Delete any stale pending approvals (cleanup from previous attempts)
    $pdo->prepare("
        DELETE FROM request_approvals
        WHERE request_id = ?
          AND status = 'pending'
    ")->execute([$requestId]);
    
    // Step 2: Get the approval chain for this request type/amount/branch
    $approvalRoles = getApprovalChain($requestType, $estimatedValue, $branchId, $pdo);
    
    // Step 3: Insert approval tasks in order
    $stageOrder = 1;
    foreach ($approvalRoles as $role) {
        $pdo->prepare("
            INSERT INTO request_approvals
            (entity_type, entity_id, request_id, role, stage_order, status)
            VALUES ('REQUEST', ?, ?, ?, ?, 'pending')
        ")->execute([$requestId, $requestId, $role, $stageOrder]);
        $stageOrder++;
    }
    
    return $approvalRoles;
}

/**
 * Determine the appropriate first approval stage name based on approval chain.
 * Used by workflow to set request status after approval chain creation.
 *
 * @param array $approvalRoles Array of role names from getApprovalChain()
 * @return string Status name (HOD_APPROVED, FUNDS_VERIFIED, DIRECTOR_APPROVED, GC_APPROVED, or default HOD_APPROVED)
 */
function getFirstApprovalStage(array $approvalRoles): string
{
    if (empty($approvalRoles)) {
        return 'HOD_APPROVED';
    }
    
    $firstRole = $approvalRoles[0];
    return match($firstRole) {
        'HOD' => 'HOD_APPROVED',
        'Finance Officer' => 'FUNDS_VERIFIED',
        'Director HRM&A' => 'DIRECTOR_APPROVED',
        'Deputy Government Chemist' => 'GC_APPROVED',
        'Procurement Committee' => 'PROCUREMENT_STAGE',
        'Procurement Officer' => 'PROCUREMENT_ENDORSED',
        default => 'HOD_APPROVED'
    };
}

?>
