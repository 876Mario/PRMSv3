<?php
$REQUIRE_PERMISSION = 'upload_rfq_report';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

$rfq_id = (int)($_GET['rfq_id'] ?? 0);

if (!$rfq_id) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('/rfq/view.php?id=' . $rfq_id);

    if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== 0) {
        pop('Upload failed', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($_FILES['report_file']['size'] > 50 * 1024 * 1024) { // 50 MB
        pop('File size exceeds 50 MB limit', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* Ensure minimum 3 committee members */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM rfq_evaluation_committee
    WHERE rfq_id = ?
");
$stmt->execute([$rfq_id]);

if ($stmt->fetchColumn() < 3) {
    pop('Minimum 3 committee members required before uploading report', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}


    try {
    $storedReport = SecureFileStorage::storeUploadedFile(
        $_FILES['report_file'],
        'rfq_evaluation_reports',
        'RFQ_REPORT_' . $rfq_id,
        ['application/pdf' => 'pdf'],
        50 * 1024 * 1024
    );
    $pdo->prepare("
        INSERT INTO rfq_evaluation_reports
        (rfq_id, report_file, created_at)
        VALUES (?, ?, NOW())
    ")->execute([$rfq_id, $storedReport['storage_path']]);
    } catch (Throwable $e) {
        if (isset($storedReport)) {
            SecureFileStorage::deleteStoredFile($storedReport['storage_path']);
        }
        pop(extractDbMessage($e), '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    header("Location: view.php?id=".$rfq_id);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

// Fetch RFQ info for context
$stmtRfq = $pdo->prepare("SELECT r.rfq_number, pr.request_number FROM rfqs r JOIN procurement_requests pr ON r.request_id = pr.request_id WHERE r.rfq_id = ?");
$stmtRfq->execute([$rfq_id]);
$rfqInfo = $stmtRfq->fetch(PDO::FETCH_ASSOC);
$rfqNum = htmlspecialchars($rfqInfo['rfq_number'] ?? 'N/A');
$reqNum = htmlspecialchars($rfqInfo['request_number'] ?? 'N/A');

// Committee count
$stmtCom = $pdo->prepare("SELECT COUNT(*) FROM rfq_evaluation_committee WHERE rfq_id = ?");
$stmtCom->execute([$rfq_id]);
$committeeCount = (int)$stmtCom->fetchColumn();
?>

<!-- Page Header -->
<div class="mb-4">
    <a href="view.php?id=<?= $rfq_id ?>" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i>Back to RFQ
    </a>
    <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
        <i class="bi bi-file-earmark-medical"></i> Upload Evaluation Report
    </h4>
    <p class="text-muted mb-0 small">Attach the signed evaluation report for this RFQ</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">

        <!-- RFQ Info Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-left:4px solid #0b5e2b !important;">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:48px;height:48px;background:#d1e7dd;color:#0b5e2b;font-size:1.3rem;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div>
                    <div class="fw-semibold"><?= $rfqNum ?></div>
                    <span class="text-muted small">Request: <?= $reqNum ?></span>
                </div>
                <div class="ms-auto">
                    <span class="badge <?= $committeeCount >= 3 ? 'bg-success' : 'bg-danger' ?> rounded-pill">
                        <i class="bi bi-people me-1"></i><?= $committeeCount ?> member<?= $committeeCount !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($committeeCount < 3): ?>
        <!-- Committee Warning -->
        <div class="alert alert-danger border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>Insufficient Committee Members</strong><br>
                <small>A minimum of 3 evaluation committee members must be assigned before uploading a report.
                Currently <strong><?= $committeeCount ?></strong> assigned.</small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Upload Form Card -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 pt-4 pb-2 px-4">
                <h6 class="fw-semibold mb-1"><i class="bi bi-upload me-1"></i> Report Document</h6>
                <p class="text-muted small mb-0">Upload the signed evaluation report as a PDF document.</p>
            </div>
            <div class="card-body px-4 pb-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken()) ?>">

                    <div class="mb-4">
                        <div class="border rounded-3 p-4 text-center bg-light" id="dropZone"
                             style="border-style:dashed !important; cursor:pointer; transition:all .2s;">
                            <i class="bi bi-cloud-arrow-up fs-1 d-block mb-2" style="color:#0b5e2b;"></i>
                            <p class="mb-1 fw-semibold small">Click to browse or drag & drop</p>
                            <p class="text-muted small mb-2">PDF files only (max 10 MB)</p>
                            <input type="file" name="report_file" accept=".pdf,application/pdf"
                                   class="form-control d-none" id="reportFile" required>
                            <span class="text-success small fw-semibold d-none" id="fileName"></span>
                        </div>
                    </div>

                    <!-- Info box -->
                    <div class="alert alert-light border rounded-3 py-2 px-3 mb-4">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        <small class="text-muted">The report should include all vendor scores, committee recommendations, and be signed by all committee members.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn text-white px-4 rounded-pill <?= $committeeCount < 3 ? 'disabled' : '' ?>"
                                style="background:#0b5e2b;" <?= $committeeCount < 3 ? 'disabled' : '' ?>>
                            <i class="bi bi-upload me-1"></i> Upload Report
                        </button>
                        <a href="view_report.php?id=<?= $rfq_id ?>"
                           class="btn btn-outline-secondary rounded-pill px-4">
                            Cancel
                        </a>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('reportFile');
const fileNameEl = document.getElementById('fileName');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('border-success');
    dropZone.style.background = '#d1e7dd';
});
dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-success');
    dropZone.style.background = '';
});
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('border-success');
    dropZone.style.background = '';
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showFile(e.dataTransfer.files[0]);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) showFile(fileInput.files[0]);
});

function showFile(file) {
    fileNameEl.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    fileNameEl.classList.remove('d-none');
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
