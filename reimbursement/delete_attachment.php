<?php
/**
 * Reimbursement Invoice Attachment Delete Handler
 */
$REQUIRE_PERMISSION = 'delete_reimbursement_invoice_attachment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reimbursement/list.php');
    exit;
}

$id = (int)($_POST['attachment_id'] ?? 0);
if ($id <= 0) {
    modalPop('Error', 'Invalid attachment reference.', '/reimbursement/list.php', 'error');
    exit;
}

// Fetch attachment
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
    modalPop('Error', 'Attachment not found.', '/reimbursement/list.php', 'error');
    exit;
}

// Verify user is the requestor or admin
if ($att['created_by'] != $_SESSION['user_id'] && !has_permission('manage_users')) {
    modalPop('Error', 'You are not authorized to delete this attachment.', '/reimbursement/list.php', 'error');
    exit;
}

try {
    $upd = $pdo->prepare("UPDATE reimbursement_invoice_attachments SET is_deleted = 1 WHERE id = ?");
    $upd->execute([$id]);

    logAudit($pdo, 'reimbursement_invoice_attachments', $id, 'DELETE',
        "Reimbursement invoice attachment deleted: {$att['original_file_name']} (Request #{$att['request_number']})");

    pop('Attachment deleted successfully.', "/reimbursement/view.php?request_id={$att['request_id']}", POP_DEFAULT_DELAY_MS, 'success');

} catch (Exception $e) {
    modalPop('Error', 'Failed to delete attachment: ' . $e->getMessage(), "/reimbursement/view.php?request_id={$att['request_id']}", 'error');
}
