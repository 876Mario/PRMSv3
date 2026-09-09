<?php
$REQUIRE_PERMISSION = 'view_director_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$statsStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN status = 'SUBMITTED' AND request_type = 'PETTY_CASH' THEN 1 ELSE 0 END) AS pending_fund_verifications,
        SUM(CASE WHEN status = 'FUNDS_VERIFIED' AND request_type = 'PETTY_CASH' THEN 1 ELSE 0 END) AS pending_disbursements,
        SUM(CASE WHEN status IN ('FUNDS_VERIFIED','FINANCE_AUTHORIZED','DISBURSED') AND request_type = 'PETTY_CASH' THEN 1 ELSE 0 END) AS petty_cash_processing,
        SUM(CASE WHEN status IN ('COMMITMENTS_PENDING','INVOICE_RECEIVED') THEN 1 ELSE 0 END) AS outstanding_finance_actions,
        SUM(CASE WHEN DATEDIFF(NOW(), updated_at) >= 7 AND status NOT IN ('COMPLETED','DECLINED','CANCELLED','PAUSED') THEN 1 ELSE 0 END) AS aging_items
    FROM procurement_requests
")->fetch(PDO::FETCH_ASSOC) ?: [];

$unreadNotifications = 0;
if (class_exists('NotificationService')) {
    $unreadNotifications = NotificationService::countUnread((int)($_SESSION['user_id'] ?? 0));
}

$workloadStmt = $pdo->query("
    SELECT u.full_name, COUNT(pr.request_id) AS active_items
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN procurement_requests pr
      ON pr.branch_id = u.branch_id
     AND u.branch_id IS NOT NULL
     AND pr.request_type IN ('PETTY_CASH','REIMBURSEMENT','REGULAR')
     AND pr.status IN ('SUBMITTED','FUNDS_VERIFIED','COMMITMENTS_PENDING','INVOICE_RECEIVED')
     AND pr.updated_at IS NOT NULL
    WHERE r.name = 'Finance Officer'
      AND u.is_active = 1
    GROUP BY u.user_id, u.full_name
    ORDER BY active_items DESC, u.full_name ASC
");
$workload = $workloadStmt ? $workloadStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem;">
        <h2 style="margin:0 0 1rem 0;">Director Accounts &amp; Finance Dashboard</h2>
        <p style="margin:0 0 1.5rem 0; color:#666;">Supervisory visibility and escalation oversight for finance workflows.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Finance Reviews</div><div class="fs-4 fw-bold"><?= (int)($statsStmt['pending_fund_verifications'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Disbursements</div><div class="fs-4 fw-bold"><?= (int)($statsStmt['pending_disbursements'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Finance Backlog</div><div class="fs-4 fw-bold"><?= (int)($statsStmt['outstanding_finance_actions'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Aging Finance Items</div><div class="fs-4 fw-bold text-danger"><?= (int)($statsStmt['aging_items'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Unread Notifications</div><div class="fs-4 fw-bold"><?= (int)$unreadNotifications ?></div></div></div>
        </div>

        <div class="alert alert-danger">
            <strong>URGENT:</strong> Overdue items, escalations, and blocked finance workflows require immediate intervention.
        </div>
        <div class="alert alert-warning">
            <strong>HIGH PRIORITY:</strong> Pending verifications, disbursements, and approval bottlenecks.
        </div>
        <div class="alert alert-info mb-4">
            <strong>NORMAL:</strong> Open and recently assigned finance activities.
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Finance Officer Workload</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Finance Officer</th><th class="text-end">Active Items</th></tr></thead>
                    <tbody>
                    <?php if (empty($workload)): ?>
                        <tr><td colspan="2" class="text-muted text-center py-4">No active finance officers found.</td></tr>
                    <?php else: foreach ($workload as $row): ?>
                        <tr><td><?= htmlspecialchars($row['full_name']) ?></td><td class="text-end"><?= (int)$row['active_items'] ?></td></tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="/petty_cash/list.php">Pending Petty Cash Requests</a>
            <a class="btn btn-outline-primary btn-sm" href="/reimbursement/list.php">Reimbursement Workflows</a>
            <a class="btn btn-outline-primary btn-sm" href="/dashboard/approval_queue.php">Escalations</a>
            <a class="btn btn-outline-primary btn-sm" href="/audit/logs.php">Audit Log</a>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
