<?php
$REQUIRE_PERMISSION = 'view_inventory_reports';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';

/* ── Schema readiness guards ─────────────────────────────────────────────── */
function locRptColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function locRptTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$assetDetailsReady = locRptTableExists($pdo, 'inv_asset_details');
$branchesReady     = locRptTableExists($pdo, 'branches');
$serialTableReady  = locRptTableExists($pdo, 'inv_serial_numbers');

/* ── Filter inputs ───────────────────────────────────────────────────────── */
$locationId  = (int) ($_GET['location_id']  ?? 0);
$categoryId  = (int) ($_GET['category_id']  ?? 0);
$statusF     = trim($_GET['status']          ?? '');
$acquiredFrom = trim($_GET['acquired_from']  ?? '');
$acquiredTo   = trim($_GET['acquired_to']    ?? '');

/* ── Build WHERE ─────────────────────────────────────────────────────────── */
$where  = ["sl.quantity_on_hand > 0"];
$params = [];

if ($locationId > 0) {
    $where[]  = "sl.location_id = ?";
    $params[] = $locationId;
}
if ($categoryId > 0) {
    $where[]  = "i.category_id = ?";
    $params[] = $categoryId;
}
if ($statusF !== '') {
    $statusClauses = ["sl.stock_status = ?"];
    $params[]      = $statusF;
    if ($assetDetailsReady) {
        $statusClauses[] = "ad.asset_status = ?";
        $params[]        = $statusF;
    }
    $where[] = '(' . implode(' OR ', $statusClauses) . ')';
}
if ($acquiredFrom !== '' && $assetDetailsReady) {
    $where[]  = "ad.acquired_date >= ?";
    $params[] = $acquiredFrom;
}
if ($acquiredTo !== '' && $assetDetailsReady) {
    $where[]  = "ad.acquired_date <= ?";
    $params[] = $acquiredTo;
}

$whereClause = implode(' AND ', $where);

/* ── Conditional selects / joins ─────────────────────────────────────────── */
$adJoin = $assetDetailsReady
    ? "LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id"
      . ($branchesReady ? " LEFT JOIN branches b ON ad.department_branch_id = b.branch_id" : "")
      . " LEFT JOIN users u ON ad.custodian_user_id = u.user_id"
    : "";

$adSelect = $assetDetailsReady
    ? "ad.asset_code AS asset_tag,
       ad.serial_number AS asset_serial,
       ad.acquired_date,
       ad.asset_status,
       COALESCE(ad.custodian_name, u.full_name) AS officer_name,"
      . ($branchesReady ? " b.branch_name AS department_name," : " NULL AS department_name,")
    : "NULL AS asset_tag,
       NULL AS asset_serial,
       NULL AS acquired_date,
       NULL AS asset_status,
       NULL AS officer_name,
       NULL AS department_name,";

/* ── Count & totals ──────────────────────────────────────────────────────── */
extract(getPaginationParams(100));

$countSql = "
    SELECT COUNT(*)
    FROM inv_stock sl
    JOIN inv_items i ON sl.item_id = i.item_id
    $adJoin
    LEFT JOIN inv_locations l ON sl.location_id = l.location_id
    WHERE $whereClause
";
$totalRows = (int) $pdo->prepare($countSql)->execute($params) ? $pdo->prepare($countSql)->fetchColumn() : 0;
$cntStmt   = $pdo->prepare($countSql);
$cntStmt->execute($params);
$totalRows = (int) $cntStmt->fetchColumn();

$totalsStmt = $pdo->prepare("
    SELECT COALESCE(SUM(sl.quantity_on_hand * sl.unit_cost), 0) AS grand_total,
           COALESCE(SUM(sl.quantity_on_hand), 0) AS grand_qty
    FROM inv_stock sl
    JOIN inv_items i ON sl.item_id = i.item_id
    $adJoin
    LEFT JOIN inv_locations l ON sl.location_id = l.location_id
    WHERE $whereClause
");
$totalsStmt->execute($params);
$totalsRow  = $totalsStmt->fetch(PDO::FETCH_ASSOC);
$grandTotal = (float) ($totalsRow['grand_total'] ?? 0);
$grandQty   = (float) ($totalsRow['grand_qty']   ?? 0);

/* ── Main query ──────────────────────────────────────────────────────────── */
$dataStmt = $pdo->prepare("
    SELECT
        i.item_code,
        i.item_name,
        i.description,
        c.category_name,
        l.location_id,
        l.location_code,
        CONCAT_WS(' › ',
            NULLIF(l.site_name, ''),
            NULLIF(l.building, ''),
            NULLIF(l.floor, ''),
            NULLIF(l.room_storage_area, '')
        ) AS location_path,
        sl.quantity_on_hand,
        sl.unit_cost,
        (sl.quantity_on_hand * sl.unit_cost) AS total_value,
        sl.stock_status,
        $adSelect
        i.item_status
    FROM inv_stock sl
    JOIN inv_items i ON sl.item_id = i.item_id
    LEFT JOIN inv_categories c ON i.category_id = c.category_id
    LEFT JOIN inv_locations l ON sl.location_id = l.location_id
    $adJoin
    WHERE $whereClause
    ORDER BY l.location_code, i.item_name
    LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Filter option lists ─────────────────────────────────────────────────── */
$locations  = $pdo->query(
    "SELECT location_id, location_code, site_name, building FROM inv_locations
     WHERE is_active = 1 ORDER BY location_code"
)->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query(
    "SELECT category_id, category_name FROM inv_categories ORDER BY category_name"
)->fetchAll(PDO::FETCH_ASSOC);

$statuses = ['USABLE', 'QUARANTINE', 'EXPIRED', 'DAMAGED', 'DISPOSAL'];
if ($assetDetailsReady) {
    $extraStatuses = $pdo->query(
        "SELECT DISTINCT asset_status FROM inv_asset_details WHERE asset_status IS NOT NULL ORDER BY asset_status"
    )->fetchAll(PDO::FETCH_COLUMN);
    $statuses = array_unique(array_merge($statuses, $extraStatuses));
    sort($statuses);
}

/* ── Selected location label ─────────────────────────────────────────────── */
$selectedLocation = null;
if ($locationId > 0) {
    foreach ($locations as $loc) {
        if ((int) $loc['location_id'] === $locationId) {
            $selectedLocation = $loc;
            break;
        }
    }
}

/* ── Export URLs ─────────────────────────────────────────────────────────── */
$exportParams = http_build_query(array_filter([
    'location_id'   => $locationId  ?: null,
    'category_id'   => $categoryId  ?: null,
    'status'        => $statusF      !== '' ? $statusF : null,
    'acquired_from' => $acquiredFrom !== '' ? $acquiredFrom : null,
    'acquired_to'   => $acquiredTo   !== '' ? $acquiredTo   : null,
]));

$pdfUrl   = '/inventory/reports/export_pdf.php?report=location_inventory&' . $exportParams;
$excelUrl = '/inventory/reports/export_location_excel.php?' . $exportParams;

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    .table thead { background-color: #0b5e2b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .report-header { background: linear-gradient(90deg,#0b5e2b,#c9a227) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h2><i class="bi bi-geo-alt"></i> Inventory Report by Location</h2>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" class="btn btn-outline-danger" target="_blank">
            <i class="bi bi-file-pdf"></i> PDF
        </a>
        <a href="<?= htmlspecialchars($excelUrl) ?>" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel"></i> Excel
        </a>
        <button class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <a href="/inventory/reports/" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Reports
        </a>
    </div>
</div>

<!-- Filter Form -->
<form class="row g-2 mb-4 no-print" method="get">
    <div class="col-md-3">
        <label class="form-label small text-muted">Location</label>
        <select name="location_id" class="form-select">
            <option value="">All Locations</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['location_id'] ?>"
                <?= $locationId == $loc['location_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($loc['location_code']) ?>
                <?php if ($loc['site_name']): ?>
                — <?= htmlspecialchars($loc['site_name']) ?>
                <?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted">Category</label>
        <select name="category_id" class="form-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['category_id'] ?>"
                <?= $categoryId == $cat['category_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['category_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"
                <?= $statusF === $s ? 'selected' : '' ?>>
                <?= htmlspecialchars($s) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted">Acq. From</label>
        <input type="date" name="acquired_from" class="form-control"
               value="<?= htmlspecialchars($acquiredFrom) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted">Acq. To</label>
        <input type="date" name="acquired_to" class="form-control"
               value="<?= htmlspecialchars($acquiredTo) ?>">
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button class="btn btn-dark w-100"><i class="bi bi-funnel"></i> Filter</button>
    </div>
</form>

<!-- Report Header (visible on screen and in print) -->
<div class="report-header p-3 mb-3 text-white rounded"
     style="background:linear-gradient(90deg,#0b5e2b,#c9a227);">
    <div class="row">
        <div class="col">
            <div class="fw-bold fs-5">Government Chemist — PRMS</div>
            <div class="small opacity-75">Procurement &amp; Resource Management System</div>
            <div class="mt-1 fs-6">
                Inventory Report by Location:
                <strong>
                    <?= $selectedLocation
                        ? htmlspecialchars($selectedLocation['location_code'])
                        : 'All Locations' ?>
                </strong>
            </div>
        </div>
        <div class="col-auto text-end small opacity-90">
            <div>Report Date: <?= date('d M Y') ?></div>
            <div>Generated By: <?= htmlspecialchars($_SESSION['full_name'] ?? 'System') ?></div>
            <?php if ($categoryId > 0): ?>
            <div>Category Filter Applied</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Summary -->
<div class="alert alert-primary d-flex gap-4 mb-3 no-print">
    <div><strong>Total Items:</strong> <?= number_format($totalRows) ?></div>
    <div><strong>Total Quantity:</strong> <?= number_format($grandQty, 2) ?></div>
    <div><strong>Total Inventory Value:</strong> $<?= number_format($grandTotal, 2) ?></div>
</div>

<!-- Report Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Item / Asset Name</th>
                        <th>Description</th>
                        <th>Serial No.</th>
                        <th>Asset Tag</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Total Value</th>
                        <th>Department</th>
                        <th>Officer / User</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Acq. Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="14" class="text-center text-muted py-4">
                            No inventory records found for the selected filters.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php
                    $currentLocation = null;
                    $locSubQty   = 0;
                    $locSubTotal = 0;
                    $rowNum = $offset + 1;
                    foreach ($rows as $r):
                        /* ── Location subtotal header row ── */
                        if ($locationId === 0 && $r['location_code'] !== $currentLocation):
                            if ($currentLocation !== null):
                    ?>
                    <tr class="table-secondary fw-bold">
                        <td colspan="6" class="text-end">
                            Subtotal — <?= htmlspecialchars($currentLocation) ?>:
                        </td>
                        <td class="text-end"><?= number_format($locSubQty, 2) ?></td>
                        <td></td>
                        <td class="text-end">$<?= number_format($locSubTotal, 2) ?></td>
                        <td colspan="5"></td>
                    </tr>
                    <?php
                            endif;
                            $currentLocation = $r['location_code'];
                            $locSubQty   = 0;
                            $locSubTotal = 0;
                        endif;
                        $locSubQty   += (float) $r['quantity_on_hand'];
                        $locSubTotal += (float) $r['total_value'];
                        $displayStatus = $r['asset_status'] ?: $r['stock_status'];
                        $statusBadge   = match($displayStatus) {
                            'USABLE', 'In Use', 'In Service' => 'success',
                            'QUARANTINE', 'DAMAGED'          => 'warning',
                            'EXPIRED', 'DISPOSED'            => 'danger',
                            'DISPOSAL'                       => 'danger',
                            default                          => 'secondary',
                        };
                    ?>
                    <tr>
                        <td class="text-muted"><?= $rowNum++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($r['item_name']) ?></strong>
                            <div class="text-muted" style="font-size:0.75rem;">
                                <?= htmlspecialchars($r['item_code']) ?>
                            </div>
                        </td>
                        <td class="text-muted" style="max-width:150px;word-break:break-word;">
                            <?= htmlspecialchars(mb_strimwidth($r['description'] ?? '', 0, 80, '…')) ?>
                        </td>
                        <td><code><?= htmlspecialchars($r['asset_serial'] ?? '-') ?></code></td>
                        <td><?= htmlspecialchars($r['asset_tag'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['category_name'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format((float)$r['quantity_on_hand'], 2) ?></td>
                        <td class="text-end">$<?= number_format((float)$r['unit_cost'], 2) ?></td>
                        <td class="text-end fw-bold">$<?= number_format((float)$r['total_value'], 2) ?></td>
                        <td><?= htmlspecialchars($r['department_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['officer_name'] ?? '-') ?></td>
                        <td>
                            <span title="<?= htmlspecialchars($r['location_path'] ?? '') ?>">
                                <code><?= htmlspecialchars($r['location_code'] ?? '-') ?></code>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $statusBadge ?>">
                                <?= htmlspecialchars($displayStatus ?: '-') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r['acquired_date'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if ($locationId === 0 && $currentLocation !== null): ?>
                    <tr class="table-secondary fw-bold">
                        <td colspan="6" class="text-end">
                            Subtotal — <?= htmlspecialchars($currentLocation) ?>:
                        </td>
                        <td class="text-end"><?= number_format($locSubQty, 2) ?></td>
                        <td></td>
                        <td class="text-end">$<?= number_format($locSubTotal, 2) ?></td>
                        <td colspan="5"></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="6" class="text-end fw-bold">Grand Total:</td>
                        <td class="text-end fw-bold"><?= number_format($grandQty, 2) ?></td>
                        <td></td>
                        <td class="text-end fw-bold">$<?= number_format($grandTotal, 2) ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="mt-3 no-print">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
