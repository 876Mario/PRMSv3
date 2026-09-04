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

$stmt = $pdo->prepare("SELECT contract_id, contract_number, document_path FROM service_contracts WHERE contract_id = ? LIMIT 1");
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract || empty($contract['document_path'])) {
    pop('Contract document not found.', '/contracts/view.php?id=' . $contractId, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

logAudit($pdo, 'service_contracts', $contractId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'Contract document accessed for ' . ($contract['contract_number'] ?? ('#' . $contractId)));

try {
    SecureFileStorage::streamStoredFile(
        (string)$contract['document_path'],
        'application/octet-stream',
        (string)(basename((string)$contract['document_path']) ?: 'contract-document'),
        $action,
        'contracts'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/contracts/view.php?id=' . $contractId, POP_DEFAULT_DELAY_MS, 'error');
}
