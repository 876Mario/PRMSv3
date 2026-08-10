<?php
$REQUIRE_PERMISSION = 'issue_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$where = "1=1";
$params = [];
if ($search !== '') {
    $itemSearch = buildInventoryItemSearchExistsClause('si', 'issue_id', 'inv_issue_items', 'issue_id');
    $where .= " AND (si.issue_number LIKE ? OR si.requisition_number LIKE ? OR u.full_name LIKE ? OR r.full_name LIKE ? OR $itemSearch)";
    $s = inventoryTransactionSearchPattern($search);
    $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s]);
}
if ($status) { $where .= " AND si.status = ?"; $params[] = $status; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare("SELECT COUNT(*) FROM inv_issues si LEFT JOIN users u ON si.issued_by = u.user_id LEFT JOIN users r ON si.issued_to_user_id = r.user_id WHERE $where");
$total->execute($params);
$totalRows = $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT si.*, u.full_name AS issuer_name, r.full_name AS recipient_name, b.branch_name,
           (SELECT COUNT(*) FROM inv_issue_items WHERE issue_id = si.issue_id) AS line_count
    FROM inv_issues si
    LEFT JOIN users u ON si.issued_by = u.user_id
    LEFT JOIN users r ON si.issued_to_user_id = r.user_id
    LEFT JOIN branches b ON si.issued_to_department_id = b.branch_id
    WHERE $where
    ORDER BY si.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) AS completed,
    SUM(CASE WHEN status='PENDING_APPROVAL' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN status='PARTIAL' THEN 1 ELSE 0 END) AS partial
    FROM inv_issues")->fetch(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-right"></i> Stock Issues</h2>
    <a href="/inventory/issuing/add.php" class="btn btn-dark"><i class="bi bi-plus-lg"></i> New Issue</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-primary bg-opacity-10 text-center py-3"><h4><?= $kpi['total'] ?></h4><small class="text-muted">Total Issues</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-success bg-opacity-10 text-center py-3"><h4><?= $kpi['completed'] ?></h4><small class="text-muted">Completed</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-warning bg-opacity-10 text-center py-3"><h4><?= $kpi['pending'] ?></h4><small class="text-muted">Pending Approval</small></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm bg-info bg-opacity-10 text-center py-3"><h4><?= $kpi['partial'] ?></h4><small class="text-muted">Partial Issue</small></div></div>
</div>

<form class="row g-2 mb-3">
    <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search issue#, requisition#, person, item code or description..." value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach (['DRAFT','PENDING_APPROVAL','APPROVED','COMPLETED','PARTIAL','CANCELLED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
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
                    <tr><th>Issue #</th><th>Requisition</th><th>Issued To</th><th>Department</th><th>Date</th><th>Lines</th><th>Status</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/issuing/view.php?id=<?= $r['issue_id'] ?>"><?= htmlspecialchars($r['issue_number']) ?></a></td>
                        <td><?= htmlspecialchars($r['requisition_number'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($r['recipient_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['branch_name'] ?? '-') ?></td>
                        <td><?= date('Y-m-d', strtotime($r['created_at'])) ?></td>
                        <td><?= $r['line_count'] ?></td>
                        <td>
                            <?php $sc = match($r['status']) { 'COMPLETED' => 'success', 'APPROVED' => 'info', 'PENDING_APPROVAL' => 'warning', 'PARTIAL' => 'primary', 'DRAFT' => 'secondary', default => 'light' }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= $r['status'] ?></span>
                        </td>
                        <td class="text-end"><a href="/inventory/issuing/view.php?id=<?= $r['issue_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
