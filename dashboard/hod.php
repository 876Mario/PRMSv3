<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* ================================
   Advanced filter parameters
================================ */
$fReqNum  = trim($_GET['req_number'] ?? '');
$fBranch  = (int)($_GET['branch_id'] ?? 0);
$fRequestor = trim($_GET['requestor'] ?? '');
$fStatus  = trim($_GET['status'] ?? '');
$fFrom    = trim($_GET['from'] ?? '');
$fTo      = trim($_GET['to'] ?? '');
$fYear    = (int)($_GET['budget_year'] ?? 0);

/* Sorting */
$sortAllowed = ['request_number' => 'pr.request_number', 'estimated_value' => 'pr.estimated_value', 'request_date' => 'pr.created_at', 'branch_name' => 'b.branch_name', 'requestor_name' => 'u.full_name'];
$sortCol = $sortAllowed[$_GET['sort'] ?? ''] ?? 'pr.created_at';
$sortDir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

/* ================================
   Build WHERE clause for approval queue
================================ */
$pendingWhere = [
    "ra.entity_type = 'REQUEST'",
    "ra.role = 'HOD'",
    "ra.status = 'pending'",
    "UPPER(pr.status) NOT IN ('DECLINED','COMPLETED','AWARDED')",
];
$pendingParams = [];
if ($fReqNum !== '') {
    $pendingWhere[] = "pr.request_number LIKE :fReqNum";
    $pendingParams[':fReqNum'] = '%' . $fReqNum . '%';
}
if ($fBranch > 0) {
    $pendingWhere[] = "pr.branch_id = :fBranch";
    $pendingParams[':fBranch'] = $fBranch;
}
if ($fRequestor !== '') {
    $pendingWhere[] = "u.full_name LIKE :fRequestor";
    $pendingParams[':fRequestor'] = '%' . $fRequestor . '%';
}
if ($fStatus !== '') {
    $pendingWhere[] = "pr.status = :fStatus";
    $pendingParams[':fStatus'] = $fStatus;
}
if ($fFrom !== '') {
    $pendingWhere[] = "DATE(pr.created_at) >= :fFrom";
    $pendingParams[':fFrom'] = $fFrom;
}
if ($fTo !== '') {
    $pendingWhere[] = "DATE(pr.created_at) <= :fTo";
    $pendingParams[':fTo'] = $fTo;
}
if ($fYear > 0) {
    $pendingWhere[] = "YEAR(pr.created_at) = :fYear";
    $pendingParams[':fYear'] = $fYear;
}
$pendingWhereSQL = 'WHERE ' . implode(' AND ', $pendingWhere);

/* Requests awaiting HOD approval */
$stmt = $pdo->prepare("
    SELECT 
        pr.request_id, 
        pr.request_number, 
        pr.estimated_value, 
        pr.currency,
        pr.created_at,
        pr.status as request_status,
        pr.branch_id,
        pr.workflow_path,
        b.branch_name,
        ra.role as required_role,
        ra.stage_order,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    $pendingWhereSQL
    ORDER BY $sortCol $sortDir
");
foreach ($pendingParams as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Commitment approvals awaiting HOD — no longer needed (auto-approved) */
$pendingCommitments = [];

/* PO approvals awaiting HOD — no longer needed (auto-approved) */
$pendingPOs = [];

/* Over-threshold RFQs at GC_APPROVED awaiting award by HOD */
$rfqAwardStmt = $pdo->prepare("
    SELECT 
        r.rfq_id,
        r.rfq_number,
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        b.branch_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.status = 'GC_APPROVED'
      AND r.status != 'AWARDED'
    ORDER BY pr.created_at ASC
");
$rfqAwardStmt->execute();
$rfqAwards = $rfqAwardStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPending = count($requests) + count($pendingCommitments) + count($pendingPOs) + count($rfqAwards);

/* ================================
   Status count cards (all requests, not filtered)
================================ */
$statusCounts = [];
$countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM procurement_requests GROUP BY status");
foreach ($countStmt as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$cntPendingApprovals    = $statusCounts['SUBMITTED'] ?? 0;
$cntHodApproved         = $statusCounts['HOD_APPROVED'] ?? 0;
$cntDeclined            = $statusCounts['DECLINED'] ?? 0;
$cntAwaitingProcurement = $statusCounts['FUNDS_VERIFIED'] ?? 0;
$cntAwaitingFinance     = $statusCounts['COMMITMENTS_PENDING'] ?? 0;

/* Branches & budget years for filter dropdowns */
$branches   = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$budgetYears = $pdo->query("SELECT DISTINCT YEAR(request_date) AS yr FROM procurement_requests WHERE request_date IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);

/* All distinct statuses for filter dropdown */
$allStatuses = [
    'SUBMITTED' => 'Submitted', 'HOD_APPROVED' => 'HOD Approved',
    'FUNDS_VERIFIED' => 'Funds Verified', 'DIRECTOR_APPROVED' => 'Director Approved',
    'GC_APPROVED' => 'GC Approved', 'RFQ_LETTER_AVAILABLE' => 'RFQ Letters',
    'QUOTE_REVIEW_PENDING' => 'Quote Review', 'QUOTE_APPROVED' => 'Quote Selected',
    'COMMITMENTS_PENDING' => 'Commitment Pending', 'COMMITMENT_APPROVED' => 'Committed',
    'COMMITMENT_DECLINED' => 'Commitment Declined', 'PO_PENDING' => 'PO Created',
    'AWARDED' => 'Awarded', 'COMPLETED' => 'Completed',
    'DECLINED' => 'Declined', 'CANCELLED' => 'Cancelled',
];

/* Helper: build sort link preserving current filters */
function hodSortLink(string $col, string $currentSort, string $currentDir, string $label): string {
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir']  = ($currentSort === $col && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $arrow = '';
    if ($currentSort === $col) {
        $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    }
    $qs = http_build_query($params);
    return '<a href="/dashboard/hod.php?' . htmlspecialchars($qs) . '" style="color:inherit;text-decoration:none;">'
         . htmlspecialchars($label) . '<span style="font-size:0.7rem;opacity:0.8;">' . $arrow . '</span></a>';
}
$curSort = $_GET['sort'] ?? 'request_date';
$curDir  = strtoupper($_GET['dir'] ?? 'ASC');
?>

<style>
.hod-kpi-card {
    color: white;
    padding: 1.1rem 1rem;
    border-radius: 12px;
    text-decoration: none;
    display: block;
    transition: transform 0.2s, box-shadow 0.2s;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.hod-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.18) !important;
    color: white;
}
.hod-kpi-card h6 { margin: 0; font-weight: 600; opacity: 0.92; font-size: 0.82rem; }
.hod-kpi-card h3 { margin: 0.4rem 0 0 0; font-size: 2.1rem; font-weight: 700; }
.hod-kpi-card .kpi-icon { position: absolute; right: 0.8rem; top: 50%; transform: translateY(-50%); font-size: 2.2rem; opacity: 0.2; }
.hod-th-sort { cursor: pointer; user-select: none; white-space: nowrap; }
</style>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
  <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
    <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
      <span style="font-size: 1.5em; margin-right: 1rem;">👤</span>
      <h4 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">HOD <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Dashboard</span></h4>
    </div>

    <!-- Quick Nav Buttons -->
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
      <a href="/procurement/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600;">
        <i class="bi bi-list-task" style="margin-right: 0.5rem;"></i>All Requests
      </a>
      <a href="/commitments/list.php" style="background: white; border: 1px solid #fa709a; color: #fa709a; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600;">
        <i class="bi bi-pin-angle" style="margin-right: 0.5rem;"></i>Commitments
      </a>
      <a href="/po/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600;">
        <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Purchase Orders
      </a>
      <a href="/dashboard/approval_queue.php" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);">
        <i class="bi bi-clock-history" style="margin-right: 0.5rem;"></i>Approval Queue
      </a>
    </div>

    <!-- Clickable KPI Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
      <a href="/procurement/list.php?request_status=SUBMITTED" class="hod-kpi-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); box-shadow: 0 4px 12px rgba(250,112,154,0.3);">
        <h6>Pending Approvals</h6>
        <h3><?= $cntPendingApprovals ?></h3>
        <span class="kpi-icon">⏳</span>
      </a>
      <a href="/procurement/list.php?request_status=HOD_APPROVED" class="hod-kpi-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); box-shadow: 0 4px 12px rgba(102,126,234,0.3);">
        <h6>Approved Requests</h6>
        <h3><?= $cntHodApproved ?></h3>
        <span class="kpi-icon">✅</span>
      </a>
      <a href="/procurement/list.php?request_status=DECLINED" class="hod-kpi-card" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); box-shadow: 0 4px 12px rgba(245,87,108,0.3);">
        <h6>Rejected Requests</h6>
        <h3><?= $cntDeclined ?></h3>
        <span class="kpi-icon">❌</span>
      </a>
      <a href="/procurement/list.php?request_status=FUNDS_VERIFIED" class="hod-kpi-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); box-shadow: 0 4px 12px rgba(67,233,123,0.3);">
        <h6>Awaiting Procurement</h6>
        <h3><?= $cntAwaitingProcurement ?></h3>
        <span class="kpi-icon">⚙️</span>
      </a>
      <a href="/procurement/list.php?request_status=COMMITMENTS_PENDING" class="hod-kpi-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); box-shadow: 0 4px 12px rgba(79,172,254,0.3);">
        <h6>Awaiting Finance</h6>
        <h3><?= $cntAwaitingFinance ?></h3>
        <span class="kpi-icon">💰</span>
      </a>
      <a href="/procurement/list.php" class="hod-kpi-card" style="background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%); box-shadow: 0 4px 12px rgba(161,140,209,0.3);">
        <h6>Total Pending Items</h6>
        <h3><?= $totalPending ?></h3>
        <span class="kpi-icon">📊</span>
      </a>
    </div>

    <!-- Advanced Filter Panel -->
    <details style="margin-bottom: 1.5rem;" <?= (!empty($fReqNum) || $fBranch || !empty($fRequestor) || !empty($fStatus) || !empty($fFrom) || !empty($fTo) || $fYear) ? 'open' : '' ?>>
      <summary style="cursor:pointer; font-weight:700; color:#667eea; font-size:0.95rem; padding: 0.5rem 0; list-style:none; display:flex; align-items:center; gap:0.5rem;">
        <i class="bi bi-funnel-fill"></i> Advanced Search &amp; Filter
        <span style="font-size:0.75rem; color:#999; font-weight:400; margin-left:0.5rem;">(click to expand)</span>
      </summary>
      <div style="background:#f8f9fa; border-radius:8px; padding:1.25rem; margin-top:0.75rem; border:1px solid #e0e0e0;">
        <form method="get" class="row g-3">
          <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-muted">Request Number</label>
            <input type="text" name="req_number" value="<?= htmlspecialchars($fReqNum) ?>" placeholder="e.g. PR001" class="form-control form-control-sm">
          </div>
          <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-muted">Department</label>
            <select name="branch_id" class="form-select form-select-sm">
              <option value="">All Departments</option>
              <?php foreach ($branches as $br): ?>
                <option value="<?= $br['branch_id'] ?>" <?= $fBranch == $br['branch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['branch_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-muted">Requestor</label>
            <input type="text" name="requestor" value="<?= htmlspecialchars($fRequestor) ?>" placeholder="Name" class="form-control form-control-sm">
          </div>
          <div class="col-md-2 col-sm-4">
            <label class="form-label small fw-bold text-muted">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="">All Statuses</option>
              <?php foreach ($allStatuses as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-1 col-sm-3">
            <label class="form-label small fw-bold text-muted">From</label>
            <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-1 col-sm-3">
            <label class="form-label small fw-bold text-muted">To</label>
            <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-1 col-sm-3">
            <label class="form-label small fw-bold text-muted">Budget Year</label>
            <select name="budget_year" class="form-select form-select-sm">
              <option value="">All Years</option>
              <?php foreach ($budgetYears as $yr): ?>
                <option value="<?= $yr ?>" <?= $fYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-1 col-sm-3 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1" style="background:linear-gradient(135deg,#667eea,#764ba2);border:none;">
              <i class="bi bi-search"></i> Filter
            </button>
            <a href="/dashboard/hod.php" class="btn btn-outline-secondary btn-sm" title="Clear filters"><i class="bi bi-arrow-clockwise"></i></a>
          </div>
        </form>
      </div>
    </details>

    <!-- Pending Actions Widget -->
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>

    <!-- Pending Request Approvals (filterable, sortable) -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📋 Pending Request Approvals <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($requests) ?></span></h6>
      </div>
      <?php if (empty($requests)): ?>
        <div style="text-align: center; color: #999; padding: 2rem 0;"><span style="font-size: 1.5em;">✅</span><br><span style="display: block; margin-top: 0.5rem;">No pending request approvals</span></div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
            <thead style="background: #f5f5f5;">
              <tr>
                <th class="hod-th-sort" style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= hodSortLink('request_number', $curSort, $curDir, 'Request #') ?></th>
                <th class="hod-th-sort" style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= hodSortLink('requestor_name', $curSort, $curDir, 'Requestor') ?></th>
                <th class="hod-th-sort" style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= hodSortLink('branch_name', $curSort, $curDir, 'Branch') ?></th>
                <th class="hod-th-sort" style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= hodSortLink('estimated_value', $curSort, $curDir, 'Amount') ?></th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Status</th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Workflow</th>
                <th class="hod-th-sort" style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= hodSortLink('request_date', $curSort, $curDir, 'Date') ?></th>
                <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $r): ?>
                <tr style="border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='white'">
                  <td style="padding: 0.75rem 1rem; font-weight: 600;">
                    <a href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>" style="color:#3f51b5; text-decoration:none; font-weight:700;">
                      <?= htmlspecialchars($r['request_number']) ?>
                    </a>
                  </td>
                  <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($r['requestor_name'] ?? 'N/A') ?></td>
                  <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($r['branch_name'] ?? 'N/A') ?></td>
                  <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;">
                    <?= htmlspecialchars(normalizeCurrency($r['currency'] ?? 'JMD')) ?> <?= number_format($r['estimated_value'], 2) ?>
                  </td>
                  <td style="padding: 0.75rem 1rem;"><?= statusBadge($r['request_status']) ?></td>
                  <td style="padding: 0.75rem 1rem;"><?= getWorkflowBadgeHtml(['workflow_path' => $r['workflow_path'] ?? 'STANDARD']) ?></td>
                  <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                  <td style="padding: 0.75rem 1rem; text-align: center;">
                    <a href="/procurement/view.php?id=<?= (int)$r['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">
                      Review
                    </a>
                    &nbsp;
                    <a href="/procurement/approve_hod.php?id=<?= (int)$r['request_id'] ?>" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;" title="Quick Approve">
                      ✅ Approve
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pending Commitment Approvals -->
    <?php if (!empty($pendingCommitments)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">💰 Pending Commitment Approvals <span style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($pendingCommitments) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Commitment #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Total</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingCommitments as $c): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($c['commitment_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($c['request_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($c['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;">JMD <?= number_format($c['commitment_total'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/commitments/view.php?id=<?= $c['commitment_id'] ?>" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Pending PO Approvals -->
    <?php if (!empty($pendingPOs)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📄 Pending PO Approvals <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($pendingPOs) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">PO #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Total</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingPOs as $po): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($po['po_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($po['request_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($po['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;">JMD <?= number_format($po['po_total'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($po['created_at'])) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/po/view.php?po_id=<?= $po['po_id'] ?>" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- RFQ Awards Ready -->
    <?php if (!empty($rfqAwards)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">🏆 RFQs Ready for Award <span style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($rfqAwards) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">RFQ #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Est. Value</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rfqAwards as $rq): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='white'">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($rq['rfq_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;">
                  <a href="/procurement/view.php?id=<?= (int)$rq['request_id'] ?>" style="color:#3f51b5; text-decoration:none; font-weight:700;"><?= htmlspecialchars($rq['request_number']) ?></a>
                </td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($rq['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($rq['currency'] ?? 'JMD')) ?> <?= number_format($rq['estimated_value'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/rfq/view.php?id=<?= (int)$rq['rfq_id'] ?>" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Award</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/branch_summary.php"; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/recent_activity.php"; ?>
    </div>

  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>

/* Self-heal: seed any missing approval chains for SUBMITTED requests */
ensureApprovalChainsExist($pdo);

/* Requests awaiting HOD approval */
$stmt = $pdo->prepare("
    SELECT 
        pr.request_id, 
        pr.request_number, 
        pr.estimated_value, 
        pr.currency,
        pr.created_at,
        pr.status as request_status,
        pr.branch_id,
        b.branch_name,
        ra.role as required_role,
        ra.stage_order,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = 'HOD'
      AND ra.status = 'pending'
      AND UPPER(pr.status) NOT IN ('DECLINED', 'COMPLETED', 'AWARDED')
    ORDER BY pr.created_at ASC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Commitment approvals awaiting HOD — no longer needed (auto-approved) */
$pendingCommitments = [];

/* PO approvals awaiting HOD — no longer needed (auto-approved) */
$pendingPOs = [];

/* Over-threshold RFQs at GC_APPROVED awaiting award by HOD */
$rfqAwardStmt = $pdo->prepare("
    SELECT 
        r.rfq_id,
        r.rfq_number,
        pr.request_id,
        pr.request_number,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        b.branch_name
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    WHERE pr.status = 'GC_APPROVED'
      AND r.status != 'AWARDED'
    ORDER BY pr.created_at ASC
");
$rfqAwardStmt->execute();
$rfqAwards = $rfqAwardStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPending = count($requests) + count($pendingCommitments) + count($pendingPOs) + count($rfqAwards);
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
  <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
    <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
      <span style="font-size: 1.5em; margin-right: 1rem;">👤</span>
      <h4 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">HOD <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">Dashboard</span></h4>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
      <a href="/procurement/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-list-task" style="margin-right: 0.5rem;"></i>All Requests
      </a>
      <a href="/commitments/list.php" style="background: white; border: 1px solid #fa709a; color: #fa709a; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-pin-angle" style="margin-right: 0.5rem;"></i>Commitments
      </a>
      <a href="/po/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Purchase Orders
      </a>
      <a href="/dashboard/approval_queue.php" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);">
        <i class="bi bi-clock-history" style="margin-right: 0.5rem;"></i>Approval Queue
      </a>
    </div>

    <!-- KPI Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
      <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 1rem; border-radius: 12px;">
        <h6 style="margin: 0; font-weight: 600; opacity: 0.9;">Total Pending</h6>
        <h3 style="margin: 0.5rem 0 0 0; font-size: 2rem; font-weight: 700;"><?= $totalPending ?></h3>
      </div>
      <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 12px;">
        <h6 style="margin: 0; font-weight: 600; opacity: 0.9;">Requests</h6>
        <h3 style="margin: 0.5rem 0 0 0; font-size: 2rem; font-weight: 700;"><?= count($requests) ?></h3>
      </div>
      <div style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 1rem; border-radius: 12px;">
        <h6 style="margin: 0; font-weight: 600; opacity: 0.9;">RFQ Awards</h6>
        <h3 style="margin: 0.5rem 0 0 0; font-size: 2rem; font-weight: 700;"><?= count($rfqAwards) ?></h3>
      </div>
    </div>

    <!-- Pending Actions Widget -->
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>

    <!-- Pending Request Approvals -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📋 Pending Request Approvals <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($requests) ?></span></h6>
      </div>
      <?php if (empty($requests)): ?>
        <div style="text-align: center; color: #999; padding: 2rem 0;"><span style="font-size: 1.5em;">✅</span><br><span style="display: block; margin-top: 0.5rem;">No pending request approvals</span></div>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
            <thead style="background: #f5f5f5;">
              <tr>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Requestor</th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
                <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Amount</th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Status</th>
                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
                <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $r): ?>
                <tr style="border-bottom: 1px solid #f0f0f0;">
                  <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($r['request_number']) ?></td>
                  <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($r['requestor_name'] ?? 'N/A') ?></td>
                  <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($r['branch_name'] ?? 'N/A') ?></td>
                  <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($r['currency'] ?? 'JMD')) ?> <?= number_format($r['estimated_value'], 2) ?></td>
                  <td style="padding: 0.75rem 1rem;"><span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($r['request_status']) ?></span></td>
                  <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                  <td style="padding: 0.75rem 1rem; text-align: center;">
                    <a href="/procurement/view.php?id=<?= $r['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pending Commitment Approvals -->
    <?php if (!empty($pendingCommitments)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">💰 Pending Commitment Approvals <span style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($pendingCommitments) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Commitment #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Total</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingCommitments as $c): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($c['commitment_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($c['request_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($c['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;">JMD <?= number_format($c['commitment_total'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/commitments/view.php?id=<?= $c['commitment_id'] ?>" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Pending PO Approvals -->
    <?php if (!empty($pendingPOs)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📄 Pending PO Approvals <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($pendingPOs) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">PO #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Total</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingPOs as $po): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($po['po_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($po['request_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($po['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;">JMD <?= number_format($po['po_total'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($po['created_at'])) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/po/view.php?po_id=<?= $po['po_id'] ?>" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- RFQ Awards Ready -->
    <?php if (!empty($rfqAwards)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
      <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">🏆 RFQs Ready for Award <span style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= count($rfqAwards) ?></span></h6>
      </div>
      <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
          <thead style="background: #f5f5f5;">
            <tr>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">RFQ #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
              <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Est. Value</th>
              <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rfqAwards as $rq): ?>
              <tr style="border-bottom: 1px solid #f0f0f0;">
                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($rq['rfq_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($rq['request_number']) ?></td>
                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($rq['branch_name'] ?? 'N/A') ?></td>
                <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($rq['currency'] ?? 'JMD')) ?> <?= number_format($rq['estimated_value'], 2) ?></td>
                <td style="padding: 0.75rem 1rem; text-align: center;">
                  <a href="/rfq/view.php?id=<?= $rq['rfq_id'] ?>" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Award</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
          <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/branch_summary.php"; ?>
      
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?>
      
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/recent_activity.php"; ?>
    </div>
    

  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
