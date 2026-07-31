<?php
/**
 * RFQ Approval Pending Actions
 * Shows RFQs awaiting specification review or branch head approval
 */

$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

// Initialize approval service
$approvalService = new RFQQuoteApprovalService($pdo, $_SESSION['user_id'], $_SESSION['role_name'] ?? '');

// Get pending spec reviews for this user (if they have permission)
$pendingSpecReviews = [];
$pendingBranchHeadApprovals = [];

if (hasPermission('approve_rfq_spec_review')) {
    // Get RFQs assigned to this user for spec review
    $stmt = $pdo->prepare("
        SELECT 
            r.rfq_id,
            r.rfq_number,
            r.submission_deadline,
            pr.request_number,
            pr.description,
            pr.estimated_value,
            COUNT(q.quote_id) as quote_count,
            r.created_at,
            u.display_name as created_by_name
        FROM rfqs r
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        JOIN rfq_spec_reviewers rsr ON r.rfq_id = rsr.rfq_id
        LEFT JOIN rfq_quotes q ON (
            SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = r.rfq_id LIMIT 1
        ) = q.rfq_vendor_id
        LEFT JOIN users u ON r.created_by = u.user_id
        WHERE r.spec_review_status = 'PENDING'
          AND rsr.reviewer_id = ?
          AND rsr.is_active = 1
        GROUP BY r.rfq_id, r.rfq_number, r.submission_deadline, pr.request_number, 
                 pr.description, pr.estimated_value, r.created_at, u.display_name
        ORDER BY r.submission_deadline ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingSpecReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (hasPermission('approve_rfq_branch_head')) {
    // Get RFQs assigned to this user for branch head approval
    $stmt = $pdo->prepare("
        SELECT 
            r.rfq_id,
            r.rfq_number,
            r.submission_deadline,
            pr.request_number,
            pr.description,
            pr.estimated_value,
            COUNT(q.quote_id) as quote_count,
            r.created_at,
            u.display_name as created_by_name,
            sr.display_name as spec_reviewer_name,
            r.spec_reviewed_at
        FROM rfqs r
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        JOIN rfq_branch_head_approvers rbha ON r.rfq_id = rbha.rfq_id
        LEFT JOIN rfq_quotes q ON (
            SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = r.rfq_id LIMIT 1
        ) = q.rfq_vendor_id
        LEFT JOIN users u ON r.created_by = u.user_id
        LEFT JOIN users sr ON r.spec_reviewer_id = sr.user_id
        WHERE r.spec_review_status = 'APPROVED'
          AND r.branch_head_approval_status = 'PENDING'
          AND rbha.approver_id = ?
          AND rbha.is_active = 1
        GROUP BY r.rfq_id, r.rfq_number, r.submission_deadline, pr.request_number, 
                 pr.description, pr.estimated_value, r.created_at, u.display_name,
                 sr.display_name, r.spec_reviewed_at
        ORDER BY r.submission_deadline ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingBranchHeadApprovals = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPending = count($pendingSpecReviews) + count($pendingBranchHeadApprovals);
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="container-fluid my-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2 class="mb-0">
                <i class="bi bi-clipboard-check"></i> RFQ Approval Pending Actions
            </h2>
            <p class="text-muted mt-2">
                RFQs awaiting your specification review or branch head approval
            </p>
        </div>
    </div>

    <?php if ($totalPending === 0): ?>
        <div class="alert alert-info" role="alert">
            <i class="bi bi-info-circle"></i> 
            <strong>No pending actions</strong> - All RFQs assigned to you have been reviewed or are awaiting other approvals.
        </div>
    <?php endif; ?>

    <!-- Specification Review Pending -->
    <?php if (hasPermission('approve_rfq_spec_review') && !empty($pendingSpecReviews)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="bi bi-hourglass-split"></i> 
                            Awaiting Your Specification Review
                            <span class="badge bg-danger float-end"><?= count($pendingSpecReviews) ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>RFQ Number</th>
                                        <th>Request</th>
                                        <th>Description</th>
                                        <th>Estimated Value</th>
                                        <th>Quotes</th>
                                        <th>Deadline</th>
                                        <th>Created By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingSpecReviews as $rfq): ?>
                                        <tr class="<?= strtotime($rfq['submission_deadline']) < time() ? 'table-danger' : '' ?>">
                                            <td>
                                                <strong><?= he($rfq['rfq_number']) ?></strong>
                                            </td>
                                            <td><?= he($rfq['request_number']) ?></td>
                                            <td><?= he(substr($rfq['description'], 0, 50)) ?></td>
                                            <td><?= formatCurrency($rfq['estimated_value']) ?></td>
                                            <td>
                                                <span class="badge bg-info"><?= $rfq['quote_count'] ?></span>
                                            </td>
                                            <td>
                                                <small class="<?= strtotime($rfq['submission_deadline']) < time() ? 'text-danger fw-bold' : '' ?>">
                                                    <?= formatDate($rfq['submission_deadline']) ?>
                                                </small>
                                            </td>
                                            <td><small><?= he($rfq['created_by_name']) ?></small></td>
                                            <td>
                                                <a href="/rfq/spec_review_approve.php?id=<?= (int)$rfq['rfq_id'] ?>" 
                                                   class="btn btn-sm btn-warning">
                                                    <i class="bi bi-check-square"></i> Review
                                                </a>
                                            </td>
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

    <!-- Branch Head Approval Pending -->
    <?php if (hasPermission('approve_rfq_branch_head') && !empty($pendingBranchHeadApprovals)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-hourglass-split"></i> 
                            Awaiting Your Branch Head Approval
                            <span class="badge bg-danger float-end"><?= count($pendingBranchHeadApprovals) ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>RFQ Number</th>
                                        <th>Request</th>
                                        <th>Description</th>
                                        <th>Estimated Value</th>
                                        <th>Quotes</th>
                                        <th>Reviewed By</th>
                                        <th>Deadline</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingBranchHeadApprovals as $rfq): ?>
                                        <tr class="<?= strtotime($rfq['submission_deadline']) < time() ? 'table-danger' : '' ?>">
                                            <td>
                                                <strong><?= he($rfq['rfq_number']) ?></strong>
                                            </td>
                                            <td><?= he($rfq['request_number']) ?></td>
                                            <td><?= he(substr($rfq['description'], 0, 50)) ?></td>
                                            <td><?= formatCurrency($rfq['estimated_value']) ?></td>
                                            <td>
                                                <span class="badge bg-info"><?= $rfq['quote_count'] ?></span>
                                            </td>
                                            <td><small><?= he($rfq['spec_reviewer_name'] ?? 'N/A') ?></small></td>
                                            <td>
                                                <small class="<?= strtotime($rfq['submission_deadline']) < time() ? 'text-danger fw-bold' : '' ?>">
                                                    <?= formatDate($rfq['submission_deadline']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <a href="/rfq/branch_head_approve.php?id=<?= (int)$rfq['rfq_id'] ?>" 
                                                   class="btn btn-sm btn-info text-white">
                                                    <i class="bi bi-check-square"></i> Review
                                                </a>
                                            </td>
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

    <!-- Quick Stats -->
    <?php if ($totalPending > 0): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <h6 class="card-title">Quick Stats</h6>
                        <div class="row">
                            <div class="col-sm-6">
                                <p class="mb-0">
                                    <i class="bi bi-hourglass-split"></i>
                                    <strong><?= count($pendingSpecReviews) ?></strong> awaiting your specification review
                                </p>
                            </div>
                            <div class="col-sm-6">
                                <p class="mb-0">
                                    <i class="bi bi-hourglass-split"></i>
                                    <strong><?= count($pendingBranchHeadApprovals) ?></strong> awaiting your branch head approval
                                </p>
                            </div>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            Click the "Review" button to open the approval interface for any RFQ
                        </p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Information Panel -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card border-left border-info">
                <div class="card-body">
                    <h6 class="card-title">About RFQ Approvals</h6>
                    <p class="card-text small mb-0">
                        The RFQ approval workflow consists of two stages:
                    </p>
                    <ol class="small mt-2 mb-0">
                        <li><strong>Specification Review:</strong> Verify that vendor quotes comply with all specification requirements</li>
                        <li><strong>Branch Head Approval:</strong> Provide final approval before proceeding to supplier selection</li>
                    </ol>
                    <p class="card-text small mt-2 mb-0">
                        Both stages must be completed before the procurement team can proceed with supplier selection and award.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
