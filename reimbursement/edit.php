<?php
$REQUIRE_PERMISSION = 'create_reimbursement_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    pop('Invalid request ID.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Fetch existing request */
$stmt = $pdo->prepare("
    SELECT pr.*, pa.authorization_amount, pa.authorization_date, pa.authorization_notes
    FROM procurement_requests pr
    LEFT JOIN pre_authorizations pa ON pr.request_id = pa.request_id
    WHERE pr.request_id = ?
      AND pr.request_type = 'REIMBURSEMENT'
");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    pop('Reimbursement request not found.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Admin override: admins with the dedicated permission may edit any status */
$isAdminEdit = has_permission('edit_reimbursement_request_admin');

/* Only allow editing workflow-defined editable statuses (unless admin override) */
if (!$isAdminEdit && !isEditableRequestStatus('REIMBURSEMENT', (string)$existing['status'])) {
    pop('Only draft or returned requests can be edited.', '/reimbursement/view.php?request_id=' . $id, 3000, 'warning');
    exit;
}

/* Only owner or admin can edit */
if ($existing['created_by'] != $_SESSION['user_id'] && !$isAdminEdit && !has_permission('manage_users')) {
    pop('You are not authorized to edit this request.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* ═══════════════════════════════════════════════════════
   Handle POST - Update Reimbursement Request
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/reimbursement/edit.php?id=' . $id);
    try {
        $branch_id               = (int)($_POST['branch_id'] ?? 0);
        $request_date            = trim($_POST['request_date'] ?? '');
        $description             = trim($_POST['description'] ?? '');
        $estimated_value         = (float)($_POST['estimated_value'] ?? 0);
        $invoice_amount          = (float)($_POST['invoice_amount'] ?? 0);
        $pre_authorization_date  = trim($_POST['pre_authorization_date'] ?? '');
        $pre_authorization_amount = (float)($_POST['pre_authorization_amount'] ?? 0);

        if ($branch_id <= 0) {
            throw new Exception('Branch selection is required.');
        }
        if (empty($request_date)) {
            throw new Exception('Request date is required.');
        }
        if ($invoice_amount <= 0) {
            throw new Exception('Invoice amount must be greater than zero.');
        }
        if (empty($pre_authorization_date) || $pre_authorization_amount <= 0) {
            throw new Exception('Prior authorization details are required.');
        }
        if ($invoice_amount > $pre_authorization_amount) {
            throw new Exception(sprintf(
                'Invoice amount (%s) exceeds pre-authorization amount (%s).',
                number_format($invoice_amount, 2),
                number_format($pre_authorization_amount, 2)
            ));
        }

        $pdo->beginTransaction();

        /* Update request */
        $upStmt = $pdo->prepare("
            UPDATE procurement_requests
            SET branch_id = ?, request_date = ?, description = ?, estimated_value = ?
            WHERE request_id = ?
        ");
        $upStmt->execute([$branch_id, $request_date, $description, $invoice_amount, $id]);

        /* Update or insert pre-authorization */
        $checkAuth = $pdo->prepare("SELECT auth_id FROM pre_authorizations WHERE request_id = ?");
        $checkAuth->execute([$id]);
        if ($checkAuth->fetchColumn()) {
            $authStmt = $pdo->prepare("
                UPDATE pre_authorizations
                SET authorization_date = ?, authorization_amount = ?
                WHERE request_id = ?
            ");
            $authStmt->execute([$pre_authorization_date, $pre_authorization_amount, $id]);
        } else {
            $authStmt = $pdo->prepare("
                INSERT INTO pre_authorizations
                (request_id, authorized_by, authorization_date, authorization_amount, authorization_notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $authStmt->execute([
                $id,
                $_SESSION['user_id'],
                $pre_authorization_date,
                $pre_authorization_amount,
                'Pre-authorization for reimbursement request'
            ]);
        }

        if ($isAdminEdit) {
            $oldStatus = $existing['status'];
            logAudit($pdo, 'procurement_requests', $id, 'ADMIN_UPDATE',
                'Administrator override edit by ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                " (status={$oldStatus}) on reimbursement request {$existing['request_number']}"
            );
        } else {
            logAudit($pdo, 'procurement_requests', $id, 'UPDATE', 'Reimbursement request edited');
        }

        $pdo->commit();

        modalPop(
            'Request Updated',
            'Reimbursement request ' . $existing['request_number'] . ' has been updated successfully.',
            '/reimbursement/view.php?request_id=' . $id,
            'success'
        );
        header('Location: /reimbursement/view.php?request_id=' . $id);
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
              <i class="bi bi-pencil-square me-2" style="color: #667eea;"></i>Edit Reimbursement Request
            </h3>
            <small class="text-muted">
              Editing: <strong><?= htmlspecialchars($existing['request_number']) ?></strong>
              &bull; Status: <span class="badge bg-secondary"><?= htmlspecialchars($existing['status']) ?></span>
            </small>
          </div>
        </div>
        <a href="/reimbursement/view.php?request_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">

        <!-- Step 1: Request Information -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">
              <i class="bi bi-clipboard me-2"></i>Step 1: Request Information
            </h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                <select name="branch_id" class="form-select" required>
                  <option value="">-- Select Branch --</option>
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
                <label class="form-label fw-semibold">Request Date <span class="text-danger">*</span></label>
                <input type="date" name="request_date" class="form-control" required
                       value="<?= htmlspecialchars($existing['request_date'] ?? date('Y-m-d')) ?>">
                <div class="invalid-feedback">Request date is required.</div>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label fw-semibold">Purpose / Description <span class="text-danger">*</span></label>
              <textarea name="description" class="form-control" rows="3" required
                        placeholder="Describe what goods/services were purchased and why..."><?= htmlspecialchars($existing['description'] ?? '') ?></textarea>
              <div class="invalid-feedback">Description is required.</div>
            </div>
          </div>
        </div>

        <!-- Step 2: Prior Authorization -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">
              <i class="bi bi-shield-check me-2"></i>Step 2: Prior Authorization
            </h5>
          </div>
          <div class="card-body">
            <div class="alert alert-info">
              <i class="bi bi-info-circle-fill me-2"></i>
              <strong>Important:</strong> Prior authorization must be obtained <em>before</em> purchasing goods/services.
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Authorization Date <span class="text-danger">*</span></label>
                <input type="date" name="pre_authorization_date" class="form-control" required
                       value="<?= htmlspecialchars($existing['authorization_date'] ?? '') ?>">
                <div class="invalid-feedback">Authorization date is required.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Authorized Amount <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" name="pre_authorization_amount" class="form-control"
                         step="0.01" min="0" required placeholder="0.00"
                         value="<?= htmlspecialchars($existing['authorization_amount'] ?? '') ?>"
                         onchange="updateInvoiceMaxAmount()">
                </div>
                <div class="invalid-feedback">Authorized amount is required.</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Invoice Details -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0">
              <i class="bi bi-receipt me-2"></i>Step 3: Invoice Details
            </h5>
          </div>
          <div class="card-body">
            <div class="alert alert-warning">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <strong>Note:</strong> After approval, you will submit a copy of this invoice to Procurement at GC2,
              and then the original invoice to Finance at GC10A.
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Invoice Amount <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" id="invoice_amount" name="invoice_amount" class="form-control"
                         step="0.01" min="0.01" required placeholder="0.00"
                         value="<?= htmlspecialchars($existing['estimated_value'] ?? '') ?>">
                </div>
                <small class="text-muted">Must not exceed the authorized amount.</small>
                <div class="invalid-feedback">Invoice amount is required.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Estimated Total Value</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="number" name="estimated_value" class="form-control"
                         step="0.01" min="0" placeholder="0.00"
                         value="<?= htmlspecialchars($existing['estimated_value'] ?? '') ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2">
          <button type="submit" class="btn"
                  style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.65rem 1.75rem; font-weight: 600;">
            <i class="bi bi-save me-1"></i> Save Changes
          </button>
          <a href="/reimbursement/view.php?request_id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Cancel
          </a>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
function updateInvoiceMaxAmount() {
    const preAuthAmount = parseFloat(document.querySelector('input[name="pre_authorization_amount"]').value) || 0;
    const invoiceInput = document.getElementById('invoice_amount');
    invoiceInput.max = preAuthAmount;
    if (invoiceInput.value > preAuthAmount && preAuthAmount > 0) {
        invoiceInput.value = preAuthAmount;
    }
}
document.addEventListener('DOMContentLoaded', function () {
    const preAuthInput = document.querySelector('input[name="pre_authorization_amount"]');
    if (preAuthInput) preAuthInput.addEventListener('change', updateInvoiceMaxAmount);

    // Bootstrap form validation
    const form = document.querySelector('.needs-validation');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
