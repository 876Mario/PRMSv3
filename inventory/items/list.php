<?php
$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';

/* Filters */
$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(i.item_code LIKE :q OR i.item_name LIKE :q OR i.barcode LIKE :q OR i.part_number LIKE :q OR i.manufacturer LIKE :q OR i.model LIKE :q)";
    $params[':q'] = '%' . $_GET['q'] . '%';
}
if (!empty($_GET['category'])) {
    $where[] = "i.category_id = :cat";
    $params[':cat'] = (int) $_GET['category'];
}
if (!empty($_GET['status'])) {
    $where[] = "i.item_status = :status";
    $params[':status'] = $_GET['status'];
}
if (!empty($_GET['criticality'])) {
    $where[] = "i.criticality_id = :crit";
    $params[':crit'] = (int) $_GET['criticality'];
}
if (!empty($_GET['domain'])) {
    $where[] = "i.item_domain = :domain";
    $params[':domain'] = $_GET['domain'];
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

extract(getPaginationParams(20));

$sql = "
    SELECT i.*, c.category_name, u.uom_code, cr.criticality_name,
           COALESCE(SUM(s.quantity_on_hand), 0) AS total_stock,
           COALESCE(SUM(s.quantity_available), 0) AS available_stock
    FROM inv_items i
    LEFT JOIN inv_categories c ON i.category_id = c.category_id
    LEFT JOIN inv_units_of_measure u ON i.uom_id = u.uom_id
    LEFT JOIN inv_criticality_classes cr ON i.criticality_id = cr.criticality_id
    LEFT JOIN inv_stock s ON i.item_id = s.item_id AND s.stock_status = 'USABLE'
    $whereSQL
    GROUP BY i.item_id
    ORDER BY i.item_name ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "SELECT COUNT(DISTINCT i.item_id) FROM inv_items i $whereSQL";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$totalRows = (int) $countStmt->fetchColumn();

/* KPIs */
$kpi = $pdo->query("
    SELECT
        COUNT(*) AS total_items,
        SUM(CASE WHEN item_status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_items,
        SUM(CASE WHEN item_status = 'OBSOLETE' THEN 1 ELSE 0 END) AS obsolete_items,
        SUM(CASE WHEN item_status = 'QUARANTINED' THEN 1 ELSE 0 END) AS quarantined_items
    FROM inv_items
")->fetch(PDO::FETCH_ASSOC);

// Low stock count
$lowStock = $pdo->query("
    SELECT COUNT(*) FROM inv_items i
    WHERE i.item_status = 'ACTIVE' AND i.reorder_level > 0
    AND i.reorder_level >= (
        SELECT COALESCE(SUM(s.quantity_on_hand), 0) FROM inv_stock s
        WHERE s.item_id = i.item_id AND s.stock_status = 'USABLE'
    )
")->fetchColumn();

$categories = getCategories($pdo);
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
<form method="GET" class="card border-0 shadow-sm mb-4">
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
                    <option value="ACTIVE" <?= ($_GET['status'] ?? '') === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                    <option value="BLOCKED" <?= ($_GET['status'] ?? '') === 'BLOCKED' ? 'selected' : '' ?>>Blocked</option>
                    <option value="OBSOLETE" <?= ($_GET['status'] ?? '') === 'OBSOLETE' ? 'selected' : '' ?>>Obsolete</option>
                    <option value="QUARANTINED" <?= ($_GET['status'] ?? '') === 'QUARANTINED' ? 'selected' : '' ?>>Quarantined</option>
                    <option value="DISPOSAL" <?= ($_GET['status'] ?? '') === 'DISPOSAL' ? 'selected' : '' ?>>Disposal</option>
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
            <div class="col-md-2">
                <label class="form-label">Domain</label>
                <select name="domain" class="form-select">
                    <option value="">All</option>
                    <option value="INVENTORY" <?= ($_GET['domain'] ?? '') === 'INVENTORY' ? 'selected' : '' ?>>Inventory / Stock / Consumable</option>
                    <option value="ASSET"     <?= ($_GET['domain'] ?? '') === 'ASSET'     ? 'selected' : '' ?>>Assets</option>
                    <option value="BOTH"      <?= ($_GET['domain'] ?? '') === 'BOTH'      ? 'selected' : '' ?>>Both</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-dark w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="/inventory/items/list.php" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </div>
    </div>
</form>

<!-- Items Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Code</th>
                        <th>Item Name</th>
                        <th>Domain</th>
                        <th>Category</th>
                        <th>Manufacturer / Model</th>
                        <th>UOM</th>
                        <th class="text-end">On Hand</th>
                        <th class="text-end">Available</th>
                        <th class="text-end">Avg Cost</th>
                        <th>Status</th>
                        <th>Criticality</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($row['item_code']) ?></code></td>
                        <td>
                            <a href="/inventory/items/view.php?id=<?= $row['item_id'] ?>" class="text-decoration-none fw-semibold">
                                <?= htmlspecialchars($row['item_name']) ?>
                            </a>
                            <?php if ($row['serial_number_flag']): ?><span class="badge bg-info ms-1" title="Serialized">SN</span><?php endif; ?>
                            <?php if ($row['hazard_class_flag']): ?><span class="badge bg-danger ms-1" title="Hazardous">⚠️</span><?php endif; ?>
                            <?php if ($row['expiry_date_flag']): ?><span class="badge bg-warning text-dark ms-1" title="Expiry Tracked">EXP</span><?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $domainBadge = ['INVENTORY' => 'primary', 'ASSET' => 'success', 'BOTH' => 'info'];
                            $domainLabel = ['INVENTORY' => 'Inventory', 'ASSET' => 'Asset', 'BOTH' => 'Both'];
                            $d = $row['item_domain'] ?? 'INVENTORY';
                            ?>
                            <span class="badge bg-<?= $domainBadge[$d] ?? 'secondary' ?>"><?= $domainLabel[$d] ?? htmlspecialchars($d) ?></span>
                        </td>
                        <td><?= htmlspecialchars($row['category_name'] ?? '-') ?></td>
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
                        <td><?= htmlspecialchars($row['uom_code'] ?? '-') ?></td>
                        <td class="text-end"><?= number_format($row['total_stock'], 0) ?></td>
                        <td class="text-end <?= $row['available_stock'] <= ($row['reorder_level'] ?? 0) ? 'text-danger fw-bold' : '' ?>">
                            <?= number_format($row['available_stock'], 0) ?>
                        </td>
                        <td class="text-end">$<?= number_format($row['average_cost'], 2) ?></td>
                        <td>
                            <?php
                            $statusColors = ['ACTIVE' => 'success', 'BLOCKED' => 'secondary', 'OBSOLETE' => 'dark',
                                             'QUARANTINED' => 'warning', 'DISPOSAL' => 'danger'];
                            $sc = $statusColors[$row['item_status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $sc ?>"><?= $row['item_status'] ?></span>
                        </td>
                        <td><?= htmlspecialchars($row['criticality_name'] ?? '-') ?></td>
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
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
