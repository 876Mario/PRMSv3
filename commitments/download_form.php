<?php
$REQUIRE_PERMISSION = 'create_commitment';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
if ($requestId <= 0) {
    pop('Invalid request ID.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT request_id, created_by, status, commitment_form_path
    FROM procurement_requests
    WHERE request_id = ?
    LIMIT 1
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request || empty($request['commitment_form_path'])) {
    pop('Commitment form not found.', '/commitments/add.php?request_id=' . $requestId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($request, '/procurement/list.php');

$storedPath = (string)$request['commitment_form_path'];
$mimeType = SecureFileStorage::detectStoredMimeType($storedPath, 'commitments');
$extension = pathinfo(parse_url($storedPath, PHP_URL_PATH) ?: $storedPath, PATHINFO_EXTENSION);
$downloadName = 'commitment_form_request_' . $requestId;
if ($extension !== '') {
    $downloadName .= '.' . strtolower($extension);
}

try {
    SecureFileStorage::streamStoredFile($storedPath, $mimeType, $downloadName, 'view', 'commitments');
} catch (Throwable $e) {
    error_log('Commitment form download failed for request_id=' . $requestId . ': ' . $e->getMessage());
    pop('Unable to download commitment form.', '/commitments/add.php?request_id=' . $requestId, POP_DEFAULT_DELAY_MS, 'error');
}
