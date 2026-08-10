<?php
$REQUIRE_PERMISSION = 'transfer_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';

$where = "1=1";
$params = [];
if ($search) {
    $itemSearch = buildInventoryItemSearchExistsClause('t', 'transfer_id', 'inv_transfer_items', 'transfer_id');
    $where .= " AND (t.transfer_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND t.status = ?"; $params[] = $status; }
if ($type) { $where .= " AND t.transfer_type = ?"; $params[] = $type; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_transfers t LEFT JOIN users u ON t.requested_by = u.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT t.*, u.full_name AS initiator_name,
           fl.location_code AS from_loc, tl.location_code AS to_loc,
           (SELECT COUNT(*) FROM inv_transfer_items WHERE transfer_id = t.transfer_id) AS line_count
    FROM inv_transfers t
    LEFT JOIN users u ON t.requested_by = u.user_id
    LEFT JOIN inv_locations fl ON t.source_location_id = fl.location_id
    LEFT JOIN inv_locations tl ON t.destination_location_id = tl.location_id
    WHERE $where
    ORDER BY t.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN status='IN_TRANSIT' THEN 1 ELSE 0 END) AS transit,
    SUM(CASE WHEN status='PENDING_APPROVAL' THEN 1 ELSE 0 END) AS pending
    FROM inv_transfers")->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> Stock Transfers</h2>
    <a href="/inventory/transfers/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New Transfer</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total Transfers</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['completed'] ?></h4><small class="text-muted">Completed</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-info bg-opacity-10 text-center py-3"><h4><?= $kpi['transit'] ?></h4><small class="text-muted">In Transit</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['pending'] ?></h4><small class="text-muted">Pending Approval</small></div></div>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="text" name="search" class="form-control" placeholder="Search transfer#, person, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['DRAFT','PENDING_APPROVAL','APPROVED','IN_TRANSIT','COMPLETED','CANCELLED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="type" class="form-select">
            <option value="">All Types</option>
            <?php foreach (['INTERNAL','INTER_BRANCH','INTER_MDA'] as $t): ?>
            <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= str_replace('_', ' ', $t) ?></option>
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
                    <tr><th>Transfer #</th><th>Type</th><th>From</th><th>To</th><th>Initiated By</th><th>Lines</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/transfers/view.php?id=<?= $r['transfer_id'] ?>"><?= htmlspecialchars($r['transfer_number']) ?></a></td>
                        <td><span class="badge bg-<?= $r['transfer_type'] === 'INTER_MDA' ? 'danger' : ($r['transfer_type'] === 'INTER_BRANCH' ? 'warning' : 'info') ?>"><?= str_replace('_', ' ', $r['transfer_type']) ?></span></td>
                        <td><?= htmlspecialchars($r['from_loc']) ?></td>
                        <td><?= htmlspecialchars($r['to_loc']) ?></td>
                        <td><?= htmlspecialchars($r['initiator_name']) ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'COMPLETED' => 'success', 'IN_TRANSIT' => 'info', 'APPROVED' => 'primary', 'PENDING_APPROVAL' => 'warning', 'DRAFT' => 'secondary', default => 'light' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= $r['status'] ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/transfers/view.php?id=<?= $r['transfer_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
