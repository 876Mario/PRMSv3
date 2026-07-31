<?php
$REQUIRE_PERMISSION = 'manage_quarantine';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_compliance_setup.php';

$locations = $pdo->query("SELECT location_id, location_code, site_name FROM inv_locations WHERE is_active=1 ORDER BY site_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $qty = (float) ($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $batchLot = trim($_POST['batch_lot_number'] ?? '') ?: null;
        $serial = trim($_POST['serial_number'] ?? '') ?: null;

        if ($itemId <= 0) throw new Exception("Item is required.");
        if ($locationId <= 0) throw new Exception("Location is required.");
        if ($qty <= 0) throw new Exception("Quantity must be greater than 0.");
        if (empty($reason)) throw new Exception("Reason for quarantine is required.");

        // Check stock availability
        $available = InventoryService::getStockLevel($pdo, $itemId, $locationId);
        if ($available < $qty) {
            throw new Exception("Insufficient usable stock. Available: $available");
        }

        $quarantineId = quarantineStock($pdo, $itemId, $locationId, $qty, $reason, $batchLot, $serial);
        logInventoryAudit($pdo, 'inv_quarantine_log', $quarantineId, 'QUARANTINED', "Stock quarantined: $reason");

        $pdo->commit();
        pop("Stock quarantined successfully.", "/inventory/quarantine/view.php?id=$quarantineId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shield-exclamation"></i> Quarantine Stock</h2>
    <a href="/inventory/quarantine/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Item *</label>
                    <select name="item_id" class="form-select item-select" required>
                        <option value="">-- Search by code or name --</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location *</label>
                    <select name="location_id" class="form-select" required>
                        <option value="">Select location...</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_code'] . ' — ' . $loc['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Quantity *</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Batch/Lot Number</label>
                    <input type="text" name="batch_lot_number" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Reason for Quarantine *</label>
                    <textarea name="reason" class="form-control" rows="3" required placeholder="Describe the reason for quarantine..."></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-danger btn-lg"><i class="bi bi-shield-exclamation"></i> Quarantine Stock</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.item-select').select2({
        theme: 'bootstrap-5',
        placeholder: '-- Search by code or name --',
        allowClear: true,
        ajax: {
            url: '/inventory/items/search_api.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    limit: 50
                };
            },
            processResults: function(data) {
                return {
                    results: data.results || [],
                    pagination: data.pagination || {}
                };
            },
            cache: true
        },
        minimumInputLength: 1
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>