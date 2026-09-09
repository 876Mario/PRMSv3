<?php
$REQUIRE_PERMISSION = 'view_director_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/WorkflowConfigurationService.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$procurementThreshold = WorkflowConfigurationService::getHighValueThreshold($pdo, 'procurement');
$poThreshold = WorkflowConfigurationService::getHighValueThreshold($pdo, 'purchase_order');
$slaSnapshot = WorkflowConfigurationService::getEscalationSnapshot($pdo, 'REGULAR', 'PROCUREMENT_STAGE', 0);
$overdueDays = $slaSnapshot['overdue_days'];

$stats = $pdo->query("
    SELECT
      SUM(CASE WHEN status IN ('PROCUREMENT_STAGE','RFQ_LETTER_AVAILABLE','QUOTE_REVIEW_PENDING','ADDITIONAL_QUOTATIONS_REQUIRED') THEN 1 ELSE 0 END) AS outstanding_rfqs,
      SUM(CASE WHEN status IN ('QUOTE_REQUESTOR_REVIEW_PENDING','QUOTE_REQUESTOR_REVIEW_APPROVED','QUOTE_BRANCH_HEAD_APPROVAL_PENDING') THEN 1 ELSE 0 END) AS pending_reviews,
      SUM(CASE WHEN status = 'ADDITIONAL_QUOTATIONS_REQUIRED' THEN 1 ELSE 0 END) AS returned_for_quotes,
      SUM(CASE WHEN DATEDIFF(NOW(), updated_at) >= {$overdueDays} AND status NOT IN ('COMPLETED','DECLINED','CANCELLED','PAUSED') THEN 1 ELSE 0 END) AS aging_items,
      SUM(CASE WHEN status IN ('COMMITMENTS_PENDING','COMMITMENT_APPROVED','PO_PENDING') THEN 1 ELSE 0 END) AS urgent_actions,
      SUM(CASE WHEN estimated_value >= {$procurementThreshold} AND status NOT IN ('COMPLETED','DECLINED','CANCELLED','PAUSED') THEN 1 ELSE 0 END) AS high_value_requests,
      SUM(CASE WHEN estimated_value >= {$poThreshold} AND status = 'PO_PENDING' THEN 1 ELSE 0 END) AS po_oversight_items
    FROM procurement_requests
")->fetch(PDO::FETCH_ASSOC) ?: [];

$workloadStmt = $pdo->query("
    SELECT u.full_name, COUNT(pr.request_id) AS active_items
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN procurement_requests pr
      ON pr.branch_id = u.branch_id
     AND u.branch_id IS NOT NULL
     AND pr.status IN ('PROCUREMENT_STAGE','RFQ_LETTER_AVAILABLE','QUOTE_REVIEW_PENDING','ADDITIONAL_QUOTATIONS_REQUIRED','PO_PENDING')
    WHERE r.name = 'Procurement Officer'
      AND u.is_active = 1
    GROUP BY u.user_id, u.full_name
    ORDER BY active_items DESC, u.full_name ASC
");
$workload = $workloadStmt ? $workloadStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$unreadNotifications = class_exists('NotificationService')
    ? NotificationService::countUnread((int)($_SESSION['user_id'] ?? 0))
    : 0;
?>

<div style="max-width: 1400px; margin: 2rem auto; padding: 0 1rem;">
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <span style="font-size: 1.75em; margin-right: 1rem;">🏢</span>
            <div>
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #333;">Director Procurement Dashboard</h2>
                <small style="color: #999; font-size: 0.875rem; display: block; margin-top: 0.25rem;">Oversight of all procurement requests, commitments, and purchase orders.</small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/kpis.php'; ?>
            <?php include $_SERVER['DOCUMENT_ROOT'].'/dashboard/widgets/alerts.php'; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Outstanding Procurement RFQs</div><div class="fs-4 fw-bold"><?= (int)($stats['outstanding_rfqs'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Pending Procurement Reviews</div><div class="fs-4 fw-bold"><?= (int)($stats['pending_reviews'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">RFQs Returned For Additional Quotes</div><div class="fs-4 fw-bold text-warning"><?= (int)($stats['returned_for_quotes'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Aging Procurement Items</div><div class="fs-4 fw-bold text-danger"><?= (int)($stats['aging_items'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Urgent Procurement Actions</div><div class="fs-4 fw-bold"><?= (int)($stats['urgent_actions'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">High-Value Requests</div><div class="fs-4 fw-bold text-warning"><?= (int)($stats['high_value_requests'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">PO Oversight Queue</div><div class="fs-4 fw-bold text-danger"><?= (int)($stats['po_oversight_items'] ?? 0) ?></div></div></div>
            <div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Unread Notifications</div><div class="fs-4 fw-bold"><?= (int)$unreadNotifications ?></div></div></div>
        </div>

        <div class="alert alert-danger"><strong>What needs attention today:</strong> overdue and escalated procurement items older than <?= (int)$overdueDays ?> day(s).</div>
        <div class="alert alert-warning"><strong>High priority:</strong> pending reviews, approvals, returned RFQs, and requests at or above JMD <?= number_format($procurementThreshold, 2) ?>.</div>
        <div class="alert alert-info mb-4"><strong>Normal:</strong> open assigned procurement actions.</div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Procurement Officer Workload</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Officer</th><th class="text-end">Active Items</th></tr></thead>
                    <tbody>
                    <?php if (empty($workload)): ?>
                        <tr><td colspan="2" class="text-center text-muted py-4">No active procurement officers found.</td></tr>
                    <?php else: foreach ($workload as $row): ?>
                        <tr><td><?= htmlspecialchars($row['full_name']) ?></td><td class="text-end"><?= (int)$row['active_items'] ?></td></tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
            <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
                <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">📋 Recent Procurement Requests</h6>
            </div>
            
            <?php
            $stmt = $pdo->prepare("SELECT request_id, request_number, request_type, estimated_value, status, request_date
                FROM procurement_requests
                ORDER BY request_date DESC LIMIT 20");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (empty($rows)): ?>
                <div style="text-align: center; color: #999; padding: 2rem 0;">
                    <span style="font-size: 1.5em;">📄</span><br>
                    <span style="display: block; margin-top: 0.5rem;">No recent requests</span>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead style="background: #f5f5f5;">
                            <tr>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Request #</th>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Type</th>
                                <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Value</th>
                                <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Status</th>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Date</th>
                                <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($row['request_number']) ?></td>
                                <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($row['request_type']) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: right; color: #666;">JMD <?= number_format((float)$row['estimated_value'], 2) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: center;">
                                    <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-block;">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 1rem; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($row['request_date'])) ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: center;">
                                    <a href="/procurement/view.php?id=<?= $row['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600; display: inline-block; transition: transform 0.3s ease;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
