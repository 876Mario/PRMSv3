<?php
/**
 * Reimbursement Approval Handler
 * Supports approvals from: HOD, Branch Head, Finance Officer
 */
$REQUIRE_PERMISSION = 'approve_reimbursement_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
requireCsrfToken('/reimbursement/list.php');

if ($request_id <= 0) {
    pop("Invalid reimbursement request reference.", "/reimbursement/list.php");
    exit;
}

if (!in_array($action, ['approve', 'decline', 'return'])) {
    pop("Invalid action specified.", "/reimbursement/view.php?request_id=".$request_id);
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
        "Only Finance Officers can approve reimbursement requests.",
        "/reimbursement/view.php?request_id=".$request_id,
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
    WHERE r.request_id = ? AND r.request_type = 'REIMBURSEMENT'
    LIMIT 1
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop("Reimbursement request not found.", "/reimbursement/list.php");
    exit;
}

/* ================================
   Status Validation
================================ */
$allowedStatuses = ['SUBMITTED', 'FUNDS_VERIFIED', 'INVOICE_VERIFIED'];

if (!in_array(strtoupper($request['status']), $allowedStatuses, true)) {
    pop(
        "This request is not pending approval. Current status: " . $request['status'],
        "/reimbursement/view.php?request_id=".$request_id,
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
       "/reimbursement/view.php?request_id=".$request_id,
       3000,
       "warning"
   );
   exit;
}

try {
    $pdo->beginTransaction();
    $isInvoiceBypassApproval = false;

    if ($action === 'approve') {
        if (strtoupper($request['status']) === 'SUBMITTED') {
            $newStatus = 'FUNDS_VERIFIED';
        } elseif (strtoupper($request['status']) === 'FUNDS_VERIFIED') {
            if (!has_permission('approve_reimbursement_without_invoice_verification')) {
                throw new Exception('Unauthorized: invoice-bypass approval requires additional permission.');
            }
            if (mb_strlen($comments) < 5) {
                throw new Exception('An invoice-bypass reason of at least 5 characters is required.');
            }
            $newStatus = 'APPROVED';
            $isInvoiceBypassApproval = true;
        } else {
            $newStatus = 'APPROVED';
        }
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
       Record Status History
    ================================ */
    $historyStmt = $pdo->prepare("
        INSERT INTO reimbursement_status_history
        (request_id, old_status, new_status, changed_by, change_notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $historyStmt->execute([
        $request_id,
        $request['status'],
        $newStatus,
        $_SESSION['user_id'],
        $comments ?: (($action === 'approve') ? 'Approved by ' . $approverRole : 'Declined by ' . $approverRole)
    ]);

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

    if ($isInvoiceBypassApproval) {
        $bypassNote = 'Reimbursement invoice verification bypass approved by ' . $approverRole . '. Reason: ' . $comments;
        logAudit($pdo, 'procurement_requests', $request_id, 'REIMBURSEMENT_INVOICE_BYPASS_APPROVAL', $bypassNote);
        logRequestTimeline($pdo, $request_id, 'REIMBURSEMENT_INVOICE_BYPASS_APPROVAL', $bypassNote);
    }

    /* ================================
       Notify Requestor
    ================================ */
    if ($newStatus === 'DECLINED') {
        // Request declined
        notifyRequestDeclined($request_id, (int)$request['created_by'], $comments ?: 'Your reimbursement request was declined.');
    } elseif ($newStatus === 'RETURNED_FOR_CORRECTION') {
        // Request returned for correction
        notifyRequestReturned($request_id, (int)$request['created_by'], $comments ?: 'Please review the feedback and correct your request.');
    } else {
        // Approved (HOD_APPROVED, FUNDS_VERIFIED, or final APPROVED)
        notifyRequestFinalized($request_id, $newStatus);
    }

    $pdo->commit();

    /* ================================
       Redirect
    ================================ */
    if ($action === 'approve') {
        $message = ($newStatus === 'APPROVED')
            ? "Reimbursement request approved successfully. It is now ready for payment processing."
            : "Reimbursement request funds verified and approved successfully.";
    } else {
        $message = "Reimbursement request has been declined.";
    }

    pop(
        $message,
        "/reimbursement/view.php?request_id=".$request_id,
        1500,
        ($action === 'approve') ? "success" : "warning"
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Reimbursement approval error: " . $e->getMessage());
    pop(
        "Error processing approval: " . extractDbMessage($e),
        "/reimbursement/view.php?request_id=".$request_id,
        2000,
        "error"
    );
}
