<?php
$REQUIRE_PERMISSION = 'create_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/policy.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/workflow.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';
require_once $_SERVER['DOCUMENT_ROOT'] . "/services/AdminWorkflowOverrideService.php";

$roleName = $_SESSION['role_name'] ?? '';
$isAdmin = in_array($roleName, ['Admin', 'SuperAdmin'], true);
$adminWorkflowOptions = getAdminWorkflowStatusOptions();

/* ---------- Fetch direct procurement threshold from system_config ---------- */
$directThreshold = 500000.00; // default
$cfgStmt2 = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'direct_procurement_threshold'");
$cfgStmt2->execute();
$cfgVal2 = $cfgStmt2->fetchColumn();
if ($cfgVal2 !== false) {
    $directThreshold = (float)$cfgVal2;
}

/* ---------- Handle POST before any output ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // FIX: define all required variables explicitly
        $branch_id   = (int)($_POST['branch_id'] ?? 0);
        $requestDateRaw = trim($_POST['request_date'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $request_type = 'REGULAR'; // This form is for regular procurement only
        $estimated_value = (float)($_POST['estimated_value'] ?? 0);
        $currency = in_array(($_POST['currency'] ?? ''), ['JMD', 'USD']) ? $_POST['currency'] : 'JMD';
        $usd_rate = null;
        
        // NEW: Capture work_performed and goods_delivered flags
        $workPerformed = isset($_POST['work_performed']) && $_POST['work_performed'] === 'on' ? 1 : 0;
        $goodsDelivered = isset($_POST['goods_delivered']) && $_POST['goods_delivered'] === 'on' ? 1 : 0;
        $poRequirementNotes = trim($_POST['po_requirement_notes'] ?? '');
        $initialWorkflowStatus = 'DRAFT';
        $initialWorkflowReason = '';

        if ($isAdmin) {
            $initialWorkflowStatus = strtoupper(trim((string)($_POST['workflow_status'] ?? 'DRAFT')));
            $initialWorkflowReason = trim((string)($_POST['workflow_override_reason'] ?? ''));
            if (!isset($adminWorkflowOptions[$initialWorkflowStatus])) {
                throw new Exception("Invalid workflow status selected.");
            }
            if ($initialWorkflowStatus !== 'DRAFT' && mb_strlen($initialWorkflowReason) < 5) {
                throw new Exception("A reason of at least 5 characters is required for workflow status overrides.");
            }
        }

        // If USD, get the current exchange rate
        if ($currency === 'USD') {
            $rateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
            $rateStmt->execute();
            $usd_rate = (float)($rateStmt->fetchColumn() ?: 155.00);
        }

        if ($branch_id <= 0) {
            throw new Exception("Branch is required.");
        }

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception("At least one item is required.");
        }

        /* ---------- Date policy ---------- */
        $reqDate = DateTimeImmutable::createFromFormat('Y-m-d', $requestDateRaw);
        $tz = new DateTimeZone(date_default_timezone_get());
        $today = new DateTimeImmutable('today', $tz);

        if (!$reqDate) {
            throw new Exception("Invalid request date.");
        }

        if ($reqDate < $today) {
            policyViolation(
                $pdo,
                'BACKDATED_REQUEST_ATTEMPT',
                'Back-dating of procurement request was attempted'
            );
        }

        $pdo->beginTransaction();

        // FIX: consistent variable
        $requestNumber = generateRequestNumber($pdo);

        /* ---------- Insert procurement request ---------- */
        $stmt = $pdo->prepare("
            INSERT INTO procurement_requests
            (branch_id, request_number, request_date, description, created_by, status, request_type, estimated_value, currency, usd_rate, work_performed, goods_delivered, po_requirement_notes)
            VALUES (?, ?, ?, ?, ?, 'Draft', ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $branch_id,
            $requestNumber,
            $requestDateRaw,
            $description,
            $_SESSION['user_id'],
            $request_type,
            $estimated_value,
            $currency,
            $usd_rate,
            $workPerformed,
            $goodsDelivered,
            $poRequirementNotes ?: null
        ]);

        // FIX: correct request ID
        $requestId = (int)$pdo->lastInsertId();

        /* ---------- Insert items ---------- */
        $itemStmt = $pdo->prepare("
            INSERT INTO procurement_request_items
            (request_id, item_name, specification, quantity, remarks)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($_POST['items'] as $item) {
            if (empty($item['name']) || empty($item['qty'])) {
                continue;
            }

            $itemStmt->execute([
                $requestId,
                $item['name'],
                $item['spec'] ?? null,
                (int)$item['qty'],
                $item['remarks'] ?? null
            ]);
        }

        /* ---------- Optional supporting memo upload ---------- */
        if (isset($_FILES['memo_file']) && $_FILES['memo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $memoFile = $_FILES['memo_file'];
            if ($memoFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Memo upload failed. Please try again.");
            }

            $memoStored = SecureFileStorage::storeUploadedFile(
                $memoFile,
                'request_documents',
                'MEMO_' . $requestId,
                [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png'
                ],
                25 * 1024 * 1024
            );

            $memoStmt = $pdo->prepare("
                INSERT INTO request_documents
                (request_id, document_type, document_name, document_path, uploaded_by, notes)
                VALUES (?, 'MEMO', ?, ?, ?, 'Supporting memo attached at request creation')
            ");
            $memoStmt->execute([
                $requestId,
                $memoStored['original_name'],
                $memoStored['storage_path'],
                $_SESSION['user_id']
            ]);

            logAudit(
                $pdo,
                'request_documents',
                (int)$pdo->lastInsertId(),
                'CREATE',
                'Supporting memo uploaded with new request ' . $requestNumber
            );
        }

        /* ---------- Audit ---------- */
        logAudit(
            $pdo,
            'procurement_requests',
            $requestId,
            'CREATE',
            'Procurement request created'
        );

        $overrideService = null;
        if ($isAdmin && $initialWorkflowStatus !== 'DRAFT') {
            $overrideService = new AdminWorkflowOverrideService(
                $pdo,
                (int)($_SESSION['user_id'] ?? 0),
                $roleName,
                $_SESSION['full_name'] ?? 'System'
            );
            $overrideResult = $overrideService->overrideStatus($requestId, $initialWorkflowStatus, $initialWorkflowReason, false);
            if (!$overrideResult['success']) {
                throw new Exception($overrideResult['error']);
            }
        }
        $pdo->commit();
        if ($overrideService !== null) {
            $overrideService->sendStatusNotifications($requestId, $initialWorkflowStatus);
        }
modalPop(
    $initialWorkflowStatus === 'DRAFT' ? "Draft Saved" : "Request Saved",
    $initialWorkflowStatus === 'DRAFT'
        ? "Your procurement request was saved as a draft. Submit it to send for approval."
        : "Your procurement request was saved and moved to the selected workflow status.",
    "/procurement/view.php?id=".$requestId,
    "success"
);
header("Location: /procurement/list.php");
exit;




    } catch (Throwable $e) {
        if (isset($memoStored)) {
            SecureFileStorage::deleteStoredFile($memoStored['storage_path']);
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Procurement add failed: " . $e->getMessage());
        $_SESSION['error'] = "Error saving procurement request.";
    }
}


// ---------- Only now, render the page ----------
require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";

// Data needed for the form (safe to run on GET or after a failed POST)
// Hide Finance/Accounts (id=4) and Quality Assurance (id=7) from request creation
$branches = $pdo->query("SELECT * FROM branches WHERE is_active = 1 AND branch_id NOT IN (4, 7) ORDER BY branch_name")->fetchAll();
$previewRequestNumber = generateRequestNumber($pdo);

// Get current USD rate for JS
$sysRateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
$sysRateStmt->execute();
$jsUsdRate = (float)($sysRateStmt->fetchColumn() ?: 155.00);
?>

<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex align-items-center mb-3">
        <img src="/logo/cropped-Logo.png" alt="Logo" style="height:36px;width:auto;" class="me-3">
        <div>
          <h3 class="section-title mb-1">
            <i class="bi bi-file-earmark-plus me-2"></i>New Procurement Request
            <span class="badge bg-secondary ms-2">Regular Procurement</span>
          </h3>
          <small class="text-muted">Department of Government Chemist</small>
        </div>
      </div>
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash']['type']) ?> alert-dismissible fade show">
          <?= htmlspecialchars($_SESSION['flash']['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-bold">Currency <span class="text-danger">*</span></label>
            <select name="currency" id="currency_select" class="form-select" required onchange="updateCurrencyLabel()">
              <option value="JMD" selected>JMD - Jamaican Dollar</option>
              <option value="USD">USD - US Dollar</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Estimated Value <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text" id="currency_label">JMD</span>
              <input type="number" name="estimated_value" id="estimated_value"
                     class="form-control" step="0.01" min="0" required
                     onchange="updateThresholdHint()" onkeyup="updateThresholdHint()">
            </div>
            <small class="text-muted" id="threshold_hint"></small>
            <small class="text-info d-none" id="usd_conversion_hint"></small>
          </div>
          <div class="col-md-4" id="usd_rate_display" style="display:none;">
            <label class="form-label fw-bold">Exchange Rate</label>
            <input type="text" class="form-control bg-light" id="usd_rate_preview" readonly>
            <small class="text-muted">System rate (auto-applied)</small>
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-bold">Branch <span class="text-danger">*</span></label>
            <select name="branch_id" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($branches as $b): ?>
                <option value="<?= $b['branch_id'] ?>">
                  <?= htmlspecialchars($b['branch_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Request Number</label>
            <input type="text"
                   class="form-control bg-light"
                   value="<?= htmlspecialchars($previewRequestNumber) ?>"
                   readonly>
            <small class="text-muted">Auto-generated by system</small>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Request Date <span class="text-danger">*</span></label>
            <input type="date"
                   name="request_date"
                   class="form-control"
                   max="<?= date('Y-m-d') ?>"
                   required>
          </div>
        </div>

        <!-- NEW: PO Requirement Decision Flags -->
        <div class="row g-3 mb-3 border-top pt-3">
          <div class="col-12">
            <div class="alert alert-info" role="alert">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Purchase Order Determination:</strong> Indicate whether work has already been performed and goods have already been delivered. 
              If both are checked, a Purchase Order will not be required for this request.
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-check">
              <input type="checkbox" name="work_performed" id="work_performed" class="form-check-input" onchange="updatePoRequirementInfo()">
              <label class="form-check-label" for="work_performed">
                <span class="fw-bold">Work has already been performed</span>
                <br>
                <small class="text-muted">Check if the requested work or service has already been completed</small>
              </label>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-check">
              <input type="checkbox" name="goods_delivered" id="goods_delivered" class="form-check-input" onchange="updatePoRequirementInfo()">
              <label class="form-check-label" for="goods_delivered">
                <span class="fw-bold">Goods have already been delivered</span>
                <br>
                <small class="text-muted">Check if the ordered goods have already been received</small>
              </label>
            </div>
          </div>
          <div class="col-12">
            <div id="po_requirement_status" class="alert alert-warning d-none">
              <strong>PO Status:</strong> <span id="po_requirement_text"></span>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Additional Notes (Optional)</label>
            <textarea name="po_requirement_notes" id="po_requirement_notes" class="form-control" rows="2" 
                      placeholder="Provide justification or additional context for the PO requirement decision"
                      maxlength="500"></textarea>
            <small class="text-muted">Max 500 characters</small>
          </div>
        </div>

        <div class="mb-3">
          <label for="description" class="form-label fw-bold">Brief Description <span class="text-danger">*</span></label>
          <textarea name="description" id="description" class="form-control" rows="3" maxlength="500" required
                    placeholder="Briefly describe the purpose of this procurement request"></textarea>
          <small class="text-muted">Max 500 characters. This will be shown as a summary on the procurement list.</small>
        </div>
        <?php if ($isAdmin): ?>
          <div class="alert alert-warning border-0 mb-3">
            <div class="fw-bold mb-1">
              <i class="bi bi-exclamation-triangle me-1"></i>Admin Workflow Status Override
            </div>
            Changing the workflow status manually may bypass normal approval steps. Continue only when authorized.
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="workflow_status" class="form-label fw-bold">Workflow Status</label>
              <select name="workflow_status" id="workflow_status" class="form-select" data-original-status="DRAFT">
                <?php foreach ($adminWorkflowOptions as $statusCode => $statusInfo): ?>
                  <option value="<?= htmlspecialchars($statusCode) ?>" <?= $statusCode === 'DRAFT' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($statusInfo['label']) ?> — <?= htmlspecialchars($statusInfo['description']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label for="workflow_override_reason" class="form-label fw-bold">Override Reason / Comment</label>
              <textarea name="workflow_override_reason" id="workflow_override_reason" class="form-control" rows="2" placeholder="Required when changing workflow status"></textarea>
            </div>
          </div>
        <?php endif; ?>
        <h5 class="mt-4 mb-2"><i class="bi bi-list-task me-2"></i> Items Required</h5>
        <div class="table-responsive mb-3">
          <table class="table table-bordered align-middle" id="itemsTable">
            <thead class="table-dark">
              <tr>
                <th>Item(s)</th>
                <th>Specification(s)</th>
                <th width="100">Quantity</th>
                <th>Remarks</th>
                <th width="50"></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><input name="items[0][name]" class="form-control" required></td>
                <td><input name="items[0][spec]" class="form-control"></td>
                <td><input name="items[0][qty]" type="number" min="1" class="form-control" required></td>
                <td><input name="items[0][remarks]" class="form-control"></td>
                <td>
                  <button type="button" class="btn btn-danger btn-sm removeRow" title="Remove"><i class="bi bi-x-circle"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="addRow">
          <i class="bi bi-plus-circle"></i> Add Item
        </button>
        <div class="mb-3">
          <label class="form-label"><i class="bi bi-paperclip me-1"></i> Supporting Memo (optional)</label>
          <input type="file" name="memo_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
          <small class="text-muted">Attach a supporting memo (PDF, Word, or image). It will remain accessible throughout the request lifecycle.</small>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-success"><i class="bi bi-save me-1"></i> Save</button>
          <a href="/procurement/list.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i> Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>


<script>
const DIRECT_THRESHOLD = <?= $directThreshold ?>;
const USD_RATE = <?= $jsUsdRate ?>;

function updateCurrencyLabel() {
    const currency = document.getElementById('currency_select').value;
    document.getElementById('currency_label').textContent = currency;
    const rateDisplay = document.getElementById('usd_rate_display');
    const ratePreview = document.getElementById('usd_rate_preview');
    if (currency === 'USD') {
        rateDisplay.style.display = '';
        ratePreview.value = '1 USD = ' + USD_RATE.toFixed(2) + ' JMD';
    } else {
        rateDisplay.style.display = 'none';
    }
    updateThresholdHint();
}

// Show workflow info based on estimated value and threshold
function updateThresholdHint() {
    const val = parseFloat(document.getElementById('estimated_value').value) || 0;
    const currency = document.getElementById('currency_select').value;
    const hint = document.getElementById('threshold_hint');
    const convHint = document.getElementById('usd_conversion_hint');

    // For threshold comparison, convert to JMD if needed
    const jmdVal = currency === 'USD' ? val * USD_RATE : val;

    if (currency === 'USD' && val > 0) {
        convHint.classList.remove('d-none');
        convHint.innerHTML = '<i class="bi bi-arrow-right-circle"></i> ≈ JMD ' + jmdVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        convHint.classList.add('d-none');
    }

    if (jmdVal > 0 && jmdVal <= DIRECT_THRESHOLD) {
        hint.innerHTML = '<span class=\"text-info\">ℹ️ Under threshold — simplified RFQ workflow (branch supervisor approval)</span>';
    } else if (jmdVal > DIRECT_THRESHOLD) {
        hint.innerHTML = '<span class=\"text-warning\">⚠️ Over threshold — full RFQ with committee evaluation (HOD approval)</span>';
    } else {
        hint.innerHTML = '';
    }
}

// NEW: Update PO requirement status display
function updatePoRequirementInfo() {
    const workPerformed = document.getElementById('work_performed').checked;
    const goodsDelivered = document.getElementById('goods_delivered').checked;
    const statusDiv = document.getElementById('po_requirement_status');
    const statusText = document.getElementById('po_requirement_text');
    
    if (workPerformed && goodsDelivered) {
        // Both true: NO PO required
        statusDiv.classList.remove('alert-warning', 'd-none');
        statusDiv.classList.add('alert-success');
        statusText.textContent = '✓ Purchase Order NOT REQUIRED (work performed + goods delivered)';
    } else if (workPerformed || goodsDelivered) {
        // One true: PO still required
        statusDiv.classList.remove('alert-success', 'd-none');
        statusDiv.classList.add('alert-warning');
        const missing = !workPerformed ? 'work' : 'goods delivery';
        statusText.textContent = `⚠ Purchase Order REQUIRED (${missing} not yet completed)`;
    } else {
        // Both false: Hide status
        statusDiv.classList.add('d-none');
    }
}


// Optional: dynamic add/remove rows (does not change backend logic)
let rowIndex = 1;
document.getElementById('addRow').addEventListener('click', function () {
  const tbody = document.querySelector('#itemsTable tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input name="items[${rowIndex}][name]" class="form-control" required></td>
    <td><input name="items[${rowIndex}][spec]" class="form-control"></td>
    <td><input name="items[${rowIndex}][qty]" type="number" min="1" class="form-control" required></td>
    <td><input name="items[${rowIndex}][remarks]" class="form-control"></td>
    <td><button type="button" class="btn btn-danger btn-sm removeRow">×</button></td>
  `;
  tbody.appendChild(tr);
  rowIndex++;
});
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('removeRow')) {
    const tr = e.target.closest('tr');
    if (tr) tr.remove();
  }
});

const createForm = document.querySelector('form[method="post"]');
if (createForm) {
  createForm.addEventListener('submit', function (e) {
    const workflowStatus = document.getElementById('workflow_status');
    if (workflowStatus && workflowStatus.value !== workflowStatus.dataset.originalStatus) {
      const reason = document.getElementById('workflow_override_reason');
      if (!reason || reason.value.trim().length < 5) {
        e.preventDefault();
        alert('Please enter a reason of at least 5 characters for the workflow status override.');
        return false;
      }
      if (!confirm('Changing the workflow status manually may bypass normal approval steps. Continue only when authorized.')) {
        e.preventDefault();
        return false;
      }
    }
  });
}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>