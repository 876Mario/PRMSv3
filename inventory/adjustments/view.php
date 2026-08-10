<?php
$REQUIRE_PERMISSION = 'adjust_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$adjId = (int) ($_GET['id'] ?? 0);
if ($adjId <= 0) { pop("Invalid adjustment.", "/inventory/adjustments/list.php", 1800, 'warning'); exit; }

$adj = $pdo->prepare("
    SELECT a.*, u.full_name AS creator_name, au.full_name AS approver_name,
           l.location_code, l.site_name
    FROM inv_adjustments a
    LEFT JOIN users u ON a.requested_by = u.user_id
    LEFT JOIN users au ON a.supervisor_approved_by = au.user_id
    LEFT JOIN inv_locations l ON a.location_id = l.location_id
    WHERE a.adjustment_id = ?
");
$adj->execute([$adjId]);
$adj = $adj->fetch(PDO::FETCH_ASSOC);
if (!$adj) { pop("Adjustment not found.", "/inventory/adjustments/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT ai.*, i.item_code, i.item_name, um.uom_code
    FROM inv_adjustment_items ai
    JOIN inv_items i ON ai.item_id = i.item_id
    LEFT JOIN inv_units_of_measure um ON i.uom_id = um.uom_id
    WHERE ai.adjustment_id = ?
");
$lineItems->execute([$adjId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* Handle approval */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_permission('approve_adjustment')) {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        if ($action === 'approve') {
            if (!in_array($adj['status'], ['PENDING_APPROVAL', 'UNDER_INVESTIGATION'])) {
                throw new Exception("Adjustment is not in an approvable state.");
            }
            if ($_SESSION['user_id'] == $adj['requested_by']) {
                throw new Exception("Cannot approve your own adjustment (segregation of duties).");
            }

            // Enforce period and freeze controls
            requireOpenPeriod($pdo);
            requireLocationNotFrozen($pdo, $adj['location_id']);

            // Apply stock changes
            foreach ($lineItems as $li) {
                $variance = $li['quantity_variance'];
                if ($variance > 0) {
                    InventoryService::updateStockLevel($pdo, $li['item_id'], $adj['location_id'], abs($variance), 'add');
                    InventoryService::recordTransaction($pdo, $li['item_id'], $adj['location_id'], 'ADJUSTMENT_IN', abs($variance),
                        $adjId, 'inv_adjustments', "Adjustment gain: " . $adj['reason_code'], $_SESSION['user_id']);
                } elseif ($variance < 0) {
                    InventoryService::updateStockLevel($pdo, $li['item_id'], $adj['location_id'], abs($variance), 'subtract');
                    InventoryService::recordTransaction($pdo, $li['item_id'], $adj['location_id'], 'ADJUSTMENT_OUT', abs($variance),
                        $adjId, 'inv_adjustments', "Adjustment loss: " . $adj['reason_code'], $_SESSION['user_id']);
                }
            }

            $pdo->prepare("UPDATE inv_adjustments SET status = 'APPROVED', supervisor_approved_by = ?, supervisor_approved_at = NOW() WHERE adjustment_id = ?")
                ->execute([$_SESSION['user_id'], $adjId]);

            // Lock document and create control record
            lockDocumentByReference($pdo, 'inv_adjustments', $adjId);

            logInventoryAudit($pdo, 'inv_adjustments', $adjId, 'APPROVED', "Stock adjustment approved and applied");

        } elseif ($action === 'reject') {
            if (!in_array($adj['status'], ['PENDING_APPROVAL', 'UNDER_INVESTIGATION'])) {
                throw new Exception("Adjustment cannot be rejected in its current state.");
            }
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) throw new Exception("Rejection reason is required.");
            $pdo->prepare("UPDATE inv_adjustments SET status = 'REJECTED', investigation_notes = CONCAT(IFNULL(investigation_notes,''), '\nRejected: ', ?) WHERE adjustment_id = ?")
                ->execute([$reason, $adjId]);
            logInventoryAudit($pdo, 'inv_adjustments', $adjId, 'REJECTED', "Rejected: $reason");

        } elseif ($action === 'investigate') {
            if ($adj['status'] !== 'PENDING_APPROVAL') {
                throw new Exception("Only pending adjustments can be sent for investigation.");
            }
            $pdo->prepare("UPDATE inv_adjustments SET status = 'UNDER_INVESTIGATION' WHERE adjustment_id = ?")
                ->execute([$adjId]);
            logInventoryAudit($pdo, 'inv_adjustments', $adjId, 'UNDER_INVESTIGATION', "Sent for investigation");

        } elseif ($action === 'resolve_investigation') {
            if ($adj['status'] !== 'UNDER_INVESTIGATION') {
                throw new Exception("Only adjustments under investigation can be resolved.");
            }
            $pdo->prepare("UPDATE inv_adjustments SET status = 'PENDING_APPROVAL' WHERE adjustment_id = ?")
                ->execute([$adjId]);
            logInventoryAudit($pdo, 'inv_adjustments', $adjId, 'PENDING_APPROVAL', "Investigation resolved — returned for approval");
        }

        $pdo->commit();
        pop("Adjustment updated.", "/inventory/adjustments/view.php?id=$adjId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-sliders"></i> Adjustment <?= htmlspecialchars($adj['adjustment_number']) ?></h2>
    <a href="/inventory/adjustments/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><strong>Adjustment #:</strong> <?= htmlspecialchars($adj['adjustment_number']) ?></div>
                    <div class="col-md-3"><strong>Type:</strong>
                        <span class="badge bg-<?= $adj['adjustment_type'] === 'GAIN' ? 'success' : 'danger' ?>"><?= $adj['adjustment_type'] ?></span>
                    </div>
                    <div class="col-md-3"><strong>Status:</strong>
                        <?php $sc = match($adj['status']) { 'APPROVED' => 'success', 'PENDING_APPROVAL' => 'warning', 'UNDER_INVESTIGATION' => 'info', 'REJECTED' => 'danger', 'COMPLETED' => 'primary', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= $adj['status'] ?></span>
                    </div>
                    <div class="col-md-3"><strong>Location:</strong> <?= htmlspecialchars($adj['location_code']) ?></div>
                    <div class="col-md-4"><strong>Reason:</strong> <?= str_replace('_', ' ', htmlspecialchars($adj['reason_code'])) ?></div>
                    <div class="col-md-4"><strong>Created By:</strong> <?= htmlspecialchars($adj['creator_name']) ?></div>
                    <div class="col-md-4"><strong>Date:</strong> <?= date('Y-m-d H:i', strtotime($adj['created_at'])) ?></div>
                    <?php if ($adj['supervisor_approved_by']): ?>
                    <div class="col-md-4"><strong>Approved By:</strong> <?= htmlspecialchars($adj['approver_name']) ?></div>
                    <?php endif; ?>
                    <?php if ($adj['reason_detail']): ?>
                    <div class="col-12"><strong>Description:</strong> <?= htmlspecialchars($adj['reason_detail']) ?></div>
                    <?php endif; ?>
                    <?php if ($adj['investigation_notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($adj['investigation_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if (in_array($adj['status'], ['PENDING_APPROVAL', 'UNDER_INVESTIGATION']) && has_permission('approve_adjustment')): ?>
        <form method="POST">
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg mb-2">
                <i class="bi bi-check-circle"></i> Approve & Apply
            </button>
            <?php if ($adj['status'] === 'PENDING_APPROVAL'): ?>
            <button type="submit" name="action" value="investigate" class="btn btn-info w-100 mb-2">
                <i class="bi bi-search"></i> Send for Investigation
            </button>
            <?php endif; ?>
            <?php if ($adj['status'] === 'UNDER_INVESTIGATION'): ?>
            <button type="submit" name="action" value="resolve_investigation" class="btn btn-secondary w-100 mb-2">
                <i class="bi bi-arrow-counterclockwise"></i> Close Investigation &amp; Return for Approval
            </button>
            <?php endif; ?>
            <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Rejection reason..."></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="bi bi-list-ol"></i> Adjustment Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item Code</th><th>Item Name</th><th class="text-end">System Qty</th><th class="text-end">Actual Qty</th><th class="text-end">Variance</th><th>UOM</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td><?= htmlspecialchars($li['item_name']) ?></td>
                        <td class="text-end"><?= number_format($li['quantity_system'], 2) ?></td>
                        <td class="text-end"><?= number_format($li['quantity_actual'], 2) ?></td>
                        <td class="text-end fw-bold text-<?= $li['quantity_variance'] >= 0 ? 'success' : 'danger' ?>">
                            <?= $li['quantity_variance'] >= 0 ? '+' : '' ?><?= number_format($li['quantity_variance'], 2) ?>
                        </td>
                        <td><?= htmlspecialchars($li['uom_code'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
