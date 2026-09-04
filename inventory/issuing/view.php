<?php
$REQUIRE_PERMISSION = 'issue_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_setup.php';

$issueId = (int) ($_GET['id'] ?? 0);
if ($issueId <= 0) { pop("Invalid issue.", "/inventory/issuing/list.php", 1800, 'warning'); exit; }

$issue = $pdo->prepare("
    SELECT si.*, u.full_name AS issuer_name, r.full_name AS recipient_name,
           b.branch_name, l.location_code, l.site_name,
           au.full_name AS approver_name
    FROM inv_issues si
    LEFT JOIN users u ON si.issued_by = u.user_id
    LEFT JOIN users r ON si.issued_to_user_id = r.user_id
    LEFT JOIN branches b ON si.issued_to_department_id = b.branch_id
    LEFT JOIN inv_locations l ON si.from_location_id = l.location_id
    LEFT JOIN users au ON si.approved_by = au.user_id
    WHERE si.issue_id = ?
");
$issue->execute([$issueId]);
$issue = $issue->fetch(PDO::FETCH_ASSOC);
if (!$issue) { pop("Issue not found.", "/inventory/issuing/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT sii.*, i.item_code, i.item_name, um.uom_code
    FROM inv_issue_items sii
    JOIN inv_items i ON sii.item_id = i.item_id
    LEFT JOIN inv_units_of_measure um ON i.uom_id = um.uom_id
    WHERE sii.issue_id = ?
");
$lineItems->execute([$issueId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* Handle approval and dispatch actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/issuing/view.php?id=' . $issueId);
        $pdo->beginTransaction();

        if ($action === 'approve' && has_permission('approve_issue') && $issue['status'] === 'PENDING_APPROVAL') {
            // Segregation: approver must not be the person who created the issue
            if ($_SESSION['user_id'] == $issue['issued_by']) {
                throw new Exception("Cannot approve your own stock issue (segregation of duties).");
            }

            InventoryService::reserveIssueStock($pdo, $issue, $lineItems);

            $pdo->prepare("UPDATE inv_issues SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE issue_id = ?")
                ->execute([$_SESSION['user_id'], $issueId]);

            // Lock the document
            lockDocumentByReference($pdo, 'inv_issues', $issueId);

            logInventoryAudit($pdo, 'inv_issues', $issueId, 'APPROVED', "Issue approved");

        } elseif ($action === 'dispatch' && $issue['status'] === 'APPROVED') {
            InventoryService::dispatchIssueStock($pdo, $issue, $lineItems);

            if (!empty($issue['requisition_id'])) {
                InventoryService::applyIssuedQuantitiesToRequisition($pdo, (int) $issue['requisition_id'], $lineItems);
            }

            $pdo->prepare("UPDATE inv_issues SET status = 'COMPLETED' WHERE issue_id = ?")->execute([$issueId]);
            logInventoryAudit($pdo, 'inv_issues', $issueId, 'COMPLETED', "Stock dispatched and issue completed");

        } elseif ($action === 'reject' && has_permission('approve_issue') && $issue['status'] === 'PENDING_APPROVAL') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) throw new Exception("Rejection reason is required.");
            $pdo->prepare("UPDATE inv_issues SET status = 'CANCELLED', notes = CONCAT(IFNULL(notes,''), '\nRejected: ', ?) WHERE issue_id = ?")
                ->execute([$reason, $issueId]);
            logInventoryAudit($pdo, 'inv_issues', $issueId, 'REJECTED', "Rejected: $reason");
        } else {
            throw new Exception("Invalid issue action for the current state.");
        }

        $pdo->commit();
        pop("Issue updated.", "/inventory/issuing/view.php?id=$issueId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-arrow-right"></i> Issue <?= htmlspecialchars($issue['issue_number']) ?></h2>
    <a href="/inventory/issuing/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><strong>Issue #:</strong> <?= htmlspecialchars($issue['issue_number']) ?></div>
                    <div class="col-md-3"><strong>Requisition:</strong> <?= htmlspecialchars($issue['requisition_number'] ?: '-') ?></div>
                    <div class="col-md-3"><strong>Status:</strong>
                        <?php $sc = match($issue['status']) { 'COMPLETED' => 'success', 'APPROVED' => 'info', 'PENDING_APPROVAL' => 'warning', 'CANCELLED' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $issue['status']) ?></span>
                    </div>
                    <div class="col-md-3"><strong>Date:</strong> <?= date('Y-m-d H:i', strtotime($issue['created_at'])) ?></div>
                    <div class="col-md-3"><strong>Issued By:</strong> <?= htmlspecialchars($issue['issuer_name']) ?></div>
                    <div class="col-md-3"><strong>Issued To:</strong> <?= htmlspecialchars($issue['recipient_name'] ?? '-') ?></div>
                    <div class="col-md-3"><strong>Department:</strong> <?= htmlspecialchars($issue['branch_name'] ?? '-') ?></div>
                    <div class="col-md-3"><strong>From Location:</strong> <?= htmlspecialchars($issue['location_code'] . ' - ' . $issue['site_name']) ?></div>
                    <?php if ($issue['issued_to_project']): ?>
                    <div class="col-md-3"><strong>Project:</strong> <?= htmlspecialchars($issue['issued_to_project']) ?></div>
                    <?php endif; ?>
                    <?php if ($issue['cost_centre']): ?>
                    <div class="col-md-3"><strong>Cost Centre:</strong> <?= htmlspecialchars($issue['cost_centre']) ?></div>
                    <?php endif; ?>
                    <?php if ($issue['approved_by']): ?>
                    <div class="col-md-6"><strong>Approved By:</strong> <?= htmlspecialchars($issue['approver_name']) ?> on <?= $issue['approved_at'] ?></div>
                    <?php endif; ?>
                    <?php if ($issue['notes']): ?>
                    <div class="col-12"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($issue['notes'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <?php if ($issue['status'] === 'PENDING_APPROVAL' && has_permission('approve_issue')): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg mb-2">
                <i class="bi bi-check-circle"></i> Approve Issue
            </button>
            <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="Rejection reason..."></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>

        <?php if ($issue['status'] === 'APPROVED'): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="dispatch" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-box-arrow-right"></i> Dispatch Stock
            </button>
            <small class="text-muted mt-2 d-block">Dispatching will deduct stock from the source location.</small>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="bi bi-list-ol"></i> Issued Items</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Item Code</th><th>Item Name</th><th>Lot</th><th>Batch</th><th>Serial</th><th class="text-end">Qty Requested</th><th class="text-end">Qty Issued</th><th>UOM</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td><?= htmlspecialchars($li['item_name']) ?></td>
                        <td><?= htmlspecialchars($li['lot_number'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($li['batch_number'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($li['serial_number'] ?: '-') ?></td>
                        <td class="text-end"><?= number_format($li['quantity_requested'], 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format($li['quantity_issued'], 2) ?></td>
                        <td><?= htmlspecialchars($li['uom_code'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>