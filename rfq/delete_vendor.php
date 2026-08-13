<?php
/**
 * /rfq/delete_vendor.php
 * =====================
 * Soft-delete an RFQ vendor with permission checking and audit logging.
 * 
 * GET/POST params:
 *   - rfq_id: The RFQ ID
 *   - rfq_vendor_id: The RFQ vendor record to delete
 */

$REQUIRE_PERMISSION = 'procurement_delete_vendor';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$rfq_id = (int)($_GET['rfq_id'] ?? $_POST['rfq_id'] ?? 0);
$rfq_vendor_id = (int)($_GET['rfq_vendor_id'] ?? $_POST['rfq_vendor_id'] ?? 0);

if (!$rfq_id || !$rfq_vendor_id) {
    pop('Invalid request parameters', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch RFQ to verify ownership and status */
$stmt = $pdo->prepare("SELECT rfq_id, rfq_number, status FROM rfqs WHERE rfq_id = ?");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Prevent deletion if RFQ is AWARDED */
if ($rfq['status'] === 'AWARDED') {
    pop('Cannot delete vendors from an awarded RFQ', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Verify vendor exists and is not already deleted */
$stmt = $pdo->prepare("
    SELECT rv.rfq_vendor_id, v.vendor_name, rv.is_deleted
    FROM rfq_vendors rv
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE rv.rfq_id = ? AND rv.rfq_vendor_id = ?
");
$stmt->execute([$rfq_id, $rfq_vendor_id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    pop('Vendor not found on this RFQ', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($vendor['is_deleted']) {
    pop('Vendor is already deleted', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Check if vendor has submitted quotes — soft delete only, don't prevent */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM rfq_quotes WHERE rfq_vendor_id = ? AND is_deleted = 0");
$stmt->execute([$rfq_vendor_id]);
$quoteCount = (int)$stmt->fetchColumn();

$warningMessage = '';
if ($quoteCount > 0) {
    $warningMessage = " and $quoteCount associated quote(s)";
}

/* Perform soft delete of vendor */
try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        UPDATE rfq_vendors
        SET is_deleted = 1, deleted_by = ?, deleted_at = NOW()
        WHERE rfq_id = ? AND rfq_vendor_id = ?
    ");
    $stmt->execute([
        $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown',
        $rfq_id,
        $rfq_vendor_id
    ]);

    /* Soft delete associated quotes */
    $stmt = $pdo->prepare("
        UPDATE rfq_quotes
        SET is_deleted = 1, deleted_by = ?, deleted_at = NOW()
        WHERE rfq_vendor_id = ? AND is_deleted = 0
    ");
    $stmt->execute([
        $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown',
        $rfq_vendor_id
    ]);

    /* Log audit trail */
    logAudit($pdo, 'rfq_vendors', $rfq_vendor_id, 'SOFT_DELETE', 
        'Vendor "' . $vendor['vendor_name'] . '" and ' . $quoteCount . ' quote(s) soft-deleted');

    $pdo->commit();
    $_SESSION['popup_success'] = htmlspecialchars($vendor['vendor_name']) . $warningMessage . ' deleted successfully.';

} catch (Throwable $e) {
    $pdo->rollBack();
    $_SESSION['popup_error'] = 'Failed to delete vendor: ' . extractDbMessage($e);
}

header("Location: /rfq/view.php?id=" . $rfq_id);
exit;
