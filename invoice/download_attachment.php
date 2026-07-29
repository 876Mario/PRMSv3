<?php
/**
 * Invoice Attachment Download/View Handler
 */
$REQUIRE_PERMISSION = 'view_invoices';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

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

$filePath = $_SERVER['DOCUMENT_ROOT'] . $att['file_path'];

if (!file_exists($filePath) || !is_file($filePath)) {
    pop('File not found on server.', "/invoice/view.php?id={$att['invoice_id']}", POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Determine action: inline view or force download
$action = $_GET['action'] ?? 'download';

$mimeType = $att['file_type'];

// Log access
logAudit($pdo, 'invoice_attachments', $id, 'VIEW',
    "Attachment accessed: {$att['original_file_name']} (Invoice #{$att['invoice_number']})");

// Serve file
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));

if ($action === 'view' && in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    header('Content-Disposition: inline; filename="' . $att['original_file_name'] . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $att['original_file_name'] . '"');
}

header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
