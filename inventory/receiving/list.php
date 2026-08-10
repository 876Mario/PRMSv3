<?php
$REQUIRE_PERMISSION = 'receive_goods';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = "1=1";
$params = [];

if ($search !== '') {
    $itemSearch = buildInventoryItemSearchExistsClause('g', 'grn_id', 'inv_grn_items', 'grn_id');
    $where .= " AND (g.grn_number LIKE ? OR g.po_reference LIKE ? OR u.full_name LIKE ? OR g.supplier_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND g.status = ?"; $params[] = $status; }
if ($dateFrom) { $where .= " AND g.received_date >= ?"; $params[] = $dateFrom; }
if ($dateTo) { $where .= " AND g.received_date <= ?"; $params[] = $dateTo; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_goods_received g LEFT JOIN users u ON g.received_by = u.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT g.*, u.full_name AS receiver_name,
           (SELECT COUNT(*) FROM inv_grn_items WHERE grn_id = g.grn_id) AS line_count
    FROM inv_goods_received g
    LEFT JOIN users u ON g.received_by = u.user_id
    WHERE $where
    ORDER BY g.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* KPIs */
$kpiStmt = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN status='INSPECTION' THEN 1 ELSE 0 END) AS inspection,
    SUM(CASE WHEN status='QUARANTINE' THEN 1 ELSE 0 END) AS quarantine
    FROM inv_goods_received");
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam"></i> Goods Received Notes</h2>
    <a href="/inventory/receiving/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New GRN</a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total GRNs</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['completed'] ?></h4><small class="text-muted">Completed</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['inspection'] ?></h4><small class="text-muted">In Inspection</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-danger bg-opacity-10 text-center py-3"><h4><?= $kpi['quarantine'] ?></h4><small class="text-muted">Quarantine</small></div></div>
</div>

<!-- Filters -->
<form class="row g-2 mb-3">
    <div class="col-md-3"><input type="text" name="search" class="form-control" placeholder="Search GRN#, PO#, supplier, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['DRAFT','INSPECTION','QUARANTINE','COMPLETED','CANCELLED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>"></div>
    <div class="col-md-2"><input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>"></div>
    <div class="col-md-2"><button class="btn btn-dark w-100"><i class="bi bi-search"></i> Filter</button></div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>GRN #</th><th>PO #</th><th>Supplier</th><th>Received Date</th><th>Received By</th><th>Lines</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/receiving/view.php?id=<?= $r['grn_id'] ?>"><?= htmlspecialchars($r['grn_number']) ?></a></td>
                        <td><?= htmlspecialchars($r['po_reference'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($r['supplier_name']) ?></td>
                        <td><?= $r['received_date'] ?></td>
                        <td><?= htmlspecialchars($r['receiver_name']) ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'COMPLETED' => 'success', 'INSPECTION' => 'warning', 'QUARANTINE' => 'danger', 'DRAFT' => 'secondary', default => 'light' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= $r['status'] ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/receiving/view.php?id=<?= $r['grn_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
