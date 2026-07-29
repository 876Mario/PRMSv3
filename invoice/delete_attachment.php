<?php
/**
 * Invoice Attachment Delete Handler
 */
$REQUIRE_PERMISSION = 'delete_invoice_attachment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /invoice/list.php');
    exit;
}

$id = (int)($_POST['attachment_id'] ?? 0);
if ($id <= 0) {
    modalPop('Error', 'Invalid attachment reference.', '/invoice/list.php', 'error');
    exit;
}

// Fetch attachment
$stmt = $pdo->prepare("
    SELECT a.*, i.invoice_number
    FROM invoice_attachments a
    JOIN invoices i ON a.invoice_id = i.invoice_id
    WHERE a.id = ? AND a.is_deleted = 0
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    modalPop('Error', 'Attachment not found.', '/invoice/list.php', 'error');
    exit;
}

// Soft-delete
$upd = $pdo->prepare("UPDATE invoice_attachments SET is_deleted = 1 WHERE id = ?");
$upd->execute([$id]);

logAudit($pdo, 'invoice_attachments', $id, 'DELETE',
    "Invoice attachment deleted: {$att['original_file_name']} (Invoice #{$att['invoice_number']})");

pop('Attachment deleted.', "/invoice/view.php?id={$att['invoice_id']}", POP_DEFAULT_DELAY_MS, 'success');
