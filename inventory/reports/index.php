<?php
$REQUIRE_PERMISSION = 'view_inventory_reports';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

// Report definitions grouped by section
$reportGroups = [
    'Stock Position & Valuation' => [
        ['icon' => 'bi-currency-dollar',    'color' => 'primary',   'title' => 'Stock Valuation',             'desc' => 'Current inventory value by item, location, and category (IPSAS 12).', 'url' => '/inventory/reports/stock_valuation.php'],
        ['icon' => 'bi-geo-alt-fill',       'color' => 'success',   'title' => 'Inventory by Location',       'desc' => 'Location-specific inventory report with assets, quantities, values, custodians, and acquisition details. Supports PDF, Excel, and print.', 'url' => '/inventory/reports/location_inventory.php'],
       ['icon' => 'bi-building',           'color' => 'info',      'title' => 'Asset Register',              'desc' => 'Consolidated register of asset-designated items with quantity, value, and serialization status.', 'url' => '/inventory/reports/asset_register.php'],
       ['icon' => 'bi-arrow-left-right',   'color' => 'secondary', 'title' => 'Asset Movement Register',     'desc' => 'Serialized asset movement history by date range, lifecycle status, and location.', 'url' => '/inventory/reports/asset_movement_register.php'],
        ['icon' => 'bi-exclamation-triangle','color' => 'warning',  'title' => 'Reorder Report',              'desc' => 'Items at or below reorder point, sorted by criticality.', 'url' => '/inventory/reports/reorder_report.php'],
        ['icon' => 'bi-archive',            'color' => 'secondary', 'title' => 'Obsolete Stock',              'desc' => 'Items flagged as obsolete with residual on-hand quantity.', 'url' => '/inventory/reports/obsolete_stock.php'],
        ['icon' => 'bi-hourglass-split',    'color' => 'warning',   'title' => 'Slow-Moving / Dead Stock',    'desc' => 'Items with no outbound movement in a configurable period.', 'url' => '/inventory/reports/slow_moving_stock.php'],
        ['icon' => 'bi-shield-fill-check',  'color' => 'success',   'title' => 'Emergency & Contingency Stock','desc' => 'Emergency reserve items vs. safety stock levels.', 'url' => '/inventory/reports/emergency_stock.php'],
    ],
    'Expiry & Quality' => [
        ['icon' => 'bi-calendar-x',         'color' => 'danger',    'title' => 'Expiry Report',               'desc' => 'Items expiring within configurable time windows.', 'url' => '/inventory/reports/expiry_report.php'],
    ],
    'Transaction Registers' => [
        ['icon' => 'bi-box-seam',           'color' => 'success',   'title' => 'Goods Received Register',     'desc' => 'All GRNs with supplier, PO reference, inspection result, and value.', 'url' => '/inventory/reports/goods_received_register.php'],
        ['icon' => 'bi-box-arrow-right',    'color' => 'info',      'title' => 'Issue Register',              'desc' => 'All stock issues by recipient, department, project, and cost.', 'url' => '/inventory/reports/issue_register.php'],
        ['icon' => 'bi-arrow-left-right',   'color' => 'primary',   'title' => 'Transfer Register',           'desc' => 'Internal, inter-branch, and inter-MDA transfers with FS approval status.', 'url' => '/inventory/reports/transfer_register.php'],
        ['icon' => 'bi-trash3',             'color' => 'danger',    'title' => 'Disposal Register',           'desc' => 'Disposal records with method, write-off value, and proceeds.', 'url' => '/inventory/reports/disposal_register.php'],
        ['icon' => 'bi-gift',               'color' => 'info',      'title' => 'Donation / Non-Exchange Register','desc' => 'Non-exchange inventory received as donations or grants (IPSAS 12).', 'url' => '/inventory/reports/donation_register.php'],
        ['icon' => 'bi-clock-history',      'color' => 'secondary', 'title' => 'Transaction History',         'desc' => 'Complete stock ledger with all movement types and filters.', 'url' => '/inventory/reports/transaction_history.php'],
    ],
    'Financial & Accounting' => [
        ['icon' => 'bi-receipt-cutoff',     'color' => 'danger',    'title' => 'Inventory Expense Report',    'desc' => 'Cost of goods issued/consumed — IPSAS 12 §34 expense recognition.', 'url' => '/inventory/reports/inventory_expense.php'],
        ['icon' => 'bi-arrow-down-circle',  'color' => 'warning',   'title' => 'Write-Down & Reversal Report','desc' => 'Write-downs to NRV and reversals with approval details (IPSAS 12).', 'url' => '/inventory/reports/write_down_report.php'],
        ['icon' => 'bi-exclamation-diamond','color' => 'danger',    'title' => 'Shrinkage & Loss Report',     'desc' => 'Losses from adjustments, damage, theft, and incidents.', 'url' => '/inventory/reports/shrinkage_loss.php'],
    ],
    'Traceability & Quality' => [
        ['icon' => 'bi-upc-scan',           'color' => 'info',      'title' => 'Batch / Serial Traceability', 'desc' => 'Full movement history by batch/lot or serial number for recall support.', 'url' => '/inventory/reports/traceability_report.php'],
        ['icon' => 'bi-clipboard-data',     'color' => 'success',   'title' => 'Stock Count Variances',       'desc' => 'Completed stock counts with count-vs-system variance analysis.', 'url' => '/inventory/stocktake/list.php?status=COMPLETED'],
    ],
    'Supplier & Procurement' => [
        ['icon' => 'bi-truck',              'color' => 'primary',   'title' => 'Supplier Performance',        'desc' => 'GRN acceptance rates, rejection rates, and short-supply analysis by supplier.', 'url' => '/inventory/reports/supplier_performance.php'],
    ],
    'Governance & Audit' => [
        ['icon' => 'bi-stopwatch',          'color' => 'secondary', 'title' => 'Approval Turnaround',         'desc' => 'Time taken for approvals across requisitions, transfers, adjustments, and disposals.', 'url' => '/inventory/reports/approval_turnaround.php'],
        ['icon' => 'bi-person-lines-fill',  'color' => 'dark',      'title' => 'User Activity Report',        'desc' => 'All inventory transactions by user, with value and type summary.', 'url' => '/inventory/reports/user_activity.php'],
        ['icon' => 'bi-shield-exclamation', 'color' => 'secondary', 'title' => 'Audit Exceptions',            'desc' => 'Inventory audit trail events for internal and external audit review.', 'url' => '/inventory/reports/audit_exceptions.php'],
    ],
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bar-chart"></i> Inventory Reports</h2>
    <a href="/inventory/dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
</div>

<?php foreach ($reportGroups as $groupName => $reports): ?>
<h5 class="mt-4 mb-2 text-muted"><i class="bi bi-folder2-open"></i> <?= htmlspecialchars($groupName) ?></h5>
<div class="row g-3 mb-2">
    <?php foreach ($reports as $rpt): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi <?= $rpt['icon'] ?> fs-2 text-<?= $rpt['color'] ?> flex-shrink-0"></i>
                    <div>
                        <h6 class="mb-1"><?= htmlspecialchars($rpt['title']) ?></h6>
                        <p class="text-muted small mb-2"><?= htmlspecialchars($rpt['desc']) ?></p>
                        <a href="<?= htmlspecialchars($rpt['url']) ?>" class="btn btn-sm btn-dark">View Report</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
