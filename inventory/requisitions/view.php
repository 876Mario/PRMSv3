<?php
$REQUIRE_PERMISSION = 'view_inventory';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once __DIR__ . '/../check_setup.php';

$reqId = (int) ($_GET['id'] ?? 0);
if ($reqId <= 0) { pop("Invalid requisition.", "/inventory/requisitions/list.php", 1800, 'warning'); exit; }

$req = $pdo->prepare("
    SELECT r.*, u.full_name AS requester_name, b.branch_name,
           l.location_code AS dest_location, au.full_name AS approver_name
    FROM inv_requisitions r
    JOIN users u ON r.requester_user_id = u.user_id
    LEFT JOIN branches b ON r.department_id = b.branch_id
    LEFT JOIN inv_locations l ON r.destination_location_id = l.location_id
    LEFT JOIN users au ON r.approved_by = au.user_id
    WHERE r.requisition_id = ?
");
$req->execute([$reqId]);
$req = $req->fetch(PDO::FETCH_ASSOC);
if (!$req) { pop("Requisition not found.", "/inventory/requisitions/list.php", 1800, 'warning'); exit; }

$lineItems = $pdo->prepare("
    SELECT ri.*, i.item_code, i.item_name, i.issue_policy, u2.uom_code
    FROM inv_requisition_items ri
    JOIN inv_items i ON ri.item_id = i.item_id
    LEFT JOIN inv_units_of_measure u2 ON i.uom_id = u2.uom_id
    WHERE ri.requisition_id = ?
");
$lineItems->execute([$reqId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* Handle approval/rejection/submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (has_permission('approve_stock_requisition') || $_SESSION['user_id'] == $req['requester_user_id'])) {
    $action = $_POST['action'] ?? '';
    try {
        requireCsrfToken('/inventory/requisitions/view.php?id=' . $reqId);
        $pdo->beginTransaction();

        if ($action === 'approve' && $req['status'] === 'SUBMITTED') {
            // Segregation check: approver must not be the requester
            if ($_SESSION['user_id'] == $req['requester_user_id']) {
                throw new Exception("You cannot approve your own requisition (segregation of duties).");
            }

            $pdo->prepare("UPDATE inv_requisitions SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE requisition_id = ?")
                ->execute([$_SESSION['user_id'], $reqId]);

            foreach ($lineItems as $li) {
                $approvedQty = (float) ($_POST['approved_qty'][$li['req_item_id']] ?? $li['quantity_requested']);
                if ($approvedQty < 0) {
                    throw new Exception("Approved quantity cannot be negative.");
                }
                if ($approvedQty > (float) $li['quantity_requested']) {
                    throw new Exception("Approved quantity cannot exceed the requested quantity.");
                }
                $pdo->prepare("UPDATE inv_requisition_items SET quantity_approved = ? WHERE req_item_id = ?")
                    ->execute([$approvedQty, $li['req_item_id']]);
            }

            // Lock the requisition document
            lockDocumentByReference($pdo, 'inv_requisitions', $reqId);

            logInventoryAudit($pdo, 'inv_requisitions', $reqId, 'APPROVED', "Requisition approved");
            $pdo->commit();
            pop("Requisition approved.", "/inventory/requisitions/view.php?id=$reqId", 1800, 'success');
            exit;

        } elseif ($action === 'emergency_approve' && $req['status'] === 'SUBMITTED' && $req['urgency'] === 'EMERGENCY') {
            // Emergency expedited approval — GoJ FI requirement
            // Allows senior officer to approve and immediately release for issuing
            // Post-facto documentation must be completed within 48 hours
            if ($_SESSION['user_id'] == $req['requester_user_id']) {
                throw new Exception("Segregation of duties: you cannot approve your own emergency requisition.");
            }

            $emergencyJustification = trim($_POST['emergency_justification'] ?? '');
            if (empty($emergencyJustification)) {
                throw new Exception("Emergency justification is required for expedited approval.");
            }

            $pdo->prepare("UPDATE inv_requisitions SET status = 'APPROVED', approved_by = ?, approved_at = NOW(),
                justification = CONCAT(COALESCE(justification,''), '\n[EMERGENCY APPROVAL] ', ?)
                WHERE requisition_id = ?")
                ->execute([$_SESSION['user_id'], $emergencyJustification, $reqId]);

            foreach ($lineItems as $li) {
                $pdo->prepare("UPDATE inv_requisition_items SET quantity_approved = quantity_requested WHERE req_item_id = ?")
                    ->execute([$li['req_item_id']]);
            }

            lockDocumentByReference($pdo, 'inv_requisitions', $reqId);
            logInventoryAudit($pdo, 'inv_requisitions', $reqId, 'EMERGENCY_APPROVED',
                "Emergency approval: $emergencyJustification — Post-facto documentation required within 48h");
            $pdo->commit();
            pop("Emergency requisition approved. Post-facto documentation required within 48 hours.", "/inventory/requisitions/view.php?id=$reqId", 3000, 'success');
            exit;

        } elseif ($action === 'reject' && $req['status'] === 'SUBMITTED') {
            $reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($reason)) throw new Exception("Rejection reason is required.");

            $pdo->prepare("UPDATE inv_requisitions SET status = 'REJECTED', rejection_reason = ? WHERE requisition_id = ?")
                ->execute([$reason, $reqId]);
            logInventoryAudit($pdo, 'inv_requisitions', $reqId, 'REJECTED', "Rejected: $reason");
            $pdo->commit();
            pop("Requisition rejected.", "/inventory/requisitions/view.php?id=$reqId", 1800, 'success');
            exit;

        } elseif ($action === 'submit' && $req['status'] === 'DRAFT' && $_SESSION['user_id'] == $req['requester_user_id']) {
            $pdo->prepare("UPDATE inv_requisitions SET status = 'SUBMITTED' WHERE requisition_id = ? AND status = 'DRAFT'")
                ->execute([$reqId]);
            logInventoryAudit($pdo, 'inv_requisitions', $reqId, 'SUBMITTED', "Requisition submitted");
            $pdo->commit();
            pop("Requisition submitted for approval.", "/inventory/requisitions/view.php?id=$reqId", 1800, 'success');
            exit;
        } else {
            throw new Exception("Invalid requisition action for the current state.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>
        <i class="bi bi-clipboard-check"></i> Requisition <?= htmlspecialchars($req['requisition_number']) ?>
    </h2>
    <a href="/inventory/requisitions/list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Status Badge -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Requester:</strong> <?= htmlspecialchars($req['requester_name']) ?></div>
                    <div class="col-md-4"><strong>Department:</strong> <?= htmlspecialchars($req['branch_name'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Cost Centre:</strong> <?= htmlspecialchars($req['cost_centre'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Urgency:</strong>
                        <span class="badge bg-<?= $req['urgency'] === 'EMERGENCY' ? 'danger' : ($req['urgency'] === 'URGENT' ? 'warning' : 'info') ?>">
                            <?= $req['urgency'] ?>
                        </span>
                    </div>
                    <div class="col-md-4"><strong>Status:</strong>
                        <?php $sc = match($req['status']) { 'DRAFT' => 'secondary', 'SUBMITTED' => 'primary', 'APPROVED' => 'success', 'REJECTED' => 'danger', default => 'secondary' }; ?>
                        <span class="badge bg-<?= $sc ?>"><?= $req['status'] ?></span>
                    </div>
                    <div class="col-md-4"><strong>Date:</strong> <?= date('Y-m-d H:i', strtotime($req['created_at'])) ?></div>
                    <?php if ($req['intended_use']): ?>
                    <div class="col-md-6"><strong>Intended Use:</strong> <?= htmlspecialchars($req['intended_use']) ?></div>
                    <?php endif; ?>
                    <?php if ($req['justification']): ?>
                    <div class="col-md-6"><strong>Justification:</strong> <?= htmlspecialchars($req['justification']) ?></div>
                    <?php endif; ?>
                    <?php if ($req['dest_location']): ?>
                    <div class="col-md-6"><strong>Destination:</strong> <?= htmlspecialchars($req['dest_location']) ?></div>
                    <?php endif; ?>
                    <?php if ($req['approved_by']): ?>
                    <div class="col-md-6"><strong>Approved by:</strong> <?= htmlspecialchars($req['approver_name']) ?> on <?= $req['approved_at'] ?></div>
                    <?php endif; ?>
                    <?php if ($req['rejection_reason']): ?>
                    <div class="col-12"><div class="alert alert-danger mb-0"><strong>Rejection Reason:</strong> <?= htmlspecialchars($req['rejection_reason']) ?></div></div>
                    <?php endif; ?>
                    <?php if ($req['is_duplicate_flagged']): ?>
                    <div class="col-12"><div class="alert alert-warning mb-0">⚠️ This requisition has been flagged as a possible duplicate.</div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <!-- Action buttons -->
        <?php if ($req['status'] === 'DRAFT' && $_SESSION['user_id'] == $req['requester_user_id']): ?>
        <form method="POST" class="mb-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <button type="submit" name="action" value="submit" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-send"></i> Submit for Approval
            </button>
        </form>
        <?php endif; ?>

        <?php if ($req['status'] === 'SUBMITTED' && has_permission('approve_stock_requisition')): ?>
        <form method="POST" id="approvalForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
            <?php if ($req['urgency'] === 'EMERGENCY'): ?>
            <div class="alert alert-danger py-2 mb-2">
                <strong><i class="bi bi-lightning-charge"></i> EMERGENCY</strong> — Expedited approval available
            </div>
            <div class="mb-2">
                <textarea name="emergency_justification" class="form-control" rows="2" placeholder="Emergency justification (GoJ FI requirement)..."></textarea>
            </div>
            <button type="submit" name="action" value="emergency_approve" class="btn btn-warning w-100 btn-lg mb-2">
                <i class="bi bi-lightning-charge"></i> Emergency Approve (Immediate Release)
            </button>
            <hr>
            <?php endif; ?>
            <button type="submit" name="action" value="approve" class="btn btn-success w-100 btn-lg mb-2">
                <i class="bi bi-check-circle"></i> Approve
            </button>
            <div class="mb-2">
                <textarea name="rejection_reason" class="form-control" rows="2" placeholder="Reason for rejection..."></textarea>
            </div>
            <button type="submit" name="action" value="reject" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject
            </button>
        </form>
        <?php endif; ?>

        <?php if ($req['status'] === 'APPROVED' && has_permission('issue_stock')): ?>
        <a href="/inventory/issuing/add.php?requisition_id=<?= $reqId ?>" class="btn btn-dark w-100 btn-lg">
            <i class="bi bi-box-arrow-right"></i> Issue Stock
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Line Items -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white"><i class="bi bi-list-check"></i> Requested Items</div>
    <div class="card-body p-0">
        <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Item Code</th>
                        <th>Item Name</th>
                        <th>Issue Policy</th>
                        <th class="text-end">Stock at Request</th>
                        <th class="text-end">Qty Requested</th>
                        <?php if ($req['status'] === 'SUBMITTED' && has_permission('approve_stock_requisition')): ?>
                        <th class="text-end">Qty Approved</th>
                        <?php elseif ($req['status'] !== 'DRAFT'): ?>
                        <th class="text-end">Qty Approved</th>
                        <?php endif; ?>
                        <th class="text-end">Qty Issued</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td><?= htmlspecialchars($li['item_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $li['issue_policy'] === 'CONTROLLED' ? 'danger' : ($li['issue_policy'] === 'APPROVAL_REQUIRED' ? 'warning' : 'success') ?>">
                                <?= $li['issue_policy'] ?>
                            </span>
                        </td>
                        <td class="text-end"><?= number_format($li['stock_available_at_request'] ?? 0, 0) ?> <?= $li['uom_code'] ?></td>
                        <td class="text-end fw-bold"><?= number_format($li['quantity_requested'], 2) ?></td>
                        <?php if ($req['status'] === 'SUBMITTED' && has_permission('approve_stock_requisition')): ?>
                        <td class="text-end">
                            <input type="number" step="0.01" name="approved_qty[<?= $li['req_item_id'] ?>]"
                                   class="form-control form-control-sm text-end" style="width:100px;display:inline"
                                   value="<?= $li['quantity_requested'] ?>" max="<?= $li['quantity_requested'] ?>">
                        </td>
                        <?php elseif ($req['status'] !== 'DRAFT'): ?>
                        <td class="text-end"><?= number_format($li['quantity_approved'] ?? 0, 2) ?></td>
                        <?php endif; ?>
                        <td class="text-end"><?= number_format($li['quantity_issued'], 2) ?></td>
                        <td><?= htmlspecialchars($li['remarks'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>