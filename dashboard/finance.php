<?php

$REQUIRE_PERMISSION = 'view_finance_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

$financeStats = $pdo->query("
    SELECT
      SUM(CASE WHEN request_type = 'PETTY_CASH' AND status = 'SUBMITTED' THEN 1 ELSE 0 END) AS pending_fund_verifications,
      SUM(CASE WHEN request_type = 'PETTY_CASH' AND status IN ('FUNDS_VERIFIED','FINANCE_AUTHORIZED') THEN 1 ELSE 0 END) AS pending_disbursements,
      SUM(CASE WHEN request_type = 'PETTY_CASH' AND status IN ('SUBMITTED','FUNDS_VERIFIED','FINANCE_AUTHORIZED','DISBURSED') THEN 1 ELSE 0 END) AS pending_petty_cash,
      SUM(CASE WHEN status IN ('COMMITMENTS_PENDING','INVOICE_RECEIVED') THEN 1 ELSE 0 END) AS outstanding_finance_tasks,
      SUM(CASE WHEN DATEDIFF(NOW(), updated_at) >= 7 AND status NOT IN ('COMPLETED','DECLINED','CANCELLED','PAUSED') THEN 1 ELSE 0 END) AS urgent_items
    FROM procurement_requests
")->fetch(PDO::FETCH_ASSOC) ?: [];

$unreadNotifications = class_exists('NotificationService')
    ? NotificationService::countUnread((int)($_SESSION['user_id'] ?? 0))
    : 0;


/* 🧱 ONLY AFTER AUTH IS PASSED */
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
  <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
    <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
      <img src="/logo/cropped-Logo.png"
           alt="Government Chemist Logo"
           style="height: 36px; width: auto; margin-right: 1rem;">
      <div>
        <h4 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Finance Dashboard <span style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">💰</span></h4>
        <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">
          Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> • 
          <?php
            $displayRole = match ($_SESSION['role_name'] ?? '') {
              'Finance' => 'Finance Officer',
              'Admin' => 'System Administrator',
              'SuperAdmin' => 'System Administrator',
              default => $_SESSION['role_name'] ?? 'User'
            };
          ?>
          <?= htmlspecialchars($displayRole) ?> • <?= date('l, j F Y') ?>
        </small>
      </div>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
      <a href="/procurement/add.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);">
        <i class="bi bi-plus-circle" style="margin-right: 0.5rem;"></i> New Procurement
      </a>
      <a href="/procurement/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-list-task" style="margin-right: 0.5rem;"></i> All Requests
      </a>
      <a href="/commitments/list.php" style="background: white; border: 1px solid #fa709a; color: #fa709a; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-pin-angle" style="margin-right: 0.5rem;"></i> Commitments
      </a>
      <a href="/po/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i> Purchase Orders
      </a>
      <a href="/invoice/list.php" style="background: white; border: 1px solid #43e97b; color: #43e97b; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-file-earmark" style="margin-right: 0.5rem;"></i> Invoices
      </a>
      <a href="/payment/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-credit-card" style="margin-right: 0.5rem;"></i> Payments
      </a>
      <a href="/rfq/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
        <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i> RFQs
      </a>
      <a href="/dashboard/approval_queue.php" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);">
        <i class="bi bi-clock-history" style="margin-right: 0.5rem;"></i> Approval Queue
      </a>
      <button type="button"
              style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; border: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 8px rgba(250, 112, 154, 0.3);"
              title="Refresh page"
              onclick="location.reload()">
        <i class="bi bi-arrow-clockwise" style="margin-right: 0.5rem;"></i> Refresh
      </button>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Fund Verifications</div><div class="fs-4 fw-bold"><?= (int)($financeStats['pending_fund_verifications'] ?? 0) ?></div></div></div>
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Petty Cash Requests</div><div class="fs-4 fw-bold"><?= (int)($financeStats['pending_petty_cash'] ?? 0) ?></div></div></div>
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Disbursements</div><div class="fs-4 fw-bold"><?= (int)($financeStats['pending_disbursements'] ?? 0) ?></div></div></div>
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Outstanding Finance Tasks</div><div class="fs-4 fw-bold"><?= (int)($financeStats['outstanding_finance_tasks'] ?? 0) ?></div></div></div>
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Items Requiring Immediate Action</div><div class="fs-4 fw-bold text-danger"><?= (int)($financeStats['urgent_items'] ?? 0) ?></div></div></div>
      <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Unread Notifications</div><div class="fs-4 fw-bold"><?= (int)$unreadNotifications ?></div></div></div>
    </div>

    <div class="alert alert-danger"><strong>URGENT:</strong> Overdue and escalated finance workflows.</div>
    <div class="alert alert-warning"><strong>HIGH PRIORITY:</strong> Pending verifications and disbursements.</div>
    <div class="alert alert-info mb-4"><strong>NORMAL:</strong> Open assigned finance activities.</div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include __DIR__."/widgets/kpis.php"; ?>
      <?php include __DIR__."/widgets/alerts.php"; ?>
      <?php include __DIR__."/widgets/payment_progress.php"; ?>
    </div>

    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include __DIR__."/widgets/overdue_invoices.php"; ?>
      <?php include __DIR__."/widgets/commitment_utilization.php"; ?>
      <?php include __DIR__."/widgets/po_variations.php"; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
      <?php include __DIR__."/widgets/branch_summary.php"; ?>
      <?php include __DIR__."/widgets/monthly_trend.php"; ?>
    </div>

    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
      <?php include __DIR__."/widgets/recent_activity.php"; ?>
    </div>
  </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
