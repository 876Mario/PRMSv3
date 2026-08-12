<?php
$REQUIRE_PERMISSION = 'manage_inventory_items';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$sourceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($sourceId <= 0) {
    pop("Invalid item ID.", "/inventory/items/list.php", 1800, 'warning');
    exit;
}

$source = getInventoryItem($pdo, $sourceId);
if (!$source) {
    pop("Item not found.", "/inventory/items/list.php", 1800, 'warning');
    exit;
}

/* Asset Register helpers */
$assetDetailsTableExists = (function (PDO $pdo): bool {
    try {
        $s = $pdo->prepare("SHOW TABLES LIKE 'inv_asset_details'");
        $s->execute();
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return false; }
})($pdo);

$sourceAssetDetail = [];
$isAssetDomain = in_array($source['item_domain'] ?? 'INVENTORY', ['ASSET', 'BOTH']);
if ($assetDetailsTableExists && $isAssetDomain) {
    try {
        $adStmt = $pdo->prepare("SELECT * FROM inv_asset_details WHERE item_id = ? LIMIT 1");
        $adStmt->execute([$sourceId]);
        $sourceAssetDetail = $adStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* ignore */ }
}

/* Source risk class IDs */
$sourceRiskIds = array_column(getItemRiskClasses($pdo, $sourceId), 'risk_class_id');

/* Get active locations for initial stock setup */
try {
    $locations = $pdo->query("SELECT location_id, location_code, COALESCE(site_name, site_campus, '') AS display_name FROM inv_locations WHERE is_active=1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    $locations = [];
    error_log("Failed to retrieve locations: " . $ex->getMessage());
}
$defaultLocationId = !empty($locations) ? $locations[0]['location_id'] : 0;

if (empty($locations)) {
    $error = "No active locations are available. Please contact an administrator to set up inventory locations.";
}

/* Handle POST — create the duplicate */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($locations)) {
        $error = "No active locations are available. Cannot duplicate item without location.";
    } else {
        try {
            $pdo->beginTransaction();

        $newCode = trim($_POST['new_item_code'] ?? '');
        if ($newCode === '') $newCode = generateItemCode($pdo);

        $newName = trim($_POST['new_item_name'] ?? '');
        if ($newName === '') throw new Exception("New item name is required.");

        /* Uniqueness check */
        $dupChk = $pdo->prepare("SELECT COUNT(*) FROM inv_items WHERE item_code = ?");
        $dupChk->execute([$newCode]);
        if ($dupChk->fetchColumn() > 0) {
            throw new Exception("Item Code '$newCode' is already in use. Please choose a different code.");
        }

        /* Validate initial quantity and location */
        $rawQty = $_POST['initial_quantity'] ?? '1';
        if (!is_numeric($rawQty)) {
            throw new Exception("Initial quantity must be a valid number.");
        }
        $initialQty = (float) $rawQty;
        if ($initialQty <= 0) {
            throw new Exception("Initial quantity must be greater than 0.");
        }
        
        $initialLocId = (int) ($_POST['initial_location_id'] ?? 0);
        $validLocationId = false;
        foreach ($locations as $loc) {
            if ($loc['location_id'] == $initialLocId) {
                $validLocationId = true;
                break;
            }
        }
        if (!$validLocationId) {
            throw new Exception("Invalid location selected.");
        }

        /* Validate and prepare unit cost */
        $unitCost = (float) ($_POST['standard_cost'] ?? $source['standard_cost'] ?? 0);
        if ($unitCost < 0) {
            throw new Exception("Unit cost cannot be negative.");
        }

        /* Copy inv_items record */
        $ins = $pdo->prepare("
            INSERT INTO inv_items (
                item_code, item_name, description, category_id, subcategory_id, uom_id,
                pack_size, barcode, manufacturer, brand, model, part_number,
                serial_number_flag, batch_lot_flag, expiry_date_flag, hazard_class_flag,
                storage_conditions, shelf_life_days, inspection_required, receiving_tolerance_pct,
                contract_reference, procurement_method,
                reorder_level, reorder_quantity, min_level, max_level, safety_stock,
                lead_time_days, economic_order_qty,
                standard_cost, last_cost, average_cost, valuation_method,
                funding_source, program_project_code, gl_account_code,
                criticality_id, acct_class_id, item_status, issue_policy,
                asset_inventory_boundary, item_domain, asset_type_id, inventory_type_id,
                asset_item_type_id, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?
            )
        ");
        $ins->execute([
            $newCode,
            $newName,
            $source['description'],
            $source['category_id'],
            $source['subcategory_id'],
            $source['uom_id'],
            $source['pack_size'],
            $source['barcode'],
            $source['manufacturer'],
            $source['brand'],
            $source['model'],
            $source['part_number'],
            $source['serial_number_flag'],
            $source['batch_lot_flag'],
            $source['expiry_date_flag'],
            $source['hazard_class_flag'],
            $source['storage_conditions'],
            $source['shelf_life_days'],
            $source['inspection_required'],
            $source['receiving_tolerance_pct'],
            $source['contract_reference'],
            $source['procurement_method'],
            $source['reorder_level'],
            $source['reorder_quantity'],
            $source['min_level'],
            $source['max_level'],
            $source['safety_stock'],
            $source['lead_time_days'],
            $source['economic_order_qty'],
            $source['standard_cost'],
            $source['last_cost'],
            $source['average_cost'],
            $source['valuation_method'],
            $source['funding_source'],
            $source['program_project_code'],
            $source['gl_account_code'],
            $source['criticality_id'],
            $source['acct_class_id'],
            $source['item_status'],
            $source['issue_policy'],
            $source['asset_inventory_boundary'],
            $source['item_domain'],
            $source['asset_type_id'],
            $source['inventory_type_id'],
            $source['asset_item_type_id'],
            $_SESSION['user_id'] ?? null,
        ]);

        $newItemId = (int) $pdo->lastInsertId();

        /* Copy risk classes */
        if (!empty($sourceRiskIds)) {
            $rcStmt = $pdo->prepare("INSERT INTO inv_item_risk_classes (item_id, risk_class_id) VALUES (?, ?)");
            foreach ($sourceRiskIds as $rcId) {
                $rcStmt->execute([$newItemId, (int) $rcId]);
            }
        }

        /* Copy asset register details (with inventory number cleared for uniqueness) */
        if ($assetDetailsTableExists && $isAssetDomain && !empty($sourceAssetDetail) && isset($_POST['copy_asset_details'])) {
            try {
                $adIns = $pdo->prepare("
                    INSERT INTO inv_asset_details
                        (item_id, asset_code, acquired_date, asset_condition, asset_status,
                         custodian_name, custodian_role, accountable_officer, secondary_custodian,
                         site, building, floor_room, address, location_id,
                         purchase_cost, disposal_date, disposal_amount, is_disposed,
                         warranty_provider, warranty_start_date, warranty_end_date,
                         warranty_period, warranty_reference, warranty_notes, warranty_status)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ad = $sourceAssetDetail;
                $adIns->execute([
                    $newItemId,
                    $ad['acquired_date']      ?? null,
                    $ad['asset_condition']    ?? null,
                    $ad['asset_status']       ?? null,
                    $ad['custodian_name']     ?? null,
                    $ad['custodian_role']     ?? null,
                    $ad['accountable_officer'] ?? $ad['custodian_name'] ?? null,   // accountable_officer
                    $ad['secondary_custodian'] ?? null,
                    $ad['site']               ?? null,
                    $ad['building']           ?? null,
                    $ad['floor_room']         ?? null,
                    $ad['address']            ?? null,
                    $ad['location_id']        ?? null,
                    isset($ad['purchase_cost']) ? (float) $ad['purchase_cost'] : null,
                    $ad['disposal_date']      ?? null,
                    isset($ad['disposal_amount']) ? (float) $ad['disposal_amount'] : null,
                    $ad['is_disposed']        ?? 0,
                    $ad['warranty_provider']  ?? null,
                    $ad['warranty_start_date'] ?? null,
                    $ad['warranty_end_date']  ?? null,
                    $ad['warranty_period']    ?? null,
                    $ad['warranty_reference'] ?? null,
                    $ad['warranty_notes']     ?? null,
                    $ad['warranty_status']    ?? null,
                ]);
                logInventoryAudit($pdo, 'inv_asset_details', $newItemId, 'CREATE',
                    "Asset Register record copied from item #{$sourceId} (inventory number cleared).");
            } catch (Throwable $adEx) {
                // Asset details copy is best-effort; log but don't abort
                error_log("Duplicate asset details copy failed for item {$newItemId}: " . $adEx->getMessage());
            }
        }

        /* Create initial stock record with user-specified quantity (defaults to 1) */
        $stockId = increaseStock($pdo, $newItemId, $initialLocId, $initialQty, ['unit_cost' => $unitCost]);
        recordStockTransaction($pdo, [
            'transaction_type' => 'RECEIPT',
            'item_id'          => $newItemId,
            'stock_id'         => $stockId,
            'location_id'      => $initialLocId,
            'quantity'         => $initialQty,
            'unit_cost'        => $unitCost,
            'notes'            => 'Initial stock set on item duplication from item #' . $sourceId,
        ]);

        /* Get location code for audit log */
        $locationCode = '';
        foreach ($locations as $loc) {
            if ($loc['location_id'] == $initialLocId) {
                $locationCode = $loc['location_code'];
                break;
            }
        }

        /* Log item creation first (chronologically first event) */
        logInventoryAudit($pdo, 'inv_items', $newItemId, 'CREATE',
           "Item duplicated from #{$sourceId} ({$source['item_code']}): new code $newCode - $newName");
        
        /* Then log stock creation */
        logInventoryAudit($pdo, 'inv_stock', $newItemId, 'OPENING_BALANCE',
           "Initial stock of $initialQty set at location {$locationCode} for duplicated item from #$sourceId");

        $pdo->commit();
        pop("Item duplicated successfully as '$newCode — $newName'.", "/inventory/items/edit.php?id=$newItemId", 1800, 'success');
        exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = extractDbMessage($e);
        }
    }
}
/* Suggested new code: append _2, _3, etc. checking all at once */
$suggestedCode = $source['item_code'];
$likePattern   = $source['item_code'] . '_%';
$existing = $pdo->prepare("SELECT item_code FROM inv_items WHERE item_code LIKE ?");
$existing->execute([$likePattern]);
$existingCodes = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

$suffix = 2;
while ($suffix <= 999) {
    $try = $source['item_code'] . '_' . $suffix;
    if (!isset($existingCodes[$try])) { $suggestedCode = $try; break; }
    $suffix++;
}
if ($suffix > 999) $suggestedCode = generateItemCode($pdo);

$postCode = $_POST['new_item_code'] ?? $suggestedCode;
$postName = $_POST['new_item_name'] ?? 'Copy of ' . $source['item_name'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-copy"></i> Duplicate Asset / Item</h2>
    <a href="/inventory/items/view.php?id=<?= $sourceId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Item
    </a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Source item summary -->
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-info">
    <div class="card-body">
        <h6 class="text-muted mb-2"><i class="bi bi-box-seam"></i> Source Item (being duplicated)</h6>
        <div class="row g-2">
            <div class="col-md-3"><strong>Code:</strong> <code><?= htmlspecialchars($source['item_code']) ?></code></div>
            <div class="col-md-5"><strong>Name:</strong> <?= htmlspecialchars($source['item_name']) ?></div>
            <div class="col-md-2"><strong>Domain:</strong> <?= htmlspecialchars($source['item_domain'] ?? 'INVENTORY') ?></div>
            <div class="col-md-2"><strong>Status:</strong> <?= htmlspecialchars($source['item_status']) ?></div>
            <?php if ($source['manufacturer'] || $source['model']): ?>
            <div class="col-md-5"><strong>Manufacturer / Model:</strong>
                <?= htmlspecialchars(implode(' / ', array_filter([$source['manufacturer'], $source['model']]))) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<form method="POST">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-pencil-square"></i> New Item Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">New Item Code <span class="text-danger">*</span></label>
                    <input type="text" name="new_item_code" class="form-control" required
                           value="<?= htmlspecialchars($postCode) ?>">
                    <small class="text-muted">Auto-suggested based on source code. Modify if needed.</small>
                </div>
                <div class="col-md-8">
                    <label class="form-label">New Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="new_item_name" class="form-control" required
                           value="<?= htmlspecialchars($postName) ?>">
                </div>
            </div>

            <hr class="my-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Initial Location <span class="text-danger">*</span></label>
                    <select name="initial_location_id" class="form-select" required>
                        <option value="">-- Select Location --</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int)$loc['location_id'] ?>" <?= $loc['location_id'] == ($_POST['initial_location_id'] ?? $defaultLocationId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['location_code']) ?> — <?= htmlspecialchars($loc['display_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Where to store the initial quantity</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Initial Quantity <span class="text-danger">*</span></label>
                    <input type="number" name="initial_quantity" class="form-control" required step="0.01" min="0.01" 
                           value="<?= htmlspecialchars($_POST['initial_quantity'] ?? '1') ?>">
                    <small class="text-muted">Must be greater than 0. Defaults to 1.</small>
                </div>
            </div>

            <?php if ($isAssetDomain && !empty($sourceAssetDetail)): ?>
            <hr class="my-3">
            <div class="form-check form-switch">
               <input class="form-check-input" type="checkbox" name="copy_asset_details" id="copyAssetDetails"
                      <?= isset($_POST['copy_asset_details']) ? 'checked' : '' ?>>
               <label class="form-check-label" for="copyAssetDetails">
                   Copy Asset Register details (custodian, location, warranty, etc.)
               </label>
            </div>
            <small class="text-muted d-block mt-1">
               The Inventory Number will be <strong>cleared</strong> on the duplicate — you must assign a new unique number after saving.
            </small>
            <?php endif; ?>

            <div class="alert alert-info mt-3 mb-0 small">
                <i class="bi bi-info-circle"></i>
                All other fields (category, manufacturer, model, tracking flags, costs, etc.) are copied from the source item.
                You can edit them after the duplicate is created.
            </div>
        </div>
    </div>

    <div class="text-end mb-4">
        <a href="/inventory/items/view.php?id=<?= $sourceId ?>" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-copy"></i> Create Duplicate
        </button>
    </div>
</form>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
