<?php
$REQUIRE_PERMISSION = 'view_approval_que';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$userRole = $_SESSION['role'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ═══════════════════════════════════════════════════════════════
   1. Pending Request Approvals (from request_approvals table)
═══════════════════════════════════════════════════════════════ */
$requestStmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        pr.created_at,
        ra.role as required_role,
        ra.stage_order,
        b.branch_name,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = ?
      AND ra.status = 'pending'
    ORDER BY ra.created_at ASC
");
$requestStmt->execute([$userRole]);
$pendingRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════
   2. Commitments & POs — no longer need approval (auto-approved)
═══════════════════════════════════════════════════════════════ */
$pendingCommitments = [];
$pendingPOs = [];

/* ═══════════════════════════════════════════════════════════════
   3. Workflow Actions Required (filtered by role using stageOwner)
═══════════════════════════════════════════════════════════════ */
$allWorkflowStatuses = [
    'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
    'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED',
    'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
    'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'
];
$myStatuses = [];
foreach ($allWorkflowStatuses as $st) {
    if (in_array($userRole, stageOwner($st))) {
        $myStatuses[] = $st;
    }
}
$workflowActions = [];
if (!empty($myStatuses)) {
    $placeholders = implode(',', array_fill(0, count($myStatuses), '?'));
    // Branch filtering: Director HRM&A sees only branch 5, Deputy GC sees only branch 6
    $branchFilter = '';
    $branchParams = [];
    if ($userRole === 'Director HRM&A') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [5];
    } elseif ($userRole === 'Deputy Government Chemist') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [6];
    }
    $workflowStmt = $pdo->prepare("
        SELECT 
            pr.request_id,
            pr.request_number,
            pr.request_type,
            pr.estimated_value,
            pr.currency,
            pr.status as request_status,
            pr.created_at,
            b.branch_name,
            u.full_name as requestor_name
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE UPPER(pr.status) IN ($placeholders)
        $branchFilter
        ORDER BY pr.created_at ASC
    ");
    $workflowStmt->execute(array_merge($myStatuses, $branchParams));
    $workflowActions = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
}

$statusActionMap = [
    'PROCUREMENT_STAGE'    => ['label' => 'Create RFQ', 'color' => '#6c757d', 'icon' => 'bi-cart-plus'],
    'EVALUATION_STAGE'     => ['label' => 'Evaluate RFQ', 'color' => '#fd7e14', 'icon' => 'bi-clipboard-check'],
    'RFQ_LETTER_AVAILABLE' => ['label' => 'Move to Quote Review', 'color' => '#4facfe', 'icon' => 'bi-envelope-open'],
    'QUOTE_REVIEW_PENDING' => ['label' => 'Review Quotes', 'color' => '#fa709a', 'icon' => 'bi-search'],
    'QUOTE_APPROVED'       => ['label' => 'Create Commitment', 'color' => '#43e97b', 'icon' => 'bi-plus-circle'],
    'COMMITTEE_RECOMMENDED'=> ['label' => 'GC Approval Required', 'color' => '#f093fb', 'icon' => 'bi-shield-check'],
    'GC_APPROVED'          => ['label' => 'Ready for Award', 'color' => '#20c997', 'icon' => 'bi-trophy'],
    'COMMITMENT_APPROVED'  => ['label' => 'Create Purchase Order', 'color' => '#667eea', 'icon' => 'bi-file-earmark-plus'],
    'COMMITMENT_DECLINED'  => ['label' => 'Revise & Resubmit', 'color' => '#f5576c', 'icon' => 'bi-arrow-repeat'],
];

/* Counts */
$cntRequests = count($pendingRequests);
$cntWorkflow = count($workflowActions);
$totalAll = $cntRequests + $cntWorkflow;
?>

<div class="container-fluid" style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-clock-history me-2"></i>Approval Queue</h3>
        <small class="text-muted">All pending approvals and workflow actions across the system</small>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-primary fs-6 py-2 px-3"><?= $totalAll ?> Total Pending</span>
        <a href="/dashboard/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="border-left: 5px solid #667eea !important;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#667eea;">Requests</small>
                <h2 class="mb-0 fw-bold"><?= $cntRequests ?></h2>
                <small class="text-muted">awaiting approval</small>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="border-left: 5px solid #43e97b !important;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#43e97b;">Workflow Actions</small>
                <h2 class="mb-0 fw-bold"><?= $cntWorkflow ?></h2>
                <small class="text-muted">need attention</small>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PENDING REQUESTS
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Pending Request Approvals</h5>
        <span class="badge bg-light text-dark"><?= $cntRequests ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pendingRequests)): ?>
            <div class="text-center py-4 text-muted"><i class="bi bi-check-circle fs-3"></i><p class="mt-2 mb-0">No pending request approvals</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Request #</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th>Requestor</th>
                        <th class="text-end">Amount</th>
                        <th>Awaiting</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $r): 
                        $canAct = ($r['required_role'] === $userRole);
                    ?>
                    <tr class="<?= $canAct ? 'table-warning' : '' ?>">
                        <td class="px-3 fw-bold"><?= htmlspecialchars($r['request_number']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= $r['request_type'] ?></span></td>
                        <td><?= htmlspecialchars($r['branch_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($r['requestor_name'] ?? 'N/A') ?></td>
                        <td class="text-end fw-semibold"><?= htmlspecialchars($r['currency'] ?? 'JMD') ?> <?= number_format($r['estimated_value'], 2) ?></td>
                        <td>
                            <span class="badge <?= $canAct ? 'bg-warning text-dark' : 'bg-info' ?>"><?= htmlspecialchars($r['required_role']) ?></span>
                            <?php if ($canAct): ?><small class="d-block text-success fw-bold">&larr; You</small><?php endif; ?>
                        </td>
                        <td><span class="badge bg-primary-subtle text-primary"><?= str_replace('_', ' ', $r['request_status']) ?></span></td>
                        <td><small class="text-muted"><?= date('d M Y', strtotime($r['created_at'])) ?></small></td>
                        <td class="text-center">
                            <a href="/procurement/view.php?id=<?= $r['request_id'] ?>" class="btn btn-sm <?= $canAct ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-eye me-1"></i><?= $canAct ? 'Review' : 'View' ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     WORKFLOW ACTIONS REQUIRED
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Workflow Actions Required</h5>
        <span class="badge bg-light text-dark"><?= $cntWorkflow ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($workflowActions)): ?>
            <div class="text-center py-4 text-muted"><i class="bi bi-check-circle fs-3"></i><p class="mt-2 mb-0">No pending workflow actions</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Request #</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th>Requestor</th>
                        <th class="text-end">Amount</th>
                        <th>Current Status</th>
                        <th>Next Action</th>
                        <th>Date</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workflowActions as $w):
                        $st = strtoupper($w['request_status']);
                        $action = $statusActionMap[$st] ?? ['label' => $st, 'color' => '#999', 'icon' => 'bi-question-circle'];
                    ?>
                    <tr>
                        <td class="px-3 fw-bold"><?= htmlspecialchars($w['request_number']) ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary"><?= $w['request_type'] ?></span></td>
                        <td><?= htmlspecialchars($w['branch_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($w['requestor_name'] ?? 'N/A') ?></td>
                        <td class="text-end fw-semibold"><?= htmlspecialchars($w['currency'] ?? 'JMD') ?> <?= number_format($w['estimated_value'], 2) ?></td>
                        <td><span class="badge" style="background-color: <?= $action['color'] ?>; color: white;"><?= str_replace('_', ' ', $st) ?></span></td>
                        <td>
                            <span style="font-size: 0.85rem; font-weight: 600; color: <?= $action['color'] ?>;">
                                <i class="bi <?= $action['icon'] ?> me-1"></i><?= $action['label'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d M Y', strtotime($w['created_at'])) ?></small></td>
                        <td class="text-center">
                            <a href="/procurement/view.php?id=<?= $w['request_id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi <?= $action['icon'] ?> me-1"></i>Take Action
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
