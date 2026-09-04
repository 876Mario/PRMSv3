<?php
$REQUIRE_PERMISSION = 'create_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

const RECONCILIATION_TOLERANCE = 0.0001;
const SECONDS_PER_HOUR = 3600;

$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) {
    pop("Invalid petty cash request reference.", "/petty_cash/list.php", 2200, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT pr.*, b.branch_name
    FROM procurement_requests pr
    LEFT JOIN branches b ON b.branch_id = pr.branch_id
    WHERE pr.request_id = ?
      AND pr.request_type = 'PETTY_CASH'
    LIMIT 1
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Petty cash request not found.", "/petty_cash/list.php", 2200, 'error');
    exit;
}

$canAct = ((int)$request['created_by'] === (int)($_SESSION['user_id'] ?? 0)) || hasPermission('admin_override');
if (!$canAct) {
    pop("You are not allowed to reconcile this request.", "/petty_cash/view.php?request_id=".$requestId, 2200, 'error');
    exit;
}

$disbStmt = $pdo->prepare("
    SELECT disburse_id, amount_authorized, disbursement_date, disbursement_deadline
    FROM petty_cash_disbursements
    WHERE request_id = ?
    LIMIT 1
");
$disbStmt->execute([$requestId]);
$disbursement = $disbStmt->fetch(PDO::FETCH_ASSOC);

if (!$disbursement) {
    pop("This petty cash request has not been disbursed yet.", "/petty_cash/view.php?request_id=".$requestId, 2500, 'warning');
    exit;
}

$existingStmt = $pdo->prepare("
    SELECT reconcile_id
    FROM petty_cash_reconciliations
    WHERE disburse_id = ?
    LIMIT 1
");
$existingStmt->execute([(int)$disbursement['disburse_id']]);
$existingReconcileId = (int)($existingStmt->fetchColumn() ?: 0);
if ($existingReconcileId > 0) {
    pop("Reconciliation was already submitted for this petty cash request.", "/petty_cash/view.php?request_id=".$requestId, 2200, 'info');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $purchaseAmount = (float)($_POST['purchase_amount'] ?? 0);
        $changeAmount = (float)($_POST['change_amount'] ?? 0);
        $notes = trim($_POST['reconciliation_notes'] ?? '');
        $authorizedAmount = (float)($disbursement['amount_authorized'] ?? 0);

        if ($purchaseAmount <= 0) {
            throw new Exception("Purchase amount must be greater than zero.");
        }
        if ($changeAmount < 0) {
            throw new Exception("Change amount cannot be negative.");
        }
        $reconciliationDelta = abs(($purchaseAmount + $changeAmount) - $authorizedAmount);
        if ($reconciliationDelta > RECONCILIATION_TOLERANCE) {
            throw new Exception(sprintf(
                "Reconciliation failed: difference of %.2f exceeds tolerance of %.4f.",
                $reconciliationDelta,
                RECONCILIATION_TOLERANCE
            ));
        }

        $now = new DateTime();
        $disbursementTime = new DateTime($disbursement['disbursement_date']);
        $deadline = new DateTime($disbursement['disbursement_deadline']);
        $seconds = max(0, $now->getTimestamp() - $disbursementTime->getTimestamp());
        $hoursFromDisbursement = round($seconds / SECONDS_PER_HOUR, 2);
        $deadlineMet = $now <= $deadline ? 1 : 0;

        $pdo->beginTransaction();

        $insert = $pdo->prepare("
            INSERT INTO petty_cash_reconciliations
            (disburse_id, purchase_amount, change_amount, submitted_by, submission_deadline_met, hours_from_disbursement, reconciliation_notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING_VERIFICATION')
        ");
        $insert->execute([
            (int)$disbursement['disburse_id'],
            $purchaseAmount,
            $changeAmount,
            (int)($_SESSION['user_id'] ?? 0),
            $deadlineMet,
            $hoursFromDisbursement,
            $notes !== '' ? $notes : null
        ]);
        $reconcileId = (int)$pdo->lastInsertId();

        $pdo->prepare("
            UPDATE petty_cash_disbursements
            SET status = 'RECONCILED',
                updated_at = NOW()
            WHERE disburse_id = ?
        ")->execute([(int)$disbursement['disburse_id']]);

        $oldStatus = strtoupper((string)($request['status'] ?? ''));
        $newStatus = 'PENDING_RECONCILIATION';
        $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                updated_at = NOW()
            WHERE request_id = ?
        ")->execute([$newStatus, $requestId]);

        logAudit(
            $pdo,
            'petty_cash_reconciliations',
            $reconcileId,
            'CREATE',
            'Petty cash reconciliation submitted'
        );

        logAudit(
            $pdo,
            'procurement_requests',
            $requestId,
            'STATUS_CHANGE',
            "Petty Cash Request: {$oldStatus} → {$newStatus} (Reconciliation submitted)"
        );

        logRequestTimeline(
            $pdo,
            $requestId,
            'PENDING_RECONCILIATION',
            'Petty cash reconciliation submitted by ' . ($_SESSION['full_name'] ?? 'Unknown')
        );

        $pdo->commit();

        pop(
            "Reconciliation submitted successfully.",
            "/petty_cash/view.php?request_id=".$requestId,
            1600,
            "success"
        );
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<div class="container-fluid mt-4">
  <div class="row justify-content-center">
    <div class="col-xl-8 col-lg-9">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Petty Cash Reconciliation</h5>
          <span class="badge bg-primary"><?= htmlspecialchars($request['request_number']) ?></span>
        </div>
        <div class="card-body">
          <?php if ($error): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <small class="text-muted d-block">Branch</small>
              <strong><?= htmlspecialchars($request['branch_name'] ?? 'N/A') ?></strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Authorized Amount</small>
              <strong class="text-success">
                <?= htmlspecialchars(normalizeCurrency($request['currency'] ?? 'JMD')) ?>
                <?= number_format((float)$disbursement['amount_authorized'], 2) ?>
              </strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Disbursement Date</small>
              <strong><?= date('M d, Y g:i A', strtotime($disbursement['disbursement_date'])) ?></strong>
            </div>
            <div class="col-md-6">
              <small class="text-muted d-block">Deadline</small>
              <strong><?= date('M d, Y g:i A', strtotime($disbursement['disbursement_deadline'])) ?></strong>
            </div>
          </div>

          <form method="post" class="row g-3">
            <div class="col-md-6">
              <label for="purchase_amount" class="form-label">Total Purchase Amount</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                class="form-control"
                id="purchase_amount"
                name="purchase_amount"
                required
                value="<?= htmlspecialchars($_POST['purchase_amount'] ?? '') ?>"
              >
            </div>
            <div class="col-md-6">
              <label for="change_amount" class="form-label">Change / Balance Returned</label>
              <input
                type="number"
                step="0.01"
                min="0"
                class="form-control"
                id="change_amount"
                name="change_amount"
                required
                value="<?= htmlspecialchars($_POST['change_amount'] ?? '0.00') ?>"
              >
            </div>
            <div class="col-12">
              <label for="reconciliation_notes" class="form-label">Notes (optional)</label>
              <textarea
                class="form-control"
                id="reconciliation_notes"
                name="reconciliation_notes"
                rows="4"
                placeholder="Add receipt details, vendors visited, and any reconciliation notes."
              ><?= htmlspecialchars($_POST['reconciliation_notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
              <button type="submit" class="btn btn-success">
                <i class="bi bi-check2-circle me-1"></i>Submit Reconciliation
              </button>
              <a href="/petty_cash/view.php?request_id=<?= $requestId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Request
              </a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>