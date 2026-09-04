<?php
$REQUIRE_PERMISSION = 'view_commitments';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/policy.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/pagination.php";


/* ================================
   Filters
================================ */
$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(c.commitment_number LIKE :q 
                 OR pr.request_number LIKE :q 
                 OR po.po_number LIKE :q)";
    $params[':q'] = '%'.$_GET['q'].'%';
}

if (!empty($_GET['from'])) {
    $where[] = "c.commitment_date >= :from";
    $params[':from'] = $_GET['from'];
}

if (!empty($_GET['to'])) {
    $where[] = "c.commitment_date <= :to";
    $params[':to'] = $_GET['to'];
}

// Branch filtering: Director HRM&A sees only branch 5, Deputy GC sees only branch 6
$currentRole = $_SESSION['role'] ?? $_SESSION['role_name'] ?? '';
if ($currentRole === 'Director HRM&A') {
    $where[] = "pr.branch_id = :branch_filter";
    $params[':branch_filter'] = 5;
} elseif ($currentRole === 'Deputy Government Chemist') {
    $where[] = "pr.branch_id = :branch_filter";
    $params[':branch_filter'] = 6;
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ================================
   Pagination params
================================ */
extract(getPaginationParams(20));

/* ================================
   Data query (PAGINATED)
================================ */
$sql = "
   SELECT
        c.commitment_id,
        c.commitment_number,
        c.commitment_date,
        c.commitment_total,
        c.request_id,
        c.status AS commitment_status,
        c.commitment_type,
        c.gfms_commitment_number,
        c.document_path,
        po.po_id,
        pr.request_number,
        pr.status AS request_status,
        po.po_number,
        b.branch_name

    FROM commitments c
    JOIN procurement_requests pr 
        ON c.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN purchase_orders po 
        ON po.commitment_id = c.commitment_id

    $whereSQL
    GROUP BY c.commitment_id
    ORDER BY c.commitment_date DESC
    LIMIT :limit OFFSET :offset
";



$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Count query (FOR PAGINATION)
================================ */
$countSql = "
    SELECT COUNT(DISTINCT c.commitment_id)
    FROM commitments c
    JOIN procurement_requests pr 
        ON c.request_id = pr.request_id
    LEFT JOIN purchase_orders po 
        ON c.commitment_id = po.commitment_id
    $whereSQL
";


$countStmt = $pdo->prepare($countSql);

foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}

$countStmt->execute();
$totalRows = (int)$countStmt->fetchColumn();

/* ================================
   KPI Summary Metrics
================================ */
// KPI query with branch filtering — always JOIN procurement_requests to match the data query
$kpiBranchWhere = '';
$kpiBranchParams = [];
if ($currentRole === 'Director HRM&A') {
    $kpiBranchWhere = 'WHERE pr.branch_id = ?';
    $kpiBranchParams = [5];
} elseif ($currentRole === 'Deputy Government Chemist') {
    $kpiBranchWhere = 'WHERE pr.branch_id = ?';
    $kpiBranchParams = [6];
}
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*)                                    AS total_commitments,
        COALESCE(SUM(c.commitment_total), 0)        AS total_value,
        SUM(CASE WHEN c.status = 'open' THEN 1 ELSE 0 END)   AS open_count,
        SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed_count
    FROM commitments c
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    $kpiBranchWhere
");
$kpiStmt->execute($kpiBranchParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

/* ================================
   Render page
================================ */
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
?>

<style>
    .section-title {
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 1.5rem;
    }
    
    .card {
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }
    
    .form-control, .form-select {
        transition: all 0.2s ease;
        box-shadow: none !important;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
    }
    
    .btn {
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    table tbody tr:hover td {
        background-color: #f9f9f9 !important;
    }
</style>

<div class="mb-5">

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">📋 Commitments Register</h2>
        <p class="text-muted mb-0">Track and manage all procurement commitments</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI SUMMARY CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Value</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 1.5rem;"><?= money((float)$kpi['total_value']) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">💰</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Closed</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$kpi['closed_count'] ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">✅</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Open</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$kpi['open_count'] ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">📂</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Commitments</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= number_format((int)$kpi['total_commitments']) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">📊</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     FILTERS
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <div class="d-flex align-items-center gap-2 py-2">
            <i class="bi bi-funnel" style="font-size: 1.2rem; color: #667eea;"></i>
            <h6 class="mb-0" style="font-weight: 600; color: #1a1a1a;">Search & Filter</h6>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">Search</label>
                <input type="text" name="q"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       placeholder="Commitment #, Request #, PO #"
                       class="form-control"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-3 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">From Date</label>
                <input type="date" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>" class="form-control" style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-3 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">To Date</label>
                <input type="date" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>" class="form-control" style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-2 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/commitments/list.php" class="btn btn-outline-secondary" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info border-0 mb-4" style="border-radius: 6px; background-color: #e3f2fd; color: #1565c0;">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-info-circle" style="font-size: 1.2rem;"></i>
        <small><strong>Showing</strong> <?= ($offset + 1) ?> - <?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> commitments (Page <?= $page ?>/<?= max(1, ceil($totalRows / $perPage)) ?>)</small>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     COMMITMENTS TABLE
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead class="table-dark">
                <tr>
                    <th >Commitment #</th>
                    <th >Date</th>
                    <th >Request #</th>
                    <th >Total</th>
                    <th >Branch</th>
                    <th >Purchase Order</th>
                    <th >GFMS #</th>
                    <th >Status</th>
                    <th >Doc</th>
                    <th >Actions</th>
                </tr>
            </thead>
            <tbody>
<?php foreach($rows as $row): 
    // Determine row styling based on status
    $rowBg = $row['commitment_status'] === 'closed' ? '#e8f5e9' : '#ffffff';
?>
    <tr style="background-color: <?= $rowBg ?>; border-bottom: 1px solid #e0e0e0;">
        <td style="padding: 1rem; border: none;">
            <strong style="color: #667eea; font-size: 0.95rem;">CMT-<?= str_pad((int)$row['commitment_id'], 6, '0', STR_PAD_LEFT) ?></strong>
        </td>
        <td style="padding: 1rem; border: none;">
            <small><?= date('d M Y', strtotime($row['commitment_date'])) ?></small>
        </td>
        <td style="padding: 1rem; border: none;">
            <small style="color: #555;">REQ-<?= str_pad((int)$row['request_id'], 6, '0', STR_PAD_LEFT) ?></small>
        </td>
        <td style="padding: 1rem; border: none; text-align: right; font-weight: 600; color: #1a1a1a;">
            <?= money((float)$row['commitment_total']) ?>
        </td>
        <td style="padding: 1rem; border: none;">
            <small style="color: #555;"><?= htmlspecialchars($row['branch_name'] ?? 'N/A') ?></small>
        </td>
        <td style="padding: 1rem; border: none;">
            <?php if ($row['po_id']): ?>
                <small style="color: #667eea; font-weight: 500;"><?= htmlspecialchars($row['po_number'] ?? 'PO-'.str_pad((int)$row['po_id'], 5, '0', STR_PAD_LEFT)) ?></small>
            <?php else: ?>
                <span class="badge bg-light text-muted">No PO</span>
            <?php endif; ?>
        </td>
        <td style="padding: 1rem; border: none; text-align: center;">
            <?php if (!empty($row['gfms_commitment_number'])): ?>
                <small style="color: #1a1a2e; font-weight: 600;"><?= htmlspecialchars($row['gfms_commitment_number']) ?></small>
            <?php else: ?>
                <span class="text-muted small">—</span>
            <?php endif; ?>
        </td>
        <td style="padding: 1rem; border: none; text-align: center;">
            <?php if ($row['commitment_status'] === 'closed'): ?>
                <span class="badge bg-success p-2" style="font-size: 0.75rem;">Closed</span>
            <?php else: ?>
                <span class="badge bg-primary p-2" style="font-size: 0.75rem;">Open</span>
            <?php endif; ?>
        </td>
        <td style="padding: 1rem; border: none; text-align: center;">
            <?php if (!empty($row['document_path'])): ?>
                <a href="/commitments/download_document.php?commitment_id=<?= (int)$row['commitment_id'] ?>" class="text-success" title="View Document"><i class="bi bi-file-earmark-pdf-fill"></i></a>
            <?php else: ?>
                <span class="text-muted"><i class="bi bi-dash"></i></span>
            <?php endif; ?>
        </td>
        <td style="padding: 1rem; border: none; text-align: center;">
            <a href="/commitments/view.php?id=<?= (int)$row['commitment_id'] ?>"
               class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem;">
                <i class="bi bi-eye"></i>
            </a>
        </td>
    </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (empty($result)): ?>
        <div class="card-body text-center py-5">
            <p style="color: #999; font-size: 1rem;">
                <i class="bi bi-inbox" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 0.5rem;"></i>
                No commitments found
            </p>
        </div>
    <?php endif; ?>
</div>

<div style="text-align: center; margin-top: 2rem; padding: 1rem; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e0e0e0;">
    <small style="color: #666; font-weight: 500;">
        📊 Total: <strong><?= number_format($totalRows) ?></strong> commitment(s)
        | Value: <strong><?= money((float)$kpi['total_value']) ?></strong>
    </small>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>
<?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
