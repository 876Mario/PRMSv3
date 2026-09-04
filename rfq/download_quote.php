<?php
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

$quoteId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($quoteId <= 0) {
    pop('Invalid quote reference.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT q.quote_id, q.quote_file, rv.rfq_id, v.vendor_name,
           r.rfq_number, pr.request_id, pr.request_number, pr.status, pr.created_by
    FROM rfq_quotes q
    INNER JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
    INNER JOIN rfqs r ON r.rfq_id = rv.rfq_id
    INNER JOIN procurement_requests pr ON pr.request_id = r.request_id
    INNER JOIN vendors v ON v.vendor_id = rv.vendor_id
    WHERE q.quote_id = ?
    LIMIT 1
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote || empty($quote['quote_file'])) {
    pop('Quote document not found.', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($quote, '/rfq/list.php');

$mimeType = SecureFileStorage::detectStoredMimeType((string)$quote['quote_file'], 'uploads/quotes');
$extensionMap = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-excel' => 'xls',
];
$baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($quote['vendor_name'] ?? 'quote')) ?: 'quote';
$downloadName = ($quote['rfq_number'] ?? 'rfq-quote') . '_' . $baseName;
if (isset($extensionMap[$mimeType])) {
    $downloadName .= '.' . $extensionMap[$mimeType];
}

logAudit($pdo, 'rfq_quotes', $quoteId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'RFQ quote accessed for ' . ($quote['request_number'] ?? ('RFQ #' . $quote['rfq_id'])));

try {
    SecureFileStorage::streamStoredFile(
        (string)$quote['quote_file'],
        $mimeType,
        $downloadName,
        $action,
        'uploads/quotes'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/rfq/view.php?id=' . (int)$quote['rfq_id'], POP_DEFAULT_DELAY_MS, 'error');
}
