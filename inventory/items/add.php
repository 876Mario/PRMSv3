<?php
$REQUIRE_PERMISSION = 'manage_inventory_items';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$categories = getCategories($pdo);
$uoms = getUnitsOfMeasure($pdo);
$critClasses = getCriticalityClasses($pdo);
$acctClasses = getAccountingClasses($pdo);
$riskClasses = getRiskClasses($pdo);
$assetTypes = getAssetTypes($pdo);
$invTypes    = getInventoryTypes($pdo);
$assetItemTypeGroups = getAssetItemTypeGroups($pdo);
$assetItemTypes      = getAssetItemTypes($pdo);
$locations = $pdo->query("SELECT location_id, location_code, COALESCE(site_name, site_campus, '') AS display_name FROM inv_locations WHERE is_active=1 ORDER BY location_code")->fetchAll(PDO::FETCH_ASSOC);

/* Asset Register helpers — used by the Asset Register Details section */
$assetDetailsTableExists = (function (PDO $pdo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_asset_details'");
    $s->execute();
    return (int) $s->fetchColumn() > 0;
})($pdo);

$branches = [];
if ($assetDetailsTableExists) {
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

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $itemCode = trim($_POST['item_code'] ?? '') ?: generateItemCode($pdo);
        $itemName = trim($_POST['item_name'] ?? '');
        if (empty($itemName)) throw new Exception("Item name is required.");

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId <= 0) throw new Exception("Category is required.");

        // Handle inline subcategory creation
        $newSubcatName = trim($_POST['new_subcategory_name'] ?? '');
        $subcategoryId = null;
        $rawSubcat     = $_POST['subcategory_id'] ?? '';
        if (!empty($newSubcatName)) {
            // Generate a unique category_code from parent code + name slug
            $parentRow = $pdo->prepare("SELECT category_code FROM inv_categories WHERE category_id = ?");
            $parentRow->execute([$categoryId]);
            $parentCode = $parentRow->fetchColumn() ?: 'CAT';
            $nameSlug   = substr(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $newSubcatName)), 0, 10);
            $baseCode   = substr($parentCode . '_' . $nameSlug, 0, 18);
            $code       = $baseCode;
            $suffix     = 2;
            $codeChk    = $pdo->prepare("SELECT COUNT(*) FROM inv_categories WHERE category_code = ?");
            do {
                $codeChk->execute([$code]);
                if ((int) $codeChk->fetchColumn() === 0) break;
                $code = substr($baseCode, 0, 17) . $suffix++;
                if ($suffix >= 100) throw new Exception("Could not generate a unique code for subcategory '$newSubcatName'. Please try a different name.");
            } while (true);
            $insSubcat = $pdo->prepare(
                "INSERT INTO inv_categories (category_name, category_code, parent_category_id, is_active) VALUES (?, ?, ?, 1)"
            );
            $insSubcat->execute([$newSubcatName, $code, $categoryId]);
            $subcategoryId = (int) $pdo->lastInsertId();
        } elseif ($rawSubcat !== '' && $rawSubcat !== '__new__') {
            $subcategoryId = (int) $rawSubcat ?: null;
        }

        $uomId = (int) ($_POST['uom_id'] ?? 0);
        if ($uomId <= 0) throw new Exception("Unit of measure is required.");

        // Primary Asset Type is mandatory; classifications may only belong to it
        [$itemDomain, $assetTypeId, $inventoryTypeId] = validatePrimaryAssetTypeSelection(
            $pdo,
            $_POST['item_domain'] ?? 'INVENTORY',
            $_POST['asset_type_id'] ?? null,
            $_POST['inventory_type_id'] ?? null
        );

        // Check for duplicate item code
        $dup = $pdo->prepare("SELECT COUNT(*) FROM inv_items WHERE item_code = ?");
        $dup->execute([$itemCode]);
        if ($dup->fetchColumn() > 0) throw new Exception("Item code '$itemCode' already exists.");

        $stmt = $pdo->prepare("
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
                asset_item_type_id,
                created_by
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
                ?,
                ?
            )
        ");

        $stmt->execute([
            $itemCode,
            $itemName,
            trim($_POST['description'] ?? ''),
            $categoryId,
            $subcategoryId,
            $uomId,
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
            (float) ($_POST['last_cost'] ?? 0),
            (float) ($_POST['average_cost'] ?? 0),
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
        ]);

        $newItemId = (int) $pdo->lastInsertId();

        // Save risk classes
        if (!empty($_POST['risk_classes'])) {
            $rcStmt = $pdo->prepare("INSERT INTO inv_item_risk_classes (item_id, risk_class_id) VALUES (?, ?)");
            foreach ($_POST['risk_classes'] as $rcId) {
                $rcStmt->execute([$newItemId, (int) $rcId]);
            }
        }

        // ── Asset Register Details ──────────────────────────────────────────
        if ($assetDetailsTableExists && in_array($itemDomain, ['ASSET', 'BOTH'])) {
            $arInventoryNumber  = trim($_POST['ar_inventory_number'] ?? '');
            $arCondition        = trim($_POST['ar_condition'] ?? '');
            $arStatus           = trim($_POST['ar_asset_status'] ?? '');
            $arAcquiredDate     = trim($_POST['ar_acquired_date'] ?? '');
            $arCustodian        = trim($_POST['ar_custodian'] ?? '');
            $arCustodianRole    = trim($_POST['ar_custodian_role'] ?? '');
            $arSecondaryCustodian = trim($_POST['ar_secondary_custodian'] ?? '');
            $arLocation         = trim($_POST['ar_location'] ?? '');
            $arSite             = trim($_POST['ar_site'] ?? '');
            $arBuilding         = trim($_POST['ar_building'] ?? '');
            $arFloorRoom        = trim($_POST['ar_floor_room'] ?? '');
            $arLocationId       = ($_POST['ar_location_id'] ?? '') !== '' ? (int)$_POST['ar_location_id'] : null;
            $arPurchaseCost     = trim($_POST['ar_purchase_cost'] ?? '');
            $arDisposalDate     = trim($_POST['ar_disposal_date'] ?? '');
            $arDisposalAmount   = trim($_POST['ar_disposal_amount'] ?? '');
            $arIsDisposed       = isset($_POST['ar_is_disposed']) ? 1 : 0;
            // Warranty fields
            $arWarrantyProvider   = trim($_POST['ar_warranty_provider'] ?? '');
            $arWarrantyStartDate  = trim($_POST['ar_warranty_start_date'] ?? '');
            $arWarrantyEndDate    = trim($_POST['ar_warranty_end_date'] ?? '');
            $arWarrantyPeriod     = trim($_POST['ar_warranty_period'] ?? '');
            $arWarrantyReference  = trim($_POST['ar_warranty_reference'] ?? '');
            $arWarrantyNotes      = trim($_POST['ar_warranty_notes'] ?? '');
            $arWarrantyStatus     = trim($_POST['ar_warranty_status'] ?? '');

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

            // Disposal consistency check
            if ($arIsDisposed) {
                if ($arFieldRequired['ar_require_disposal_date'] && $arDisposalDate === '')
                    $arErrors[] = "Disposal Date is required when the asset is disposed.";
                if ($arDisposalAmount === '')
                    $arErrors[] = "Disposal Amount Realized is required when the asset is disposed.";
            }

            if (!empty($arErrors)) {
                throw new Exception(implode(' ', $arErrors));
            }

            // Unique inventory number check
            if ($arInventoryNumber !== '') {
                $dupAr = $pdo->prepare("SELECT COUNT(*) FROM inv_asset_details WHERE asset_code = ?");
                $dupAr->execute([$arInventoryNumber]);
                if ($dupAr->fetchColumn() > 0)
                    throw new Exception("Inventory Number '$arInventoryNumber' is already assigned to another asset.");
            }

            $arStmt = $pdo->prepare("
                INSERT INTO inv_asset_details
                    (item_id, asset_code, acquired_date, asset_condition, asset_status,
                     custodian_name, custodian_role, accountable_officer, secondary_custodian,
                     site, building, floor_room, address, location_id,
                     purchase_cost, disposal_date, disposal_amount, is_disposed,
                     warranty_provider, warranty_start_date, warranty_end_date,
                     warranty_period, warranty_reference, warranty_notes, warranty_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $arStmt->execute([
                $newItemId,
                $arInventoryNumber ?: null,
                $arAcquiredDate ?: null,
                $arCondition ?: null,
                $arStatus ?: null,
                $arCustodian ?: null,
                $arCustodianRole ?: null,
                $arCustodian ?: null,      // accountable_officer mirrors custodian_name
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

            logInventoryAudit($pdo, 'inv_asset_details', $newItemId, 'CREATE',
                "Asset Register record created: Inv# $arInventoryNumber, Condition: $arCondition, Status: $arStatus, Custodian: $arCustodian");
        }

        // ── Initial Stock ───────────────────────────────────────────────────
        $initialQty     = max(0, (float) ($_POST['initial_quantity'] ?? 1));
        $initialLocId   = (int) ($_POST['initial_location_id'] ?? 0);
        if ($initialQty > 0 && $initialLocId > 0) {
            $stockId = increaseStock($pdo, $newItemId, $initialLocId, $initialQty, ['unit_cost' => (float)($_POST['standard_cost'] ?? 0)]);
            recordStockTransaction($pdo, [
                'transaction_type' => 'RECEIPT',
                'item_id'          => $newItemId,
                'stock_id'         => $stockId,
                'location_id'      => $initialLocId,
                'quantity'         => $initialQty,
                'unit_cost'        => (float) ($_POST['standard_cost'] ?? 0),
                'notes'            => 'Opening balance set at item creation',
            ]);
            logInventoryAudit($pdo, 'inv_stock', $newItemId, 'OPENING_BALANCE',
                "Initial stock of $initialQty set at location ID $initialLocId for item $itemCode");
        }

        logInventoryAudit($pdo, 'inv_items', $newItemId, 'CREATE', "Item created: $itemCode - $itemName");

        $pdo->commit();
        pop("Item '$itemName' created successfully.", "/inventory/items/view.php?id=$newItemId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-plus-circle"></i> Add Inventory Item</h2>
    <a href="/inventory/items/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Items
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
                    <label class="form-label">Item Code</label>
                    <input type="text" name="item_code" class="form-control" placeholder="Auto-generated if blank"
                           value="<?= htmlspecialchars($_POST['item_code'] ?? '') ?>">
                    <small class="text-muted">Leave blank for auto-generation</small>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="item_name" class="form-control" required
                           value="<?= htmlspecialchars($_POST['item_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Barcode / QR / GS1</label>
                    <input type="text" name="barcode" class="form-control"
                           value="<?= htmlspecialchars($_POST['barcode'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category_id" id="categorySelect" class="form-select" required>
                        <option value="">Select...</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>" <?= ($_POST['category_id'] ?? '') == $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Subcategory</label>
                    <select name="subcategory_id" id="subcategorySelect" class="form-select">
                        <option value="">— None —</option>
                        <option value="__new__">+ Add new subcategory…</option>
                    </select>
                    <div id="newSubcatField" class="mt-2" style="display:none">
                        <input type="text" name="new_subcategory_name" id="newSubcatName" class="form-control"
                               placeholder="New subcategory name"
                               value="<?= htmlspecialchars($_POST['new_subcategory_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Unit of Measure <span class="text-danger">*</span></label>
                    <select name="uom_id" class="form-select" required>
                        <option value="">Select...</option>
                        <?php foreach ($uoms as $u): ?>
                        <option value="<?= $u['uom_id'] ?>" <?= ($_POST['uom_id'] ?? '') == $u['uom_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['uom_name']) ?> (<?= $u['uom_code'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pack Size / Conversion</label>
                    <input type="number" step="0.01" name="pack_size" class="form-control" value="<?= $_POST['pack_size'] ?? '1.00' ?>">
                </div>
                <!-- Domain classification (migration 024 / Primary Asset Type restructure) -->
                <?php if (!empty($assetTypes) || !empty($invTypes)): ?>
                <div class="col-md-4">
                    <label class="form-label">Item Domain <span class="text-danger">*</span></label>
                    <select name="item_domain" id="itemDomain" class="form-select" required>
                        <option value="INVENTORY" <?= ($_POST['item_domain'] ?? 'INVENTORY') === 'INVENTORY' ? 'selected' : '' ?>>Inventory / Stock / Consumable</option>
                        <option value="ASSET"     <?= ($_POST['item_domain'] ?? '') === 'ASSET'     ? 'selected' : '' ?>>Asset (Fixed/Movable)</option>
                        <option value="BOTH"      <?= ($_POST['item_domain'] ?? '') === 'BOTH'      ? 'selected' : '' ?>>Both</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Primary Asset Type <span class="text-danger">*</span></label>
                    <input type="text" id="primaryAssetType" class="form-control" readonly
                           value="<?= htmlspecialchars(getPrimaryAssetTypeLabel($_POST['item_domain'] ?? 'INVENTORY')) ?>">
                    <small class="text-muted">Derived from the Item Domain per Ministry of Finance classification.</small>
                </div>
                <div class="col-md-6" id="assetTypeGroup" style="<?= in_array($_POST['item_domain'] ?? 'INVENTORY', ['ASSET','BOTH']) ? '' : 'display:none' ?>">
                    <label class="form-label">Asset Classification (Property, Plant, and Equipment)</label>
                    <select name="asset_type_id" class="form-select">
                        <option value="">— Select classification —</option>
                        <?php foreach ($assetTypes as $at): ?>
                        <option value="<?= $at['asset_type_id'] ?>" <?= ($_POST['asset_type_id'] ?? '') == $at['asset_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($at['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6" id="invTypeGroup" style="<?= in_array($_POST['item_domain'] ?? 'INVENTORY', ['INVENTORY','BOTH']) ? '' : 'display:none' ?>">
                    <label class="form-label">Asset Classification (Consumable and Expendable)</label>
                    <select name="inventory_type_id" class="form-select">
                        <option value="">— Select classification —</option>
                        <?php foreach ($invTypes as $it): ?>
                        <option value="<?= $it['inventory_type_id'] ?>" <?= ($_POST['inventory_type_id'] ?? '') == $it['inventory_type_id'] ? 'selected' : '' ?>>
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
                            <?= ($_POST['asset_item_type_id'] ?? '') == $ait['item_type_id'] ? 'selected' : '' ?>>
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
                <div class="col-md-3">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" name="manufacturer" class="form-control" value="<?= htmlspecialchars($_POST['manufacturer'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Part / Catalogue Number</label>
                    <input type="text" name="part_number" class="form-control" value="<?= htmlspecialchars($_POST['part_number'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Tracking Flags -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-flag"></i> Tracking & Control Flags</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="serial_number_flag" id="snFlag" <?= isset($_POST['serial_number_flag']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="snFlag">Serial Number Tracking</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="batch_lot_flag" id="batchFlag" <?= isset($_POST['batch_lot_flag']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="batchFlag">Batch / Lot Tracking</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="expiry_date_flag" id="expFlag" <?= isset($_POST['expiry_date_flag']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="expFlag">Expiry Date Tracking</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="hazard_class_flag" id="hazFlag" <?= isset($_POST['hazard_class_flag']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="hazFlag">Hazardous Item</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="inspection_required" id="inspFlag" <?= isset($_POST['inspection_required']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="inspFlag">Inspection Required</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="asset_inventory_boundary" id="aibFlag" <?= isset($_POST['asset_inventory_boundary']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aibFlag">Asset/Inventory Boundary</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Shelf Life (days)</label>
                    <input type="number" name="shelf_life_days" class="form-control" value="<?= $_POST['shelf_life_days'] ?? '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Receiving Tolerance %</label>
                    <input type="number" step="0.01" name="receiving_tolerance_pct" class="form-control" value="<?= $_POST['receiving_tolerance_pct'] ?? '0' ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Storage Conditions</label>
                    <input type="text" name="storage_conditions" class="form-control" placeholder="e.g. 15-25°C, dry storage"
                           value="<?= htmlspecialchars($_POST['storage_conditions'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Replenishment -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-arrow-repeat"></i> Replenishment Parameters</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Reorder Level</label>
                    <input type="number" step="0.01" name="reorder_level" class="form-control" value="<?= $_POST['reorder_level'] ?? '0' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Reorder Quantity</label>
                    <input type="number" step="0.01" name="reorder_quantity" class="form-control" value="<?= $_POST['reorder_quantity'] ?? '0' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Min Level</label>
                    <input type="number" step="0.01" name="min_level" class="form-control" value="<?= $_POST['min_level'] ?? '0' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Max Level</label>
                    <input type="number" step="0.01" name="max_level" class="form-control" value="<?= $_POST['max_level'] ?? '0' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Safety Stock</label>
                    <input type="number" step="0.01" name="safety_stock" class="form-control" value="<?= $_POST['safety_stock'] ?? '0' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Lead Time (days)</label>
                    <input type="number" name="lead_time_days" class="form-control" value="<?= $_POST['lead_time_days'] ?? '0' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Economic Order Qty</label>
                    <input type="number" step="0.01" name="economic_order_qty" class="form-control" value="<?= $_POST['economic_order_qty'] ?? '' ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Costing & Financial -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-currency-dollar"></i> Costing & Financial</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Standard Cost</label>
                    <input type="number" step="0.01" name="standard_cost" class="form-control" value="<?= $_POST['standard_cost'] ?? '0.00' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Cost</label>
                    <input type="number" step="0.01" name="last_cost" class="form-control" value="<?= $_POST['last_cost'] ?? '0.00' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Average Cost</label>
                    <input type="number" step="0.01" name="average_cost" class="form-control" value="<?= $_POST['average_cost'] ?? '0.00' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Valuation Method</label>
                    <select name="valuation_method" class="form-select">
                        <option value="AVERAGE" <?= ($_POST['valuation_method'] ?? '') === 'AVERAGE' ? 'selected' : '' ?>>Weighted Average</option>
                        <option value="FIFO" <?= ($_POST['valuation_method'] ?? '') === 'FIFO' ? 'selected' : '' ?>>FIFO</option>
                        <option value="STANDARD" <?= ($_POST['valuation_method'] ?? '') === 'STANDARD' ? 'selected' : '' ?>>Standard Cost</option>
                        <option value="SPECIFIC" <?= ($_POST['valuation_method'] ?? '') === 'SPECIFIC' ? 'selected' : '' ?>>Specific Identification</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Funding Source</label>
                    <input type="text" name="funding_source" class="form-control" value="<?= htmlspecialchars($_POST['funding_source'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Program / Project Code</label>
                    <input type="text" name="program_project_code" class="form-control" value="<?= htmlspecialchars($_POST['program_project_code'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">GL / Account Code</label>
                    <input type="text" name="gl_account_code" class="form-control" value="<?= htmlspecialchars($_POST['gl_account_code'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Classification -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-diagram-3"></i> Classification & Control</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Criticality</label>
                    <select name="criticality_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach ($critClasses as $cc): ?>
                        <option value="<?= $cc['criticality_id'] ?>" <?= ($_POST['criticality_id'] ?? '') == $cc['criticality_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cc['criticality_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Accounting Class</label>
                    <select name="acct_class_id" class="form-select">
                        <option value="">None</option>
                        <?php foreach ($acctClasses as $ac): ?>
                        <option value="<?= $ac['acct_class_id'] ?>" <?= ($_POST['acct_class_id'] ?? '') == $ac['acct_class_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ac['acct_class_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Item Status</label>
                    <select name="item_status" class="form-select">
                        <option value="ACTIVE">Active</option>
                        <option value="BLOCKED">Blocked</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Issue Policy</label>
                    <select name="issue_policy" class="form-select">
                        <option value="UNRESTRICTED">Unrestricted</option>
                        <option value="APPROVAL_REQUIRED">Approval Required</option>
                        <option value="CONTROLLED">Controlled Issue</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Risk & Control Classes</label>
                    <div class="row g-2">
                        <?php foreach ($riskClasses as $rc): ?>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="risk_classes[]"
                                       value="<?= $rc['risk_class_id'] ?>" id="rc<?= $rc['risk_class_id'] ?>"
                                       <?= in_array($rc['risk_class_id'], $_POST['risk_classes'] ?? []) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rc<?= $rc['risk_class_id'] ?>">
                                    <?= htmlspecialchars($rc['risk_name']) ?>
                                </label>
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
        <div class="card-header bg-dark text-white"><i class="bi bi-cart"></i> Procurement Reference</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Contract Reference</label>
                    <input type="text" name="contract_reference" class="form-control" value="<?= htmlspecialchars($_POST['contract_reference'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Procurement Method</label>
                    <input type="text" name="procurement_method" class="form-control" placeholder="e.g. Direct, Competitive, Framework"
                           value="<?= htmlspecialchars($_POST['procurement_method'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <?php if ($assetDetailsTableExists): ?>
    <!-- Asset Register Details (shown only for ASSET / BOTH domain) -->
    <div class="card border-0 shadow-sm mb-4 border-warning" id="assetRegisterSection"
         style="<?= in_array($_POST['item_domain'] ?? 'INVENTORY', ['ASSET','BOTH']) ? '' : 'display:none' ?>">
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
                <!-- Inventory Number -->
                <div class="col-md-4">
                    <label class="form-label">Inventory Number Assigned <?= $arFieldRequired['ar_require_inventory_number'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="ar_inventory_number" id="ar_inventory_number"
                           class="form-control <?= $arFieldRequired['ar_require_inventory_number'] ? 'ar-required' : '' ?>"
                           placeholder="Unique asset identifier"
                           value="<?= htmlspecialchars($_POST['ar_inventory_number'] ?? '') ?>">
                    <small class="text-muted">Must be unique across the asset register.</small>
                </div>
                <!-- Purchase Cost -->
                <div class="col-md-4">
                    <label class="form-label">Cost / Purchase Price <?= $arFieldRequired['ar_require_purchase_cost'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="number" step="0.01" min="0" name="ar_purchase_cost" id="ar_purchase_cost"
                           class="form-control <?= $arFieldRequired['ar_require_purchase_cost'] ? 'ar-required' : '' ?>"
                           value="<?= htmlspecialchars($_POST['ar_purchase_cost'] ?? '') ?>">
                </div>
                <!-- Date of Acquisition -->
                <div class="col-md-4">
                    <label class="form-label">Date of Acquisition <?= $arFieldRequired['ar_require_acquired_date'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="date" name="ar_acquired_date" id="ar_acquired_date"
                           class="form-control <?= $arFieldRequired['ar_require_acquired_date'] ? 'ar-required' : '' ?>"
                           value="<?= htmlspecialchars($_POST['ar_acquired_date'] ?? '') ?>">
                </div>
                <!-- Asset Condition -->
                <div class="col-md-4">
                    <label class="form-label">Asset Condition <?= $arFieldRequired['ar_require_condition'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_condition" id="ar_condition" class="form-select <?= $arFieldRequired['ar_require_condition'] ? 'ar-required' : '' ?>">
                        <option value="">— Select —</option>
                        <?php foreach (['New','Good','Fair','Poor','Damaged'] as $cnd): ?>
                        <option value="<?= $cnd ?>" <?= ($_POST['ar_condition'] ?? '') === $cnd ? 'selected' : '' ?>><?= $cnd ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Asset Status -->
                <div class="col-md-4">
                    <label class="form-label">Asset Status <?= $arFieldRequired['ar_require_status'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_asset_status" id="ar_asset_status" class="form-select <?= $arFieldRequired['ar_require_status'] ? 'ar-required' : '' ?>">
                        <option value="">— Select —</option>
                        <?php foreach (['Active','In Use','In Storage','Under Repair','Disposed'] as $st): ?>
                        <option value="<?= $st ?>" <?= ($_POST['ar_asset_status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Custodian -->
                <div class="col-md-4">
                    <label class="form-label">Custodian <?= $arFieldRequired['ar_require_custodian'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="ar_custodian" id="ar_custodian"
                           class="form-control <?= $arFieldRequired['ar_require_custodian'] ? 'ar-required' : '' ?>"
                           placeholder="Person or department responsible"
                           value="<?= htmlspecialchars($_POST['ar_custodian'] ?? '') ?>">
                </div>
                <!-- Custodian Role -->
                <div class="col-md-4">
                    <label class="form-label">Custodian Role</label>
                    <?php
                    $defaultCustodianRole = 'Property Management Officer';
                    $savedCustodianRole   = $_POST['ar_custodian_role'] ?? $defaultCustodianRole;
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
                           value="<?= htmlspecialchars($_POST['ar_secondary_custodian'] ?? '') ?>">
                    <small class="text-muted">Backup person responsible for this asset.</small>
                </div>
                <!-- Location -->
                <div class="col-md-3">
                    <label class="form-label">Site / Campus <?= $arFieldRequired['ar_require_location'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <select name="ar_site" id="ar_site" class="form-select ar-location">
                        <option value="">— Select Site —</option>
                        <?php if (!empty($_POST['ar_site'])): ?>
                        <option value="<?= htmlspecialchars($_POST['ar_site']) ?>" selected><?= htmlspecialchars($_POST['ar_site']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Building</label>
                    <select name="ar_building" id="ar_building" class="form-select ar-location" disabled>
                        <option value="">— Select Building —</option>
                        <?php if (!empty($_POST['ar_building'])): ?>
                        <option value="<?= htmlspecialchars($_POST['ar_building']) ?>" selected><?= htmlspecialchars($_POST['ar_building']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Floor / Room</label>
                    <select name="ar_floor_room" id="ar_floor_room" class="form-select ar-location" disabled>
                        <option value="">— Select Floor / Room —</option>
                        <?php if (!empty($_POST['ar_floor_room'])): ?>
                        <option value="<?= htmlspecialchars($_POST['ar_floor_room']) ?>" selected><?= htmlspecialchars($_POST['ar_floor_room']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Address / Other Location</label>
                    <input type="text" name="ar_location" id="ar_location" class="form-control ar-location"
                           placeholder="Street address or other"
                           value="<?= htmlspecialchars($_POST['ar_location'] ?? '') ?>">
                </div>
                <!-- Hidden field for resolved location_id -->
                <input type="hidden" name="ar_location_id" id="ar_location_id"
                       value="<?= htmlspecialchars($_POST['ar_location_id'] ?? '') ?>">
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
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Warranty Provider / Vendor</label>
                    <input type="text" name="ar_warranty_provider" class="form-control"
                           placeholder="Provider or vendor name"
                           value="<?= htmlspecialchars($_POST['ar_warranty_provider'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Start Date</label>
                    <input type="date" name="ar_warranty_start_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['ar_warranty_start_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty End Date</label>
                    <input type="date" name="ar_warranty_end_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['ar_warranty_end_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Period</label>
                    <input type="text" name="ar_warranty_period" class="form-control"
                           placeholder="e.g. 1 Year, 24 Months"
                           value="<?= htmlspecialchars($_POST['ar_warranty_period'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Reference / Contract No.</label>
                    <input type="text" name="ar_warranty_reference" class="form-control"
                           placeholder="Contract or reference number"
                           value="<?= htmlspecialchars($_POST['ar_warranty_reference'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Warranty Status</label>
                    <select name="ar_warranty_status" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach (['Active','Expired','Void','Pending','Unknown'] as $ws): ?>
                        <option value="<?= $ws ?>" <?= ($_POST['ar_warranty_status'] ?? '') === $ws ? 'selected' : '' ?>><?= $ws ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Warranty Notes</label>
                    <textarea name="ar_warranty_notes" class="form-control" rows="2"
                              placeholder="Any additional warranty details..."><?= htmlspecialchars($_POST['ar_warranty_notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Disposal Details -->
            <hr class="my-3">
            <h6 class="text-secondary"><i class="bi bi-trash3"></i> Disposal Information
                <small class="text-muted fw-normal">(required if asset has been disposed)</small>
            </h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="ar_is_disposed" id="ar_is_disposed"
                               <?= isset($_POST['ar_is_disposed']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ar_is_disposed">Asset is Disposed</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Disposal Date <?php if ($arFieldRequired['ar_require_disposal_date']): ?><span class="text-danger ar-disposal-required" style="display:none">*</span><?php endif; ?></label>
                    <input type="date" name="ar_disposal_date" id="ar_disposal_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['ar_disposal_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Disposal Amount Realized <span class="text-danger ar-disposal-required" style="display:none">*</span></label>
                    <input type="number" step="0.01" min="0" name="ar_disposal_amount" id="ar_disposal_amount"
                           class="form-control"
                           value="<?= htmlspecialchars($_POST['ar_disposal_amount'] ?? '') ?>">
                </div>
            </div>

            <!-- Asset Register Preview -->
            <hr class="my-3">
            <div class="alert alert-info small mb-0">
                <strong><i class="bi bi-info-circle"></i> Asset Register Record Format:</strong>
                Inventory Number | Asset Description | Cost | Condition | Status | Date of Acquisition | Custodian | Location | Disposal Date | Disposal Amount Realized
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Initial Stock -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><i class="bi bi-boxes"></i> Initial Stock</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Initial Quantity</label>
                    <input type="number" step="0.01" min="0" name="initial_quantity" id="initial_quantity"
                           class="form-control" value="<?= htmlspecialchars($_POST['initial_quantity'] ?? '1') ?>">
                    <small class="text-muted">Defaults to 1. Set to 0 to skip stock initialisation.</small>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Initial Location</label>
                    <select name="initial_location_id" id="initial_location_id" class="form-select">
                        <option value="">— Select a location (optional) —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>"
                            <?= ($_POST['initial_location_id'] ?? '') == $loc['location_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['location_code'] . ($loc['display_name'] ? ' — ' . $loc['display_name'] : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">The location where this item will be stocked. Required when Initial Quantity &gt; 0 to enable transfers.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end mb-4">
        <a href="/inventory/items/list.php" class="btn btn-outline-secondary me-2">Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check-circle"></i> Create Item
        </button>
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
    var arLocation   = arSection ? arSection.querySelectorAll('.ar-location') : [];
    var disposedChk  = document.getElementById('ar_is_disposed');
    var statusSel    = document.getElementById('ar_asset_status');
    var disposalDate = document.getElementById('ar_disposal_date');
    var disposalAmt  = document.getElementById('ar_disposal_amount');
    var disposalStars = arSection ? arSection.querySelectorAll('.ar-disposal-required') : [];

    function setArRequired(enable) {
        arRequired.forEach(function (el) { el.required = enable; });
        // Location: at least one must be filled — handled server-side; toggle visual asterisk only
        // No client-side required on individual location fields (any one suffices)
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
}());

// ── Subcategory dynamic loader ──────────────────────────────────────────────
(function () {
    var catSel        = document.getElementById('categorySelect');
    var subcatSel     = document.getElementById('subcategorySelect');
    var newSubcatField = document.getElementById('newSubcatField');
    var newSubcatName  = document.getElementById('newSubcatName');

    if (!catSel || !subcatSel) return;

    var preselectedId  = <?= json_encode($_POST['subcategory_id'] ?? '') ?>;
    var preselectedNew = <?= json_encode(trim($_POST['new_subcategory_name'] ?? '')) ?>;

    function buildOpts(subs, selectId) {
        subcatSel.innerHTML = '<option value="">— None —</option>';
        subs.forEach(function (sub) {
            var opt = document.createElement('option');
            opt.value = sub.category_id;
            opt.textContent = sub.category_name;
            if (String(sub.category_id) === String(selectId)) opt.selected = true;
            subcatSel.appendChild(opt);
        });
        var addOpt = document.createElement('option');
        addOpt.value = '__new__';
        addOpt.textContent = '+ Add new subcategory\u2026';
        if (selectId === '__new__') addOpt.selected = true;
        subcatSel.appendChild(addOpt);
        // Sync visibility of the "new name" input after options are built
        syncNewField();
    }

    function loadSubcategories(categoryId, selectId, afterLoad) {
        if (!categoryId) { buildOpts([], selectId); if (afterLoad) afterLoad(); return; }
        fetch('/inventory/items/get_subcategories.php?category_id=' + encodeURIComponent(categoryId))
            .then(function (r) { return r.json(); })
            .then(function (data) { buildOpts(data, selectId); if (afterLoad) afterLoad(); })
            .catch(function ()   { buildOpts([], selectId); if (afterLoad) afterLoad(); });
    }

    function syncNewField() {
        var show = subcatSel.value === '__new__';
        newSubcatField.style.display = show ? '' : 'none';
        if (!show && newSubcatName) newSubcatName.value = '';
    }

    catSel.addEventListener('change', function () {
        newSubcatField.style.display = 'none';
        loadSubcategories(catSel.value, '');
    });

    subcatSel.addEventListener('change', syncNewField);

    // Restore state after a failed POST submission
    var initSelectId = preselectedNew ? '__new__' : preselectedId;
    if (catSel.value) {
        loadSubcategories(catSel.value, initSelectId, function () {
            if (preselectedNew && newSubcatName) newSubcatName.value = preselectedNew;
        });
    }
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
        // Populate location_id from selected option
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
                    // No separate floor level — load rooms directly (floors=rooms in flat hierarchy)
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
            // Floor selected but not a room — load rooms for this floor
            buildOptions(floorSel, [], '', '— Select Floor / Room —');
            loadRooms(siteSel.value, buildSel.value, floorSel.value, '');
        }
    });

    // Initial population — restore any pre-selected values (e.g. after failed POST)
    var initSite  = <?= json_encode($_POST['ar_site'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var initBuild = <?= json_encode($_POST['ar_building'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var initFloor = <?= json_encode($_POST['ar_floor_room'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    loadSites(initSite);
    if (initSite) {
        loadBuildings(initSite, initBuild);
        if (initBuild) loadFloors(initSite, initBuild, initFloor);
    }
}());

// ── Asset code auto-parser ──────────────────────────────────────────────────
// Parses codes of the form  "G.C.10A / OM32 / 1"
// Part 1 = location code, Part 2 = category hint, Part 3 = quantity
(function () {
    var codeInput = document.querySelector('input[name="initial_quantity"]');
    var itemCodeInput = document.querySelector('input[name="item_code"]');
    var locSel       = document.getElementById('initial_location_id');
    var qtyInput     = document.getElementById('initial_quantity');
    // Map of location_code -> option value provided by server
    var locationMap  = {};
    <?php foreach ($locations as $loc): ?>
    locationMap[<?= json_encode($loc['location_code']) ?>] = <?= (int)$loc['location_id'] ?>;
    <?php endforeach; ?>

    function parseAssetCode(raw) {
        if (!raw || raw.indexOf('/') === -1) return null;
        var parts = raw.split('/').map(function(p) { return p.trim(); });
        if (parts.length < 2) return null;
        var result = { locationCode: parts[0] || null, categoryCode: parts[1] || null, quantity: null };
        if (parts.length >= 3 && parts[2] !== '') {
            var q = parseFloat(parts[2]);
            if (!isNaN(q) && q > 0) result.quantity = q;
        }
        return result;
    }

    function applyParsed(parsed) {
        if (!parsed) return;
        // Set initial quantity
        if (parsed.quantity !== null && qtyInput) {
            qtyInput.value = parsed.quantity;
        }
        // Set initial location from map
        if (parsed.locationCode && locSel) {
            var locId = locationMap[parsed.locationCode];
            if (locId) {
                locSel.value = locId;
            } else {
                // Show hint that location may need to be created
                var hint = document.getElementById('locationParseHint');
                if (!hint) {
                    hint = document.createElement('small');
                    hint.id = 'locationParseHint';
                    hint.className = 'text-warning';
                    locSel.parentNode.appendChild(hint);
                }
                hint.textContent = 'Location "' + parsed.locationCode + '" not found — select manually or create it first.';
            }
        }
    }

    if (itemCodeInput) {
        itemCodeInput.addEventListener('blur', function () {
            var parsed = parseAssetCode(this.value);
            applyParsed(parsed);
        });
        // Apply on page load if item code already has a value (after failed POST)
        if (itemCodeInput.value) applyParsed(parseAssetCode(itemCodeInput.value));
    }
}());
</script>