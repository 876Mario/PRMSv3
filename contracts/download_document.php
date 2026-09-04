<?php
$REQUIRE_PERMISSION = 'view_contracts';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/SecureFileStorage.php';

$contractId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($contractId <= 0) {
    pop('Invalid contract reference.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("SELECT contract_id, contract_number, document_path, created_by FROM service_contracts WHERE contract_id = ? LIMIT 1");
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract || empty($contract['document_path'])) {
    pop('Contract document not found.', '/contracts/view.php?id=' . $contractId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$canAccess = has_permission('manage_contracts')
    || (int)($contract['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);

if (!$canAccess) {
    $linkedRequests = $pdo->prepare("SELECT request_id, created_by, status FROM procurement_requests WHERE contract_id = ?");
    $linkedRequests->execute([$contractId]);

    foreach ($linkedRequests->fetchAll(PDO::FETCH_ASSOC) as $request) {
        if (canCurrentUserAccessRequestRecord($request)) {
            $canAccess = true;
            break;
        }
    }
}

if (!$canAccess) {
    pop('You do not have permission to access this contract document.', '/contracts/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

logAudit($pdo, 'service_contracts', $contractId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'Contract document accessed for ' . ($contract['contract_number'] ?? ('#' . $contractId)));

try {
    $mimeType = SecureFileStorage::detectStoredMimeType(
        (string)$contract['document_path'],
        'contracts'
    );
    $extensionMap = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $downloadName = trim((string)($contract['contract_number'] ?? 'contract-document'));
    $downloadName .= isset($extensionMap[$mimeType]) ? '.' . $extensionMap[$mimeType] : '';

    SecureFileStorage::streamStoredFile(
        (string)$contract['document_path'],
        $mimeType,
        $downloadName,
        $action,
        'contracts'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/contracts/view.php?id=' . $contractId, POP_DEFAULT_DELAY_MS, 'error');
}
