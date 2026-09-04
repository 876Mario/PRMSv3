<?php
$REQUIRE_PERMISSION = 'view_petty_cash_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/SecureFileStorage.php';

$documentId = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'download';

if ($documentId <= 0) {
    pop('Invalid document reference.', '/petty_cash/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT d.id, d.original_file_name, d.file_path, d.file_type,
           pcd.request_id, pr.request_number, pr.status, pr.created_by
    FROM petty_cash_reconciliation_documents d
    INNER JOIN petty_cash_reconciliations pcr ON pcr.reconcile_id = d.reconcile_id
    INNER JOIN petty_cash_disbursements pcd ON pcd.disburse_id = pcr.disburse_id
    INNER JOIN procurement_requests pr ON pr.request_id = pcd.request_id
    WHERE d.id = ? AND d.is_deleted = 0
    LIMIT 1
");
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document || empty($document['file_path'])) {
    pop('Document not found.', '/petty_cash/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

enforceRequestRecordAccess($document, '/petty_cash/list.php');

logAudit($pdo, 'petty_cash_reconciliation_documents', $documentId, strtoupper($action) === 'VIEW' ? 'VIEW' : 'DOWNLOAD', 'Petty cash reconciliation document accessed for ' . ($document['request_number'] ?? ('#' . $document['request_id'])));

try {
    SecureFileStorage::streamStoredFile(
        (string)$document['file_path'],
        (string)($document['file_type'] ?: SecureFileStorage::detectStoredMimeType((string)$document['file_path'], 'petty_cash_documents')),
        (string)($document['original_file_name'] ?: 'reconciliation-document'),
        $action,
        'petty_cash_documents'
    );
} catch (Throwable $e) {
    pop(extractDbMessage($e), '/petty_cash/view.php?request_id=' . (int)$document['request_id'], POP_DEFAULT_DELAY_MS, 'error');
}
