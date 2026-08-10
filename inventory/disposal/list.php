<?php
$REQUIRE_PERMISSION = 'dispose_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$where = "1=1";
$params = [];
if ($search) {
    $itemSearch = buildInventoryItemSearchExistsClause('d', 'disposal_id', 'inv_disposal_items', 'disposal_id');
    $where .= " AND (d.disposal_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND d.status = ?"; $params[] = $status; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_disposals d LEFT JOIN users u ON d.requested_by = u.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT d.*, u.full_name AS requester_name,
           (SELECT COUNT(*) FROM inv_disposal_items WHERE disposal_id = d.disposal_id) AS line_count,
           (SELECT SUM(estimated_value) FROM inv_disposal_items WHERE disposal_id = d.disposal_id) AS total_value
    FROM inv_disposals d
    LEFT JOIN users u ON d.requested_by = u.user_id
    WHERE $where
    ORDER BY d.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN status='PENDING_SURVEY' THEN 1 ELSE 0 END) AS survey,
    SUM(CASE WHEN status='PENDING_APPROVAL' THEN 1 ELSE 0 END) AS pending
    FROM inv_disposals")->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-trash3"></i> Disposal / Write-Off</h2>
    <a href="/inventory/disposal/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New Request</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['pending'] ?></h4><small class="text-muted">Pending Approval</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-info bg-opacity-10 text-center py-3"><h4><?= $kpi['survey'] ?></h4><small class="text-muted">Pending Survey</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['completed'] ?></h4><small class="text-muted">Completed</small></div></div>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search disposal#, person, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['DRAFT','PENDING_SURVEY','PENDING_APPROVAL','APPROVED','IN_PROGRESS','COMPLETED','REJECTED'] as $s): ?>
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
                    <tr><th>Disposal #</th><th>Method</th><th>Requested By</th><th>Items</th><th class="text-end">Est. Value</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/disposal/view.php?id=<?= $r['disposal_id'] ?>"><?= htmlspecialchars($r['disposal_number']) ?></a></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $r['disposal_method'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars($r['requester_name']) ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td class="text-end">$<?= number_format($r['total_value'] ?? 0, 2) ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'COMPLETED' => 'success', 'APPROVED' => 'info', 'PENDING_APPROVAL' => 'warning', 'PENDING_SURVEY' => 'primary', 'REJECTED' => 'danger', default => 'secondary' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $r['status']) ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/disposal/view.php?id=<?= $r['disposal_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
