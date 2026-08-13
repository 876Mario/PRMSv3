<?php
/**
 * Upload Supporting Documents for Petty Cash Reconciliation
 * Finance Officer can attach receipts, invoices, and supporting documentation
 */

$REQUIRE_PERMISSION = 'verify_petty_cash_reconciliation';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /petty_cash/list.php');
    exit;
}

$reconcile_id = isset($_POST['reconcile_id']) ? (int)$_POST['reconcile_id'] : 0;
$document_type = isset($_POST['document_type']) ? trim($_POST['document_type']) : 'OTHER';
$document_notes = isset($_POST['document_notes']) ? trim($_POST['document_notes']) : '';

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

// Validate file type
$allowedTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];

if (!function_exists('finfo_open')) {
    pop("File type validation is not available. Please contact the system administrator.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    pop("Unable to validate file type. Please try again.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    pop("Invalid file type. Only PDF, images, and Office documents are allowed.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

// Validate file size (50MB max)
$maxSize = 50 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    pop("File size exceeds 50MB limit.", "/petty_cash/view.php?request_id={$request_id}", 2000, "error");
    exit;
}

/* ============================================================
   PROCESS FILE UPLOAD
   ============================================================ */
try {
    // Create uploads directory if it doesn't exist
    $uploadsDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/petty_cash_documents';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            throw new Exception("Failed to create uploads directory.");
        }
    }

    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = "{$document_type}_{$reconcile_id}_" . time() . "_" . uniqid() . "." . strtolower($ext);
    $filePath = $uploadsDir . '/' . $fileName;

    // Move file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    // Check if petty_cash_reconciliation_documents table exists
    $checkTable = $pdo->prepare("
        SELECT 1 FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'petty_cash_reconciliation_documents'
    ");
    $checkTable->execute();
    
    if ($checkTable->fetchColumn()) {
        // Insert document record
        $insertStmt = $pdo->prepare("
            INSERT INTO petty_cash_reconciliation_documents
            (reconcile_id, document_type, file_name, original_file_name, file_path, file_type, file_size, uploaded_by, document_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $reconcile_id,
            $document_type,
            $fileName,
            $file['name'],
            '/uploads/petty_cash_documents/' . $fileName,
            $mimeType,
            $file['size'],
            (int)$_SESSION['user_id'],
            $document_notes !== '' ? $document_notes : null
        ]);

        // Audit log
        logAudit(
            $pdo,
            'petty_cash_reconciliation_documents',
            (int)$pdo->lastInsertId(),
            'CREATE',
            "Document uploaded: {$file['name']} ({$document_type})"
        );

        logRequestTimeline(
            $pdo,
            $request_id,
            'RECONCILIATION_DOCUMENT_UPLOADED',
            "Supporting document uploaded by Finance: {$file['name']}"
        );

        pop(
            "Document uploaded successfully.",
            "/petty_cash/view.php?request_id={$request_id}",
            1500,
            "success"
        );
    } else {
        // If table doesn't exist yet, at least log the upload
        logRequestTimeline(
            $pdo,
            $request_id,
            'RECONCILIATION_DOCUMENT_UPLOADED',
            "Document uploaded: {$file['name']} to /uploads/petty_cash_documents/{$fileName}"
        );

        pop(
            "Document uploaded successfully.",
            "/petty_cash/view.php?request_id={$request_id}",
            1500,
            "success"
        );
    }

} catch (Throwable $e) {
    error_log("Document upload error: " . $e->getMessage());
    pop(
        "Error uploading document: " . $e->getMessage(),
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
}
?>
