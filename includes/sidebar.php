<?php
$currentPage = $_SERVER['REQUEST_URI'];
$userName = $_SESSION['full_name'] ?? 'User';
$roleName = $_SESSION['role_name'] ?? '';
$isActing = isActingRole();
$actingRoles = isset($pdo) ? getActingRoles($pdo, $_SESSION['user_id'] ?? 0) : [];

function active($url, $currentPage) {
    return strpos($currentPage, $url) !== false ? 'sidebar-active' : '';
}

function isCollapsibleActive($urls, $currentPage) {
    foreach ($urls as $url) {
        if (strpos($currentPage, $url) !== false) return true;
    }
    return false;
}
?>

<div class="sidebar-inner pt-2 text-white">

    <!-- BRAND -->
    <div class="sidebar-brand text-center mb-2 pb-3 border-bottom border-secondary">
        <a href="/dashboard/index.php" class="text-decoration-none text-white d-flex align-items-center justify-content-center gap-2">
            <img src="/logo/cropped-Logo.png" alt="Logo" style="height:30px; filter: brightness(0) invert(1);">
            <span class="fw-bold fs-5 tracking-wide">DGC IPAMS</span>
        </a>
    </div>

    <!-- USER PROFILE SECTION -->
    <div class="sidebar-user text-center mb-3 pb-3 border-bottom border-secondary px-3">
        <div class="sidebar-avatar mx-auto mb-2">
            <i class="bi bi-person-circle" style="font-size: 2.2rem; color: rgba(255,255,255,0.7);"></i>
        </div>
        <div class="fw-semibold small text-truncate"><?= htmlspecialchars($userName) ?></div>
        <?php if ($isActing): ?>
            <span class="badge bg-warning text-dark mt-1" style="font-size: 0.7rem;">
                <i class="bi bi-lightning-fill me-1"></i>Acting: <?= htmlspecialchars($roleName) ?>
            </span>
            <br>
            <small class="text-white-50" style="font-size: 0.68rem;">
                Primary: <?= htmlspecialchars(getPrimaryRoleName()) ?>
            </small>
        <?php else: ?>
            <span class="badge mt-1" style="background: rgba(255,255,255,0.15); font-size: 0.7rem;">
                <?= htmlspecialchars($roleName) ?>
            </span>
        <?php endif; ?>

        <?php if ($isActing || !empty($actingRoles)): ?>
        <div class="mt-2 d-flex flex-column gap-1">
            <?php if ($isActing): ?>
                <form method="POST" action="/auth/switch_role.php">
                    <input type="hidden" name="action" value="revert">
                    <button type="submit" class="btn btn-outline-light btn-sm w-100" style="font-size: 0.72rem;">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Revert to <?= htmlspecialchars(getPrimaryRoleName()) ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php foreach ($actingRoles as $ar): ?>
                <?php if ($ar['acting_role_id'] != ($_SESSION['role_id'] ?? 0)): ?>
                <form method="POST" action="/auth/switch_role.php">
                    <input type="hidden" name="action" value="switch">
                    <input type="hidden" name="acting_role_id" value="<?= $ar['acting_role_id'] ?>">
                    <button type="submit" class="btn btn-outline-warning btn-sm w-100" style="font-size: 0.72rem;" title="<?= htmlspecialchars($ar['reason'] ?? '') ?>">
                        <i class="bi bi-lightning me-1"></i>Act as <?= htmlspecialchars($ar['role_name']) ?>
                    </button>
                </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <ul class="nav flex-column px-2">

        <!-- ===== REQUESTOR DASHBOARD ===== -->
        <?php if ($roleName === 'Requestor'): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">REQUESTOR</div>
            <?php if (has_permission('submit_own_request')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/procurement/add', $currentPage) ?>" href="/procurement/add.php">
                <i class="bi bi-plus-circle me-2"></i>New Request
            </a>
            <?php endif; ?>
            <?php if (has_permission('view_own_requests')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/procurement/my_requests', $currentPage) ?>" href="/procurement/my_requests.php">
                <i class="bi bi-clipboard-check me-2"></i>My Requests
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>


        <!-- ===== HOD DASHBOARD ===== -->
        <?php if ($roleName === 'HOD'): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">HOD</div>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/hod', $currentPage) ?>" href="/dashboard/hod.php">
                <i class="bi bi-speedometer2 me-2"></i>HOD Dashboard
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/procurement', $currentPage) ?>" href="/procurement/list.php">
                <i class="bi bi-clipboard-check me-2"></i>All Requests
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/commitments', $currentPage) ?>" href="/commitments/list.php">
                <i class="bi bi-folder2 me-2"></i>Commitments
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/po', $currentPage) ?>" href="/po/list.php">
                <i class="bi bi-receipt me-2"></i>Purchase Orders
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== DIRECTOR PROCUREMENT DASHBOARD ===== -->
        <?php if ($roleName === 'Director Procurement'): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">DIRECTOR PROCUREMENT</div>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/director_procurement', $currentPage) ?>" href="/dashboard/director_procurement.php">
                <i class="bi bi-speedometer2 me-2"></i>Director Dashboard
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/procurement', $currentPage) ?>" href="/procurement/list.php">
                <i class="bi bi-clipboard-check me-2"></i>All Requests
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/commitments', $currentPage) ?>" href="/commitments/list.php">
                <i class="bi bi-folder2 me-2"></i>Commitments
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/po', $currentPage) ?>" href="/po/list.php">
                <i class="bi bi-receipt me-2"></i>Purchase Orders
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== PROPERTY MANAGEMENT OFFICER DASHBOARD ===== -->
        <?php if ($roleName === 'Property Management Officer'): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">PROPERTY MANAGEMENT</div>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/property_management_officer', $currentPage) ?>" href="/dashboard/property_management_officer.php">
                <i class="bi bi-building-gear me-2"></i>PMO Dashboard
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/procurement/list', $currentPage) ?>" href="/procurement/list.php">
                <i class="bi bi-clipboard-check me-2"></i>All Requests
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/po/list', $currentPage) ?>" href="/po/list.php">
                <i class="bi bi-receipt me-2"></i>Purchase Orders
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== CORE SECTION ===== -->
        <li class="nav-item mt-2">
            <a class="nav-link text-white sidebar-link <?= active('/dashboard', $currentPage) ?>"
               href="/dashboard/index.php">
                <i class="bi bi-house me-2"></i>Dashboard
            </a>
        </li>

        <!-- ===== PROCUREMENT WORKFLOW ===== -->
        <?php if (has_permission('view_requests')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">PROCUREMENT</div>
            <a class="nav-link text-white sidebar-link <?= active('/procurement', $currentPage) ?>"
               href="/procurement/list.php">
                <i class="bi bi-file-earmark-text me-2"></i>Requests
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link text-white sidebar-link <?= active('/rfq', $currentPage) ?>"
               href="/rfq/list.php">
                <i class="bi bi-clipboard-data me-2"></i>RFQ / Evaluation
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== REIMBURSEMENT & PETTY CASH ===== -->
        <?php if (has_permission('submit_own_request') || has_permission('view_requests') || has_permission('approve_reimbursement') || has_permission('approve_petty_cash')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">SPECIAL REQUESTS</div>

            <?php if (has_permission('submit_own_request')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/reimbursement/add', $currentPage) ?>"
               href="/reimbursement/add.php">
                <i class="bi bi-cash-coin me-2"></i>Submit Reimbursement
            </a>

            <a class="nav-link text-white sidebar-link <?= active('/petty_cash/add', $currentPage) ?>"
               href="/petty_cash/add.php">
                <i class="bi bi-piggy-bank me-2"></i>Submit Petty Cash
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_requests') || has_permission('approve_reimbursement')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/reimbursement/list', $currentPage) ?>"
               href="/reimbursement/list.php">
                <i class="bi bi-cash-stack me-2"></i>Reimbursements
                <?php
                try {
                    $reimbPending = $pdo->query("
                        SELECT COUNT(*)
                        FROM procurement_requests
                        WHERE request_type='REIMBURSEMENT'
                        AND status = 'SUBMITTED'
                    ")->fetchColumn();
                } catch (Throwable $e) {
                    $reimbPending = 0;
                }
                if ($reimbPending > 0):
                ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><?= $reimbPending ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_requests') || has_permission('approve_petty_cash')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/petty_cash/list', $currentPage) ?>"
               href="/petty_cash/list.php">
                <i class="bi bi-wallet2 me-2"></i>Petty Cash
                <?php
                try {
                    $pettyCashPending = $pdo->query("
                        SELECT COUNT(*)
                        FROM procurement_requests
                        WHERE request_type='PETTY_CASH'
                        AND status = 'SUBMITTED'
                    ")->fetchColumn();
                } catch (Throwable $e) {
                    $pettyCashPending = 0;
                }
                if ($pettyCashPending > 0):
                ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $pettyCashPending ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <?php if (has_permission('view_commitments')): ?>
        <li class="nav-item">
            <a class="nav-link text-white sidebar-link <?= active('/commitments', $currentPage) ?>"
               href="/commitments/list.php">
                <i class="bi bi-folder2-open me-2"></i>Commitments
                <?php
                try {
                    $pending = $pdo->query("
                        SELECT COUNT(*)
                        FROM request_approvals
                        WHERE entity_type='COMMITMENT'
                        AND status='pending'
                    ")->fetchColumn();
                } catch (Throwable $e) {
                    $pending = 0;
                }
                if ($pending > 0):
                ?>
                    <span class="badge bg-danger ms-1" style="font-size:0.65rem;"><?= $pending ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== FINANCIAL SECTION ===== -->
        <?php if (has_permission('view_purchase_orders') || has_permission('view_invoices') || has_permission('view_po_adjustments')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">FINANCIAL</div>

            <?php if (has_permission('view_purchase_orders')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/po', $currentPage) ?>"
               href="/po/list.php">
                <i class="bi bi-receipt me-2"></i>Purchase Orders
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_invoices')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/invoice', $currentPage) ?>"
               href="/invoice/list.php">
                <i class="bi bi-file-earmark-ruled me-2"></i>Invoices
            </a>

            <a class="nav-link text-white sidebar-link <?= active('/payment', $currentPage) ?>"
               href="/payment/list.php">
                <i class="bi bi-credit-card me-2"></i>Payments
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_po_adjustments')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/po/variation', $currentPage) ?>"
               href="/po/variation_queue.php">
                <i class="bi bi-sliders me-2"></i>PO Variations
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <!-- ===== REPORTS SECTION ===== -->
        <?php if (has_permission('view_financial_reports') || has_permission('view_management_dashboard')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">REPORTS</div>

            <?php if (has_permission('view_financial_reports')): ?>
            <div class="sidebar-subsection">Procurement</div>
            <a class="nav-link text-white sidebar-link <?= active('/reports/procurement_by_status', $currentPage) ?>"
               href="/reports/procurement_by_status.php">
                <i class="bi bi-bar-chart me-2"></i>By Status
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/procurement_by_type', $currentPage) ?>"
               href="/reports/procurement_by_type.php">
                <i class="bi bi-pie-chart me-2"></i>By Type
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/procurement_by_branch', $currentPage) ?>"
               href="/reports/procurement_by_branch.php">
                <i class="bi bi-building me-2"></i>By Branch
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/procurement_by_supplier', $currentPage) ?>"
               href="/reports/procurement_by_supplier.php">
                <i class="bi bi-shop me-2"></i>By Supplier
            </a>

            <div class="sidebar-subsection">Purchase Orders</div>
            <a class="nav-link text-white sidebar-link <?= active('/reports/po_status', $currentPage) ?>"
               href="/reports/po_status_report.php">
                <i class="bi bi-boxes me-2"></i>PO Status
            </a>

            <div class="sidebar-subsection">Financial</div>
            <a class="nav-link text-white sidebar-link <?= active('/reports/period_reports', $currentPage) ?>"
               href="/reports/period_reports.php">
                <i class="bi bi-calendar-range me-2"></i>Period Reports
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/amounts_paid', $currentPage) ?>"
               href="/reports/amounts_paid_report.php">
                <i class="bi bi-cash me-2"></i>Amounts Paid
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/outstanding', $currentPage) ?>"
               href="/reports/outstanding_commitments_po.php">
                <i class="bi bi-hourglass-split me-2"></i>Outstanding
            </a>

            <div class="sidebar-subsection">Branch</div>
            <a class="nav-link text-white sidebar-link <?= active('/reports/branch_summary', $currentPage) ?>"
               href="/reports/branch_summary.php">
                <i class="bi bi-graph-up me-2"></i>Summary
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/reports/branch_outstanding', $currentPage) ?>"
               href="/reports/branch_outstanding.php">
                <i class="bi bi-currency-dollar me-2"></i>Outstanding Balances
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_management_dashboard')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/reports/export', $currentPage) ?>"
               href="/reports/export_pdf.php">
                <i class="bi bi-file-earmark-arrow-down me-2"></i>Export Reports
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <!-- ===== INVENTORY MANAGEMENT ===== -->
        <?php if (has_permission('view_inventory')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">INVENTORY</div>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/dashboard', $currentPage) ?>"
               href="/inventory/dashboard.php">
                <i class="bi bi-speedometer me-2"></i>Inventory Dashboard
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/items', $currentPage) ?>"
               href="/inventory/items/list.php">
                <i class="bi bi-boxes me-2"></i>Items
            </a>
            <?php if (has_permission('import_inventory_assets')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/items/import', $currentPage) ?>"
               href="/inventory/items/import.php">
                <i class="bi bi-file-earmark-arrow-up me-2"></i>Import Assets
            </a>
            <?php endif; ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/locations', $currentPage) ?>"
               href="/inventory/locations/list.php">
                <i class="bi bi-geo-alt me-2"></i>Locations
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/requisitions', $currentPage) ?>"
               href="/inventory/requisitions/list.php">
                <i class="bi bi-list-check me-2"></i>Requisitions
            </a>
            <?php if (has_permission('receive_goods')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/receiving', $currentPage) ?>"
               href="/inventory/receiving/list.php">
                <i class="bi bi-inbox-fill me-2"></i>Receiving (GRN)
            </a>
            <?php endif; ?>
            <?php if (has_permission('issue_stock')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/issuing', $currentPage) ?>"
               href="/inventory/issuing/list.php">
                <i class="bi bi-box-arrow-up me-2"></i>Stock Issues
            </a>
            <?php endif; ?>
            <?php if (has_permission('transfer_stock')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/transfers', $currentPage) ?>"
               href="/inventory/transfers/list.php">
                <i class="bi bi-arrow-left-right me-2"></i>Transfers
            </a>
            <?php endif; ?>
            <?php if (has_permission('adjust_stock')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/adjustments', $currentPage) ?>"
               href="/inventory/adjustments/list.php">
                <i class="bi bi-sliders2 me-2"></i>Adjustments
            </a>
            <?php endif; ?>
            <?php if (has_permission('dispose_stock')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/disposal', $currentPage) ?>"
               href="/inventory/disposal/list.php">
                <i class="bi bi-trash3 me-2"></i>Disposal
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_board_of_survey')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/board_of_survey', $currentPage) ?>"
               href="/inventory/board_of_survey/list.php">
                <i class="bi bi-clipboard2-pulse me-2"></i>Board of Survey
            </a>
            <?php endif; ?>
            <?php if (has_permission('conduct_stock_count')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/stocktake', $currentPage) ?>"
               href="/inventory/stocktake/list.php">
                <i class="bi bi-clipboard-data me-2"></i>Stock Counts
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_quarantine')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/quarantine', $currentPage) ?>"
               href="/inventory/quarantine/list.php">
                <i class="bi bi-shield-lock me-2"></i>Quarantine
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_recalls')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/recall', $currentPage) ?>"
               href="/inventory/recall/list.php">
                <i class="bi bi-bell me-2"></i>Recalls
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_returns')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/returns', $currentPage) ?>"
               href="/inventory/returns/list.php">
                <i class="bi bi-arrow-return-left me-2"></i>Returns to Supplier
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_incidents')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/incidents', $currentPage) ?>"
               href="/inventory/incidents/list.php">
                <i class="bi bi-exclamation-triangle me-2"></i>Incidents / Losses
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_write_downs')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/writedowns', $currentPage) ?>"
               href="/inventory/writedowns/list.php">
                <i class="bi bi-graph-down-arrow me-2"></i>Write-Downs (NRV)
            </a>
            <?php endif; ?>
            <?php if (has_permission('view_inventory_reports')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/reports', $currentPage) ?>"
               href="/inventory/reports/">
                <i class="bi bi-bar-chart-line me-2"></i>Inventory Reports
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_inventory_items')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/inventory/asset-item-types', $currentPage) ?>"
               href="/inventory/asset-item-types/list.php">
                <i class="bi bi-tags me-2"></i>Asset Item Types
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <!-- ===== VENDORS SECTION ===== -->
        <?php if (has_permission('view_vendors') || has_permission('manage_vendors') || has_permission('view_contracts')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">VENDORS & CONTRACTS</div>
            <a class="nav-link text-white sidebar-link <?= active('/vendors', $currentPage) ?>"
               href="/vendors/list.php">
                <i class="bi bi-building me-2"></i>Vendors
            </a>
            <?php if (has_permission('view_contracts')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/contracts', $currentPage) ?>"
               href="/contracts/list.php">
                <i class="bi bi-file-earmark-text me-2"></i>Service Contracts
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <!-- ===== SUPERADMIN: ALL DASHBOARDS ACCESS ===== -->
        <?php if ($roleName === 'SuperAdmin'): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">ALL DASHBOARDS</div>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/admin', $currentPage) ?>" href="/dashboard/admin.php">
                <i class="bi bi-speedometer2 me-2"></i>Admin
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/gc', $currentPage) ?>" href="/dashboard/gc.php">
                <i class="bi bi-award me-2"></i>Deputy Government Chemist
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/committee', $currentPage) ?>" href="/dashboard/committee.php">
                <i class="bi bi-people-fill me-2"></i>Procurement Committee
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/procurement', $currentPage) ?>" href="/dashboard/procurement.php">
                <i class="bi bi-cart-check me-2"></i>Procurement Officer
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/evaluation', $currentPage) ?>" href="/dashboard/evaluation.php">
                <i class="bi bi-clipboard-data me-2"></i>Evaluation Committee
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/finance', $currentPage) ?>" href="/dashboard/finance.php">
                <i class="bi bi-cash-stack me-2"></i>Finance
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/hod', $currentPage) ?>" href="/dashboard/hod.php">
                <i class="bi bi-diagram-2 me-2"></i>HOD
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/director_hrma', $currentPage) ?>" href="/dashboard/director_hrma.php">
                <i class="bi bi-person-badge me-2"></i>Director HRM&amp;A
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/director_procurement', $currentPage) ?>" href="/dashboard/director_procurement.php">
                <i class="bi bi-person-vcard me-2"></i>Director Procurement
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/property_management_officer', $currentPage) ?>" href="/dashboard/property_management_officer.php">
                <i class="bi bi-building-gear me-2"></i>Property Management
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/requestor', $currentPage) ?>" href="/dashboard/requestor.php">
                <i class="bi bi-person-lines-fill me-2"></i>Requestor
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/viewer', $currentPage) ?>" href="/dashboard/viewer.php">
                <i class="bi bi-eye me-2"></i>Viewer
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/management', $currentPage) ?>" href="/dashboard/management.php">
                <i class="bi bi-bar-chart-line me-2"></i>Management
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/compliance', $currentPage) ?>" href="/dashboard/compliance.php">
                <i class="bi bi-shield-check me-2"></i>Compliance
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/approval_queue', $currentPage) ?>" href="/dashboard/approval_queue.php">
                <i class="bi bi-list-check me-2"></i>Approval Queue
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/approval_analytics', $currentPage) ?>" href="/dashboard/approval_analytics.php">
                <i class="bi bi-graph-up me-2"></i>Approval Analytics
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/monthly', $currentPage) ?>" href="/dashboard/monthly.php">
                <i class="bi bi-calendar3 me-2"></i>Monthly Metrics
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/dashboard/metrics', $currentPage) ?>" href="/dashboard/metrics.php">
                <i class="bi bi-speedometer me-2"></i>Metrics
            </a>
        </li>
        <?php endif; ?>

        <!-- ===== ADMINISTRATION SECTION ===== -->
        <?php if (has_permission('manage_users') || has_permission('manage_roles') || has_permission('view_audit_logs')): ?>
        <li class="nav-item mt-2">
            <div class="sidebar-section-label">ADMIN</div>

            <?php if (has_permission('manage_users')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/users', $currentPage) ?>"
               href="/users/list.php">
                <i class="bi bi-people me-2"></i>Users
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/acting_roles', $currentPage) ?>"
               href="/users/acting_roles.php">
                <i class="bi bi-lightning me-2"></i>Acting Roles
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_roles')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/admin/roles', $currentPage) ?>"
               href="/admin/roles.php">
                <i class="bi bi-shield-check me-2"></i>Roles
            </a>
            <?php endif; ?>
            <?php if (has_permission('manage_users')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/admin/permissions', $currentPage) ?>"
               href="/admin/permissions.php">
                <i class="bi bi-key me-2"></i>Permissions
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/admin/page_permissions', $currentPage) ?>"
               href="/admin/page_permissions.php">
                <i class="bi bi-shield-lock me-2"></i>Page Permissions
            </a>
            <a class="nav-link text-white sidebar-link <?= active('/admin/branches', $currentPage) ?>"
               href="/admin/branches.php">
                <i class="bi bi-diagram-3 me-2"></i>Branches
            </a>
            <?php endif; ?>

            <?php if (has_permission('view_audit_logs')): ?>
            <a class="nav-link text-white sidebar-link <?= active('/audit', $currentPage) ?>"
               href="/audit/list.php">
                <i class="bi bi-journal-check me-2"></i>Audit Logs
            </a>
            <?php endif; ?>
        </li>
        <?php endif; ?>

        <!-- ===== ACCOUNT SECTION ===== -->
        <li class="nav-item mt-3 border-top border-secondary pt-3">
            <a class="nav-link text-white sidebar-link <?= active('/change_password', $currentPage) ?>"
               href="/auth/change_password.php">
                <i class="bi bi-key me-2"></i>Change Password
            </a>

            <a class="nav-link sidebar-link sidebar-logout"
               href="/auth/logout.php">
                <i class="bi bi-door-open me-2"></i>Logout
            </a>
        </li>

    </ul>

</div>