<?php
$REQUIRE_PERMISSION = 'view_request';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$documentId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($documentId <= 0) {
    pop('Invalid document reference.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("\n    SELECT rd.document_id, rd.document_type, rd.document_name, rd.document_path,
           pr.request_id, pr.request_number, pr.status, pr.created_by
    FROM request_documents rd
    INNER JOIN procurement_requests pr ON pr.request_id = rd.request_id
    WHERE rd.document_id = ?
    LIMIT 1
");
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    pop('Document not found.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($document, '/procurement/list.php');

logAudit(
    $pdo,
    'request_documents',
    $documentId,
    strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD',
    'Request document accessed for request ' . ($document['request_number'] ?? ('#' . $document['request_id']))
);

try {
    SecureFileStorage::streamStoredFile(
        (string)$document['document_path'],
        'application/octet-stream',
        (string)($document['document_name'] ?: 'document'),
        $action,
        'request_documents'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/procurement/view.php?id=' . (int)$document['request_id'], POP_DEFAULT_DELAY_MS, 'error');
}
