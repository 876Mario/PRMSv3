<?php
/**
 * Reimbursement Invoice Attachment Upload Handler
 */
$REQUIRE_PERMISSION = 'upload_reimbursement_invoice_attachment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reimbursement/list.php');
    exit;
}

$reimb_invoice_id = (int)($_POST['reimb_invoice_id'] ?? 0);
if ($reimb_invoice_id <= 0) {
    modalPop('Error', 'Invalid reimbursement invoice reference.', '/reimbursement/list.php', 'error');
    exit;
}

// Verify reimbursement invoice exists and user has access
$stmt = $pdo->prepare("
    SELECT ri.reimb_invoice_id, ri.request_id, pr.request_number
    FROM reimbursement_invoices ri
    LEFT JOIN procurement_requests pr ON ri.request_id = pr.request_id
    WHERE ri.reimb_invoice_id = ?
");
$stmt->execute([$reimb_invoice_id]);
$reimb_invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reimb_invoice) {
    modalPop('Error', 'Reimbursement invoice not found.', '/reimbursement/list.php', 'error');
    exit;
}

// Verify the request exists and user has permission to view it
$reqStmt = $pdo->prepare("
    SELECT request_id, created_by FROM procurement_requests WHERE request_id = ?
");
$reqStmt->execute([$reimb_invoice['request_id']]);
$request = $reqStmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    modalPop('Error', 'Request not found.', '/reimbursement/list.php', 'error');
    exit;
}

// Only the requestor or admin can upload attachments
if ($request['created_by'] != $_SESSION['user_id'] && !has_permission('manage_users')) {
    modalPop('Error', 'You are not authorized to upload attachments for this request.', '/reimbursement/list.php', 'error');
    exit;
}

try {
    if (!isset($_FILES['attachment_file']) || $_FILES['attachment_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please select a file to upload.');
    }

    $file = $_FILES['attachment_file'];
    
    // Use shared helper function for file upload
    $attachmentId = saveReimbursementAttachment($pdo, $file, $reimb_invoice_id, $_SESSION['user_id']);

    logAudit($pdo, 'reimbursement_invoice_attachments', $attachmentId, 'CREATE',
        "Reimbursement invoice attachment uploaded: " . preg_replace('/[^\w.\-]/', '_', basename($file['name'])) . " for Request #{$reimb_invoice['request_number']}");

    pop('Attachment uploaded successfully.', "/reimbursement/view.php?request_id={$reimb_invoice['request_id']}", POP_DEFAULT_DELAY_MS, 'success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    modalPop('Upload Failed', $e->getMessage(), "/reimbursement/view.php?request_id={$reimb_invoice['request_id']}", 'error');
}
