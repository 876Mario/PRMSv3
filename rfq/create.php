<?php
$REQUIRE_PERMISSION = 'create_rfq';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$request_id = (int)($_GET['request_id'] ?? 0);

if ($request_id <= 0) {
    pop('Invalid request', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Confirm request exists */
$stmt = $pdo->prepare("
    SELECT request_id, status, request_number
    FROM procurement_requests
    WHERE request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* SOP Step 6: RFQ is created after submission or approval stages */
$allowedForRFQ = ['SUBMITTED', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'FUNDS_VERIFIED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'EVALUATION_STAGE'];
if (!in_array(strtoupper($request['status']), $allowedForRFQ, true)) {
    pop('RFQ can only be created for submitted requests.', '/procurement/view.php?id='.$request_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Prevent duplicate RFQ */
$stmt = $pdo->prepare("
    SELECT rfq_id FROM rfqs
    WHERE request_id = ?
");
$stmt->execute([$request_id]);
if ($stmt->fetch()) {
    pop('RFQ already exists for this request', '/procurement/view.php?id='.$request_id, POP_DEFAULT_DELAY_MS, 'warning');
    exit;
}

/* Handle POST (create RFQ with date and deadline) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrfToken('/rfq/create.php?request_id=' . $request_id);
        $rfqDate = trim($_POST['rfq_date'] ?? '');
        $submissionDeadline = trim($_POST['submission_deadline'] ?? '');
        
        if (empty($rfqDate)) {
            throw new Exception("RFQ date is required.");
        }
        if (empty($submissionDeadline)) {
            throw new Exception("Submission deadline is required.");
        }
        
        // Validate dates
        $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $rfqDate);
        if (!$dateObj) {
            throw new Exception("Invalid RFQ date format.");
        }
        
        $deadlineObj = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $submissionDeadline);
        if (!$deadlineObj) {
            $deadlineObj = DateTimeImmutable::createFromFormat('Y-m-d', $submissionDeadline);
            if (!$deadlineObj) {
                throw new Exception("Invalid deadline format.");
            }
        }
        
        if ($deadlineObj <= $dateObj) {
            throw new Exception("Submission deadline must be after the RFQ date.");
        }

        // Handle RFQ letter upload (optional)
        $rfqLetterPath = null;
        if (isset($_FILES['rfq_letter']) && $_FILES['rfq_letter']['error'] === UPLOAD_ERR_OK) {
            $storedLetter = SecureFileStorage::storeUploadedFile(
                $_FILES['rfq_letter'],
                'rfq_letters',
                'RFQ_LETTER_' . $request_id,
                [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ],
                50 * 1024 * 1024
            );
            $rfqLetterPath = $storedLetter['storage_path'];
        }

        /* Generate RFQ Number */
        $rfqNumber = 'RFQ-' . date('Ymd') . '-' . $request_id;

        /* Insert RFQ */
        $stmt = $pdo->prepare("
            INSERT INTO rfqs
            (request_id, rfq_number, rfq_date, submission_deadline, created_by, created_at, status, rfq_letter_file)
            VALUES (?, ?, ?, ?, ?, NOW(), 'OPEN', ?)
        ");
        $stmt->execute([
            $request_id,
            $rfqNumber,
            $rfqDate,
            $submissionDeadline,
            $_SESSION['user_id'],
            $rfqLetterPath
        ]);

        $rfq_id = $pdo->lastInsertId();

        /* Audit */
        logAudit($pdo, 'rfqs', $rfq_id, 'CREATE', 
            "RFQ created for request ID $request_id. Date: $rfqDate, Deadline: $submissionDeadline");

        /* Redirect to view */
        header("Location: view.php?id=" . $rfq_id);
        exit;
        
    } catch (Throwable $e) {
        if (isset($storedLetter)) {
            SecureFileStorage::deleteStoredFile($storedLetter['storage_path'], 'rfq_letters');
        }
        $error = extractDbMessage($e);
    }
}

/* Render form */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Create RFQ for <?= htmlspecialchars($request['request_number']) ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">
                        <div class="mb-4">
                            <label for="rfq_date" class="form-label fw-bold">
                                <i class="bi bi-calendar-event"></i>
                                <span class="text-danger">*</span> RFQ Date
                            </label>
                            <input type="date" id="rfq_date" name="rfq_date" 
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($_POST['rfq_date'] ?? date('Y-m-d')) ?>"
                                   required>
                            <small class="text-muted">The date of the RFQ issuance</small>
                        </div>

                        <div class="mb-4">
                            <label for="submission_deadline" class="form-label fw-bold">
                                <i class="bi bi-clock"></i>
                                <span class="text-danger">*</span> Submission Deadline
                            </label>
                            <input type="datetime-local" id="submission_deadline" name="submission_deadline" 
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($_POST['submission_deadline'] ?? '') ?>"
                                   required>
                            <small class="text-muted">Deadline for vendors to submit their quotes</small>
                        </div>

                        <div class="mb-4">
                            <label for="rfq_letter" class="form-label fw-bold">
                                <i class="bi bi-file-pdf text-danger"></i>
                                Upload RFQ Letter (Optional)
                            </label>
                            <input type="file" id="rfq_letter" name="rfq_letter" 
                                   class="form-control form-control-lg"
                                   accept=".pdf,.doc,.docx">
                            <small class="text-muted">Upload the formal RFQ letter document (PDF or Word). Max 50 MB.</small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle me-1"></i> Create RFQ
                            </button>
                            <a href="/procurement/view.php?id=<?= $request_id ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
