<?php
/**
 * Reimbursement Invoice Attachment Download Handler
 */
$REQUIRE_PERMISSION = 'view_own_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    pop('Invalid attachment reference.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch attachment (not deleted)
$stmt = $pdo->prepare("
    SELECT a.*, ri.request_id, ri.reimb_invoice_id, pr.request_number, pr.created_by
    FROM reimbursement_invoice_attachments a
    INNER JOIN reimbursement_invoices ri ON a.reimb_invoice_id = ri.reimb_invoice_id
    INNER JOIN procurement_requests pr ON ri.request_id = pr.request_id
    WHERE a.id = ? AND a.is_deleted = 0
");
$stmt->execute([$id]);
$att = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$att) {
    pop('Attachment not found.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Verify user has access to the request
if ($att['created_by'] != $_SESSION['user_id'] && !has_permission('manage_users')) {
    pop('You are not authorized to download this attachment.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$filePath = $_SERVER['DOCUMENT_ROOT'] . $att['file_path'];

if (!file_exists($filePath)) {
    pop('File not found on server.', '/reimbursement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

logAudit($pdo, 'reimbursement_invoice_attachments', $id, 'VIEW',
    "Reimbursement invoice attachment downloaded: {$att['original_file_name']} (Request #{$att['request_number']})");

// Sanitize filename for Content-Disposition header to prevent header injection
$safeFilename = preg_replace('/[^\w.\-]/', '', $att['original_file_name']);
if (empty($safeFilename)) {
    $safeFilename = 'attachment';
}

// Download the file
header('Content-Type: ' . $att['file_type']);
header('Content-Length: ' . $att['file_size']);
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
