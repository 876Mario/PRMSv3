<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQSearchService.php';

// Branch filtering: Director HRM&A sees only branch 5, Deputy GC sees only branch 6
$currentRole = $_SESSION['role'] ?? $_SESSION['role_name'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$branchFilter = '';
$branchParams = [];
if ($currentRole === 'Director HRM&A') {
    $branchFilter = 'WHERE pr.branch_id = :branch_id';
    $branchParams = [':branch_id' => 5];
} elseif ($currentRole === 'Deputy Government Chemist') {
    $branchFilter = 'WHERE pr.branch_id = :branch_id';
    $branchParams = [':branch_id' => 6];
}

// Get search term from query params
$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
$isSearching = !empty($searchTerm);

// KPI aggregate query (all records, no paging)
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_rfqs,
        SUM(CASE WHEN r.status = 'AWARDED' THEN 1 ELSE 0 END) AS awarded,
        SUM(CASE WHEN r.status IN ('OPEN','EVALUATION','PUBLISHED') THEN 1 ELSE 0 END) AS open_count
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    $branchFilter
");
$kpiStmt->execute($branchParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

$totalRfqs = (int)$kpi['total_rfqs'];
$awarded   = (int)$kpi['awarded'];
$open      = (int)$kpi['open_count'];

// Unique status count
$statusCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.status) AS unique_statuses
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    $branchFilter
");
$statusCountStmt->execute($branchParams);
$uniqueStatuses = (int)$statusCountStmt->fetchColumn();

// Pagination
extract(getPaginationParams(20));

// Paginated results - use search if search term provided
if ($isSearching) {
    $rfqSearch = new RFQSearchService($pdo, $userId, $currentRole);
    $searchResult = $rfqSearch->search($searchTerm, $perPage, $offset);
    $rfqs = $searchResult['rfqs'];
    $totalRfqs = $searchResult['total_count'];
    $searchDisplayTerm = htmlspecialchars($searchResult['search_term']);
} else {
    $stmt = $pdo->prepare("
        SELECT r.rfq_id, r.rfq_number, r.status, r.created_at,
               pr.request_number,
               (SELECT COUNT(*) FROM rfq_vendors rv WHERE rv.rfq_id = r.rfq_id) AS vendor_count
        FROM rfqs r
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        $branchFilter
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($branchParams as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rfqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $searchDisplayTerm = '';
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<!-- Page Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#1a1a2e;">
            <i class="bi bi-file-earmark-text"></i> Request for Quotations
        </h4>
        <p class="text-muted mb-0 small">Manage and track all RFQs issued for procurement requests</p>
    </div>
    <a href="/procurement/list.php" class="btn btn-outline-secondary rounded-pill">
        <i class="bi bi-arrow-left"></i> Back to Procurement
    </a>
</div>

<!-- Search Bar -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <div class="flex-grow-1" style="min-width: 250px;">
                <input 
                    type="text" 
                    name="q" 
                    class="form-control rounded-pill" 
                    placeholder="Search by request number, vendor, description, status…"
                    value="<?= htmlspecialchars($searchTerm) ?>"
                    autocomplete="off"
                >
            </div>
            <button type="submit" class="btn btn-success rounded-pill">
                <i class="bi bi-search me-1"></i>Search
            </button>
            <?php if ($isSearching): ?>
            <a href="/rfq/list.php" class="btn btn-outline-secondary rounded-pill">
                <i class="bi bi-x-circle me-1"></i>Clear
            </a>
            <?php endif; ?>
        </form>
        <?php if ($isSearching): ?>
        <small class="text-muted d-block mt-2">
            <i class="bi bi-info-circle"></i> 
            Found <?= $totalRfqs ?> matching RFQ<?= $totalRfqs !== 1 ? 's' : '' ?> for: <strong><?= $searchDisplayTerm ?></strong>
        </small>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1">Total RFQs</div>
                <div class="fs-3 fw-bold" style="color:#0b5e2b;"><?= $totalRfqs ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #198754 !important;">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1">Awarded</div>
                <div class="fs-3 fw-bold text-success"><?= $awarded ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-left:4px solid #ffc107 !important;">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1">Open / In Evaluation</div>
                <div class="fs-3 fw-bold text-warning"><?= $open ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body text-center py-3">
                <div class="text-muted small mb-1">Unique Statuses</div>
                <div class="fs-3 fw-bold" style="color:#6f42c1;"><?= $uniqueStatuses ?></div>
            </div>
        </div>
    </div>
</div>

<!-- RFQ Table Card -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-0 rounded-top-4 py-3 d-flex align-items-center justify-content-between">
        <h6 class="fw-semibold mb-0"><i class="bi bi-list-ul me-1"></i> <?= $isSearching ? 'Search Results' : 'All RFQs' ?></h6>
        <?php if ($isSearching): ?>
        <span class="badge rounded-pill" style="background:#0b5e2b;"><?= $totalRfqs ?> result<?= $totalRfqs !== 1 ? 's' : '' ?></span>
        <?php else: ?>
        <span class="badge rounded-pill" style="background:#0b5e2b;"><?= $totalRfqs ?> record<?= $totalRfqs !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr >
                        <th >#</th>
                        <th >RFQ Number</th>
                        <th >Request #</th>
                        <th >Vendors</th>
                        <th >Status</th>
                        <th >Created</th>
                        <th >Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rfqs)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
                            <?= $isSearching ? 'No matching RFQs found.' : 'No RFQs created yet.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rfqs as $idx => $r):
                        $statusMap = [
                            'OPEN'       => ['bg' => 'bg-primary',   'icon' => 'bi-envelope-open'],
                            'PUBLISHED'  => ['bg' => 'bg-info text-dark', 'icon' => 'bi-send'],
                            'EVALUATION' => ['bg' => 'bg-warning text-dark', 'icon' => 'bi-clipboard-check'],
                            'AWARDED'    => ['bg' => 'bg-success',   'icon' => 'bi-trophy'],
                            'CANCELLED'  => ['bg' => 'bg-danger',    'icon' => 'bi-x-circle'],
                            'CLOSED'     => ['bg' => 'bg-secondary', 'icon' => 'bi-lock'],
                        ];
                        $sm = $statusMap[$r['status']] ?? ['bg' => 'bg-secondary', 'icon' => 'bi-question-circle'];
                    ?>
                    <tr>
                        <td class="ps-4 text-muted"><?= $offset + $idx + 1 ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r['rfq_number']) ?></td>
                        <td>
                            <span class="text-muted"><?= htmlspecialchars($r['request_number']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <i class="bi bi-people me-1"></i><?= (int)$r['vendor_count'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $sm['bg'] ?> rounded-pill">
                                <i class="bi <?= $sm['icon'] ?> me-1"></i><?= htmlspecialchars($r['status']) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                        <td class="text-end pe-4">
                            <a href="view.php?id=<?= $r['rfq_id'] ?>"
                               class="btn btn-sm btn-outline-success rounded-pill">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalRfqs > 0): ?>
    <div class="card-footer bg-white border-0 pt-0 pb-3 px-3">
        <?php 
        // Pass all GET params to pagination to preserve search state
        $paginationParams = $_GET;
        renderShowingInfo($page, $perPage, $totalRfqs); 
        renderPagination($totalRfqs, $perPage, $page, $paginationParams); 
        ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
