<?php
/**
 * /procurement/delete_document.php
 * ================================
 * Soft-delete a request document (supporting document) with permission
 * checking, mandatory deletion reason, and audit logging.
 *
 * The physical file is never removed here — only the database record is
 * flagged as deleted so the original file, uploader, upload date, and
 * deletion history remain available for audit purposes.
 *
 * POST params:
 *   - request_id: The procurement request the document belongs to
 *   - document_id: The request_documents record to soft-delete
 *   - reason: Mandatory reason for the deletion
 */

$REQUIRE_PERMISSION = 'procurement_delete_request_document';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /procurement/list.php');
    exit;
}

$request_id  = (int)($_POST['request_id'] ?? 0);
$document_id = (int)($_POST['document_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');

$redirectUrl = '/procurement/view.php?id=' . $request_id;

if ($request_id <= 0 || $document_id <= 0) {
    pop('Invalid request parameters.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($reason === '') {
    logAudit($pdo, 'request_documents', $document_id, 'DELETE_DENIED',
        'Delete attempt rejected: no deletion reason provided');
    pop('A reason is required to delete a document.', $redirectUrl, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (strlen($reason) > 500) {
    $reason = substr($reason, 0, 500);
}

try {
    /* Fetch the request (to confirm it exists and check finalized status) */
    $stmt = $pdo->prepare("SELECT request_id, request_number, status FROM procurement_requests WHERE request_id = ?");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        logAudit($pdo, 'request_documents', $document_id, 'DELETE_DENIED',
            "Delete attempt rejected: request #$request_id not found");
        pop('Request not found.', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* Fetch the document, ensuring it belongs to this request and isn't already deleted */
    $stmt = $pdo->prepare("
        SELECT document_id, document_type, document_name, is_deleted
        FROM request_documents
        WHERE document_id = ? AND request_id = ?
    ");
    $stmt->execute([$document_id, $request_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        logAudit($pdo, 'request_documents', $document_id, 'DELETE_DENIED',
            "Delete attempt rejected: document not found on request {$request['request_number']}");
        pop('Document not found on this request.', $redirectUrl, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    if ($document['is_deleted']) {
        pop('This document has already been deleted.', $redirectUrl, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* A request is considered "finalized" once it has reached COMPLETED status.
       Deleting documents already incorporated into a finalized transaction
       requires the elevated permission. */
    $isFinalized = isFinalizedRequestStatus($request['status'] ?? '');

    if ($isFinalized && !hasPermission('procurement_delete_finalized_document')) {
        logAudit($pdo, 'request_documents', $document_id, 'DELETE_DENIED',
            "Delete attempt rejected: request {$request['request_number']} is finalized ({$request['status']}) and user lacks elevated permission. Document: {$document['document_name']}");
        pop('This document belongs to a finalized request and cannot be deleted without elevated administrative permission.', $redirectUrl, POP_DEFAULT_DELAY_MS, 'error');
        exit;
    }

    /* Perform soft delete — the physical file and upload metadata are retained */
    $stmt = $pdo->prepare("
        UPDATE request_documents
        SET is_deleted = 1, deleted_by = ?, deleted_at = NOW(), deletion_reason = ?
        WHERE document_id = ?
    ");
    $stmt->execute([
        $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown',
        $reason,
        $document_id
    ]);

    logAudit($pdo, 'request_documents', $document_id, 'SOFT_DELETE',
        "Document '{$document['document_name']}' ({$document['document_type']}) deleted from request {$request['request_number']}. Reason: $reason");
    logRequestTimeline($pdo, $request_id, 'DOCUMENT_DELETED',
        "Document deleted: {$document['document_name']}. Reason: $reason");

    pop('Document deleted successfully.', $redirectUrl, POP_DEFAULT_DELAY_MS, 'success');

} catch (Throwable $e) {
    try {
        logAudit($pdo, 'request_documents', $document_id, 'DELETE_FAILED',
            'Delete attempt failed: ' . $e->getMessage());
    } catch (Throwable $e2) {
        error_log('delete_document.php audit log failure: ' . $e2->getMessage());
    }
    pop('Failed to delete document: ' . extractDbMessage($e), $redirectUrl, POP_DEFAULT_DELAY_MS, 'error');
}
