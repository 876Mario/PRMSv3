<?php
$REQUIRE_PERMISSION = 'adjust_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';

$where = "1=1";
$params = [];
if ($search !== '') {
    $itemSearch = buildInventoryItemSearchExistsClause('a', 'adjustment_id', 'inv_adjustment_items', 'adjustment_id');
    $where .= " AND (a.adjustment_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND a.status = ?"; $params[] = $status; }
if ($type) { $where .= " AND a.adjustment_type = ?"; $params[] = $type; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_adjustments a LEFT JOIN users u ON a.requested_by = u.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS creator_name, l.location_code,
           (SELECT COUNT(*) FROM inv_adjustment_items WHERE adjustment_id = a.adjustment_id) AS line_count
    FROM inv_adjustments a
    LEFT JOIN users u ON a.requested_by = u.user_id
    LEFT JOIN inv_locations l ON a.location_id = l.location_id
    WHERE $where
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN status='PENDING_APPROVAL' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN adjustment_type='GAIN' THEN 1 ELSE 0 END) AS gains,
    SUM(CASE WHEN adjustment_type='LOSS' THEN 1 ELSE 0 END) AS losses
    FROM inv_adjustments")->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-sliders"></i> Stock Adjustments</h2>
    <a href="/inventory/adjustments/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New Adjustment</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['pending'] ?></h4><small class="text-muted">Pending Approval</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['gains'] ?></h4><small class="text-muted">Gains</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-danger bg-opacity-10 text-center py-3"><h4><?= $kpi['losses'] ?></h4><small class="text-muted">Losses</small></div></div>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="text" name="search" class="form-control" placeholder="Search adjustment#, person, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['DRAFT','PENDING_APPROVAL','APPROVED','REJECTED','UNDER_INVESTIGATION'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="type" class="form-select">
            <option value="">All Types</option>
            <option value="GAIN" <?= $type === 'GAIN' ? 'selected' : '' ?>>Gain</option>
            <option value="LOSS" <?= $type === 'LOSS' ? 'selected' : '' ?>>Loss</option>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-dark w-100"><i class="bi bi-search"></i> Filter</button></div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Adjustment #</th><th>Type</th><th>Reason</th><th>Location</th><th>Created By</th><th>Lines</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/adjustments/view.php?id=<?= $r['adjustment_id'] ?>"><?= htmlspecialchars($r['adjustment_number']) ?></a></td>
                        <td><span class="badge bg-<?= $r['adjustment_type'] === 'GAIN' ? 'success' : 'danger' ?>"><?= $r['adjustment_type'] ?></span></td>
                        <td><?= htmlspecialchars($r['reason_code'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['location_code']) ?></td>
                        <td><?= htmlspecialchars($r['creator_name']) ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'APPROVED' => 'success', 'PENDING_APPROVAL' => 'warning', 'UNDER_INVESTIGATION' => 'info', 'REJECTED' => 'danger', 'COMPLETED' => 'primary', default => 'secondary' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= $r['status'] ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/adjustments/view.php?id=<?= $r['adjustment_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
