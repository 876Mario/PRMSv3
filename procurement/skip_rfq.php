<?php
/**
 * Proceed Without RFQ (Under-Threshold Requests)
 * ==============================================
 * RFQ is mandatory for requests over the direct procurement threshold
 * (JMD $500,000 by default) and OPTIONAL for requests at or below it.
 * This action lets Procurement proceed without an RFQ for eligible
 * under-threshold requests, moving them directly to the award/commitment path.
 */
$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    pop('Invalid request ID', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

// Only Procurement (or admins) may choose to skip the RFQ
$allowedRoles = ['Procurement Officer', 'Admin', 'SuperAdmin'];
if (!in_array($_SESSION['role_name'] ?? '', $allowedRoles, true)) {
    pop('Only Procurement Officers can proceed without an RFQ.', '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch request */
$stmt = $pdo->prepare("
    SELECT pr.*
    FROM procurement_requests pr
    WHERE pr.request_id = ?
");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Request not found', '/procurement/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Must be in an RFQ-eligible, post-approval stage */
$eligibleStatuses = ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'FUNDS_VERIFIED', 'RFQ_LETTER_AVAILABLE'];
if (!in_array(strtoupper($request['status']), $eligibleStatuses, true)) {
    pop('This request is not at a stage where the RFQ can be skipped.', '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* RFQ is mandatory above the threshold — convert value to JMD for comparison */
$threshold = getDirectProcurementThreshold($pdo);
$estimatedValue = (float)($request['estimated_value'] ?? 0);
$jmdValue = $estimatedValue;
if (($request['currency'] ?? 'JMD') === 'USD') {
    $usdRate = (float)($request['usd_rate'] ?? 0);
    if ($usdRate <= 0) {
        $rateStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'usd_to_jmd_rate'");
        $rateStmt->execute();
        $usdRate = (float)($rateStmt->fetchColumn() ?: 155.00);
    }
    $jmdValue = $estimatedValue * $usdRate;
}

if ($jmdValue > $threshold) {
    pop(
        'RFQ is mandatory for requests over JMD $' . number_format($threshold, 2) . '. This request cannot proceed without an RFQ.',
        '/procurement/view.php?id=' . $id,
        POP_DEFAULT_DELAY_MS,
        'error'
    );
    exit;
}

/* An RFQ must not already exist */
$rfqStmt = $pdo->prepare("SELECT COUNT(*) FROM rfqs WHERE request_id = ?");
$rfqStmt->execute([$id]);
if ((int)$rfqStmt->fetchColumn() > 0) {
    pop('An RFQ already exists for this request.', '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'warning');
    exit;
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE procurement_requests
        SET status = 'AWARDED',
            requires_rfq = 0
        WHERE request_id = ?
    ")->execute([$id]);

    logAudit(
        $pdo,
        'procurement_requests',
        $id,
        'STATUS_CHANGE',
        $request['status'] . ' → Awarded (RFQ path intentionally bypassed — "Proceed Without RFQ" selected, under JMD threshold) by ' . ($_SESSION['full_name'] ?? 'Unknown')
    );

    logRequestTimeline(
        $pdo,
        $id,
        'RFQ_SKIPPED',
        'RFQ path intentionally bypassed via "Proceed Without RFQ" (optional for requests at or below JMD $' . number_format($threshold, 2) . '). RFQ, quote review, quote selection and funds verification stages are skipped; proceeding directly to commitment.'
    );

    $pdo->commit();

    pop('Proceeding without RFQ. Next steps: ' . getAwardedWorkflowGuidance(), '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'success');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pop('Error skipping RFQ: ' . extractDbMessage($e), '/procurement/view.php?id=' . $id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}