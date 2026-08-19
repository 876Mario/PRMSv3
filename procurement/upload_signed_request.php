<?php
/**
 * Upload Signed Procurement Request
 * Handles branch head signed request uploads
 */
$REQUIRE_PERMISSION = 'upload_procurement_signed_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /procurement/list.php');
    exit;
}

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    pop("Invalid request.", "/procurement/list.php", 2500, "error");
    exit;
}

// Verify request exists and user permissions
try {
    $stmt = $pdo->prepare("
        SELECT request_id, request_number, created_by, status, request_type
        FROM procurement_requests 
        WHERE request_id = ? AND request_type = 'REGULAR'
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    pop("Error fetching request: " . extractDbMessage($e), "/procurement/list.php", 2500, "error");
    exit;
}

if (!$request) {
    pop("Request not found.", "/procurement/list.php", 2500, "error");
    exit;
}

// Check if user is the requestor or has permission to sign/upload
$isRequestor = ($_SESSION['user_id'] == $request['created_by']);
$canSeeRequest = in_array($_SESSION['role'] ?? '', ['HOD', 'Branch Head', 'Director HRM&A', 'Deputy Government Chemist', 'Admin', 'SuperAdmin', 'Requestor']);

if (!$isRequestor && !$canSeeRequest) {
    pop("You don't have permission to upload signed requests for this request.", "/procurement/list.php", 2500, "error");
    exit;
}

try {
    // Validate file upload
    if (!isset($_FILES['signed_request_file']) || $_FILES['signed_request_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Please select a file to upload.");
    }

    $file = $_FILES['signed_request_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed. Please try again.");
    }

    // Validate file type (PDF, images, Word docs)
    $allowedTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    if (!function_exists('finfo_open')) {
        throw new Exception("File type validation is not available. Please contact the system administrator.");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new Exception("Unable to validate file type. Please try again.");
    }
    
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mimeType === false || !in_array($mimeType, $allowedTypes)) {
        throw new Exception("Invalid file type. Only PDF, images (JPG/PNG/GIF), and Word documents are allowed.");
    }

    // Validate file size (25MB max for images/PDFs)
    if ($file['size'] > 25 * 1024 * 1024) {
        throw new Exception("File size exceeds 25 MB limit.");
    }

    // Create upload directory if needed
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/signed_requests/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception("Failed to create upload directory. Please contact the system administrator.");
        }
    }

    // Generate safe filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeFilename = 'SIGNED_REQUEST_' . $request_id . '_' . time() . '_' . uniqid() . '.' . $ext;
    $uploadPath = $uploadDir . $safeFilename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception("Failed to save document. Please try again.");
    }

    $documentPath = '/uploads/signed_requests/' . $safeFilename;
    $originalName = $file['name'];

    // Start transaction
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    // Update procurement_requests with signed request info
    try {
        $updateStmt = $pdo->prepare("
            UPDATE procurement_requests
            SET signed_request_document_path = ?,
                signed_request_received_date = NOW(),
                signed_by_user_id = ?
            WHERE request_id = ?
        ");
        $updateStmt->execute([
            $documentPath,
            $_SESSION['user_id'],
            $request_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Failed to update request: " . $e->getMessage());
    }

    // Also save to request_documents for audit trail
    try {
        $docStmt = $pdo->prepare("
            INSERT INTO request_documents 
            (request_id, document_type, document_name, document_path, uploaded_by, notes)
            VALUES (?, 'SIGNED_REQUEST', ?, ?, ?, ?)
        ");
        $docStmt->execute([
            $request_id,
            $originalName,
            $documentPath,
            $_SESSION['user_id'],
            'Signed request uploaded by ' . ($_SESSION['full_name'] ?? 'User')
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Failed to save document record: " . $e->getMessage());
    }

    // Log the action
    try {
        logAudit($pdo, 'procurement_requests', $request_id, 'UPDATE',
                "Signed request uploaded: $originalName");
        logRequestTimeline($pdo, $request_id, 'SIGNED_REQUEST_UPLOADED',
                         "Signed request uploaded by " . ($_SESSION['full_name'] ?? 'User') . ": $originalName");
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Failed to log action: " . $e->getMessage());
    }

    $pdo->commit();

    // Notify procurement officers
    try {
        require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';
        if (function_exists('notifySignedRequestReceived')) {
            notifySignedRequestReceived($request_id, $request['request_number']);
        }
    } catch (Exception $e) {
        // Log notification error but don't fail the entire operation
        error_log("Warning: Failed to send notification for signed request " . $request_id . ": " . $e->getMessage());
    }

    pop(
        "Signed request uploaded successfully! Procurement team will review it shortly.",
        "/procurement/view.php?id=" . $request_id,
        2500,
        "success"
    );

} catch (Exception $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pop(extractDbMessage($e), "/procurement/view.php?id=" . $request_id, 2500, "error");
}