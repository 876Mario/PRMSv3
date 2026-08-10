<?php
$REQUIRE_PERMISSION = 'manage_board_of_survey';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryTransactionSearch.php';

/* ── Schema guard ────────────────────────────────────────────────────────── */
$bosReady = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inv_board_of_survey'"
)->fetchColumn();

if (!$bosReady) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
    echo '<div class="alert alert-warning mt-4"><i class="bi bi-exclamation-triangle"></i>
          Board of Survey tables have not been created yet. Please run the
          <code>2026_07_29_board_of_survey.sql</code> migration.</div>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit;
}

/* ── Filters ─────────────────────────────────────────────────────────────── */
$search = trim($_GET['search'] ?? '');
$status = $_GET['status']      ?? '';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $s = inventoryTransactionSearchPattern($search);
    $itemSearch = buildInventoryItemSearchExistsClause('b', 'bos_id', 'inv_bos_items', 'bos_id');
    $where[]  = "(b.bos_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}
if ($status !== '') {
    $where[]  = "b.status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $where);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
extract(getPaginationParams());

$total = $pdo->prepare(
    "SELECT COUNT(*)
     FROM inv_board_of_survey b
     LEFT JOIN users u ON b.initiated_by = u.user_id
     WHERE $whereClause"
);
$total->execute($params);
$totalRows = (int) $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT b.*,
           u.full_name   AS initiator_name,
           rv.full_name  AS reviewer_name,
           av.full_name  AS approver_name,
           l.location_code,
           l.site_name,
           (SELECT COUNT(*) FROM inv_bos_items WHERE bos_id = b.bos_id) AS item_count
    FROM inv_board_of_survey b
    LEFT JOIN users u  ON b.initiated_by = u.user_id
    LEFT JOIN users rv ON b.reviewed_by  = rv.user_id
    LEFT JOIN users av ON b.approved_by  = av.user_id
    LEFT JOIN inv_locations l ON b.location_id = l.location_id
    WHERE $whereClause
    ORDER BY b.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status IN ('DRAFT','SUBMITTED','UNDER_REVIEW')) AS open_count,
        SUM(status = 'APPROVED')  AS approved_count,
        SUM(status = 'COMPLETED') AS completed_count,
        SUM(status = 'REJECTED')  AS rejected_count
    FROM inv_board_of_survey
")->fetch(PDO::FETCH_ASSOC);

$allStatuses = ['DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','REJECTED','COMPLETED','CANCELLED'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard2-pulse"></i> Board of Survey</h2>
    <a href="/inventory/board_of_survey/add.php" class="btn btn-dark">
        <i class="bi bi-plus-lg"></i> New Board of Survey
    </a>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 bg-primary bg-opacity-10">
            <h4 class="mb-0"><?= (int) $kpi['total'] ?></h4>
            <small class="text-muted">Total</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 bg-warning bg-opacity-10">
            <h4 class="mb-0"><?= (int) $kpi['open_count'] ?></h4>
            <small class="text-muted">Open / In Review</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 bg-success bg-opacity-10">
            <h4 class="mb-0"><?= (int) $kpi['approved_count'] ?></h4>
            <small class="text-muted">Approved</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3 bg-secondary bg-opacity-10">
            <h4 class="mb-0"><?= (int) $kpi['completed_count'] ?></h4>
            <small class="text-muted">Completed</small>
        </div>
    </div>
</div>

<!-- Filters -->
<form class="row g-2 mb-3">
    <div class="col-md-5">
        <input type="text" name="search" class="form-control"
               placeholder="Search BOS number, initiator, item code or description…"
               value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <?php foreach ($allStatuses as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
                <?= str_replace('_', ' ', $s) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <button class="btn btn-dark w-100"><i class="bi bi-search"></i> Filter</button>
    </div>
    <?php if ($search !== '' || $status !== ''): ?>
    <div class="col-md-2">
        <a href="/inventory/board_of_survey/list.php" class="btn btn-outline-secondary w-100">
            <i class="bi bi-x-circle"></i> Clear
        </a>
    </div>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>BOS #</th>
                        <th>Survey Date</th>
                        <th>Location</th>
                        <th>Initiated By</th>
                        <th class="text-center">Items</th>
                        <th>Recommendation</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No Board of Survey records found.
                        </td>
                    </tr>
                    <?php else: foreach ($rows as $r): ?>
                    <?php
                        $sc = match($r['status']) {
                            'APPROVED'     => 'success',
                            'COMPLETED'    => 'primary',
                            'UNDER_REVIEW' => 'info',
                            'SUBMITTED'    => 'warning',
                            'REJECTED'     => 'danger',
                            'CANCELLED'    => 'dark',
                            default        => 'secondary',
                        };
                    ?>
                    <tr>
                        <td>
                            <a href="/inventory/board_of_survey/view.php?id=<?= $r['bos_id'] ?>">
                                <strong><?= htmlspecialchars($r['bos_number']) ?></strong>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($r['survey_date'] ?? '—') ?></td>
                        <td>
                            <?php if ($r['location_code']): ?>
                            <code><?= htmlspecialchars($r['location_code']) ?></code>
                            <?php if ($r['site_name']): ?>
                            <span class="text-muted small">— <?= htmlspecialchars($r['site_name']) ?></span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['initiator_name'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int) $r['item_count'] ?></span>
                        </td>
                        <td>
                            <?php if ($r['board_recommendation']): ?>
                            <span class="badge bg-info text-dark">
                                <?= str_replace('_', ' ', $r['board_recommendation']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $sc ?>">
                                <?= str_replace('_', ' ', $r['status']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="/inventory/board_of_survey/view.php?id=<?= $r['bos_id'] ?>"
                               class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
