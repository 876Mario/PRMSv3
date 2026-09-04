<?php
$REQUIRE_PERMISSION = 'view_purchase_orders';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$poId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if ($poId <= 0) {
    pop('Invalid Purchase Order ID.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        po.po_id,
        po.po_number,
        po.document_path,
        po.po_file,
        pr.request_id,
        pr.created_by,
        pr.status
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
    LIMIT 1
");
$stmt->execute([$poId]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$po) {
    pop('Purchase Order not found.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($po, '/po/list.php');

$storedPath = trim((string)($po['document_path'] ?? ''));
$legacyDirectory = null;
if ($storedPath === '' && !empty($po['po_file'])) {
    $storedPath = (string)$po['po_file'];
    $legacyDirectory = 'po';
}

if ($storedPath === '') {
    pop('Purchase Order document not found.', '/po/view.php?po_id=' . $poId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$mimeType = SecureFileStorage::detectStoredMimeType($storedPath, $legacyDirectory);
$extension = pathinfo(parse_url($storedPath, PHP_URL_PATH) ?: $storedPath, PATHINFO_EXTENSION);
$downloadName = trim((string)($po['po_number'] ?? 'purchase_order'));
if ($extension !== '') {
    $downloadName .= '.' . strtolower($extension);
}

try {
    SecureFileStorage::streamStoredFile($storedPath, $mimeType, $downloadName, 'view', $legacyDirectory);
} catch (Throwable $e) {
    error_log('PO document download failed for po_id=' . $poId . ': ' . $e->getMessage());
    pop('Unable to download Purchase Order document.', '/po/view.php?po_id=' . $poId, POP_DEFAULT_DELAY_MS, 'error');
}
