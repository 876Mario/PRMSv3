<?php
$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';

/* ══════════════════════════════════════════════════════════════════════════════
   COLUMN REGISTRY
   All columns available on this page. 'locked' columns cannot be hidden.
   'sortable' columns produce clickable sortable headers.
   Update preferences_api.php and export.php if keys change.
   ══════════════════════════════════════════════════════════════════════════════ */
$allColumns = [
    ['key' => 'item_code',        'label' => 'Code',                'locked' => false, 'sortable' => true,  'sort_col' => 'i.item_code',         'default_visible' => true,  'default_order' => 1],
    ['key' => 'item_name',        'label' => 'Item Name',           'locked' => false, 'sortable' => true,  'sort_col' => 'i.item_name',          'default_visible' => true,  'default_order' => 2],
    ['key' => 'item_domain',      'label' => 'Domain',              'locked' => false, 'sortable' => true,  'sort_col' => 'i.item_domain',        'default_visible' => true,  'default_order' => 3],
    ['key' => 'category_name',    'label' => 'Category',            'locked' => false, 'sortable' => true,  'sort_col' => 'c.category_name',      'default_visible' => true,  'default_order' => 4],
    ['key' => 'manufacturer',     'label' => 'Manufacturer / Model','locked' => false, 'sortable' => true,  'sort_col' => 'i.manufacturer',       'default_visible' => true,  'default_order' => 5],
    ['key' => 'uom_code',         'label' => 'UOM',                 'locked' => false, 'sortable' => false, 'sort_col' => null,                   'default_visible' => true,  'default_order' => 6],
    ['key' => 'total_stock',      'label' => 'On Hand',             'locked' => false, 'sortable' => true,  'sort_col' => 'total_stock',          'default_visible' => true,  'default_order' => 7],
    ['key' => 'available_stock',  'label' => 'Available',           'locked' => false, 'sortable' => true,  'sort_col' => 'available_stock',      'default_visible' => true,  'default_order' => 8],
    ['key' => 'average_cost',     'label' => 'Avg Cost',            'locked' => false, 'sortable' => true,  'sort_col' => 'i.average_cost',       'default_visible' => true,  'default_order' => 9],
    ['key' => 'item_status',      'label' => 'Status',              'locked' => false, 'sortable' => true,  'sort_col' => 'i.item_status',        'default_visible' => true,  'default_order' => 10],
    ['key' => 'criticality_name', 'label' => 'Criticality',         'locked' => false, 'sortable' => true,  'sort_col' => 'cr.criticality_name',  'default_visible' => true,  'default_order' => 11],
    ['key' => 'actions',          'label' => 'Actions',             'locked' => true,  'sortable' => false, 'sort_col' => null,                   'default_visible' => true,  'default_order' => 12],
];

$columnsByKey  = array_column($allColumns, null, 'key');
$allowedKeys   = array_column($allColumns, 'key');
$defaultOrder  = array_column($allColumns, 'key');

/* Sort-column whitelist: column key → SQL expression */
$sortMap = [];
foreach ($allColumns as $col) {
    if ($col['sortable'] && $col['sort_col']) {
        $sortMap[$col['key']] = $col['sort_col'];
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOAD USER PREFERENCES
   ══════════════════════════════════════════════════════════════════════════════ */
$userId = (int) $_SESSION['user_id'];
$pageId = 'inventory_items_list';
$prefs  = [];

try {
    $prefStmt = $pdo->prepare("
        SELECT visible_columns, column_order, default_sort_column,
               default_sort_direction, page_size
        FROM user_table_preferences
        WHERE user_id = ? AND page_identifier = ?
        LIMIT 1
    ");
    $prefStmt->execute([$userId, $pageId]);
    $prefsRow = $prefStmt->fetch(PDO::FETCH_ASSOC);
    if ($prefsRow) {
        $prefs = $prefsRow;
    }
} catch (Throwable $e) {
    // Table may not exist yet (before migration is applied); fall through to defaults.
    $prefs = [];
}

/* ── Resolve column order ─────────────────────────────────────────────────── */
if (!empty($prefs['column_order'])) {
    $savedOrder = json_decode($prefs['column_order'], true);
    if (is_array($savedOrder)) {
        $validSaved  = array_values(array_filter($savedOrder, fn($k) => isset($columnsByKey[$k])));
        $missing     = array_values(array_diff($defaultOrder, $validSaved));
        $columnOrder = array_merge($validSaved, $missing);
    } else {
        $columnOrder = $defaultOrder;
    }
} else {
    $columnOrder = $defaultOrder;
}

/* ── Resolve visible keys ─────────────────────────────────────────────────── */
$lockedKeys     = array_column(array_filter($allColumns, fn($c) => $c['locked']), 'key');
$defaultVisible = $defaultOrder; // all visible by default

if (!empty($prefs['visible_columns'])) {
    $savedVisible = json_decode($prefs['visible_columns'], true);
    if (is_array($savedVisible)) {
        $filtered   = array_values(array_filter($savedVisible, fn($k) => isset($columnsByKey[$k])));
        $visibleKeys = array_unique(array_merge($filtered, $lockedKeys));
    } else {
        $visibleKeys = $defaultVisible;
    }
} else {
    $visibleKeys = $defaultVisible;
}

/* ── Build ordered, visible columns for rendering ─────────────────────────── */
$visibleColumns = [];
foreach ($columnOrder as $key) {
    if (in_array($key, $visibleKeys, true) && isset($columnsByKey[$key])) {
        $visibleColumns[] = $columnsByKey[$key];
    }
}
if (empty($visibleColumns)) {
    // Safety fallback: render all defaults
    $visibleColumns = $allColumns;
}

/* ══════════════════════════════════════════════════════════════════════════════
   SORT
   URL params override saved prefs; saved prefs override the built-in default.
   ══════════════════════════════════════════════════════════════════════════════ */
$sortCol = 'item_name';
$sortDir = 'ASC';

if (!empty($prefs['default_sort_column']) && isset($sortMap[$prefs['default_sort_column']])) {
    $sortCol = $prefs['default_sort_column'];
    $sortDir = strtoupper($prefs['default_sort_direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
}
if (!empty($_GET['sort_col']) && isset($sortMap[$_GET['sort_col']])) {
    $sortCol = $_GET['sort_col'];
}
if (!empty($_GET['sort_dir']) && in_array(strtoupper($_GET['sort_dir']), ['ASC', 'DESC'], true)) {
    $sortDir = strtoupper($_GET['sort_dir']);
}
$sortSQL = $sortMap[$sortCol] . ' ' . $sortDir;

/* ══════════════════════════════════════════════════════════════════════════════
   FILTERS
   ══════════════════════════════════════════════════════════════════════════════ */
$where  = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[]      = "(i.item_code LIKE :q OR i.item_name LIKE :q OR i.barcode LIKE :q OR i.part_number LIKE :q OR i.manufacturer LIKE :q OR i.model LIKE :q)";
    $params[':q'] = '%' . $_GET['q'] . '%';
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

/* ── Per-page size: URL → saved pref → default (20) ─────────────────────── */
$savedPageSize   = (int) ($prefs['page_size'] ?? 20);
extract(getPaginationParams($savedPageSize));

/* ══════════════════════════════════════════════════════════════════════════════
   QUERIES
   ══════════════════════════════════════════════════════════════════════════════ */
$sql = "
    SELECT i.*, c.category_name, u.uom_code, cr.criticality_name,
           COALESCE(SUM(s.quantity_on_hand), 0)   AS total_stock,
           COALESCE(SUM(s.quantity_available), 0) AS available_stock
    FROM inv_items i
    LEFT JOIN inv_categories c           ON i.category_id    = c.category_id
    LEFT JOIN inv_units_of_measure u     ON i.uom_id         = u.uom_id
    LEFT JOIN inv_criticality_classes cr ON i.criticality_id = cr.criticality_id
    LEFT JOIN inv_stock s                ON i.item_id = s.item_id AND s.stock_status = 'USABLE'
    $whereSQL
    GROUP BY i.item_id
    ORDER BY $sortSQL
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql  = "SELECT COUNT(DISTINCT i.item_id) FROM inv_items i $whereSQL";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$totalRows = (int) $countStmt->fetchColumn();

/* KPIs */
$kpi = $pdo->query("
    SELECT
        COUNT(*) AS total_items,
        SUM(CASE WHEN item_status = 'ACTIVE'      THEN 1 ELSE 0 END) AS active_items,
        SUM(CASE WHEN item_status = 'OBSOLETE'    THEN 1 ELSE 0 END) AS obsolete_items,
        SUM(CASE WHEN item_status = 'QUARANTINED' THEN 1 ELSE 0 END) AS quarantined_items
    FROM inv_items
")->fetch(PDO::FETCH_ASSOC);

$lowStock = $pdo->query("
    SELECT COUNT(*) FROM inv_items i
    WHERE i.item_status = 'ACTIVE' AND i.reorder_level > 0
    AND i.reorder_level >= (
        SELECT COALESCE(SUM(s.quantity_on_hand), 0) FROM inv_stock s
        WHERE s.item_id = i.item_id AND s.stock_status = 'USABLE'
    )
")->fetchColumn();

$categories  = getCategories($pdo);
$critClasses = getCriticalityClasses($pdo);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam"></i> Inventory Items</h2>
    <?php if (has_permission('manage_inventory_items')): ?>
    <a href="/inventory/items/add.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add Item
    </a>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-primary"><?= number_format((int)$kpi['total_items']) ?></div>
                <small class="text-muted">Total Items</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-success"><?= number_format((int)$kpi['active_items']) ?></div>
                <small class="text-muted">Active</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-warning"><?= number_format($lowStock) ?></div>
                <small class="text-muted">Low Stock</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-secondary"><?= number_format((int)$kpi['obsolete_items']) ?></div>
                <small class="text-muted">Obsolete</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="fs-4 fw-bold text-danger"><?= number_format((int)$kpi['quarantined_items']) ?></div>
                <small class="text-muted">Quarantined</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Code, name, barcode..."
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= ($_GET['category'] ?? '') == $cat['category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="ACTIVE"      <?= ($_GET['status'] ?? '') === 'ACTIVE'      ? 'selected' : '' ?>>Active</option>
                    <option value="BLOCKED"     <?= ($_GET['status'] ?? '') === 'BLOCKED'     ? 'selected' : '' ?>>Blocked</option>
                    <option value="OBSOLETE"    <?= ($_GET['status'] ?? '') === 'OBSOLETE'    ? 'selected' : '' ?>>Obsolete</option>
                    <option value="QUARANTINED" <?= ($_GET['status'] ?? '') === 'QUARANTINED' ? 'selected' : '' ?>>Quarantined</option>
                    <option value="DISPOSAL"    <?= ($_GET['status'] ?? '') === 'DISPOSAL'    ? 'selected' : '' ?>>Disposal</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Criticality</label>
                <select name="criticality" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($critClasses as $cc): ?>
                    <option value="<?= $cc['criticality_id'] ?>" <?= ($_GET['criticality'] ?? '') == $cc['criticality_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cc['criticality_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Domain</label>
                <select name="domain" class="form-select">
                    <option value="">All</option>
                    <option value="INVENTORY" <?= ($_GET['domain'] ?? '') === 'INVENTORY' ? 'selected' : '' ?>>Inventory</option>
                    <option value="ASSET"     <?= ($_GET['domain'] ?? '') === 'ASSET'     ? 'selected' : '' ?>>Assets</option>
                    <option value="BOTH"      <?= ($_GET['domain'] ?? '') === 'BOTH'      ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <?php
            /* Preserve sort params across filter submissions */
            if (!empty($_GET['sort_col'])): ?>
            <input type="hidden" name="sort_col" value="<?= htmlspecialchars($_GET['sort_col']) ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['sort_dir'])): ?>
            <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($_GET['sort_dir']) ?>">
            <?php endif; ?>
            <div class="col-md-1">
                <button type="submit" class="btn btn-dark w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="/inventory/items/list.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </div>
    </div>
</form>

<!-- Table toolbar -->
<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <!-- Left: results info + per-page -->
    <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-muted small">Rows:</label>
        <select id="perPageSelect" class="form-select form-select-sm" style="width:auto">
            <?php foreach ([10, 20, 50, 100, 200] as $sz): ?>
            <option value="<?= $sz ?>" <?= $perPage == $sz ? 'selected' : '' ?>><?= $sz ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- Right: action buttons -->
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#columnManagerModal">
            <i class="bi bi-layout-three-columns"></i> Manage Columns
        </button>
        <a href="<?= '/inventory/items/export.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q','category','status','criticality','domain','sort_col','sort_dir']))) ?>"
           class="btn btn-sm btn-outline-success" title="Export visible columns to CSV">
            <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
        </a>
    </div>
</div>

<!-- Items Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <?php
                        /* Build the sort URL for a column */
                        $sortBase = array_intersect_key($_GET, array_flip(['q','category','status','criticality','domain','per_page']));
                        foreach ($visibleColumns as $col):
                            $thAlign = '';
                            if (in_array($col['key'], ['total_stock','available_stock','average_cost'], true)) {
                                $thAlign = ' class="text-end"';
                            } elseif ($col['key'] === 'actions') {
                                $thAlign = ' class="text-center"';
                            }

                            if ($col['sortable']):
                                $nextDir  = ($sortCol === $col['key'] && $sortDir === 'ASC') ? 'DESC' : 'ASC';
                                $sortHref = '?' . http_build_query(array_merge($sortBase, ['sort_col' => $col['key'], 'sort_dir' => $nextDir, 'page' => 1]));
                                $isActive = ($sortCol === $col['key']);
                                $icon     = $isActive
                                    ? ($sortDir === 'ASC' ? 'bi-sort-up' : 'bi-sort-down')
                                    : 'bi-arrow-down-up';
                        ?>
                        <th<?= $thAlign ?>>
                            <a href="<?= $sortHref ?>" class="col-sort-link">
                                <?= htmlspecialchars($col['label']) ?>
                                <i class="bi <?= $icon ?> col-sort-icon<?= $isActive ? ' active' : '' ?>"></i>
                            </a>
                        </th>
                        <?php else: ?>
                        <th<?= $thAlign ?>><?= htmlspecialchars($col['label']) ?></th>
                        <?php endif; endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= count($visibleColumns) ?>" class="text-center text-muted py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                    <?php
                    $domainBadge  = ['INVENTORY' => 'primary', 'ASSET' => 'success', 'BOTH' => 'info'];
                    $domainLabel  = ['INVENTORY' => 'Inventory', 'ASSET' => 'Asset', 'BOTH' => 'Both'];
                    $statusColors = ['ACTIVE' => 'success', 'BLOCKED' => 'secondary', 'OBSOLETE' => 'dark',
                                     'QUARANTINED' => 'warning', 'DISPOSAL' => 'danger'];

                    foreach ($rows as $row):
                    ?>
                    <tr>
                        <?php foreach ($visibleColumns as $col): switch ($col['key']): ?>

                        <?php case 'item_code': ?>
                        <td><code><?= htmlspecialchars($row['item_code']) ?></code></td>
                        <?php break; ?>

                        <?php case 'item_name': ?>
                        <td>
                            <a href="/inventory/items/view.php?id=<?= $row['item_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($row['item_name']) ?>
                            </a>
                            <?php if ($row['serial_number_flag']): ?><span class="badge bg-info ms-1" title="Serialized">SN</span><?php endif; ?>
                            <?php if ($row['hazard_class_flag']): ?><span class="badge bg-danger ms-1" title="Hazardous">⚠️</span><?php endif; ?>
                            <?php if ($row['expiry_date_flag']): ?><span class="badge bg-warning text-dark ms-1" title="Expiry Tracked">EXP</span><?php endif; ?>
                        </td>
                        <?php break; ?>

                        <?php case 'item_domain': ?>
                        <td>
                            <?php $d = $row['item_domain'] ?? 'INVENTORY'; ?>
                            <span class="badge bg-<?= $domainBadge[$d] ?? 'secondary' ?>"><?= $domainLabel[$d] ?? htmlspecialchars($d) ?></span>
                        </td>
                        <?php break; ?>

                        <?php case 'category_name': ?>
                        <td><?= htmlspecialchars($row['category_name'] ?? '-') ?></td>
                        <?php break; ?>

                        <?php case 'manufacturer': ?>
                        <td>
                            <?php
                            $mfr   = htmlspecialchars($row['manufacturer'] ?? '');
                            $model = htmlspecialchars($row['model'] ?? '');
                            if ($mfr !== '' && $model !== '') {
                                echo $mfr . '<br><small class="text-muted">' . $model . '</small>';
                            } elseif ($mfr !== '') {
                                echo $mfr;
                            } elseif ($model !== '') {
                                echo '<span class="text-muted">' . $model . '</span>';
                            } else {
                                echo '<span class="text-muted">—</span>';
                            }
                            ?>
                        </td>
                        <?php break; ?>

                        <?php case 'uom_code': ?>
                        <td><?= htmlspecialchars($row['uom_code'] ?? '-') ?></td>
                        <?php break; ?>

                        <?php case 'total_stock': ?>
                        <td class="text-end"><?= number_format($row['total_stock'], 0) ?></td>
                        <?php break; ?>

                        <?php case 'available_stock': ?>
                        <td class="text-end <?= $row['available_stock'] <= ($row['reorder_level'] ?? 0) ? 'text-danger fw-bold' : '' ?>">
                            <?= number_format($row['available_stock'], 0) ?>
                        </td>
                        <?php break; ?>

                        <?php case 'average_cost': ?>
                        <td class="text-end">$<?= number_format($row['average_cost'], 2) ?></td>
                        <?php break; ?>

                        <?php case 'item_status': ?>
                        <td><span class="badge bg-<?= $statusColors[$row['item_status']] ?? 'secondary' ?>"><?= $row['item_status'] ?></span></td>
                        <?php break; ?>

                        <?php case 'criticality_name': ?>
                        <td><?= htmlspecialchars($row['criticality_name'] ?? '-') ?></td>
                        <?php break; ?>

                        <?php case 'actions': ?>
                        <td class="text-center">
                            <a href="/inventory/items/view.php?id=<?= $row['item_id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (has_permission('manage_inventory_items')): ?>
                            <a href="/inventory/items/edit.php?id=<?= $row['item_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="/inventory/items/duplicate.php?id=<?= $row['item_id'] ?>" class="btn btn-sm btn-outline-info" title="Duplicate">
                                <i class="bi bi-copy"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (has_permission('delete_inventory_items')): ?>
                            <form method="post" action="/inventory/items/delete.php" class="d-inline" onsubmit='return confirm(<?= json_encode("Delete {$row['item_name']}? This cannot be undone.") ?>);'>
                                <input type="hidden" name="item_id" value="<?= (int) $row['item_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <?php break; ?>

                        <?php endswitch; endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php
    /* Include sort params in pagination links */
    $paginationParams = $_GET;
    unset($paginationParams['page']);
    renderPagination($totalRows, $perPage, $page, $paginationParams);
    ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════════
     COLUMN MANAGER MODAL
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="columnManagerModal" tabindex="-1" aria-labelledby="columnManagerLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="columnManagerLabel">
                    <i class="bi bi-layout-three-columns me-2"></i>Manage Columns
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    <i class="bi bi-grip-vertical"></i> Drag to reorder &nbsp;·&nbsp;
                    <i class="bi bi-check2-square"></i> Check to show &nbsp;·&nbsp;
                    <i class="bi bi-lock"></i> Locked columns always visible
                </p>
                <ul id="colManagerList" class="list-group" style="max-height:420px;overflow-y:auto">
                    <?php
                    /* Render columns in current display order so the user
                       sees their current layout in the modal. */
                    foreach ($columnOrder as $key):
                        if (!isset($columnsByKey[$key])) continue;
                        $col      = $columnsByKey[$key];
                        $isLocked = $col['locked'];
                        $isVisible = in_array($key, $visibleKeys, true);
                    ?>
                    <li class="list-group-item d-flex align-items-center gap-2 py-2"
                        <?= !$isLocked ? 'draggable="true"' : '' ?>
                        data-col-key="<?= htmlspecialchars($key) ?>">
                        <?php if (!$isLocked): ?>
                        <i class="bi bi-grip-vertical col-drag-handle flex-shrink-0" title="Drag to reorder"></i>
                        <input type="checkbox" class="form-check-input col-visible-check flex-shrink-0"
                               id="col_<?= htmlspecialchars($key) ?>"
                               <?= $isVisible ? 'checked' : '' ?>>
                        <label class="form-check-label flex-grow-1" for="col_<?= htmlspecialchars($key) ?>">
                            <?= htmlspecialchars($col['label']) ?>
                        </label>
                        <?php else: ?>
                        <i class="bi bi-lock text-muted flex-shrink-0" title="Cannot be hidden"></i>
                        <input type="checkbox" class="form-check-input flex-shrink-0" checked disabled>
                        <label class="form-check-label flex-grow-1 text-muted">
                            <?= htmlspecialchars($col['label']) ?>
                            <span class="badge bg-secondary ms-1">Locked</span>
                        </label>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger btn-sm" id="resetLayoutBtn">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset to Default
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveLayoutBtn">
                        <i class="bi bi-floppy"></i> Save Layout
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999">
    <div id="prefToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="prefToastMsg"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── Current sort state (from server) ──────────────────────────────────── */
    const currentSortCol = <?= json_encode($sortCol) ?>;
    const currentSortDir = <?= json_encode($sortDir) ?>;
    const currentPerPage = <?= json_encode($perPage) ?>;
    const pageId         = <?= json_encode($pageId) ?>;

    /* ── Toast helper ───────────────────────────────────────────────────────── */
    function showToast(message, type) {
        const toast    = document.getElementById('prefToast');
        const toastMsg = document.getElementById('prefToastMsg');
        if (!toast || !toastMsg) return;

        // Reset classes
        toast.className = 'toast align-items-center border-0 text-bg-' + (type || 'success');
        toastMsg.textContent = message;

        const bsToast = bootstrap.Toast.getOrCreateInstance(toast, { delay: 3000 });
        bsToast.show();
    }

    /* ── Per-page selector ──────────────────────────────────────────────────── */
    const perPageSelect = document.getElementById('perPageSelect');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }

    /* ── Drag-and-drop column reordering ────────────────────────────────────── */
    const colList = document.getElementById('colManagerList');
    let dragSrc   = null;

    if (colList) {
        colList.querySelectorAll('[draggable="true"]').forEach(function (item) {
            item.addEventListener('dragstart', function (e) {
                dragSrc = this;
                this.style.opacity = '0.45';
                e.dataTransfer.effectAllowed = 'move';
            });

            item.addEventListener('dragend', function () {
                this.style.opacity = '';
                colList.querySelectorAll('.drag-over').forEach(function (el) {
                    el.classList.remove('drag-over');
                });
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                colList.querySelectorAll('.drag-over').forEach(function (el) {
                    el.classList.remove('drag-over');
                });
                this.classList.add('drag-over');
            });

            item.addEventListener('dragleave', function () {
                this.classList.remove('drag-over');
            });

            item.addEventListener('drop', function (e) {
                e.stopPropagation();
                if (dragSrc && dragSrc !== this) {
                    const items  = Array.from(colList.children);
                    const srcIdx = items.indexOf(dragSrc);
                    const tgtIdx = items.indexOf(this);
                    if (srcIdx < tgtIdx) {
                        colList.insertBefore(dragSrc, this.nextSibling);
                    } else {
                        colList.insertBefore(dragSrc, this);
                    }
                }
                this.classList.remove('drag-over');
            });
        });
    }

    /* ── Collect modal state ────────────────────────────────────────────────── */
    function collectPreferences() {
        const items         = Array.from(colList.querySelectorAll('[data-col-key]'));
        const columnOrder   = items.map(function (el) { return el.dataset.colKey; });
        const visibleColumns = items
            .filter(function (el) {
                const cb = el.querySelector('.col-visible-check');
                // Locked columns have no .col-visible-check (or it is disabled), always visible
                return !cb || cb.checked || cb.disabled;
            })
            .map(function (el) { return el.dataset.colKey; });

        return {
            action: 'save',
            page_id: pageId,
            column_order:           columnOrder,
            visible_columns:        visibleColumns,
            default_sort_column:    currentSortCol,
            default_sort_direction: currentSortDir,
            page_size:              currentPerPage
        };
    }

    /* ── Save layout ────────────────────────────────────────────────────────── */
    const saveBtn = document.getElementById('saveLayoutBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

            fetch('/inventory/items/preferences_api.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(collectPreferences())
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast('Layout saved successfully.', 'success');
                    setTimeout(function () { location.reload(); }, 900);
                } else {
                    showToast('Error: ' + (data.error || 'Could not save layout.'), 'danger');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="bi bi-floppy"></i> Save Layout';
                }
            })
            .catch(function () {
                showToast('Network error — layout not saved.', 'danger');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-floppy"></i> Save Layout';
            });
        });
    }

    /* ── Reset layout ───────────────────────────────────────────────────────── */
    const resetBtn = document.getElementById('resetLayoutBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (!confirm('Reset to default layout? All column customisations will be cleared.')) return;

            resetBtn.disabled = true;

            fetch('/inventory/items/preferences_api.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'reset', page_id: pageId })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast('Layout reset to default.', 'info');
                    setTimeout(function () {
                        /* Remove sort params so the default sort is applied */
                        const url = new URL(window.location.href);
                        url.searchParams.delete('sort_col');
                        url.searchParams.delete('sort_dir');
                        url.searchParams.delete('per_page');
                        url.searchParams.set('page', '1');
                        window.location.href = url.toString();
                    }, 900);
                } else {
                    showToast('Error: ' + (data.error || 'Could not reset layout.'), 'danger');
                    resetBtn.disabled = false;
                }
            })
            .catch(function () {
                showToast('Network error — layout not reset.', 'danger');
                resetBtn.disabled = false;
            });
        });
    }

}());
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
