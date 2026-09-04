<?php
/**
 * Upload Document for a Procurement Request
 * Handles signed POs, signed commitments, and other documents per request
 */
$REQUIRE_PERMISSION = 'view_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

requirePostRequest('/procurement/list.php');
requireCsrfToken('/procurement/list.php');

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    pop("Invalid request.", "/procurement/list.php", 2500, "warning");
    exit;
}

// Verify request exists
$stmt = $pdo->prepare("SELECT request_id, request_number, request_type FROM procurement_requests WHERE request_id = ?");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    pop("Request not found.", "/procurement/list.php", 2500, "warning");
    exit;
}

try {
    // Validate document type
    $documentType = $_POST['document_type'] ?? 'OTHER';
    if (!in_array($documentType, ['SIGNED_PO', 'SIGNED_COMMITMENT', 'SIGNED_REQUEST', 'MEMO', 'OTHER'])) {
        throw new Exception("Invalid document type.");
    }

    $notes = trim($_POST['notes'] ?? '');
    if (strlen($notes) > 255) {
        throw new Exception("Notes must not exceed 255 characters.");
    }

    // Validate file upload
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Please select a file to upload.");
    }

    $file = $_FILES['document_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed. Please try again.");
    }

    $stored = SecureFileStorage::storeUploadedFile(
        $file,
        'request_documents',
        strtoupper($documentType) . '_' . $request_id,
        [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ],
        50 * 1024 * 1024
    );

    $documentPath = $stored['storage_path'];
    $originalName = $stored['original_name'];
    $mimeType = $stored['mime_type'];

    // Insert into request_documents table
    $stmt = $pdo->prepare("
        INSERT INTO request_documents (request_id, document_type, document_name, document_path, uploaded_by, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $request_id,
        $documentType,
        $originalName,
        $documentPath,
        $_SESSION['user_id'],
        !empty($notes) ? $notes : null
    ]);

    $typeLabels = [
        'SIGNED_PO' => 'Signed PO',
        'SIGNED_COMMITMENT' => 'Signed Commitment',
        'SIGNED_REQUEST' => 'Signed Request Form',
        'MEMO' => 'Supporting Memo',
        'OTHER' => 'Document'
    ];
    $typeLabel = $typeLabels[$documentType] ?? 'Document';

    logAudit($pdo, 'request_documents', $pdo->lastInsertId(), 'CREATE',
            "$typeLabel uploaded for request {$request['request_number']}");
    logRequestTimeline($pdo, $request_id, 'DOCUMENT_UPLOADED',
                      "$typeLabel uploaded: $originalName");

    // When a Signed Request Form is uploaded here, also register it as the
    // official signed request so the approval gate clears and the branch head
    // can approve or deny the request.
    $successMessage = "$typeLabel uploaded successfully.";
    $successType = "success";
    $requestType = strtoupper($request['request_type'] ?? 'REGULAR');
    if ($documentType === 'SIGNED_REQUEST' && in_array($requestType, ['REGULAR', 'REIMBURSEMENT', 'PETTY_CASH'], true)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SignedRequestService.php';
        $signedService = new SignedRequestService($pdo);
        $registration = $signedService->registerStoredDocument(
            $request_id,
            $requestType,
            $documentPath,
            $originalName,
            $mimeType,
            (int)$stored['file_size'],
            (int)$_SESSION['user_id']
        );

        if ($registration['success']) {
            $successMessage = "$typeLabel uploaded and registered as the signed request (Version {$registration['version']}).";

            try {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
                if (function_exists('notifySignedRequestReceived')) {
                    notifySignedRequestReceived($request_id, $request['request_number']);
                }
            } catch (Exception $e) {
                error_log('Warning: Failed to send notification for signed request ' . $request_id . ': ' . $e->getMessage());
            }
        } else {
            $successMessage = "$typeLabel uploaded, but it could not be registered as the signed request.";
            $successType = "warning";
        }
    }

    pop(
        $successMessage,
        "/procurement/view.php?id=" . $request_id,
        2500,
        $successType
    );

} catch (Exception $e) {
    if (isset($stored)) {
        SecureFileStorage::deleteStoredFile($stored['storage_path']);
    }
    pop(extractDbMessage($e), "/procurement/view.php?id=" . $request_id, 2500, "error");
}