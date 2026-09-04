<?php
$REQUIRE_PERMISSION = 'manage_contracts';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

define('MAX_CONTRACT_DOCUMENT_SIZE', 25 * 1024 * 1024); // 25 MB

/* ===============================
   Fetch vendors and branches for dropdowns
================================ */
$vendorsStmt = $pdo->query("SELECT vendor_id, vendor_name FROM vendors WHERE status = 'ACTIVE' ORDER BY vendor_name");
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC);

$branchesStmt = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name");
$branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);

/* Generate next contract number */
$contractNumber = previewServiceContractNumber($pdo);

/* ===============================
   Handle Form Submission
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contract_number = trim($_POST['contract_number'] ?? $contractNumber);
    $contract_title  = trim($_POST['contract_title'] ?? '');
    $vendor_id       = (int)($_POST['vendor_id'] ?? 0);
    $branch_id       = (int)($_POST['branch_id'] ?? 0);
    $contract_type   = $_POST['contract_type'] ?? 'FIXED_PRICE';
    $description     = trim($_POST['description'] ?? '');
    $total_value     = (float)($_POST['total_value'] ?? 0);
    $currency        = in_array(($_POST['currency'] ?? ''), ['JMD', 'USD']) ? $_POST['currency'] : 'JMD';
    $start_date      = trim($_POST['start_date'] ?? '');
    $end_date        = trim($_POST['end_date'] ?? '');
    $payment_terms   = (int)($_POST['payment_terms'] ?? 30);
    $billing_frequency = $_POST['billing_frequency'] ?? 'MONTHLY';
    $notes           = trim($_POST['notes'] ?? '');

    // Validation
    $errors = [];
    if ($contract_title === '') $errors[] = 'Contract title is required.';
    if ($vendor_id <= 0) $errors[] = 'Vendor is required.';
    if ($branch_id <= 0) $errors[] = 'Department is required.';
    if ($total_value <= 0) $errors[] = 'Contract value must be greater than zero.';
    if ($start_date === '') $errors[] = 'Start date is required.';
    if ($end_date === '') $errors[] = 'End date is required.';
    if ($start_date && $end_date && $end_date <= $start_date) $errors[] = 'End date must be after start date.';

    if (empty($errors)) {
        // Check for duplicate contract number when a manual override was entered
        if ($contract_number !== '' && $contract_number !== $contractNumber) {
            $dupStmt = $pdo->prepare("SELECT contract_id FROM service_contracts WHERE contract_number = ?");
            $dupStmt->execute([$contract_number]);
            if ($dupStmt->fetch()) {
                $errors[] = 'Contract number already exists.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if ($contract_number === '' || $contract_number === $contractNumber) {
                $contract_number = generateServiceContractNumber($pdo);
            }

            // Handle document upload
            $document_path = null;
            if (!empty($_FILES['contract_document']['name'])) {
                $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png',
                    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $file = $_FILES['contract_document'];

                if ($file['error'] === UPLOAD_ERR_OK) {
                    $storedContractDoc = SecureFileStorage::storeUploadedFile(
                        $file,
                        'contracts',
                        $contract_number,
                        [
                            'application/pdf' => 'pdf',
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'application/msword' => 'doc',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                        ],
                        MAX_CONTRACT_DOCUMENT_SIZE
                    );
                    $document_path = $storedContractDoc['storage_path'];
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO service_contracts
                (contract_number, contract_title, vendor_id, branch_id, contract_type,
                 description, total_value, currency, start_date, end_date,
                 payment_terms, billing_frequency, status, document_path, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?, ?)
            ");
            $stmt->execute([
                $contract_number, $contract_title, $vendor_id, $branch_id, $contract_type,
                $description, $total_value, $currency, $start_date, $end_date,
                $payment_terms, $billing_frequency, $document_path, $notes, $_SESSION['user_id']
            ]);

            $newId = $pdo->lastInsertId();
            logAudit($pdo, 'service_contracts', $newId, 'CREATE', "Service contract '$contract_number' created");
            $pdo->commit();

            header("Location: /contracts/view.php?id=$newId");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($storedContractDoc)) {
                SecureFileStorage::deleteStoredFile($storedContractDoc['storage_path']);
            }
            $errors[] = extractDbMessage($e);
        }
    }

    $error = implode('<br>', $errors);
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="section-title">📄 New Service Contract</h3>
    <a href="/contracts/list.php" class="btn btn-secondary btn-sm">← Back to List</a>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>⚠️ Error:</strong> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">📋 Contract Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Contract Number *</label>
                            <input type="text" name="contract_number"
                                   value="<?= htmlspecialchars($_POST['contract_number'] ?? $contractNumber) ?>"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Contract Title *</label>
                            <input type="text" name="contract_title"
                                   value="<?= htmlspecialchars($_POST['contract_title'] ?? '') ?>"
                                   class="form-control" required placeholder="e.g., Garbage Collection Services 2026">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Vendor/Contractor *</label>
                            <select name="vendor_id" class="form-select" required>
                                <option value="">-- Select Vendor --</option>
                                <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['vendor_id'] ?>" <?= (int)($_POST['vendor_id'] ?? 0) === (int)$v['vendor_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['vendor_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Department *</label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= (int)($_POST['branch_id'] ?? 0) === (int)$b['branch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Contract Type *</label>
                            <select name="contract_type" class="form-select" required>
                                <option value="FIXED_PRICE" <?= ($_POST['contract_type'] ?? '') === 'FIXED_PRICE' ? 'selected' : '' ?>>Fixed Price</option>
                                <option value="TIME_MATERIALS" <?= ($_POST['contract_type'] ?? '') === 'TIME_MATERIALS' ? 'selected' : '' ?>>Time & Materials</option>
                                <option value="RETAINER" <?= ($_POST['contract_type'] ?? '') === 'RETAINER' ? 'selected' : '' ?>>Retainer</option>
                                <option value="UNIT_RATE" <?= ($_POST['contract_type'] ?? '') === 'UNIT_RATE' ? 'selected' : '' ?>>Unit Rate</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Currency</label>
                            <select name="currency" class="form-select">
                                <option value="JMD" <?= ($_POST['currency'] ?? 'JMD') === 'JMD' ? 'selected' : '' ?>>JMD</option>
                                <option value="USD" <?= ($_POST['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Total Contract Value *</label>
                            <input type="number" step="0.01" min="0.01" name="total_value"
                                   value="<?= htmlspecialchars($_POST['total_value'] ?? '') ?>"
                                   class="form-control" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Date *</label>
                            <input type="date" name="start_date"
                                   value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">End Date *</label>
                            <input type="date" name="end_date"
                                   value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Terms (days)</label>
                            <input type="number" min="1" name="payment_terms"
                                   value="<?= htmlspecialchars($_POST['payment_terms'] ?? '30') ?>"
                                   class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Billing Frequency</label>
                            <select name="billing_frequency" class="form-select">
                                <option value="MONTHLY" <?= ($_POST['billing_frequency'] ?? 'MONTHLY') === 'MONTHLY' ? 'selected' : '' ?>>Monthly</option>
                                <option value="QUARTERLY" <?= ($_POST['billing_frequency'] ?? '') === 'QUARTERLY' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="MILESTONE" <?= ($_POST['billing_frequency'] ?? '') === 'MILESTONE' ? 'selected' : '' ?>>Milestone</option>
                                <option value="ON_DELIVERY" <?= ($_POST['billing_frequency'] ?? '') === 'ON_DELIVERY' ? 'selected' : '' ?>>On Delivery</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contract Document</label>
                            <input type="file" name="contract_document" class="form-control"
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small class="text-muted">PDF, Word, or Image (max 25MB)</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" rows="3" class="form-control"
                                  placeholder="Scope of services, deliverables..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Notes</label>
                        <textarea name="notes" rows="2" class="form-control"
                                  placeholder="Internal notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            ✨ Create Contract
                        </button>
                        <a href="/contracts/list.php" class="btn btn-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-light mb-3">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">📌 Required Fields</h5>
            </div>
            <div class="card-body small">
                <ul class="mb-0 ps-3">
                    <li><strong>Contract Title</strong> — Descriptive name</li>
                    <li><strong>Vendor</strong> — Must exist in vendor master</li>
                    <li><strong>Department</strong> — Responsible branch</li>
                    <li><strong>Total Value</strong> — Contract ceiling amount</li>
                    <li><strong>Start & End Date</strong> — Contract period</li>
                </ul>
            </div>
        </div>

        <div class="card border-0 shadow-sm bg-light mb-3">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">💡 Workflow</h5>
            </div>
            <div class="card-body small">
                <ol class="mb-0 ps-3">
                    <li>Create contract (DRAFT status)</li>
                    <li>Activate when signed</li>
                    <li>Create payment requests against contract</li>
                    <li>Branch Head approves request</li>
                    <li>Finance verifies funds & creates commitment</li>
                    <li>Submit invoice → record payment</li>
                </ol>
            </div>
        </div>

        <div class="alert alert-info small">
            <strong>✨ Note:</strong> Contracts are created in DRAFT status. Activate them via the contract view once all parties have signed.
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
