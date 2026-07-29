<?php
$REQUIRE_PERMISSION = 'manage_board_of_survey';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

/* ── Load record ─────────────────────────────────────────────────────────── */
$bosId = (int) ($_GET['id'] ?? 0);
if ($bosId <= 0) {
    pop('Invalid Board of Survey ID.', '/inventory/board_of_survey/list.php', 1800, 'warning');
    exit;
}

$bos = $pdo->prepare("
    SELECT b.*,
           u.full_name   AS initiator_name,
           rv.full_name  AS reviewer_name,
           av.full_name  AS approver_name,
           l.location_code, l.site_name
    FROM inv_board_of_survey b
    LEFT JOIN users u  ON b.initiated_by = u.user_id
    LEFT JOIN users rv ON b.reviewed_by  = rv.user_id
    LEFT JOIN users av ON b.approved_by  = av.user_id
    LEFT JOIN inv_locations l ON b.location_id = l.location_id
    WHERE b.bos_id = ?
");
$bos->execute([$bosId]);
$bos = $bos->fetch(PDO::FETCH_ASSOC);

if (!$bos) {
    pop('Board of Survey not found.', '/inventory/board_of_survey/list.php', 1800, 'warning');
    exit;
}

$lineItems = $pdo->prepare("
    SELECT bi.*, i.item_code, i.item_name, i.description
    FROM inv_bos_items bi
    JOIN inv_items i ON bi.item_id = i.item_id
    WHERE bi.bos_id = ?
    ORDER BY bi.bos_item_id
");
$lineItems->execute([$bosId]);
$lineItems = $lineItems->fetchAll(PDO::FETCH_ASSOC);

/* ── Audit trail ─────────────────────────────────────────────────────────── */
$auditRows = $pdo->prepare("
    SELECT al.*, u.full_name
    FROM inv_audit_log al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE al.table_name = 'inv_board_of_survey' AND al.record_id = ?
    ORDER BY al.logged_at DESC
    LIMIT 50
");
$auditRows->execute([$bosId]);
$auditRows = $auditRows->fetchAll(PDO::FETCH_ASSOC);

/* ── Workflow POST handler ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        /* Submit for Review (DRAFT → SUBMITTED) */
        if ($action === 'submit' && $bos['status'] === 'DRAFT') {
            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'SUBMITTED', submitted_at = NOW()
                 WHERE bos_id = ?"
            )->execute([$bosId]);
            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'SUBMITTED',
                'BOS submitted for review.');

        /* Start Review (SUBMITTED → UNDER_REVIEW) */
        } elseif ($action === 'start_review' && $bos['status'] === 'SUBMITTED'
                  && has_permission('approve_board_of_survey')) {
            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'UNDER_REVIEW', reviewed_by = ?
                 WHERE bos_id = ?"
            )->execute([$_SESSION['user_id'], $bosId]);
            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'UNDER_REVIEW',
                'BOS taken under review.');

        /* Approve (UNDER_REVIEW → APPROVED) */
        } elseif ($action === 'approve'
                  && in_array($bos['status'], ['SUBMITTED', 'UNDER_REVIEW'], true)
                  && has_permission('approve_board_of_survey')) {
            if ((int) $_SESSION['user_id'] === (int) $bos['initiated_by']) {
                throw new Exception('You cannot approve a Board of Survey you initiated (segregation of duties).');
            }
            $approvalNotes = trim($_POST['approval_notes'] ?? '');
            $recommendation = $_POST['board_recommendation'] ?? $bos['board_recommendation'];
            $recNotes       = trim($_POST['recommendation_notes'] ?? '');

            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'APPROVED',
                     approved_by = ?, approved_at = NOW(),
                     approval_notes = ?,
                     board_recommendation = ?,
                     recommendation_notes = ?
                 WHERE bos_id = ?"
            )->execute([
                $_SESSION['user_id'],
                $approvalNotes ?: null,
                $recommendation ?: null,
                $recNotes ?: null,
                $bosId,
            ]);
            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'APPROVED',
                "Approved. Recommendation: $recommendation. " . ($approvalNotes ? "Notes: $approvalNotes" : ''));

        /* Reject (SUBMITTED|UNDER_REVIEW → REJECTED) */
        } elseif ($action === 'reject'
                  && in_array($bos['status'], ['SUBMITTED', 'UNDER_REVIEW'], true)
                  && has_permission('approve_board_of_survey')) {
            $rejectReason = trim($_POST['rejection_reason'] ?? '');
            if (empty($rejectReason)) {
                throw new Exception('A rejection reason is required.');
            }
            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'REJECTED',
                     approved_by = ?, approved_at = NOW(),
                     approval_notes = ?
                 WHERE bos_id = ?"
            )->execute([$_SESSION['user_id'], $rejectReason, $bosId]);
            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'REJECTED',
                "Rejected: $rejectReason");

        /* Complete (APPROVED → COMPLETED) */
        } elseif ($action === 'complete' && $bos['status'] === 'APPROVED') {
            $completionNotes = trim($_POST['completion_notes'] ?? '');
            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'COMPLETED',
                     completed_at = NOW(),
                     supporting_notes = CONCAT(COALESCE(supporting_notes,''), IF(? != '', CONCAT('\nCompletion: ', ?), ''))
                 WHERE bos_id = ?"
            )->execute([$completionNotes, $completionNotes, $bosId]);

            /* Update inv_asset_details bos_number for items that have asset detail records */
            $updateBosRef = $pdo->prepare("
                UPDATE inv_asset_details
                SET bos_number = ?
                WHERE item_id = ?
            ");
            foreach ($lineItems as $li) {
                $updateBosRef->execute([$bos['bos_number'], $li['item_id']]);
            }

            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'COMPLETED',
                'BOS completed. ' . ($completionNotes ? "Notes: $completionNotes" : ''));

        /* Cancel (DRAFT|SUBMITTED → CANCELLED) */
        } elseif ($action === 'cancel'
                  && in_array($bos['status'], ['DRAFT', 'SUBMITTED'], true)
                  && ((int) $_SESSION['user_id'] === (int) $bos['initiated_by']
                      || has_permission('approve_board_of_survey'))) {
            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            $pdo->prepare(
                "UPDATE inv_board_of_survey
                 SET status = 'CANCELLED',
                     supporting_notes = CONCAT(COALESCE(supporting_notes,''), IF(? != '', CONCAT('\nCancelled: ', ?), ''))
                 WHERE bos_id = ?"
            )->execute([$cancelReason, $cancelReason, $bosId]);
            logInventoryAudit($pdo, 'inv_board_of_survey', $bosId, 'CANCELLED',
                'BOS cancelled. ' . ($cancelReason ? "Reason: $cancelReason" : ''));

        } else {
            throw new Exception('Invalid or unauthorised action.');
        }

        $pdo->commit();
        pop('Board of Survey updated.',
            "/inventory/board_of_survey/view.php?id=$bosId", 1800, 'success');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = extractDbMessage($e);
    }

    /* Reload record after POST */
    $bos = $pdo->prepare("
        SELECT b.*, u.full_name AS initiator_name,
               rv.full_name AS reviewer_name, av.full_name AS approver_name,
               l.location_code, l.site_name
        FROM inv_board_of_survey b
        LEFT JOIN users u  ON b.initiated_by = u.user_id
        LEFT JOIN users rv ON b.reviewed_by  = rv.user_id
        LEFT JOIN users av ON b.approved_by  = av.user_id
        LEFT JOIN inv_locations l ON b.location_id = l.location_id
        WHERE b.bos_id = ?
    ");
    $bos->execute([$bosId]);
    $bos = $bos->fetch(PDO::FETCH_ASSOC);
}

/* ── Status badge ────────────────────────────────────────────────────────── */
$statusColor = match($bos['status']) {
    'APPROVED'     => 'success',
    'COMPLETED'    => 'primary',
    'UNDER_REVIEW' => 'info',
    'SUBMITTED'    => 'warning',
    'REJECTED'     => 'danger',
    'CANCELLED'    => 'dark',
    default        => 'secondary',
};

$recommendations = [
    'DISPOSE'   => 'Dispose',
    'REPAIR'    => 'Repair',
    'TRANSFER'  => 'Transfer',
    'WRITE_OFF' => 'Write-Off',
    'RETAIN'    => 'Retain',
    'AUCTION'   => 'Auction',
    'DONATE'    => 'Donate',
    'OTHER'     => 'Other',
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard2-pulse"></i>
        Board of Survey — <strong><?= htmlspecialchars($bos['bos_number']) ?></strong>
    </h2>
    <a href="/inventory/board_of_survey/list.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to List
    </a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i>
    <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- BOS Details -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="bi bi-info-circle"></i> Survey Details
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <small class="text-muted">BOS Number</small>
                        <div><strong><?= htmlspecialchars($bos['bos_number']) ?></strong></div>
                    </div>
                    <div class="col-sm-4">
                        <small class="text-muted">Status</small>
                        <div>
                            <span class="badge bg-<?= $statusColor ?> fs-6">
                                <?= str_replace('_', ' ', $bos['status']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <small class="text-muted">Survey Date</small>
                        <div><?= htmlspecialchars($bos['survey_date'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-4">
                        <small class="text-muted">Initiated By</small>
                        <div><?= htmlspecialchars($bos['initiator_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-4">
                        <small class="text-muted">Submitted At</small>
                        <div><?= htmlspecialchars($bos['submitted_at'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-4">
                        <small class="text-muted">Location</small>
                        <div>
                            <?php if ($bos['location_code']): ?>
                            <code><?= htmlspecialchars($bos['location_code']) ?></code>
                            <?= $bos['site_name'] ? ' — ' . htmlspecialchars($bos['site_name']) : '' ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Reason for Survey</small>
                        <div><?= nl2br(htmlspecialchars($bos['reason_for_survey'])) ?></div>
                    </div>
                    <?php if ($bos['board_recommendation']): ?>
                    <div class="col-sm-6">
                        <small class="text-muted">Board Recommendation</small>
                        <div>
                            <span class="badge bg-info text-dark fs-6">
                                <?= htmlspecialchars($recommendations[$bos['board_recommendation']] ?? $bos['board_recommendation']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bos['recommendation_notes']): ?>
                    <div class="col-12">
                        <small class="text-muted">Recommendation Notes</small>
                        <div><?= nl2br(htmlspecialchars($bos['recommendation_notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bos['reviewer_name']): ?>
                    <div class="col-sm-6">
                        <small class="text-muted">Reviewed By</small>
                        <div><?= htmlspecialchars($bos['reviewer_name']) ?>
                             <?= $bos['reviewed_at'] ? '<span class="text-muted small">(' . $bos['reviewed_at'] . ')</span>' : '' ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bos['approver_name']): ?>
                    <div class="col-sm-6">
                        <small class="text-muted">Approved / Decided By</small>
                        <div><?= htmlspecialchars($bos['approver_name']) ?>
                             <?= $bos['approved_at'] ? '<span class="text-muted small">(' . $bos['approved_at'] . ')</span>' : '' ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bos['approval_notes']): ?>
                    <div class="col-12">
                        <small class="text-muted">Approval / Rejection Notes</small>
                        <div class="alert alert-light py-2 mb-0">
                            <?= nl2br(htmlspecialchars($bos['approval_notes'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($bos['supporting_notes']): ?>
                    <div class="col-12">
                        <small class="text-muted">Supporting Notes</small>
                        <div><?= nl2br(htmlspecialchars($bos['supporting_notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Actions -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="bi bi-gear"></i> Workflow Actions
            </div>
            <div class="card-body d-flex flex-column gap-3">

                <?php if ($bos['status'] === 'DRAFT'): ?>
                <form method="POST">
                    <button class="btn btn-primary w-100 btn-lg" name="action" value="submit">
                        <i class="bi bi-send"></i> Submit for Review
                    </button>
                </form>
                <form method="POST">
                    <input type="text" name="cancel_reason" class="form-control mb-2"
                           placeholder="Reason for cancellation…">
                    <button class="btn btn-outline-danger w-100" name="action" value="cancel">
                        <i class="bi bi-x-circle"></i> Cancel BOS
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($bos['status'] === 'SUBMITTED' && has_permission('approve_board_of_survey')): ?>
                <form method="POST">
                    <button class="btn btn-info w-100 mb-2" name="action" value="start_review">
                        <i class="bi bi-eye"></i> Start Review
                    </button>
                </form>
                <?php endif; ?>

                <?php if (in_array($bos['status'], ['SUBMITTED', 'UNDER_REVIEW'], true)
                          && has_permission('approve_board_of_survey')): ?>
                <form method="POST">
                    <div class="mb-2">
                        <label class="form-label small text-muted">Board Recommendation</label>
                        <select name="board_recommendation" class="form-select form-select-sm mb-2">
                            <option value="">— Confirm recommendation —</option>
                            <?php foreach ($recommendations as $val => $label): ?>
                            <option value="<?= $val ?>"
                                <?= $bos['board_recommendation'] === $val ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="recommendation_notes" class="form-control form-control-sm mb-2"
                               placeholder="Recommendation rationale…"
                               value="<?= htmlspecialchars($bos['recommendation_notes'] ?? '') ?>">
                        <textarea name="approval_notes" class="form-control form-control-sm mb-2" rows="2"
                                  placeholder="Approval notes…"></textarea>
                    </div>
                    <button class="btn btn-success w-100 mb-2" name="action" value="approve">
                        <i class="bi bi-check-circle"></i> Approve
                    </button>
                    <hr class="my-1">
                    <textarea name="rejection_reason" class="form-control form-control-sm mb-2"
                              rows="2" placeholder="Rejection reason (required)…"></textarea>
                    <button class="btn btn-danger w-100" name="action" value="reject">
                        <i class="bi bi-x-circle"></i> Reject
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($bos['status'] === 'APPROVED'): ?>
                <form method="POST">
                    <textarea name="completion_notes" class="form-control mb-2" rows="2"
                              placeholder="Completion notes (optional)…"></textarea>
                    <button class="btn btn-success w-100 btn-lg" name="action" value="complete">
                        <i class="bi bi-check2-all"></i> Mark as Completed
                    </button>
                </form>
                <?php endif; ?>

                <?php if (in_array($bos['status'], ['COMPLETED', 'REJECTED', 'CANCELLED'], true)): ?>
                <div class="alert alert-secondary text-center mb-0">
                    <i class="bi bi-lock"></i> This Board of Survey is closed.
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- Line Items -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <i class="bi bi-list-ol"></i> Assets / Items Under Survey
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-secondary">
                    <tr>
                        <th>#</th>
                        <th>Item Code</th>
                        <th>Item Name</th>
                        <th>Asset Tag</th>
                        <th>Serial Number</th>
                        <th class="text-end">Quantity</th>
                        <th>Condition</th>
                        <th>Item Recommendation</th>
                        <th class="text-end">Est. Value ($)</th>
                        <th>Surveyor Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lineItems)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No items recorded.</td>
                    </tr>
                    <?php else: foreach ($lineItems as $n => $li): ?>
                    <tr>
                        <td class="text-muted"><?= $n + 1 ?></td>
                        <td><code><?= htmlspecialchars($li['item_code']) ?></code></td>
                        <td>
                            <strong><?= htmlspecialchars($li['item_name']) ?></strong>
                            <?php if ($li['description']): ?>
                            <div class="text-muted" style="font-size:0.75rem;">
                                <?= htmlspecialchars(mb_strimwidth($li['description'], 0, 60, '…')) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($li['asset_code'] ?? '—') ?></td>
                        <td><code><?= htmlspecialchars($li['serial_number'] ?? '—') ?></code></td>
                        <td class="text-end fw-bold"><?= number_format((float) $li['quantity'], 2) ?></td>
                        <td>
                            <?php
                            $condColor = match($li['condition_at_survey'] ?? '') {
                                'Good'         => 'success',
                                'Fair'         => 'info',
                                'Poor','Damaged' => 'warning',
                                'Irreparable','Missing Parts' => 'danger',
                                default        => 'secondary',
                            };
                            ?>
                            <?php if ($li['condition_at_survey']): ?>
                            <span class="badge bg-<?= $condColor ?>">
                                <?= htmlspecialchars($li['condition_at_survey']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($li['item_recommendation']): ?>
                            <span class="badge bg-info text-dark">
                                <?= htmlspecialchars($recommendations[$li['item_recommendation']] ?? $li['item_recommendation']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= $li['estimated_value'] !== null
                                ? '$' . number_format((float) $li['estimated_value'], 2)
                                : '—' ?>
                        </td>
                        <td><?= htmlspecialchars($li['surveyor_notes'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($lineItems)): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="8" class="text-end fw-bold">Totals:</td>
                        <td class="text-end fw-bold">
                            $<?= number_format(
                                array_sum(array_column($lineItems, 'estimated_value')), 2
                            ) ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Audit Trail -->
<?php if (!empty($auditRows)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white">
        <i class="bi bi-clock-history"></i> Audit Trail
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-secondary">
                    <tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditRows as $al): ?>
                    <tr>
                        <td class="text-muted"><?= htmlspecialchars($al['logged_at']) ?></td>
                        <td><?= htmlspecialchars($al['full_name'] ?? 'System') ?></td>
                        <td>
                            <span class="badge bg-secondary">
                                <?= htmlspecialchars($al['action']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($al['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
