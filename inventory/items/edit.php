<?php
$REQUIRE_PERMISSION = 'manage_inventory_items';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$itemId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($itemId <= 0) { pop("Invalid item ID.", "/inventory/items/list.php", 1800, 'warning'); exit; }

$item = getInventoryItem($pdo, $itemId);
if (!$item) { pop("Item not found.", "/inventory/items/list.php", 1800, 'warning'); exit; }

$categories = getCategories($pdo);
$uoms = getUnitsOfMeasure($pdo);
$critClasses = getCriticalityClasses($pdo);
$acctClasses = getAccountingClasses($pdo);
$riskClasses = getRiskClasses($pdo);
$itemRiskIds = array_column(getItemRiskClasses($pdo, $itemId), 'risk_class_id');
$assetTypes  = getAssetTypes($pdo);
$invTypes    = getInventoryTypes($pdo);
$assetItemTypeGroups = getAssetItemTypeGroups($pdo);
$assetItemTypes      = getAssetItemTypes($pdo);

/* Asset Register helpers */
$assetDetailsTableExists = (function (PDO $pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_asset_details'");
    $s->execute();
    return (int) $s->fetchColumn() > 0;
})($pdo);

$assetDetail = [];
$branches    = [];
if ($assetDetailsTableExists) {
    $adStmt = $pdo->prepare("
        SELECT asset_detail_id, item_id, asset_code, reference_number, make, serial_number, acquired_date,
               department_branch_id, custodian_user_id, custodian_name, custodian_role, asset_status,
               asset_condition, delivery_date, placed_in_service_date, warranty_expiration, warranty_provider,
               warranty_start_date, warranty_end_date, warranty_period, warranty_reference, warranty_notes,
               warranty_status, address, location_id, site, building, floor_room, purchase_cost, source_of_funds,
               depreciation_method, depreciation_method_type, useful_life_years, salvage_value, total_production_units,
               declining_balance_rate, current_replacement_value, accountable_officer, revalued_cost, revalued_date,
               accumulated_depreciation, depreciation_charge, carrying_value, depreciation_method_rate, impairment,
               budget_code, acquisition_method, bos_number, insured_value, forced_sale_value, disposal_date,
               disposal_amount, disposal_authorization, is_disposed, attachments_note, comments, secondary_custodian
        FROM inv_asset_details
        WHERE item_id = ?
        LIMIT 1
    ");
    $adStmt->execute([$itemId]);
    $assetDetail = $adStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    try {
        $branches = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* branches table may not exist on all installs */ }
}

/* Job titles for custodian role dropdown */
$jobTitlesForCustodian = [];
try {
    $jobTitlesForCustodian = $pdo->query(
        "SELECT id, title_name FROM job_titles WHERE is_active = 1 ORDER BY sort_order, title_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* graceful degradation */ }

/* Roles list for custodian role dropdown (fallback if job_titles not available) */
$allRoles = !empty($jobTitlesForCustodian)
    ? []
    : $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* Load admin-configured field requirement settings for Asset Register Details */
$arFieldRequired = [];
$arFieldKeys = [
    'ar_require_inventory_number',
    'ar_require_condition',
    'ar_require_status',
    'ar_require_acquired_date',
    'ar_require_custodian',
    'ar_require_location',
    'ar_require_purchase_cost',
    'ar_require_disposal_date',
];
foreach ($arFieldKeys as $arKey) {
    try {
        $s = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $s->execute([$arKey]);
        $val = $s->fetchColumn();
        $arFieldRequired[$arKey] = $val !== false ? (bool)(int)$val : true;
    } catch (Throwable $e) {
        $arFieldRequired[$arKey] = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $itemName = trim($_POST['item_name'] ?? '');
        if (empty($itemName)) throw new Exception("Item name is required.");

        // Primary Asset Type is mandatory; classifications may only belong to it
        [$itemDomain, $assetTypeId, $inventoryTypeId] = validatePrimaryAssetTypeSelection(
            $pdo,
            $_POST['item_domain'] ?? ($item['item_domain'] ?? 'INVENTORY'),
            $_POST['asset_type_id'] ?? null,
            $_POST['inventory_type_id'] ?? null
        );

        /* ── Asset Code Tag editing (permission-gated) ── */
        $newItemCode = null;
        $oldItemCode = $item['item_code'];
        if (has_permission('edit_asset_code') && isset($_POST['item_code'])) {
            $newItemCode = trim($_POST['item_code']);
            if ($newItemCode === '') throw new Exception("Item Code (Asset Code Tag) cannot be empty.");
            if (strlen($newItemCode) > 50) throw new Exception("Item Code must be 50 characters or less.");

            // Uniqueness check (exclude current item)
            if ($newItemCode !== $oldItemCode) {
                $dupChk = $pdo->prepare("SELECT COUNT(*) FROM inv_items WHERE item_code = ? AND item_id != ?");
                $dupChk->execute([$newItemCode, $itemId]);
                if ($dupChk->fetchColumn() > 0) {
                    throw new Exception("Item Code '{$newItemCode}' is already in use by another item.");
                }
            }
        }

        $updateItemCode = ($newItemCode !== null && $newItemCode !== $oldItemCode);

        $sql = "
            UPDATE inv_items SET
                item_name = ?, description = ?, category_id = ?, subcategory_id = ?, uom_id = ?,
                pack_size = ?, barcode = ?, manufacturer = ?, brand = ?, model = ?, part_number = ?,
                serial_number_flag = ?, batch_lot_flag = ?, expiry_date_flag = ?, hazard_class_flag = ?,
                storage_conditions = ?, shelf_life_days = ?, inspection_required = ?, receiving_tolerance_pct = ?,
                contract_reference = ?, procurement_method = ?,
                reorder_level = ?, reorder_quantity = ?, min_level = ?, max_level = ?, safety_stock = ?,
                lead_time_days = ?, economic_order_qty = ?,
                standard_cost = ?, valuation_method = ?,
                funding_source = ?, program_project_code = ?, gl_account_code = ?,
                criticality_id = ?, acct_class_id = ?, item_status = ?, issue_policy = ?,
                asset_inventory_boundary = ?, item_domain = ?, asset_type_id = ?, inventory_type_id = ?,
                asset_item_type_id = ?,
                updated_by = ?" . ($updateItemCode ? ", item_code = ?" : "") . "
            WHERE item_id = ?
        ";

        $stmt = $pdo->prepare($sql);

        $params = [
            $itemName,
            trim($_POST['description'] ?? ''),
            (int) $_POST['category_id'],
            ($_POST['subcategory_id'] ?? null) ?: null,
            (int) $_POST['uom_id'],
            (float) ($_POST['pack_size'] ?? 1),
            trim($_POST['barcode'] ?? '') ?: null,
            trim($_POST['manufacturer'] ?? '') ?: null,
            trim($_POST['brand'] ?? '') ?: null,
            trim($_POST['model'] ?? '') ?: null,
            trim($_POST['part_number'] ?? '') ?: null,
            isset($_POST['serial_number_flag']) ? 1 : 0,
            isset($_POST['batch_lot_flag']) ? 1 : 0,
            isset($_POST['expiry_date_flag']) ? 1 : 0,
            isset($_POST['hazard_class_flag']) ? 1 : 0,
            trim($_POST['storage_conditions'] ?? '') ?: null,
            ($_POST['shelf_life_days'] ?? null) ?: null,
            isset($_POST['inspection_required']) ? 1 : 0,
            (float) ($_POST['receiving_tolerance_pct'] ?? 0),
            trim($_POST['contract_reference'] ?? '') ?: null,
            trim($_POST['procurement_method'] ?? '') ?: null,
            (float) ($_POST['reorder_level'] ?? 0),
            (float) ($_POST['reorder_quantity'] ?? 0),
            (float) ($_POST['min_level'] ?? 0),
            (float) ($_POST['max_level'] ?? 0),
            (float) ($_POST['safety_stock'] ?? 0),
            (int) ($_POST['lead_time_days'] ?? 0),
            ($_POST['economic_order_qty'] ?? null) ?: null,
            (float) ($_POST['standard_cost'] ?? 0),
            $_POST['valuation_method'] ?? 'AVERAGE',
            trim($_POST['funding_source'] ?? '') ?: null,
            trim($_POST['program_project_code'] ?? '') ?: null,
            trim($_POST['gl_account_code'] ?? '') ?: null,
            ($_POST['criticality_id'] ?? null) ?: null,
            ($_POST['acct_class_id'] ?? null) ?: null,
            $_POST['item_status'] ?? 'ACTIVE',
            $_POST['issue_policy'] ?? 'UNRESTRICTED',
            isset($_POST['asset_inventory_boundary']) ? 1 : 0,
            $itemDomain,
            $assetTypeId,
            $inventoryTypeId,
            (int) ($_POST['asset_item_type_id'] ?? 0) ?: null,
            $_SESSION['user_id'] ?? null,
        ];

        if ($newItemCode !== null && $newItemCode !== $oldItemCode) {
            $params[] = $newItemCode;
        }
        $params[] = $itemId;

        $stmt->execute($params);

        // For STANDARD cost valuation, keep inv_stock.unit_cost in sync with standard_cost
        // so that the total stock value calculation (quantity × unit_cost) is correct.
        $newStdCost = (float) ($_POST['standard_cost'] ?? 0);
        if (($_POST['valuation_method'] ?? 'AVERAGE') === 'STANDARD' && $newStdCost > 0) {
            $pdo->prepare("UPDATE inv_stock SET unit_cost = ? WHERE item_id = ?")
                ->execute([$newStdCost, $itemId]);
        }

        // Update risk classes
        $pdo->prepare("DELETE FROM inv_item_risk_classes WHERE item_id = ?")->execute([$itemId]);
        if (!empty($_POST['risk_classes'])) {
            $rcStmt = $pdo->prepare("INSERT INTO inv_item_risk_classes (item_id, risk_class_id) VALUES (?, ?)");
            foreach ($_POST['risk_classes'] as $rcId) {
                $rcStmt->execute([$itemId, (int) $rcId]);
            }
        }

        /* ── Sync asset_code in inv_asset_details if item_code changed ── */
        if ($newItemCode !== null && $newItemCode !== $oldItemCode) {
            try {
                $syncStmt = $pdo->prepare("UPDATE inv_asset_details SET asset_code = ? WHERE item_id = ?");
                $syncStmt->execute([$newItemCode, $itemId]);
            } catch (Throwable $e) {
                // Log non-trivial errors; missing row (0 affected rows) is expected
                if (strpos($e->getMessage(), 'doesn\'t exist') === false) {
                    error_log("Asset code sync warning for item {$itemId}: " . $e->getMessage());
                }
            }

            logInventoryAudit($pdo, 'inv_items', $itemId, 'UPDATE',
                "Asset Code changed: '{$oldItemCode}' → '{$newItemCode}'");
        }

        // ── Asset Register Details upsert ───────────────────────────────────
        if ($assetDetailsTableExists && in_array($itemDomain, ['ASSET', 'BOTH']) && isset($_POST['ar_inventory_number'])) {
            $arInventoryNumber = trim($_POST['ar_inventory_number'] ?? '');
            $arCondition       = trim($_POST['ar_condition'] ?? '');
            $arStatus          = trim($_POST['ar_asset_status'] ?? '');
            $arAcquiredDate    = trim($_POST['ar_acquired_date'] ?? '');
            $arCustodian       = trim($_POST['ar_custodian'] ?? '');
            $arCustodianRole   = trim($_POST['ar_custodian_role'] ?? '');
            $arSecondaryCustodian = trim($_POST['ar_secondary_custodian'] ?? '');
            $arLocation        = trim($_POST['ar_location'] ?? '');
            $arSite            = trim($_POST['ar_site'] ?? '');
            $arBuilding        = trim($_POST['ar_building'] ?? '');
            $arFloorRoom       = trim($_POST['ar_floor_room'] ?? '');
            $arLocationId      = ($_POST['ar_location_id'] ?? '') !== '' ? (int)$_POST['ar_location_id'] : null;
            $arPurchaseCost    = trim($_POST['ar_purchase_cost'] ?? '');
            $arDisposalDate    = trim($_POST['ar_disposal_date'] ?? '');
            $arDisposalAmount  = trim($_POST['ar_disposal_amount'] ?? '');
            $arIsDisposed      = isset($_POST['ar_is_disposed']) ? 1 : 0;
            // Warranty fields
            $arWarrantyProvider  = trim($_POST['ar_warranty_provider'] ?? '');
            $arWarrantyStartDate = trim($_POST['ar_warranty_start_date'] ?? '');
            $arWarrantyEndDate   = trim($_POST['ar_warranty_end_date'] ?? '');
            $arWarrantyPeriod    = trim($_POST['ar_warranty_period'] ?? '');
            $arWarrantyReference = trim($_POST['ar_warranty_reference'] ?? '');
            $arWarrantyNotes     = trim($_POST['ar_warranty_notes'] ?? '');
            $arWarrantyStatus    = trim($_POST['ar_warranty_status'] ?? '');

            // Mandatory field validation (respects admin settings)
            $arErrors = [];
            if ($arFieldRequired['ar_require_inventory_number'] && $arInventoryNumber === '')
                $arErrors[] = "Inventory Number is required for assets.";
            if ($arFieldRequired['ar_require_condition'] && $arCondition === '')
                $arErrors[] = "Asset Condition is required.";
            if ($arFieldRequired['ar_require_status'] && $arStatus === '')
                $arErrors[] = "Asset Status is required.";
            if ($arFieldRequired['ar_require_acquired_date'] && $arAcquiredDate === '')
                $arErrors[] = "Date of Acquisition is required.";
            if ($arFieldRequired['ar_require_custodian'] && $arCustodian === '')
                $arErrors[] = "Custodian is required.";
            if ($arFieldRequired['ar_require_location'] && $arSite === '' && $arBuilding === '' && $arFloorRoom === '' && $arLocation === '')
                $arErrors[] = "Asset Location is required (provide at least Site, Building, Floor/Room, or Address).";
            if ($arFieldRequired['ar_require_purchase_cost'] && ($arPurchaseCost === '' || (float) $arPurchaseCost < 0))
                $arErrors[] = "Cost / Purchase Price is required and must be a non-negative number.";

            if ($arIsDisposed) {
                if ($arFieldRequired['ar_require_disposal_date'] && $arDisposalDate === '')
                    $arErrors[] = "Disposal Date is required when the asset is disposed.";
                if ($arDisposalAmount === '')
                    $arErrors[] = "Disposal Amount Realized is required when the asset is disposed.";
            }

            if (!empty($arErrors)) {
                throw new Exception(implode(' ', $arErrors));
            }

            // Unique inventory number check (exclude this item's existing record)
            if ($arInventoryNumber !== '') {
                $dupAr = $pdo->prepare("SELECT COUNT(*) FROM inv_asset_details WHERE asset_code = ? AND item_id != ?");
                $dupAr->execute([$arInventoryNumber, $itemId]);
                if ($dupAr->fetchColumn() > 0)
                    throw new Exception("Inventory Number '$arInventoryNumber' is already assigned to another asset.");
            }

            // Determine whether this is an insert or update (check existing record)
            $existingAd = $pdo->prepare("SELECT asset_detail_id FROM inv_asset_details WHERE item_id = ? LIMIT 1");
            $existingAd->execute([$itemId]);
            $existingAdId = $existingAd->fetchColumn();

            if ($existingAdId) {
                $pdo->prepare("
                    UPDATE inv_asset_details SET
                        asset_code = ?, acquired_date = ?, asset_condition = ?, asset_status = ?,
                        custodian_name = ?, custodian_role = ?, accountable_officer = ?, secondary_custodian = ?,
                        site = ?, building = ?, floor_room = ?, address = ?, location_id = ?,
                        purchase_cost = ?, disposal_date = ?, disposal_amount = ?, is_disposed = ?,
                        warranty_provider = ?, warranty_start_date = ?, warranty_end_date = ?,
                        warranty_period = ?, warranty_reference = ?, warranty_notes = ?, warranty_status = ?
                    WHERE item_id = ?
                ")->execute([
                    $arInventoryNumber ?: null,
                    $arAcquiredDate ?: null,
                    $arCondition ?: null,
                    $arStatus ?: null,
                    $arCustodian ?: null,
                    $arCustodianRole ?: null,
                    $arCustodian ?: null,  // accountable_officer mirrors custodian_name
                    $arSecondaryCustodian ?: null,
                    $arSite ?: null,
                    $arBuilding ?: null,
                    $arFloorRoom ?: null,
                    $arLocation ?: null,
                    $arLocationId,
                    ($arPurchaseCost !== '') ? (float) $arPurchaseCost : null,
                    ($arDisposalDate !== '') ? $arDisposalDate : null,
                    ($arDisposalAmount !== '') ? (float) $arDisposalAmount : null,
                    $arIsDisposed,
                    $arWarrantyProvider ?: null,
                    ($arWarrantyStartDate !== '') ? $arWarrantyStartDate : null,
                    ($arWarrantyEndDate !== '') ? $arWarrantyEndDate : null,
                    $arWarrantyPeriod ?: null,
                    $arWarrantyReference ?: null,
                    $arWarrantyNotes ?: null,
                    $arWarrantyStatus ?: null,
                    $itemId,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO inv_asset_details
                        (item_id, asset_code, acquired_date, asset_condition, asset_status,
                         custodian_name, custodian_role, accountable_officer, secondary_custodian,
                         site, building, floor_room, address, location_id,
                         purchase_cost, disposal_date, disposal_amount, is_disposed,
                         warranty_provider, warranty_start_date, warranty_end_date,
                         warranty_period, warranty_reference, warranty_notes, warranty_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $itemId,
                    $arInventoryNumber ?: null,
                    $arAcquiredDate ?: null,
                    $arCondition ?: null,
                    $arStatus ?: null,
                    $arCustodian ?: null,
                    $arCustodianRole ?: null,
                    $arCustodian ?: null,  // accountable_officer mirrors custodian_name
                    $arSecondaryCustodian ?: null,
                    $arSite ?: null,
                    $arBuilding ?: null,
                    $arFloorRoom ?: null,
                    $arLocation ?: null,
                    $arLocationId,
                    ($arPurchaseCost !== '') ? (float) $arPurchaseCost : null,
                    ($arDisposalDate !== '') ? $arDisposalDate : null,
                    ($arDisposalAmount !== '') ? (float) $arDisposalAmount : null,
                    $arIsDisposed,
                    $arWarrantyProvider ?: null,
                    ($arWarrantyStartDate !== '') ? $arWarrantyStartDate : null,
                    ($arWarrantyEndDate !== '') ? $arWarrantyEndDate : null,
                    $arWarrantyPeriod ?: null,
                    $arWarrantyReference ?: null,
                    $arWarrantyNotes ?: null,
                    $arWarrantyStatus ?: null,
                ]);
            }

            logInventoryAudit($pdo, 'inv_asset_details', $itemId, $existingAdId ? 'UPDATE' : 'CREATE',
                "Asset Register updated: Inv# $arInventoryNumber, Condition: $arCondition, Status: $arStatus, Custodian: $arCustodian");
        }

        logInventoryAudit($pdo, 'inv_items', $itemId, 'UPDATE', "Item updated: " . ($newItemCode ?? $oldItemCode));
        $pdo->commit();
        pop("Item updated successfully.", "/inventory/items/view.php?id=$itemId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

// Use item data as defaults
$f = $item;

// Load current stock levels for this item
$currentStock = [];
try {
    $stockStmt = $pdo->prepare("
        SELECT s.quantity_on_hand, s.quantity_reserved, s.quantity_available, s.unit_cost,
               s.stock_status, s.batch_lot_number, s.received_date,
               l.location_id, l.location_code, COALESCE(l.site_name, l.site_campus, '') AS location_display
        FROM inv_stock s
        JOIN inv_locations l ON s.location_id = l.location_id
        WHERE s.item_id = ? AND s.stock_status = 'USABLE'
        ORDER BY l.location_code, s.received_date
    ");
    $stockStmt->execute([$itemId]);
    $currentStock = $stockStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* graceful degradation */ }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-pencil-square"></i> Edit Item: <?= htmlspecialchars($item['item_code']) ?></h2>
    <a href="/inventory/items/view.php?id=<?= $itemId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Item
    </a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" class="needs-validation" novalidate>
    <!-- Basic Information -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-info-circle"></i> Basic Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Item Code (Asset Code Tag)</label>
                    <?php if (has_permission('edit_asset_code')): ?>
                    <input type="text" name="item_code" class="form-control" required maxlength="50"
                           value="<?= htmlspecialchars($f['item_code']) ?>">
                    <small class="text-muted">Editable — must be unique</small>
                    <?php else: ?>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($f['item_code']) ?>" disabled>
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="item_name" class="form-control" required value="<?= htmlspecialchars($f['item_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Barcode / QR / GS1</label>
                    <input type="text" name="barcode" class="form-control" value="<?= htmlspecialchars($f['barcode'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($f['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select...</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>" <?= $f['category_id'] == $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Unit of Measure <span class="text-danger">*</span></label>
                    <select name="uom_id" class="form-select" required>
                        <option value="">Select...</option>
                        <?php foreach ($uoms as $u): ?>
                        <option value="<?= $u['uom_id'] ?>" <?= $f['uom_id'] == $u['uom_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['uom_name']) ?> (<?= $u['uom_code'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pack Size</label>
                    <input type="number" step="0.01" name="pack_size" class="form-control" value="<?= $f['pack_size'] ?>">
                </div>
                <!-- Domain classification (migration 024 / Primary Asset Type restructure) -->
                <?php if (!empty($assetTypes) || !empty($invTypes)): ?>
                <div class="col-md-4">
                    <label class="form-label">Item Domain <span class="text-danger">*</span></label>
                    <select name="item_domain" id="itemDomain" class="form-select" required>
                        <option value="INVENTORY" <?= ($f['item_domain'] ?? 'INVENTORY') === 'INVENTORY' ? 'selected' : '' ?>>Inventory / Stock / Consumable</option>
                        <option value="ASSET"     <?= ($f['item_domain'] ?? '') === 'ASSET'     ? 'selected' : '' ?>>Asset (Fixed/Movable)</option>
                        <option value="BOTH"      <?= ($f['item_domain'] ?? '') === 'BOTH'      ? 'selected' : '' ?>>Both</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Primary Asset Type <span class="text-danger">*</span></label>
                    <input type="text" id="primaryAssetType" class="form-control" readonly
                           value="<?= htmlspecialchars(getPrimaryAssetTypeLabel($f['item_domain'] ?? 'INVENTORY')) ?>">
                    <small class="text-muted">Derived from the Item Domain per Ministry of Finance classification.</small>
                </div>
                <div class="col-md-6" id="assetTypeGroup" style="<?= in_array($f['item_domain'] ?? 'INVENTORY', ['ASSET','BOTH']) ? '' : 'display:none' ?>">
                    <label class="form-label">Asset Classification (Property, Plant, and Equipment)</label>
                    <select name="asset_type_id" class="form-select">
                        <option value="">— Select classification —</option>
                        <?php foreach ($assetTypes as $at): ?>
                        <option value="<?= $at['asset_type_id'] ?>" <?= ($f['asset_type_id'] ?? '') == $at['asset_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($at['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6" id="invTypeGroup" style="<?= in_array($f['item_domain'] ?? 'INVENTORY', ['INVENTORY','BOTH']) ? '' : 'display:none' ?>">
                    <label class="form-label">Asset Classification (Consumable and Expendable)</label>
                    <select name="inventory_type_id" class="form-select">
                        <option value="">— Select classification —</option>
                        <?php foreach ($invTypes as $it): ?>
                        <option value="<?= $it['inventory_type_id'] ?>" <?= ($f['inventory_type_id'] ?? '') == $it['inventory_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($it['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($assetItemTypeGroups)): ?>
                <div class="col-md-6">
                    <label class="form-label">Asset Item Type
                        <small class="text-muted">(<a href="/inventory/asset-item-types/list.php" target="_blank">manage</a>)</small>
                    </label>
                    <select name="asset_item_type_id" id="assetItemTypeSelect" class="form-select">
                        <option value="">— None —</option>
                        <?php
                        $prevGroup = null;
                        foreach ($assetItemTypes as $ait):
                            if ($ait['group_id'] !== $prevGroup):
                                if ($prevGroup !== null) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($ait['group_code'] . ' — ' . $ait['group_name']) . '">';
                                $prevGroup = $ait['group_id'];
                            endif;
                        ?>
                        <option value="<?= $ait['item_type_id'] ?>"
                            <?= ($f['asset_item_type_id'] ?? '') == $ait['item_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ait['type_code'] . ' — ' . $ait['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($prevGroup !== null) echo '</optgroup>'; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Product Details -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-tag"></i> Product Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Manufacturer</label><input type="text" name="manufacturer" class="form-control" value="<?= htmlspecialchars($f['manufacturer'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Brand</label><input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($f['brand'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= htmlspecialchars($f['model'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Part Number</label><input type="text" name="part_number" class="form-control" value="<?= htmlspecialchars($f['part_number'] ?? '') ?>"></div>
            </div>
        </div>
    </div>

    <!-- Tracking Flags -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-flag"></i> Tracking & Control</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="serial_number_flag" <?= $f['serial_number_flag'] ? 'checked' : '' ?>><label class="form-check-label">Serial Number Tracking</label></div></div>
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="batch_lot_flag" <?= $f['batch_lot_flag'] ? 'checked' : '' ?>><label class="form-check-label">Batch / Lot Tracking</label></div></div>
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="expiry_date_flag" <?= $f['expiry_date_flag'] ? 'checked' : '' ?>><label class="form-check-label">Expiry Date Tracking</label></div></div>
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="hazard_class_flag" <?= $f['hazard_class_flag'] ? 'checked' : '' ?>><label class="form-check-label">Hazardous Item</label></div></div>
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="inspection_required" <?= $f['inspection_required'] ? 'checked' : '' ?>><label class="form-check-label">Inspection Required</label></div></div>
                <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="asset_inventory_boundary" <?= $f['asset_inventory_boundary'] ? 'checked' : '' ?>><label class="form-check-label">Asset/Inventory Boundary</label></div></div>
                <div class="col-md-3"><label class="form-label">Shelf Life (days)</label><input type="number" name="shelf_life_days" class="form-control" value="<?= $f['shelf_life_days'] ?? '' ?>"></div>
                <div class="col-md-3"><label class="form-label">Receiving Tolerance %</label><input type="number" step="0.01" name="receiving_tolerance_pct" class="form-control" value="<?= $f['receiving_tolerance_pct'] ?>"></div>
                <div class="col-md-6"><label class="form-label">Storage Conditions</label><input type="text" name="storage_conditions" class="form-control" value="<?= htmlspecialchars($f['storage_conditions'] ?? '') ?>"></div>
            </div>
        </div>
    </div>

    <!-- Replenishment -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-arrow-repeat"></i> Replenishment</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2"><label class="form-label">Reorder Level</label><input type="number" step="0.01" name="reorder_level" class="form-control" value="<?= $f['reorder_level'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Reorder Qty</label><input type="number" step="0.01" name="reorder_quantity" class="form-control" value="<?= $f['reorder_quantity'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Min</label><input type="number" step="0.01" name="min_level" class="form-control" value="<?= $f['min_level'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Max</label><input type="number" step="0.01" name="max_level" class="form-control" value="<?= $f['max_level'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Safety Stock</label><input type="number" step="0.01" name="safety_stock" class="form-control" value="<?= $f['safety_stock'] ?>"></div>
                <div class="col-md-2"><label class="form-label">Lead Time (days)</label><input type="number" name="lead_time_days" class="form-control" value="<?= $f['lead_time_days'] ?>"></div>
                <div class="col-md-3"><label class="form-label">EOQ</label><input type="number" step="0.01" name="economic_order_qty" class="form-control" value="<?= $f['economic_order_qty'] ?? '' ?>"></div>
            </div>
        </div>
    </div>

    <!-- Costing -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-currency-dollar"></i> Costing & Financial</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label">Standard Cost</label><input type="number" step="0.01" name="standard_cost" class="form-control" value="<?= $f['standard_cost'] ?>"></div>
                <div class="col-md-3"><label class="form-label">Valuation Method</label>
                    <select name="valuation_method" class="form-select">
                        <?php foreach (['AVERAGE', 'FIFO', 'STANDARD', 'SPECIFIC'] as $vm): ?>
                        <option value="<?= $vm ?>" <?= $f['valuation_method'] === $vm ? 'selected' : '' ?>><?= $vm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Funding Source</label><input type="text" name="funding_source" class="form-control" value="<?= htmlspecialchars($f['funding_source'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Program/Project Code</label><input type="text" name="program_project_code" class="form-control" value="<?= htmlspecialchars($f['program_project_code'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">GL Account Code</label><input type="text" name="gl_account_code" class="form-control" value="<?= htmlspecialchars($f['gl_account_code'] ?? '') ?>"></div>
            </div>
        </div>
    </div>

    <!-- Classification -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-diagram-3"></i> Classification</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Criticality</label>
                    <select name="criticality_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach ($critClasses as $cc): ?>
                        <option value="<?= $cc['criticality_id'] ?>" <?= $f['criticality_id'] == $cc['criticality_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cc['criticality_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Accounting Class</label>
                    <select name="acct_class_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach ($acctClasses as $ac): ?>
                        <option value="<?= $ac['acct_class_id'] ?>" <?= $f['acct_class_id'] == $ac['acct_class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($ac['acct_class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="item_status" class="form-select">
                        <?php foreach (['ACTIVE','BLOCKED','OBSOLETE','QUARANTINED','DISPOSAL'] as $st): ?>
                        <option value="<?= $st ?>" <?= $f['item_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Issue Policy</label>
                    <select name="issue_policy" class="form-select">
                        <?php foreach (['UNRESTRICTED','APPROVAL_REQUIRED','CONTROLLED'] as $ip): ?>
                        <option value="<?= $ip ?>" <?= $f['issue_policy'] === $ip ? 'selected' : '' ?>><?= $ip ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Risk & Control Classes</label>
                    <div class="row g-2">
                        <?php foreach ($riskClasses as $rc): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="risk_classes[]" value="<?= $rc['risk_class_id'] ?>"
                                       <?= in_array($rc['risk_class_id'], $itemRiskIds) ? 'checked' : '' ?>>
                                <label class="form-check-label"><?= htmlspecialchars($rc['risk_name']) ?></label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Procurement -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-cart"></i> Procurement</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Contract Reference</label><input type="text" name="contract_reference" class="form-control" value="<?= htmlspecialchars($f['contract_reference'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">Procurement Method</label><input type="text" name="procurement_method" class="form-control" value="<?= htmlspecialchars($f['procurement_method'] ?? '') ?>"></div>
            </div>
        </div>
    </div>

    <?php if ($assetDetailsTableExists): ?>
    <?php
    // Use POSTed values on validation failure, otherwise fall back to the stored record
    $arv = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ar_inventory_number']))
        ? $_POST
        : $assetDetail;
    $arIsAsset = in_array($f['item_domain'] ?? 'INVENTORY', ['ASSET', 'BOTH']);
    ?>
    <!-- Asset Register Details (shown only for ASSET / BOTH domain) -->
    <div class="card border-0 shadow-sm mb-4 border-warning" id="assetRegisterSection"
         style="<?= $arIsAsset ? '' : 'display:none' ?>">
        <div class="card-header bg-warning text-dark">
            <i class="bi bi-clipboard2-check"></i> Asset Register Details
            <span class="badge bg-danger ms-2">Required for Assets</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Fields marked <span class="text-danger fw-bold">*</span> are mandatory (configured by admin).
                Disposal fields are required only when the asset has been disposed of.
            </p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Inventory Number Assigned <?= $arFieldRequired['ar_require_inventory_number'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="ar_inventory_number" id="ar_inventory_number"
                           class="form-control <?= $arFieldRequired['ar_require_inventory_number'] ? 'ar-required' : '' ?>"
                           placeholder="Unique asset identifier"
                           value="<?= htmlspecialchars($arv['ar_inventory_number'] ?? $arv['asset_code'] ?? '') ?>">
                    <small class="text-muted">Must be unique across the asset register.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cost / Purchase Price <?= $arFieldRequired['ar_require_purchase_cost'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="number" step="0.01" min="0" name="ar_purchase_cost" id="ar_purchase_cost"
                           class="form-control <?= $arFieldRequired['ar_require_purchase_cost'] ? 'ar-required' : '' ?>"
                           value="<?= htmlspecialchars($arv['ar_purchase_cost'] ?? $arv['purchase_cost'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Acquisition <?= $arFieldRequired['ar_require_acquired_date'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="date" name="ar_acquired_date" id="ar_acquired_date"
                           class="form-control <?= $arFieldRequired['ar_require_acquired_date'] ? 'ar-required' : '' ?>"
                           value="<?= htmlspecialchars($arv['ar_acquired_date'] ?? $arv['acquired_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Asset Condition <?= $arFieldRequired['ar_require_condition'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_condition" id="ar_condition" class="form-select <?= $arFieldRequired['ar_require_condition'] ? 'ar-required' : '' ?>">
                        <option value="">— Select —</option>
                        <?php
                        $arCondVal = $arv['ar_condition'] ?? $arv['asset_condition'] ?? '';
                        foreach (['New','Good','Fair','Poor','Damaged'] as $cnd): ?>
                        <option value="<?= $cnd ?>" <?= $arCondVal === $cnd ? 'selected' : '' ?>><?= $cnd ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Asset Status <?= $arFieldRequired['ar_require_status'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_asset_status" id="ar_asset_status" class="form-select <?= $arFieldRequired['ar_require_status'] ? 'ar-required' : '' ?>">
                        <option value="">— Select —</option>
                        <?php
                        $arStatVal = $arv['ar_asset_status'] ?? $arv['asset_status'] ?? '';
                        foreach (['Active','In Use','In Storage','Under Repair','Not in Service','Disposed'] as $st): ?>
                        <option value="<?= $st ?>" <?= $arStatVal === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Custodian <?= $arFieldRequired['ar_require_custodian'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="ar_custodian" id="ar_custodian"
                           class="form-control <?= $arFieldRequired['ar_require_custodian'] ? 'ar-required' : '' ?>"
                           placeholder="Person or department responsible"
                           value="<?= htmlspecialchars($arv['ar_custodian'] ?? $arv['custodian_name'] ?? '') ?>">
                </div>
                <!-- Custodian Role -->
                <div class="col-md-4">
                    <label class="form-label">Custodian Role</label>
                    <?php
                    $defaultCustodianRole = 'Property Management Officer';
                    $savedCustodianRole   = $arv['ar_custodian_role'] ?? $arv['custodian_role'] ?? $defaultCustodianRole;
                    ?>
                    <select name="ar_custodian_role" id="ar_custodian_role" class="form-select">
                        <option value="">— Select Role —</option>
                        <?php if (!empty($jobTitlesForCustodian)): ?>
                        <?php foreach ($jobTitlesForCustodian as $jt): ?>
                        <option value="<?= htmlspecialchars($jt['title_name']) ?>"
                            <?= $savedCustodianRole === $jt['title_name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($jt['title_name']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <?php foreach ($allRoles as $role): ?>
                        <option value="<?= htmlspecialchars($role['name']) ?>"
                            <?= $savedCustodianRole === $role['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['name']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <small class="text-muted">Role assigned to the custodian for this asset.</small>
                </div>
                <!-- Secondary Custodian -->
                <div class="col-md-4">
                    <label class="form-label">Secondary Custodian</label>
                    <input type="text" name="ar_secondary_custodian" id="ar_secondary_custodian"
                           class="form-control"
                           placeholder="Backup custodian (optional)"
                           value="<?= htmlspecialchars($arv['ar_secondary_custodian'] ?? $arv['secondary_custodian'] ?? '') ?>">
                    <small class="text-muted">Backup person responsible for this asset.</small>
                </div>
                <!-- Location (cascading dropdowns) -->
                <?php
                $editSite      = $arv['ar_site']       ?? $arv['site']       ?? '';
                $editBuild     = $arv['ar_building']    ?? $arv['building']   ?? '';
                $editFloor     = $arv['ar_floor_room']  ?? $arv['floor_room'] ?? '';
                $editAddr      = $arv['ar_location']    ?? $arv['address']    ?? '';
                $editLocationId = $arv['ar_location_id'] ?? $arv['location_id'] ?? '';
                ?>
                <div class="col-md-3">
                    <label class="form-label">Site / Campus <?= $arFieldRequired['ar_require_location'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_site" id="ar_site" class="form-select ar-location">
                        <option value="">— Select Site —</option>
                        <?php if ($editSite !== ''): ?>
                        <option value="<?= htmlspecialchars($editSite) ?>" selected><?= htmlspecialchars($editSite) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Building</label>
                    <select name="ar_building" id="ar_building" class="form-select ar-location" <?= $editSite === '' ? 'disabled' : '' ?>>
                        <option value="">— Select Building —</option>
                        <?php if ($editBuild !== ''): ?>
                        <option value="<?= htmlspecialchars($editBuild) ?>" selected><?= htmlspecialchars($editBuild) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Floor / Room</label>
                    <select name="ar_floor_room" id="ar_floor_room" class="form-select ar-location" <?= $editBuild === '' ? 'disabled' : '' ?>>
                        <option value="">— Select Floor / Room —</option>
                        <?php if ($editFloor !== ''): ?>
                        <option value="<?= htmlspecialchars($editFloor) ?>" selected><?= htmlspecialchars($editFloor) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Address / Other Location</label>
                    <input type="text" name="ar_location" id="ar_location" class="form-control ar-location"
                           placeholder="Street address or other"
                           value="<?= htmlspecialchars($editAddr) ?>">
                </div>
                <!-- Hidden field for resolved location_id -->
                <input type="hidden" name="ar_location_id" id="ar_location_id"
                       value="<?= htmlspecialchars($editLocationId) ?>">
                <?php if ($arFieldRequired['ar_require_location']): ?>
                <div class="col-12">
                    <small class="text-muted"><span class="text-danger">*</span> At least one location field (Site, Building, Floor/Room, or Address) must be completed.</small>
                </div>
                <?php endif; ?>
            </div>

            <!-- Warranty Information -->
            <hr class="my-3">
            <h6 class="text-secondary"><i class="bi bi-shield-check"></i> Warranty Information
                <small class="text-muted fw-normal">(optional)</small>
            </h6>
            <?php
            $wv = $arv; // Use same source as the rest of the form (POST or stored)
            ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Warranty Provider / Vendor</label>
                    <input type="text" name="ar_warranty_provider" class="form-control"
                           placeholder="Provider or vendor name"
                           value="<?= htmlspecialchars($wv['ar_warranty_provider'] ?? $wv['warranty_provider'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Start Date</label>
                    <input type="date" name="ar_warranty_start_date" class="form-control"
                           value="<?= htmlspecialchars($wv['ar_warranty_start_date'] ?? $wv['warranty_start_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty End Date</label>
                    <input type="date" name="ar_warranty_end_date" class="form-control"
                           value="<?= htmlspecialchars($wv['ar_warranty_end_date'] ?? $wv['warranty_end_date'] ?? $wv['warranty_expiration'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Period</label>
                    <input type="text" name="ar_warranty_period" class="form-control"
                           placeholder="e.g. 1 Year, 24 Months"
                           value="<?= htmlspecialchars($wv['ar_warranty_period'] ?? $wv['warranty_period'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Reference / Contract No.</label>
                    <input type="text" name="ar_warranty_reference" class="form-control"
                           placeholder="Contract or reference number"
                           value="<?= htmlspecialchars($wv['ar_warranty_reference'] ?? $wv['warranty_reference'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Status</label>
                    <?php $wsv = $wv['ar_warranty_status'] ?? $wv['warranty_status'] ?? ''; ?>
                    <select name="ar_warranty_status" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach (['Active','Expired','Void','Pending','Unknown'] as $ws): ?>
                        <option value="<?= $ws ?>" <?= $wsv === $ws ? 'selected' : '' ?>><?= $ws ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Warranty Notes</label>
                    <textarea name="ar_warranty_notes" class="form-control" rows="2"
                              placeholder="Any additional warranty details..."><?= htmlspecialchars($wv['ar_warranty_notes'] ?? $wv['warranty_notes'] ?? '') ?></textarea>
                </div>
            </div>

            <hr class="my-3">
            <h6 class="text-secondary"><i class="bi bi-trash3"></i> Disposal Information
                <small class="text-muted fw-normal">(required if asset has been disposed)</small>
            </h6>
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ar_inventory_number'])) {
                // After a failed POST: checkbox is only in $_POST if it was checked
                $arIsDisposedVal = isset($_POST['ar_is_disposed']);
            } else {
                $arIsDisposedVal = (bool) ($assetDetail['is_disposed'] ?? false);
            }
            ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="ar_is_disposed" id="ar_is_disposed"
                               <?= $arIsDisposedVal ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ar_is_disposed">Asset is Disposed</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Disposal Date <?php if ($arFieldRequired['ar_require_disposal_date']): ?><span class="text-danger ar-disposal-required" style="display:none">*</span><?php endif; ?></label>
                    <input type="date" name="ar_disposal_date" id="ar_disposal_date" class="form-control"
                           value="<?= htmlspecialchars($arv['ar_disposal_date'] ?? $arv['disposal_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Disposal Amount Realized <span class="text-danger ar-disposal-required" style="display:none">*</span></label>
                    <input type="number" step="0.01" min="0" name="ar_disposal_amount" id="ar_disposal_amount"
                           class="form-control"
                           value="<?= htmlspecialchars($arv['ar_disposal_amount'] ?? $arv['disposal_amount'] ?? '') ?>">
                </div>
            </div>

            <hr class="my-3">
            <div class="alert alert-info small mb-0">
                <strong><i class="bi bi-info-circle"></i> Asset Register Record Format:</strong>
                Inventory Number | Asset Description | Cost | Condition | Status | Date of Acquisition | Custodian | Location | Disposal Date | Disposal Amount Realized
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current Stock Levels (read-only) -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-boxes"></i> Current Stock Levels</span>
            <a href="/inventory/adjustments/add.php?item_id=<?= $itemId ?>" class="btn btn-sm btn-light">
                <i class="bi bi-pencil-square"></i> Adjust Stock
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($currentStock)): ?>
            <div class="alert alert-warning m-3 mb-3">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>No stock records found.</strong>
                This item has a quantity of <strong>0</strong> at all locations.
                Transfers will fail until stock is initialised via a
                <a href="/inventory/adjustments/add.php?item_id=<?= $itemId ?>">Stock Adjustment</a>
                or by receiving stock.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-secondary">
                        <tr>
                            <th>Location</th>
                            <th class="text-end">On Hand</th>
                            <th class="text-end">Reserved</th>
                            <th class="text-end">Available</th>
                            <th>Batch / Lot</th>
                            <th>Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentStock as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['location_code'] . ($s['location_display'] ? ' — ' . $s['location_display'] : '')) ?></td>
                            <td class="text-end"><?= number_format((float)$s['quantity_on_hand'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$s['quantity_reserved'], 2) ?></td>
                            <td class="text-end fw-bold <?= (float)$s['quantity_available'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= number_format((float)$s['quantity_available'], 2) ?>
                            </td>
                            <td><?= htmlspecialchars($s['batch_lot_number'] ?? '—') ?></td>
                            <td><?= $s['received_date'] ? date('d M Y', strtotime($s['received_date'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-end mb-4">
        <a href="/inventory/items/view.php?id=<?= $itemId ?>" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle"></i> Save Changes</button>
    </div>
</form>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
<script>
(function () {
    var domainSel = document.getElementById('itemDomain');
    if (!domainSel) return;
    var primaryLabels = {
        'INVENTORY': <?= json_encode(getPrimaryAssetTypeLabel('INVENTORY')) ?>,
        'ASSET':     <?= json_encode(getPrimaryAssetTypeLabel('ASSET')) ?>,
        'BOTH':      <?= json_encode(getPrimaryAssetTypeLabel('BOTH')) ?>
    };

    var arSection    = document.getElementById('assetRegisterSection');
    var arRequired   = arSection ? arSection.querySelectorAll('.ar-required') : [];
    var disposedChk  = document.getElementById('ar_is_disposed');
    var statusSel    = document.getElementById('ar_asset_status');
    var disposalDate = document.getElementById('ar_disposal_date');
    var disposalAmt  = document.getElementById('ar_disposal_amount');
    var disposalStars = arSection ? arSection.querySelectorAll('.ar-disposal-required') : [];

    function setArRequired(enable) {
        arRequired.forEach(function (el) { el.required = enable; });
    }

    function isDisposalActive() {
        return (disposedChk && disposedChk.checked) ||
               (statusSel && statusSel.value === 'Disposed') ||
               (disposalDate && disposalDate.value !== '') ||
               (disposalAmt && disposalAmt.value !== '');
    }

    var disposalDateRequired = <?= json_encode((bool)$arFieldRequired['ar_require_disposal_date']) ?>;

    function toggleDisposalRequired() {
        var active = isDisposalActive();
        if (disposalDate) disposalDate.required = active && disposalDateRequired;
        if (disposalAmt)  disposalAmt.required  = active;
        disposalStars.forEach(function (el) { el.style.display = active ? '' : 'none'; });
    }

    function toggleTypeGroups() {
        var v = domainSel.value;
        var isAsset = (v === 'ASSET' || v === 'BOTH');
        var ag = document.getElementById('assetTypeGroup');
        var ig = document.getElementById('invTypeGroup');
        var pt = document.getElementById('primaryAssetType');
        if (ag) ag.style.display = isAsset ? '' : 'none';
        if (ig) ig.style.display = (v === 'INVENTORY' || v === 'BOTH') ? '' : 'none';
        if (pt) pt.value = primaryLabels[v] || primaryLabels['INVENTORY'];
        if (arSection) arSection.style.display = isAsset ? '' : 'none';
        setArRequired(isAsset);
        toggleDisposalRequired();
    }

    domainSel.addEventListener('change', toggleTypeGroups);
    if (disposedChk) disposedChk.addEventListener('change', toggleDisposalRequired);
    if (statusSel)   statusSel.addEventListener('change', function () {
        if (statusSel.value === 'Disposed' && disposedChk) disposedChk.checked = true;
        toggleDisposalRequired();
    });
    if (disposalDate) disposalDate.addEventListener('change', toggleDisposalRequired);
    if (disposalAmt)  disposalAmt.addEventListener('change', toggleDisposalRequired);

    toggleTypeGroups();
    toggleDisposalRequired();
}());

// ── Cascading location dropdowns ─────────────────────────────────────────────
(function () {
    var siteSel      = document.getElementById('ar_site');
    var buildSel     = document.getElementById('ar_building');
    var floorSel     = document.getElementById('ar_floor_room');
    var locationIdEl = document.getElementById('ar_location_id');

    if (!siteSel || !buildSel || !floorSel) return;

    var ENDPOINT = '/inventory/items/get_locations.php';

    function clearLocationId() {
        if (locationIdEl) locationIdEl.value = '';
    }

    function buildOptions(sel, values, currentVal, placeholder) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        values.forEach(function (v) {
            var opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            if (v === currentVal) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.disabled = (values.length === 0);
    }

    function buildRoomOptions(sel, rows, currentVal, placeholder) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        rows.forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = row.room_storage_area;
            opt.dataset.locationId = row.location_id || '';
            opt.textContent = row.room_storage_area;
            if (row.room_storage_area === currentVal) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.disabled = (rows.length === 0);
        var selectedOpt = sel.options[sel.selectedIndex];
        if (locationIdEl) locationIdEl.value = (selectedOpt && selectedOpt.dataset.locationId) ? selectedOpt.dataset.locationId : '';
    }

    function loadSites(currentSite) {
        fetch(ENDPOINT + '?type=sites')
            .then(function (r) { return r.json(); })
            .then(function (data) { buildOptions(siteSel, data, currentSite || '', '— Select Site —'); })
            .catch(function () { siteSel.disabled = false; });
    }

    function loadBuildings(site, currentBuilding) {
        fetch(ENDPOINT + '?type=buildings&site=' + encodeURIComponent(site))
            .then(function (r) { return r.json(); })
            .then(function (data) { buildOptions(buildSel, data, currentBuilding || '', '— Select Building —'); })
            .catch(function () { buildSel.disabled = false; });
    }

    function loadFloors(site, building, currentFloor) {
        fetch(ENDPOINT + '?type=floors&site=' + encodeURIComponent(site) + '&building=' + encodeURIComponent(building))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.length > 0) {
                    buildOptions(floorSel, data, currentFloor || '', '— Select Floor / Room —');
                } else {
                    loadRooms(site, building, '', currentFloor);
                }
            })
            .catch(function () { floorSel.disabled = false; });
    }

    function loadRooms(site, building, floor, currentRoom) {
        fetch(ENDPOINT + '?type=rooms&site=' + encodeURIComponent(site)
            + '&building=' + encodeURIComponent(building)
            + '&floor=' + encodeURIComponent(floor))
            .then(function (r) { return r.json(); })
            .then(function (rows) { buildRoomOptions(floorSel, rows, currentRoom || '', '— Select Floor / Room —'); })
            .catch(function () { floorSel.disabled = false; });
    }

    siteSel.addEventListener('change', function () {
        buildOptions(buildSel, [], '', '— Select Building —');
        buildOptions(floorSel, [], '', '— Select Floor / Room —');
        clearLocationId();
        if (siteSel.value) loadBuildings(siteSel.value, '');
    });

    buildSel.addEventListener('change', function () {
        buildOptions(floorSel, [], '', '— Select Floor / Room —');
        clearLocationId();
        if (buildSel.value) loadFloors(siteSel.value, buildSel.value, '');
    });

    floorSel.addEventListener('change', function () {
        clearLocationId();
        var selectedOpt = floorSel.options[floorSel.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.locationId) {
            if (locationIdEl) locationIdEl.value = selectedOpt.dataset.locationId;
        } else if (floorSel.value) {
            buildOptions(floorSel, [], '', '— Select Floor / Room —');
            loadRooms(siteSel.value, buildSel.value, floorSel.value, '');
        }
    });

    // Initial population — restore pre-selected values from stored record
    var initSite  = <?= json_encode($editSite ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var initBuild = <?= json_encode($editBuild ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var initFloor = <?= json_encode($editFloor ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    loadSites(initSite);
    if (initSite) {
        loadBuildings(initSite, initBuild);
        if (initBuild) loadFloors(initSite, initBuild, initFloor);
    }
}());
</script>