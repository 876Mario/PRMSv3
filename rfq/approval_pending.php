<?php
/**
 * RFQ approval pending actions for requestor review and Branch Head approval.
 */

$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

$approvalService = new RFQQuoteApprovalService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');
$pendingRequestorReviews = hasPermission('submit_requestor_spec_review') ? $approvalService->getPendingRequestorReviews() : [];
$pendingBranchHeadApprovals = hasPermission('approve_branch_head_award') ? $approvalService->getPendingBranchHeadApprovals() : [];
$totalPending = count($pendingRequestorReviews) + count($pendingBranchHeadApprovals);
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-0"><i class="bi bi-clipboard-check"></i> RFQ Approval Pending Actions</h2>
            <p class="text-muted mt-2">RFQs awaiting your requestor specification confirmation or Branch Head approval.</p>
        </div>
    </div>

    <?php if ($totalPending === 0): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> <strong>No pending actions</strong> - There are no RFQs awaiting your action right now.</div>
    <?php endif; ?>

    <?php if (!empty($pendingRequestorReviews)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Pending Requestor Review <span class="badge bg-danger float-end"><?= count($pendingRequestorReviews) ?></span></h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light"><tr><th>RFQ Number</th><th>Request</th><th>Description</th><th>Selected Vendor</th><th>Selected Quote</th><th>Deadline</th><th>Requestor</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingRequestorReviews as $rfq): ?>
                                    <?php $isOverdue = !empty($rfq['submission_deadline']) && strtotime($rfq['submission_deadline']) < time(); ?>
                                    <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                                        <td><strong><?= he($rfq['rfq_number']) ?></strong></td>
                                        <td><?= he($rfq['request_number']) ?></td>
                                        <td><?= he(substr((string)$rfq['description'], 0, 60)) ?></td>
                                        <td><?= he($rfq['selected_vendor_name'] ?? 'Pending selection') ?></td>
                                        <td><?= !empty($rfq['selected_quote_amount']) ? formatCurrency((float)$rfq['selected_quote_amount']) : '—' ?></td>
                                        <td><small class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= formatDate($rfq['submission_deadline']) ?></small></td>
                                        <td><small><?= he($rfq['requestor_name'] ?? 'N/A') ?></small></td>
                                        <td><a href="/rfq/requestor_spec_review.php?id=<?= (int)$rfq['rfq_id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-check-square"></i> Review</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pendingBranchHeadApprovals)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-hourglass-split"></i> Pending Branch Head Approval <span class="badge bg-danger float-end"><?= count($pendingBranchHeadApprovals) ?></span></h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light"><tr><th>RFQ Number</th><th>Request</th><th>Description</th><th>Requestor</th><th>Selected Vendor</th><th>Selected Quote</th><th>Reviewed</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingBranchHeadApprovals as $rfq): ?>
                                    <?php $isOverdue = !empty($rfq['submission_deadline']) && strtotime($rfq['submission_deadline']) < time(); ?>
                                    <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                                        <td><strong><?= he($rfq['rfq_number']) ?></strong></td>
                                        <td><?= he($rfq['request_number']) ?></td>
                                        <td><?= he(substr((string)$rfq['description'], 0, 60)) ?></td>
                                        <td><small><?= he($rfq['requestor_name'] ?? 'N/A') ?></small></td>
                                        <td><?= he($rfq['selected_vendor_name'] ?? 'Pending selection') ?></td>
                                        <td><?= !empty($rfq['selected_quote_amount']) ? formatCurrency((float)$rfq['selected_quote_amount']) : '—' ?></td>
                                        <td><small><?= formatDate($rfq['requestor_reviewed_at']) ?></small></td>
                                        <td><a href="/rfq/branch_head_approve.php?id=<?= (int)$rfq['rfq_id'] ?>" class="btn btn-sm btn-info text-white"><i class="bi bi-check-square"></i> Review</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
