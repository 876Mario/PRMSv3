<?php
/**
 * Payment Voucher Upload Handler
 */
$REQUIRE_PERMISSION = 'upload_payment_voucher';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

requirePostRequest('/payment/list.php');
requireCsrfToken('/payment/list.php');

$payment_id = (int)($_POST['payment_id'] ?? 0);
if ($payment_id <= 0) {
    modalPop('Error', 'Invalid payment reference.', '/payment/list.php', 'error');
    exit;
}

// Verify payment exists
$stmt = $pdo->prepare("SELECT payment_id, payment_reference FROM payments WHERE payment_id = ?");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payment) {
    modalPop('Error', 'Payment not found.', '/payment/list.php', 'error');
    exit;
}

try {
    if (!isset($_FILES['voucher_file']) || $_FILES['voucher_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please select a file to upload.');
    }

    $file = $_FILES['voucher_file'];

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
        'payment_vouchers',
        'PV_' . $payment_id,
        $mimeMap,
        10 * 1024 * 1024
    );

    // Persist to database
    $ins = $pdo->prepare("
        INSERT INTO payment_voucher_attachments
            (payment_id, file_name, original_file_name, file_path, file_type, file_size, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $payment_id,
        $stored['stored_name'],
        $stored['original_name'],
        $stored['storage_path'],
        $stored['mime_type'],
        $stored['file_size'],
        $_SESSION['user_id'],
    ]);

    $attachmentId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'payment_voucher_attachments', $attachmentId, 'CREATE',
        "Payment voucher uploaded: {$stored['original_name']} for Payment #{$payment['payment_reference']}");

    pop('Payment voucher uploaded successfully.', "/payment/view.php?id={$payment_id}", POP_DEFAULT_DELAY_MS, 'success');

} catch (Exception $e) {
    if (isset($stored)) {
        SecureFileStorage::deleteStoredFile($stored['storage_path']);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    modalPop('Upload Failed', $e->getMessage(), "/payment/view.php?id={$payment_id}", 'error');
}
