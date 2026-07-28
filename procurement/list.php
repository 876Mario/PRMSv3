<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/pagination.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
// Handle delete request
if (
    isset($_POST['delete_request_id']) &&
    (
        (isset($_SESSION['role_name']) && in_array(strtolower($_SESSION['role_name']), ['admin', 'superadmin'])) ||
        (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'superadmin']))
    )
) {
    $deleteId = (int)$_POST['delete_request_id'];
    // Delete request
    try {
        $delStmt = $pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?");
        $delStmt->execute([$deleteId]);
        // Audit log
        logAudit($pdo, 'procurement_requests', $deleteId, 'DELETE', 'Request deleted by admin');
        logRequestTimeline($pdo, $deleteId, 'DELETE', 'Request deleted by admin');
        // Feedback: show popup notification then reload
        echo '<script>alert("Request deleted successfully."); window.location.href="/procurement/list.php";</script>';
    } catch (Throwable $e) {
        echo '<script>alert("' . addslashes(extractDbMessage($e)) . '"); window.location.href="/procurement/list.php";</script>';
    }
    exit;
}

/* ================================
   Filters
================================ */
$where = [];
$params = [];

/* Search */
if (!empty($_GET['q'])) {
    $where[] = "(
        po.po_number LIKE :q
        OR c.commitment_number LIKE :q
        OR pr.request_number LIKE :q
        OR pr.description LIKE :q
        OR EXISTS (
            SELECT 1
            FROM procurement_request_items pri
            WHERE pri.request_id = pr.request_id
              AND (
                  pri.item_name LIKE :q
                  OR pri.specification LIKE :q
                  OR pri.remarks LIKE :q
              )
        )
    )";
    $params[':q'] = '%'.$_GET['q'].'%';
}


// Restrict Requestor to only their own requests
if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Requestor') {
    $where[] = "pr.created_by = :requestor_id";
    $params[':requestor_id'] = $_SESSION['user_id'];
}

// Branch heads: restrict to their own branch (HOD sees all)
$currentRole = $_SESSION['role'] ?? $_SESSION['role_name'] ?? '';
if ($currentRole === 'Director HRM&A') {
    $where[] = "pr.branch_id = :branch_filter";
    $params[':branch_filter'] = 5; // HRM&A
} elseif ($currentRole === 'Deputy Government Chemist') {
    $where[] = "pr.branch_id = :branch_filter";
    $params[':branch_filter'] = 6; // Analytical & Advisory
}

/* REQUEST STATUS FILTER (CORRECT) */
if (!empty($_GET['request_status'])) {
    $where[] = "pr.status = :request_status";
    $params[':request_status'] = $_GET['request_status'];
}

/* PO STATUS FILTER (OPTIONAL BUT GOOD) */
if (!empty($_GET['po_status'])) {
    $where[] = "po.status = :po_status";
    $params[':po_status'] = $_GET['po_status'];
}

/* REQUEST DATE FILTERS */
if (!empty($_GET['from'])) {
    $where[] = "pr.request_date >= :from";
    $params[':from'] = $_GET['from'];
}

if (!empty($_GET['to'])) {
    $where[] = "pr.request_date <= :to";
    $params[':to'] = $_GET['to'];
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ================================
   Pagination params
================================ */
extract(getPaginationParams(10));

/* ================================
   Data query (PAGINATED)
================================ */
$sql = "
    SELECT
        pr.request_id,
        pr.request_number,
        pr.request_date,
        pr.request_type,
        pr.status AS request_status,
        pr.description AS request_description,

        c.commitment_id,
        c.commitment_number,

        po.po_id,
        po.po_number,
        po.po_date,
        po.po_total,
        po.status AS po_status

    FROM procurement_requests pr

    LEFT JOIN commitments c 
        ON pr.request_id = c.request_id

    LEFT JOIN purchase_orders po
        ON c.commitment_id = po.commitment_id

    $whereSQL

    ORDER BY pr.request_date DESC
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
    SELECT COUNT(DISTINCT pr.request_id)
    FROM procurement_requests pr
    LEFT JOIN commitments c ON pr.request_id = c.request_id
    LEFT JOIN purchase_orders po ON c.commitment_id = po.commitment_id
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
$kpiStmt = $pdo->query("
    SELECT
        COUNT(*)                                                              AS total_requests,
        SUM(CASE WHEN status IN ('GC_APPROVED','AWARDED','COMPLETED') THEN 1 ELSE 0 END)  AS approved,
        SUM(CASE WHEN status IN ('SUBMITTED','HOD_APPROVED','FUNDS_VERIFIED','DIRECTOR_APPROVED',
                                  'PROCUREMENT_STAGE','EVALUATION_STAGE',
                                  'COMMITTEE_RECOMMENDED') THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status = 'DECLINED' THEN 1 ELSE 0 END)                 AS declined,
        SUM(CASE WHEN status = 'DRAFT'    THEN 1 ELSE 0 END)                 AS drafts
    FROM procurement_requests
");
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

$poValueStmt = $pdo->query("
    SELECT COALESCE(SUM(po_total), 0) AS total_po_value
    FROM purchase_orders
    WHERE status IN ('Open','Closed')
");
$totalPoValue = (float)$poValueStmt->fetchColumn();

/* ================================
   Render page
================================ */
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* Status badge helper */
function reqBadgeClass(string $status): string {
    return match (strtoupper($status)) {
        'GC_APPROVED', 'AWARDED', 'COMPLETED' => 'success',
        'SUBMITTED'             => 'warning text-dark',
        'HOD_APPROVED'          => 'info',
        'FUNDS_VERIFIED'        => 'primary',
        'COMMITMENTS_PENDING'   => 'warning text-dark',
        'COMMITMENT_APPROVED'   => 'success',
        'COMMITMENT_DECLINED'   => 'danger',
        'RFQ_LETTER_AVAILABLE'  => 'info',
        'QUOTE_REVIEW_PENDING'  => 'warning text-dark',
        'QUOTE_APPROVED'        => 'info',
        'PO_PENDING'            => 'success',
        'PROCUREMENT_STAGE', 'EVALUATION_STAGE' => 'dark',
        'COMMITTEE_RECOMMENDED' => 'info',
        'DECLINED'              => 'danger',
        'CANCELLED'             => 'danger',
        'DRAFT'                 => 'secondary',
        default                 => 'secondary',
    };
}
function reqLabel(string $status): string {
    return match (strtoupper($status)) {
        'DRAFT'                 => 'Draft',
        'SUBMITTED'             => 'Submitted',
        'HOD_APPROVED'          => 'HOD Approved',
        'FUNDS_VERIFIED'        => 'Funds Verified',
        'DIRECTOR_APPROVED'     => 'Director Approved',
        'COMMITMENTS_PENDING'   => 'Commitment Form',
        'COMMITMENT_APPROVED'   => 'Committed',
        'COMMITMENT_DECLINED'   => 'Declined (Funds)',
        'RFQ_LETTER_AVAILABLE'  => 'RFQ Letters',
        'QUOTE_REVIEW_PENDING'  => 'Quote Review',
        'QUOTE_APPROVED'        => 'Quote Selected',
        'PO_PENDING'            => 'PO Created',
        'PROCUREMENT_STAGE'     => 'Procurement',
        'EVALUATION_STAGE'      => 'Evaluation',
        'COMMITTEE_RECOMMENDED' => 'Committee',
        'GC_APPROVED'           => 'GC Approved',
        'AWARDED'               => 'Awarded',
        'COMPLETED'             => 'Completed',
        'DECLINED'              => 'Declined',
        'CANCELLED'             => 'Cancelled',
        default                 => $status,
    };
}

/* Request type badge helper */
function getRequestTypeBadge(string $type): array {
    return match (strtoupper($type)) {
        'REGULAR' => [
            'label' => 'Regular',
            'bg' => '#e3f2fd',
            'color' => '#1565c0',
            'icon' => '📋'
        ],
        'REIMBURSEMENT' => [
            'label' => 'Reimbursement',
            'bg' => '#f3e5f5',
            'color' => '#6a1b9a',
            'icon' => '💰'
        ],
        'PETTY_CASH' => [
            'label' => 'Petty Cash',
            'bg' => '#e8f5e9',
            'color' => '#2e7d32',
            'icon' => '🏧'
        ],
        'SERVICE_CONTRACT' => [
            'label' => 'Service Contract',
            'bg' => '#fff3e0',
            'color' => '#e65100',
            'icon' => '📄'
        ],
        default => [
            'label' => $type,
            'bg' => '#f5f5f5',
            'color' => '#666',
            'icon' => '📄'
        ]
    };
}

$statusOptions = [
    'DRAFT'                 => 'Draft',
    'SUBMITTED'             => 'Submitted',
    'HOD_APPROVED'          => 'HOD Approved',
    'FUNDS_VERIFIED'        => 'Funds Verified',
    'DIRECTOR_APPROVED'     => 'Director Approved',
    'GC_APPROVED'           => 'GC Approved',
    'RFQ_LETTER_AVAILABLE'  => 'RFQ Letters',
    'QUOTE_REVIEW_PENDING'  => 'Quote Review',
    'QUOTE_APPROVED'        => 'Quote Selected',
    'COMMITMENTS_PENDING'   => 'Commitment Form',
    'COMMITMENT_APPROVED'   => 'Committed',
    'COMMITMENT_DECLINED'   => 'Declined (Funds)',
    'PO_PENDING'            => 'PO Created',
    'AWARDED'               => 'Awarded',
    'COMPLETED'             => 'Completed',
    'DECLINED'              => 'Declined',
    'CANCELLED'             => 'Cancelled',
];

$hasFilters = !empty($_GET['q']) || !empty($_GET['request_status']) || !empty($_GET['po_status']) || !empty($_GET['from']) || !empty($_GET['to']);

require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
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
        border-radius: 6px;
        border: 1px solid #e0e0e0;
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
        border-radius: 6px;
        font-weight: 600;
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
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">📋 Procurement Request Tracker</h2>
            <p class="text-muted mb-0">Manage procurement requests, commitments, and purchase orders</p>
        </div>
        <a href="/procurement/add.php" class="btn btn-primary" style="border-radius: 6px;">
            <i class="bi bi-plus-lg me-2"></i>New Request
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         STATISTICS CARDS
    ═══════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Total Requests</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= number_format((int)$kpi['total_requests']) ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">📊</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Approved</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$kpi['approved'] ?></h4>
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
                            <p class="mb-1 small" style="opacity: 0.9;">In Progress</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$kpi['in_progress'] ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">⏳</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1 small" style="opacity: 0.9;">Total PO Value</p>
                            <h4 class="mb-0" style="font-weight: 700; font-size: 1.5rem;"><?= money($totalPoValue ?? 0) ?></h4>
                        </div>
                        <div style="font-size: 2rem; opacity: 0.3;">💰</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Advanced Filter Section -->
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
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       placeholder="PO #, Commitment #, Request #"
                       class="form-control">
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">Request Status</label>
                <select name="request_status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach ($statusOptions as $val => $label): ?>
                        <option value="<?= $val ?>"
                            <?= ($_GET['request_status'] ?? '') === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">PO Status</label>
                <select name="po_status" class="form-select">
                    <option value="">All Status</option>
                    <?php foreach (['Open','Closed','Cancelled'] as $s): ?>
                        <option value="<?= $s ?>"
                            <?= ($_GET['po_status'] ?? '') === $s ? 'selected' : '' ?>>
                            <?= $s ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">From Date</label>
                <input type="date" name="from" value="<?= $_GET['from'] ?? '' ?>" class="form-control">
            </div>

            <div class="col-md-1 col-sm-6">
                <label class="form-label small text-muted" style="font-weight: 600;">To Date</label>
                <input type="date" name="to" value="<?= $_GET['to'] ?? '' ?>" class="form-control">
            </div>

            <div class="col-md-2 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/procurement/list.php" class="btn btn-outline-secondary" style="border-radius: 6px;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info border-0 mb-4" style="border-radius: 6px; background-color: #e3f2fd; color: #1565c0;">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-info-circle" style="font-size: 1.2rem;"></i>
        <small><strong>Showing</strong> <?= ($offset + 1) ?> - <?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> requests (Page <?= $page ?>/<?= max(1, ceil($totalRows / $perPage)) ?>)</small>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PROCUREMENT TABLE
═══════════════════════════════════════════════════════ -->
<?php if (empty($rows)): ?>
<div class="alert alert-info border-0 mb-4" style="border-radius: 6px;" role="alert">
    <div class="d-flex align-items-center gap-3">
        <span style="font-size: 3rem;">📭</span>
        <div>
            <strong style="color: #1a1a1a;">No Procurement Requests Found</strong>
            <p class="mb-0" style="color: #666; font-size: 0.9rem;">Try adjusting your filters or <a href="/procurement/add.php" style="color: #667eea; text-decoration: none; font-weight: 600;">create a new request</a></p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead class="table-dark">
                <tr>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">Request #</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">Type</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">Status</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">Commitment</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">PO #</th>
                    <th style="padding: 1rem; font-weight: 600; border: none;">PO Date</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none; text-align: right;">PO Total</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none;">PO Status</th>
                    <th style="padding: 1rem; font-weight: 600;  border: none; text-align: center; width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <?php
                $rowBgColor = 'white';
                if (in_array($row['request_status'], ['GC_APPROVED','AWARDED','COMPLETED'])) {
                    $rowBgColor = '#e8f5e9';
                } elseif ($row['request_status'] === 'DECLINED') {
                    $rowBgColor = '#ffebee';
                }
            ?>
            <tr style="background-color: <?= $rowBgColor ?>; border-bottom: 1px solid #e0e0e0; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='#f5f5f5'" onmouseout="this.style.backgroundColor='<?= $rowBgColor ?>'">
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <div <?php if (!empty($row['request_description'])): ?>data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($row['request_description'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
                        <code style="background-color: #f0f0f0; padding: 0.4rem 0.8rem; border-radius: 4px; color: #1a1a1a; font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($row['request_number']) ?></code>
                        <br>
                        <small style="color: #999;"><?= date('d M Y', strtotime($row['request_date'])) ?></small>
                    </div>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php
                        $typeBadge = getRequestTypeBadge($row['request_type'] ?? 'REGULAR');
                    ?>
                    <span style="display: inline-block; background-color: <?= $typeBadge['bg'] ?>; color: <?= $typeBadge['color'] ?>; padding: 0.4rem 0.8rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                        <?= $typeBadge['icon'] ?> <?= $typeBadge['label'] ?>
                    </span>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <span style="display: inline-block; background-color: #f0f0f0; padding: 0.4rem 0.8rem; border-radius: 6px; font-weight: 600; font-size: 0.85rem; color: #1a1a1a;">
                        <?= reqLabel($row['request_status']) ?>
                    </span>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php if (!empty($row['commitment_number'])): ?>
                        <a href="/commitments/view.php?commitment_id=<?= (int)$row['commitment_id'] ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                            <?= htmlspecialchars($row['commitment_number']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php if (!empty($row['po_number'])): ?>
                        <a href="/po/view.php?po_id=<?= (int)$row['po_id'] ?>" style="color: #667eea; text-decoration: none; font-weight: 600;">
                            <?= htmlspecialchars($row['po_number']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php if (!empty($row['po_date'])): ?>
                        <small style="color: #666; font-weight: 500;"><?= date('d M Y', strtotime($row['po_date'])) ?></small>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle; text-align: right; font-weight: 600; color: #1a1a1a;">
                    <?php if ($row['po_total']): ?>
                        <?= money((float)$row['po_total']) ?>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle;">
                    <?php if (!empty($row['po_status'])): ?>
                        <?php
                            $icon = match ($row['po_status']) {
                                'Closed'    => '✅',
                                'Cancelled' => '❌',
                                default     => '⏳'
                            };
                            $badgeBgColor = match ($row['po_status']) {
                                'Closed'    => '#e8f5e9',
                                'Cancelled' => '#ffebee',
                                default     => '#fff3cd'
                            };
                            $badgeTextColor = match ($row['po_status']) {
                                'Closed'    => '#2e7d32',
                                'Cancelled' => '#c62828',
                                default     => '#b09500'
                            };
                        ?>
                        <span style="display: inline-block; background-color: <?= $badgeBgColor ?>; color: <?= $badgeTextColor ?>; padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">
                            <?= $icon ?> <?= htmlspecialchars($row['po_status']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 1rem; border: none; vertical-align: middle; text-align: center;">
                    <div style="display: flex; gap: 0.25rem; justify-content: center;">
                        <a href="/procurement/view.php?id=<?= (int)$row['request_id'] ?>"
                           style="display: inline-block; padding: 0.4rem 0.8rem; background-color: #e8eaf6; color: #3f51b5; text-decoration: none; border-radius: 4px; font-weight: 600; border: none; cursor: pointer;" title="View Request">👁️</a>
                        <?php if (!empty($row['po_id'])): ?>
                        <a href="/po/view.php?po_id=<?= (int)$row['po_id'] ?>"
                           style="display: inline-block; padding: 0.4rem 0.8rem; background-color: #fff3cd; color: #b09500; text-decoration: none; border-radius: 4px; font-weight: 600; border: none; cursor: pointer;" title="View PO">📄</a>
                        <?php endif; ?>
                        <?php if (
                            (isset($_SESSION['role_name']) && in_array(strtolower($_SESSION['role_name']), ['admin', 'superadmin'])) ||
                            (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['admin', 'superadmin']))
                        ): ?>
                        <form method="post" style="display:inline; margin-left:0.25rem;" onsubmit="return confirm('Are you sure you want to delete this request? This action cannot be undone.');">
                            <input type="hidden" name="delete_request_id" value="<?= (int)$row['request_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.4rem 0.8rem; border-radius: 4px; font-weight: 600; border: none; cursor: pointer;" title="Delete Request">🗑️</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
                <div class="alert alert-success border-0 mb-4" style="border-radius: 6px;" role="alert">
                    <div class="d-flex align-items-center gap-3">
                        <span style="font-size: 2rem;">🗑️</span>
                        <div>
                            <strong style="color: #1a1a1a;">Request deleted successfully.</strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="background-color: #f8f9fa; padding: 1.5rem; border-top: 1px solid #e0e0e0; border-radius: 0 0 8px 8px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <small style="color: #666; font-weight: 500;">
                Showing <strong><?= count($rows) ?></strong> records 
                <span style="color: #999;">•</span> 
                Page <strong><?= $page ?></strong> of <strong><?= max(1, ceil($totalRows / $perPage)) ?></strong>
            </small>
        </div>
        <small style="color: #999;">
            Total: <strong style="color: #1a1a1a;"><?= number_format((int)$kpi['total_requests']) ?> Requests</strong>
        </small>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($rows)): ?>
<!-- Modern Pagination -->
<div class="mt-4 mb-4">
    <?php
    $queryParams = $_GET;
    unset($queryParams['page']);

    renderPagination(
        $totalRows,
        $perPage,
        $page,
        $queryParams
    );
    ?>
</div>
<?php endif; ?>

<!-- Initialize Bootstrap Tooltips -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>