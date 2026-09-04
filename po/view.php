<?php
$REQUIRE_PERMISSION = 'view_purchase_orders';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
$warning = false;

/* ================================
   Validate po_id
================================ */
$po_id = $_GET['po_id'] ?? null;

if (!is_numeric($po_id) || (int)$po_id <= 0) {
    pop("Missing Purchase Order ID.", "/po/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

$po_id = (int)$po_id;

/* ================================
   Fetch PO + Commitment
================================ */
$stmt = $pdo->prepare("
    SELECT 
        po.*,
        c.commitment_number,
        c.commitment_total
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    WHERE po.po_id = ?
    LIMIT 1
");
$stmt->execute([$po_id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$po) {
    pop("Purchase Order not found.", "/po/list.php", POP_DEFAULT_DELAY_MS);
    exit;
}

$requestScopeStmt = $pdo->prepare("
    SELECT pr.request_id, pr.created_by, pr.status
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
    LIMIT 1
");
$requestScopeStmt->execute([$po_id]);
$requestScope = $requestScopeStmt->fetch(PDO::FETCH_ASSOC);

if (!$requestScope) {
    pop("Linked procurement request not found.", "/po/list.php", POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($requestScope, "/po/list.php");

/* ================================
   Check for PO limit warning
================================ */
try {
    $warnStmt = $pdo->prepare("
        SELECT created_at FROM po_warnings
        WHERE po_id = ? AND warning_type = 'PO_LIMIT_ATTEMPT'
        ORDER BY created_at DESC LIMIT 1
    ");
    $warnStmt->execute([$po_id]);
    $warning = $warnStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("PO view warning query failed (po_id=$po_id): " . $e->getMessage());
    $warning = false;
}

/* ================================
   Fetch Approval Progress
================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_stages,
        SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_stages
    FROM request_approvals
    WHERE entity_type='PO'
      AND entity_id=?
");
$stmt->execute([$po_id]);
$approvalProgress = $stmt->fetch(PDO::FETCH_ASSOC);

$totalStages = (int)$approvalProgress['total_stages'];
$approvedStages = (int)$approvalProgress['approved_stages'];

// POs no longer require approval chain — treat as approved if no stages exist OR all stages approved
$isFullyApproved = ($totalStages === 0) || ($approvedStages === $totalStages);

/* ================================
   Fetch Approval Stages
================================ */
$stageStmt = $pdo->prepare("
    SELECT 
        ra.role,
        ra.status,
        ra.approved_at,
        u.full_name AS approved_by_name
    FROM request_approvals ra
    LEFT JOIN users u 
        ON ra.approved_by = u.user_id
    WHERE ra.entity_type = 'PO'
      AND ra.entity_id = ?
    ORDER BY ra.id ASC
");
$stageStmt->execute([$po_id]);
$approvalStages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);



/* ================================
   Fetch Approved PO Adjustments
================================ */
$originalPoId = ($po['po_type'] === 'ADJUSTMENT')
    ? (int)$po['parent_po_id']
    : (int)$po['po_id'];

$stmt = $pdo->prepare("
SELECT po_id, po_total, adjustment_reason, approved_at
FROM purchase_orders
WHERE parent_po_id = ?
  AND po_type = 'ADJUSTMENT'
ORDER BY approved_at ASC

");
$stmt->execute([$originalPoId]);
$adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);


$approvedTotal = (float)$po['po_total'];

foreach ($adjustments as $adj) {
    $approvedTotal += (float)$adj['po_total'];
}



$stmt = $pdo->prepare("
    SELECT IFNULL(SUM(invoice_amount),0)
    FROM invoices
    WHERE po_id = ?
");
$stmt->execute([$po_id]);
$total_invoiced = (float)$stmt->fetchColumn();



$stmt = $pdo->prepare("
    SELECT IFNULL(SUM(variation_amount),0)
    FROM po_variations
    WHERE po_id=? AND status='APPROVED'
");
$stmt->execute([$po_id]);
$variation_total = $stmt->fetchColumn();

/* ================================
   Fetch Approved Advance Payments
================================ */
$apSumStmt = $pdo->prepare("
    SELECT IFNULL(SUM(payment_amount), 0)
    FROM po_advance_payments
    WHERE po_id = ? AND status = 'APPROVED'
");
$apSumStmt->execute([$po_id]);
$total_advance_payments = (float)$apSumStmt->fetchColumn();

$remaining_balance = $approvedTotal - $total_invoiced - $total_advance_payments;

/* ================================
   Fetch All Advance Payments (all statuses) for history display
================================ */
$apListStmt = $pdo->prepare("
    SELECT
        ap.advance_payment_id,
        ap.payment_type,
        ap.payment_amount,
        ap.payment_date,
        ap.payment_reference,
        ap.supplier_name,
        ap.payment_method,
        ap.notes,
        ap.supporting_document_path,
        ap.supporting_document_original_name,
        ap.status,
        ap.approved_at,
        ap.rejection_reason,
        ap.approval_comments,
        uc.full_name AS created_by_name,
        ua.full_name AS approved_by_name
    FROM po_advance_payments ap
    LEFT JOIN users uc ON ap.created_by  = uc.user_id
    LEFT JOIN users ua ON ap.approved_by = ua.user_id
    WHERE ap.po_id = ?
    ORDER BY ap.created_at DESC
");
$apListStmt->execute([$po_id]);
$advancePayments = $apListStmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Fetch Invoices linked to this PO
================================ */
$invStmt = $pdo->prepare("
    SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.invoice_amount, i.status
    FROM invoices i
    WHERE i.po_id = ?
    ORDER BY i.invoice_date DESC
");
$invStmt->execute([$po_id]);
$invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// ================================
// Fetch approved PO variations
// ================================
$adjStmt = $pdo->prepare("
    SELECT 
        pv.variation_id,
        pv.variation_amount,
        pv.reason,
        pv.approved_at,
        pv.status,
        pv.commitment_id,

        CASE
            WHEN pv.commitment_id IS NULL THEN 0
            ELSE 0
        END AS supp_commitment_fully_approved

    FROM po_variations pv
    LEFT JOIN commitments c
        ON pv.commitment_id = c.commitment_id
    WHERE pv.po_id = ?
  AND pv.status IN ('PENDING','APPROVED')
  ORDER BY pv.approved_at ASC
");
$adjStmt->execute([$po['po_id']]);
$variations = $adjStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($variations as &$v) {

    if (empty($v['commitment_id'])) {
        $v['supp_commitment_fully_approved'] = 0;
        continue;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM request_approvals
        WHERE entity_type='COMMITMENT'
          AND entity_id=?
          AND status='pending'
    ");
    $stmt->execute([$v['commitment_id']]);

    $v['supp_commitment_fully_approved'] =
        ($stmt->fetchColumn() == 0) ? 1 : 0;
}
unset($v);



$approvedTotal = (float)$po['po_total'];

foreach ($variations as $v) {
    $approvedTotal += (float)$v['variation_amount'];
}

/* ================================
   Fetch linked inventory GRNs
================================ */
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ProcurementInventoryBridge.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryService.php';
$inventoryModuleReady = inventoryTablesExist($pdo);
$linkedGrns = [];
if ($inventoryModuleReady) {
    try {
        $linkedGrns = ProcurementInventoryBridge::getGrnsForPo($pdo, $po_id);
    } catch (PDOException $e) {
        // Column procurement_po_id may not exist if migration 020 hasn't been applied
        error_log("PO view GRN query failed (po_id=$po_id): " . $e->getMessage());
        $linkedGrns = [];
        $inventoryModuleReady = false;
    }
}

/* ================================
   Render page AFTER logic
================================ */
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* Computed display values */
$progress = ($totalStages > 0) ? round(($approvedStages / $totalStages) * 100) : 0;
$rejectedStages = count(array_filter($approvalStages, fn($s) => $s['status'] === 'rejected'));

$utilizationPct = ($approvedTotal > 0) ? min(100, round((($total_invoiced + $total_advance_payments) / $approvedTotal) * 100)) : 0;

$statusIcon = match($po['status']) {
    'Open'      => ['bg-success', 'bi-unlock',   'Open'],
    'Closed'    => ['bg-secondary','bi-lock',     'Closed'],
    'Cancelled' => ['bg-danger',   'bi-x-circle', 'Cancelled'],
    default     => ['bg-dark',     'bi-question-circle', $po['status']],
};
?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="container mt-4">

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h3 class="section-title mb-1">
                <i class="bi bi-file-earmark-ruled me-2"></i>Purchase Order: <?= htmlspecialchars($po['po_number']) ?>
            </h3>
            <small class="text-muted">
                Commitment
                <a href="/commitments/view.php?commitment_id=<?= (int)$po['commitment_id'] ?>" class="text-decoration-none fw-semibold">
                    <?= htmlspecialchars($po['commitment_number']) ?>
                </a>
                &middot; Type: <span class="badge bg-secondary"><?= htmlspecialchars($po['po_type']) ?></span>
            </small>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <span class="badge <?= $statusIcon[0] ?> fs-6">
            <i class="bi <?= $statusIcon[1] ?> me-1"></i><?= $statusIcon[2] ?>
        </span>
        <?php if ($isFullyApproved): ?>
            <span class="badge bg-success fs-6"><i class="bi bi-patch-check me-1"></i>Fully Approved</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($warning)): ?>
<div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i><strong>Notice:</strong>
    Invoice attempted above PO limit on
    <?= date('d M Y H:i', strtotime($warning['created_at'])) ?>.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     KPI METRIC CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-gold h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">PO Total</small>
                <h3 class="mb-0 fw-bold"><?= money((float)$po['po_total']) ?></h3>
                <small class="text-muted">Original value</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-green h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Total Invoiced</small>
                <h3 class="mb-0 fw-bold"><?= money($total_invoiced) ?></h3>
                <small class="text-muted"><?= $utilizationPct ?>% utilized</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left: 6px solid #ff9800;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#e65100;">Advance Payments</small>
                <h3 class="mb-0 fw-bold" style="color:#bf360c;"><?= money($total_advance_payments) ?></h3>
                <small class="text-muted"><?= count($advancePayments) ?> record<?= count($advancePayments) !== 1 ? 's' : '' ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Remaining Balance</small>
                <h3 class="mb-0 fw-bold" style="color:#1a237e;"><?= money($remaining_balance) ?></h3>
                <small class="text-muted"><?= (100 - $utilizationPct) ?>% available</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">Approved Value</small>
                <h3 class="mb-0 fw-bold" style="color:#880e4f;"><?= money($approvedTotal) ?></h3>
                <small class="text-muted">Incl. <?= count($variations) ?> variation<?= count($variations) !== 1 ? 's' : '' ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Utilization Bar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <small class="fw-bold text-muted">Budget Utilization</small>
            <small class="fw-bold"><?= $utilizationPct ?>%</small>
        </div>
        <div class="progress" style="height: 8px; border-radius: 4px;">
            <div class="progress-bar <?= $utilizationPct > 90 ? 'bg-danger' : ($utilizationPct > 70 ? 'bg-warning' : 'bg-success') ?>"
                 style="width: <?= $utilizationPct ?>%; transition: width 0.6s ease;"
                 role="progressbar"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     APPROVAL PIPELINE
═══════════════════════════════════════════════════════ -->
<!--<div class="card shadow-sm border-0 mb-4">-->
<!--    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">-->
<!--        <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Approval Pipeline</h5>-->
<!--        <span class="badge <?= $progress === 100 ? 'bg-success' : ($rejectedStages > 0 ? 'bg-danger' : 'bg-warning text-dark') ?> fs-6">-->
<!--            <?= $approvedStages ?>/<?= $totalStages ?> Complete-->
<!--        </span>-->
<!--    </div>-->
<!--    <div class="card-body">-->
<!--        <?php if (empty($approvalStages)): ?>-->
<!--            <div class="text-center py-3">-->
<!--                <i class="bi bi-exclamation-triangle text-warning fs-3"></i>-->
<!--                <p class="text-muted mt-2 mb-0">No approval stages defined. Stages will be created when approval is initiated.</p>-->
<!--            </div>-->
<!--        <?php else: ?>-->
            <!-- Progress bar -->
<!--            <div class="progress mb-4" style="height: 8px; border-radius: 4px;">-->
<!--                <div class="progress-bar <?= $rejectedStages > 0 ? 'bg-danger' : 'bg-success' ?>"-->
<!--                     style="width: <?= $progress ?>%; transition: width 0.6s ease;"-->
<!--                     role="progressbar" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">-->
<!--                </div>-->
<!--            </div>-->

            <!-- Stage cards -->
<!--            <div class="row g-3">-->
<!--                <?php foreach ($approvalStages as $idx => $stage): ?>-->
<!--                    <div class="col-md-6">-->
<!--                        <div class="d-flex align-items-start gap-3 p-3 rounded-3 border-->
<!--                            <?php if ($stage['status'] === 'approved'): ?> border-success bg-success bg-opacity-10-->
<!--                            <?php elseif ($stage['status'] === 'rejected'): ?> border-danger bg-danger bg-opacity-10-->
<!--                            <?php else: ?> border-warning bg-warning bg-opacity-10-->
<!--                            <?php endif; ?>">-->

                            <!-- Step number circle -->
<!--                            <div class="flex-shrink-0">-->
<!--                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold-->
<!--                                    <?php if ($stage['status'] === 'approved'): ?> bg-success text-white-->
<!--                                    <?php elseif ($stage['status'] === 'rejected'): ?> bg-danger text-white-->
<!--                                    <?php else: ?> bg-warning text-dark-->
<!--                                    <?php endif; ?>"-->
<!--                                     style="width: 40px; height: 40px; font-size: 1.1rem;">-->
<!--                                    <?php if ($stage['status'] === 'approved'): ?>-->
<!--                                        <i class="bi bi-check-lg"></i>-->
<!--                                    <?php elseif ($stage['status'] === 'rejected'): ?>-->
<!--                                        <i class="bi bi-x-lg"></i>-->
<!--                                    <?php else: ?>-->
<!--                                        <?= $idx + 1 ?>-->
<!--                                    <?php endif; ?>-->
<!--                                </div>-->
<!--                            </div>-->

                            <!-- Stage details -->
<!--                            <div class="flex-grow-1">-->
<!--                                <div class="d-flex justify-content-between align-items-center mb-1">-->
<!--                                    <strong><?= htmlspecialchars($stage['role']) ?></strong>-->
<!--                                    <?php if ($stage['status'] === 'approved'): ?>-->
<!--                                        <span class="badge bg-success">Approved</span>-->
<!--                                    <?php elseif ($stage['status'] === 'rejected'): ?>-->
<!--                                        <span class="badge bg-danger">Rejected</span>-->
<!--                                    <?php else: ?>-->
<!--                                        <span class="badge bg-warning text-dark">Pending</span>-->
<!--                                    <?php endif; ?>-->
<!--                                </div>-->
<!--                                <?php if ($stage['status'] === 'approved' && $stage['approved_at']): ?>-->
<!--                                    <small class="text-muted">-->
<!--                                        <i class="bi bi-person-check me-1"></i><?= htmlspecialchars($stage['approved_by_name'] ?? 'System') ?>-->
<!--                                        <span class="mx-1">&middot;</span>-->
<!--                                        <i class="bi bi-clock me-1"></i><?= date('d M Y, h:i A', strtotime($stage['approved_at'])) ?>-->
<!--                                    </small>-->
<!--                                <?php elseif ($stage['status'] === 'pending'): ?>-->
<!--                                    <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting approval</small>-->
<!--                                <?php endif; ?>-->
<!--                            </div>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                <?php endforeach; ?>-->
<!--            </div>-->
<!--        <?php endif; ?>-->
<!--    </div>-->
<!--</div>-->

<!-- ═══════════════════════════════════════════════════════
     PO DETAILS + ACTIONS (TWO-COLUMN)
═══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <!-- PO Information -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>PO Information</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">PO Number</label>
                        <p class="mb-0 fw-semibold fs-5"><?= htmlspecialchars($po['po_number']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">PO Date</label>
                        <p class="mb-0"><?= date('d M Y', strtotime($po['po_date'])) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Commitment</label>
                        <p class="mb-0">
                            <a href="/commitments/view.php?commitment_id=<?= (int)$po['commitment_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($po['commitment_number']) ?>
                            </a>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Commitment Total</label>
                        <p class="mb-0 fw-semibold"><?= money((float)$po['commitment_total']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">PO Type</label>
                        <p class="mb-0">
                            <span class="badge <?= $po['po_type'] === 'ORIGINAL' ? 'bg-primary' : 'bg-info' ?>">
                                <?= htmlspecialchars($po['po_type']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Final Approval</label>
                        <p class="mb-0">
                            <?php if ($isFullyApproved && !empty($po['approved_at'])): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved</span>
                                <small class="text-muted d-block mt-1"><?= date('d M Y, h:i A', strtotime($po['approved_at'])) ?></small>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions</h5>
            </div>
            <div class="card-body">
                <?php
                /* Action-required alerts for variations needing commitments */
                $pendingFunding = array_filter($variations, fn($v) => $v['status'] === 'PENDING' && empty($v['commitment_id']));
                if (!empty($pendingFunding)): ?>
                    <?php foreach ($pendingFunding as $v): ?>
                    <div class="d-flex align-items-center gap-2 p-2 rounded-3 border border-danger bg-danger bg-opacity-10 mb-2">
                        <i class="bi bi-exclamation-triangle fs-4 text-danger"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block small">Funding Required</strong>
                            <span class="small text-muted">A variation needs a supplementary commitment.</span>
                        </div>
                        <a href="/commitments/add_supplementary.php?variation_id=<?= (int)$v['variation_id'] ?>"
                           class="btn btn-sm btn-danger">
                            <i class="bi bi-plus-lg me-1"></i>Create
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!$isFullyApproved && $po['status'] === 'Open'): ?>
                <div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light border mb-3">
                    <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                    <div>
                        <strong class="d-block small">Next Step</strong>
                        <span class="small">PO approval pending (<?= $approvedStages ?>/<?= $totalStages ?> stages complete)</span>
                    </div>
                </div>
                <?php elseif ($isFullyApproved && $po['status'] === 'Open'): ?>
                <div class="d-flex align-items-center gap-2 p-2 rounded-3 bg-light border mb-3">
                    <i class="bi bi-receipt fs-4 text-success"></i>
                    <div>
                        <strong class="d-block small">Next Step</strong>
                        <span class="small">Add invoices against this PO</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <?php if (empty($po['po_file']) && empty($po['document_path']) && has_permission('upload_purchase_order')): ?>
                        <a href="/po/upload.php?commitment_id=<?= (int)$po['commitment_id'] ?>" class="btn btn-warning">
                            <i class="bi bi-cloud-upload me-1"></i>Upload PO Document
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($po['document_path']) || !empty($po['po_file'])): ?>
                        <a href="/po/download_document.php?po_id=<?= (int)$po_id ?>" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i>View PO Document
                        </a>
                    <?php endif; ?>
                    <?php if ($isFullyApproved && $po['status'] === 'Open' && has_permission('create_invoice')): ?>
                        <a href="/invoice/add.php?po_id=<?= (int)$po_id ?>" class="btn btn-success">
                            <i class="bi bi-receipt me-1"></i>Add Invoice
                        </a>
                    <?php endif; ?>

                    <?php if ($isFullyApproved && $po['status'] === 'Open' && has_permission('record_advance_payment')): ?>
                        <a href="/po/advance_payment.php?po_id=<?= (int)$po_id ?>" class="btn btn-warning">
                            <i class="bi bi-cash-coin me-1"></i>Record Advance Payment
                        </a>
                    <?php endif; ?>

                    <?php if (has_permission('approve_purchase_order') && !$isFullyApproved && $po['status'] === 'Open'): ?>
                        <a href="/po/approve.php?id=<?= (int)$po['po_id'] ?>" class="btn btn-primary">
                            <i class="bi bi-check2-square me-1"></i>Approve PO
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($invoices)): ?>
                        <a href="#invoicesSection" class="btn btn-outline-info">
                            <i class="bi bi-receipt me-1"></i>View Invoices <span class="badge bg-info text-white ms-1"><?= count($invoices) ?></span>
                        </a>
                    <?php endif; ?>

                    <a href="/reports/print_po.php?po_id=<?= $po_id ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-printer me-1"></i>Print PDF
                    </a>

                    <?php if ($inventoryModuleReady && has_permission('receive_goods')): ?>
                        <a href="/po/receive_to_inventory.php?po_id=<?= (int)$po_id ?>"
                           class="btn btn-outline-success"
                           title="Create a Goods Received Note (GRN) in the inventory module for this PO">
                            <i class="bi bi-box-seam me-1"></i>Receive to Inventory
                        </a>
                    <?php endif; ?>

                    <?php if (has_permission('view_audit_logs')): ?>
                        <a href="/audit/view.php?table=purchase_orders&id=<?= (int)$po['po_id'] ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-journal-text me-1"></i>Audit Trail
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     ADJUSTMENTS & VARIATIONS
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Adjustments & Variations</h5>
        <span class="badge bg-light text-dark"><?= count($variations) ?> variation<?= count($variations) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th class="ps-3" style="color:#000;">Type</th>
                        <th class="text-end" style="color:#000;">Amount</th>
                        <th style="color:#000;">Reason</th>
                        <th style="color:#000;">Date</th>
                        <th class="text-center" style="color:#000;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-3 fw-semibold">
                            <i class="bi bi-file-earmark-text me-1 text-muted"></i>Original PO
                        </td>
                        <td class="text-end fw-semibold"><?= money((float)$po['po_total']) ?></td>
                        <td><span class="text-muted">&mdash;</span></td>
                        <td class="small text-muted"><?= date('d M Y', strtotime($po['po_date'])) ?></td>
                        <td class="text-center"><span class="badge bg-secondary">Base</span></td>
                    </tr>

                    <?php if (!empty($variations)): ?>
                        <?php foreach ($variations as $adj): ?>
                        <tr>
                            <td class="ps-3">
                                <i class="bi bi-gear me-1 text-info"></i>Variation
                            </td>
                            <td class="text-end">
                                <span class="fw-bold"><?= money((float)$adj['variation_amount']) ?></span>
                            </td>
                            <td class="small"><?= htmlspecialchars($adj['reason']) ?></td>
                            <td class="small text-muted">
                                <?= $adj['approved_at'] ? date('d M Y', strtotime($adj['approved_at'])) : '&mdash;' ?>
                            </td>
                            <td class="text-center">
                                <?php if (empty($adj['commitment_id'])): ?>
                                    <span class="badge bg-danger">No Funding</span>
                                <?php elseif ((int)$adj['supp_commitment_fully_approved'] !== 1): ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <tr class="table-dark">
                        <td class="ps-3 fw-bold">
                            <i class="bi bi-calculator me-1"></i>Total Approved Value
                        </td>
                        <td class="text-end fw-bold text-white"><?= money($approvedTotal) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     LINKED INVOICES
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4" id="invoicesSection">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Linked Invoices</h5>
        <span class="badge bg-light text-dark"><?= count($invoices) ?> invoice<?= count($invoices) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th class="ps-3" style="color:#000;"><i class="bi bi-hash me-1"></i>Invoice #</th>
                        <th style="color:#000;"><i class="bi bi-calendar-event me-1"></i>Date</th>
                        <th class="text-end" style="color:#000;"><i class="bi bi-currency-dollar me-1"></i>Amount</th>
                        <th class="text-center" style="color:#000;"><i class="bi bi-info-circle me-1"></i>Status</th>
                        <th class="text-center" style="color:#000;"><i class="bi bi-lightning me-1"></i>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            <i class="bi bi-inbox text-muted fs-1"></i>
                            <p class="text-muted mt-2 mb-0">No invoices recorded against this PO yet.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="ps-3 fw-semibold">
                                <a href="/invoice/view.php?id=<?= (int)$inv['invoice_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td class="small text-muted"><?= date('d M Y', strtotime($inv['invoice_date'])) ?></td>
                            <td class="text-end fw-semibold"><?= money((float)$inv['invoice_amount']) ?></td>
                            <td class="text-center">
                                <?php
                                    $invBadge = match ($inv['status']) {
                                        'Paid'           => 'bg-success',
                                        'Partially Paid' => 'bg-warning text-dark',
                                        default          => 'bg-secondary'
                                    };
                                ?>
                                <span class="badge <?= $invBadge ?>"><?= htmlspecialchars($inv['status']) ?></span>
                            </td>
                            <td class="text-center">
                                <a href="/invoice/view.php?id=<?= (int)$inv['invoice_id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark">
                            <td class="ps-3 fw-bold" colspan="2"><i class="bi bi-calculator me-1"></i>Total Invoiced</td>
                            <td class="text-end fw-bold text-white"><?= money($total_invoiced) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     ADVANCE PAYMENTS HISTORY
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4" id="advancePaymentsSection">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Advance / Partial Payments</h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark"><?= count($advancePayments) ?> record<?= count($advancePayments) !== 1 ? 's' : '' ?></span>
            <?php if ($isFullyApproved && $po['status'] === 'Open' && has_permission('record_advance_payment')): ?>
            <a href="/po/advance_payment.php?po_id=<?= (int)$po_id ?>" class="btn btn-sm btn-warning">
                <i class="bi bi-plus-circle me-1"></i>Record Advance Payment
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th class="ps-3" style="color:#000;"><i class="bi bi-calendar-event me-1"></i>Date</th>
                        <th style="color:#000;"><i class="bi bi-tag me-1"></i>Type</th>
                        <th style="color:#000;"><i class="bi bi-hash me-1"></i>Reference</th>
                        <th class="text-end" style="color:#000;"><i class="bi bi-currency-dollar me-1"></i>Amount</th>
                        <th style="color:#000;"><i class="bi bi-credit-card me-1"></i>Method</th>
                        <th class="text-center" style="color:#000;"><i class="bi bi-info-circle me-1"></i>Status</th>
                        <th style="color:#000;"><i class="bi bi-person me-1"></i>Submitted By</th>
                        <th style="color:#000;"><i class="bi bi-person-check me-1"></i>Approved By</th>
                        <th class="text-center" style="color:#000;"><i class="bi bi-lightning me-1"></i>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advancePayments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="bi bi-inbox text-muted fs-1"></i>
                            <p class="text-muted mt-2 mb-0">No advance payments recorded against this PO yet.</p>
                            <?php if ($isFullyApproved && $po['status'] === 'Open' && has_permission('record_advance_payment')): ?>
                            <a href="/po/advance_payment.php?po_id=<?= (int)$po_id ?>" class="btn btn-sm btn-warning mt-2">
                                <i class="bi bi-cash-coin me-1"></i>Record Advance Payment
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php
                        $apTypeBadges = [
                            'ADVANCE_PAYMENT' => 'bg-primary',
                            'PARTIAL_PAYMENT' => 'bg-info text-dark',
                            'FINAL_PAYMENT'   => 'bg-success',
                        ];
                        $apStatusBadges = [
                            'PENDING_APPROVAL' => 'bg-warning text-dark',
                            'APPROVED'         => 'bg-success',
                            'REJECTED'         => 'bg-danger',
                            'CANCELLED'        => 'bg-secondary',
                        ];
                        $totalApproved = 0.0;
                        foreach ($advancePayments as $ap):
                            if ($ap['status'] === 'APPROVED') $totalApproved += (float)$ap['payment_amount'];
                            $apTypeBadge   = $apTypeBadges[$ap['payment_type']] ?? 'bg-secondary';
                            $apStatusBadge = $apStatusBadges[$ap['status']] ?? 'bg-secondary';
                            $apTypeLabel   = match ($ap['payment_type']) {
                                'ADVANCE_PAYMENT' => 'Advance',
                                'PARTIAL_PAYMENT' => 'Partial',
                                'FINAL_PAYMENT'   => 'Final',
                                default           => htmlspecialchars($ap['payment_type']),
                            };
                        ?>
                        <tr>
                            <td class="ps-3 small text-muted"><?= date('d M Y', strtotime($ap['payment_date'])) ?></td>
                            <td><span class="badge <?= $apTypeBadge ?>"><?= $apTypeLabel ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars($ap['payment_reference']) ?></td>
                            <td class="text-end fw-semibold"><?= money((float)$ap['payment_amount']) ?></td>
                            <td class="small"><?= htmlspecialchars($ap['payment_method'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="badge <?= $apStatusBadge ?>"><?= htmlspecialchars($ap['status']) ?></span>
                            </td>
                            <td class="small"><?= htmlspecialchars($ap['created_by_name'] ?? '—') ?></td>
                            <td class="small">
                                <?php if ($ap['approved_by_name']): ?>
                                    <?= htmlspecialchars($ap['approved_by_name']) ?>
                                    <?php if ($ap['approved_at']): ?>
                                    <br><small class="text-muted"><?= date('d M Y', strtotime($ap['approved_at'])) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ap['status'] === 'PENDING_APPROVAL' && has_permission('approve_advance_payment')): ?>
                                <a href="/po/approve_advance_payment.php?id=<?= (int)$ap['advance_payment_id'] ?>"
                                   class="btn btn-sm btn-success me-1">
                                    <i class="bi bi-check2"></i> Decide
                                </a>
                                <?php else: ?>
                                <a href="/po/view.php?po_id=<?= (int)$po_id ?>#advance-payments"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <?php endif; ?>
                                <?php if ($ap['supporting_document_path']): ?>
                                <a href="/po/download_advance_payment_doc.php?id=<?= (int)$ap['advance_payment_id'] ?>&action=view"
                                   class="btn btn-sm btn-outline-secondary" target="_blank" title="View document">
                                    <i class="bi bi-paperclip"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark">
                            <td class="ps-3 fw-bold" colspan="3"><i class="bi bi-calculator me-1"></i>Total Approved Advance Payments</td>
                            <td class="text-end fw-bold text-white"><?= money($totalApproved) ?></td>
                            <td colspan="5"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     LINKED INVENTORY GRNs
═══════════════════════════════════════════════════════ -->
<?php if ($inventoryModuleReady): ?>
<div class="card shadow-sm border-0 mb-4" id="grnsSection">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-boxes me-2"></i>Inventory — Goods Received Notes</h5>
        <span class="badge bg-light text-dark"><?= count($linkedGrns) ?> GRN<?= count($linkedGrns) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th class="ps-3" style="color:#000;">GRN #</th>
                        <th style="color:#000;">Received Date</th>
                        <th style="color:#000;">Supplier</th>
                        <th style="color:#000;">Location</th>
                        <th class="text-center" style="color:#000;">Status</th>
                        <th class="text-center" style="color:#000;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($linkedGrns)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <i class="bi bi-inbox text-muted fs-1"></i>
                            <p class="text-muted mt-2 mb-0">No inventory GRNs linked to this PO yet.</p>
                            <?php if (has_permission('receive_goods')): ?>
                            <a href="/po/receive_to_inventory.php?po_id=<?= (int)$po_id ?>"
                               class="btn btn-sm btn-outline-success mt-2">
                                <i class="bi bi-box-seam me-1"></i>Create GRN
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($linkedGrns as $grn): ?>
                        <tr>
                            <td class="ps-3 fw-semibold">
                                <a href="/inventory/receiving/view.php?id=<?= (int)$grn['grn_id'] ?>"
                                   class="text-decoration-none">
                                    <?= htmlspecialchars($grn['grn_number']) ?>
                                </a>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars(date('d M Y', strtotime($grn['received_date']))) ?>
                            </td>
                            <td><?= htmlspecialchars($grn['supplier_name']) ?></td>
                            <td class="small">
                                <?= htmlspecialchars(
                                    ($grn['location_code'] ?? '') .
                                    ($grn['site_name'] ? ' — ' . $grn['site_name'] : '')
                                ) ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $grnBadge = match ($grn['status']) {
                                    'COMPLETED'  => ['bg-success',          'Completed'],
                                    'RECEIVED'   => ['bg-primary',          'Received'],
                                    'INSPECTION' => ['bg-warning text-dark','Inspection'],
                                    'DRAFT'      => ['bg-secondary',        'Draft'],
                                    'CANCELLED'  => ['bg-danger',           'Cancelled'],
                                    default      => ['bg-secondary',        $grn['status']],
                                };
                                ?>
                                <span class="badge <?= $grnBadge[0] ?>"><?= $grnBadge[1] ?></span>
                            </td>
                            <td class="text-center">
                                <a href="/inventory/receiving/view.php?id=<?= (int)$grn['grn_id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     NAVIGATION
═══════════════════════════════════════════════════════ -->
<div class="d-flex gap-2 mb-4">
    <a href="/po/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>
    <a href="javascript:history.back()" class="btn btn-outline-secondary">
        Cancel
    </a>
</div>

</div><!-- /.container -->

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
