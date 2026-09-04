<?php
/**
 * Invoice Attachment Download/View Handler
 */
$REQUIRE_PERMISSION = 'view_invoices';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    pop('Invalid attachment reference.', '/invoice/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch attachment (not deleted)
$stmt = $pdo->prepare("
    SELECT a.*, i.invoice_number
    FROM invoice_attachments a
    JOIN invoices i ON a.invoice_id = i.invoice_id
    WHERE a.id = ? AND a.is_deleted = 0
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    pop('Attachment not found.', '/invoice/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$action = $_GET['action'] ?? 'download';

// Log access
logAudit($pdo, 'invoice_attachments', $id, 'VIEW',
    "Attachment accessed: {$att['original_file_name']} (Invoice #{$att['invoice_number']})");

try {
    SecureFileStorage::streamStoredFile(
        (string)$att['file_path'],
        (string)($att['file_type'] ?? 'application/octet-stream'),
        (string)($att['original_file_name'] ?? 'attachment'),
        $action,
        'invoice_attachments'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), "/invoice/view.php?id={$att['invoice_id']}", POP_DEFAULT_DELAY_MS, 'error');
}
