<?php
$REQUIRE_PERMISSION = 'view_commitments';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$commitmentId = isset($_GET['commitment_id']) ? (int)$_GET['commitment_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if ($commitmentId <= 0) {
    pop('Invalid Commitment ID.', '/commitments/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        c.commitment_id,
        c.commitment_number,
        c.document_path,
        pr.request_id,
        pr.created_by,
        pr.status
    FROM commitments c
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE c.commitment_id = ?
    LIMIT 1
");
$stmt->execute([$commitmentId]);
$commitment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$commitment || empty($commitment['document_path'])) {
    pop('Commitment document not found.', '/commitments/view.php?commitment_id=' . $commitmentId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($commitment, '/commitments/list.php');

$storedPath = (string)$commitment['document_path'];
$mimeType = SecureFileStorage::detectStoredMimeType($storedPath, 'commitments');
$extension = pathinfo(parse_url($storedPath, PHP_URL_PATH) ?: $storedPath, PATHINFO_EXTENSION);
$downloadName = trim((string)($commitment['commitment_number'] ?? 'commitment'));
if ($extension !== '') {
    $downloadName .= '.' . strtolower($extension);
}

try {
    SecureFileStorage::streamStoredFile($storedPath, $mimeType, $downloadName, 'view', 'commitments');
} catch (Throwable $e) {
    error_log('Commitment document download failed for commitment_id=' . $commitmentId . ': ' . $e->getMessage());
    pop('Unable to download commitment document.', '/commitments/view.php?commitment_id=' . $commitmentId, POP_DEFAULT_DELAY_MS, 'error');
}
