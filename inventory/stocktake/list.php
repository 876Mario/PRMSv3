<?php
$REQUIRE_PERMISSION = 'conduct_stock_count';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$where = "1=1";
$params = [];
if ($search !== '') {
    $itemSearch = buildInventoryItemSearchExistsClause('sc', 'count_id', 'inv_stock_count_items', 'count_id');
    $where .= " AND (sc.count_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND sc.status = ?"; $params[] = $status; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_stock_counts sc LEFT JOIN users u ON sc.count_lead = u.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT sc.*, u.full_name AS lead_name, l.location_code,
           (SELECT COUNT(*) FROM inv_stock_count_items WHERE count_id = sc.count_id) AS line_count,
           (SELECT SUM(ABS(variance_quantity)) FROM inv_stock_count_items WHERE count_id = sc.count_id) AS total_variance
    FROM inv_stock_counts sc
    LEFT JOIN users u ON sc.count_lead = u.user_id
    LEFT JOIN inv_locations l ON sc.location_id = l.location_id
    WHERE $where
    ORDER BY sc.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN status='IN_PROGRESS' THEN 1 ELSE 0 END) AS inprog,
    SUM(CASE WHEN status='PLANNED' THEN 1 ELSE 0 END) AS planned
    FROM inv_stock_counts")->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard-data"></i> Stock Counts</h2>
    <a href="/inventory/stocktake/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New Count</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-info bg-opacity-10 text-center py-3"><h4><?= $kpi['planned'] ?></h4><small class="text-muted">Planned</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['inprog'] ?></h4><small class="text-muted">In Progress</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['completed'] ?></h4><small class="text-muted">Completed</small></div></div>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search count#, person, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['PLANNED','IN_PROGRESS','COMPLETED','CANCELLED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-dark w-100"><i class="bi bi-search"></i> Filter</button></div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Count #</th><th>Type</th><th>Location</th><th>Count Lead</th><th>Date</th><th>Items</th><th>Variance</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/stocktake/view.php?id=<?= $r['count_id'] ?>"><?= htmlspecialchars($r['count_number']) ?></a></td>
                        <td><span class="badge bg-info"><?= str_replace('_', ' ', $r['count_type']) ?></span></td>
                        <td><?= htmlspecialchars($r['location_code']) ?></td>
                        <td><?= htmlspecialchars($r['lead_name']) ?></td>
                        <td><?= $r['count_date'] ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td class="text-<?= ($r['total_variance'] ?? 0) > 0 ? 'danger' : 'success' ?>"><?= number_format($r['total_variance'] ?? 0, 2) ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'COMPLETED' => 'success', 'IN_PROGRESS' => 'warning', 'PLANNED' => 'info', default => 'secondary' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $r['status']) ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/stocktake/view.php?id=<?= $r['count_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
