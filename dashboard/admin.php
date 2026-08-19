

<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">

    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <img src="/logo/cropped-Logo.png" alt="Government Chemist Logo" style="height: 36px; width: auto; margin-right: 1rem;">
            <div>
                <h4 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Admin Dashboard <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">🔧</span></h4>
                <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">
                    Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?> • 
                    <?php
                      $displayRole = match ($_SESSION['role_name'] ?? '') {
                        'SuperAdmin' => 'System Administrator',
                        'HOD' => 'Head of Department',
                        default => $_SESSION['role_name'] ?? 'User'
                      };
                    ?>
                    <?= htmlspecialchars($displayRole) ?> • <?= date('l, j F Y') ?>
                </small>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 2px solid #e0e0e0;">
            <a href="/procurement/add.php" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);">
                <i class="bi bi-plus-circle" style="margin-right: 0.5rem;"></i>New Procurement
            </a>
            <a href="/procurement/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-list-task" style="margin-right: 0.5rem;"></i>All Requests
            </a>
            <a href="/commitments/list.php" style="background: white; border: 1px solid #fa709a; color: #fa709a; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-pin-angle" style="margin-right: 0.5rem;"></i>Commitments
            </a>
            <a href="/po/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Purchase Orders
            </a>
            <a href="/invoice/list.php" style="background: white; border: 1px solid #43e97b; color: #43e97b; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark" style="margin-right: 0.5rem;"></i>Invoices
            </a>
            <a href="/payment/list.php" style="background: white; border: 1px solid #667eea; color: #667eea; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-credit-card" style="margin-right: 0.5rem;"></i>Payments
            </a>
            <a href="/dashboard/monthly.php" style="background: white; border: 1px solid #43e97b; color: #43e97b; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-bar-chart-line" style="margin-right: 0.5rem;"></i>Monthly
            </a>
            <a href="/dashboard/management.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-person-badge" style="margin-right: 0.5rem;"></i>Management
            </a>
            <a href="/rfq/list.php" style="background: white; border: 1px solid #B62FFA; color: #B62FFA; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>RFQs
            </a>
            <a href="/dashboard/approval_queue.php" style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(245, 87, 108, 0.3);">
                <i class="bi bi-clock-history" style="margin-right: 0.5rem;"></i>Approval Queue
            </a>
               <a href="/users/list.php" style="background: white; border: 1px solid #4facfe; color: #4facfe; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Users
            </a>
               <a href="/admin/settings.php" style="background: white; border: 1px solid #FA512F; color: #FA512F; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Settings
            </a>
               <a href="/tools/email_diagnostic.php" style="background: white; border: 1px solid #2FFA51; color: #2FFA51; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-file-earmark-text" style="margin-right: 0.5rem;"></i>Email Diagnostics
            </a>
            <?php if (has_permission('access_test_suite')): ?>
            <a href="/admin/test_suite.php" style="background: white; border: 1px solid #764ba2; color: #764ba2; padding: 0.625rem 1.25rem; border-radius: 8px; text-decoration: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                <i class="bi bi-bug" style="margin-right: 0.5rem;"></i>Test Suite
            </a>
            <?php endif; ?>
            <button type="button" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 0.625rem 1.25rem; border-radius: 8px; border: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 2px 8px rgba(250, 112, 154, 0.3);" onclick="location.reload()" title="Refresh page">
                <i class="bi bi-arrow-clockwise" style="margin-right: 0.5rem;"></i>Refresh
            </button>
        </div>
    </div>

    <!-- Row 1: KPIs + Alerts + Pipeline -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/kpis.php"; ?>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/alerts.php"; ?>
        </div>
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/pipeline.php"; ?>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/monthly_trend.php"; ?>
        </div>
    </div>

    <!-- Pending Actions -->
    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem; margin-bottom: 2rem;">
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/pending_actions.php'; ?>
        </div>
    </div>

    <!-- Row 2: Branch Summary + Top Vendors -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/branch_summary.php"; ?>
        </div>
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/top_vendors.php"; ?>
        </div>
    </div>

    <!-- Row 3: Financial Widgets -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/payment_progress.php"; ?>
        </div>
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/commitment_utilization.php"; ?>
        </div>
        <div>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/overdue_invoices.php"; ?>
            <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/po_variations.php"; ?>
        </div>
    </div>

    <!-- Row 4: Recent Activity -->
    <div style="margin-bottom: 2rem;">
        <?php include $_SERVER['DOCUMENT_ROOT']."/dashboard/widgets/recent_activity.php"; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>

