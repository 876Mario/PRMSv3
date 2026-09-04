<?php
$REQUIRE_PERMISSION = 'view_rfq_evaluations';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

$report_id = (int)($_GET['id'] ?? 0);

if (!$report_id) {
    pop('Invalid report', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch Report */
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name
    FROM rfq_evaluation_reports r
    LEFT JOIN users u ON r.created_by = u.user_id
    WHERE r.report_id = ?
");
$stmt->execute([$report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    pop('Report not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch RFQ context */
$stmtRfq = $pdo->prepare("SELECT r.rfq_number, pr.request_number FROM rfqs r JOIN procurement_requests pr ON r.request_id = pr.request_id WHERE r.rfq_id = ?");
$stmtRfq->execute([$report['rfq_id']]);
$rfqInfo = $stmtRfq->fetch(PDO::FETCH_ASSOC);
$rfqNum = htmlspecialchars($rfqInfo['rfq_number'] ?? 'N/A');
$reqNum = htmlspecialchars($rfqInfo['request_number'] ?? 'N/A');

$uploaderName = htmlspecialchars($report['full_name'] ?? 'Unknown');
$uploadDate   = date('d M Y', strtotime($report['created_at']));
$uploadTime   = date('H:i', strtotime($report['created_at']));
$fileName     = htmlspecialchars(basename(str_replace('private://rfq_evaluation_reports/', '', (string)($report['report_file'] ?? ''))));

require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/header.php";
?>

<!-- Page Header -->
<div class="mb-4">
    <a href="view.php?id=<?= $report['rfq_id'] ?>" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i>Back to RFQ
    </a>
    <h4 class="fw-bold mt-2 mb-1" style="color:#1a1a2e;">
        <i class="bi bi-file-earmark-ruled"></i> Evaluation Report
    </h4>
    <p class="text-muted mb-0 small">Uploaded evaluation report document for review</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">

        <!-- RFQ Context Card -->
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
            </div>
        </div>

        <!-- Report Details Card -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 rounded-top-4 pt-4 pb-2 px-4">
                <h6 class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i> Report Details</h6>
            </div>
            <div class="card-body px-4 pb-4">

                <div class="row g-3 mb-4">
                    <!-- Uploaded By -->
                    <div class="col-sm-6">
                        <div class="bg-light rounded-3 p-3 h-100">
                            <div class="text-muted small mb-1">
                                <i class="bi bi-person me-1"></i>Uploaded By
                            </div>
                            <div class="fw-semibold"><?= $uploaderName ?></div>
                        </div>
                    </div>
                    <!-- Upload Date -->
                    <div class="col-sm-6">
                        <div class="bg-light rounded-3 p-3 h-100">
                            <div class="text-muted small mb-1">
                                <i class="bi bi-calendar-event me-1"></i>Upload Date
                            </div>
                            <div class="fw-semibold"><?= $uploadDate ?> <span class="text-muted fw-normal small">at <?= $uploadTime ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- File Preview Area -->
                <div class="border rounded-3 p-4 text-center mb-4" style="background:#f8f9fa;">
                    <i class="bi bi-file-earmark-pdf fs-1 d-block mb-2 text-danger"></i>
                    <p class="fw-semibold mb-1"><?= $fileName ?></p>
                    <p class="text-muted small mb-0">PDF Document</p>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2">
                    <a href="/rfq/download_report.php?id=<?= (int)$report['report_id'] ?>&action=view"
                       target="_blank"
                       class="btn text-white px-4 rounded-pill"
                       style="background:#0b5e2b;">
                        <i class="bi bi-eye me-1"></i> View PDF
                    </a>
                    <a href="/rfq/download_report.php?id=<?= (int)$report['report_id'] ?>&action=download"
                       class="btn btn-outline-success rounded-pill px-4">
                        <i class="bi bi-download me-1"></i> Download
                    </a>
                    <a href="view.php?id=<?= $report['rfq_id'] ?>"
                       class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="bi bi-arrow-left me-1"></i> Back to RFQ
                    </a>
                </div>

            </div>
        </div>

    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . "/includes/footer.php"; ?>
