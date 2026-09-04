<?php
$REQUIRE_PERMISSION = 'manage_returns';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_compliance_setup.php';

$returnId = (int) ($_GET['id'] ?? 0);
if ($returnId <= 0) { pop("Invalid return.", "/inventory/returns/list.php", 1800, 'warning'); exit; }

$return = $pdo->prepare("
    SELECT r.*, u.full_name AS requested_by_name, ua.full_name AS approved_by_name,
           l.location_code, l.site_name
    FROM inv_returns r
    LEFT JOIN users u ON r.requested_by = u.user_id
    LEFT JOIN users ua ON r.approved_by = ua.user_id
    LEFT JOIN inv_locations l ON r.from_location_id = l.location_id
    WHERE r.return_id = ?
");
$return->execute([$returnId]);
$return = $return->fetch(PDO::FETCH_ASSOC);
if (!$return) { pop("Return not found.", "/inventory/returns/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT ri.*, i.item_code, i.item_name, i.unit_of_measure
    FROM inv_return_items ri
    JOIN inv_items i ON ri.item_id = i.item_id
    WHERE ri.return_id = ?
");
$lineItems->execute([$returnId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/returns/view.php?id=' . $returnId);
        $pdo->beginTransaction();

        if ($action === 'submit' && $return['status'] === 'DRAFT') {
            $pdo->prepare("UPDATE inv_returns SET status = 'PENDING_APPROVAL' WHERE return_id = ?")->execute([$returnId]);
            logInventoryAudit($pdo, 'inv_returns', $returnId, 'SUBMITTED', "Return submitted for approval");

        } elseif ($action === 'approve' && $return['status'] === 'PENDING_APPROVAL') {
            if ((int) $return['requested_by'] === (int) $_SESSION['user_id']) {
                throw new Exception("Segregation of duties: approver cannot be the requester.");
            }
            $pdo->prepare("UPDATE inv_returns SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE return_id = ?")
                ->execute([$_SESSION['user_id'], $returnId]);
            lockDocumentByReference($pdo, 'RETURN', $return['return_number']);
            logInventoryAudit($pdo, 'inv_returns', $returnId, 'APPROVED', "Return approved");

        } elseif ($action === 'reject' && $return['status'] === 'PENDING_APPROVAL') {
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');
            $pdo->prepare("UPDATE inv_returns SET status = 'CANCELLED', notes = CONCAT(COALESCE(notes,''), '\nRejected: ', ?) WHERE return_id = ?")
                ->execute([$rejectionReason, $returnId]);
            logInventoryAudit($pdo, 'inv_returns', $returnId, 'REJECTED', "Return rejected: $rejectionReason");

        } elseif ($action === 'dispatch' && $return['status'] === 'APPROVED') {
            requireOpenPeriod($pdo);
            if ($return['from_location_id']) {
                requireLocationNotFrozen($pdo, $return['from_location_id']);
            }
            // Deduct stock for each line item
            foreach ($lineItems as $li) {
                if ($return['from_location_id']) {
                    InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $return['from_location_id'], (float) $li['quantity'], 'subtract');
                    InventoryService::recordTransaction($pdo, (int) $li['item_id'], (int) $return['from_location_id'], 'RETURN_TO_SUPPLIER', (float) $li['quantity'],
                        $returnId, 'inv_returns', "Return {$return['return_number']}: {$return['return_type']}", $_SESSION['user_id'],
                        $li['batch_lot_number'] ?? null, null, $li['serial_number'] ?? null, null);
                }
            }
            $pdo->prepare("UPDATE inv_returns SET status = 'DISPATCHED', dispatched_at = NOW() WHERE return_id = ?")->execute([$returnId]);
            logInventoryAudit($pdo, 'inv_returns', $returnId, 'DISPATCHED', "Return dispatched to supplier");

        } elseif ($action === 'complete' && $return['status'] === 'DISPATCHED') {
            $pdo->prepare("UPDATE inv_returns SET status = 'COMPLETED', completed_at = NOW() WHERE return_id = ?")->execute([$returnId]);
            logInventoryAudit($pdo, 'inv_returns', $returnId, 'COMPLETED', "Return completed — supplier acknowledged receipt");

        } else {
            throw new Exception("Invalid action for current status.");
        }

        $pdo->commit();
        pop("Return updated.", "/inventory/returns/view.php?id=$returnId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

$totalValue = array_sum(array_map(fn($li) => $li['quantity'] * $li['unit_cost'], $lineItems));

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-return-left"></i> Return <?= htmlspecialchars($return['return_number']) ?></h2>
    <a href="/inventory/returns/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Type:</strong> <span class="badge bg-secondary"><?= str_replace('_', ' ', $return['return_type']) ?></span></div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($return['status']) { 'DRAFT' => 'secondary', 'PENDING_APPROVAL' => 'warning', 'APPROVED' => 'info', 'DISPATCHED' => 'primary', 'COMPLETED' => 'success', 'CANCELLED' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $return['status']) ?></span>
                    </div>
                    <div class="col-md-4"><strong>Total Value:</strong> $<?= number_format($totalValue, 2) ?></div>
                    <div class="col-md-6"><strong>Supplier:</strong> <?= htmlspecialchars($return['supplier_name'] ?: '-') ?></div>
                    <div class="col-md-6"><strong>GRN:</strong> <?= $return['grn_id'] ? '<a href="/inventory/receiving/view.php?id=' . $return['grn_id'] . '">View GRN</a>' : '-' ?></div>
                    <div class="col-md-4"><strong>RMA #:</strong> <?= htmlspecialchars($return['rma_number'] ?: '-') ?></div>
                    <div class="col-md-4"><strong>Debit Note #:</strong> <?= htmlspecialchars($return['debit_note_number'] ?: '-') ?></div>
                    <div class="col-md-4"><strong>From:</strong> <?= htmlspecialchars(($return['location_code'] ?? '') . ' ' . ($return['site_name'] ?? '-')) ?></div>
                    <div class="col-md-4"><strong>Requested By:</strong> <?= htmlspecialchars($return['requested_by_name']) ?></div>
                    <div class="col-md-4"><strong>Approved By:</strong> <?= htmlspecialchars($return['approved_by_name'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Created:</strong> <?= date('Y-m-d', strtotime($return['created_at'])) ?></div>
                    <div class="col-12"><strong>Reason:</strong> <?= nl2br(htmlspecialchars($return['reason'])) ?></div>
                    <?php if ($return['notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($return['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($return['status'] === 'DRAFT'): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="submit" class="btn btn-warning w-100 btn-lg"><i class="bi bi-send"></i> Submit for Approval</button>
        </form>
        <?php endif; ?>

        <?php if ($return['status'] === 'PENDING_APPROVAL'): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg"><i class="bi bi-check-circle"></i> Approve</button>
        </form>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <div class="mb-2"><input type="text" name="rejection_reason" class="form-control" placeholder="Rejection reason..."></div>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100"><i class="bi bi-x-circle"></i> Reject</button>
        </form>
        <?php endif; ?>

        <?php if ($return['status'] === 'APPROVED'): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="dispatch" class="btn btn-primary w-100 btn-lg"
                    onclick="return confirm('Dispatch return? Stock will be deducted.')">
                <i class="bi bi-truck"></i> Dispatch to Supplier
            </button>
        </form>
        <?php endif; ?>

        <?php if ($return['status'] === 'DISPATCHED'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="complete" class="btn btn-success w-100 btn-lg"><i class="bi bi-check2-all"></i> Mark Completed</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Line Items -->
<div class="card border-0 shadow-sm">
    <div class="card-header"><i class="bi bi-list-ul"></i> Return Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item</th><th>UoM</th><th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Line Total</th><th>Batch</th><th>Serial</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code> <?= htmlspecialchars($li['item_name']) ?></td>
                        <td><?= htmlspecialchars($li['unit_of_measure'] ?? '-') ?></td>
                        <td class="text-end fw-bold"><?= number_format($li['quantity'], 2) ?></td>
                        <td class="text-end">$<?= number_format($li['unit_cost'], 2) ?></td>
                        <td class="text-end">$<?= number_format($li['quantity'] * $li['unit_cost'], 2) ?></td>
                        <td><?= htmlspecialchars($li['batch_lot_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($li['serial_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($li['reason'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr><td colspan="4" class="text-end fw-bold">Total:</td><td class="text-end fw-bold">$<?= number_format($totalValue, 2) ?></td><td colspan="3"></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>