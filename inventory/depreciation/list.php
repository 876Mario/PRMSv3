<?php
/**
 * List assets with depreciation schedules.
 */
$REQUIRE_PERMISSION = 'view_asset_depreciation';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$assets = [];
try {
    $stmt = $pdo->query("
        SELECT
            i.item_id,
            i.item_name,
            i.item_code,
            s.schedule_id,
            s.method,
            s.cost_basis,
            s.salvage_value,
            s.useful_life_years,
            s.start_date,
            s.end_date,
            ad.accumulated_depreciation,
            ad.carrying_value,
            ad.purchase_cost,
            (SELECT COUNT(*) FROM asset_depreciation_periods p WHERE p.schedule_id = s.schedule_id AND p.is_recorded = 1) AS recorded_periods,
            (SELECT COUNT(*) FROM asset_depreciation_periods p WHERE p.schedule_id = s.schedule_id) AS total_periods
        FROM asset_depreciation_schedules s
        JOIN inv_items i              ON i.item_id        = s.item_id
        LEFT JOIN inv_asset_details ad ON ad.item_id      = s.item_id
        WHERE s.is_active = 1
        ORDER BY i.item_name ASC
    ");
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Table may not exist yet
    error_log('depreciation/list.php: ' . $e->getMessage());
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container mt-4">
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="section-title">📉 Asset Depreciation</h3>
        <p class="text-muted mb-0">Active depreciation schedules across all assets.</p>
    </div>
</div>

<?php if (empty($assets)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    No depreciation schedules found. Open an asset and click
    <strong>Create Depreciation Schedule</strong> to get started.
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Asset</th>
                        <th>Method</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Salvage</th>
                        <th class="text-end">Accumulated Dep.</th>
                        <th class="text-end">Book Value</th>
                        <th class="text-center">Progress</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $a):
                        $accumulated = (float)($a['accumulated_depreciation'] ?? 0);
                        $bookValue   = (float)($a['carrying_value'] ?? ($a['cost_basis'] - $accumulated));
                        $cost        = (float)$a['cost_basis'];
                        $pct         = $cost > 0 ? min(100, round($accumulated / $cost * 100)) : 0;
                        $recorded    = (int)$a['recorded_periods'];
                        $total       = (int)$a['total_periods'];
                    ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/inventory/items/view.php?id=<?= $a['item_id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($a['item_name']) ?>
                            </a>
                            <small class="text-muted d-block"><?= htmlspecialchars($a['item_code']) ?></small>
                        </td>
                        <td><span class="badge bg-secondary"><?= str_replace('_', ' ', $a['method']) ?></span></td>
                        <td class="text-end">JMD <?= number_format($cost, 2) ?></td>
                        <td class="text-end">JMD <?= number_format((float)$a['salvage_value'], 2) ?></td>
                        <td class="text-end text-danger">JMD <?= number_format($accumulated, 2) ?></td>
                        <td class="text-end fw-bold">JMD <?= number_format($bookValue, 2) ?></td>
                        <td class="text-center" style="min-width:120px;">
                            <div class="progress" style="height:8px;" title="<?= $recorded ?>/<?= $total ?> periods recorded">
                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $pct ?>% depreciated</small>
                        </td>
                        <td class="text-center">
                            <a href="/inventory/depreciation/schedule.php?item_id=<?= $a['item_id'] ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-table"></i> Schedule
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
