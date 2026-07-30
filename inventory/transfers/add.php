<?php
$REQUIRE_PERMISSION = 'transfer_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$items = $pdo->query("SELECT item_id, item_code, item_name FROM inv_items WHERE item_status='ACTIVE' ORDER BY item_name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT location_id, location_code, site_name FROM inv_locations WHERE is_active=1 ORDER BY site_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $transferType  = $_POST['transfer_type'] ?? 'INTERNAL';
        $fromLocId     = (int) ($_POST['from_location_id'] ?? 0);
        $toLocId       = (int) ($_POST['to_location_id'] ?? 0);
        $reason        = trim($_POST['reason'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');

        if ($fromLocId <= 0 || $toLocId <= 0) throw new Exception("Both source and destination locations are required.");
        if ($fromLocId === $toLocId) throw new Exception("Source and destination must be different.");
        if (empty($reason)) throw new Exception("Transfer reason is required.");

        $itemIds = $_POST['item_id'] ?? [];
        $qtys    = $_POST['quantity'] ?? [];
        if (empty($itemIds) || count(array_filter($itemIds)) === 0) throw new Exception("At least one item is required.");

        // Inter-MDA requires higher approval
        $initialStatus = 'PENDING_APPROVAL';
        if ($transferType === 'INTER_MDA') {
            $initialStatus = 'PENDING_APPROVAL'; // Will require Financial Secretary approval
        }

        $transferNumber = InventoryService::generateDocNumber($pdo, 'TRF', 'inv_transfers', 'transfer_number');

        $pdo->prepare("INSERT INTO inv_transfers
            (transfer_number, transfer_type, source_location_id, destination_location_id, requested_by,
             notes, status, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$transferNumber, $transferType, $fromLocId, $toLocId,
                $_SESSION['user_id'], ($reason ? $reason . "\n" : '') . $notes, $initialStatus]);

        $transferId = $pdo->lastInsertId();

        $insertItem = $pdo->prepare("INSERT INTO inv_transfer_items
            (transfer_id, item_id, quantity, batch_lot_number, serial_number)
            VALUES (?,?,?,?,?)");

        for ($i = 0; $i < count($itemIds); $i++) {
            $iid = (int) ($itemIds[$i] ?? 0);
            if ($iid <= 0) continue;
            $qty = (float) ($qtys[$i] ?? 0);
            if ($qty <= 0) continue;

            // Verify stock availability
            $stock = InventoryService::getStockLevel($pdo, $iid, $fromLocId);
            if ($stock < $qty) {
                $name = $pdo->query("SELECT item_name FROM inv_items WHERE item_id=$iid")->fetchColumn();
                throw new Exception("Insufficient stock for $name at source. Available: $stock");
            }

            $insertItem->execute([$transferId, $iid, $qty,
                $_POST['lot_number'][$i] ?? $_POST['batch_number'][$i] ?? null, $_POST['serial_number'][$i] ?? null]);
        }

        logInventoryAudit($pdo, 'inv_transfers', $transferId, 'CREATED', "Transfer $transferNumber ($transferType)");
        $pdo->commit();
        pop("Transfer $transferNumber created.", "/inventory/transfers/view.php?id=$transferId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> New Stock Transfer</h2>
    <a href="/inventory/transfers/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-dark"><i class="bi bi-info-circle"></i> Transfer Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Transfer Type <span class="text-danger">*</span></label>
                    <select name="transfer_type" class="form-select" required id="transferType">
                        <option value="INTERNAL">Internal (Same Branch)</option>
                        <option value="INTER_BRANCH">Inter-Branch</option>
                        <option value="INTER_MDA">Inter-MDA (Requires FS Approval)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">From Location <span class="text-danger">*</span></label>
                    <select name="from_location_id" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_code'] . ' - ' . $loc['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">To Location <span class="text-danger">*</span></label>
                    <select name="to_location_id" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_code'] . ' - ' . $loc['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <input type="text" name="reason" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="1"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div id="interMdaAlert" class="alert alert-warning d-none mb-4">
        <i class="bi bi-exclamation-triangle"></i> Inter-MDA transfers require Financial Secretary approval before completion.
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-dark d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ol"></i> Items to Transfer</span>
            <button type="button" class="btn btn-sm btn-light" onclick="addTransferRow()"><i class="bi bi-plus"></i> Add Row</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-dark">
                        <tr><th>Item <span class="text-danger">*</span></th><th>Lot #</th><th>Batch #</th><th>Serial #</th><th>Quantity <span class="text-danger">*</span></th><th>Available</th><th></th></tr>
                    </thead>
                    <tbody id="transferBody">
                        <tr>
                            <td>
                                <select name="item_id[]" class="form-select form-select-sm item-select" required onchange="fetchAvailableStock(this)">
                                    <option value="">--</option>
                                    <?php foreach ($items as $it): ?>
                                    <option value="<?= $it['item_id'] ?>"><?= htmlspecialchars($it['item_code'] . ' - ' . $it['item_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="lot_number[]" class="form-control form-control-sm"></td>
                            <td><input type="text" name="batch_number[]" class="form-control form-control-sm"></td>
                            <td><input type="text" name="serial_number[]" class="form-control form-control-sm"></td>
                            <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control form-control-sm text-end" required></td>
                            <td><small class="stock-available text-muted">—</small></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send"></i> Submit Transfer</button>
</form>

<script>
document.getElementById('transferType').addEventListener('change', function() {
    document.getElementById('interMdaAlert').classList.toggle('d-none', this.value !== 'INTER_MDA');
});
function addTransferRow() {
    const tbody = document.getElementById('transferBody');
    const row = tbody.querySelector('tr').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    row.querySelectorAll('.stock-available').forEach(el => { el.textContent = '—'; el.className = 'stock-available text-muted'; });
    const newSel = row.querySelector('.item-select');
    if (newSel) newSel.addEventListener('change', function() { fetchAvailableStock(this); });
    tbody.appendChild(row);
}
function fetchAvailableStock(selectEl) {
    const row = selectEl.closest('tr');
    const badge = row ? row.querySelector('.stock-available') : null;
    if (!badge) return;
    const itemId = selectEl.value;
    const fromLoc = document.querySelector('[name="from_location_id"]');
    const locId = fromLoc ? fromLoc.value : '';
    if (!itemId || !locId) { badge.textContent = '—'; badge.className = 'stock-available text-muted'; return; }
    fetch('/inventory/items/get_stock_level.php?item_id=' + encodeURIComponent(itemId) + '&location_id=' + encodeURIComponent(locId))
        .then(r => r.json())
        .then(d => {
            badge.textContent = d.available !== undefined ? Number(d.available).toFixed(2) : '—';
            badge.className = 'stock-available fw-bold ' + (parseFloat(d.available) > 0 ? 'text-success' : 'text-danger');
        })
        .catch(() => { badge.textContent = '—'; badge.className = 'stock-available text-muted'; });
}
// Refresh available stock when from_location changes
document.querySelector('[name="from_location_id"]').addEventListener('change', function() {
    document.querySelectorAll('#transferBody .item-select').forEach(sel => fetchAvailableStock(sel));
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>