<?php
$REQUIRE_PERMISSION = 'view_request';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$requestId = (int)($_GET['request_id'] ?? 0);
$version = isset($_GET['version']) ? (int)$_GET['version'] : 0;
$action = $_GET['action'] ?? 'download';

if ($requestId <= 0) {
    pop('Invalid request reference.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$params = [];
if ($version > 0) {
    $sql = "
        SELECT pr.request_id, pr.request_number, pr.status, pr.created_by,
               srd.document_path, srd.file_type, srd.original_file_name, srd.version_number
        FROM signed_request_documents srd
        INNER JOIN procurement_requests pr ON pr.request_id = srd.request_id
        WHERE srd.request_id = ?
          AND srd.version_number = ?
          AND srd.is_deleted = 0
        LIMIT 1
    ";
    $params = [$requestId, $version];
} else {
    $sql = "
        SELECT pr.request_id, pr.request_number, pr.status, pr.created_by,
               srd.document_path, srd.file_type, srd.original_file_name, srd.version_number
        FROM signed_request_documents srd
        INNER JOIN procurement_requests pr ON pr.request_id = srd.request_id
        WHERE srd.request_id = ?
          AND srd.is_active = 1
          AND srd.is_deleted = 0
        LIMIT 1
    ";
    $params = [$requestId];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document || empty($document['document_path'])) {
    pop('Signed request document not found.', '/procurement/view.php?id=' . $requestId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($document, '/procurement/list.php');

logAudit(
    $pdo,
    'signed_request_documents',
    $requestId,
    strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD',
    'Signed request document accessed for request ' . ($document['request_number'] ?? ('#' . $requestId))
);

try {
    SecureFileStorage::streamStoredFile(
        (string)$document['document_path'],
        (string)($document['file_type'] ?? 'application/octet-stream'),
        (string)($document['original_file_name'] ?: 'signed-request'),
        $action,
        'signed_requests'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/procurement/view.php?id=' . $requestId, POP_DEFAULT_DELAY_MS, 'error');
}
