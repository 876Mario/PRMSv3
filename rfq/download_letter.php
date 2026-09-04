<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

$rfqId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($rfqId <= 0) {
    pop('Invalid RFQ reference.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.rfq_id, r.rfq_number, r.rfq_letter_file,
           pr.request_id, pr.request_number, pr.status, pr.created_by
    FROM rfqs r
    INNER JOIN procurement_requests pr ON pr.request_id = r.request_id
    WHERE r.rfq_id = ?
    LIMIT 1
");
$stmt->execute([$rfqId]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq || empty($rfq['rfq_letter_file'])) {
    pop('RFQ letter not found.', '/rfq/view.php?id=' . $rfqId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($rfq, '/rfq/list.php');

$mimeType = SecureFileStorage::detectStoredMimeType((string)$rfq['rfq_letter_file'], 'rfq_letters');
$extensionMap = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];
$downloadName = (string)($rfq['rfq_number'] ?? 'rfq-letter');
if (isset($extensionMap[$mimeType])) {
    $downloadName .= '_letter.' . $extensionMap[$mimeType];
}

logAudit($pdo, 'rfqs', $rfqId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'RFQ letter accessed for ' . ($rfq['request_number'] ?? ('RFQ #' . $rfqId)));

try {
    SecureFileStorage::streamStoredFile(
        (string)$rfq['rfq_letter_file'],
        $mimeType,
        $downloadName,
        $action,
        'rfq_letters'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/rfq/view.php?id=' . $rfqId, POP_DEFAULT_DELAY_MS, 'error');
}
