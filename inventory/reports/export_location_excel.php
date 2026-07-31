<?php
/**
 * Excel (tab-separated) export for the Inventory Report by Location.
 * Permission: view_inventory_reports
 */
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Verify permission
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
if (!has_permission('view_inventory_reports')) {
    http_response_code(403);
    exit('Forbidden');
}

/* ── Schema checks ───────────────────────────────────────────────────────── */
function excelTableExists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"
    );
    $s->execute([$table]);
    return (int) $s->fetchColumn() > 0;
}

$adReady = excelTableExists($pdo, 'inv_asset_details');
$brReady = excelTableExists($pdo, 'branches');

/* ── Filters ─────────────────────────────────────────────────────────────── */
$locationId   = (int)  ($_GET['location_id']   ?? 0);
$categoryId   = (int)  ($_GET['category_id']   ?? 0);
$statusF      = trim($_GET['status']            ?? '');
$acquiredFrom = trim($_GET['acquired_from']     ?? '');
$acquiredTo   = trim($_GET['acquired_to']       ?? '');
$searchText   = trim($_GET['search']            ?? '');

/* ── WHERE ───────────────────────────────────────────────────────────────── */
$where  = [];
$params = [];

if ($locationId > 0) {
    $where[] = "sl.location_id = ?"; $params[] = $locationId;
    $where[] = "sl.quantity_on_hand > 0";
} elseif ($adReady) {
    // All locations: stock items OR non-disposed imported asset items
    $where[] = "(sl.quantity_on_hand > 0 OR (ad.asset_detail_id IS NOT NULL AND COALESCE(ad.is_disposed, 0) = 0))";
} else {
    $where[] = "sl.quantity_on_hand > 0";
}
if ($categoryId > 0) { $where[] = "i.category_id = ?";  $params[] = $categoryId; }
if ($statusF !== '') {
    $sc = ["sl.stock_status = ?"];
    $params[] = $statusF;
    if ($adReady) { $sc[] = "ad.asset_status = ?"; $params[] = $statusF; }
    $where[] = '(' . implode(' OR ', $sc) . ')';
}
if ($acquiredFrom !== '' && $adReady) { $where[] = "ad.acquired_date >= ?"; $params[] = $acquiredFrom; }
if ($acquiredTo   !== '' && $adReady) { $where[] = "ad.acquired_date <= ?"; $params[] = $acquiredTo; }
if ($searchText !== '') {
    $s = "%$searchText%";
    $searchClauses = [
        "i.item_name LIKE ?",
        "i.item_code LIKE ?",
        "i.description LIKE ?",
    ];
    $params[] = $s; $params[] = $s; $params[] = $s;
    if ($adReady) {
        $searchClauses[] = "ad.serial_number LIKE ?";
        $searchClauses[] = "ad.asset_code LIKE ?";
        $params[] = $s; $params[] = $s;
    }
    $snReadyExcel = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inv_serial_numbers'"
    )->fetchColumn();
    if ($snReadyExcel) {
        $searchClauses[] = "EXISTS (SELECT 1 FROM inv_serial_numbers sn WHERE sn.item_id = i.item_id AND sn.serial_number LIKE ?)";
        $params[] = $s;
    }
    $where[] = '(' . implode(' OR ', $searchClauses) . ')';
}

// Exclude BOS status items from active inventory reports (unless specifically filtered)
if ($statusF !== 'BOS' && $adReady) {
    $where[] = "(ad.asset_status IS NULL OR ad.asset_status != 'BOS')";
}

$whereClause = implode(' AND ', $where);

/* ── Unit-cost expression (falls back to asset detail values when no stock) ── */
$unitCostExpr = $adReady
    ? "COALESCE(sl.unit_cost, ad.balance_value, ad.purchase_cost, ad.bos_value, 0)"
    : "COALESCE(sl.unit_cost, 0)";

/* ── Joins/selects ───────────────────────────────────────────────────────── */
$adJoin = $adReady
    ? "LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id"
      . ($brReady ? " LEFT JOIN branches b ON ad.department_branch_id = b.branch_id" : "")
      . " LEFT JOIN users u ON ad.custodian_user_id = u.user_id"
    : "";

$adSelect = $adReady
    ? "ad.asset_code AS asset_tag, ad.serial_number AS asset_serial,
       ad.acquired_date, ad.asset_status,
       COALESCE(ad.custodian_name, u.full_name) AS officer_name,"
      . ($brReady ? " b.branch_name AS department_name," : " NULL AS department_name,")
    : "NULL AS asset_tag, NULL AS asset_serial,
       NULL AS acquired_date, NULL AS asset_status,
       NULL AS officer_name, NULL AS department_name,";

/* ── Query ───────────────────────────────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT
        i.item_code,
        i.item_name,
        i.description,
        c.category_name,
        l.location_code,
        CONCAT_WS(' > ',
            NULLIF(l.site_name, ''),
            NULLIF(l.building, ''),
            NULLIF(l.floor, ''),
            NULLIF(l.room_storage_area, '')
        ) AS location_path,
        COALESCE(sl.quantity_on_hand, 1) AS quantity_on_hand,
        $unitCostExpr AS unit_cost,
        (COALESCE(sl.quantity_on_hand, 1) * $unitCostExpr) AS total_value,
        sl.stock_status,
        $adSelect
        i.item_status
    FROM inv_items i
    LEFT JOIN inv_stock sl ON sl.item_id = i.item_id
    LEFT JOIN inv_categories c ON i.category_id = c.category_id
    LEFT JOIN inv_locations l ON l.location_id = sl.location_id
    $adJoin
    WHERE $whereClause
    ORDER BY (l.location_code IS NULL), l.location_code, i.item_name
    LIMIT 10000
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Resolve location label ──────────────────────────────────────────────── */
$locationLabel = 'All Locations';
if ($locationId > 0) {
    $ls = $pdo->prepare("SELECT location_code, site_name FROM inv_locations WHERE location_id = ?");
    $ls->execute([$locationId]);
    $ld = $ls->fetch(PDO::FETCH_ASSOC);
    if ($ld) {
        $locationLabel = $ld['location_code'] . ($ld['site_name'] ? ' — ' . $ld['site_name'] : '');
    }
}

/* ── Output ──────────────────────────────────────────────────────────────── */
$filename = 'location_inventory_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for UTF-8 Excel
echo "\xEF\xBB\xBF";

// Report header
echo "Inventory Report by Location\t\t\t\t\t\t\t\t\t\t\t\t\t\n";
echo "Location:\t" . $locationLabel . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
if ($statusF !== '') {
    echo "Status Filter:\t" . $statusF . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
}
if ($searchText !== '') {
    echo "Search:\t" . $searchText . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
}
if ($acquiredFrom !== '') {
    echo "Acquired From:\t" . $acquiredFrom . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
}
if ($acquiredTo !== '') {
    echo "Acquired To:\t" . $acquiredTo . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
}
echo "Report Date:\t" . date('d M Y') . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
echo "Generated By:\t" . ($_SESSION['full_name'] ?? 'System') . "\t\t\t\t\t\t\t\t\t\t\t\t\n";
echo "\n";

// Column headers
$headers = [
    '#', 'Item Code', 'Item / Asset Name', 'Description',
    'Serial Number', 'Asset Tag', 'Category',
    'Quantity', 'Unit Cost', 'Total Value',
    'Department', 'Officer / User',
    'Location Code', 'Location Path',
    'Status', 'Acquisition Date',
];
echo implode("\t", $headers) . "\n";

// Data rows
$grandTotal = 0.0;
$grandQty   = 0.0;
$n = 1;
foreach ($rows as $r) {
    $displayStatus = $r['asset_status'] ?: $r['stock_status'];
    $grandTotal   += (float) $r['total_value'];
    $grandQty     += (float) $r['quantity_on_hand'];

    $cols = [
        $n++,
        $r['item_code'],
        $r['item_name'],
        str_replace(["\r\n", "\r", "\n", "\t"], ' ', $r['description'] ?? ''),
        $r['asset_serial']    ?? '',
        $r['asset_tag']       ?? '',
        $r['category_name']   ?? '',
        number_format((float) $r['quantity_on_hand'], 4, '.', ''),
        number_format((float) $r['unit_cost'],        2, '.', ''),
        number_format((float) $r['total_value'],      2, '.', ''),
        $r['department_name'] ?? '',
        $r['officer_name']    ?? '',
        $r['location_code']   ?? '',
        $r['location_path']   ?? '',
        $displayStatus        ?? '',
        $r['acquired_date']   ?? '',
    ];
    echo implode("\t", $cols) . "\n";
}

// Grand total row
echo "\n";
echo "\t\t\t\t\t\tGrand Total\t"
    . number_format($grandQty, 4, '.', '') . "\t\t"
    . number_format($grandTotal, 2, '.', '') . "\t\t\t\t\t\t\n";

exit;
