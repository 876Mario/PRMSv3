<?php
/**
 * /rfq/delete_quote.php
 * ====================
 * Soft-delete an RFQ quote with permission checking and audit logging.
 * 
 * GET/POST params:
 *   - rfq_id: The RFQ ID
 *   - quote_id: The quote record to delete
 */

$REQUIRE_PERMISSION = 'procurement_delete_quote';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$rfq_id = (int)($_GET['rfq_id'] ?? $_POST['rfq_id'] ?? 0);
$quote_id = (int)($_GET['quote_id'] ?? $_POST['quote_id'] ?? 0);

if (!$rfq_id || !$quote_id) {
    pop('Invalid request parameters', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch RFQ to verify status */
$stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfqs WHERE rfq_id = ?");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Prevent deletion if RFQ is AWARDED */
if ($rfq['status'] === 'AWARDED') {
    pop('Cannot delete quotes from an awarded RFQ', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Verify quote exists, belongs to this RFQ, and is not already deleted */
$stmt = $pdo->prepare("
    SELECT q.quote_id, q.rfq_vendor_id, q.quote_amount, q.currency, 
           q.is_selected, q.is_deleted, v.vendor_name
    FROM rfq_quotes q
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND q.quote_id = ?
");
$stmt->execute([$rfq_id, $quote_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    pop('Quote not found on this RFQ', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($quote['is_deleted']) {
    pop('Quote is already deleted', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Warn if quote was selected */
$wasSelected = $quote['is_selected'] ? ' (was selected)' : '';

/* Perform soft delete */
try {
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET is_deleted = 1, deleted_by = ?, deleted_at = NOW()
        WHERE quote_id = ?
    ");
    $stmt->execute([
        $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown',
        $quote_id
    ]);

    /* If the quote was selected, clear selection only from this quote */
    if ($quote['is_selected']) {
        $stmt = $pdo->prepare("
            UPDATE rfq_quotes
            SET is_selected = 0
            WHERE quote_id = ? AND is_selected = 1
        ");
        $stmt->execute([$quote_id]);
    }

    /* Log audit trail */
    logAudit($pdo, 'rfq_quotes', $quote_id, 'SOFT_DELETE', 
        'Quote from "' . $quote['vendor_name'] . '" (' . $quote['currency'] . ' ' . 
        number_format((float)$quote['quote_amount'], 2) . ') soft-deleted');

    $_SESSION['popup_success'] = 'Quote from ' . htmlspecialchars($quote['vendor_name']) . $wasSelected . ' deleted successfully.';

} catch (Throwable $e) {
    $_SESSION['popup_error'] = 'Failed to delete quote: ' . extractDbMessage($e);
}

header("Location: /rfq/view.php?id=" . $rfq_id);
exit;
