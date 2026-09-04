<?php
$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

$itemId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($itemId <= 0) { pop("Invalid item ID.", "/inventory/items/list.php", 1800, 'warning'); exit; }

$item = getInventoryItem($pdo, $itemId);
if (!$item) { pop("Item not found.", "/inventory/items/list.php", 1800, 'warning'); exit; }

$riskClasses = getItemRiskClasses($pdo, $itemId);

// Stock at each location
$stockStmt = $pdo->prepare("
    SELECT s.*, l.location_code, l.building, l.room_storage_area, l.bin_shelf_rack
    FROM inv_stock s
    JOIN inv_locations l ON s.location_id = l.location_id
    WHERE s.item_id = ?
    ORDER BY l.location_code, s.expiry_date
");
$stockStmt->execute([$itemId]);
$stockRecords = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

$totalOnHand = array_sum(array_column($stockRecords, 'quantity_on_hand'));
$totalAvailable = array_sum(array_column($stockRecords, 'quantity_available'));
$totalValue = 0;
foreach ($stockRecords as $sr) {
    $totalValue += (float) $sr['quantity_on_hand'] * (float) $sr['unit_cost'];
}

// Recent transactions
$txnStmt = $pdo->prepare("
    SELECT t.*, u.full_name AS performed_by_name
    FROM inv_transactions t
    LEFT JOIN users u ON t.performed_by = u.user_id
    WHERE t.item_id = ?
    ORDER BY t.created_at DESC
    LIMIT 20
");
$txnStmt->execute([$itemId]);
$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

// Suppliers
$supStmt = $pdo->prepare("
    SELECT iss.*, v.vendor_name
    FROM inv_item_suppliers iss
    JOIN vendors v ON iss.vendor_id = v.vendor_id
    WHERE iss.item_id = ?
");
$supStmt->execute([$itemId]);
$suppliers = $supStmt->fetchAll(PDO::FETCH_ASSOC);

// Asset Register details
$assetDetail = [];
$isAssetDomain = in_array($item['item_domain'] ?? 'INVENTORY', ['ASSET', 'BOTH']);
if ($isAssetDomain) {
    try {
        $adStmt = $pdo->prepare("
            SELECT asset_detail_id, item_id, asset_code, acquired_date, custodian_name, custodian_role,
                   asset_status, asset_condition, warranty_expiration, warranty_provider, warranty_start_date,
                   warranty_end_date, warranty_period, warranty_reference, warranty_notes, warranty_status,
                   address, site, building, floor_room, purchase_cost, accumulated_depreciation, carrying_value,
                   disposal_date, disposal_amount, is_disposed, secondary_custodian
            FROM inv_asset_details
            WHERE item_id = ?
            LIMIT 1
        ");
        $adStmt->execute([$itemId]);
        $assetDetail = $adStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* table may not be migrated yet */ }
}

// Active depreciation schedule (asset domain only)
$depSchedule = null;
$depPeriods  = [];
$depRecorded = 0;
if ($isAssetDomain && has_permission('view_asset_depreciation')) {
    try {
        $dsStmt = $pdo->prepare("
            SELECT s.schedule_id, s.method, s.cost_basis, s.salvage_value, s.useful_life_years,
                   s.total_production_units, s.declining_balance_rate, s.start_date, s.end_date, s.is_active,
                   (SELECT COUNT(*) FROM asset_depreciation_periods p WHERE p.schedule_id = s.schedule_id AND p.is_recorded = 1) AS recorded_periods,
                   (SELECT COUNT(*) FROM asset_depreciation_periods p WHERE p.schedule_id = s.schedule_id) AS total_periods
            FROM asset_depreciation_schedules s
            WHERE s.item_id = ? AND s.is_active = 1
            ORDER BY s.schedule_id DESC LIMIT 1
        ");
        $dsStmt->execute([$itemId]);
        $depSchedule = $dsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($depSchedule) {
            $dpStmt = $pdo->prepare("
                SELECT period_id, schedule_id, period_number, period_end_date, depreciation_charge,
                       accumulated_depreciation, book_value_end, is_recorded
                FROM asset_depreciation_periods
                WHERE schedule_id = ?
                ORDER BY period_number ASC LIMIT 5
            ");
            $dpStmt->execute([$depSchedule['schedule_id']]);
            $depPeriods  = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
            $depRecorded = (int)$depSchedule['recorded_periods'];
        }
    } catch (Throwable $e) { /* depreciation tables may not exist yet */ }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        <i class="bi bi-box-seam"></i>
        <?= htmlspecialchars($item['item_name']) ?>
        <small class="text-muted ms-2"><?= htmlspecialchars($item['item_code']) ?></small>
    </h2>
    <div>
        <?php if (has_permission('manage_inventory_items')): ?>
        <a href="/inventory/items/edit.php?id=<?= $itemId ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Edit
        </a>
        <a href="/inventory/items/duplicate.php?id=<?= $itemId ?>" class="btn btn-outline-secondary" title="Duplicate this item">
            <i class="bi bi-copy"></i> Duplicate
        </a>
        <?php endif; ?>
        <a href="/inventory/items/list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Status and KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <?php
                $statusColors = ['ACTIVE' => 'success', 'BLOCKED' => 'secondary', 'OBSOLETE' => 'dark',
                                 'QUARANTINED' => 'warning', 'DISPOSAL' => 'danger'];
                $sc = $statusColors[$item['item_status']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $sc ?> fs-6"><?= $item['item_status'] ?></span>
                <div class="text-muted small mt-1">Status</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-primary"><?= number_format($totalOnHand, 0) ?></div>
                <small class="text-muted">On Hand (<?= htmlspecialchars($item['uom_code']) ?>)</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold <?= $totalAvailable <= ($item['reorder_level'] ?? 0) ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($totalAvailable, 0) ?>
                </div>
                <small class="text-muted">Available</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-dark">$<?= number_format($totalValue, 2) ?></div>
                <small class="text-muted">Total Value</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold">$<?= number_format($item['average_cost'], 2) ?></div>
                <small class="text-muted">Avg Cost (<?= $item['valuation_method'] ?>)</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#details">Details</a></li>
    <?php if ($isAssetDomain): ?>
    <li class="nav-item"><a class="nav-link<?= empty($assetDetail) ? ' text-danger' : '' ?>" data-bs-toggle="tab" href="#asset-register">
        <i class="bi bi-clipboard2-check"></i> Asset Register
        <?php if (empty($assetDetail)): ?><span class="badge bg-danger ms-1">Incomplete</span><?php endif; ?>
    </a></li>
    <?php
    $hasWarranty = !empty($assetDetail) && (
        !empty($assetDetail['warranty_provider'])  ||
        !empty($assetDetail['warranty_start_date'])||
        !empty($assetDetail['warranty_end_date'])  ||
        !empty($assetDetail['warranty_expiration'])
    );
    $warrantyExpired = false;
    $warrantyEndDate = $assetDetail['warranty_end_date'] ?? $assetDetail['warranty_expiration'] ?? null;
    if ($warrantyEndDate) {
        $warrantyExpired = (strtotime($warrantyEndDate) < time());
    }
    ?>
    <li class="nav-item"><a class="nav-link<?= $warrantyExpired ? ' text-danger' : '' ?>" data-bs-toggle="tab" href="#warranty">
        <i class="bi bi-shield-check"></i> Warranty
        <?php if ($warrantyExpired): ?><span class="badge bg-danger ms-1">Expired</span><?php endif; ?>
    </a></li>
    <?php endif; ?>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#stock">Stock Levels</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#transactions">Transactions</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#replenishment">Replenishment</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#suppliers">Suppliers</a></li>
</ul>

<div class="tab-content">
    <!-- Details Tab -->
    <div class="tab-pane fade show active" id="details">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-bold">Product Information</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr><th class="w-40">Category</th><td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td></tr>
                            <tr><th>UOM</th><td><?= htmlspecialchars($item['uom_name'] ?? '-') ?> (<?= $item['uom_code'] ?>)</td></tr>
                            <tr><th>Pack Size</th><td><?= $item['pack_size'] ?></td></tr>
                            <tr><th>Barcode</th><td><?= htmlspecialchars($item['barcode'] ?? '-') ?></td></tr>
                            <tr><th>Manufacturer</th><td><?= htmlspecialchars($item['manufacturer'] ?? '-') ?></td></tr>
                            <tr><th>Brand</th><td><?= htmlspecialchars($item['brand'] ?? '-') ?></td></tr>
                            <tr><th>Model</th><td><?= htmlspecialchars($item['model'] ?? '-') ?></td></tr>
                            <tr><th>Part Number</th><td><?= htmlspecialchars($item['part_number'] ?? '-') ?></td></tr>
                            <tr><th>Description</th><td><?= nl2br(htmlspecialchars($item['description'] ?? '-')) ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-light fw-bold">Tracking & Control</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr><th>Serial Tracking</th><td><?= $item['serial_number_flag'] ? '✅ Yes' : '❌ No' ?></td></tr>
                            <tr><th>Batch/Lot Tracking</th><td><?= $item['batch_lot_flag'] ? '✅ Yes' : '❌ No' ?></td></tr>
                            <tr><th>Expiry Tracking</th><td><?= $item['expiry_date_flag'] ? '✅ Yes' : '❌ No' ?></td></tr>
                            <tr><th>Hazardous</th><td><?= $item['hazard_class_flag'] ? '⚠️ Yes' : '❌ No' ?></td></tr>
                            <tr><th>Inspection Required</th><td><?= $item['inspection_required'] ? '✅ Yes' : '❌ No' ?></td></tr>
                            <tr><th>Receiving Tolerance</th><td><?= $item['receiving_tolerance_pct'] ?>%</td></tr>
                            <tr><th>Shelf Life</th><td><?= $item['shelf_life_days'] ? $item['shelf_life_days'] . ' days' : '-' ?></td></tr>
                            <tr><th>Storage Conditions</th><td><?= htmlspecialchars($item['storage_conditions'] ?? '-') ?></td></tr>
                            <tr><th>Issue Policy</th><td><span class="badge bg-<?= $item['issue_policy'] === 'CONTROLLED' ? 'danger' : ($item['issue_policy'] === 'APPROVAL_REQUIRED' ? 'warning' : 'success') ?>"><?= $item['issue_policy'] ?></span></td></tr>
                        </table>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light fw-bold">Classification</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr>
                                <th>Domain</th>
                                <td>
                                    <?php
                                    $domainBadge = ['INVENTORY' => 'primary', 'ASSET' => 'success', 'BOTH' => 'info'];
                                    $domainLabel = ['INVENTORY' => 'Inventory / Stock / Consumable', 'ASSET' => 'Asset (Fixed/Movable)', 'BOTH' => 'Both'];
                                    $d = $item['item_domain'] ?? 'INVENTORY';
                                    ?>
                                    <span class="badge bg-<?= $domainBadge[$d] ?? 'secondary' ?>"><?= $domainLabel[$d] ?? htmlspecialchars($d) ?></span>
                                </td>
                            </tr>
                            <tr><th>Primary Asset Type</th><td><?= htmlspecialchars(getPrimaryAssetTypeLabel($d)) ?></td></tr>
                            <?php if (!empty($item['asset_type_name'])): ?>
                            <tr><th>Asset Classification (PPE)</th><td><?= htmlspecialchars($item['asset_type_name']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($item['inventory_type_name'])): ?>
                            <tr><th>Asset Classification (Consumable)</th><td><?= htmlspecialchars($item['inventory_type_name']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($item['asset_item_type_name'])): ?>
                            <tr>
                                <th>Asset Item Type</th>
                                <td>
                                    <code><?= htmlspecialchars($item['asset_item_type_code']) ?></code>
                                    &mdash; <?= htmlspecialchars($item['asset_item_type_name']) ?>
                                    <span class="text-muted small">(<?= htmlspecialchars($item['asset_item_group_name'] ?? '') ?>)</span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr><th>Criticality</th><td><?= htmlspecialchars($item['criticality_name'] ?? '-') ?></td></tr>
                            <tr><th>Accounting Class</th><td><?= htmlspecialchars($item['acct_class_name'] ?? '-') ?></td></tr>
                            <tr>
                                <th>Risk Classes</th>
                                <td>
                                    <?php if (empty($riskClasses)): ?>
                                        <span class="text-muted">None</span>
                                    <?php else: ?>
                                        <?php foreach ($riskClasses as $rc): ?>
                                            <span class="badge bg-outline-dark border me-1"><?= htmlspecialchars($rc['risk_name']) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><th>GL Account</th><td><?= htmlspecialchars($item['gl_account_code'] ?? '-') ?></td></tr>
                            <tr><th>Funding Source</th><td><?= htmlspecialchars($item['funding_source'] ?? '-') ?></td></tr>
                            <tr><th>Program/Project</th><td><?= htmlspecialchars($item['program_project_code'] ?? '-') ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Levels Tab -->
    <div class="tab-pane fade" id="stock">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Location</th>
                                <th>Building / Room</th>
                                <th>Bin/Shelf</th>
                                <th>Batch/Lot</th>
                                <th>Serial</th>
                                <th>Expiry</th>
                                <th class="text-end">On Hand</th>
                                <th class="text-end">Reserved</th>
                                <th class="text-end">Available</th>
                                <th class="text-end">Unit Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stockRecords)): ?>
                            <tr><td colspan="11" class="text-center text-muted py-4">No stock records.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($stockRecords as $sr): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($sr['location_code']) ?></code></td>
                                <td><?= htmlspecialchars(($sr['building'] ?? '') . ' / ' . ($sr['room_storage_area'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($sr['bin_shelf_rack'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($sr['batch_lot_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($sr['serial_number'] ?? '-') ?></td>
                                <td>
                                    <?php if ($sr['expiry_date']): ?>
                                        <?php $expDate = new DateTime($sr['expiry_date']); $now = new DateTime(); ?>
                                        <span class="<?= $expDate < $now ? 'text-danger fw-bold' : ($expDate->diff($now)->days < 30 ? 'text-warning' : '') ?>">
                                            <?= $sr['expiry_date'] ?>
                                        </span>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($sr['quantity_on_hand'], 2) ?></td>
                                <td class="text-end"><?= number_format($sr['quantity_reserved'], 2) ?></td>
                                <td class="text-end fw-bold"><?= number_format($sr['quantity_available'], 2) ?></td>
                                <td class="text-end">$<?= number_format($sr['unit_cost'], 2) ?></td>
                                <td><span class="badge bg-<?= $sr['stock_status'] === 'USABLE' ? 'success' : 'warning' ?>"><?= $sr['stock_status'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Transactions Tab -->
    <div class="tab-pane fade" id="transactions">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Balance</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No transactions recorded.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($transactions as $txn): ?>
                            <?php
                            $isPositive = in_array($txn['transaction_type'], ['RECEIVE', 'TRANSFER_IN', 'ADJUSTMENT_GAIN', 'RETURN']);
                            ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($txn['created_at'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $isPositive ? 'success' : 'danger' ?>">
                                        <?= str_replace('_', ' ', $txn['transaction_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($txn['reference_number'] ?? '-') ?></td>
                                <td class="text-end <?= $isPositive ? 'text-success' : 'text-danger' ?>">
                                    <?= $isPositive ? '+' : '-' ?><?= number_format(abs($txn['quantity']), 2) ?>
                                </td>
                                <td class="text-end">$<?= number_format($txn['unit_cost'], 2) ?></td>
                                <td class="text-end">$<?= number_format($txn['total_cost'], 2) ?></td>
                                <td class="text-end fw-bold"><?= $txn['balance_after'] !== null ? number_format($txn['balance_after'], 2) : '-' ?></td>
                                <td><?= htmlspecialchars($txn['performed_by_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Replenishment Tab -->
    <div class="tab-pane fade" id="replenishment">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <table class="table table-sm">
                            <tr><th>Reorder Level</th><td><?= number_format($item['reorder_level'], 2) ?></td></tr>
                            <tr><th>Reorder Quantity</th><td><?= number_format($item['reorder_quantity'], 2) ?></td></tr>
                            <tr><th>Min Level</th><td><?= number_format($item['min_level'], 2) ?></td></tr>
                            <tr><th>Max Level</th><td><?= number_format($item['max_level'], 2) ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <table class="table table-sm">
                            <tr><th>Safety Stock</th><td><?= number_format($item['safety_stock'], 2) ?></td></tr>
                            <tr><th>Lead Time</th><td><?= $item['lead_time_days'] ?> days</td></tr>
                            <tr><th>EOQ</th><td><?= $item['economic_order_qty'] ? number_format($item['economic_order_qty'], 2) : '-' ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <?php
                        $belowReorder = $totalAvailable <= (float) $item['reorder_level'] && (float) $item['reorder_level'] > 0;
                        ?>
                        <?php if ($belowReorder): ?>
                        <div class="alert alert-warning">
                            <strong>⚠️ Below Reorder Level</strong><br>
                            Available: <?= number_format($totalAvailable, 0) ?> <?= $item['uom_code'] ?><br>
                            Reorder Level: <?= number_format($item['reorder_level'], 0) ?> <?= $item['uom_code'] ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <strong>✅ Stock OK</strong><br>
                            Available stock is above reorder level.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Suppliers Tab -->
    <div class="tab-pane fade" id="suppliers">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>Supplier</th><th>Primary</th><th>Last Supply Date</th><th class="text-end">Last Price</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No suppliers linked.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($suppliers as $sup): ?>
                        <tr>
                            <td><?= htmlspecialchars($sup['vendor_name']) ?></td>
                            <td><?= $sup['is_primary'] ? '⭐ Primary' : '' ?></td>
                            <td><?= $sup['last_supply_date'] ?? '-' ?></td>
                            <td class="text-end"><?= $sup['last_price'] ? '$' . number_format($sup['last_price'], 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($isAssetDomain): ?>
    <!-- Asset Register Tab -->
    <div class="tab-pane fade" id="asset-register">
        <?php if (empty($assetDetail)): ?>
        <div class="alert alert-warning d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div>
                <strong>Asset Register record is incomplete.</strong>
                Required Asset Register information has not been recorded for this asset.
                <?php if (has_permission('manage_inventory_items')): ?>
                <a href="/inventory/items/edit.php?id=<?= $itemId ?>" class="btn btn-sm btn-warning ms-2">
                    <i class="bi bi-pencil"></i> Complete Asset Register
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <?php
        $ad = $assetDetail;
        $locationParts = array_filter([$ad['site'] ?? '', $ad['building'] ?? '', $ad['floor_room'] ?? '', $ad['address'] ?? '']);
        $locationStr   = $locationParts ? implode(', ', $locationParts) : '-';
        $isDisposed    = !empty($ad['is_disposed']) || !empty($ad['disposal_date']);
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clipboard2-check"></i> Asset Register Record</span>
                <?php if (has_permission('manage_inventory_items')): ?>
                <a href="/inventory/items/edit.php?id=<?= $itemId ?>" class="btn btn-sm btn-dark">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tbody>
                        <tr class="table-light">
                            <th class="w-35" style="width:35%">Inventory Number</th>
                            <td><strong><?= htmlspecialchars($ad['asset_code'] ?? '-') ?></strong></td>
                        </tr>
                        <tr>
                            <th>Asset Description</th>
                            <td><?= htmlspecialchars($item['item_name']) ?>
                                <?php if (!empty($item['description'])): ?>
                                <br><small class="text-muted"><?= nl2br(htmlspecialchars($item['description'])) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="table-light">
                            <th>Cost / Purchase Price</th>
                            <td><?= isset($ad['purchase_cost']) ? '$' . number_format((float)$ad['purchase_cost'], 2) : '-' ?></td>
                        </tr>
                        <tr>
                            <th>Asset Condition</th>
                            <td>
                                <?php
                                $condColors = ['New'=>'success','Good'=>'primary','Fair'=>'warning','Poor'=>'secondary','Damaged'=>'danger'];
                                $condVal = $ad['asset_condition'] ?? '-';
                                $condColor = $condColors[$condVal] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $condColor ?>"><?= htmlspecialchars($condVal) ?></span>
                            </td>
                        </tr>
                        <tr class="table-light">
                            <th>Asset Status</th>
                            <td>
                                <?php
                                $statColors = ['Active'=>'success','In Use'=>'primary','In Storage'=>'secondary','Under Repair'=>'warning','Not in Service'=>'dark','Disposed'=>'danger'];
                                $statVal = $ad['asset_status'] ?? '-';
                                $statColor = $statColors[$statVal] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $statColor ?>"><?= htmlspecialchars($statVal) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th>Date of Acquisition</th>
                            <td><?= !empty($ad['acquired_date']) ? date('d M Y', strtotime($ad['acquired_date'])) : '-' ?></td>
                        </tr>
                        <tr class="table-light">
                            <th>Custodian</th>
                            <td>
                                <?= htmlspecialchars($ad['custodian_name'] ?? '-') ?>
                                <?php if (!empty($ad['custodian_role'])): ?>
                                <span class="badge bg-secondary ms-1"><?= htmlspecialchars($ad['custodian_role']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Secondary Custodian</th>
                            <td><?= htmlspecialchars($ad['secondary_custodian'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>Asset Location</th>
                            <td><?= htmlspecialchars($locationStr) ?></td>
                        </tr>
                        <tr class="<?= $isDisposed ? 'table-danger' : 'table-light' ?>">
                            <th>Disposal Date</th>
                            <td><?= !empty($ad['disposal_date']) ? date('d M Y', strtotime($ad['disposal_date'])) : '-' ?></td>
                        </tr>
                        <tr class="<?= $isDisposed ? 'table-danger' : '' ?>">
                            <th>Disposal Amount Realized</th>
                            <td><?= isset($ad['disposal_amount']) && $ad['disposal_amount'] !== null ? '$' . number_format((float)$ad['disposal_amount'], 2) : '-' ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // Completeness check — flag missing mandatory fields
                $missingFields = [];
                if (empty($ad['asset_code']))       $missingFields[] = 'Inventory Number';
                if (!isset($ad['purchase_cost']) || $ad['purchase_cost'] === null || $ad['purchase_cost'] === '') $missingFields[] = 'Cost / Purchase Price';
                if (empty($ad['asset_condition']))   $missingFields[] = 'Asset Condition';
                if (empty($ad['asset_status']))      $missingFields[] = 'Asset Status';
                if (empty($ad['acquired_date']))     $missingFields[] = 'Date of Acquisition';
                if (empty($ad['custodian_name']))    $missingFields[] = 'Custodian';
                if (empty($ad['site']) && empty($ad['building']) && empty($ad['floor_room']) && empty($ad['address']))
                    $missingFields[] = 'Asset Location';
                if ($isDisposed) {
                    if (empty($ad['disposal_date']))   $missingFields[] = 'Disposal Date';
                    if (!isset($ad['disposal_amount']) || $ad['disposal_amount'] === null) $missingFields[] = 'Disposal Amount Realized';
                }
                ?>
                <?php if (!empty($missingFields)): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <strong><i class="bi bi-exclamation-triangle"></i> Incomplete Asset Register:</strong>
                    The following required fields are missing:
                    <ul class="mb-0 mt-1">
                        <?php foreach ($missingFields as $mf): ?>
                        <li><?= htmlspecialchars($mf) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="alert alert-success mt-3 mb-0">
                    <i class="bi bi-check-circle-fill"></i> Asset Register record is complete.
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isAssetDomain): ?>
    <!-- Warranty Tab -->
    <div class="tab-pane fade" id="warranty">
        <?php
        $ad = $assetDetail;
        $wEndDate  = $ad['warranty_end_date']   ?? $ad['warranty_expiration'] ?? null;
        $wStatus   = $ad['warranty_status']     ?? null;
        $wProvider = $ad['warranty_provider']   ?? null;
        $wStart    = $ad['warranty_start_date'] ?? null;
        $wPeriod   = $ad['warranty_period']     ?? null;
        $wRef      = $ad['warranty_reference']  ?? null;
        $wNotes    = $ad['warranty_notes']      ?? null;

        // Compute remaining days
        $remainingDays = null;
        $isExpired = false;
        if ($wEndDate) {
            $today = new DateTime('today');
            $end   = new DateTime($wEndDate);
            $diff  = $today->diff($end);
            if ($end < $today) {
                $isExpired = true;
                $remainingDays = -$diff->days; // negative = days past expiry
            } else {
                $remainingDays = $diff->days;
            }
        }

        $hasAnyWarrantyData = $wProvider || $wStart || $wEndDate || $wPeriod || $wRef || $wNotes || $wStatus;
        ?>
        <div class="card border-0 shadow-sm <?= $isExpired ? 'border-danger' : '' ?>">
            <div class="card-header <?= $isExpired ? 'bg-danger text-white' : 'bg-light' ?> d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-check"></i> Warranty Details
                    <?php if ($isExpired): ?><span class="badge bg-warning text-dark ms-2">Expired</span><?php endif; ?>
                </span>
                <?php if (has_permission('manage_inventory_items')): ?>
                <a href="/inventory/items/edit.php?id=<?= $itemId ?>" class="btn btn-sm <?= $isExpired ? 'btn-light' : 'btn-dark' ?>">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$hasAnyWarrantyData): ?>
                <div class="alert alert-secondary mb-0">
                    <i class="bi bi-info-circle"></i> No warranty information has been recorded for this asset.
                    <?php if (has_permission('manage_inventory_items')): ?>
                    <a href="/inventory/items/edit.php?id=<?= $itemId ?>" class="btn btn-sm btn-outline-secondary ms-2">Add Warranty Info</a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <table class="table table-sm table-bordered mb-0">
                    <tbody>
                        <tr class="table-light">
                            <th style="width:35%">Warranty Provider</th>
                            <td><?= htmlspecialchars($wProvider ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>Start Date</th>
                            <td><?= $wStart ? date('d M Y', strtotime($wStart)) : '-' ?></td>
                        </tr>
                        <tr class="<?= $isExpired ? 'table-danger' : 'table-light' ?>">
                            <th>End Date</th>
                            <td>
                                <?= $wEndDate ? date('d M Y', strtotime($wEndDate)) : '-' ?>
                                <?php if ($wEndDate && $isExpired): ?>
                                <span class="badge bg-danger ms-1">Expired <?= abs($remainingDays) ?> day<?= abs($remainingDays) !== 1 ? 's' : '' ?> ago</span>
                                <?php elseif ($wEndDate && $remainingDays !== null): ?>
                                <span class="badge bg-success ms-1"><?= $remainingDays ?> day<?= $remainingDays !== 1 ? 's' : '' ?> remaining</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Warranty Status</th>
                            <td>
                                <?php
                                $wsColors = ['Active'=>'success','Expired'=>'danger','Void'=>'secondary','Pending'=>'warning','Unknown'=>'dark'];
                                $wsVal = $wStatus ?? ($isExpired ? 'Expired' : '-');
                                $wsColor = $wsColors[$wsVal] ?? 'secondary';
                                if ($wsVal !== '-'): ?>
                                <span class="badge bg-<?= $wsColor ?>"><?= htmlspecialchars($wsVal) ?></span>
                                <?php else: echo '-'; endif; ?>
                            </td>
                        </tr>
                        <tr class="table-light">
                            <th>Warranty Period</th>
                            <td><?= htmlspecialchars($wPeriod ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>Reference / Contract No.</th>
                            <td><?= htmlspecialchars($wRef ?? '-') ?></td>
                        </tr>
                        <tr class="table-light">
                            <th>Notes</th>
                            <td><?= $wNotes ? nl2br(htmlspecialchars($wNotes)) : '-' ?></td>
                        </tr>
                        <?php if ($wEndDate && !$isExpired && $remainingDays !== null): ?>
                        <tr>
                            <th>Remaining Days</th>
                            <td><strong class="text-success"><?= $remainingDays ?> day<?= $remainingDays !== 1 ? 's' : '' ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($isExpired): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Warranty Expired:</strong> This asset's warranty expired on <?= date('d M Y', strtotime($wEndDate)) ?>.
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     DEPRECIATION MODULE (Asset domain only)
═══════════════════════════════════════════════════════ -->
<?php if ($isAssetDomain && has_permission('view_asset_depreciation')): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-graph-down-arrow me-2"></i>Asset Depreciation</h5>
        <div class="d-flex gap-2">
            <?php if (has_permission('manage_asset_depreciation')): ?>
            <a href="/inventory/depreciation/add.php?item_id=<?= $itemId ?>"
               class="btn btn-sm btn-outline-light">
                <i class="bi bi-<?= $depSchedule ? 'arrow-repeat' : 'plus-circle' ?> me-1"></i>
                <?= $depSchedule ? 'Regenerate' : 'Create' ?> Schedule
            </a>
            <?php endif; ?>
            <?php if ($depSchedule): ?>
            <a href="/inventory/depreciation/schedule.php?item_id=<?= $itemId ?>"
               class="btn btn-sm btn-outline-light">
                <i class="bi bi-table me-1"></i>Full Schedule
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$depSchedule): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-graph-down-arrow fs-2 d-block mb-2"></i>
            No depreciation schedule yet.
            <?php if (has_permission('manage_asset_depreciation')): ?>
            <a href="/inventory/depreciation/add.php?item_id=<?= $itemId ?>">Create one →</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Schedule summary -->
        <div class="row g-3 mb-4">
            <?php
            $depCost        = (float)$depSchedule['cost_basis'];
            $depSalvage     = (float)$depSchedule['salvage_value'];
            $depAccumulated = (float)($assetDetail['accumulated_depreciation'] ?? 0);
            $depBookValue   = (float)($assetDetail['carrying_value'] ?? ($depCost - $depAccumulated));
            $depPct         = $depCost > 0 ? min(100, round($depAccumulated / $depCost * 100)) : 0;
            $depTotalPeriods= (int)$depSchedule['total_periods'];
            ?>
            <div class="col-md-3 col-6">
                <div class="card border-0 bg-light text-center p-3">
                    <small class="text-muted d-block">Method</small>
                    <strong><?= str_replace('_', ' ', $depSchedule['method']) ?></strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 bg-light text-center p-3">
                    <small class="text-muted d-block">Useful Life</small>
                    <strong><?= $depSchedule['useful_life_years'] ? $depSchedule['useful_life_years'] . ' years' : '—' ?></strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 bg-light text-center p-3">
                    <small class="text-muted d-block">Accumulated Depreciation</small>
                    <strong class="text-danger">JMD <?= number_format($depAccumulated, 2) ?></strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 bg-light text-center p-3">
                    <small class="text-muted d-block">Remaining Book Value</small>
                    <strong class="text-success">JMD <?= number_format($depBookValue, 2) ?></strong>
                </div>
            </div>
        </div>
        <!-- Progress bar -->
        <div class="mb-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span><?= $depRecorded ?>/<?= $depTotalPeriods ?> periods recorded</span>
                <span><?= $depPct ?>% depreciated</span>
            </div>
            <div class="progress" style="height:10px; border-radius:5px;">
                <div class="progress-bar <?= $depPct >= 100 ? 'bg-success' : 'bg-primary' ?>"
                     style="width:<?= $depPct ?>%;"></div>
            </div>
        </div>
        <!-- First 5 periods preview -->
        <?php if (!empty($depPeriods)): ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>Year</th>
                        <th>Period End</th>
                        <th class="text-end">Charge</th>
                        <th class="text-end">Accumulated</th>
                        <th class="text-end">Book Value</th>
                        <th class="text-center">Posted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($depPeriods as $dp): ?>
                    <tr class="<?= $dp['is_recorded'] ? 'table-success-subtle' : '' ?>">
                        <td class="fw-bold"><?= $dp['period_number'] ?></td>
                        <td><?= date('d M Y', strtotime($dp['period_end_date'])) ?></td>
                        <td class="text-end text-danger">JMD <?= number_format((float)$dp['depreciation_charge'], 2) ?></td>
                        <td class="text-end">JMD <?= number_format((float)$dp['accumulated_depreciation'], 2) ?></td>
                        <td class="text-end fw-bold">JMD <?= number_format((float)$dp['book_value_end'], 2) ?></td>
                        <td class="text-center">
                            <?= $dp['is_recorded']
                                ? '<span class="badge bg-success">✓</span>'
                                : '<span class="badge bg-secondary">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($depTotalPeriods > 5): ?>
        <div class="text-center mt-2">
            <a href="/inventory/depreciation/schedule.php?item_id=<?= $itemId ?>" class="btn btn-sm btn-outline-secondary">
                View All <?= $depTotalPeriods ?> Periods →
            </a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
