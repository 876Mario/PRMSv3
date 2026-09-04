<?php
$REQUIRE_PERMISSION = 'create_rfq';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pop('Invalid request', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

requireCsrfToken('/rfq/list.php');

$rfq_id = (int)($_POST['rfq_id'] ?? 0);
if ($rfq_id <= 0) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Verify RFQ exists
$stmt = $pdo->prepare("SELECT rfq_id, status FROM rfqs WHERE rfq_id = ?");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Only Procurement Officers, Admin, SuperAdmin
$allowedRoles = ['Procurement Officer', 'Admin', 'SuperAdmin'];
if (!in_array(($_SESSION['role_name'] ?? ''), $allowedRoles)) {
    pop('Only Procurement Officers can upload RFQ letters.', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

try {
    if (!isset($_FILES['rfq_letter']) || $_FILES['rfq_letter']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Please select an RFQ letter document to upload.");
    }

    $file = $_FILES['rfq_letter'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed. Please try again.");
    }

    $stored = SecureFileStorage::storeUploadedFile(
        $file,
        'rfq_letters',
        'RFQ_LETTER_' . $rfq_id,
        [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ],
        50 * 1024 * 1024
    );
    
    // Update RFQ with letter file path
    $stmt = $pdo->prepare("UPDATE rfqs SET rfq_letter_file = ? WHERE rfq_id = ?");
    $stmt->execute([$stored['storage_path'], $rfq_id]);
    
    // Log audit
    logAudit($pdo, 'rfqs', $rfq_id, 'UPDATE', 'RFQ letter uploaded');
    
    pop('RFQ letter uploaded successfully.', '/rfq/view.php?id='.$rfq_id, 2000, 'success');
    exit;
    
} catch (Throwable $e) {
    if (isset($stored)) {
        SecureFileStorage::deleteStoredFile($stored['storage_path'], 'rfq_letters');
    }
    pop(extractDbMessage($e), '/rfq/view.php?id='.$rfq_id, 2500, 'error');
    exit;
}
