<?php
/**
 * Upload Signed Reimbursement Invoice Approval Form
 * 
 * Handles Finance Officer signed approval document uploads
 * Validates file type, size, and ownership
 * Maintains version history and audit trail
 */

$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RequestDocumentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /reimbursement/list.php');
    exit;
}

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    pop("Invalid request.", "/reimbursement/list.php", 2500, "error");
    exit;
}

// Load request
try {
    $stmt = $pdo->prepare("
        SELECT request_id, request_number, request_type, status, created_by
        FROM procurement_requests 
        WHERE request_id = ? AND request_type = 'REIMBURSEMENT'
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    pop("Error fetching request: " . extractDbMessage($e), "/reimbursement/list.php", 2500, "error");
    exit;
}

if (!$request) {
    pop("Reimbursement request not found.", "/reimbursement/list.php", 2500, "error");
    exit;
}

// Initialize service
$docService = new RequestDocumentService(
    $pdo,
    $request_id,
    $_SESSION['user_id'] ?? 0,
    $_SESSION['role_name'] ?? '',
    $_SESSION['full_name'] ?? 'Unknown User'
);

try {
    // Check authorization
    $authCheck = $docService->checkUploadAuthorization($request);
    if (!$authCheck['authorized']) {
        pop($authCheck['reason'], "/reimbursement/list.php", 2500, "error");
        exit;
    }
    
    // Check workflow constraints
    $workflowCheck = $docService->checkWorkflowConstraints($request);
    if (!$workflowCheck['allowed']) {
        pop($workflowCheck['reason'], "/reimbursement/view.php?id=" . $request_id, 2500, "error");
        exit;
    }
    
    // Check file was uploaded
    if (!isset($_FILES['signed_form_file']) || $_FILES['signed_form_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Please select a file to upload.");
    }
    
    $file = $_FILES['signed_form_file'];
    
    // Validate file
    $validation = $docService->validateFile($file);
    if (!$validation['valid']) {
        throw new Exception($validation['error']);
    }
    
    // Process upload
    $uploadResult = $docService->processUpload($file, $request);
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error']);
    }
    
    // Save to database
    $saveResult = $docService->saveToDatabase($uploadResult, $request);
    if (!$saveResult['success']) {
        throw new Exception($saveResult['error']);
    }
    
    // Log actions
    $docService->logUploadAction($request, $uploadResult);
    
    // Send notifications
    $docService->sendNotifications($request);
    
    pop(
        "Signed approval form uploaded successfully! Finance team will review it shortly.",
        "/reimbursement/view.php?id=" . $request_id,
        2500,
        "success"
    );
    
} catch (Exception $e) {
    error_log("Signed form upload error for reimbursement request " . $request_id . ": " . $e->getMessage());
    pop(extractDbMessage($e), "/reimbursement/view.php?id=" . $request_id, 2500, "error");
}
?>
