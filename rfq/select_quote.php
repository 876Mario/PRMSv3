<?php
/**
 * Select Quote
 * Branch Head/HOD selects the preferred vendor quote and routes the RFQ to the
 * original requestor for specification confirmation.
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/RFQQuoteApprovalService.php';

$stmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
$stmt->execute([$userRole['role_id'] ?? 0]);
$roleName = $stmt->fetchColumn();

$quoteApproverRoles = ['Branch Head', 'HOD', 'Admin', 'SuperAdmin', 'Director HRM&A'];
if (!in_array($roleName, $quoteApproverRoles, true)) {
    pop("Only Branch Heads can select quotes. Your role: {$roleName}", '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$quote_id = (int)($_GET['quote_id'] ?? 0);
$rfq_id = (int)($_GET['rfq_id'] ?? 0);
if (!$quote_id || !$rfq_id) {
    pop('Invalid parameters', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT q.*, rv.rfq_vendor_id, v.vendor_name, r.request_id,
            r.requestor_spec_review_status, r.branch_head_approval_status,
            pr.status AS request_status
     FROM rfq_quotes q
     JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
     JOIN vendors v ON v.vendor_id = rv.vendor_id
     JOIN rfqs r ON r.rfq_id = rv.rfq_id
     JOIN procurement_requests pr ON pr.request_id = r.request_id
     WHERE q.quote_id = ? AND rv.rfq_id = ? AND COALESCE(q.is_deleted, 0) = 0"
);
$stmt->execute([$quote_id, $rfq_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    pop('Quote not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (($quote['request_status'] ?? '') !== 'QUOTE_REVIEW_PENDING') {
    pop('Quote selection is only available during the quote review stage.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (($quote['review_status'] ?? 'PENDING') === 'DOES_NOT_MEET') {
    pop('Cannot select a quote marked as not meeting requirements.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$approvalService = new RFQQuoteApprovalService($pdo, (int)$_SESSION['user_id'], $_SESSION['role_name'] ?? '');

try {
    $pdo->beginTransaction();

    $selectedStmt = $pdo->prepare(
        "SELECT q.quote_id
         FROM rfq_quotes q
         JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
         WHERE rv.rfq_id = ? AND q.is_selected = 1 AND COALESCE(q.is_deleted, 0) = 0
         LIMIT 1"
    );
    $selectedStmt->execute([$rfq_id]);
    $previousSelectedQuoteId = (int)($selectedStmt->fetchColumn() ?: 0);

    $pdo->prepare("UPDATE rfq_quotes SET is_selected = 1 WHERE quote_id = ?")->execute([$quote_id]);
    $pdo->prepare(
        "UPDATE rfq_quotes
            SET is_selected = 0
          WHERE quote_id != ?
            AND rfq_vendor_id IN (SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = ?)"
    )->execute([$quote_id, $rfq_id]);

    if ($previousSelectedQuoteId > 0 && $previousSelectedQuoteId !== $quote_id
        && (in_array(($quote['requestor_spec_review_status'] ?? 'PENDING'), ['APPROVED', 'REJECTED'], true)
            || in_array(($quote['branch_head_approval_status'] ?? 'PENDING'), ['APPROVED', 'REJECTED'], true))) {
        // If the selected quote changes after requestor review work has begun,
        // both approval stages must restart against the newly-selected quote.
        $approvalService->resetApprovalsOnQuoteChange($rfq_id);
    } else {
        $pdo->prepare(
            "UPDATE rfqs
                SET requestor_spec_review_status = 'PENDING',
                    requestor_reviewer_id = NULL,
                    requestor_reviewed_at = NULL,
                    requestor_review_comments = NULL,
                    branch_head_approval_status = 'PENDING',
                    branch_head_approver_id = NULL,
                    branch_head_approved_at = NULL,
                    branch_head_comments = NULL
              WHERE rfq_id = ?"
        )->execute([$rfq_id]);
    }

    $pdo->prepare("UPDATE procurement_requests SET status = 'QUOTE_REQUESTOR_REVIEW_PENDING' WHERE request_id = ?")
        ->execute([(int)$quote['request_id']]);

    $pdo->prepare(
        "INSERT INTO audit_log (table_name, action, notes, change_date, approval_stage, approval_action)
         VALUES ('rfq_quotes', 'SELECT', ?, CURRENT_TIMESTAMP, 'REQUESTOR_REVIEW', 'ROUTED')"
    )->execute([
        "Quote {$quote_id} selected by {$roleName} " . ($_SESSION['full_name'] ?? 'Unknown') . "; routed to the original requestor for specification confirmation. Vendor: {$quote['vendor_name']}, Amount: {$quote['quote_amount']}"
    ]);

    logRequestTimeline(
        $pdo,
        (int)$quote['request_id'],
        'QUOTE_REQUESTOR_REVIEW_PENDING',
        'Selected quote routed to the original requestor for specification confirmation by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    $pdo->commit();

    require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';
    sendRequestorReviewNotification($rfq_id);

    pop('Quote selected successfully. The RFQ has been routed to the original requestor for specification confirmation.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'success');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pop('Error selecting quote: ' . extractDbMessage($e), '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}
