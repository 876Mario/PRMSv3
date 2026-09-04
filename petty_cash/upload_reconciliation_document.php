<?php
/**
 * Upload Supporting Documents for Petty Cash Reconciliation
 * Finance Officer can attach receipts, invoices, and supporting documentation
 */

$REQUIRE_PERMISSION = 'verify_petty_cash_reconciliation';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /petty_cash/list.php');
    exit;
}

requireCsrfToken('/petty_cash/list.php');

$reconcile_id = isset($_POST['reconcile_id']) ? (int)$_POST['reconcile_id'] : 0;
$document_type = isset($_POST['document_type']) ? trim($_POST['document_type']) : '';
$document_notes = isset($_POST['document_notes']) ? trim($_POST['document_notes']) : '';

if (empty($document_type)) {
    pop("Please select a document type.", "/petty_cash/list.php", 2000, "error");
    exit;
}

if ($reconcile_id <= 0) {
    pop("Invalid reconciliation reference.", "/petty_cash/list.php", 2000, "error");
    exit;
}

/* ============================================================
   VERIFY RECONCILIATION EXISTS
   ============================================================ */
$reconcileStmt = $pdo->prepare("
    SELECT pcr.reconcile_id, pcd.request_id
    FROM petty_cash_reconciliations pcr
    INNER JOIN petty_cash_disbursements pcd ON pcr.disburse_id = pcd.disburse_id
    WHERE pcr.reconcile_id = ?
");
$reconcileStmt->execute([$reconcile_id]);
$reconciliation = $reconcileStmt->fetch(PDO::FETCH_ASSOC);

if (!$reconciliation) {
    pop("Reconciliation not found.", "/petty_cash/list.php", 2000, "error");
    exit;
}

$request_id = (int)$reconciliation['request_id'];

/* ============================================================
   VALIDATE FILE UPLOAD
   ============================================================ */
if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
    pop("Please select a file to upload.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

$file = $_FILES['document_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    pop("File upload failed. Please try again.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

/* ============================================================
   PROCESS FILE UPLOAD
   ============================================================ */
try {
    $checkTable = $pdo->prepare("
        SELECT 1 FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'petty_cash_reconciliation_documents'
    ");
    $checkTable->execute();
    if (!$checkTable->fetchColumn()) {
        throw new Exception("Supporting document storage is not available.");
    }

    $stored = SecureFileStorage::storeUploadedFile(
        $file,
        'petty_cash_documents',
        $document_type . '_' . $reconcile_id,
        [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ],
        50 * 1024 * 1024
    );

    $insertStmt = $pdo->prepare("
        INSERT INTO petty_cash_reconciliation_documents
        (reconcile_id, document_type, file_name, original_file_name, file_path, file_type, file_size, uploaded_by, document_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $reconcile_id,
        $document_type,
        $stored['stored_name'],
        $stored['original_name'],
        $stored['storage_path'],
        $stored['mime_type'],
        $stored['file_size'],
        (int)$_SESSION['user_id'],
        $document_notes !== '' ? $document_notes : null
    ]);

    logAudit(
        $pdo,
        'petty_cash_reconciliation_documents',
        (int)$pdo->lastInsertId(),
        'CREATE',
        "Document uploaded: {$stored['original_name']} ({$document_type})"
    );

    logRequestTimeline(
        $pdo,
        $request_id,
        'RECONCILIATION_DOCUMENT_UPLOADED',
        "Supporting document uploaded by Finance: {$stored['original_name']}"
    );

    pop(
        "Document uploaded successfully.",
        "/petty_cash/view.php?request_id={$request_id}",
        1500,
        "success"
    );

} catch (Throwable $e) {
    if (isset($stored)) {
        SecureFileStorage::deleteStoredFile($stored['storage_path'], 'petty_cash_documents');
    }
    error_log("Document upload error: " . $e->getMessage());
    pop(
        "An error occurred while uploading the document. Please try again or contact support.",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
}
?>
