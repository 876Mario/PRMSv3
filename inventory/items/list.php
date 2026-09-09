<?php
$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/column_modules.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ColumnPreferenceService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ColumnManagerWidget.php';

/* ══════════════════════════════════════════════════════════════════════════════
   COLUMN PREFERENCE FRAMEWORK
   ══════════════════════════════════════════════════════════════════════════════ */
$columnState = (new ColumnPreferenceService($pdo))->resolveState(
    (int) $_SESSION['user_id'],
    getColumnPreferenceModuleConfig('inventory_items', $pdo)
);
$allColumns      = $columnState['columns'];
$columnsByKey    = $columnState['columns_by_key'];
$columnOrder     = $columnState['column_order'];
$visibleKeys     = $columnState['visible_keys'];
$visibleColumns  = $columnState['visible_columns'];
$sortMap         = $columnState['sort_map'];

/* ══════════════════════════════════════════════════════════════════════════════
   SORT
   URL params override saved prefs; saved prefs override the built-in default.
   ══════════════════════════════════════════════════════════════════════════════ */
$sortCol = $columnState['sort_column'];
$sortDir = $columnState['sort_direction'];
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
$savedPageSize   = $columnState['page_size'];
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
            <?php foreach ($columnState['page_size_options'] as $sz): ?>
            <option value="<?= $sz ?>" <?= $perPage == $sz ? 'selected' : '' ?>><?= $sz ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- Right: action buttons -->
    <div class="d-flex gap-2 flex-wrap">
        <?php ColumnManagerWidget::render([
            'module' => $columnState['module'],
            'title' => $columnState['title'],
            'description' => $columnState['description'],
            'button_label' => $columnState['button_label'],
            'button_class' => 'btn btn-sm btn-outline-secondary',
            'columns_by_key' => $columnsByKey,
            'column_order' => $columnOrder,
            'visible_keys' => $visibleKeys,
            'save_endpoint' => $columnState['save_endpoint'],
            'current_sort_column' => $sortCol,
            'current_sort_direction' => $sortDir,
            'current_page_size' => $perPage,
            'per_page_selector_id' => 'perPageSelect',
        ]); ?>
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

                    /**
                     * Renders a single <td> for the given column and row data.
                     * Using a closure keeps the helper scoped to this page without
                     * polluting the global namespace.
                     */
                    $renderCell = function (array $col, array $row) use ($domainBadge, $domainLabel, $statusColors): void {
                        switch ($col['key']) {
                            case 'item_code':
                                echo '<td><code>' . htmlspecialchars($row['item_code']) . '</code></td>';
                                break;

                            case 'item_name':
                                $flags = '';
                                if (!empty($row['serial_number_flag'])) $flags .= '<span class="badge bg-info ms-1" title="Serialized">SN</span>';
                                if (!empty($row['hazard_class_flag']))   $flags .= '<span class="badge bg-danger ms-1" title="Hazardous">⚠️</span>';
                                if (!empty($row['expiry_date_flag']))    $flags .= '<span class="badge bg-warning text-dark ms-1" title="Expiry Tracked">EXP</span>';
                                echo '<td>'
                                    . '<a href="/inventory/items/view.php?id=' . (int) $row['item_id'] . '" class="text-decoration-none fw-semibold">'
                                    . htmlspecialchars($row['item_name'])
                                    . '</a>' . $flags . '</td>';
                                break;

                            case 'item_domain':
                                $d = $row['item_domain'] ?? 'INVENTORY';
                                echo '<td><span class="badge bg-' . ($domainBadge[$d] ?? 'secondary') . '">'
                                    . ($domainLabel[$d] ?? htmlspecialchars($d))
                                    . '</span></td>';
                                break;

                            case 'category_name':
                                echo '<td>' . htmlspecialchars($row['category_name'] ?? '-') . '</td>';
                                break;

                            case 'manufacturer':
                                $mfr   = htmlspecialchars($row['manufacturer'] ?? '');
                                $model = htmlspecialchars($row['model'] ?? '');
                                if ($mfr !== '' && $model !== '') {
                                    $cell = $mfr . '<br><small class="text-muted">' . $model . '</small>';
                                } elseif ($mfr !== '') {
                                    $cell = $mfr;
                                } elseif ($model !== '') {
                                    $cell = '<span class="text-muted">' . $model . '</span>';
                                } else {
                                    $cell = '<span class="text-muted">—</span>';
                                }
                                echo '<td>' . $cell . '</td>';
                                break;

                            case 'uom_code':
                                echo '<td>' . htmlspecialchars($row['uom_code'] ?? '-') . '</td>';
                                break;

                            case 'total_stock':
                                echo '<td class="text-end">' . number_format((int) $row['total_stock'], 0) . '</td>';
                                break;

                            case 'available_stock':
                                $lowCls = $row['available_stock'] <= ($row['reorder_level'] ?? 0) ? ' text-danger fw-bold' : '';
                                echo '<td class="text-end' . $lowCls . '">' . number_format((int) $row['available_stock'], 0) . '</td>';
                                break;

                            case 'average_cost':
                                echo '<td class="text-end">$' . number_format((float) ($row['average_cost'] ?? 0), 2) . '</td>';
                                break;

                            case 'item_status':
                                $sc = $statusColors[$row['item_status']] ?? 'secondary';
                                echo '<td><span class="badge bg-' . $sc . '">' . htmlspecialchars($row['item_status']) . '</span></td>';
                                break;

                            case 'criticality_name':
                                echo '<td>' . htmlspecialchars($row['criticality_name'] ?? '-') . '</td>';
                                break;

                            case 'actions':
                                echo '<td class="text-center">';
                                echo '<a href="/inventory/items/view.php?id=' . (int) $row['item_id'] . '" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a> ';
                                if (has_permission('manage_inventory_items')) {
                                    echo '<a href="/inventory/items/edit.php?id=' . (int) $row['item_id'] . '" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a> ';
                                    echo '<a href="/inventory/items/duplicate.php?id=' . (int) $row['item_id'] . '" class="btn btn-sm btn-outline-info" title="Duplicate"><i class="bi bi-copy"></i></a> ';
                                }
                                if (has_permission('delete_inventory_items')) {
                                    $confirmMsg = 'Delete ' . addslashes($row['item_name']) . '? This cannot be undone.';
                                    echo '<form method="post" action="/inventory/items/delete.php" class="d-inline" onsubmit="return confirm(' . json_encode($confirmMsg) . ');">'
                                        . '<input type="hidden" name="item_id" value="' . (int) $row['item_id'] . '">'
                                        . '<button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>'
                                        . '</form>';
                                }
                                echo '</td>';
                                break;
                        }
                    };

                    foreach ($rows as $row):
                        echo '<tr>';
                        foreach ($visibleColumns as $col) {
                            $renderCell($col, $row);
                        }
                        echo '</tr>' . "\n";
                    endforeach;
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php
    $paginationParams = $_GET;
    unset($paginationParams['page']);
    renderPagination($totalRows, $perPage, $page, $paginationParams);
    ?>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
