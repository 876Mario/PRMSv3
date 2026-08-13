<?php
/**
 * Petty Cash Reconciliation Discrepancy Review Handler
 * Finance Officer reviews and resolves discrepancies
 * 
 * Handles transitions:
 * - RECONCILIATION_DISCREPANCY → REVIEWED (after requestor provides corrections)
 * - REVIEWED → COMPLETED (final approval)
 */

$REQUIRE_PERMISSION = 'verify_petty_cash_reconciliation';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';

/* ============================================================
   VALIDATE INPUTS
   ============================================================ */
$reconcile_id = isset($_POST['reconcile_id']) ? (int)$_POST['reconcile_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$resolution_notes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : '';

if ($reconcile_id <= 0) {
    pop("Invalid reconciliation reference.", "/petty_cash/list.php");
    exit;
}

if (!in_array($action, ['resolve', 'reopen'])) {
    pop("Invalid action specified.", "/petty_cash/list.php", 2000, "error");
    exit;
}

/* ============================================================
   VERIFY USER ROLE
   ============================================================ */
$userRole = $_SESSION['role_name'] ?? '';
if (!in_array($userRole, ['Finance Officer', 'Admin', 'SuperAdmin'])) {
    pop(
        "Only Finance Officers can review petty cash discrepancies.",
        "/petty_cash/list.php",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   FETCH RECONCILIATION & RELATED DATA
   ============================================================ */
$reconcileStmt = $pdo->prepare("
    SELECT 
        pcr.*,
        pr.request_id,
        pr.request_number,
        pr.status as request_status,
        pcd.amount_authorized
    FROM petty_cash_reconciliations pcr
    INNER JOIN petty_cash_disbursements pcd ON pcr.disburse_id = pcd.disburse_id
    INNER JOIN procurement_requests pr ON pcd.request_id = pr.request_id
    WHERE pcr.reconcile_id = ?
");
$reconcileStmt->execute([$reconcile_id]);
$reconciliation = $reconcileStmt->fetch(PDO::FETCH_ASSOC);

if (!$reconciliation) {
    pop("Reconciliation record not found.", "/petty_cash/list.php", 2000, "error");
    exit;
}

$request_id = (int)$reconciliation['request_id'];

/* ============================================================
   VALIDATE REQUEST STATUS
   ============================================================ */
$currentStatus = strtoupper($reconciliation['request_status']);
if (!in_array($currentStatus, ['RECONCILIATION_DISCREPANCY', 'REVIEWED'])) {
    pop(
        "This reconciliation is not in discrepancy review status. Current status: {$currentStatus}",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   PROCESS REVIEW
   ============================================================ */
try {
    $pdo->beginTransaction();

    if ($action === 'resolve') {
        /* ==================================================
           RESOLVE DISCREPANCY PATH 1: RECONCILIATION_DISCREPANCY → REVIEWED
           ================================================== */
        if ($currentStatus === 'RECONCILIATION_DISCREPANCY') {
            $newStatus = 'REVIEWED';
            
            // Update request status
            $updateRequest = $pdo->prepare("
                UPDATE procurement_requests
                SET status = ?,
                    updated_at = NOW()
                WHERE request_id = ?
            ");
            $updateRequest->execute([$newStatus, $request_id]);

            // Update reconciliation notes
            $updateReconcile = $pdo->prepare("
                UPDATE petty_cash_reconciliations
                SET reconciliation_notes = CONCAT(
                        COALESCE(reconciliation_notes, ''),
                        '\n\n---\nDiscrepancy Resolution (',
                        DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                        '):\n',
                        ?
                    )
                WHERE reconcile_id = ?
            ");
            $updateReconcile->execute([
                $resolution_notes !== '' ? $resolution_notes : 'Discrepancy resolved.',
                $reconcile_id
            ]);

            logAudit(
                $pdo,
                'procurement_requests',
                $request_id,
                'STATUS_CHANGE',
                "Petty Cash Request: {$currentStatus} → {$newStatus} (Discrepancy resolved by Finance Officer)"
            );

            logRequestTimeline(
                $pdo,
                $request_id,
                'RECONCILIATION_DISCREPANCY_RESOLVED',
                "Discrepancy resolved by " . ($_SESSION['full_name'] ?? 'Finance Officer') . ": " . 
                ($resolution_notes !== '' ? $resolution_notes : 'Resolved')
            );

            $successMessage = "Discrepancy marked as reviewed. Requestor can now submit corrections.";
        }
        /* ==================================================
           RESOLVE DISCREPANCY PATH 2: REVIEWED → COMPLETED
           (After requestor provided corrections)
           ================================================== */
        else {
            $newStatus = 'COMPLETED';
            
            // Update request status
            $updateRequest = $pdo->prepare("
                UPDATE procurement_requests
                SET status = ?,
                    updated_at = NOW()
                WHERE request_id = ?
            ");
            $updateRequest->execute([$newStatus, $request_id]);

            // Update reconciliation notes
            $updateReconcile = $pdo->prepare("
                UPDATE petty_cash_reconciliations
                SET verified_by = ?,
                    verification_date = NOW(),
                    status = 'APPROVED',
                    reconciliation_notes = CONCAT(
                        COALESCE(reconciliation_notes, ''),
                        '\n\n---\nFinal Approval (',
                        DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                        '):\n',
                        ?
                    )
                WHERE reconcile_id = ?
            ");
            $updateReconcile->execute([
                (int)$_SESSION['user_id'],
                $resolution_notes !== '' ? $resolution_notes : 'Corrections verified and approved.',
                $reconcile_id
            ]);

            logAudit(
                $pdo,
                'procurement_requests',
                $request_id,
                'STATUS_CHANGE',
                "Petty Cash Request: {$currentStatus} → {$newStatus} (Discrepancy corrections approved by Finance Officer)"
            );

            logRequestTimeline(
                $pdo,
                $request_id,
                'RECONCILIATION_COMPLETED',
                "Petty cash reconciliation completed by " . ($_SESSION['full_name'] ?? 'Finance Officer') . ": " . 
                ($resolution_notes !== '' ? $resolution_notes : 'Approved')
            );

            $successMessage = "Reconciliation discrepancy resolved and request completed.";
        }

        $messageType = 'success';

    } else {
        /* ==================================================
           REOPEN DISCREPANCY: Return to requestor for more corrections
           ================================================== */
        if ($currentStatus !== 'REVIEWED') {
            throw new Exception("Only reviewed discrepancies can be reopened.");
        }

        $reopenStatus = 'RECONCILIATION_DISCREPANCY';

        // Update request status back to RECONCILIATION_DISCREPANCY
        $updateRequest = $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                updated_at = NOW()
            WHERE request_id = ?
        ");
        $updateRequest->execute([$reopenStatus, $request_id]);

        // Update reconciliation notes
        $updateReconcile = $pdo->prepare("
            UPDATE petty_cash_reconciliations
            SET reconciliation_notes = CONCAT(
                    COALESCE(reconciliation_notes, ''),
                    '\n\n---\nDiscrepancy Reopened (',
                    DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                    '):\n',
                    ?
                )
            WHERE reconcile_id = ?
        ");
        $updateReconcile->execute([
            $resolution_notes !== '' ? $resolution_notes : 'Additional corrections required.',
            $reconcile_id
        ]);

        logAudit(
            $pdo,
            'procurement_requests',
            $request_id,
            'STATUS_CHANGE',
            "Petty Cash Request: {$currentStatus} → {$reopenStatus} (Reopened for additional corrections)"
        );

        logRequestTimeline(
            $pdo,
            $request_id,
            'RECONCILIATION_DISCREPANCY_REOPENED',
            "Discrepancy review reopened by " . ($_SESSION['full_name'] ?? 'Finance Officer') . ": " . 
            ($resolution_notes !== '' ? $resolution_notes : 'Additional corrections required')
        );

        $successMessage = "Discrepancy review reopened. Requestor notified to provide additional corrections.";
        $messageType = 'warning';
    }

    $pdo->commit();

    pop(
        $successMessage,
        "/petty_cash/view.php?request_id={$request_id}",
        1500,
        $messageType
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Petty cash discrepancy review error: " . $e->getMessage());
    pop(
        "Error processing review: " . extractDbMessage($e),
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
}
?>
