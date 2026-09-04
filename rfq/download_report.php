<?php
$REQUIRE_PERMISSION = 'view_rfq_evaluations';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$reportId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($reportId <= 0) {
    pop('Invalid report reference.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("\n    SELECT r.report_id, r.rfq_id, r.report_file, rfq.rfq_number,
           pr.request_id, pr.request_number, pr.status, pr.created_by
    FROM rfq_evaluation_reports r
    INNER JOIN rfqs rfq ON rfq.rfq_id = r.rfq_id
    INNER JOIN procurement_requests pr ON pr.request_id = rfq.request_id
    WHERE r.report_id = ?
    LIMIT 1
");
$stmt->execute([$reportId]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report || empty($report['report_file'])) {
    pop('Report not found.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($report, '/rfq/list.php');

$fileName = basename(str_replace('private://rfq_evaluation_reports/', '', (string)$report['report_file']));
logAudit($pdo, 'rfq_evaluation_reports', $reportId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'RFQ evaluation report accessed for ' . ($report['rfq_number'] ?? ('#' . $report['rfq_id'])));

try {
    SecureFileStorage::streamStoredFile(
        (string)$report['report_file'],
        'application/pdf',
        $fileName !== '' ? $fileName : 'evaluation-report.pdf',
        $action,
        'uploads/evaluation_reports'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/rfq/view_report.php?id=' . $reportId, POP_DEFAULT_DELAY_MS, 'error');
}
