<?php
/**
 * Petty Cash Approval Handler
 * Supports approvals from: HOD, Branch Head, Finance Officer
 */
$REQUIRE_PERMISSION = 'approve_petty_cash_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
requireCsrfToken('/petty_cash/list.php');

if ($request_id <= 0) {
    pop("Invalid petty cash request reference.", "/petty_cash/list.php");
    exit;
}

if (!in_array($action, ['approve', 'decline', 'return'])) {
    pop("Invalid action specified.", "/petty_cash/view.php?request_id=".$request_id);
    exit;
}

/* ================================
   Determine Approver Role & Authorize
================================ */
$userRole = $_SESSION['role_name'] ?? '';
$approverRole = null;

if (in_array($userRole, ['Finance Officer', 'Admin', 'SuperAdmin'], true)) {
    $approverRole = 'Finance Officer';
} else {
    pop(
        "Only Finance Officers can approve petty cash requests.",
        "/petty_cash/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Fetch Request
================================ */
$stmt = $pdo->prepare("
    SELECT r.*, b.branch_name, u.full_name as requestor_name, u.email as requestor_email
    FROM procurement_requests r
    LEFT JOIN branches b ON r.branch_id = b.branch_id
    LEFT JOIN users u ON r.created_by = u.user_id
    WHERE r.request_id = ? AND r.request_type = 'PETTY_CASH'
    LIMIT 1
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Petty cash request not found.", "/petty_cash/list.php");
    exit;
}

/* ================================
   Status Validation
================================ */
$allowedStatuses = ['SUBMITTED'];

if (!in_array(strtoupper($request['status']), $allowedStatuses, true)) {
    pop(
        "This request is not pending approval. Current status: " . $request['status'],
        "/petty_cash/view.php?request_id=".$request_id,
        2000,
        "error"
    );
    exit;
}

/* ================================
   Workflow Gate: Signed Request Upload
================================ */
if (function_exists('signedRequestUploadPending') && signedRequestUploadPending($request)) {
   pop(
       'A signed request document must be uploaded before this request can be approved. Please ask the requestor to print the form, sign it, and upload the signed copy.',
       "/petty_cash/view.php?request_id=".$request_id,
       3000,
       "warning"
   );
   exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'approve') {
        $newStatus = 'FUNDS_VERIFIED';
    } elseif ($action === 'return') {
        $newStatus = 'RETURNED_FOR_CORRECTION';
    } else {
        $newStatus = 'DECLINED';
    }
    
    // Store previous status for audit
    $previousStatus = $request['status'];

    /* ================================
       Update Request Status
    ================================ */
    $update = $pdo->prepare("
        UPDATE procurement_requests
        SET status = ?,
            updated_at = NOW()
        WHERE request_id = ?
    ");
    $update->execute([$newStatus, $request_id]);

    /* ================================
       Log Status Change to History
    ================================ */
    $historyInsert = $pdo->prepare("
        INSERT INTO petty_cash_status_history
        (request_id, old_status, new_status, changed_by, change_notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $historyNotes = $comments ?: ($action === 'approve' 
        ? "Approved by {$approverRole}" 
        : ($action === 'return' 
            ? "Returned for correction by {$approverRole}" 
            : "Declined by {$approverRole}"));
    $historyInsert->execute([
        $request_id,
        $previousStatus,
        $newStatus,
        $_SESSION['user_id'],
        $historyNotes
    ]);

    /* ================================
       Update Approval Record
    ================================ */
    // Determine approval status for request_approvals table
    // Note: request_approvals.status enum is ('pending','approved','rejected')
    // For 'return', we use 'rejected' but include 'return_for_correction' marker in comments
    $approvalStatus = ($action === 'approve') ? 'approved' : 'rejected';
    $approvalComments = $comments;
    if ($action === 'return') {
        $approvalComments = '[RETURN_FOR_CORRECTION] ' . ($comments ?: '');
    }
    
    $approvalUpdate = $pdo->prepare("
        UPDATE request_approvals
        SET status = ?,
            approved_by = ?,
            approved_at = NOW(),
            comments = ?
        WHERE request_id = ?
          AND role = ?
          AND status = 'pending'
    ");
    $approvalUpdate->execute([
        $approvalStatus,
        $_SESSION['user_id'],
        $approvalComments,
        $request_id,
        $approverRole
    ]);

    /* ================================
       If approved by Finance Officer, create disbursement record
    ================================ */
    if ($action === 'approve') {
        // Check if a disbursement record already exists
        $checkDisb = $pdo->prepare("SELECT disburse_id FROM petty_cash_disbursements WHERE request_id = ?");
        $checkDisb->execute([$request_id]);
        
        if (!$checkDisb->fetchColumn()) {
            // Set 24-hour deadline from now
            $deadline = new DateTime();
            $deadline->add(new DateInterval('PT24H'));
            
            $disbInsert = $pdo->prepare("
                INSERT INTO petty_cash_disbursements
                (request_id, amount_authorized, disbursed_by, disbursement_date, disbursement_deadline, status)
                VALUES (?, ?, ?, NOW(), ?, 'AUTHORIZED')
            ");
            $disbInsert->execute([
                $request_id,
                $request['estimated_value'],
                $_SESSION['user_id'],
                $deadline->format('Y-m-d H:i:s')
            ]);
        }
    }

    /* ================================
       Audit Log
    ================================ */
    logApprovalDecision(
        $pdo,
        $request_id,
        $_SESSION['user_id'],
        $approverRole,
        $action,
        $newStatus,
        $previousStatus,
        $comments ?: null
    );

    /* ================================
       Notify Requestor
    ================================ */
    if ($newStatus === 'DECLINED') {
        // Request declined
        notifyRequestDeclined($request_id, (int)$request['created_by'], $comments ?: 'Your petty cash request was declined.');
    } elseif ($newStatus === 'RETURNED_FOR_CORRECTION') {
        // Request returned for correction
        notifyRequestReturned($request_id, (int)$request['created_by'], $comments ?: 'Please review the feedback and correct your request.');
    } else {
        // Approved (HOD_APPROVED or FUNDS_VERIFIED)
        notifyRequestFinalized($request_id, $newStatus);
    }

    $pdo->commit();

    /* ================================
       Redirect
    ================================ */
    $message = ($action === 'approve') 
        ? "Petty cash request funds verified. Ready for disbursement."
        : "Petty cash request has been declined.";
    
    pop(
        $message,
        "/petty_cash/view.php?request_id=".$request_id,
        1500,
        ($action === 'approve') ? "success" : "warning"
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Petty cash approval error: " . $e->getMessage());
    pop(
        "Error processing approval: " . extractDbMessage($e),
        "/petty_cash/view.php?request_id=".$request_id,
        2000,
        "error"
    );
}
