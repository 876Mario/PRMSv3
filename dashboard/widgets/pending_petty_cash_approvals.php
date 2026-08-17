<?php
/**
 * Dashboard Widget: Petty Cash Requests Awaiting Approval
 * Displays cards and table of pending petty cash requests for HOD/Branch Head
 */

// This widget expects $approverRole and $pdo to be available from the parent dashboard

if (!isset($pdo) || !isset($approverRole)) {
    echo '<div class="alert alert-warning">Widget not properly initialized.</div>';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$pettyCashRequests = getPendingPettyCashApprovals($pdo, $userId, $approverRole);

$totalPcAmount = 0.00;
$oldestDate = null;
$overdueCount = 0;

foreach ($pettyCashRequests as $req) {
    $totalPcAmount += (float)$req['estimated_value'];
    if (!$oldestDate || strtotime($req['created_at']) < strtotime($oldestDate)) {
        $oldestDate = $req['created_at'];
    }
    // Consider overdue if pending for more than 2 days
    if ((int)$req['days_pending'] > 2) {
        $overdueCount++;
    }
}

$daysPendingOldest = $oldestDate ? intval((time() - strtotime($oldestDate)) / 86400) : 0;
?>

<div class="card shadow-sm mb-4">
    <div class="card-header" style="background: linear-gradient(135deg, #2d5016 0%, #3d6b1f 100%); color: white; border-bottom: 3px solid #d4a574;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="card-title mb-0">💰 Petty Cash Requests Awaiting Approval</h5>
                <small style="opacity: 0.9;">Requests submitted by employees in your <?= htmlspecialchars($approverRole) ?> scope</small>
            </div>
            <?php if (!empty($pettyCashRequests)): ?>
                <a href="/petty_cash/list.php?status=SUBMITTED&approval_pending=1" class="btn btn-sm" style="background: #d4a574; color: #2d5016; border: none; font-weight: 600;">
                    Review All →
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">
        <?php if (empty($pettyCashRequests)): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-check-circle"></i> 
                <strong>All caught up!</strong> No petty cash requests are currently awaiting your approval.
            </div>
        <?php else: ?>
            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div style="background: #f8f9fa; border-left: 4px solid #2d5016; padding: 1rem; border-radius: 4px;">
                        <div style="font-size: 0.85rem; color: #666; font-weight: 500; margin-bottom: 0.5rem;">Pending Requests</div>
                        <div style="font-size: 1.8rem; font-weight: 700; color: #2d5016;"><?= count($pettyCashRequests) ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div style="background: #f8f9fa; border-left: 4px solid #d4a574; padding: 1rem; border-radius: 4px;">
                        <div style="font-size: 0.85rem; color: #666; font-weight: 500; margin-bottom: 0.5rem;">Total Amount</div>
                        <div style="font-size: 1.8rem; font-weight: 700; color: #d4a574;">JMD <?= number_format($totalPcAmount, 0) ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div style="background: #f8f9fa; border-left: 4px solid #ff9800; padding: 1rem; border-radius: 4px;">
                        <div style="font-size: 0.85rem; color: #666; font-weight: 500; margin-bottom: 0.5rem;">Oldest Request</div>
                        <div style="font-size: 1.8rem; font-weight: 700; color: #ff9800;"><?= $daysPendingOldest ?> <span style="font-size: 0.8rem;">days</span></div>
                        <small style="color: #999;"><?= $oldestDate ? date('M d, Y', strtotime($oldestDate)) : 'N/A' ?></small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div style="background: #f8f9fa; border-left: 4px solid #dc3545; padding: 1rem; border-radius: 4px;">
                        <div style="font-size: 0.85rem; color: #666; font-weight: 500; margin-bottom: 0.5rem;">Overdue</div>
                        <div style="font-size: 1.8rem; font-weight: 700; color: <?= $overdueCount > 0 ? '#dc3545' : '#6c757d' ?>;">
                            <?= $overdueCount ?>
                        </div>
                        <small style="color: #999;">Pending >2 days</small>
                    </div>
                </div>
            </div>

            <!-- Requests Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.9rem;">
                    <thead style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                        <tr>
                            <th>Request #</th>
                            <th>Requester</th>
                            <th>Branch</th>
                            <th>Purpose</th>
                            <th class="text-end">Amount</th>
                            <th>Submitted</th>
                            <th class="text-center">Days Pending</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pettyCashRequests as $req): ?>
                            <tr>
                                <td>
                                    <a href="/petty_cash/view.php?request_id=<?= $req['request_id'] ?>" style="text-decoration: none; color: #2d5016; font-weight: 600;">
                                        <?= htmlspecialchars($req['request_number']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($req['requester_name'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars($req['branch_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span title="<?= htmlspecialchars($req['description'] ?? '') ?>">
                                        <?= htmlspecialchars(substr($req['description'] ?? '', 0, 30)) ?>...
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="badge" style="background: #2d5016;">
                                        JMD <?= number_format($req['estimated_value'], 0) ?>
                                    </span>
                                </td>
                                <td>
                                    <small><?= date('M d, Y', strtotime($req['created_at'])) ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= (int)$req['days_pending'] > 2 ? 'bg-warning text-dark' : 'bg-info' ?>">
                                        <?= (int)$req['days_pending'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="/petty_cash/view.php?request_id=<?= $req['request_id'] ?>" class="btn btn-xs btn-outline-primary" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
