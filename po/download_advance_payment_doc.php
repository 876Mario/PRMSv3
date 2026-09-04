<?php
/**
 * Download / View the supporting document for an Advance Payment.
 * Requires view_purchase_orders permission.
 */
$REQUIRE_PERMISSION = 'view_purchase_orders';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    pop('Invalid advance payment reference.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT ap.advance_payment_id,
           ap.supporting_document_path,
           ap.supporting_document_original_name,
           ap.supporting_document_file_type,
           ap.po_id,
           po.po_number
    FROM po_advance_payments ap
    JOIN purchase_orders po ON ap.po_id = po.po_id
    WHERE ap.advance_payment_id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$ap = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ap || !$ap['supporting_document_path']) {
    pop('Document not found.', '/po/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$action   = $_GET['action'] ?? 'download';
$mimeType = $ap['supporting_document_file_type'] ?: 'application/octet-stream';
$origName = $ap['supporting_document_original_name'] ?: 'document';

logAudit($pdo, 'po_advance_payments', $id, 'VIEW',
    "Supporting document accessed for advance payment #{$id} (PO #{$ap['po_number']})");

try {
    SecureFileStorage::streamStoredFile(
        (string)$ap['supporting_document_path'],
        (string)$mimeType,
        (string)$origName,
        $action,
        'advance_payments'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), "/po/view.php?po_id={$ap['po_id']}", POP_DEFAULT_DELAY_MS, 'error');
}
