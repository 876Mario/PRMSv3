<?php
$REQUIRE_PERMISSION = 'transfer_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_setup.php';

$transferId = (int) ($_GET['id'] ?? 0);
if ($transferId <= 0) { pop("Invalid transfer.", "/inventory/transfers/list.php", 1800, 'warning'); exit; }

$transfer = $pdo->prepare("
    SELECT t.*, u.full_name AS initiator_name, au.full_name AS approver_name,
           fl.location_code AS from_loc, fl.site_name AS from_site,
           tl.location_code AS to_loc, tl.site_name AS to_site
    FROM inv_transfers t
    LEFT JOIN users u ON t.requested_by = u.user_id
    LEFT JOIN users au ON t.approved_by = au.user_id
    LEFT JOIN inv_locations fl ON t.source_location_id = fl.location_id
    LEFT JOIN inv_locations tl ON t.destination_location_id = tl.location_id
    WHERE t.transfer_id = ?
");
$transfer->execute([$transferId]);
$transfer = $transfer->fetch(PDO::FETCH_ASSOC);
if (!$transfer) { pop("Transfer not found.", "/inventory/transfers/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT ti.*, i.item_code, i.item_name, um.uom_code
    FROM inv_transfer_items ti
    JOIN inv_items i ON ti.item_id = i.item_id
    LEFT JOIN inv_units_of_measure um ON i.uom_id = um.uom_id
    WHERE ti.transfer_id = ?
");
$lineItems->execute([$transferId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* Handle approval / dispatch / receive actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/transfers/view.php?id=' . $transferId);
        $pdo->beginTransaction();

        if ($action === 'approve' && has_permission('approve_transfer') && $transfer['status'] === 'PENDING_APPROVAL') {
            if ($_SESSION['user_id'] == $transfer['requested_by']) {
                throw new Exception("Cannot approve your own transfer (segregation of duties).");
            }

            // For INTER_MDA transfers, require Financial Secretary approval
            if ($transfer['transfer_type'] === 'INTER_MDA') {
                $pdo->prepare("UPDATE inv_transfers SET status = 'PENDING_FS_APPROVAL', approved_by = ?, approved_at = NOW() WHERE transfer_id = ?")
                    ->execute([$_SESSION['user_id'], $transferId]);
                lockDocumentByReference($pdo, 'inv_transfers', $transferId);
                logInventoryAudit($pdo, 'inv_transfers', $transferId, 'APPROVED', "Transfer approved — awaiting Financial Secretary approval");
            } else {
                foreach ($lineItems as $li) {
                    reserveStock($pdo, (int) $li['item_id'], (int) $transfer['source_location_id'], (float) $li['quantity']);
                }

                $pdo->prepare("UPDATE inv_transfers SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE transfer_id = ?")
                    ->execute([$_SESSION['user_id'], $transferId]);
                lockDocumentByReference($pdo, 'inv_transfers', $transferId);
                logInventoryAudit($pdo, 'inv_transfers', $transferId, 'APPROVED', "Transfer approved and stock reserved");
            }

        } elseif ($action === 'fs_approve' && $transfer['status'] === 'PENDING_FS_APPROVAL') {
            // Financial Secretary approval for inter-MDA transfers
            foreach ($lineItems as $li) {
                reserveStock($pdo, (int) $li['item_id'], (int) $transfer['source_location_id'], (float) $li['quantity']);
            }

            $pdo->prepare("UPDATE inv_transfers SET financial_secretary_approval = 1, fs_approved_by = ?, fs_approved_at = NOW(), status = 'APPROVED' WHERE transfer_id = ?")
                ->execute([$_SESSION['user_id'], $transferId]);
            logInventoryAudit($pdo, 'inv_transfers', $transferId, 'FS_APPROVED', "Financial Secretary approval granted and stock reserved");

        } elseif ($action === 'dispatch' && $transfer['status'] === 'APPROVED') {
            // Enforce period and freeze controls
            requireOpenPeriod($pdo);
            requireLocationNotFrozen($pdo, $transfer['source_location_id']);

            // Deduct stock from source
            foreach ($lineItems as $li) {
                $reservedQtyStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity_reserved), 0)
                    FROM inv_stock
                    WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
                ");
                $reservedQtyStmt->execute([(int) $li['item_id'], (int) $transfer['source_location_id']]);
                $reservedQty = (float) $reservedQtyStmt->fetchColumn();

                if ($reservedQty + 0.0001 >= (float) $li['quantity']) {
                    consumeReservedStock($pdo, (int) $li['item_id'], (int) $transfer['source_location_id'], (float) $li['quantity']);
                } else {
                    InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $transfer['source_location_id'], (float) $li['quantity'], 'subtract');
                }
                InventoryService::recordTransaction($pdo, $li['item_id'], $transfer['source_location_id'], 'TRANSFER_OUT', $li['quantity'],
                    $transferId, 'inv_transfers', "Transfer to " . $transfer['to_loc'], $_SESSION['user_id'],
                    $li['batch_lot_number'], null, $li['serial_number'], null);
            }
            $pdo->prepare("UPDATE inv_transfers SET status = 'IN_TRANSIT', dispatched_at = NOW() WHERE transfer_id = ?")
                ->execute([$transferId]);
            logInventoryAudit($pdo, 'inv_transfers', $transferId, 'DISPATCHED', "Stock dispatched");

        } elseif ($action === 'receive' && $transfer['status'] === 'IN_TRANSIT') {
            requireOpenPeriod($pdo);
            requireLocationNotFrozen($pdo, $transfer['destination_location_id']);

            // Add stock to destination
            foreach ($lineItems as $li) {
                $qtyReceived = (float) ($_POST['qty_received'][$li['transfer_item_id']] ?? $li['quantity']);
                if ($qtyReceived < 0) {
                    throw new Exception("Received quantity cannot be negative.");
                }
                if ($qtyReceived > (float) $li['quantity']) {
                    throw new Exception("Received quantity cannot exceed the dispatched quantity.");
                }
                $pdo->prepare("UPDATE inv_transfer_items SET quantity_received = ? WHERE transfer_item_id = ?")
                    ->execute([$qtyReceived, $li['transfer_item_id']]);

                if ($qtyReceived > 0) {
                    InventoryService::updateStockLevel($pdo, $li['item_id'], $transfer['destination_location_id'], $qtyReceived, 'add');
                    InventoryService::recordTransaction($pdo, $li['item_id'], $transfer['destination_location_id'], 'TRANSFER_IN', $qtyReceived,
                        $transferId, 'inv_transfers', "Transfer from " . $transfer['from_loc'], $_SESSION['user_id'],
                        $li['batch_lot_number'], null, $li['serial_number'], null);
                }
            }
            $pdo->prepare("UPDATE inv_transfers SET status = 'COMPLETED', received_at = NOW() WHERE transfer_id = ?")
                ->execute([$transferId]);
            logInventoryAudit($pdo, 'inv_transfers', $transferId, 'COMPLETED', "Transfer received");

        } elseif ($action === 'reject' && has_permission('approve_transfer') && in_array($transfer['status'], ['PENDING_APPROVAL', 'PENDING_FS_APPROVAL'], true)) {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) throw new Exception("Rejection reason is required.");
            $pdo->prepare("UPDATE inv_transfers SET status = 'CANCELLED', notes = CONCAT(IFNULL(notes,''), '\nRejected: ', ?) WHERE transfer_id = ?")
                ->execute([$reason, $transferId]);
            logInventoryAudit($pdo, 'inv_transfers', $transferId, 'REJECTED', "Rejected: $reason");
        } else {
            throw new Exception("Invalid action for current status.");
        }

        $pdo->commit();
        pop("Transfer updated.", "/inventory/transfers/view.php?id=$transferId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> Transfer <?= htmlspecialchars($transfer['transfer_number']) ?></h2>
    <a href="/inventory/transfers/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Transfer #:</strong> <?= htmlspecialchars($transfer['transfer_number']) ?></div>
                    <div class="col-md-4"><strong>Type:</strong>
                        <span class="badge bg-<?= $transfer['transfer_type'] === 'INTER_MDA' ? 'danger' : ($transfer['transfer_type'] === 'INTER_BRANCH' ? 'warning' : 'info') ?>">
                            <?= str_replace('_', ' ', $transfer['transfer_type']) ?>
                        </span>
                    </div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($transfer['status']) { 'COMPLETED' => 'success', 'IN_TRANSIT' => 'info', 'APPROVED' => 'primary', 'PENDING_APPROVAL' => 'warning', 'PENDING_FS_APPROVAL' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $transfer['status']) ?></span>
                    </div>
                    <div class="col-md-4"><strong>From:</strong> <?= htmlspecialchars($transfer['from_loc'] . ' - ' . $transfer['from_site']) ?></div>
                    <div class="col-md-4"><strong>To:</strong> <?= htmlspecialchars($transfer['to_loc'] . ' - ' . $transfer['to_site']) ?></div>
                    <div class="col-md-4"><strong>Initiated By:</strong> <?= htmlspecialchars($transfer['initiator_name']) ?></div>
                    <?php if ($transfer['approved_by']): ?>
                    <div class="col-md-4"><strong>Approved By:</strong> <?= htmlspecialchars($transfer['approver_name']) ?></div>
                    <?php endif; ?>
                    <div class="col-md-6"><strong>Reason:</strong> <?= htmlspecialchars($transfer['notes'] ?? '-') ?></div>
                    <?php if ($transfer['notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($transfer['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($transfer['status'] === 'PENDING_APPROVAL' && has_permission('approve_transfer')): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg mb-2">
                <i class="bi bi-check-circle"></i> Approve Transfer
            </button>
            <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Rejection reason..."></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>

        <?php if ($transfer['status'] === 'PENDING_FS_APPROVAL'): ?>
        <div class="alert alert-warning mb-2">
            <i class="bi bi-exclamation-triangle"></i> <strong>Inter-MDA Transfer</strong> — Requires Financial Secretary approval per GoJ Financial Instructions.
        </div>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="fs_approve" class="btn btn-warning w-100 btn-lg mb-2">
                <i class="bi bi-shield-check"></i> Financial Secretary Approval
            </button>
            <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Rejection reason..."></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>

        <?php if ($transfer['status'] === 'APPROVED'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="dispatch" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-truck"></i> Dispatch Stock
            </button>
        </form>
        <?php endif; ?>

        <?php if ($transfer['status'] === 'IN_TRANSIT'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <p class="text-muted small">Confirm quantities received:</p>
            <?php foreach ($lineItems as $li): ?>
            <div class="input-group mb-2">
                <span class="input-group-text"><?= htmlspecialchars($li['item_code']) ?></span>
                <input type="number" step="0.01" name="qty_received[<?= $li['transfer_item_id'] ?>]" class="form-control text-end" value="<?= $li['quantity'] ?>" max="<?= $li['quantity'] ?>">
            </div>
            <?php endforeach; ?>
            <button type="submit" name="action" value="receive" class="btn btn-success w-100 btn-lg">
                <i class="bi bi-check-circle"></i> Confirm Receipt
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="bi bi-list-ol"></i> Transfer Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item Code</th><th>Item Name</th><th>Lot</th><th>Batch</th><th>Serial</th><th class="text-end">Qty Sent</th><th class="text-end">Qty Received</th><th>UOM</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td><?= htmlspecialchars($li['item_name']) ?></td>
                        <td><?= htmlspecialchars($li['batch_lot_number'] ?: '-') ?></td>
                        <td>-</td>
                        <td><?= htmlspecialchars($li['serial_number'] ?: '-') ?></td>
                        <td class="text-end fw-bold"><?= number_format($li['quantity'], 2) ?></td>
                        <td class="text-end"><?= $li['quantity_received'] !== null ? number_format($li['quantity_received'], 2) : '-' ?></td>
                        <td><?= htmlspecialchars($li['uom_code'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>