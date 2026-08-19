<?php
$REQUIRE_PERMISSION = 'view_own_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($request_id <= 0) {
    pop('Invalid reimbursement request.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Fetch request details */
$stmt = $pdo->prepare("
    SELECT
        pr.*,
        b.branch_name,
        u.full_name,
        pa.authorization_amount,
        pa.authorization_date
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    LEFT JOIN pre_authorizations pa ON pr.request_id = pa.request_id
    WHERE pr.request_id = ?
      AND pr.request_type = 'REIMBURSEMENT'
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Reimbursement request not found.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Only the requestor can submit invoices for their own requests */
if ($request['created_by'] != $_SESSION['user_id'] && !has_permission('manage_users')) {
    pop('You are not authorized to submit invoices for this request.', '/reimbursement/list.php', 3000, 'error');
    exit;
}

/* Must be in FUNDS_VERIFIED status (or can resubmit if in INVOICE_SUBMITTED) */
if (!in_array($request['status'], ['FUNDS_VERIFIED', 'INVOICE_SUBMITTED'])) {
    pop('This request is not in a state that allows invoice submission. Status: ' . $request['status'], '/reimbursement/view.php?request_id=' . $request_id, 3000, 'warning');
    exit;
}

/* Fetch existing invoices for this request */
$invStmt = $pdo->prepare("
    SELECT ri.*, u.full_name AS submitted_by_name
    FROM reimbursement_invoices ri
    LEFT JOIN users u ON ri.submitted_by = u.user_id
    WHERE ri.request_id = ?
    ORDER BY ri.submitted_date DESC
");
$invStmt->execute([$request_id]);
$existingInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $invoice_stage   = trim($_POST['invoice_stage'] ?? '');
        $invoice_amount  = (float)($_POST['invoice_amount'] ?? 0);

        if (empty($invoice_stage)) {
            throw new Exception('Please select an invoice stage.');
        }

        if ($invoice_amount <= 0) {
            throw new Exception('Invoice amount must be greater than zero.');
        }

        if ($invoice_amount > (float)($request['authorization_amount'] ?? 0)) {
            throw new Exception(sprintf(
                'Invoice amount (%s) exceeds the authorized amount (%s).',
                number_format($invoice_amount, 2),
                number_format((float)$request['authorization_amount'], 2)
            ));
        }

        /* Check if this stage has already been submitted */
        $dupStmt = $pdo->prepare("
            SELECT COUNT(*) FROM reimbursement_invoices
            WHERE request_id = ? AND invoice_stage = ?
        ");
        $dupStmt->execute([$request_id, $invoice_stage]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            throw new Exception('An invoice for the "' . htmlspecialchars($invoice_stage) . '" stage has already been submitted.');
        }

        /* Handle optional file attachment before starting transaction */
        $attachmentId = null;
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['attachment_file'];
            // This function will handle file validation and upload
            // If it fails, an exception is thrown before we start the DB transaction
            // The file cleanup happens inside the function on error
        }

        $pdo->beginTransaction();

        $insStmt = $pdo->prepare("
            INSERT INTO reimbursement_invoices
            (request_id, invoice_stage, invoice_amount, submitted_by, submitted_date, goods_service_verified)
            VALUES (?, ?, ?, ?, NOW(), 0)
        ");
        $insStmt->execute([
            $request_id,
            $invoice_stage,
            $invoice_amount,
            $_SESSION['user_id'],
        ]);

        $reimb_invoice_id = (int)$pdo->lastInsertId();

        logAudit($pdo, 'reimbursement_invoices', $reimb_invoice_id, 'CREATE', 'Invoice submitted for reimbursement request #' . $request_id);

        /* Now process the file attachment that was already validated */
        if (isset($file) && $_FILES['attachment_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Use shared helper function for file upload (already validated)
            $attachmentId = saveReimbursementAttachment($pdo, $file, $reimb_invoice_id, $_SESSION['user_id']);

            logAudit($pdo, 'reimbursement_invoice_attachments', $attachmentId, 'CREATE',
                "Reimbursement invoice attachment uploaded: " . preg_replace('/[^\w.\-]/', '_', basename($file['name'])) . " for Request #{$request['request_number']}");
        }

        $pdo->commit();

        /* Send notifications to Procurement Officer (GC2) or Finance Officer (GC10A) */
        notifyReimbursementInvoiceSubmitted($request_id, $invoice_stage, $invoice_amount, $request['full_name'] ?? '');

        modalPop(
            'Invoice Submitted',
            'Your invoice copy has been submitted successfully for Request ' . $request['request_number'] . '.',
            '/reimbursement/view.php?request_id=' . $request_id,
            'success'
        );
        header('Location: /reimbursement/view.php?request_id=' . $request_id);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = extractDbMessage($e);
    }
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="container-fluid">

    <!-- Breadcrumb & Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item">
                        <a href="/reimbursement/list.php" class="text-decoration-none">Reimbursements</a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="/reimbursement/view.php?request_id=<?= $request_id ?>" class="text-decoration-none">
                            <?= htmlspecialchars($request['request_number']) ?>
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Submit Invoice</li>
                </ol>
            </nav>
            <h2 class="mb-0 fw-bold" style="color: #1a1a1a;">
                <i class="bi bi-upload me-2" style="color: #667eea;"></i>Submit Invoice Copy
            </h2>
        </div>
        <a href="/reimbursement/view.php?request_id=<?= $request_id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Request
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

    <div class="row g-4">

        <!-- Form Column -->
        <div class="col-lg-8">

            <!-- Request Summary -->
            <div class="card border-0 shadow-sm mb-4"
                 style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="card-body py-3">
                    <div class="row g-3 align-items-center">
                        <div class="col-auto">
                            <i class="bi bi-cash-coin" style="font-size: 2rem; opacity: 0.6;"></i>
                        </div>
                        <div class="col">
                            <div class="fw-bold fs-5"><?= htmlspecialchars($request['request_number']) ?></div>
                            <small style="opacity: 0.85;"><?= htmlspecialchars($request['description']) ?></small>
                        </div>
                        <div class="col-auto text-end">
                            <div style="opacity: 0.75; font-size: 0.8rem;">Authorized Amount</div>
                            <div class="fw-bold fs-5">
                                JMD <?= number_format((float)($request['authorization_amount'] ?? 0), 2) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submission Form -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Invoice Submission Form
                    </h5>
                </div>
                <div class="card-body">

                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Reimbursement Process:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Step 1 – Copy to Procurement (GC2):</strong> Submit a copy of your invoice to the Procurement Office.</li>
                            <li><strong>Step 2 – Original to Finance (GC10A):</strong> Submit the original invoice to Finance for payment processing.</li>
                        </ul>
                    </div>

                    <form method="POST" class="needs-validation" novalidate enctype="multipart/form-data">

                        <!-- Invoice Stage -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Invoice Stage <span class="text-danger">*</span>
                            </label>
                            <select name="invoice_stage" class="form-select" required>
                                <option value="">-- Select Stage --</option>
                                <?php
                                $stagesUsed = array_column($existingInvoices, 'invoice_stage');
                                $allStages = [
                                    'COPY_TO_PROCUREMENT' => 'Copy to Procurement (GC2)',
                                    'ORIGINAL_TO_FINANCE' => 'Original to Finance (GC10A)',
                                ];
                                foreach ($allStages as $val => $label):
                                    $disabled = in_array($val, $stagesUsed) ? 'disabled' : '';
                                    $hint = in_array($val, $stagesUsed) ? ' (already submitted)' : '';
                                ?>
                                    <option value="<?= $val ?>" <?= $disabled ?>>
                                        <?= $label . $hint ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select an invoice stage.</div>
                        </div>

                        <!-- Invoice Amount -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Invoice Amount (JMD) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number"
                                       name="invoice_amount"
                                       class="form-control"
                                       step="0.01"
                                       min="0.01"
                                       max="<?= (float)($request['authorization_amount'] ?? 0) ?>"
                                       required
                                       placeholder="0.00"
                                       value="<?= htmlspecialchars($_POST['invoice_amount'] ?? '') ?>">
                            </div>
                            <div class="form-text text-muted">
                                Maximum: JMD <?= number_format((float)($request['authorization_amount'] ?? 0), 2) ?>
                            </div>
                            <div class="invalid-feedback">Please enter a valid invoice amount.</div>
                        </div>

                        <!-- Optional File Attachment -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Attach Document <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="file"
                                   name="attachment_file"
                                   class="form-control"
                                   id="attachment_file"
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                                   aria-label="Upload invoice document">
                            <div class="form-text text-muted mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Allowed formats: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX
                                <br>
                                Maximum file size: 10 MB
                            </div>
                            <div id="file-error" class="invalid-feedback" style="display: none;"></div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn"
                                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.65rem 1.5rem; font-weight: 600;">
                                <i class="bi bi-upload me-2"></i>Submit Invoice
                            </button>
                            <a href="/reimbursement/view.php?request_id=<?= $request_id ?>"
                               class="btn btn-outline-secondary" style="padding: 0.65rem 1.5rem;">
                                <i class="bi bi-x me-1"></i>Cancel
                            </a>
                        </div>

                    </form>
                </div>
            </div>

        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

            <!-- Previously Submitted -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-clock-history me-2 text-primary"></i>Previously Submitted
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($existingInvoices)): ?>
                        <p class="text-muted text-center mb-0 small py-2">No invoices submitted yet.</p>
                    <?php else: ?>
                        <?php foreach ($existingInvoices as $inv): ?>
                        <div class="border rounded p-3 mb-2" style="background: #f8f9fa;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold small">
                                        <?= $inv['invoice_stage'] === 'COPY_TO_PROCUREMENT'
                                            ? '<i class="bi bi-files me-1"></i>Copy to Procurement'
                                            : '<i class="bi bi-file-earmark me-1"></i>Original to Finance' ?>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        JMD <?= number_format((float)$inv['invoice_amount'], 2) ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        <?= date('d M Y', strtotime($inv['submitted_date'])) ?>
                                        &bull; <?= htmlspecialchars($inv['submitted_by_name'] ?? '—') ?>
                                    </div>
                                </div>
                                <span class="badge <?= $inv['goods_service_verified'] ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size: 0.7rem;">
                                    <?= $inv['goods_service_verified'] ? '✓ Verified' : 'Pending' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Authorization Details -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-shield-check me-2 text-success"></i>Authorization Details
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted fw-bold d-block">Authorization Date</small>
                        <strong>
                            <?= $request['authorization_date']
                                ? date('d M Y', strtotime($request['authorization_date']))
                                : '—' ?>
                        </strong>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted fw-bold d-block">Authorized Amount</small>
                        <strong class="text-success">
                            JMD <?= number_format((float)($request['authorization_amount'] ?? 0), 2) ?>
                        </strong>
                    </div>
                    <div>
                        <small class="text-muted fw-bold d-block">Branch</small>
                        <strong><?= htmlspecialchars($request['branch_name'] ?? '—') ?></strong>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.needs-validation');
    if (!form) return;

    const fileInput = document.getElementById('attachment_file');
    const fileError = document.getElementById('file-error');
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

    // Validate file on change
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            fileError.style.display = 'none';
            fileError.textContent = '';

            if (!this.files || this.files.length === 0) {
                return; // Optional field
            }

            const file = this.files[0];

            // Check file size
            if (file.size > MAX_FILE_SIZE) {
                fileError.textContent = 'File size exceeds 10 MB limit.';
                fileError.style.display = 'block';
                this.value = '';
                return;
            }

            // Check file extension
            const ext = file.name.split('.').pop().toLowerCase();
            if (!ALLOWED_EXTS.includes(ext)) {
                fileError.textContent = 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX.';
                fileError.style.display = 'block';
                this.value = '';
                return;
            }
        });
    }

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
