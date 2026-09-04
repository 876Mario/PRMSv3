<?php
$REQUIRE_PERMISSION = 'view_commitments';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/policy.php";

/* ================================
   Get Commitment ID
================================ */
$commitment_id = isset($_GET['commitment_id'])
    ? (int)$_GET['commitment_id']
    : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($commitment_id <= 0) {
    pop("Missing Commitment ID.", "/commitments/list.php");
    exit;
}

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
    LEFT JOIN users u ON ra.approved_by = u.user_id
    WHERE ra.entity_type = 'COMMITMENT'
      AND ra.entity_id = ?
    ORDER BY ra.id ASC
");
$stageStmt->execute([$commitment_id]);
$approvalStages = $stageStmt->fetchAll(PDO::FETCH_ASSOC);

/* Determine if the current user can approve the next pending stage */
$currentUserRole = $_SESSION['role'] ?? '';
$nextPendingStage = null;
$canApproveNext = false;
foreach ($approvalStages as $stage) {
    if ($stage['status'] === 'pending') {
        $nextPendingStage = $stage;
        if ($stage['role'] === $currentUserRole) {
            $canApproveNext = true;
        }
        break;
    }
}

$stmt = $pdo->prepare("
    SELECT 
        c.commitment_id,
        c.request_id,
        c.commitment_number,
        c.commitment_date,
        c.commitment_total,
        c.status,
        c.approved_at,
        c.commitment_type,
        c.gfms_commitment_number,
        c.document_path,
        c.rfq_id,
        pr.request_number,

        -- HOD approval
        CASE 
            WHEN SUM(
                CASE 
                    WHEN ra.role = 'HOD' AND ra.status = 'approved'
                    THEN 1 ELSE 0
                END
            ) > 0 THEN 1
            ELSE 0
        END AS hod_approved,

        -- Finance approval
        CASE 
            WHEN SUM(
                CASE 
                    WHEN ra.role = 'Finance Officer' AND ra.status = 'approved'
                    THEN 1 ELSE 0
                END
            ) > 0 THEN 1
            ELSE 0
        END AS finance_approved

    FROM commitments c
    JOIN procurement_requests pr 
        ON c.request_id = pr.request_id

    LEFT JOIN request_approvals ra
        ON ra.entity_type = 'COMMITMENT'
        AND ra.entity_id = c.commitment_id

    WHERE c.commitment_id = ?
    GROUP BY c.commitment_id
");

$stmt->execute([$commitment_id]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$c) {
    pop("Commitment not found.", "/commitments/list.php");
    exit;
}

// ================================
// Fetch Supplementary Commitments
// ================================
$suppStmt = $pdo->prepare("
    SELECT 
        c.commitment_id,
        c.commitment_number,
        c.commitment_date,
        c.commitment_total,
        c.approved_at,
        c.status,

        -- HOD approval
        CASE 
            WHEN SUM(
                CASE 
                    WHEN ra.role = 'HOD' 
                         AND ra.status = 'approved'
                    THEN 1 ELSE 0 
                END
            ) > 0 THEN 1
            ELSE 0
        END AS hod_approved,

        -- Finance approval
        CASE 
            WHEN SUM(
                CASE 
                    WHEN ra.role = 'Finance Officer' 
                         AND ra.status = 'approved'
                    THEN 1 ELSE 0 
                END
            ) > 0 THEN 1
            ELSE 0
        END AS finance_approved

    FROM commitments c

    LEFT JOIN request_approvals ra
        ON ra.entity_type = 'COMMITMENT'
        AND ra.entity_id = c.commitment_id

    WHERE c.request_id = ?
      AND c.commitment_type = 'SUPPLEMENTARY'

    GROUP BY c.commitment_id
    ORDER BY c.commitment_date ASC
");
$suppStmt->execute([(int)$c['request_id']]);
$supplementaries = $suppStmt->fetchAll(PDO::FETCH_ASSOC);


//2️⃣ CALCULATE TOTAL AUTHORIZED AMOUNT

$stmt = $pdo->prepare("
    SELECT
        c.commitment_total
        + COALESCE(SUM(sc.commitment_total), 0) AS total_authorized
    FROM commitments c

    LEFT JOIN commitments sc
        ON sc.request_id = c.request_id
       AND sc.commitment_type = 'SUPPLEMENTARY'

    LEFT JOIN request_approvals ra
        ON ra.entity_type = 'COMMITMENT'
       AND ra.entity_id = sc.commitment_id
       AND ra.status = 'pending'

    WHERE c.commitment_id = ?
      AND (
            sc.commitment_id IS NULL
            OR ra.id IS NULL
          )
");
$stmt->execute([(int)$c['commitment_id']]);

$totalAuthorized = (float)$stmt->fetchColumn();

?>

<?php
$stmt = $pdo->prepare("
    SELECT po_id, po_number
    FROM purchase_orders
    WHERE commitment_id = ?
    LIMIT 1
");
$stmt->execute([$c['commitment_id']]);
$existingPo = $stmt->fetch(PDO::FETCH_ASSOC);
$existing_po_id = $existingPo ? $existingPo['po_id'] : null;
$existing_po_number = $existingPo ? $existingPo['po_number'] : null;
?>

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="container mt-4">

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h3 class="section-title mb-1">
                <i class="bi bi-journal-check me-2"></i>Commitment: <?= htmlspecialchars($c['commitment_number']) ?>
            </h3>
            <small class="text-muted">
                Request <a href="/procurement/view.php?id=<?= (int)$c['request_id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($c['request_number']) ?></a>
            </small>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php
            $statusBadge = $c['status'] === 'open'
                ? '<span class="badge bg-success fs-6"><i class="bi bi-unlock me-1"></i>Open</span>'
                : '<span class="badge bg-secondary fs-6"><i class="bi bi-lock me-1"></i>Closed</span>';
            echo $statusBadge;
        ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI METRIC CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-gold h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Committed Amount</small>
                <h3 class="mb-0 fw-bold">$<?= number_format($c['commitment_total'], 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm kpi-card kpi-green h-100">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em">Total Authorized</small>
                <h3 class="mb-0 fw-bold">$<?= number_format($totalAuthorized, 2) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #e8eaf6, #c5cae9); border-left: 6px solid #3f51b5;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#283593;">Commitment Date</small>
                <h4 class="mb-0 fw-bold" style="color:#1a237e;"><?= date('d M Y', strtotime($c['commitment_date'])) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #fce4ec, #f8bbd0); border-left: 6px solid #e91e63;">
            <div class="card-body text-center py-3">
                <small class="text-uppercase fw-bold d-block mb-1" style="letter-spacing:.05em; color:#880e4f;">Purchase Order</small>
                <?php if ($existing_po_id): ?>
                    <a href="/po/view.php?po_id=<?= (int)$existing_po_id ?>" class="text-decoration-none">
                        <h4 class="mb-0 fw-bold" style="color:#880e4f;"><?= htmlspecialchars($existing_po_number) ?></h4>
                    </a>
                <?php else: ?>
                    <h4 class="mb-0 text-muted">—</h4>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     APPROVAL PIPELINE
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Approval Pipeline</h5>
        <?php
            $totalStages = count($approvalStages);
            $approvedStages = count(array_filter($approvalStages, fn($s) => $s['status'] === 'approved'));
            $rejectedStages = count(array_filter($approvalStages, fn($s) => $s['status'] === 'rejected'));
            $progress = $totalStages > 0 ? round(($approvedStages / $totalStages) * 100) : 0;
        ?>
        <span class="badge <?= $progress === 100 ? 'bg-success' : ($rejectedStages > 0 ? 'bg-danger' : 'bg-warning text-dark') ?> fs-6">
            <?= $approvedStages ?>/<?= $totalStages ?> Complete
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($approvalStages)): ?>
            <div class="text-center py-3">
                <i class="bi bi-exclamation-triangle text-warning fs-3"></i>
                <p class="text-muted mt-2 mb-0">No approval stages defined. Stages will be created when approval is initiated.</p>
            </div>
        <?php else: ?>
            <!-- Progress bar -->
            <div class="progress mb-4" style="height: 8px; border-radius: 4px;">
                <div class="progress-bar <?= $rejectedStages > 0 ? 'bg-danger' : 'bg-success' ?>"
                     style="width: <?= $progress ?>%; transition: width 0.6s ease;"
                     role="progressbar" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                </div>
            </div>

            <!-- Stage cards -->
            <div class="row g-3">
                <?php foreach ($approvalStages as $idx => $stage): ?>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3 p-3 rounded-3 border
                            <?php if ($stage['status'] === 'approved'): ?> border-success bg-success bg-opacity-10
                            <?php elseif ($stage['status'] === 'rejected'): ?> border-danger bg-danger bg-opacity-10
                            <?php else: ?> border-warning bg-warning bg-opacity-10
                            <?php endif; ?>">

                            <!-- Step number circle -->
                            <div class="flex-shrink-0">
                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold
                                    <?php if ($stage['status'] === 'approved'): ?> bg-success text-white
                                    <?php elseif ($stage['status'] === 'rejected'): ?> bg-danger text-white
                                    <?php else: ?> bg-warning text-dark
                                    <?php endif; ?>"
                                     style="width: 40px; height: 40px; font-size: 1.1rem;">
                                    <?php if ($stage['status'] === 'approved'): ?>
                                        <i class="bi bi-check-lg"></i>
                                    <?php elseif ($stage['status'] === 'rejected'): ?>
                                        <i class="bi bi-x-lg"></i>
                                    <?php else: ?>
                                        <?= $idx + 1 ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Stage details -->
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong><?= htmlspecialchars($stage['role']) ?></strong>
                                    <?php if ($stage['status'] === 'approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($stage['status'] === 'rejected'): ?>
                                        <span class="badge bg-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($stage['status'] === 'approved' && $stage['approved_at']): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-person-check me-1"></i><?= htmlspecialchars($stage['approved_by_name'] ?? 'System') ?>
                                        <span class="mx-1">·</span>
                                        <i class="bi bi-clock me-1"></i><?= date('d M Y, h:i A', strtotime($stage['approved_at'])) ?>
                                    </small>
                                <?php elseif ($stage['status'] === 'pending'): ?>
                                    <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Awaiting approval</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     COMMITMENT DETAILS
═══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Commitment Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Commitment Number</label>
                        <p class="mb-0 fw-semibold"><?= htmlspecialchars($c['commitment_number']) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Date</label>
                        <p class="mb-0"><?= date('d M Y', strtotime($c['commitment_date'])) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Commitment Type</label>
                        <p class="mb-0">
                            <span class="badge <?= ($c['commitment_type'] ?? 'ORIGINAL') === 'ORIGINAL' ? 'bg-primary' : 'bg-info' ?>">
                                <?= htmlspecialchars($c['commitment_type'] ?? 'ORIGINAL') ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Status</label>
                        <p class="mb-0">
                            <span class="badge <?= $c['status'] === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= ucfirst($c['status']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Committed Total</label>
                        <p class="mb-0 fs-5 fw-bold text-success">$<?= number_format($c['commitment_total'], 2) ?></p>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">Approved At</label>
                        <p class="mb-0">
                            <?= $c['approved_at'] ? date('d M Y, h:i A', strtotime($c['approved_at'])) : '<span class="text-muted">—</span>' ?>
                        </p>
                    </div>
                    <?php if (!empty($c['gfms_commitment_number'])): ?>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">GFMS Number</label>
                        <p class="mb-0 fw-semibold text-primary"><?= htmlspecialchars($c['gfms_commitment_number']) ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="col-6">
                        <label class="form-label text-muted small fw-bold mb-0">GFMS Document</label>
                        <p class="mb-0">
                            <?php if (!empty($c['document_path'])): ?>
                                <a href="/commitments/download_document.php?commitment_id=<?= (int)$c['commitment_id'] ?>" class="text-decoration-none">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>View Document
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Approval Status</h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-center">
                <?php if (!empty($approvalStages)): ?>
                <div class="row g-3 text-center">
                    <?php
                    $colSize = count($approvalStages) <= 2 ? 6 : 4;
                    foreach ($approvalStages as $stage):
                        $isApproved = $stage['status'] === 'approved';
                        $isRejected = $stage['status'] === 'rejected';
                    ?>
                    <div class="col-<?= $colSize ?>">
                        <div class="p-3 rounded-3 <?= $isApproved ? 'bg-success bg-opacity-10 border border-success' : ($isRejected ? 'bg-danger bg-opacity-10 border border-danger' : 'bg-light border') ?>">
                            <div class="fs-1 mb-2"><?= $isApproved ? '✅' : ($isRejected ? '❌' : '⏳') ?></div>
                            <strong><?= htmlspecialchars($stage['role']) ?></strong>
                            <div class="mt-1">
                                <?php if ($isApproved): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($isRejected): ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                $allApproved = !empty($approvalStages) && empty(array_filter($approvalStages, fn($s) => $s['status'] !== 'approved'));
                if ($allApproved): ?>
                    <div class="alert alert-success mt-3 mb-0 py-2 text-center">
                        <i class="bi bi-patch-check me-1"></i> Fully approved — ready for Purchase Order
                    </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-3 text-muted">
                    <i class="bi bi-info-circle me-1"></i>No approval stages defined yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     SUPPLEMENTARY COMMITMENTS
═══════════════════════════════════════════════════════ -->
<?php if (!empty($supplementaries)): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-layers me-2"></i>Supplementary Commitments</h5>
        <span class="badge bg-light text-dark"><?= count($supplementaries) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Commitment No.</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th class="text-center">HOD</th>
                        <th class="text-center">Finance</th>
                        <th class="text-center">Status</th>
                        <th>Approved</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplementaries as $s): ?>
                        <tr>
                            <td class="px-3">
                                <a href="/commitments/view.php?commitment_id=<?= (int)$s['commitment_id'] ?>"
                                   class="fw-bold text-decoration-none">
                                    <?= htmlspecialchars($s['commitment_number']) ?>
                                </a>
                            </td>
                            <td><?= date('d M Y', strtotime($s['commitment_date'])) ?></td>
                            <td class="text-end fw-semibold">$<?= number_format($s['commitment_total'], 2) ?></td>
                            <td class="text-center">
                                <?= (int)$s['hod_approved'] === 1
                                    ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>'
                                    : '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i></span>' ?>
                            </td>
                            <td class="text-center">
                                <?= (int)$s['finance_approved'] === 1
                                    ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>'
                                    : '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i></span>' ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?= $s['status'] === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= ucfirst($s['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $s['approved_at']
                                    ? '<small>' . date('d M Y, h:i A', strtotime($s['approved_at'])) . '</small>'
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php
                                // Check if current user can approve next pending stage for this supplementary
                                $suppStageStmt = $pdo->prepare("
                                    SELECT role FROM request_approvals
                                    WHERE entity_type = 'COMMITMENT' AND entity_id = ? AND status = 'pending'
                                    ORDER BY stage_order ASC LIMIT 1
                                ");
                                $suppStageStmt->execute([$s['commitment_id']]);
                                $suppNextRole = $suppStageStmt->fetchColumn();
                                ?>
                                <?php if (
                                    has_permission('approve_commitment') &&
                                    $suppNextRole === $currentUserRole
                                ): ?>
                                    <a href="/commitments/approve.php?id=<?= (int)$s['commitment_id'] ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-check-circle me-1"></i>Approve
                                    </a>
                                <?php elseif ($suppNextRole): ?>
                                    <span class="text-muted" title="Awaiting <?= htmlspecialchars($suppNextRole) ?>"><i class="bi bi-hourglass-split"></i></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="2" class="px-3 fw-bold">Total Authorized</td>
                        <td class="text-end fw-bold text-success">$<?= number_format($totalAuthorized, 2) ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body text-center py-4">
        <i class="bi bi-layers text-muted fs-1"></i>
        <p class="text-muted mt-2 mb-0">No supplementary commitments have been created.</p>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     ACTIONS
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>Actions</h5>
    </div>
    <div class="card-body">
        <?php
        /* Next-step indicator */
        $nextStep = null;
        $allStagesApproved = !empty($approvalStages) && empty(array_filter($approvalStages, fn($s) => $s['status'] !== 'approved'));
        if ($c['status'] === 'open' && $nextPendingStage) {
            $nextStep = [$nextPendingStage['role'] . ' approval required', $nextPendingStage['role'], 'bi-person-check'];
        } elseif (!$existing_po_id && $allStagesApproved) {
            $nextStep = ['Create Purchase Order', 'Procurement Officer', 'bi-file-earmark-plus'];
        }
        if ($nextStep): ?>
        <div class="alert alert-light border d-flex align-items-center gap-2 mb-3 py-2">
            <i class="bi <?= $nextStep[2] ?> fs-5 text-primary"></i>
            <div>
                <strong>Next step:</strong> <?= $nextStep[0] ?>
                <span class="text-muted ms-2">— Role: <em><?= $nextStep[1] ?></em></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <?php if (!empty($c['document_path'])): ?>
                <a href="/commitments/download_document.php?commitment_id=<?= (int)$c['commitment_id'] ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-pdf me-1"></i> View GFMS Document
                </a>
            <?php endif; ?>

            <?php if (
                has_permission('approve_commitment') &&
                $canApproveNext &&
                $c['status'] === 'open'
            ): ?>
                <a href="/commitments/approve.php?id=<?= (int)$c['commitment_id'] ?>"
                   class="btn btn-warning">
                    <i class="bi bi-check-circle me-1"></i> Approve
                </a>
            <?php elseif (
                $nextPendingStage &&
                $nextPendingStage['role'] !== $currentUserRole &&
                $c['status'] === 'open'
            ): ?>
                <span class="badge bg-warning text-dark py-2 px-3">
                    <i class="bi bi-hourglass-split me-1"></i> Awaiting <?= htmlspecialchars($nextPendingStage['role']) ?> approval
                </span>
            <?php endif; ?>

            <?php if (!$existing_po_id && $allStagesApproved): ?>
                <a href="/po/add.php?commitment_id=<?= (int)$c['commitment_id'] ?>"
                   class="btn btn-primary">
                    <i class="bi bi-file-earmark-plus me-1"></i> Create Purchase Order
                </a>
            <?php elseif ($existing_po_id): ?>
                <a href="/po/view.php?po_id=<?= (int)$existing_po_id ?>" class="btn btn-outline-primary">
                    <i class="bi bi-eye me-1"></i> View PO (<?= htmlspecialchars($existing_po_number) ?>)
                </a>
            <?php endif; ?>

            <a href="<?= auditUrl('commitments', $c['commitment_id']) ?>" class="btn btn-outline-dark">
                <i class="bi bi-journal-text me-1"></i> Audit Trail
            </a>

            <a href="/commitments/list.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>
</div>

</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>