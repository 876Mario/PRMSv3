<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";


if (!isset($_GET['id'])) {
    pop('Missing ID', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id <= 0) {
    pop(
        'Invalid Request',
        '/procurement/list.php',
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}


$id = (int) $_GET['id'];

// Fetch procurement request

// Restrict Requestor to only their own requests
if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Requestor') {
        $stmt = $pdo->prepare("
            SELECT 
                pr.*,
                b.branch_name,
                u.full_name AS approved_by_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.approved_by = u.user_id
            WHERE pr.request_id = ? AND pr.created_by = ?
        ");
        $stmt->execute([$id, $_SESSION['user_id']]);
} else {
        $stmt = $pdo->prepare("
            SELECT 
                pr.*,
                b.branch_name,
                u.full_name AS approved_by_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.approved_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$id]);
}
$request = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$request) {
    pop('Record not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* ================================
   Draft visibility enforcement
   (server-side guard — do NOT rely on UI alone)
================================ */
if (!canViewDraft($request)) {
    logAudit(
        $pdo,
        'procurement_requests',
        $id,
        'ACCESS_DENIED',
        'User ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
        ' (' . ($_SESSION['role_name'] ?? 'unknown') . ') ' .
        'attempted to view DRAFT request — access denied.'
    );
    pop(
        'You do not have permission to view this draft request.',
        '/procurement/list.php',
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

/* ================================
   Monitoring-role flag — suppress action buttons for read-only viewers
================================ */
$isMonitor = isMonitoringRole($_SESSION['role_name'] ?? '');

try {
    $timelineStmt = $pdo->prepare("
        SELECT a.action, a.notes, a.change_date AS created_at, a.changed_by AS full_name
        FROM audit_log a
        WHERE a.table_name = 'procurement_requests'
          AND a.record_id = ?
        ORDER BY a.change_date ASC
    ");
    $timelineStmt->execute([$request['request_id']]);
    $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log it and continue rendering the page
    error_log("Timeline query failed: " . $e->getMessage());
    $timeline = [];
}

// Items
$itemStmt = $pdo->prepare("
  SELECT item_name, specification, quantity, remarks
  FROM procurement_request_items
  WHERE request_id = ?
");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);


// Commitments (Original + Supplementary)
// Exclude remediated (soft-deleted/voided) commitments from display
$commitStmt = $pdo->prepare("
    SELECT *
    FROM commitments
    WHERE request_id = ?
    AND (is_remediated IS NULL OR is_remediated = 0)
    ORDER BY
        CASE commitment_type
            WHEN 'ORIGINAL' THEN 0
            ELSE 1
        END,
        commitment_date ASC
");
$commitStmt->execute([$request['request_id']]);
$commitments = $commitStmt->fetchAll(PDO::FETCH_ASSOC);

// Split commitments
$originalCommitment = null;
$supplementaryCommitments = [];

foreach ($commitments as $c) {
    if ($c['commitment_type'] === 'ORIGINAL') {
        $originalCommitment = $c;
    } else {
        $supplementaryCommitments[] = $c;
    }
}


// Purchase Order (PO)
// Purchase Order (PO) – tied to ORIGINAL commitment
$po = null;
if ($originalCommitment) {
    $poStmt = $pdo->prepare("
        SELECT *
        FROM purchase_orders
        WHERE commitment_id = ?
        LIMIT 1
    ");
    $poStmt->execute([$originalCommitment['commitment_id']]);
    $po = $poStmt->fetch(PDO::FETCH_ASSOC);
}

// --- PO VARIATIONS FOR THIS REQUEST ---
$sqlPoVariations = "
    SELECT
        pv.variation_id,
        pv.po_id,
        pv.variation_amount,
        pv.reason,
        pv.status,
        pv.requested_at,
        pv.approved_at,
        pv.commitment_id AS supplementary_commitment_id,
        po.po_number,
        po.po_total,
        c.commitment_number
    FROM po_variations pv
    INNER JOIN purchase_orders po ON pv.po_id = po.po_id
    INNER JOIN commitments c ON po.commitment_id = c.commitment_id
    WHERE c.request_id = ?
    ORDER BY pv.requested_at ASC
";

$stmt = $pdo->prepare($sqlPoVariations);
$stmt->execute([$request_id]);
$poVariations = $stmt->fetchAll(PDO::FETCH_ASSOC);
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

/* ================================
   Computed values for KPIs
================================ */
$status  = strtoupper($request['status']);
$current = $status;
$role    = $_SESSION['role_name'];

$totalCommitted = 0;
foreach ($commitments as $cm) {
    $totalCommitted += (float)$cm['commitment_total'];
}
$itemCount = count($items);
$poTotal   = $po ? (float)$po['po_total'] : 0;

/* ================================
   Get request details and type
================================ */
$requestType = $request['request_type'] ?? 'REGULAR';
$estimatedValueRaw = (float)($request['estimated_value'] ?? 0);
$branchId = (int)($request['branch_id'] ?? 0);
$requestCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
$requestUsdRate = (float)($request['usd_rate'] ?? 0);
// Always use JMD for threshold comparison
$estimatedValue = ($requestCurrency === 'USD') ? $estimatedValueRaw * ($requestUsdRate ?: 155.00) : $estimatedValueRaw;

/* ================================
   Fetch service contract details (SERVICE_CONTRACT requests only)
================================ */
$serviceContract = null;
if ($requestType === 'SERVICE_CONTRACT' && !empty($request['contract_id'])) {
    $scStmt = $pdo->prepare("
        SELECT sc.contract_id, sc.contract_number, sc.contract_title
        FROM service_contracts sc
        WHERE sc.contract_id = ?
    ");
    $scStmt->execute([(int)$request['contract_id']]);
    $serviceContract = $scStmt->fetch(PDO::FETCH_ASSOC);
}

/* ================================
   Fetch approval chain from database
================================ */
$approvalStmt = $pdo->prepare("
    SELECT id, role, stage_order, status
    FROM request_approvals
    WHERE request_id = ?
    ORDER BY stage_order ASC
");
$approvalStmt->execute([$request_id]);
$approvals = $approvalStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current pending approval stage
$pendingApproval = null;
$nextApproverRole = null;
$nextApprovalId = null;
$completedApprovals = [];
$allApprovalRoles = [];

foreach ($approvals as $approval) {
    // Normalize role names for consistency (legacy 'Government Chemist' → 'Deputy Government Chemist')
    $normalizedRole = ($approval['role'] === 'Government Chemist') ? 'Deputy Government Chemist' : $approval['role'];
    $allApprovalRoles[] = $normalizedRole;
    
    if ($approval['status'] === 'pending' && $pendingApproval === null) {
        $pendingApproval = $approval;
        $nextApproverRole = $normalizedRole;
        $nextApprovalId = $approval['id'];
    }
    if ($approval['status'] === 'approved') {
        $completedApprovals[] = $normalizedRole;
    }
}

// If no pending approval and request is SUBMITTED, use theoretical chain
// Only show next approver for SUBMITTED status; other stages shouldn't have pending records
if ($nextApproverRole === null && $current === 'SUBMITTED') {
    if ($requestType === 'PETTY_CASH') {
        $nextApproverRole = 'HOD';
    } elseif ($requestType === 'REIMBURSEMENT') {
        $reimbChain = getReimbursementApprovalChain();
        $nextApproverRole = $reimbChain[0] ?? 'Branch Head';
    } else {
        $theoreticalChain = getApprovalChain($requestType, $estimatedValue, $branchId, $pdo);
        $nextApproverRole = $theoreticalChain[0] ?? 'HOD';
    }
}

// Normalize role names for display (handle legacy 'Government Chemist' vs current 'Deputy Government Chemist')
if ($nextApproverRole === 'Government Chemist') {
    $nextApproverRole = 'Deputy Government Chemist';
}

/* RFQ lookup — needed by the pipeline builder and quick links */
$stmt = $pdo->prepare("SELECT rfq_id FROM rfqs WHERE request_id = ?");
$stmt->execute([$request['request_id']]);
$rfqId = $stmt->fetchColumn();

/* Build dynamic pipeline based on approval stages */
$pipelineStages = [];

if ($requestType === 'PETTY_CASH') {
    // Use centralized pipeline definition from config/workflow.php
    $pipelineArray = getPettyCashPipeline();
    $pipelineStages = [];
    foreach ($pipelineArray as $stage) {
        $pipelineStages[$stage['status']] = [
            'icon' => $stage['icon'],
            'label' => $stage['label'],
        ];
    }
} elseif ($requestType === 'REIMBURSEMENT') {
    // Use centralized pipeline definition from config/workflow.php
    $pipelineArray = getReimbursementPipeline();
    $pipelineStages = [];
    foreach ($pipelineArray as $stage) {
        $pipelineStages[$stage['status']] = [
            'icon' => $stage['icon'],
            'label' => $stage['label'],
        ];
    }
} elseif ($requestType === 'SERVICE_CONTRACT') {
    $pipelineStages = [
        'DRAFT'     => ['icon' => 'bi-pencil-square', 'label' => 'Draft'],
        'SUBMITTED' => ['icon' => 'bi-send',           'label' => 'Submitted'],
    ];
    // Add approval stage(s) from the actual chain (or theoretical)
    $scPipelineRoles = !empty($allApprovalRoles)
        ? $allApprovalRoles
        : getServiceContractApprovalChain($estimatedValue, $branchId, $pdo);
    foreach ($scPipelineRoles as $scRole) {
        switch ($scRole) {
            case 'HOD':
            case 'Branch Head':
                $pipelineStages['HOD_APPROVED'] = ['icon' => 'bi-person-check', 'label' => 'Branch Approved'];
                break;
            case 'Director HRM&A':
                $pipelineStages['DIRECTOR_APPROVED'] = ['icon' => 'bi-briefcase-fill', 'label' => 'Director Approved'];
                break;
            case 'Deputy Government Chemist':
            case 'Government Chemist':
                $pipelineStages['GC_APPROVED'] = ['icon' => 'bi-building-check', 'label' => 'GC Approved'];
                break;
        }
    }
    // Post-approval stages specific to service contracts (no PO/RFQ)
    $pipelineStages['FUNDS_VERIFIED']      = ['icon' => 'bi-cash-coin',          'label' => 'Funds Verified'];
    $pipelineStages['COMMITMENT_APPROVED'] = ['icon' => 'bi-file-earmark-check', 'label' => 'Committed'];
    $pipelineStages['INVOICE_RECEIVED']    = ['icon' => 'bi-receipt',            'label' => 'Invoiced'];
    $pipelineStages['COMPLETED']           = ['icon' => 'bi-check-circle',       'label' => 'Paid'];
} else {
    // Regular procurement - build pipeline based on actual approval chain AND threshold
    // UPDATED: All regular procurement now uses RFQ (under & over-threshold)
    // Under-threshold: Skip committee evaluation
    // Over-threshold: Include committee evaluation
    
    $pipelineStages = [
        'DRAFT'           => ['icon' => 'bi-pencil-square',   'label' => 'Draft'],
        'SUBMITTED'       => ['icon' => 'bi-send',            'label' => 'Submitted'],
    ];
    
    // Add stages based on actual roles in approval chain (or theoretical if no records exist)
    $pipelineRoles = !empty($allApprovalRoles) 
        ? $allApprovalRoles 
        : getApprovalChain($requestType, $estimatedValue, $branchId, $pdo);
    foreach ($pipelineRoles as $pipelineRole) {
        switch ($pipelineRole) {
            case 'HOD':
                $pipelineStages['HOD_APPROVED'] = ['icon' => 'bi-person-check', 'label' => 'HOD Approved'];
                break;
            case 'Director HRM&A':
                $pipelineStages['DIRECTOR_APPROVED'] = ['icon' => 'bi-briefcase-fill', 'label' => 'Director Approved'];
                break;
            case 'Deputy Government Chemist':
            case 'Government Chemist':  // Legacy support
                $pipelineStages['GC_APPROVED'] = ['icon' => 'bi-building-check', 'label' => 'GC Approved'];
                break;
            case 'Finance Officer':
                $pipelineStages['FUNDS_VERIFIED'] = ['icon' => 'bi-cash-coin', 'label' => 'Funds Verified'];
                break;
        }
    }
    
    // UPDATED: Add RFQ workflow stages (dynamically based on threshold and path)
    // All regular procurement now uses RFQ - check threshold to determine if committee evaluation needed
    $isDirectProcurement = isDirectProcurement($requestType, $estimatedValue);
    // Detect the "Proceed Without RFQ" path via isSkipRfqPath() in workflow.php.
    // The requires_rfq column cannot be used reliably here — see isSkipRfqPath() docblock for details.
    $isSkipRfqPath = isSkipRfqPath($requestType, $rfqId, $current);

    if (!$isDirectProcurement) {
        $directThreshold = getDirectProcurementThreshold($pdo);
        if ($isSkipRfqPath) {
            // "Proceed Without RFQ" path: AWARDED is the post-approval milestone followed by the
            // full financial post-award sequence. No RFQ/quote stages are shown.
            $pipelineStages['AWARDED'] = ['icon' => 'bi-trophy', 'label' => 'Awarded'];
            
            // Only include commitment/PO stages if po_required = 'YES'
            if (shouldIncludeCommitmentStages($originalCommitment)) {
                $pipelineStages['COMMITMENTS_PENDING'] = ['icon' => 'bi-pencil-square',      'label' => 'Commitment Form'];
                $pipelineStages['COMMITMENT_APPROVED'] = ['icon' => 'bi-file-earmark-check', 'label' => 'Commitment Created'];
                $pipelineStages['PO_PENDING']          = ['icon' => 'bi-file-earmark-text',  'label' => 'PO Created'];
            }
            
            $pipelineStages['INVOICE_RECEIVED'] = ['icon' => 'bi-receipt', 'label' => 'Invoice'];
        } elseif ($estimatedValue > $directThreshold) {
            // Over-threshold: include both committee evaluation and the RFQ quote approval stages.
            // The quote approval statuses are real procurement_requests.status values, so omitting
            // them leaves the pipeline unable to locate the current active stage.
            $pipelineStages['PROCUREMENT_STAGE'] = ['icon' => 'bi-clipboard-check', 'label' => 'Procurement'];
            $pipelineStages['EVALUATION_STAGE'] = ['icon' => 'bi-bar-chart', 'label' => 'Evaluation'];
            $pipelineStages['COMMITTEE_RECOMMENDED'] = ['icon' => 'bi-people-fill', 'label' => 'Committee'];
            // Reposition GC_APPROVED after committee (may already exist from approval chain)
            unset($pipelineStages['GC_APPROVED']);
            $pipelineStages['GC_APPROVED'] = ['icon' => 'bi-building-check', 'label' => 'GC Approved'];
            $pipelineStages['RFQ_LETTER_AVAILABLE'] = ['icon' => 'bi-envelope-open', 'label' => 'RFQ Letters'];
            $pipelineStages['QUOTE_REVIEW_PENDING'] = ['icon' => 'bi-chat-dots', 'label' => 'Quote Review'];
            $pipelineStages['QUOTE_REQUESTOR_REVIEW_PENDING'] = ['icon' => 'bi-person-check', 'label' => 'Requestor Review'];
            $pipelineStages['QUOTE_REQUESTOR_REVIEW_APPROVED'] = ['icon' => 'bi-person-check-fill', 'label' => 'Requestor Approved'];
            $pipelineStages['QUOTE_BRANCH_HEAD_APPROVAL_PENDING'] = ['icon' => 'bi-shield-check', 'label' => 'Branch Head Approval'];
            $pipelineStages['QUOTE_APPROVED'] = ['icon' => 'bi-check-circle', 'label' => 'Quote Selected'];
            // Post-award financial stages (funds verify → commitment form → commitment created → PO → invoice)
            $pipelineStages['FUNDS_VERIFIED'] = ['icon' => 'bi-cash-coin', 'label' => 'Funds Verified'];
            $pipelineStages['COMMITMENTS_PENDING'] = ['icon' => 'bi-pencil-square', 'label' => 'Commitment Form'];
            $pipelineStages['COMMITMENT_APPROVED'] = ['icon' => 'bi-file-earmark-check', 'label' => 'Commitment Created'];
            $pipelineStages['PO_PENDING'] = ['icon' => 'bi-file-earmark-text', 'label' => 'PO Created'];
            $pipelineStages['INVOICE_RECEIVED'] = ['icon' => 'bi-receipt', 'label' => 'Invoice'];
        } else {
            // Under-threshold: Quote review → Funds Verified → Commitment Form → Commitment Created → PO flow
            $pipelineStages['RFQ_LETTER_AVAILABLE'] = ['icon' => 'bi-envelope-open', 'label' => 'RFQ Letters'];
            $pipelineStages['QUOTE_REVIEW_PENDING'] = ['icon' => 'bi-chat-dots', 'label' => 'Quote Review'];
            $pipelineStages['QUOTE_REQUESTOR_REVIEW_PENDING'] = ['icon' => 'bi-person-check', 'label' => 'Requestor Review'];
            $pipelineStages['QUOTE_REQUESTOR_REVIEW_APPROVED'] = ['icon' => 'bi-person-check-fill', 'label' => 'Requestor Approved'];
            $pipelineStages['QUOTE_BRANCH_HEAD_APPROVAL_PENDING'] = ['icon' => 'bi-shield-check', 'label' => 'Branch Head Approval'];
            $pipelineStages['QUOTE_APPROVED'] = ['icon' => 'bi-check-circle', 'label' => 'Quote Selected'];
            $pipelineStages['FUNDS_VERIFIED'] = ['icon' => 'bi-cash-coin', 'label' => 'Funds Verified'];
            $pipelineStages['COMMITMENTS_PENDING'] = ['icon' => 'bi-pencil-square', 'label' => 'Commitment Form'];
            $pipelineStages['COMMITMENT_APPROVED'] = ['icon' => 'bi-file-earmark-check', 'label' => 'Commitment Created'];
            $pipelineStages['PO_PENDING'] = ['icon' => 'bi-file-earmark-text', 'label' => 'PO Created'];
            $pipelineStages['INVOICE_RECEIVED'] = ['icon' => 'bi-receipt', 'label' => 'Invoice'];
        }
    }
    $pipelineStages['COMPLETED'] = ['icon' => 'bi-check-circle', 'label' => 'Complete'];
}

// Build pipeline display order
$stageKeys = array_keys($pipelineStages);
$currentIdx = array_search($current, $stageKeys);
$totalStagesW = count($stageKeys);
$progressPct = ($currentIdx !== false && $totalStagesW > 1)
    ? round((($currentIdx + 1) / $totalStagesW) * 100)
    : 0;

$badgeMap = [
    'DRAFT'                 => ['secondary',         'bi-pencil-square'],
    'SUBMITTED'             => ['warning text-dark',  'bi-send'],
    'HOD_APPROVED'          => ['info text-dark',     'bi-person-check'],
    'FUNDS_VERIFIED'        => ['primary',            'bi-cash-coin'],
    'DIRECTOR_APPROVED'     => ['info',               'bi-briefcase-fill'],
    'APPROVED'              => ['success',            'bi-briefcase-fill'],
    'REIMBURSED'            => ['success text-dark',  'bi-cash-coin'],
    'INVOICE_SUBMITTED'     => ['info text-dark',     'bi-file-earmark-text'],
    'INVOICE_VERIFIED'      => ['info text-dark',     'bi-check2-circle'],
    'GC_APPROVED'           => ['success',            'bi-building-check'],
    'PROCUREMENT_STAGE'     => ['info',               'bi-clipboard-check'],
    'EVALUATION_STAGE'      => ['warning text-dark',  'bi-bar-chart'],
    'COMMITTEE_RECOMMENDED' => ['info text-dark',     'bi-people-fill'],
    'RFQ_LETTER_AVAILABLE'  => ['info',               'bi-envelope-open'],
    'QUOTE_REVIEW_PENDING'  => ['warning text-dark',  'bi-chat-dots'],
    'QUOTE_REQUESTOR_REVIEW_PENDING' => ['warning text-dark', 'bi-person-check'],
    'QUOTE_REQUESTOR_REVIEW_APPROVED' => ['info text-dark', 'bi-person-check-fill'],
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['info text-dark', 'bi-shield-check'],
    'QUOTE_APPROVED'        => ['info text-dark',     'bi-check-circle'],
    'COMMITMENTS_PENDING'   => ['warning text-dark',  'bi-pencil-square'],
    'COMMITMENT_APPROVED'   => ['success text-dark',  'bi-cash-coin'],
    'COMMITMENT_DECLINED'   => ['danger',             'bi-x-octagon'],
    'PO_PENDING'            => ['success text-dark',  'bi-file-earmark-text'],
    'PO_APPROVED'           => ['success text-dark',  'bi-file-earmark-check'],
    'INVOICE_RECEIVED'      => ['info text-dark',     'bi-receipt'],
    'AWARDED'               => ['success',            'bi-trophy'],
    'COMPLETED'             => ['success',            'bi-check-circle'],
    'DECLINED'              => ['danger',             'bi-x-octagon'],
    'CANCELLED'             => ['danger',             'bi-slash-circle'],
    'PAUSED'                => ['warning text-dark',  'bi-pause-circle'],
    'DISBURSED'             => ['success text-dark',  'bi-wallet2'],
    'FINANCE_AUTHORIZED'    => ['success text-dark',  'bi-cash-coin'],
];
$badge = $badgeMap[$status] ?? ['secondary', 'bi-question-circle'];

// Workflow responsibility tooltips
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowResponsibilityService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/workflow_pipeline.php';
$wfRespService = new WorkflowResponsibilityService($pdo);
$wfResponsibilities = $wfRespService->getPipelineResponsibility(
    $pipelineStages,
    $request,
    $current,
    $approvals,
    $role
);

?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="container mt-4">

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h3 class="section-title mb-1">
                <i class="bi bi-file-earmark-text me-2"></i>Request: <?= htmlspecialchars($request['request_number']) ?>
            </h3>
            <small class="text-muted">
                <?= htmlspecialchars($request['branch_name'] ?? 'Department of Government Chemist') ?>
                <?php if ($request['approved_by_name']): ?>
                    &middot; Approved by <strong><?= htmlspecialchars($request['approved_by_name']) ?></strong>
                <?php endif; ?>
            </small>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <span class="badge bg-<?= $badge[0] ?> fs-6">
            <i class="bi <?= $badge[1] ?> me-1"></i><?= str_replace('_', ' ', $status) ?>
        </span>
        <?php if (in_array($status, ['GC_APPROVED','AWARDED','COMPLETED'])): ?>
            <span class="badge bg-dark fs-6"><i class="bi bi-lock me-1"></i>Locked</span>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     WORKFLOW PATH BANNER
═══════════════════════════════════════════════════════ -->
<div class="d-flex flex-wrap align-items-center gap-3 mb-4 p-3" style="background:#f8f9fa; border-radius:10px; border:1px solid #e0e0e0;">
    <div>
        <span class="text-muted small fw-bold text-uppercase" style="letter-spacing:.05em;">Workflow Path</span><br>
        <?= getWorkflowBadgeHtml($request) ?>
    </div>
    <?php
    // Show PO Required badge from the original commitment
    if ($originalCommitment): ?>
    <div>
        <span class="text-muted small fw-bold text-uppercase" style="letter-spacing:.05em;">PO Required</span><br>
        <?php $poReqVal = $originalCommitment['po_required'] ?? 'YES'; ?>
        <?php if ($poReqVal === 'NO'): ?>
            <span style="display:inline-flex;align-items:center;gap:0.25rem;background:#fff3cd;color:#856404;border:1px solid #ffc107;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.78rem;font-weight:600;">⚡ No — Skip PO</span>
        <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:0.25rem;background:#d1e7dd;color:#0a3622;border:1px solid #a3cfbb;padding:0.3rem 0.75rem;border-radius:20px;font-size:0.78rem;font-weight:600;">✅ Yes — Standard PO</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div>
        <span class="text-muted small fw-bold text-uppercase" style="letter-spacing:.05em;">Current Status</span><br>
        <?= statusBadge($status) ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     QUICK ACTION BUTTONS (permission-controlled)
═══════════════════════════════════════════════════════ -->
<?php
$isProcOfficer = in_array($role, ['Procurement Officer', 'Admin', 'SuperAdmin']);
$isFinOfficer  = in_array($role, ['Finance Officer', 'Admin', 'SuperAdmin']);
$isHodRole     = in_array($role, ['HOD', 'Branch Head', 'Admin', 'SuperAdmin']);
$quickActions = [];

if ($requestType === 'REGULAR') {
    // RFQ actions for Procurement Officers
    if ($isProcOfficer) {
        if (in_array($status, ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE']) && !$rfqId) {
            $quickActions[] = ['label' => '📬 Create RFQ', 'href' => '/rfq/create.php?request_id=' . $request_id, 'style' => 'background:#cfe2ff;color:#084298;'];
        }
        if ($rfqId && in_array($status, ['RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED'])) {
            $quickActions[] = ['label' => '👁️ View RFQ', 'href' => '/rfq/view.php?id=' . $rfqId, 'style' => 'background:#e2e3e5;color:#333;'];
        }
        if (in_array($status, ['FUNDS_VERIFIED', 'COMMITMENTS_PENDING', 'AWARDED'])) {
            $quickActions[] = ['label' => '📋 Create Commitment', 'href' => '/commitments/add.php?request_id=' . $request_id, 'style' => 'background:#d1e7dd;color:#0a3622;'];
        }
    }
    // Finance actions
    if ($isFinOfficer) {
        if (in_array($status, ['QUOTE_APPROVED', 'AWARDED', 'FUNDS_VERIFIED'])) {
            $quickActions[] = ['label' => '💰 Verify Funds / Commitment', 'href' => '/commitments/add.php?request_id=' . $request_id, 'style' => 'background:#d1e7dd;color:#0a3622;'];
        }
        if ($originalCommitment && empty($po) && $status === 'COMMITMENT_APPROVED' && shouldIncludeCommitmentStages($originalCommitment)) {
            $quickActions[] = ['label' => '📄 Create PO', 'href' => '/po/add.php?commitment_id=' . $originalCommitment['commitment_id'], 'style' => 'background:#fff3cd;color:#856404;'];
        }
    }
    // HOD approve action
    if ($isHodRole && $status === 'SUBMITTED') {
        $quickActions[] = ['label' => '✅ Approve', 'href' => '/procurement/approve_hod.php?id=' . $request_id, 'style' => 'background:#d1e7dd;color:#0a3622;'];
        $quickActions[] = ['label' => '❌ Decline', 'href' => '/procurement/decline.php?id=' . $request_id, 'style' => 'background:#f8d7da;color:#842029;'];
    }
}

if (!empty($quickActions)): ?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <span class="text-muted small fw-bold align-self-center" style="text-transform:uppercase;letter-spacing:.05em;">Quick Actions:</span>
    <?php foreach ($quickActions as $qa): ?>
        <a href="<?= htmlspecialchars($qa['href']) ?>" style="<?= $qa['style'] ?> padding:0.4rem 1rem; border-radius:8px; text-decoration:none; font-size:0.82rem; font-weight:600; border:1px solid rgba(0,0,0,0.08);">
            <?= $qa['label'] ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     KPI METRIC CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <?php if ($requestType === 'REGULAR'): ?>
        <!-- REGULAR PROCUREMENT KPIs -->
        <div class="col col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-left: 6px solid #4caf50;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#2e7d32;">Estimated Cost</small>
                    <h4 class="mb-0 fw-bold" style="color:#1b5e20;"><?= $requestCurrency ?> <?= number_format($estimatedValue, 2) ?></h4>
                    <small class="text-muted"><?= $estimatedValue > getDirectProcurementThreshold($pdo) ? 'Over threshold' : 'Under threshold' ?></small>
                </div>
            </div>
        </div>
        <div class="col col-sm-6 col-lg">
            <div class="card border-0 shadow-sm kpi-card kpi-gold h-100">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Items</small>
                    <h3 class="mb-0 fw-bold"><?= $itemCount ?></h3>
                    <small class="text-muted">Line items</small>
                </div>
            </div>
        </div>
        <?php if (shouldIncludeCommitmentStages($originalCommitment)): ?>
        <div class="col col-sm-6 col-lg">
            <div class="card border-0 shadow-sm kpi-card kpi-green h-100">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Committed</small>
                    <h3 class="mb-0 fw-bold">JMD <?= number_format($totalCommitted, 2) ?></h3>
                    <small class="text-muted"><?= count($commitments) ?> commitment<?= count($commitments) !== 1 ? 's' : '' ?></small>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Purchase Order</small>
                    <?php if ($po): ?>
                        <a href="/po/view.php?po_id=<?= (int)$po['po_id'] ?>" class="text-decoration-none">
                            <h4 class="mb-0 fw-bold" style="color:#1a237e;">JMD <?= number_format($poTotal, 2) ?></h4>
                        </a>
                        <small class="text-muted"><?= htmlspecialchars($po['po_number']) ?></small>
                    <?php else: ?>
                        <h4 class="mb-0 text-muted">&mdash;</h4>
                        <small class="text-muted">No PO yet</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">RFQ</small>
                    <?php if ($rfqId): ?>
                        <a href="/rfq/view.php?id=<?= (int)$rfqId ?>" class="text-decoration-none">
                            <h4 class="mb-0 fw-bold" style="color:#880e4f;"><i class="bi bi-file-earmark-check"></i> View</h4>
                        </a>
                        <small class="text-muted">RFQ linked</small>
                    <?php else: ?>
                        <h4 class="mb-0 text-muted">&mdash;</h4>
                        <small class="text-muted">Not created</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php elseif ($requestType === 'REIMBURSEMENT'): ?>
        <!-- REIMBURSEMENT KPIs -->
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-left: 6px solid #4caf50;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#2e7d32;">Amount</small>
                    <h3 class="mb-0 fw-bold" style="color:#1b5e20;"><?= $requestCurrency ?> <?= number_format($estimatedValue, 2) ?></h3>
                    <small class="text-muted">Requested</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left: 6px solid #ff9800;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#e65100;">Status</small>
                    <h4 class="mb-0 fw-bold" style="color:#bf360c;"><?= str_replace('_', ' ', $status) ?></h4>
                    <small class="text-muted">Current</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-left: 6px solid #9c27b0;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#6a1b9a;">Request Info</small>
                    <div class="text-muted small">
                        <div>Created: <strong><?= date('M d, Y', strtotime($request['created_at'])) ?></strong></div>
                        <div>Branch: <strong><?= htmlspecialchars($request['branch_name']) ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($requestType === 'PETTY_CASH'): ?>
        <!-- PETTY CASH KPIs -->
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); border-left: 6px solid #2196f3;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#0d47a1;">Requested</small>
                    <h3 class="mb-0 fw-bold" style="color:#0d47a1;"><?= $requestCurrency ?> <?= number_format($estimatedValue, 2) ?></h3>
                    <small class="text-muted">Amount</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">Status</small>
                    <h4 class="mb-0 fw-bold" style="color:#880e4f;"><?= str_replace('_', ' ', $status) ?></h4>
                    <small class="text-muted">Current</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fff9c4, #ffe082); border-left: 6px solid #fbc02d;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#f57f17;">Details</small>
                    <div class="text-muted small">
                        <div>Purpose: <strong><?= htmlspecialchars(substr($request['description'], 0, 30)) ?>...</strong></div>
                        <div>Branch: <strong><?= htmlspecialchars($request['branch_name']) ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($requestType === 'SERVICE_CONTRACT'): ?>
        <!-- SERVICE CONTRACT KPIs -->
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-left: 6px solid #4caf50;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#2e7d32;">Payment Amount</small>
                    <h3 class="mb-0 fw-bold" style="color:#1b5e20;"><?= $requestCurrency ?> <?= number_format($estimatedValueRaw, 2) ?></h3>
                    <small class="text-muted">Requested</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left: 6px solid #ff9800;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#e65100;">Status</small>
                    <h4 class="mb-0 fw-bold" style="color:#bf360c;"><?= str_replace('_', ' ', $status) ?></h4>
                    <small class="text-muted">Current</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Contract</small>
                    <?php if ($serviceContract): ?>
                        <a href="/contracts/view.php?id=<?= (int)$serviceContract['contract_id'] ?>" class="text-decoration-none">
                            <h5 class="mb-0 fw-bold" style="color:#1a237e;"><?= htmlspecialchars($serviceContract['contract_number']) ?></h5>
                        </a>
                        <?php $scTitle = $serviceContract['contract_title'] ?? ''; ?>
                        <small class="text-muted"><?= htmlspecialchars(mb_strlen($scTitle) > 30 ? mb_substr($scTitle, 0, 30) . '…' : $scTitle) ?></small>
                    <?php else: ?>
                        <h5 class="mb-0 text-muted">&mdash;</h5>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-left: 6px solid #9c27b0;">
                <div class="card-body text-center py-3">
                    <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#6a1b9a;">Branch</small>
                    <h5 class="mb-0 fw-bold" style="color:#4a148c;"><?= htmlspecialchars($request['branch_name'] ?? '—') ?></h5>
                    <small class="text-muted"><?= date('M d, Y', strtotime($request['created_at'])) ?></small>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     WORKFLOW PIPELINE
═══════════════════════════════════════════════════════ -->
<?php if ($current !== 'DECLINED'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Workflow Pipeline</h5>
        <span class="badge <?= $current === 'COMPLETED' ? 'bg-success' : 'bg-warning text-dark' ?> fs-6">
            <?= $progressPct ?>% Complete
        </span>
    </div>
    <div class="card-body">
        <!-- Progress bar -->
        <div class="progress mb-4" style="height: 8px; border-radius: 4px;">
            <div class="progress-bar <?= $current === 'COMPLETED' ? 'bg-success' : 'bg-primary' ?>"
                 style="width: <?= $progressPct ?>%; transition: width 0.6s ease;"
                 role="progressbar" aria-valuenow="<?= $progressPct ?>" aria-valuemin="0" aria-valuemax="100">
            </div>
        </div>

        <div class="row g-2 pipeline-stages-row">
            <?php foreach ($pipelineStages as $stageKey => $stageInfo):
                $idx = array_search($stageKey, $stageKeys);
                echo renderWorkflowPipelineStage(
                    $stageKey,
                    $stageInfo,
                    (int)$idx,
                    $totalStagesW,
                    $currentIdx !== false ? (int)$currentIdx : -1,
                    $wfResponsibilities[$stageKey] ?? []
                );
            endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center gap-3 mb-4">
    <i class="bi bi-x-octagon fs-2"></i>
    <div>
        <strong>This request has been declined.</strong>
        <?php if (!empty($request['decline_reason'])): ?>
            <div class="mt-1"><?= nl2br(htmlspecialchars($request['decline_reason'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($current === 'DRAFT' && !empty($request['decline_reason'])): ?>
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3 mb-4">
    <i class="bi bi-arrow-counterclockwise fs-2"></i>
    <div>
        <strong>This request was sent back for edit.</strong>
        <div class="mt-1"><?= nl2br(htmlspecialchars($request['decline_reason'])) ?></div>
    </div>
</div>
<?php endif; ?>

<?php
// Show this banner for ALL REGULAR requests at AWARDED without a commitment — not just the
// skip-RFQ path. Both the skip-RFQ path and the standard over-threshold RFQ path land at
// AWARDED and still require Commitment → PO → Invoice before the request can be closed.
// $originalCommitment is initialized earlier in this file (set to null, then populated from
// the commitments query above).
// Build the guidance message here — not at page-top — since it is only needed from this
// point onwards (banner and Actions tab).
$awardedNextStepMsg = "This request is NOT complete. Next: " . getAwardedWorkflowGuidance();
if ($current === 'AWARDED' && $requestType === 'REGULAR' && !$originalCommitment): ?>
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-2 flex-shrink-0 text-warning"></i>
    <div>
        <strong>Action Required — This request is not yet complete.</strong>
        <div class="mt-1 small"><?= htmlspecialchars($awardedNextStepMsg) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     REQUEST DETAILS + ACTIONS (TWO-COLUMN)
═══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <!-- REQUEST DETAILS - REGULAR ONLY -->
    <?php if ($requestType === 'REGULAR'): ?>
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Request Items</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-3" >Item</th>
                                <th >Specification</th>
                                <th class="text-center" width="70" >Qty</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No items recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $i): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= htmlspecialchars($i['item_name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($i['specification']) ?></td>
                                    <td class="text-center"><?= (int)$i['quantity'] ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($i['remarks']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($requestType === 'REIMBURSEMENT'): ?>
    <!-- REIMBURSEMENT DETAILS -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Reimbursement Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Request Number</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['request_number']) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Amount</label>
                        <p class="mb-0"><strong class="text-success"><?= $requestCurrency ?> <?= number_format($estimatedValue, 2) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Date</label>
                        <p class="mb-0"><strong><?= date('M d, Y', strtotime($request['created_at'])) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Branch</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['branch_name']) ?></strong></p>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted small fw-bold">Description</label>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($requestType === 'PETTY_CASH'): ?>
    <!-- PETTY CASH DETAILS -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Petty Cash Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Request Number</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['request_number']) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Amount</label>
                        <p class="mb-0"><strong class="text-info"><?= $requestCurrency ?> <?= number_format($estimatedValue, 2) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Date Requested</label>
                        <p class="mb-0"><strong><?= date('M d, Y', strtotime($request['created_at'])) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Branch</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['branch_name']) ?></strong></p>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted small fw-bold">Purpose</label>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($requestType === 'SERVICE_CONTRACT'): ?>
    <!-- SERVICE CONTRACT PAYMENT REQUEST DETAILS -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Payment Request Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Request Number</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['request_number']) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Payment Amount</label>
                        <p class="mb-0"><strong class="text-success"><?= $requestCurrency ?> <?= number_format($estimatedValueRaw, 2) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Date Requested</label>
                        <p class="mb-0"><strong><?= date('M d, Y', strtotime($request['created_at'])) ?></strong></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Branch</label>
                        <p class="mb-0"><strong><?= htmlspecialchars($request['branch_name'] ?? '—') ?></strong></p>
                    </div>
                    <?php if ($serviceContract): ?>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Service Contract</label>
                        <p class="mb-0">
                            <a href="/contracts/view.php?id=<?= (int)$serviceContract['contract_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($serviceContract['contract_number']) ?>
                            </a>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Contract Title</label>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($serviceContract['contract_title'] ?? '—') ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label text-muted small fw-bold">Description / Purpose</label>
                        <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ACTIONS -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions</h5>
            </div>
            <div class="card-body">
                <?php
                // Determine button and label text based on request type
                $typeLabel = [
                    'PETTY_CASH'       => '💰 Petty Cash Request',
                    'REIMBURSEMENT'    => '💵 Reimbursement Request',
                    'REGULAR'          => '📋 Regular Procurement',
                    'SERVICE_CONTRACT' => '📄 Service Contract Payment',
                ][$requestType] ?? '📋 Request';
                
                // Check if under threshold (direct procurement)
                $isDirectProcurement = isDirectProcurement($requestType, $estimatedValue);
                
                // Build next step description based on workflow
                $nextStepDisplay = null;
                $nextStepIcon = 'bi-hourglass-split';
                $nextStepColor = 'text-info';
                
                // Show awaiting approval for any status with a pending approval
                if ($current === 'PAUSED') {
                    $nextStepDisplay = "Procurement paused. Resume is required before workflow actions can continue.";
                    $nextStepIcon = 'bi-pause-circle';
                    $nextStepColor = 'text-warning';
                } elseif ($nextApproverRole && $nextApprovalId) {
                    $nextStepDisplay = "Awaiting {$nextApproverRole} approval";
                    $nextStepIcon = 'bi-person-check';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'DRAFT') {
                    $nextStepDisplay = "Submit this request for approval";
                    $nextStepIcon = 'bi-send';
                    $nextStepColor = 'text-secondary';
                } elseif ($current === 'AWARDED') {
                    // Once AWARDED, explain next step based on type
                    if ($requestType === 'PETTY_CASH' || $requestType === 'REIMBURSEMENT') {
                        $nextStepDisplay = "Move to financial processing";
                        $nextStepIcon = 'bi-cash-coin';
                        $nextStepColor = 'text-success';
                    } else {
                        $nextStepDisplay = $awardedNextStepMsg;
                        $nextStepIcon = 'bi-exclamation-triangle';
                        $nextStepColor = 'text-warning';
                    }
                } elseif ($current === 'PROCUREMENT_STAGE') {
                    $nextStepDisplay = "Create RFQ and issue letters to vendors for quotes (Over threshold evaluation required).";
                    $nextStepIcon = 'bi-envelope-open';
                    $nextStepColor = 'text-warning';
                } elseif ($current === 'RFQ_LETTER_AVAILABLE') {
                    $nextStepDisplay = "RFQ letters available. Send to vendors and await quote submissions, then move to Quote Review.";
                    $nextStepIcon = 'bi-envelope-open';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'QUOTE_REVIEW_PENDING') {
                    $nextStepDisplay = "Review vendor quotes and select the preferred quotation for requestor confirmation.";
                    $nextStepIcon = 'bi-search';
                    $nextStepColor = 'text-warning';
                } elseif ($current === 'QUOTE_REQUESTOR_REVIEW_PENDING') {
                    $nextStepDisplay = "Awaiting the original requestor to confirm the selected vendor quotation meets the requested specifications.";
                    $nextStepIcon = 'bi-person-check';
                    $nextStepColor = 'text-warning';
                } elseif ($current === 'QUOTE_REQUESTOR_REVIEW_APPROVED') {
                    $nextStepDisplay = "Requestor confirmed the selected quotation. Branch Head approval is the next step.";
                    $nextStepIcon = 'bi-person-check-fill';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING') {
                    $nextStepDisplay = "Awaiting Branch Head final approval of the selected vendor quotation.";
                    $nextStepIcon = 'bi-shield-check';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'QUOTE_APPROVED') {
                    $nextStepDisplay = "Quote approved. Finance needs to verify funds availability before commitment can proceed.";
                    $nextStepIcon = 'bi-cash-coin';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'EVALUATION_STAGE') {
                    $nextStepDisplay = "RFQ evaluation in progress. Committee members reviewing vendor submissions.";
                    $nextStepIcon = 'bi-bar-chart';
                    $nextStepColor = 'text-warning';
                } elseif ($current === 'COMMITTEE_RECOMMENDED') {
                    $nextStepDisplay = "Committee has recommended a vendor. Awaiting GC approval (SOP Step 10).";
                    $nextStepIcon = 'bi-shield-check';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'FUNDS_VERIFIED' && !$nextApprovalId) {
                    // Post-quote FUNDS_VERIFIED (no more approvals pending = commitment flow)
                    $nextStepDisplay = "Funds verified by Finance. Procurement may upload an optional commitment form, then Finance creates the commitment in GFMS (document is optional).";
                    $nextStepIcon = 'bi-pencil-square';
                    $nextStepColor = 'text-warning';
                } elseif ($current === 'COMMITMENTS_PENDING') {
                    $nextStepDisplay = "Commitment form submitted by Procurement. Finance to create commitment in GFMS (document upload is optional).";
                    $nextStepIcon = 'bi-cloud-upload';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'COMMITMENT_APPROVED') {
                    if ($requestType === 'SERVICE_CONTRACT') {
                        $nextStepDisplay = "Commitment created. Submit the invoice to proceed to payment.";
                        $nextStepIcon = 'bi-receipt';
                        $nextStepColor = 'text-success';
                    } elseif (!shouldIncludeCommitmentStages($originalCommitment)) {
                        // Non-PO path: No Purchase Order required, go directly to invoicing
                        $nextStepDisplay = "Award confirmed. Submit the invoice to proceed to payment.";
                        $nextStepIcon = 'bi-receipt';
                        $nextStepColor = 'text-success';
                    } else {
                        // Standard path: PO required
                        $nextStepDisplay = "Commitment created. Create a Purchase Order.";
                        $nextStepIcon = 'bi-file-earmark-plus';
                        $nextStepColor = 'text-success';
                    }
                } elseif ($current === 'PO_PENDING') {
                    $nextStepDisplay = "Purchase Order created. Upload invoice to proceed.";
                    $nextStepIcon = 'bi-receipt';
                    $nextStepColor = 'text-info';
                } elseif ($current === 'INVOICE_RECEIVED') {
                    $nextStepDisplay = "Invoice received. Process payment to complete.";
                    $nextStepIcon = 'bi-cash-stack';
                    $nextStepColor = 'text-success';
                } elseif ($current === 'COMMITMENT_DECLINED') {
                    $nextStepDisplay = "Commitment was declined. Revise and resubmit.";
                    $nextStepIcon = 'bi-arrow-repeat';
                    $nextStepColor = 'text-danger';
                } elseif ($current === 'COMPLETED') {
                    $nextStepDisplay = "This request is complete";
                    $nextStepIcon = 'bi-check-circle';
                    $nextStepColor = 'text-success';
                } elseif ($current === 'DECLINED') {
                    $nextStepDisplay = "Request declined - can be resubmitted";
                    $nextStepIcon = 'bi-arrow-repeat';
                    $nextStepColor = 'text-warning';
                } elseif (in_array($current, ['HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED'])) {
                    if ($requestType === 'SERVICE_CONTRACT') {
                        if ($current === 'HOD_APPROVED' || $current === 'DIRECTOR_APPROVED' || $current === 'GC_APPROVED') {
                            $nextStepDisplay = "Approved. Finance Officer needs to verify funds and create a commitment.";
                            $nextStepIcon = 'bi-cash-coin';
                            $nextStepColor = 'text-success';
                        } elseif ($current === 'FUNDS_VERIFIED') {
                            $nextStepDisplay = "Funds verified. Finance to create commitment in GFMS and upload the document.";
                            $nextStepIcon = 'bi-file-earmark-check';
                            $nextStepColor = 'text-warning';
                        }
                    } else {
                        // Resolve the correct workflow to show accurate next step
                        $wf = resolveWorkflow($pdo, $requestType, $estimatedValue, $branchId, $requestCurrency, $requestUsdRate);
                        if ($current === 'GC_APPROVED' && $rfqId) {
                            // Post-committee GC approval — ready for vendor award
                            $nextStepDisplay = "GC approved. Proceed to award the recommended vendor.";
                            $nextStepIcon = 'bi-trophy';
                            $nextStepColor = 'text-success';
                        } elseif ($wf['post_approval_status'] === 'AWARDED') {
                            $nextStepDisplay = "All approvals complete. Proceeding to vendor award ({$wf['workflow_label']}).";
                            $nextStepIcon = 'bi-trophy';
                            $nextStepColor = 'text-success';
                        } elseif ($wf['post_approval_status'] === 'RFQ_LETTER_AVAILABLE') {
                            $nextStepDisplay = "Approved. Create RFQ and send letters to vendors for quotes ({$wf['workflow_label']}).";
                            $nextStepIcon = 'bi-envelope-open';
                            $nextStepColor = 'text-info';
                        } elseif ($wf['post_approval_status'] === 'PROCUREMENT_STAGE') {
                            $nextStepDisplay = "Approved. Proceed to procurement stage for evaluation ({$wf['workflow_label']}).";
                            $nextStepIcon = 'bi-clipboard-check';
                            $nextStepColor = 'text-warning';
                        } else {
                            $nextStepDisplay = "Approved. Next: " . str_replace('_', ' ', $wf['post_approval_status']);
                            $nextStepIcon = 'bi-arrow-right';
                            $nextStepColor = 'text-info';
                        }
                    }
                } else {
                    $nextStepDisplay = "In progress";
                    $nextStepIcon = 'bi-clock';
                    $nextStepColor = 'text-info';
                }
                
                if ($nextStepDisplay): ?>
                <div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light border mb-3">
                    <i class="bi <?= $nextStepIcon ?> fs-4 <?= $nextStepColor ?>"></i>
                    <div>
                        <strong class="d-block small">Next Step</strong>
                        <span class="small"><?= $nextStepDisplay ?></span>
                        <span class="badge bg-secondary-subtle text-secondary mt-1"><?= $typeLabel ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <?php if ($current === 'DRAFT'): ?>
                        <?php 
                        // Determine which edit.php file to use based on request type
                        $editUrl = '/procurement/edit.php';
                        if ($requestType === 'REIMBURSEMENT') {
                            $editUrl = '/reimbursement/edit.php';
                        } elseif ($requestType === 'PETTY_CASH') {
                            $editUrl = '/petty_cash/edit.php';
                        } elseif ($requestType === 'SERVICE_CONTRACT') {
                            // Service contract payment requests are created/edited via the contracts module
                            $editUrl = null;
                        }
                        
                        // Determine which submit.php file to use
                        $submitUrl = '/procurement/submit.php';
                        if ($requestType === 'REIMBURSEMENT') {
                            $submitUrl = '/reimbursement/submit.php';
                        } elseif ($requestType === 'PETTY_CASH') {
                            $submitUrl = '/petty_cash/submit.php';
                        }
                        ?>
                        <?php if ($editUrl): ?>
                        <a href="<?= $editUrl ?>?id=<?= $request['request_id'] ?>" class="btn btn-warning">
                            <i class="bi bi-pencil-square me-1"></i>Edit Request
                        </a>
                        <?php elseif ($serviceContract): ?>
                        <a href="/contracts/view.php?id=<?= (int)$serviceContract['contract_id'] ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-file-earmark-text me-1"></i>View Contract
                        </a>
                        <?php endif; ?>
                        <a href="<?= $submitUrl ?>?id=<?= $request['request_id'] ?>"
                           class="btn btn-primary" onclick="return confirm('Submit this request?')">
                            <i class="bi bi-send me-1"></i>Submit Request
                        </a>
                    <?php endif; ?>

                    <?php 
                    // For SUBMITTED and other stages with pending approval, check if user can approve
                    $userCanApprove = false;
                    $approvalEndpoint = null;
                    $approvalLabel = null;
                    $approvalIcon = null;
                    $procurementEditableStatuses = ['SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 
                        'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 
                        'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'COMMITMENT_DECLINED'];
                    $canSendBackForEdit = false;
                    
                    // Check if there's a pending approval for this user (regardless of current status)
                    if ($current !== 'PAUSED' && $nextApproverRole && $nextApprovalId && hasPermission('approve_request')) {
                        $userCanApprove = canApproveStage($role, $nextApproverRole, $estimatedValue);
                        
                        // Map role to endpoint
                        $roleEndpointMap = [
                            'HOD' => '/procurement/approve_hod.php',
                            'Director HRM&A' => '/procurement/approve.php',
                            'Deputy Government Chemist' => '/procurement/gc_approve.php',
                            'Finance Officer' => '/procurement/approve_finance.php',
                            'Branch Head' => '/procurement/approve_hod.php',
                            'Procurement Officer' => '/procurement/approve_finance.php',
                        ];
                        
                        $approvalEndpoint = $roleEndpointMap[$nextApproverRole] ?? '/procurement/approve.php';
                        
                        // Build label based on request type and role
                        if ($requestType === 'PETTY_CASH') {
                            $approvalLabel = match($nextApproverRole) {
                                'HOD' => 'Authorize Petty Cash',
                                'Finance Officer' => 'Process Petty Cash',
                                'Procurement Officer' => 'Verify Petty Cash',
                                default => 'Approve'
                            };
                        } elseif ($requestType === 'REIMBURSEMENT') {
                            $approvalLabel = match($nextApproverRole) {
                                'Branch Head' => 'Authorize Reimbursement',
                                'Procurement Officer' => 'Verify Reimbursement',
                                'Finance Officer' => 'Process Reimbursement',
                                default => 'Approve'
                            };
                        } else {
                            // Regular procurement
                            $approvalLabel = match($nextApproverRole) {
                                'HOD' => 'Approve (HOD)',
                                'Director HRM&A' => 'Approve (Director)',
                                'Deputy Government Chemist' => 'Approve (GC)',
                                'Finance Officer' => 'Verify Funds',
                                default => 'Approve'
                            };
                        }
                        
                        $approvalIcon = match($nextApproverRole) {
                            'HOD' => 'bi-person-check',
                            'Director HRM&A' => 'bi-briefcase-fill',
                            'Deputy Government Chemist' => 'bi-building-check',
                            'Finance Officer' => 'bi-cash-coin',
                            'Branch Head' => 'bi-person-check',
                            'Procurement Officer' => 'bi-clipboard-check',
                            default => 'bi-check-circle'
                        };
                    }

                    $canSendBackForEdit = $current !== 'PAUSED' && (
                        (
                            $nextApproverRole
                            && $nextApprovalId
                            && $userCanApprove
                            && in_array($role, ['HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist', 'Government Chemist', 'Admin', 'SuperAdmin'], true)
                        )
                        || (
                            in_array($role, ['Procurement Officer', 'Admin', 'SuperAdmin'], true)
                            && in_array($current, $procurementEditableStatuses, true)
                            && !in_array($current, ['DRAFT', 'DECLINED', 'COMPLETED'], true)
                        )
                    );
                    ?>
                    
                    <?php $signedRequestPending = signedRequestUploadPending($request); ?>
                    <?php if ($nextApproverRole && $nextApprovalId && hasPermission('approve_request')): ?>
                        <?php if ($signedRequestPending): ?>
                            <button type="button" class="btn btn-outline-warning" disabled
                                    title="The requester must print, sign, and upload the signed request form before approval becomes available.">
                                <i class="bi bi-file-earmark-lock me-1"></i>Awaiting Signed Request Form
                            </button>
                        <?php elseif ($userCanApprove): ?>
                            <a href="<?= $approvalEndpoint ?>?id=<?= $request['request_id'] ?>"
                               class="btn btn-success" onclick="return confirm('Approve this <?= strtolower(trim($typeLabel, '💰💵📋 ')) ?>?')">
                                <i class="bi <?= $approvalIcon ?> me-1"></i><?= $approvalLabel ?>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-success" disabled title="Awaiting approval from <?= htmlspecialchars($nextApproverRole) ?> (Your role: <?= htmlspecialchars($role) ?>)">
                                <i class="bi bi-hourglass-split me-1"></i>Pending <?= htmlspecialchars($nextApproverRole) ?> Approval
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($current === 'SUBMITTED' && hasPermission('approve_request')): ?>
                        <button type="button" class="btn btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#declineModal">
                            <i class="bi bi-x-octagon me-1"></i>Decline
                        </button>
                    <?php endif; ?>

                    <?php if ($canSendBackForEdit): ?>
                        <button type="button" class="btn btn-outline-warning"
                                data-bs-toggle="modal" data-bs-target="#sendBackModal">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Send Back for Edit
                        </button>
                    <?php endif; ?>

                    <?php
                    // Revert Stage — available to authorized roles when backward transitions exist
                    $canRevertStage = $current !== 'PAUSED' && in_array($role, allowedRevertRoles(), true)
                        && !in_array($current, ['DRAFT', 'COMPLETED', 'DECLINED', 'CANCELLED'], true);
                    $revertTargets = [];
                    if ($canRevertStage) {
                        foreach (allowedTransitions()[$current] ?? [] as $t) {
                            if (isBackwardTransition($current, $t)) {
                                $revertTargets[] = $t;
                            }
                        }
                        $canRevertStage = !empty($revertTargets);
                    }
                    ?>
                    <?php if ($canRevertStage): ?>
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#revertStageModal">
                            <i class="bi bi-skip-backward me-1"></i>Revert Stage
                        </button>
                    <?php endif; ?>

                    <?php
                    // Request cancellation — allowed at any non-terminal stage
                    $canCancelRequest = $current !== 'PAUSED' && canTransition($current, 'CANCELLED')
                        && (
                            $request['created_by'] == $_SESSION['user_id']
                            || hasPermission('approve_request')
                            || in_array($role, ['Admin', 'SuperAdmin', 'Procurement Officer'], true)
                        );
                    ?>
                    <?php if ($canCancelRequest): ?>
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#cancelRequestModal">
                            <i class="bi bi-slash-circle me-1"></i>Cancel Request
                        </button>
                    <?php endif; ?>

                    <?php
                    $canPauseResume = (
                        $request['created_by'] == $_SESSION['user_id']
                        || hasPermission('approve_request')
                        || in_array($role, ['Admin', 'SuperAdmin', 'Procurement Officer'], true)
                    );
                    ?>
                    <?php if ($canPauseResume && !in_array($current, ['DRAFT', 'COMPLETED', 'DECLINED', 'CANCELLED'], true)): ?>
                        <button type="button"
                                class="btn <?= $current === 'PAUSED' ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#pauseResumeModal">
                            <i class="bi <?= $current === 'PAUSED' ? 'bi-play-circle' : 'bi-pause-circle' ?> me-1"></i><?= $current === 'PAUSED' ? 'Resume Procurement' : 'Pause Procurement' ?>
                        </button>
                    <?php endif; ?>

                    <?php // --- Action buttons for RFQ / Quote workflow stages ---
                    if (in_array($current, ['RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED']) && $rfqId): ?>
                        <a href="/rfq/view.php?id=<?= (int)$rfqId ?>" class="btn btn-info">
                            <i class="bi bi-eye me-1"></i><?= $current === 'QUOTE_REVIEW_PENDING' ? 'Review Quotes' : 'View RFQ Workflow' ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($current === 'QUOTE_APPROVED'): ?>
                        <a href="/commitments/add.php?request_id=<?= $request['request_id'] ?>" class="btn btn-success">
                            <i class="bi bi-cash-coin me-1"></i>Verify Funds & Start Commitment
                        </a>
                    <?php endif; ?>

                    <?php if ($current === 'FUNDS_VERIFIED' && !$nextApprovalId): ?>
                        <a href="/commitments/add.php?request_id=<?= $request['request_id'] ?>" class="btn <?= in_array($role, ['Finance Officer', 'Admin', 'SuperAdmin']) ? 'btn-primary' : 'btn-warning text-dark' ?>">
                            <i class="bi <?= in_array($role, ['Finance Officer', 'Admin', 'SuperAdmin']) ? 'bi-cloud-upload' : 'bi-paperclip' ?> me-1"></i>
                            <?= in_array($role, ['Finance Officer', 'Admin', 'SuperAdmin']) ? 'Upload Commitment Form / Create Commitment' : 'Upload Commitment Form (Optional)' ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($current === 'COMMITMENTS_PENDING'): ?>
                        <a href="/commitments/add.php?request_id=<?= $request['request_id'] ?>" class="btn btn-primary">
                            <i class="bi bi-cloud-upload me-1"></i>Create Commitment & Upload Document
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($current, ['AWARDED']) && !$originalCommitment): ?>
                        <a href="/commitments/add.php?request_id=<?= $request['request_id'] ?>" class="btn btn-success">
                            <i class="bi bi-cash-coin me-1"></i>Create Commitment (SOP Step 13)
                        </a>
                    <?php endif; ?>

                    <?php if ($current === 'COMMITMENT_APPROVED' && $originalCommitment && !$po && $requestType !== 'SERVICE_CONTRACT' && shouldIncludeCommitmentStages($originalCommitment)): ?>
                        <a href="/po/add.php?commitment_id=<?= (int)$originalCommitment['commitment_id'] ?>" class="btn btn-primary">
                            <i class="bi bi-file-earmark-text me-1"></i>Create Purchase Order
                        </a>
                    <?php elseif ($current === 'COMMITMENT_APPROVED' && $originalCommitment && !$po && $requestType !== 'SERVICE_CONTRACT' && !shouldIncludeCommitmentStages($originalCommitment)): ?>
                        <!-- Non-PO workflow: Direct to invoice after commitment -->
                        <a href="/invoice/add_service.php?commitment_id=<?= (int)$originalCommitment['commitment_id'] ?>" class="btn btn-primary">
                            <i class="bi bi-receipt me-1"></i>Add Invoice
                        </a>
                    <?php elseif ($current === 'COMMITMENT_APPROVED' && $requestType === 'SERVICE_CONTRACT'): ?>
                        <div class="alert alert-success py-2 mb-0 small">
                            <i class="bi bi-check-circle me-1"></i>Commitment created. Submit the invoice to proceed to payment.
                        </div>
                    <?php endif; ?>

                    <?php if (
                        $current === 'DECLINED' &&
                        (
                            ($request['created_by'] == $_SESSION['user_id'] && hasPermission('submit_request'))
                            || hasPermission('admin_override')
                        )
                    ): ?>
                        <?php
                        // Determine which resubmit endpoint to use
                        $resubmitUrl = '/procurement/resubmit.php';
                        if ($requestType === 'REIMBURSEMENT') {
                            $resubmitUrl = '/reimbursement/resubmit.php';
                        } elseif ($requestType === 'PETTY_CASH') {
                            $resubmitUrl = '/petty_cash/resubmit.php';
                        }
                        ?>
                        <a href="<?= $resubmitUrl ?>?id=<?= (int)$request['request_id'] ?>"
                           class="btn btn-warning" onclick="return confirm('Resubmit this request for approval?')">
                            <i class="bi bi-arrow-repeat me-1"></i>Resubmit Request
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     FINANCIAL PROGRESS (REGULAR ONLY)
═══════════════════════════════════════════════════════ -->
<?php if ($requestType === 'REGULAR' && ($originalCommitment || !empty($poVariations))): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Financial Progress</h5>
    </div>
    <div class="card-body">

        <?php if ($originalCommitment): ?>
        <!-- Original Commitment -->
        <div class="d-flex align-items-center gap-3 p-3 rounded-3 border <?= !empty($originalCommitment['approved_at']) ? 'border-success bg-success bg-opacity-10' : 'border-warning bg-warning bg-opacity-10' ?> mb-3">
            <div class="flex-shrink-0">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold
                    <?= !empty($originalCommitment['approved_at']) ? 'bg-success text-white' : 'bg-warning text-dark' ?>"
                     style="width: 40px; height: 40px;">
                    <?= !empty($originalCommitment['approved_at']) ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-hourglass-split"></i>' ?>
                </div>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-primary me-2">ORIGINAL</span>
                        <a href="/commitments/view.php?commitment_id=<?= (int)$originalCommitment['commitment_id'] ?>"
                           class="fw-bold text-decoration-none">
                            <?= htmlspecialchars($originalCommitment['commitment_number']) ?>
                        </a>
                    </div>
                    <div class="text-end">
                        <strong class="fs-5">JMD <?= number_format($originalCommitment['commitment_total'], 2) ?></strong>
                        <?= !empty($originalCommitment['approved_at'])
                            ? '<span class="badge bg-success ms-2">Approved</span>'
                            : '<span class="badge bg-warning text-dark ms-2">Pending</span>' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplementary Commitments -->
        <?php if (!empty($supplementaryCommitments)): ?>
        <h6 class="fw-bold text-muted mt-4 mb-3"><i class="bi bi-plus-circle me-1"></i>Supplementary Commitments</h6>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Commitment #</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplementaryCommitments as $sc): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/commitments/view.php?commitment_id=<?= (int)$sc['commitment_id'] ?>"
                               class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($sc['commitment_number']) ?>
                            </a>
                        </td>
                        <td class="fw-semibold">JMD <?= number_format($sc['commitment_total'], 2) ?></td>
                        <td><?= date('d M Y', strtotime($sc['commitment_date'])) ?></td>
                        <td class="text-center">
                            <?= !empty($sc['approved_at'])
                                ? '<span class="badge bg-success">Approved</span>'
                                : '<span class="badge bg-warning text-dark">Pending</span>' ?>
                        </td>
                        <td class="text-center">
                            <a href="/commitments/view.php?commitment_id=<?= (int)$sc['commitment_id'] ?>"
                               class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- PO Variations -->
        <?php if (!empty($poVariations)): ?>
        <h6 class="fw-bold text-muted mt-4 mb-3"><i class="bi bi-arrow-left-right me-1"></i>Purchase Order Variations</h6>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">PO #</th>
                        <th>Variation Amount</th>
                        <th>Reason</th>
                        <th class="text-center">Status</th>
                        <th>Supp. Commitment</th>
                        <th>Requested</th>
                        <th>Approved</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($poVariations as $pv): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/po/view.php?po_id=<?= (int)$pv['po_id'] ?>"
                               class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($pv['po_number']) ?>
                            </a>
                        </td>
                        <td class="fw-bold">JMD <?= number_format($pv['variation_amount'], 2) ?></td>
                        <td class="small"><?= htmlspecialchars($pv['reason']) ?></td>
                        <td class="text-center">
                            <?php if ($pv['status'] === 'APPROVED'): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php elseif ($pv['status'] === 'REJECTED'): ?>
                                <span class="badge bg-danger">Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                            <?php if ($pv['status'] === 'PENDING' && !$pv['supplementary_commitment_id']): ?>
                                <span class="badge bg-danger">No Funding</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pv['supplementary_commitment_id']): ?>
                                <a href="/commitments/view.php?commitment_id=<?= (int)$pv['supplementary_commitment_id'] ?>"
                                   class="badge bg-info text-decoration-none">
                                    <?= htmlspecialchars($pv['commitment_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= date('d M Y', strtotime($pv['requested_at'])) ?></td>
                        <td class="small">
                            <?= $pv['approved_at']
                                ? date('d M Y', strtotime($pv['approved_at']))
                                : '<span class="text-muted">&mdash;</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     REQUEST DOCUMENTS (Signed POs, Commitments, etc.)
═══════════════════════════════════════════════════════ -->
<?php
// Fetch uploaded request documents
$docStmt = $pdo->prepare("
    SELECT rd.*, u.full_name AS uploader_name
    FROM request_documents rd
    LEFT JOIN users u ON rd.uploaded_by = u.user_id
    WHERE rd.request_id = ? AND rd.is_deleted = 0
    ORDER BY rd.uploaded_at DESC
");
$docStmt->execute([$request_id]);
$requestDocuments = $docStmt->fetchAll(PDO::FETCH_ASSOC);
$canDeleteRequestDocument = hasPermission('procurement_delete_request_document');
?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Request Documents</h5>
        <span class="badge bg-light text-dark"><?= count($requestDocuments) ?> document<?= count($requestDocuments) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body">
        <?php if (count($requestDocuments) > 0): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th >Type</th>
                        <th >File</th>
                        <th >Uploaded By</th>
                        <th>Date</th>
                        <th >Notes</th>
                        <th ></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requestDocuments as $doc): ?>
                    <tr>
                        <td>
                            <?php
                            $typeLabelsDoc = [
                                'SIGNED_PO' => '<span class="badge bg-primary">Signed PO</span>',
                                'SIGNED_COMMITMENT' => '<span class="badge bg-success">Signed Commitment</span>',
                                'SIGNED_REQUEST' => '<span class="badge bg-info text-dark">Signed Request</span>',
                                'MEMO' => '<span class="badge bg-warning text-dark">Supporting Memo</span>',
                                'OTHER' => '<span class="badge bg-secondary">Other</span>',
                            ];
                            echo $typeLabelsDoc[$doc['document_type']] ?? '<span class="badge bg-secondary">Other</span>';
                            ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($doc['document_name']) ?></td>
                        <td class="small"><?= htmlspecialchars($doc['uploader_name'] ?? 'N/A') ?></td>
                        <td class="small"><?= date('d M Y H:i', strtotime($doc['uploaded_at'])) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($doc['notes'] ?? '') ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($doc['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php if ($canDeleteRequestDocument): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#deleteDocumentModal"
                                    data-document-id="<?= (int)$doc['document_id'] ?>"
                                    data-document-name="<?= htmlspecialchars($doc['document_name']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted mb-3"><i class="bi bi-info-circle"></i> No documents uploaded yet.</p>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="border-top pt-3">
            <h6 class="fw-bold"><i class="bi bi-cloud-upload me-1"></i> Upload Document</h6>
            <form method="post" action="/procurement/upload_document.php" enctype="multipart/form-data">
                <input type="hidden" name="request_id" value="<?= $request_id ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small">Document Type</label>
                        <select name="document_type" class="form-select form-select-sm" required>
                            <option value="MEMO">Supporting Memo</option>
                            <option value="SIGNED_PO">Signed Purchase Order</option>
                            <option value="SIGNED_COMMITMENT">Signed Commitment</option>
                            <option value="SIGNED_REQUEST">Signed Request Form</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">File (PDF, Word, Excel)</label>
                        <input type="file" name="document_file" class="form-control form-control-sm"
                               accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Notes (optional)</label>
                        <input type="text" name="notes" class="form-control form-control-sm"
                               placeholder="Brief note..." maxlength="255">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-upload me-1"></i> Upload
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($canDeleteRequestDocument): ?>
<!-- ═══════════════════════════════════════════════════════
     DELETE DOCUMENT MODAL
═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="deleteDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/delete_document.php" class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="request_id" value="<?= $request_id ?>">
                <input type="hidden" name="document_id" id="deleteDocumentId" value="">
                <p>Are you sure you want to delete <strong id="deleteDocumentName"></strong>?</p>
                <p class="text-muted small">The file will be retained for audit purposes; this only removes it from the active documents list.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason for deletion</label>
                    <textarea name="reason" class="form-control" rows="3" required
                              placeholder="Provide a reason for deleting this document..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Confirm Delete</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var deleteDocumentModal = document.getElementById('deleteDocumentModal');
    if (deleteDocumentModal) {
        deleteDocumentModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('deleteDocumentId').value = button.getAttribute('data-document-id');
            document.getElementById('deleteDocumentName').textContent = button.getAttribute('data-document-name');
        });
    }
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     QUICK LINKS (TYPE-SPECIFIC)
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Quick Links</h5>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <!-- COMMON LINKS (ALL TYPES) -->
            <?php 
            // Allow Procurement Officers to edit requests at most stages
            $isProcurementOrAdmin = in_array($role, ['Procurement Officer', 'Admin', 'SuperAdmin']);
            $procurementEditableStatuses = ['DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 
                'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 
                'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED', 'COMMITMENT_DECLINED'];
            $canEdit = ($status === 'DRAFT') || ($isProcurementOrAdmin && in_array($status, $procurementEditableStatuses));
            ?>
            <?php if ($canEdit): ?>
            <a href="/procurement/edit.php?id=<?= (int)$request['request_id'] ?>"
               class="btn btn-warning btn-sm">
                <i class="bi bi-pencil-square me-1"></i>Edit Request
            </a>
            <?php endif; ?>
            <a href="/audit/view.php?table=procurement_requests&id=<?= (int)$request['request_id'] ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-journal-text me-1"></i>Audit Trail
            </a>
            <a href="/reports/print_request.php?request_id=<?= $request['request_id'] ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print PDF
            </a>

            <?php if ($requestType === 'REGULAR'): ?>
                <!-- REGULAR PROCUREMENT LINKS -->
                <?php if (!$originalCommitment && in_array($status, ['QUOTE_APPROVED','AWARDED','COMPLETED'])): ?>
                    <a href="/commitments/add.php?request_id=<?= $request['request_id'] ?>"
                       class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>Add Commitment (SOP Step 13)
                    </a>
                <?php elseif ($originalCommitment): ?>
                    <a href="/commitments/view.php?commitment_id=<?= (int)$originalCommitment['commitment_id'] ?>"
                       class="btn btn-outline-info btn-sm">
                        <i class="bi bi-cash-coin me-1"></i>View Commitment
                    </a>

                <?php endif; ?>

                <?php if ($originalCommitment && !$po): ?>
                    <a href="/po/add.php?commitment_id=<?= $originalCommitment['commitment_id'] ?>"
                       class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg me-1"></i>Create Purchase Order
                    </a>
                <?php elseif (!empty($po)): ?>
                    <a href="/po/view.php?po_id=<?= $po['po_id'] ?>"
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-file-earmark-check me-1"></i>View PO: <?= htmlspecialchars($po['po_number']) ?>
                    </a>

                    <?php if ($po['status'] !== 'Closed'): ?>
                        <a href="/invoice/add.php?po_id=<?= $po['po_id'] ?>"
                           class="btn btn-warning btn-sm">
                            <i class="bi bi-receipt me-1"></i>Add Invoice
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php 
                // Check if RFQ is needed based on threshold (show RFQ create for over-threshold)
                $needsRfq = !isDirectProcurement($requestType, $estimatedValue);
                if (in_array($current, ['SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'FUNDS_VERIFIED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'AWARDED', 'COMPLETED']) || ($needsRfq && in_array($current, ['SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED']))): ?>
                    
                    <!-- ✅ UPDATED: RFQ Letter Generation available after submission or approval (not just after RFQ creation) -->
                    <?php if ($needsRfq && in_array($current, ['SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'FUNDS_VERIFIED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'AWARDED'])): ?>
                        <?php if ($rfqId): ?>
                            <a href="/rfq/view.php?id=<?= $rfqId ?>" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-file-earmark-text me-1"></i>View RFQ
                            </a>
                            <a href="/rfq/generate_rtf.php?id=<?= $rfqId ?>" target="_blank" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-file-pdf me-1"></i>Generate RFQ Letters
                            </a>
                        <?php elseif (in_array($current, ['SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'FUNDS_VERIFIED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE'])): ?>
                            <!-- ✅ NEW: Create RFQ after submission (all regular procurement needs RFQ) -->
                            <a href="/rfq/create.php?request_id=<?= $request['request_id'] ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>Create RFQ & Generate Letters
                            </a>
                            <?php
                            // RFQ is optional for requests at or below the JMD threshold —
                            // Procurement may proceed without an RFQ where appropriate.
                            // $estimatedValue is already converted to JMD above.
                            $rfqOptional = $estimatedValue <= getDirectProcurementThreshold($pdo);
                            ?>
                            <?php if ($rfqOptional && $current !== 'SUBMITTED' && in_array($role, ['Procurement Officer', 'Admin', 'SuperAdmin'], true)): ?>
                                <a href="/procurement/skip_rfq.php?id=<?= $request['request_id'] ?>"
                                   class="btn btn-outline-secondary btn-sm"
                                   onclick="return confirm('You are proceeding without an RFQ. The request will move to Awarded, and you will still need to complete Commitment, Purchase Order, Invoice, and Payment steps before the request can be closed. Continue?')">
                                    <i class="bi bi-skip-forward me-1"></i>Proceed Without RFQ (Optional)
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- Original RFQ button (legacy support) -->
                    <?php if (in_array($current, ['PROCUREMENT_STAGE', 'EVALUATION_STAGE', 'COMMITTEE_RECOMMENDED', 'GC_APPROVED', 'AWARDED', 'COMPLETED']) && !$rfqId && $needsRfq): ?>
                        <a href="/rfq/create.php?request_id=<?= $request['request_id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i>Create RFQ (SOP Step 6)
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($requestType === 'REIMBURSEMENT'): ?>
                <!-- REIMBURSEMENT LINKS -->
                <a href="/reimbursement/list.php"
                   class="btn btn-outline-info btn-sm">
                    <i class="bi bi-list me-1"></i>All Reimbursements
                </a>
                <?php if ($status === 'DRAFT' || $status === 'SUBMITTED'): ?>
                    <a href="/reimbursement/edit.php?id=<?= $request['request_id'] ?>"
                       class="btn btn-warning btn-sm">
                        <i class="bi bi-pencil-square me-1"></i>Edit Details
                    </a>
                <?php endif; ?>
                <?php if (in_array($status, ['INVOICE_VERIFIED', 'APPROVED', 'REIMBURSED', 'COMPLETED'])): ?>
                    <a href="/invoice/list.php?request_id=<?= $request['request_id'] ?>"
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-receipt me-1"></i>View Invoices
                    </a>
                <?php endif; ?>

            <?php elseif ($requestType === 'PETTY_CASH'): ?>
                <!-- PETTY CASH LINKS -->
                <a href="/petty_cash/list.php"
                   class="btn btn-outline-info btn-sm">
                    <i class="bi bi-list me-1"></i>All Petty Cash
                </a>
                <?php if ($status === 'DRAFT' || $status === 'SUBMITTED'): ?>
                    <a href="/petty_cash/edit.php?id=<?= $request['request_id'] ?>"
                       class="btn btn-warning btn-sm">
                        <i class="bi bi-pencil-square me-1"></i>Edit Details
                    </a>
                <?php endif; ?>
                <?php if (in_array($status, ['FINANCE_AUTHORIZED', 'DISBURSED', 'PENDING_RECONCILIATION', 'COMPLETED'])): ?>
                    <a href="/petty_cash/reconcile.php?id=<?= $request['request_id'] ?>"
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-check2-circle me-1"></i>Reconciliation
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SIGNED REQUEST MANAGEMENT
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-file-earmark-pdf me-2"></i>Signed Request Management</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-printer me-1"></i> Branch Head Signing
                </h6>
                <div class="alert alert-info mb-3" style="font-size: 0.9rem;">
                    <strong>Workflow:</strong> Branch head prints request, signs it, then uploads the signed copy for procurement processing.
                </div>
                
                <!-- Print for Signing Button -->
                <a href="/procurement/print_for_signing.php?id=<?= $request_id ?>"
                   target="_blank" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-printer me-1"></i> Print Request for Signing
                </a>
            </div>
            
            <div class="col-md-6">
                <h6 class="fw-bold mb-2">
                    <i class="bi bi-check-circle me-1"></i> Signed Request Status
                </h6>
                
                <?php if (!empty($request['signed_request_document_path'])): ?>
                    <!-- Signed Request Received -->
                    <div class="alert alert-success mb-3" style="font-size: 0.9rem;">
                        <strong><i class="bi bi-check-circle-fill me-1"></i> Received</strong><br>
                        <small class="text-muted d-block mt-1">
                            Received: <?= date('d M Y H:i', strtotime($request['signed_request_received_date'])) ?>
                        </small>
                    </div>
                    
                    <!-- View Signed Document -->
                    <a href="<?= htmlspecialchars($request['signed_request_document_path']) ?>"
                       target="_blank" class="btn btn-outline-success btn-sm w-100">
                        <i class="bi bi-download me-1"></i> View Signed Document
                    </a>
                    
                    <!-- Option to Upload New Version -->
                    <button class="btn btn-outline-secondary btn-sm w-100 mt-2" 
                           data-bs-toggle="collapse" data-bs-target="#uploadSignedRequest">
                        <i class="bi bi-upload me-1"></i> Upload Revised Version
                    </button>
                <?php else: ?>
                    <!-- No Signed Request Yet -->
                    <div class="alert alert-warning mb-3" style="font-size: 0.9rem;">
                        <strong>⏳ Pending</strong><br>
                        <small class="text-muted d-block mt-1">Waiting for signed request to be uploaded</small>
                    </div>
                    
                    <!-- Upload Signed Request Form -->
                    <button class="btn btn-warning btn-sm w-100"
                           data-bs-toggle="collapse" data-bs-target="#uploadSignedRequest">
                        <i class="bi bi-cloud-upload me-1"></i> Upload Signed Request
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Upload Signed Request Form (Collapsed) -->
        <div class="collapse mt-3" id="uploadSignedRequest">
            <div class="card card-body bg-light">
                <h6 class="fw-bold mb-3">Upload Signed Request</h6>
                <form method="post" action="/procurement/upload_signed_request.php" enctype="multipart/form-data">
                    <input type="hidden" name="request_id" value="<?= $request_id ?>">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">
                            <i class="bi bi-file-earmark me-1"></i> Signed Document
                            <span class="text-danger">*</span>
                        </label>
                        <input type="file" name="signed_request_file" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx" required>
                        <small class="text-muted d-block mt-1">
                            Accepted: PDF, Images (JPG/PNG/GIF), Word documents. Max 25 MB.
                        </small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-cloud-check me-1"></i> Upload Signed Request
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                               data-bs-toggle="collapse" data-bs-target="#uploadSignedRequest">
                            Cancel
                        </button>
                    </div>
                </form>
                
                <div class="alert alert-info mt-3 mb-0" style="font-size: 0.85rem;">
                    <strong>How to prepare:</strong>
                    <ol class="mb-0 mt-1 ps-3">
                        <li>Print the PDF request document</li>
                        <li>Review all details carefully</li>
                        <li>Sign and date in the Authorization section</li>
                        <li>Scan or photograph the signed document</li>
                        <li>Upload the file here</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     REQUEST TIMELINE
═══════════════════════════════════════════════════════ -->
<?php
function timelineMeta(string $action): array {
    $action = strtoupper($action);
    return match (true) {
        str_contains($action, 'PO_VARIATION')  => ['icon' => 'bi-arrow-left-right', 'color' => 'text-info',    'label' => 'PO Variation'],
        str_contains($action, 'SUPPLEMENTARY') => ['icon' => 'bi-plus-circle',      'color' => 'text-primary', 'label' => 'Supplementary'],
        str_contains($action, 'COMMITMENT')    => ['icon' => 'bi-cash-coin',        'color' => 'text-success', 'label' => 'Commitment'],
        str_contains($action, 'PO_')           => ['icon' => 'bi-file-earmark',     'color' => 'text-dark',    'label' => 'Purchase Order'],
        str_contains($action, 'INVOICE')       => ['icon' => 'bi-receipt',          'color' => 'text-warning', 'label' => 'Invoice'],
        str_contains($action, 'DECLINE')       => ['icon' => 'bi-x-octagon',        'color' => 'text-danger',  'label' => 'Declined'],
        str_contains($action, 'APPROVE')       => ['icon' => 'bi-check-circle',     'color' => 'text-success', 'label' => 'Approved'],
        str_contains($action, 'SUBMIT')        => ['icon' => 'bi-send',             'color' => 'text-primary', 'label' => 'Submitted'],
        default                                => ['icon' => 'bi-clock-history',     'color' => 'text-secondary','label' => 'Update'],
    };
}
?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Request Timeline</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($timeline)): ?>
            <div class="text-center py-4">
                <i class="bi bi-clock text-muted fs-3"></i>
                <p class="text-muted mt-2 mb-0">No timeline events recorded.</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($timeline as $idx => $e): ?>
                    <?php $meta = timelineMeta($e['action']); ?>
                    <div class="list-group-item px-4 py-3 <?= $idx === 0 ? '' : 'border-top' ?>">
                        <div class="d-flex gap-3">
                            <div class="flex-shrink-0 pt-1">
                                <div class="rounded-circle d-flex align-items-center justify-content-center <?= $meta['color'] ?>"
                                     style="width: 36px; height: 36px; background: currentColor; background: rgba(0,0,0,.06);">
                                    <i class="bi <?= $meta['icon'] ?> <?= $meta['color'] ?>"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars(str_replace('_', ' ', $e['action'])) ?></strong>
                                        <span class="badge bg-light text-dark border ms-1 small"><?= $meta['label'] ?></span>
                                    </div>
                                    <div class="text-muted small text-end text-nowrap">
                                        <?= date('d M Y', strtotime($e['created_at'])) ?><br>
                                        <span class="text-muted"><?= date('h:i A', strtotime($e['created_at'])) ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($e['notes'])): ?>
                                    <div class="text-muted small mt-1"><?= htmlspecialchars($e['notes']) ?></div>
                                <?php endif; ?>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($e['full_name'] ?? 'System') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     NAVIGATION
═══════════════════════════════════════════════════════ -->
<div class="d-flex gap-2 mb-4">
    <a href="/procurement/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>
    <a href="javascript:history.back()" class="btn btn-outline-secondary">
        Cancel
    </a>
</div>

</div><!-- /.container -->

<!-- ═══════════════════════════════════════════════════════
     DECLINE MODAL
═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="declineModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/decline.php" class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-octagon me-2"></i>Decline Procurement Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $request['request_id'] ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason for decline</label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Provide a detailed reason for declining this request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Confirm Decline</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     CANCEL REQUEST MODAL
═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="cancelRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/cancel.php" class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Cancel Procurement Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $request['request_id'] ?>">
                <p class="text-muted small mb-3">Cancellation stops this request permanently. The reason will be recorded in the audit trail and stakeholders will be notified.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason for cancellation <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Provide a detailed reason for cancelling this request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-slash-circle me-1"></i>Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="pauseResumeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/pause_resume.php" class="modal-content">
            <div class="modal-header <?= $current === 'PAUSED' ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                <h5 class="modal-title">
                    <i class="bi <?= $current === 'PAUSED' ? 'bi-play-circle' : 'bi-pause-circle' ?> me-2"></i><?= $current === 'PAUSED' ? 'Resume Procurement' : 'Pause Procurement' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= (int)$request['request_id'] ?>">
                <input type="hidden" name="action" value="<?= $current === 'PAUSED' ? 'resume' : 'pause' ?>">
                <p class="text-muted small mb-3">A reason is required and will be recorded in the audit trail. Participants and stakeholders will be notified.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Enter the reason for this <?= $current === 'PAUSED' ? 'resume' : 'pause' ?> action..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn <?= $current === 'PAUSED' ? 'btn-success' : 'btn-warning' ?>">
                    <i class="bi <?= $current === 'PAUSED' ? 'bi-play-circle' : 'bi-pause-circle' ?> me-1"></i><?= $current === 'PAUSED' ? 'Confirm Resume' : 'Confirm Pause' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="sendBackModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/send_back.php" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Send Back for Edit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $request['request_id'] ?>">
                <p class="text-muted small mb-3">This returns the request to draft so it can be updated and submitted again.</p>
                <div class="mb-0">
                    <label class="form-label fw-bold">Reason for sending back</label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="State what needs to be corrected before resubmission..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Send Back</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canRevertStage ?? false): ?>
<div class="modal fade" id="revertStageModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="/procurement/revert_status.php" class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-skip-backward me-2"></i>Revert Workflow Stage</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $request['request_id'] ?>">
                <div class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Reverting a workflow stage moves the request backwards. Any approvals granted
                    for stages after the target will need to be re-completed.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Revert to Stage</label>
                    <select name="target_status" class="form-select" required>
                        <option value="">— Select target stage —</option>
                        <?php foreach ($revertTargets ?? [] as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $t)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required
                              placeholder="Explain why this request is being moved back to a prior stage..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-secondary"
                        onclick="return confirm('Revert this request to the selected stage?')">
                    <i class="bi bi-skip-backward me-1"></i>Revert Stage
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>