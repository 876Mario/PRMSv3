<?php
/**
 * Inventory Items — CSV Export
 *
 * Generates a CSV download of the current filtered inventory items list,
 * respecting the requesting user's saved column preferences.
 *
 * Accepts the same GET filter parameters as list.php:
 *   q, category, status, criticality, domain, sort_col, sort_dir
 *
 * Security:
 *  - Requires active session with view_inventory permission.
 *  - Column keys resolved server-side from a whitelist; the client
 *    cannot inject arbitrary columns.
 *  - All SQL parameters use prepared-statement binding.
 */

$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

/* ── Column registry (must match list.php) ──────────────────────────────────── */
$allColumns = [
    ['key' => 'item_code',        'label' => 'Code',                'sortable' => true,  'sort_col' => 'i.item_code'],
    ['key' => 'item_name',        'label' => 'Item Name',           'sortable' => true,  'sort_col' => 'i.item_name'],
    ['key' => 'item_domain',      'label' => 'Domain',              'sortable' => true,  'sort_col' => 'i.item_domain'],
    ['key' => 'category_name',    'label' => 'Category',            'sortable' => true,  'sort_col' => 'c.category_name'],
    ['key' => 'manufacturer',     'label' => 'Manufacturer',        'sortable' => true,  'sort_col' => 'i.manufacturer'],
    ['key' => 'uom_code',         'label' => 'UOM',                 'sortable' => false, 'sort_col' => null],
    ['key' => 'total_stock',      'label' => 'On Hand',             'sortable' => true,  'sort_col' => 'total_stock'],
    ['key' => 'available_stock',  'label' => 'Available',           'sortable' => true,  'sort_col' => 'available_stock'],
    ['key' => 'average_cost',     'label' => 'Avg Cost',            'sortable' => true,  'sort_col' => 'i.average_cost'],
    ['key' => 'item_status',      'label' => 'Status',              'sortable' => true,  'sort_col' => 'i.item_status'],
    ['key' => 'criticality_name', 'label' => 'Criticality',         'sortable' => true,  'sort_col' => 'cr.criticality_name'],
    // 'actions' excluded from export
];

$allowedKeys = array_column($allColumns, 'key');
$columnsByKey = array_column($allColumns, null, 'key');

$sortMap = array_filter(
    array_combine(
        array_column($allColumns, 'key'),
        array_column($allColumns, 'sort_col')
    )
);

/* ── Load user column preferences ────────────────────────────────────────────── */
$userId     = (int) $_SESSION['user_id'];
$pageId     = 'inventory_items_list';
$prefStmt   = $pdo->prepare("SELECT visible_columns, column_order, default_sort_column, default_sort_direction FROM user_table_preferences WHERE user_id = ? AND page_identifier = ? LIMIT 1");
$prefStmt->execute([$userId, $pageId]);
$prefsRow   = $prefStmt->fetch(PDO::FETCH_ASSOC);

/* Resolve visible columns */
$defaultOrder   = array_column($allColumns, 'key');
$defaultVisible = $defaultOrder;

if ($prefsRow) {
    $savedOrder   = json_decode($prefsRow['column_order']    ?? 'null', true);
    $savedVisible = json_decode($prefsRow['visible_columns'] ?? 'null', true);

    if (is_array($savedOrder)) {
        $validSaved    = array_filter($savedOrder, fn($k) => in_array($k, $allowedKeys, true));
        $missing       = array_diff($defaultOrder, $validSaved);
        $columnOrder   = array_merge(array_values($validSaved), array_values($missing));
    } else {
        $columnOrder = $defaultOrder;
    }

    if (is_array($savedVisible)) {
        $visibleKeys = array_values(array_filter($savedVisible, fn($k) => in_array($k, $allowedKeys, true)));
    } else {
        $visibleKeys = $defaultVisible;
    }
} else {
    $columnOrder = $defaultOrder;
    $visibleKeys = $defaultVisible;
}

/* Build ordered visible columns (exclude 'actions') */
$exportColumns = [];
foreach ($columnOrder as $key) {
    if (in_array($key, $visibleKeys, true) && isset($columnsByKey[$key]) && $key !== 'actions') {
        $exportColumns[] = $columnsByKey[$key];
    }
}
if (empty($exportColumns)) {
    $exportColumns = $allColumns; // fall back to all if none configured
}

/* ── Resolve sort ─────────────────────────────────────────────────────────────── */
$sortCol = 'item_name';
$sortDir = 'ASC';

if (!empty($prefsRow['default_sort_column']) && isset($sortMap[$prefsRow['default_sort_column']])) {
    $sortCol = $prefsRow['default_sort_column'];
    $sortDir = strtoupper($prefsRow['default_sort_direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
}
if (!empty($_GET['sort_col']) && isset($sortMap[$_GET['sort_col']])) {
    $sortCol = $_GET['sort_col'];
}
if (!empty($_GET['sort_dir']) && in_array(strtoupper($_GET['sort_dir']), ['ASC', 'DESC'], true)) {
    $sortDir = strtoupper($_GET['sort_dir']);
}
$sortSQL = $sortMap[$sortCol] . ' ' . $sortDir;

/* ── Filters (mirrors list.php) ──────────────────────────────────────────────── */
$where  = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[]       = "(i.item_code LIKE :q OR i.item_name LIKE :q OR i.barcode LIKE :q OR i.part_number LIKE :q OR i.manufacturer LIKE :q OR i.model LIKE :q)";
    $params[':q']  = '%' . $_GET['q'] . '%';
}
if (!empty($_GET['category'])) {
    $where[]         = "i.category_id = :cat";
    $params[':cat']  = (int) $_GET['category'];
}
if (!empty($_GET['status'])) {
    $where[]            = "i.item_status = :status";
    $params[':status']  = $_GET['status'];
}
if (!empty($_GET['criticality'])) {
    $where[]          = "i.criticality_id = :crit";
    $params[':crit']  = (int) $_GET['criticality'];
}
if (!empty($_GET['domain'])) {
    $where[]            = "i.item_domain = :domain";
    $params[':domain']  = $_GET['domain'];
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ── Query (no LIMIT for export) ─────────────────────────────────────────────── */
$sql = "
    SELECT i.*, c.category_name, u.uom_code, cr.criticality_name,
           COALESCE(SUM(s.quantity_on_hand), 0)    AS total_stock,
           COALESCE(SUM(s.quantity_available), 0)  AS available_stock
    FROM inv_items i
    LEFT JOIN inv_categories c         ON i.category_id    = c.category_id
    LEFT JOIN inv_units_of_measure u   ON i.uom_id         = u.uom_id
    LEFT JOIN inv_criticality_classes cr ON i.criticality_id = cr.criticality_id
    LEFT JOIN inv_stock s              ON i.item_id = s.item_id AND s.stock_status = 'USABLE'
    $whereSQL
    GROUP BY i.item_id
    ORDER BY $sortSQL
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();

/* ── Output CSV ──────────────────────────────────────────────────────────────── */
$filename = 'inventory_items_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

// UTF-8 BOM so Excel opens with correct encoding
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

/* Header row */
fputcsv($out, array_column($exportColumns, 'label'));

/* Data rows */
$domainLabel = ['INVENTORY' => 'Inventory', 'ASSET' => 'Asset', 'BOTH' => 'Both'];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $csvRow = [];
    foreach ($exportColumns as $col) {
        switch ($col['key']) {
            case 'item_code':
                $csvRow[] = $row['item_code'] ?? '';
                break;
            case 'item_name':
                $name  = $row['item_name'] ?? '';
                $flags = [];
                if (!empty($row['serial_number_flag'])) $flags[] = '[SN]';
                if (!empty($row['hazard_class_flag']))   $flags[] = '[Hazardous]';
                if (!empty($row['expiry_date_flag']))    $flags[] = '[EXP]';
                $csvRow[] = $flags ? $name . ' ' . implode(' ', $flags) : $name;
                break;
            case 'item_domain':
                $csvRow[] = $domainLabel[$row['item_domain'] ?? ''] ?? ($row['item_domain'] ?? '');
                break;
            case 'category_name':
                $csvRow[] = $row['category_name'] ?? '';
                break;
            case 'manufacturer':
                $parts = array_filter([$row['manufacturer'] ?? '', $row['model'] ?? '']);
                $csvRow[] = implode(' / ', $parts);
                break;
            case 'uom_code':
                $csvRow[] = $row['uom_code'] ?? '';
                break;
            case 'total_stock':
                $csvRow[] = (int) $row['total_stock'];
                break;
            case 'available_stock':
                $csvRow[] = (int) $row['available_stock'];
                break;
            case 'average_cost':
                $csvRow[] = number_format((float) ($row['average_cost'] ?? 0), 2, '.', '');
                break;
            case 'item_status':
                $csvRow[] = $row['item_status'] ?? '';
                break;
            case 'criticality_name':
                $csvRow[] = $row['criticality_name'] ?? '';
                break;
            default:
                $csvRow[] = '';
        }
    }
    fputcsv($out, $csvRow);
}

fclose($out);
exit;
