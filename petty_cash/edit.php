<?php
$REQUIRE_PERMISSION = 'create_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pop('Invalid request ID.', '/petty_cash/list.php', 3000, 'error');
    exit;
}

/* Get petty cash limit */
$pettyCashLimit = getPettyCashLimit($pdo);

/* Fetch existing request */
$stmt = $pdo->prepare("
    SELECT * FROM procurement_requests
    WHERE request_id = ? AND request_type = 'PETTY_CASH'
");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    pop('Petty cash request not found.', '/petty_cash/list.php', 3000, 'error');
    exit;
}

/* Admin override: admins with the dedicated permission may edit any status */
$isAdminEdit = has_permission('edit_petty_cash_request_admin');

/* Only allow editing DRAFT status (unless admin override) */
if (!$isAdminEdit && $existing['status'] !== 'DRAFT') {
    pop('Only DRAFT requests can be edited.', '/petty_cash/view.php?request_id=' . $id, 3000, 'warning');
    exit;
}

/* Only owner or admin can edit */
if ($existing['created_by'] != $_SESSION['user_id'] && !$isAdminEdit && !has_permission('manage_users')) {
    pop('You are not authorized to edit this request.', '/petty_cash/list.php', 3000, 'error');
    exit;
}

/* ═══════════════════════════════════════════════════════
   Handle POST - Update Petty Cash Request
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $branch_id         = (int)($_POST['branch_id'] ?? 0);
        $requested_amount  = (float)($_POST['requested_amount'] ?? 0);
        $purpose           = trim($_POST['purpose'] ?? '');

        if ($branch_id <= 0) {
            throw new Exception('Branch selection is required.');
        }
        if ($requested_amount <= 0) {
            throw new Exception('Requested amount must be greater than zero.');
        }
        if ($requested_amount > $pettyCashLimit) {
            throw new Exception(sprintf(
                'Petty cash requests are limited to %s. Amount requested exceeds the limit.',
                number_format($pettyCashLimit, 2)
            ));
        }
        if (empty($purpose)) {
            throw new Exception('Purpose of petty cash request is required.');
        }

        $pdo->beginTransaction();

        $upStmt = $pdo->prepare("
            UPDATE procurement_requests
            SET branch_id = ?, description = ?, estimated_value = ?
            WHERE request_id = ?
        ");
        $upStmt->execute([$branch_id, $purpose, $requested_amount, $id]);

        if ($isAdminEdit) {
            $oldStatus = $existing['status'];
            logAudit($pdo, 'procurement_requests', $id, 'ADMIN_UPDATE',
                'Administrator override edit by ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                " (status={$oldStatus}) on petty cash request {$existing['request_number']}"
            );
        } else {
            logAudit($pdo, 'procurement_requests', $id, 'UPDATE', 'Petty cash request edited');
        }

        $pdo->commit();

        modalPop(
            'Request Updated',
            'Petty cash request ' . $existing['request_number'] . ' has been updated successfully.',
            '/petty_cash/view.php?request_id=' . $id,
            'success'
        );
        header('Location: /petty_cash/view.php?request_id=' . $id);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = extractDbMessage($e);
    }
}

/* Fetch branches */
$branches = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">

      <!-- Header -->
      <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
          <img src="/logo/cropped-Logo.png" alt="Logo" style="height:36px;width:auto;" class="me-3">
          <div>
            <h3 class="mb-1 fw-bold" style="color: #1a1a1a;">
              <i class="bi bi-pencil-square me-2" style="color: #667eea;"></i>Edit Petty Cash Request
            </h3>
            <small class="text-muted">
              Editing: <strong><?= htmlspecialchars($existing['request_number']) ?></strong>
              &bull; Max: <strong>JMD <?= number_format($pettyCashLimit, 2) ?></strong>
            </small>
          </div>
        </div>
        <a href="/petty_cash/view.php?request_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Back
        </a>
      </div>

      <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <strong>Error:</strong> <?= htmlspecialchars($_SESSION['error']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <form method="post" class="needs-validation" novalidate>

        <!-- Process Reminder -->
        <div class="alert alert-info mb-4">
          <h6 class="mb-2 fw-bold">
            <i class="bi bi-list-ol me-2"></i>Petty Cash Process Overview
          </h6>
          <ol class="mb-0 small">
            <li>Complete this form and submit for approval</li>
            <li>Procurement (GC2) endorses the request</li>
            <li>Finance (GC10A) authorizes and disburses cash</li>
            <li><strong>Within 24 hours:</strong> Purchase goods/services, return invoice &amp; change to Finance</li>
            <li>Procurement verifies goods/services received</li>
          </ol>
        </div>

        <!-- Step 1: Request Details -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">
              <i class="bi bi-clipboard me-2"></i>Request Details
            </h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                <select name="branch_id" class="form-select" required>
                  <option value="">-- Select Your Branch --</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['branch_id'] ?>"
                            <?= ((int)$existing['branch_id'] === (int)$b['branch_id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Branch is required.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Requested Amount <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" name="requested_amount" class="form-control"
                         step="0.01" min="0.01" max="<?= $pettyCashLimit ?>" required
                         placeholder="0.00"
                         value="<?= htmlspecialchars($existing['estimated_value'] ?? '') ?>"
                         onchange="validateAmount()">
                </div>
                <div class="form-text text-muted">Maximum: JMD <?= number_format($pettyCashLimit, 2) ?></div>
                <div class="invalid-feedback">Requested amount is required.</div>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label fw-semibold">Purpose of Petty Cash <span class="text-danger">*</span></label>
              <textarea name="purpose" class="form-control" rows="3" required
                        placeholder="Describe what you need to purchase..."><?= htmlspecialchars($existing['description'] ?? '') ?></textarea>
              <div class="invalid-feedback">Purpose is required.</div>
            </div>
          </div>
        </div>

        <!-- 24-Hour Rule Reminder -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">
              <i class="bi bi-exclamation-triangle me-2 text-warning"></i>Important Reminders
            </h5>
          </div>
          <div class="card-body">
            <div class="alert alert-warning mb-0">
              <h6 class="fw-bold">24-Hour Accountability Rule:</h6>
              <ul class="mb-0 small">
                <li>Purchase must be made <strong>within 24 hours</strong> of cash disbursement</li>
                <li>Original invoice must be returned to Finance within 24 hours</li>
                <li>Any change (balance) must be returned to Finance within 24 hours</li>
                <li>Procurement must verify goods/services quality within 24 hours</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2">
          <button type="submit" class="btn"
                  style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.65rem 1.75rem; font-weight: 600;">
            <i class="bi bi-save me-1"></i>Save Changes
          </button>
          <a href="/petty_cash/view.php?request_id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Cancel
          </a>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
function validateAmount() {
    const input = document.querySelector('input[name="requested_amount"]');
    const max = <?= (float)$pettyCashLimit ?>;
    if (!input) return;
    const val = parseFloat(input.value) || 0;
    if (val > max) {
        input.value = max;
        input.classList.add('is-invalid');
    } else {
        input.classList.remove('is-invalid');
    }
}
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.needs-validation');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        form.classList.add('was-validated');
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
