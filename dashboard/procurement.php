<?php
$REQUIRE_PERMISSION = 'view_procurement_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$procStats = $pdo->query("
  SELECT
    SUM(CASE WHEN status IN ('PROCUREMENT_STAGE','RFQ_LETTER_AVAILABLE') THEN 1 ELSE 0 END) AS pending_rfqs,
    SUM(CASE WHEN status = 'RFQ_LETTER_AVAILABLE' THEN 1 ELSE 0 END) AS awaiting_vendor_addition,
    SUM(CASE WHEN status = 'ADDITIONAL_QUOTATIONS_REQUIRED' THEN 1 ELSE 0 END) AS returned_by_requestor,
    SUM(CASE WHEN status = 'QUOTE_REVIEW_PENDING' THEN 1 ELSE 0 END) AS pending_quote_reviews,
    SUM(CASE WHEN status = 'PO_PENDING' THEN 1 ELSE 0 END) AS pending_purchase_orders,
    SUM(CASE WHEN status NOT IN ('DRAFT','COMPLETED','DECLINED','CANCELLED','PAUSED') THEN 1 ELSE 0 END) AS outstanding_tasks
  FROM procurement_requests
")->fetch(PDO::FETCH_ASSOC) ?: [];

$unreadNotifications = class_exists('NotificationService')
    ? NotificationService::countUnread((int)($_SESSION['user_id'] ?? 0))
    : 0;
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
    <div style="display: flex; align-items: center;">
      <img src="/logo/cropped-Logo.png" alt="Government Chemist Logo" style="height: 42px; margin-right: 1rem;">
      <div>
        <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #333;">📋 Procurement Dashboard</h2>
        <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">
          Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> • 
          <?php
          $displayRole = match ($_SESSION['role_name'] ?? '') {
              'Procurement' => 'Procurement Officer',
              'Finance' => 'Finance Officer',
              'Admin', 'SuperAdmin' => 'System Administrator',
              'HOD' => 'Head of Department',
              default => $_SESSION['role_name'] ?? 'User'
          };
          ?>
          <?= htmlspecialchars($displayRole) ?> • <?= date('l, j F Y') ?>
        </small>
      </div>
    </div>
    <div style="display: flex; gap: 0.75rem;">
      <a href="/dashboard/metrics.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; transition: all 0.3s ease; cursor: pointer;">💰 Metrics</a>
      <a href="/dashboard/monthly.php" style="background: white; border: 1px solid #43e97b; color: #43e97b; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; transition: all 0.3s ease; cursor: pointer;">📈 Monthly Trend</a>
    </div>
  </div>

  <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
    <a href="/procurement/add.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);">➕ New Procurement</a>
    <a href="/procurement/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">📋 All Requests</a>
    <a href="/commitments/list.php" style="background: white; border: 1px solid #fa709a; color: #fa709a; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">📌 Commitments</a>
    <a href="/po/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">📑 Purchase Orders</a>
    <a href="/invoice/list.php" style="background: white; border: 1px solid #43e97b; color: #43e97b; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">📄 Invoices</a>
    <a href="/rfq/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">📄 RFQs</a>
    <a href="/dashboard/approval_queue.php" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);">⏳ Approval Queue</a>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">My Pending RFQs</div><div class="fs-4 fw-bold"><?= (int)($procStats['pending_rfqs'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">RFQs Awaiting Vendor Addition</div><div class="fs-4 fw-bold"><?= (int)($procStats['awaiting_vendor_addition'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">RFQs Returned By Requestors</div><div class="fs-4 fw-bold text-warning"><?= (int)($procStats['returned_by_requestor'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Quote Reviews</div><div class="fs-4 fw-bold"><?= (int)($procStats['pending_quote_reviews'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Purchase Orders</div><div class="fs-4 fw-bold"><?= (int)($procStats['pending_purchase_orders'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Outstanding Procurement Tasks</div><div class="fs-4 fw-bold"><?= (int)($procStats['outstanding_tasks'] ?? 0) ?></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Unread Notifications</div><div class="fs-4 fw-bold"><?= (int)$unreadNotifications ?></div></div></div>
  </div>

  <div class="alert alert-danger"><strong>URGENT:</strong> Overdue, escalated, and returned workflows.</div>
  <div class="alert alert-warning"><strong>HIGH PRIORITY:</strong> Pending reviews and approvals.</div>
  <div class="alert alert-info mb-4"><strong>NORMAL:</strong> Open and recently assigned procurement work.</div>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/kpis.php'; ?>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/alerts.php'; ?>
  </div>

  <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
  </div>

  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pipeline.php'; ?>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/commitment_utilization.php'; ?>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/payment_progress.php'; ?>
  </div>

  <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/recent_activity.php'; ?>
  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
