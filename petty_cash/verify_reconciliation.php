<?php
/**
 * Petty Cash Reconciliation Verification Handler
 * Finance Officer verifies/approves reconciliation submissions
 * 
 * Handles transitions:
 * - PENDING_RECONCILIATION → PROCUREMENT_VERIFIED (approval)
 * - PENDING_RECONCILIATION → RECONCILIATION_DISCREPANCY (rejection with discrepancy reason)
 */

$REQUIRE_PERMISSION = 'verify_petty_cash_reconciliation';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

/* ============================================================
   VALIDATE INPUTS
   ============================================================ */
$reconcile_id = isset($_POST['reconcile_id']) ? (int)$_POST['reconcile_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$verification_notes = isset($_POST['verification_notes']) ? trim($_POST['verification_notes']) : '';
$discrepancy_amount = isset($_POST['discrepancy_amount']) ? (float)$_POST['discrepancy_amount'] : 0.0;
$required_action = isset($_POST['required_action']) ? trim($_POST['required_action']) : '';

if ($reconcile_id <= 0) {
    pop("Invalid reconciliation reference.", "/petty_cash/list.php");
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    pop("Invalid action specified.", "/petty_cash/list.php", 2000, "error");
    exit;
}

/* ============================================================
   VERIFY USER ROLE
   ============================================================ */
$userRole = $_SESSION['role_name'] ?? '';
if (!in_array($userRole, ['Finance Officer', 'Admin', 'SuperAdmin'])) {
    pop(
        "Only Finance Officers can verify petty cash reconciliations.",
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
        pr.estimated_value,
        pr.currency,
        pcd.amount_authorized,
        pcd.disbursement_date,
        pcd.disbursement_deadline,
        u.full_name as submitted_by_name,
        u.email as submitted_by_email,
        req.full_name as requestor_name,
        req.email as requestor_email
    FROM petty_cash_reconciliations pcr
    INNER JOIN petty_cash_disbursements pcd ON pcr.disburse_id = pcd.disburse_id
    INNER JOIN procurement_requests pr ON pcd.request_id = pr.request_id
    LEFT JOIN users u ON pcr.submitted_by = u.user_id
    LEFT JOIN users req ON pr.created_by = req.user_id
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
if ($currentStatus !== 'PENDING_RECONCILIATION') {
    pop(
        "This reconciliation cannot be verified. Current request status: {$currentStatus}",
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
    exit;
}

/* ============================================================
   PROCESS VERIFICATION
   ============================================================ */
try {
    $pdo->beginTransaction();

    if ($action === 'approve') {
        /* ==================================================
           APPROVAL PATH: PENDING_RECONCILIATION → PROCUREMENT_VERIFIED
           ================================================== */
        
        // Update request status to PROCUREMENT_VERIFIED
        $previousStatus = $request['status'];
        $newStatus = 'PROCUREMENT_VERIFIED';
        $updateRequest = $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                updated_at = NOW()
            WHERE request_id = ?
        ");
        $updateRequest->execute([$newStatus, $request_id]);

        /* ================================
           Log Status Change to History
        ================================ */
        $historyInsert = $pdo->prepare("
            INSERT INTO petty_cash_status_history
            (request_id, old_status, new_status, changed_by, change_notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $historyNotes = 'Reconciliation verified by Finance Officer';
        if ($verification_notes) {
            $historyNotes .= ': ' . $verification_notes;
        }
        $historyInsert->execute([
            $request_id,
            $previousStatus,
            $newStatus,
            $_SESSION['user_id'],
            $historyNotes
        ]);

        // Update reconciliation with verification details
        $updateReconcile = $pdo->prepare("
            UPDATE petty_cash_reconciliations
            SET verified_by = ?,
                verification_date = NOW(),
                status = 'VERIFIED',
                reconciliation_notes = CONCAT(
                    COALESCE(reconciliation_notes, ''),
                    IF(reconciliation_notes IS NOT NULL, '\n\n', ''),
                    'Finance Verification (',
                    DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                    '): ',
                    ?
                )
            WHERE reconcile_id = ?
        ");
        $updateReconcile->execute([
            (int)$_SESSION['user_id'],
            $verification_notes !== '' ? $verification_notes : 'Verified and approved.',
            $reconcile_id
        ]);

        // Record verification in new verification table (if it exists)
        $checkVerifTable = $pdo->prepare("
            SELECT 1 FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'petty_cash_reconciliation_verifications'
        ");
        $checkVerifTable->execute();
        
        if ($checkVerifTable->fetchColumn()) {
            $insertVerif = $pdo->prepare("
                INSERT INTO petty_cash_reconciliation_verifications
                (reconcile_id, verified_by, verification_status, verification_notes)
                VALUES (?, ?, 'APPROVED', ?)
            ");
            $insertVerif->execute([
                $reconcile_id,
                (int)$_SESSION['user_id'],
                $verification_notes !== '' ? $verification_notes : 'Verified and approved.'
            ]);
        }

        // Audit logging
        logAudit(
            $pdo,
            'petty_cash_reconciliations',
            $reconcile_id,
            'VERIFICATION',
            "Reconciliation verified and approved by {$userRole}"
        );

        logAudit(
            $pdo,
            'procurement_requests',
            $request_id,
            'STATUS_CHANGE',
            "Petty Cash Request: {$currentStatus} → {$newStatus} (Reconciliation verified by Finance Officer)"
        );

        logRequestTimeline(
            $pdo,
            $request_id,
            'RECONCILIATION_VERIFIED',
            "Reconciliation verified and approved by " . ($_SESSION['full_name'] ?? 'Finance Officer')
        );

        $successMessage = "Reconciliation verified and approved successfully.";
        $messageType = 'success';

    } else {
        /* ==================================================
           REJECTION PATH: PENDING_RECONCILIATION → RECONCILIATION_DISCREPANCY
           ================================================== */
        
        if ($verification_notes === '') {
            throw new Exception("Discrepancy reason is required when rejecting a reconciliation.");
        }

        // Update request status to RECONCILIATION_DISCREPANCY
        $previousStatus = $currentStatus;
        $newStatus = 'RECONCILIATION_DISCREPANCY';
        $updateRequest = $pdo->prepare("
            UPDATE procurement_requests
            SET status = ?,
                updated_at = NOW()
            WHERE request_id = ?
        ");
        $updateRequest->execute([$newStatus, $request_id]);

        /* ================================
           Log Status Change to History
        ================================ */
        $historyInsert = $pdo->prepare("
            INSERT INTO petty_cash_status_history
            (request_id, old_status, new_status, changed_by, change_notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $historyNotes = 'Discrepancy reported by Finance Officer: ' . $verification_notes;
        $historyInsert->execute([
            $request_id,
            $previousStatus,
            $newStatus,
            $_SESSION['user_id'],
            $historyNotes
        ]);

        // Update reconciliation with verification details
        $updateReconcile = $pdo->prepare("
            UPDATE petty_cash_reconciliations
            SET verified_by = ?,
                verification_date = NOW(),
                status = 'DISCREPANCY',
                reconciliation_notes = CONCAT(
                    COALESCE(reconciliation_notes, ''),
                    IF(reconciliation_notes IS NOT NULL, '\n\n', ''),
                    'DISCREPANCY FOUND (',
                    DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i'),
                    '): ',
                    ?
                )
            WHERE reconcile_id = ?
        ");
        $updateReconcile->execute([
            (int)$_SESSION['user_id'],
            $verification_notes,
            $reconcile_id
        ]);

        // Record verification in new verification table (if it exists)
        $checkVerifTable = $pdo->prepare("
            SELECT 1 FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'petty_cash_reconciliation_verifications'
        ");
        $checkVerifTable->execute();
        
        if ($checkVerifTable->fetchColumn()) {
            $insertVerif = $pdo->prepare("
                INSERT INTO petty_cash_reconciliation_verifications
                (reconcile_id, verified_by, verification_status, verification_notes, 
                 discrepancy_amount, required_action)
                VALUES (?, ?, 'REJECTED_DISCREPANCY', ?, ?, ?)
            ");
            $insertVerif->execute([
                $reconcile_id,
                (int)$_SESSION['user_id'],
                $verification_notes,
                $discrepancy_amount > 0 ? $discrepancy_amount : null,
                $required_action !== '' ? $required_action : null
            ]);
        }

        // Audit logging
        logAudit(
            $pdo,
            'petty_cash_reconciliations',
            $reconcile_id,
            'VERIFICATION',
            "Discrepancy found in reconciliation by {$userRole}: " . substr($verification_notes, 0, 100)
        );

        logAudit(
            $pdo,
            'procurement_requests',
            $request_id,
            'STATUS_CHANGE',
            "Petty Cash Request: {$currentStatus} → {$newStatus} (Discrepancy found by Finance Officer)"
        );

        logRequestTimeline(
            $pdo,
            $request_id,
            'RECONCILIATION_DISCREPANCY',
            "Discrepancy found in reconciliation by " . ($_SESSION['full_name'] ?? 'Finance Officer') . ": " . substr($verification_notes, 0, 100)
        );

        $successMessage = "Reconciliation marked with discrepancy. Requestor has been notified.";
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
    error_log("Petty cash reconciliation verification error: " . $e->getMessage());
    pop(
        "Error processing verification: " . extractDbMessage($e),
        "/petty_cash/view.php?request_id={$request_id}",
        2000,
        "error"
    );
}
?>
