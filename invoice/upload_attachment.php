<?php
/**
 * Invoice Attachment Upload Handler
 */
$REQUIRE_PERMISSION = 'upload_invoice_attachment';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /invoice/list.php');
    exit;
}

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

    // Validate file type via MIME
    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new Exception('Invalid file type. Allowed types: PDF, JPG, JPEG, PNG, DOC, DOCX.');
    }

    // Also validate by extension as a secondary guard
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        throw new Exception('Invalid file extension. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX.');
    }

    // Validate file size (10 MB max)
    $maxBytes = 10 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new Exception('File size exceeds the 10 MB limit.');
    }

    // Build upload directory
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/invoice_attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Sanitize and generate unique filename
    $safeExt      = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $uniqueName   = 'INV_' . $invoice_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
    $uploadPath   = $uploadDir . $uniqueName;
    $relativePath = '/uploads/invoice_attachments/' . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('Failed to save the file. Please try again.');
    }

    // Sanitize original filename for storage
    $originalName = preg_replace('/[^\w.\-]/', '_', basename($file['name']));

    // Persist to database
    $ins = $pdo->prepare("
        INSERT INTO invoice_attachments
            (invoice_id, file_name, original_file_name, file_path, file_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $invoice_id,
        $uniqueName,
        $originalName,
        $relativePath,
        $mimeType,
        (int)$file['size'],
        $_SESSION['user_id'],
    ]);

    $attachmentId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'invoice_attachments', $attachmentId, 'CREATE',
        "Invoice attachment uploaded: {$originalName} for Invoice #{$invoice['invoice_number']}");

    pop('Attachment uploaded successfully.', "/invoice/view.php?id={$invoice_id}", POP_DEFAULT_DELAY_MS, 'success');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    modalPop('Upload Failed', $e->getMessage(), "/invoice/view.php?id={$invoice_id}", 'error');
}
