<?php
$REQUIRE_PERMISSION = 'dispose_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_setup.php';

$dispId = (int) ($_GET['id'] ?? 0);
if ($dispId <= 0) { pop("Invalid disposal.", "/inventory/disposal/list.php", 1800, 'warning'); exit; }

$disp = $pdo->prepare("
    SELECT d.*, u.full_name AS requester_name, au.full_name AS approver_name,
           l.location_code, l.site_name
    FROM inv_disposals d
    LEFT JOIN users u ON d.requested_by = u.user_id
    LEFT JOIN users au ON d.approved_by = au.user_id
    LEFT JOIN inv_locations l ON d.location_id = l.location_id
    WHERE d.disposal_id = ?
");
$disp->execute([$dispId]);
$disp = $disp->fetch(PDO::FETCH_ASSOC);
if (!$disp) { pop("Disposal not found.", "/inventory/disposal/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT di.*, i.item_code, i.item_name, um.uom_code
    FROM inv_disposal_items di
    JOIN inv_items i ON di.item_id = i.item_id
    LEFT JOIN inv_units_of_measure um ON i.uom_id = um.uom_id
    WHERE di.disposal_id = ?
");
$lineItems->execute([$dispId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* Handle workflow actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/disposal/view.php?id=' . $dispId);
        $pdo->beginTransaction();

        if ($action === 'complete_survey' && $disp['status'] === 'PENDING_SURVEY') {
            $surveyNotes = trim($_POST['survey_notes'] ?? '');
            $pdo->prepare("UPDATE inv_disposals SET status = 'PENDING_APPROVAL', survey_notes = ?, survey_completed_by = ?, survey_completed_at = NOW() WHERE disposal_id = ?")
                ->execute([$surveyNotes, $_SESSION['user_id'], $dispId]);
            logInventoryAudit($pdo, 'inv_disposals', $dispId, 'SURVEYED', "Survey completed");

        } elseif ($action === 'approve' && has_permission('approve_disposal') && $disp['status'] === 'PENDING_APPROVAL') {
            if ($_SESSION['user_id'] == $disp['requested_by']) {
                throw new Exception("Cannot approve your own disposal (segregation of duties).");
            }
            $pdo->prepare("UPDATE inv_disposals SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE disposal_id = ?")
                ->execute([$_SESSION['user_id'], $dispId]);

            // Lock disposal document
            lockDocumentByReference($pdo, 'inv_disposals', $dispId);

            logInventoryAudit($pdo, 'inv_disposals', $dispId, 'APPROVED', "Disposal approved");

        } elseif ($action === 'complete' && $disp['status'] === 'APPROVED') {
            // Enforce period and freeze controls
            requireOpenPeriod($pdo);

            $proceeds = (float) ($_POST['actual_proceeds'] ?? 0);
            InventoryService::completeDisposalStock($pdo, $disp, $lineItems);
            $pdo->prepare("UPDATE inv_disposals SET status = 'COMPLETED', actual_proceeds = ?, completed_at = NOW() WHERE disposal_id = ?")
                ->execute([$proceeds, $dispId]);
            logInventoryAudit($pdo, 'inv_disposals', $dispId, 'COMPLETED', "Disposal completed, proceeds: $proceeds");

        } elseif ($action === 'reject' && has_permission('approve_disposal') && $disp['status'] === 'PENDING_APPROVAL') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) throw new Exception("Rejection reason is required.");
            $pdo->prepare("UPDATE inv_disposals SET status = 'REJECTED', notes = CONCAT(IFNULL(notes,''), '\nRejected: ', ?) WHERE disposal_id = ?")
                ->execute([$reason, $dispId]);
            logInventoryAudit($pdo, 'inv_disposals', $dispId, 'REJECTED', "Rejected: $reason");
        } else {
            throw new Exception("Invalid action for current status.");
        }

        $pdo->commit();
        pop("Disposal updated.", "/inventory/disposal/view.php?id=$dispId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-trash3"></i> Disposal <?= htmlspecialchars($disp['disposal_number']) ?></h2>
    <a href="/inventory/disposal/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Disposal #:</strong> <?= htmlspecialchars($disp['disposal_number']) ?></div>
                    <div class="col-md-4"><strong>Method:</strong> <?= str_replace('_', ' ', htmlspecialchars($disp['disposal_method'])) ?></div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($disp['status']) { 'COMPLETED' => 'success', 'APPROVED' => 'info', 'PENDING_APPROVAL' => 'warning', 'PENDING_SURVEY' => 'primary', 'REJECTED' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $disp['status']) ?></span>
                    </div>
                    <div class="col-md-4"><strong>Location:</strong> <?= htmlspecialchars($disp['location_code'] . ' - ' . $disp['site_name']) ?></div>
                    <div class="col-md-4"><strong>Requested By:</strong> <?= htmlspecialchars($disp['requester_name']) ?></div>
                    <div class="col-md-4"><strong>Date:</strong> <?= date('Y-m-d', strtotime($disp['created_at'])) ?></div>
                    <div class="col-12"><strong>Reason:</strong> <?= htmlspecialchars($disp['reason']) ?></div>
                    <?php if ($disp['approved_by']): ?>
                    <div class="col-md-4"><strong>Approved By:</strong> <?= htmlspecialchars($disp['approver_name']) ?></div>
                    <?php endif; ?>
                    <?php if ($disp['actual_proceeds']): ?>
                    <div class="col-md-4"><strong>Actual Proceeds:</strong> $<?= number_format($disp['actual_proceeds'], 2) ?></div>
                    <?php endif; ?>
                    <?php if ($disp['notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($disp['notes'])) ?></div>
                    <?php endif; ?>
                    <?php if ($disp['survey_notes']): ?>
                    <div class="col-12"><strong>Survey Notes:</strong> <?= nl2br(htmlspecialchars($disp['survey_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($disp['status'] === 'PENDING_SURVEY'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <textarea name="survey_notes" class="form-control mb-2" rows="3" placeholder="Survey findings..."></textarea>
            <button type="submit" name="action" value="complete_survey" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-clipboard-check"></i> Complete Survey
            </button>
        </form>
        <?php endif; ?>

        <?php if ($disp['status'] === 'PENDING_APPROVAL' && has_permission('approve_disposal')): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg mb-2">
                <i class="bi bi-check-circle"></i> Approve Disposal
            </button>
            <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Rejection reason..."></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>

        <?php if ($disp['status'] === 'APPROVED'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <label class="form-label">Actual Proceeds ($)</label>
            <input type="number" step="0.01" name="actual_proceeds" class="form-control mb-2">
            <button type="submit" name="action" value="complete" class="btn btn-success w-100 btn-lg">
                <i class="bi bi-check-circle"></i> Complete Disposal
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="bi bi-list-ol"></i> Disposal Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item Code</th><th>Item Name</th><th class="text-end">Quantity</th><th>Condition</th><th class="text-end">Est. Value</th><th>UOM</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td><?= htmlspecialchars($li['item_name']) ?></td>
                        <td class="text-end fw-bold"><?= number_format($li['quantity'], 2) ?></td>
                        <td><?= htmlspecialchars($li['condition_description'] ?: '-') ?></td>
                        <td class="text-end">$<?= number_format($li['estimated_value'] ?? 0, 2) ?></td>
                        <td><?= htmlspecialchars($li['uom_code'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>