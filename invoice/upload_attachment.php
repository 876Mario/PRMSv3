<?php
/**
 * Invoice Attachment Upload Handler
 */
$REQUIRE_PERMISSION = 'upload_invoice_attachment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

requirePostRequest('/invoice/list.php');
requireCsrfToken('/invoice/list.php');

$invoice_id = (int)($_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    modalPop('Error', 'Invalid invoice reference.', '/invoice/list.php', 'error');
    exit;
}

// Verify invoice exists
$stmt = $pdo->prepare("SELECT invoice_id, invoice_number FROM invoices WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
    modalPop('Error', 'Invoice not found.', '/invoice/list.php', 'error');
    exit;
}

try {
    if (!isset($_FILES['attachment_file']) || $_FILES['attachment_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please select a file to upload.');
    }

    $file = $_FILES['attachment_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed. Please try again.');
    }

    $mimeMap = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    $stored = SecureFileStorage::storeUploadedFile(
        $file,
        'invoice_attachments',
        'INV_' . $invoice_id,
        $mimeMap,
        10 * 1024 * 1024
    );

    // Persist to database
    $ins = $pdo->prepare("
        INSERT INTO invoice_attachments
            (invoice_id, file_name, original_file_name, file_path, file_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $invoice_id,
        $stored['stored_name'],
        $stored['original_name'],
        $stored['storage_path'],
        $stored['mime_type'],
        $stored['file_size'],
        $_SESSION['user_id'],
    ]);

    $attachmentId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'invoice_attachments', $attachmentId, 'CREATE',
        "Invoice attachment uploaded: {$stored['original_name']} for Invoice #{$invoice['invoice_number']}");

    pop('Attachment uploaded successfully.', "/invoice/view.php?id={$invoice_id}", POP_DEFAULT_DELAY_MS, 'success');

} catch (Exception $e) {
    if (isset($stored)) {
        SecureFileStorage::deleteStoredFile($stored['storage_path']);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    modalPop('Upload Failed', $e->getMessage(), "/invoice/view.php?id={$invoice_id}", 'error');
}
