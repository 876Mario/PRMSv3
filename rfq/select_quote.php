<?php
/**
 * Select Quote
 * ============
 * Branch Head (or HOD equivalent) approves and selects a quote from reviewed quotes
 * This marks the quote as selected and transitions request to QUOTE_APPROVED
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

// Get user's role from database
$stmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRole = $stmt->fetch(PDO::FETCH_ASSOC);

// Get role name
$stmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
$stmt->execute([$userRole['role_id'] ?? 0]);
$roleName = $stmt->fetchColumn();

// Branch Heads approve RFQ quotations and quote recommendations
$quoteApproverRoles = ['Branch Head', 'HOD', 'Admin', 'SuperAdmin'];
if (!in_array($roleName, $quoteApproverRoles, true)) {
    pop("Only Branch Heads can approve/select quotes. Your role: {$roleName}", '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$quote_id = (int)($_GET['quote_id'] ?? 0);
$rfq_id = (int)($_GET['rfq_id'] ?? 0);

if (!$quote_id || !$rfq_id) {
    pop('Invalid parameters', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Fetch quote details
$stmt = $pdo->prepare("
    SELECT q.*, rv.rfq_vendor_id, v.vendor_name, r.request_id, pr.status as request_status,
           r.spec_review_status, r.branch_head_approval_status
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    JOIN rfqs r ON rv.rfq_id = r.rfq_id
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    WHERE q.quote_id = ? AND rv.rfq_id = ?
");
$stmt->execute([$quote_id, $rfq_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    pop('Quote not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check request status - must be QUOTE_REVIEW_PENDING
if ($quote['request_status'] !== 'QUOTE_REVIEW_PENDING') {
    pop('Quote selection is only available during QUOTE_REVIEW_PENDING stage', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Enforce the two-stage approval workflow: supplier selection cannot proceed
// until both the Requestor's Specification Review and the Branch Head's Final
// Approval have been completed. This prevents bypassing either approval stage.
if (($quote['spec_review_status'] ?? 'PENDING') !== 'APPROVED') {
    pop('Specification review must be approved by the Requestor before a quote can be selected.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if (($quote['branch_head_approval_status'] ?? 'PENDING') !== 'APPROVED') {
    pop('Branch Head final approval is required before a quote can be selected.', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Check if quote meets requirements
if (($quote['review_status'] ?? 'PENDING') === 'DOES_NOT_MEET') {
    pop('Cannot select a quote marked as "Does Not Meet Requirements"', '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Start transaction
try {
    $pdo->beginTransaction();

    // Mark this quote as selected
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET is_selected = 1
        WHERE quote_id = ?
    ");
    $stmt->execute([$quote_id]);

    // Unmark any other selected quotes for this RFQ
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET is_selected = 0
        WHERE quote_id != ? AND rfq_vendor_id IN (
            SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = ?
        )
    ");
    $stmt->execute([$quote_id, $rfq_id]);

    // Update procurement request status to QUOTE_APPROVED
    $stmt = $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'QUOTE_APPROVED'
        WHERE request_id = ?
    ");
    $stmt->execute([$quote['request_id']]);

    // Audit log
    $pdo->prepare("
        INSERT INTO audit_log (table_name, action, notes, change_date)
        VALUES ('rfq_quotes', 'SELECT', ?, NOW())
    ")->execute([
        "Quote {$quote_id} selected/approved by {$roleName} {$_SESSION['full_name']} - Vendor: {$quote['vendor_name']}, Amount: \${$quote['quote_amount']}"
    ]);

    $pdo->commit();
    
    // Notify requestor of quote selection
    require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";
    notifyQuoteSelected($quote['request_id'], $quote['vendor_name'], (float)$quote['quote_amount']);
    
    // Notify Finance Officers that commitment/funds verification is needed
    notifyFinanceCommitmentNeeded($quote['request_id'], $quote['vendor_name'], (float)$quote['quote_amount']);
    
    pop('Quote selected successfully. Next: Verify funds and create commitment.', '/commitments/add.php?request_id=' . $quote['request_id'], POP_DEFAULT_DELAY_MS, 'success');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    pop('Error selecting quote: ' . extractDbMessage($e), '/rfq/view.php?id=' . $rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}