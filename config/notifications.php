<?php
/**
 * Notification System
 * Send emails at key workflow stages with admin ability to disable
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app.php';

/**
 * HTML-escape a value for safe insertion into email templates.
 */
function he($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Get application URL for email links
 */
function getAppUrl(): string {
    if (defined('APP_URL')) {
        return APP_URL;
    }
    return isset($_SERVER['REQUEST_SCHEME']) && isset($_SERVER['HTTP_HOST']) 
        ? $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] 
        : 'http://localhost';
}

/**
 * Check if notifications are enabled globally
 */
function notificationsEnabled(): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute(['enable_notifications']);
        $value = $stmt->fetchColumn();
        return $value !== false ? (bool)(int)$value : true; // Default: enabled
    } catch (Exception $e) {
        error_log("Notification check error: {$e->getMessage()}");
        return true;
    }
}

/**
 * Get user email by ID
 */
function getUserEmail(int $userId): ?string {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Get user email error: {$e->getMessage()}");
        return null;
    }
}

/**
 * Get approver email based on branch and approval stage
 * Uses the branch-based approval rules from workflow.php
 */
function getApproverEmailForBranch(int $branchId, float $estimatedValue, string $requestType): ?string {
    global $pdo;
    try {
        require_once __DIR__ . '/workflow.php';
        
        // Get the approval chain for this branch/amount/type
        $approvalRoles = getApprovalChain($requestType, $estimatedValue, $branchId);
        if (empty($approvalRoles)) return null;
        
        // Get the first approver role
        $firstRole = $approvalRoles[0];
        
        // Find a user with that role
        $stmt = $pdo->prepare("
            SELECT u.email
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$firstRole]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Get approver email for branch error: {$e->getMessage()}");
        return null;
    }
}

/**
 * Get branch head (HOD) by branch ID - DEPRECATED
 * This function is no longer used as users don't have branch_id
 */
function getBranchHeadEmail(int $branchId): ?string {
    global $pdo;
    try {
        // Find HOD for this branch - look for HOD role generally
        $stmt = $pdo->prepare("
            SELECT u.email
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'HOD'
            AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute();
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Get branch head error: {$e->getMessage()}");
        return null;
    }
}

/**
 * Get users with a specific role for notification sending
 */
function getUsersByRole(string $roleName): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id, u.email, u.full_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = ? AND u.is_active = 1
        ");
        $stmt->execute([$roleName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get users by role error: {$e->getMessage()}");
        return [];
    }
}

/**
 * Request submitted notification - send to first approver in the chain
 */
function notifyRequestSubmitted(int $requestId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.request_date, pr.estimated_value, pr.request_type, 
                   pr.branch_id, pr.created_by, b.branch_name,
                   u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) return false;

        // Get first approver email based on branch and approval rules
        $approverEmail = getApproverEmailForBranch(
            (int)$request['branch_id'],
            (float)$request['estimated_value'],
            $request['request_type']
        );
        if (!$approverEmail) return false;

        $requestor = $request['full_name'] ?? 'Requestor';
        $subject = "New Procurement Request Pending Approval - {$request['request_number']}";
        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);

        // HTML-safe variables
        $safeRequestor = he($requestor);
        $safeRequestNumber = he($request['request_number']);
        $safeBranchName = he($request['branch_name']);
        $safeRequestType = he($request['request_type']);
        $safeRequestDate = he($request['request_date']);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
        .content { padding: 20px; }
        .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">New Procurement Request Awaiting Approval</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <p>Dear Approver,</p>
            <p>A new {$safeRequestType} procurement request has been submitted by {$safeRequestor} and requires your immediate approval.</p>
            
            <div class="details">
                <div class="detail-row">
                    <span class="label">Request Number:</span> {$safeRequestNumber}
                </div>
                <div class="detail-row">
                    <span class="label">Requestor:</span> {$safeRequestor}
                </div>
                <div class="detail-row">
                    <span class="label">Branch:</span> {$safeBranchName}
                </div>
                <div class="detail-row">
                    <span class="label">Request Type:</span> {$safeRequestType}
                </div>
                <div class="detail-row">
                    <span class="label">Estimated Value:</span> \${$estimatedValue}
                </div>
                <div class="detail-row">
                    <span class="label">Request Date:</span> {$safeRequestDate}
                </div>
            </div>
            
            <p>
                <a href="{$appUrl}/procurement/approve.php?id={$requestId}" class="button">
                    Review &amp; Approve Request
                </a>
            </p>
            
            <p style="margin-top: 20px; font-size: 12px; color: #777;">
                This is an automated notification from the Procurement Request Management System. 
                Please do not reply to this email.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        return sendMail($approverEmail, $subject, $html);

    } catch (Exception $e) {
        error_log("Notify request submitted error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify all Finance Officers about a new reimbursement or petty cash request
 * These requests bypass HOD/Procurement and go directly to Finance for fund verification
 */
function notifyFinanceForDirectApproval(int $requestId, string $requestType): bool {
    if (!notificationsEnabled()) {
        error_log("NOTIFY: Notifications disabled globally");
        return false;
    }

    global $pdo;
    try {
        error_log("NOTIFY FINANCE: Starting finance notification for {$requestType} request $requestId");
        
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.description, pr.request_type,
                   b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            error_log("NOTIFY FINANCE: Request not found for ID $requestId");
            return false;
        }

        // Get all Finance Officers
        $financeUsers = getUsersByRole('Finance Officer');
        if (empty($financeUsers)) {
            error_log("NOTIFY FINANCE: No Finance Officers found in the system");
            return false;
        }

        $typeDisplay = ($requestType === 'PETTY_CASH') ? 'Petty Cash' : 'Reimbursement';
        $typeEmoji = ($requestType === 'PETTY_CASH') ? '💰' : '💵';
        $subject = "Action Required: {$typeDisplay} Request - {$request['request_number']}";
        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);
        $viewUrl = ($requestType === 'PETTY_CASH') 
            ? "{$appUrl}/petty_cash/view.php?request_id={$requestId}"
            : "{$appUrl}/reimbursement/view.php?request_id={$requestId}";

        // HTML-safe variables
        $safeRequestNumber = he($request['request_number']);
        $safeRequestorName = he($request['requestor_name']);
        $safeBranchName = he($request['branch_name']);
        $safeDescription = he($request['description']);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
        .content { padding: 20px; }
        .alert { background: #cce5ff; border-left: 4px solid #0056b3; padding: 12px; margin: 15px 0; border-radius: 4px; }
        .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">{$typeEmoji} {$typeDisplay} Request - Fund Verification Needed</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <p>Dear Finance Officer,</p>
            
            <div class="alert">
                <strong>&#x1F4B0; Fund Verification Required:</strong> A new {$typeDisplay} request has been submitted and requires your fund verification.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="label">Request Number:</span> {$safeRequestNumber}
                </div>
                <div class="detail-row">
                    <span class="label">Request Type:</span> {$typeDisplay}
                </div>
                <div class="detail-row">
                    <span class="label">Requestor:</span> {$safeRequestorName}
                </div>
                <div class="detail-row">
                    <span class="label">Branch:</span> {$safeBranchName}
                </div>
                <div class="detail-row">
                    <span class="label">Amount:</span> \${$estimatedValue}
                </div>
                <div class="detail-row">
                    <span class="label">Description:</span> {$safeDescription}
                </div>
            </div>
            
            <p>Please verify fund availability and process this request.</p>
            
            <p>
                <a href="{$viewUrl}" class="button">
                    Review &amp; Verify Funds
                </a>
            </p>
            
            <p style="margin-top: 20px; font-size: 12px; color: #777;">
                This is an automated notification from the Procurement Request Management System.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        // Send to all Finance Officers
        $successCount = 0;
        foreach ($financeUsers as $finance) {
            if (!empty($finance['email'])) {
                error_log("NOTIFY FINANCE: Sending to {$finance['email']}");
                if (sendMail($finance['email'], $subject, $html)) {
                    $successCount++;
                }
            }
        }

        error_log("NOTIFY FINANCE: Sent to {$successCount} finance officers");
        return $successCount > 0;

    } catch (Exception $e) {
        error_log("Notify finance for direct approval ERROR: {$e->getMessage()}");
        return false;
    }
}

/**
 * Approval needed notification - send to approver
 */
function notifyApprovalNeeded(int $requestId, string $stage, int $approverId): bool {
    if (!notificationsEnabled()) {
        error_log("NOTIFY: Notifications disabled globally");
        return false;
    }

    global $pdo;
    try {
        error_log("NOTIFY: Starting approval notification for request $requestId to approver $approverId at stage $stage");
        
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.request_type,
                   b.branch_name, u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            error_log("NOTIFY: Request not found for ID $requestId");
            return false;
        }

        // Get approver email
        $approverEmail = getUserEmail($approverId);
        if (!$approverEmail) {
            error_log("NOTIFY: No email found for approver user ID $approverId");
            return false;
        }

        error_log("NOTIFY: Found approver email: $approverEmail");

        // Get approver name
        $stmt2 = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $stmt2->execute([$approverId]);
        $approver = $stmt2->fetch(PDO::FETCH_ASSOC);
        $approverName = $approver['full_name'] ?? 'Approver';

        $stageLabel = str_replace('_', ' ', ucwords(strtolower(str_replace('_APPROVED', '', $stage))));
        $subject = "Action Required: Approve Request {$request['request_number']}";
        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);

        // HTML-safe variables
        $safeApproverName = he($approverName);
        $safeStageLabel = he($stageLabel);
        $safeRequestNumber = he($request['request_number']);
        $safeRequestType = he($request['request_type']);
        $safeBranchName = he($request['branch_name']);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
        .content { padding: 20px; }
        .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; border-radius: 4px; }
        .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Action Required - Request Approval</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <p>Dear {$safeApproverName},</p>
            
            <div class="alert">
                <strong>&#9888; Action Needed:</strong> A procurement request is pending your {$safeStageLabel} approval.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="label">Request Number:</span> {$safeRequestNumber}
                </div>
                <div class="detail-row">
                    <span class="label">Request Type:</span> {$safeRequestType}
                </div>
                <div class="detail-row">
                    <span class="label">Branch:</span> {$safeBranchName}
                </div>
                <div class="detail-row">
                    <span class="label">Estimated Value:</span> \${$estimatedValue}
                </div>
                <div class="detail-row">
                    <span class="label">Approval Stage:</span> {$safeStageLabel}
                </div>
            </div>
            
            <p>Please review and take action on this request at your earliest convenience.</p>
            
            <p>
                <a href="{$appUrl}/procurement/approve.php?id={$requestId}" class="button">
                    Review &amp; Approve Request
                </a>
            </p>
            
            <p style="margin-top: 20px; font-size: 12px; color: #777;">
                This is an automated notification from the Procurement Request Management System.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        error_log("NOTIFY: Sending email to $approverEmail with subject: $subject");
        $result = sendMail($approverEmail, $subject, $html);
        error_log("NOTIFY: Email send result: " . ($result ? "SUCCESS" : "FAILED"));
        return $result;

    } catch (Exception $e) {
        error_log("Notify approval needed ERROR: {$e->getMessage()}");
        return false;
    }
}

/**
 * Request finalized notification - send to requestor
 */
function notifyRequestFinalized(int $requestId, string $finalStatus): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.created_by, pr.estimated_value, pr.request_type,
                   b.branch_name, u.email, u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request || !$request['email']) return false;

        $statusLabel = str_replace('_', ' ', ucfirst(strtolower($finalStatus)));
        $statusColor = in_array($finalStatus, ['AWARDED', 'COMPLETED', 'GC_APPROVED', 'RFQ_LETTER_AVAILABLE', 'PROCUREMENT_STAGE', 'HOD_APPROVED', 'DIRECTOR_APPROVED', 'FUNDS_VERIFIED']) ? '#198754' : '#dc3545';
        
        $subject = "Procurement Request Status Update - {$request['request_number']}";
        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);
        $requestorName = $request['full_name'] ?? 'Requestor';

        // HTML-safe variables
        $safeRequestorName = he($requestorName);
        $safeStatusLabel = he($statusLabel);
        $safeRequestNumber = he($request['request_number']);
        $safeRequestType = he($request['request_type']);
        $safeBranchName = he($request['branch_name']);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
        .content { padding: 20px; }
        .status-box { background: {$statusColor}; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
        .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .detail-row { margin: 8px 0; }
        .label { font-weight: bold; color: #555; }
        .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
        .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Request Status Update</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <p>Dear {$safeRequestorName},</p>
            <p>Your procurement request has been updated. Here are the details:</p>
            
            <div class="status-box">{$safeStatusLabel}</div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="label">Request Number:</span> {$safeRequestNumber}
                </div>
                <div class="detail-row">
                    <span class="label">Request Type:</span> {$safeRequestType}
                </div>
                <div class="detail-row">
                    <span class="label">Branch:</span> {$safeBranchName}
                </div>
                <div class="detail-row">
                    <span class="label">Estimated Value:</span> \${$estimatedValue}
                </div>
                <div class="detail-row">
                    <span class="label">Current Status:</span> <strong style="color: {$statusColor};">{$safeStatusLabel}</strong>
                </div>
            </div>
            
            <p>
                <a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">
                    View Request Details
                </a>
            </p>
            
            <p style="margin-top: 20px; font-size: 12px; color: #777;">
                This is an automated notification from the Procurement Request Management System.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        return sendMail($request['email'], $subject, $html);

    } catch (Exception $e) {
        error_log("Notify request finalized error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify next approver in chain after a stage approval
 * Called after each approval stage to alert the next person
 */
function notifyNextApprover(int $requestId, string $completedStage): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        // Find the next pending approval for this request
        $stmt = $pdo->prepare("
            SELECT ra.role, ra.stage_order
            FROM request_approvals ra
            WHERE ra.request_id = ?
              AND ra.status = 'pending'
            ORDER BY ra.stage_order ASC
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        $nextApproval = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$nextApproval) return false; // No more approvals pending

        // Find users with that role
        $users = getUsersByRole($nextApproval['role']);
        if (empty($users)) return false;

        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.request_type,
                   b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);
        $stageLabel = str_replace('_', ' ', ucwords(strtolower($nextApproval['role'])));

        // HTML-safe variables
        $safeCompletedStage = he($completedStage);
        $safeStageLabel = he($stageLabel);
        $safeRequestNumber = he($request['request_number']);
        $safeRequestorName = he($request['requestor_name']);
        $safeBranchName = he($request['branch_name']);
        $safeRequestType = he($request['request_type']);

        $subject = "Action Required: Approve Request {$request['request_number']} - {$stageLabel} Stage";
        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; border-radius: 4px; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Approval Stage Escalated</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <div class="alert"><strong>&#9888; Action Required:</strong> The previous stage ({$safeCompletedStage}) is complete. Your approval is now needed.</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$safeRequestNumber}</div>
            <div class="detail-row"><span class="label">Requestor:</span> {$safeRequestorName}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$safeBranchName}</div>
            <div class="detail-row"><span class="label">Type:</span> {$safeRequestType}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> \${$estimatedValue}</div>
            <div class="detail-row"><span class="label">Your Approval Stage:</span> {$safeStageLabel}</div>
        </div>
        <p><a href="{$appUrl}/procurement/approve.php?id={$requestId}" class="button">Review &amp; Approve</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from the Procurement Request Management System.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        $sent = false;
        foreach ($users as $user) {
            if (!empty($user['email'])) {
                $sent = sendMail($user['email'], $subject, $html) || $sent;
            }
        }
        return $sent;
    } catch (Exception $e) {
        error_log("Notify next approver error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about commitment lifecycle events (created, approved, declined)
 */
function notifyCommitmentAction(int $requestId, string $commitmentNumber, string $action, string $details = ''): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.estimated_value,
                   b.branch_name, u.email, u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;

        $actionLabel = match($action) {
            'CREATED' => 'Commitment Created',
            'APPROVED' => 'Commitment Approved',
            'DECLINED' => 'Commitment Declined',
            'STAGE_APPROVED' => 'Commitment Stage Approved',
            default => 'Commitment Update'
        };
        $actionColor = in_array($action, ['APPROVED', 'CREATED', 'STAGE_APPROVED']) ? '#198754' : '#dc3545';

        $appUrl = getAppUrl();
        $estimatedValue = number_format($request['estimated_value'], 2);
        $subject = "Commitment {$actionLabel} - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: {$actionColor}; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">{$actionLabel}</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <div class="status-box">{$actionLabel}</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Commitment Number:</span> {$commitmentNumber}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> \${$estimatedValue}</div>
        </div>
        <p>{$details}</p>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        // Also notify Finance Officer for commitment creation/approval events
        $sent = sendMail($request['email'], $subject, $html);
        if ($action === 'CREATED') {
            $financeUsers = getUsersByRole('Finance Officer');
            foreach ($financeUsers as $fu) {
                if (!empty($fu['email'])) sendMail($fu['email'], $subject, $html);
            }
        }
        return $sent;
    } catch (Exception $e) {
        error_log("Notify commitment action error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers when Finance uploads a commitment
 */
function notifyProcurementOfCommitment(int $requestId, string $commitmentNumber): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency,
                   b.branch_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) return false;

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $subject = "Commitment Uploaded - {$request['request_number']} Ready for PO";

        foreach ($procurementUsers as $pu) {
            if (empty($pu['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Commitment Uploaded - Ready for PO</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$pu['full_name']},</p>
        <div class="status-box">Commitment Ready for PO Creation</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Commitment Number:</span> {$commitmentNumber}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> {$estimatedValue}</div>
        </div>
        <p>Finance has verified funds and uploaded the commitment document. This request is now ready for Purchase Order creation.</p>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request & Create PO</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            sendMail($pu['email'], $subject, $html);
        }
        return true;
    } catch (Exception $e) {
        error_log("Notify procurement of commitment error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about PO lifecycle events (created, approved, rejected)
 */
function notifyPOAction(int $requestId, string $poNumber, string $action, string $details = ''): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.estimated_value,
                   b.branch_name, u.email, u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;

        $actionLabel = match($action) {
            'CREATED' => 'Purchase Order Created',
            'APPROVED' => 'Purchase Order Fully Approved',
            'REJECTED' => 'Purchase Order Rejected',
            'STAGE_APPROVED' => 'PO Approval Stage Complete',
            default => 'Purchase Order Update'
        };
        $actionColor = in_array($action, ['APPROVED', 'CREATED', 'STAGE_APPROVED']) ? '#198754' : '#dc3545';

        $appUrl = getAppUrl();
        $subject = "PO {$actionLabel} - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: {$actionColor}; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">{$actionLabel}</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <div class="status-box">{$actionLabel}</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">PO Number:</span> {$poNumber}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
        </div>
        <p>{$details}</p>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        return sendMail($request['email'], $subject, $html);
    } catch (Exception $e) {
        error_log("Notify PO action error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about invoice received
 */
function notifyInvoiceReceived(int $requestId, string $invoiceNumber, string $poNumber, float $invoiceAmount): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.currency, u.email, u.full_name, b.branch_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;

        $appUrl = getAppUrl();
        $invCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
        $formattedAmount = number_format($invoiceAmount, 2);
        $subject = "Invoice Received - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Invoice Received</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <p>An invoice has been recorded against your procurement request.</p>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Invoice Number:</span> {$invoiceNumber}</div>
            <div class="detail-row"><span class="label">PO Number:</span> {$poNumber}</div>
            <div class="detail-row"><span class="label">Invoice Amount:</span> {$invCurrency} \${$formattedAmount}</div>
        </div>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        // Notify requestor and finance
        $sent = sendMail($request['email'], $subject, $html);
        $financeUsers = getUsersByRole('Finance Officer');
        foreach ($financeUsers as $fu) {
            if (!empty($fu['email'])) sendMail($fu['email'], $subject, $html);
        }
        return $sent;
    } catch (Exception $e) {
        error_log("Notify invoice received error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about payment recorded
 */
function notifyPaymentRecorded(int $requestId, int $invoiceId, float $paymentAmount, string $paymentReference): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.currency, u.email, u.full_name, b.branch_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;

        $appUrl = getAppUrl();
        $payCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
        $formattedAmount = number_format($paymentAmount, 2);
        $subject = "Payment Recorded - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Payment Recorded</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <div class="status-box">Payment of {$payCurrency} \${$formattedAmount} Recorded</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Payment Reference:</span> {$paymentReference}</div>
            <div class="detail-row"><span class="label">Amount:</span> {$payCurrency} \${$formattedAmount}</div>
        </div>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        return sendMail($request['email'], $subject, $html);
    } catch (Exception $e) {
        error_log("Notify payment recorded error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about PO variation lifecycle (requested, approved, rejected)
 */
function notifyPOVariation(int $requestId, string $poNumber, string $action, float $variationAmount, string $reason = ''): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.currency, u.email, u.full_name, b.branch_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;

        $actionLabel = match($action) {
            'REQUESTED' => 'PO Variation Requested',
            'APPROVED' => 'PO Variation Approved',
            'REJECTED' => 'PO Variation Rejected',
            default => 'PO Variation Update'
        };
        $actionColor = ($action === 'REJECTED') ? '#dc3545' : '#198754';

        $appUrl = getAppUrl();
        $formattedAmount = number_format($variationAmount, 2);
        $subject = "{$actionLabel} - PO {$poNumber}";

        $varCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
        $detailBlock = $reason ? "<p><strong>Reason:</strong> {$reason}</p>" : '';

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: {$actionColor}; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">{$actionLabel}</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <div class="status-box">{$actionLabel}</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">PO Number:</span> {$poNumber}</div>
            <div class="detail-row"><span class="label">Variation Amount:</span> {$varCurrency} \${$formattedAmount}</div>
        </div>
        {$detailBlock}
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        // Notify requestor, and also HOD/Finance for approval-needed events
        $sent = sendMail($request['email'], $subject, $html);
        if ($action === 'REQUESTED') {
            $hodUsers = getUsersByRole('HOD');
            foreach ($hodUsers as $hu) {
                if (!empty($hu['email'])) sendMail($hu['email'], $subject, $html);
            }
        }
        return $sent;
    } catch (Exception $e) {
        error_log("Notify PO variation error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify about RFQ quote selected
 */
function notifyQuoteSelected(int $requestId, string $vendorName, float $quoteAmount): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, pr.currency, u.email, u.full_name, b.branch_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || !$request['email']) return false;
        $appUrl = getAppUrl();
        $quoteCurrency = normalizeCurrency($request['currency'] ?? 'JMD');
        $formattedAmount = number_format($quoteAmount, 2);
        $subject = "Quote Selected - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Quote Selected</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$request['full_name']},</p>
        <p>A vendor quote has been selected for your procurement request. The process will now proceed to commitment creation.</p>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Selected Vendor:</span> {$vendorName}</div>
            <div class="detail-row"><span class="label">Quote Amount:</span> {$quoteCurrency} \${$formattedAmount}</div>
        </div>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        return sendMail($request['email'], $subject, $html);
    } catch (Exception $e) {
        error_log("Notify quote selected error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify requestor that their request has been declined
 */
function notifyRequestDeclined(int $requestId, int $requestorId, string $declineReason): bool {
    global $pdo;

    if (!notificationsEnabled()) {
        return false;
    }

    try {
        // Fetch request details
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.request_type, pr.description,
                   u.full_name as requestor_name, b.branch_name, a.full_name as approver_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users a ON pr.approved_by = a.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return false;
        }

        // Get requestor email
        $email = getUserEmail($requestorId);
        if (!$email) {
            return false;
        }

        $appUrl = getAppUrl();
        $estimatedValue = number_format((float)($request['estimated_value'] ?? 0), 2);
        $requestType = ucfirst(str_replace('_', ' ', $request['request_type'] ?? 'Regular'));
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');

        $subject = "Request Declined: {$request['request_number']}";

        // HTML-safe variables
        $safeRequestorName = he($request['requestor_name']);
        $safeRequestNumber = he($request['request_number']);
        $safeRequestType = he($requestType);
        $safeBranchName = he($request['branch_name']);
        $safeDescription = he($request['description']);
        $safeDeclineReason = he($declineReason);
        $safeApproverName = he($request['approver_name'] ?? 'Unknown');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #f44336; color: white; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; }
        .alert { background: #ffebee; border-left: 4px solid #f44336; padding: 12px; margin: 15px 0; }
        .details { background: white; padding: 15px; margin: 15px 0; border: 1px solid #ddd; border-radius: 4px; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; color: #555; }
        .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .button { display: inline-block; background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Request Declined</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <p>Dear {$safeRequestorName},</p>
            
            <div class="alert">
                <strong>&#9888; Your procurement request has been declined.</strong>
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="label">Request Number:</span> <strong>{$safeRequestNumber}</strong>
                </div>
                <div class="detail-row">
                    <span class="label">Request Type:</span> {$safeRequestType}
                </div>
                <div class="detail-row">
                    <span class="label">Branch:</span> {$safeBranchName}
                </div>
                <div class="detail-row">
                    <span class="label">Estimated Value:</span> <strong>{$currency} {$estimatedValue}</strong>
                </div>
                <div class="detail-row">
                    <span class="label">Description:</span> {$safeDescription}
                </div>
            </div>

            <h3 style="color: #f44336; margin-top: 20px;">Reason for Decline:</h3>
            <p style="background: #fff3e0; padding: 12px; border-left: 4px solid #ff9800; line-height: 1.8;">
                {$safeDeclineReason}
            </p>

            <p style="margin-top: 20px;">
                Declined by: <strong>{$safeApproverName}</strong>
            </p>

            <p>
                You can resubmit this request with any necessary modifications:
            </p>
            
            <p>
                <a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">
                    Review Request &amp; Resubmit
                </a>
            </p>

            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                If you have questions about this decline, please contact the approver or your procurement officer.
            </p>
            
            <p style="margin-top: 30px; font-size: 12px; color: #777;">
                This is an automated notification from the Procurement Request Management System.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        return sendMail($email, $subject, $html);

    } catch (Exception $e) {
        error_log("Notify request declined error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify new user about their account creation and provide login information
 */
function notifyNewUser(int $userId, string $email, string $fullName, string $roleName): bool {
    global $pdo;

    if (!notificationsEnabled()) {
        return false;
    }

    try {
        $appUrl = getAppUrl();
        $subject = "Welcome to PRMS - Your Account Has Been Created";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #2196F3; color: white; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; }
        .welcome-box { background: #e3f2fd; border-left: 4px solid #2196F3; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .details { background: white; padding: 15px; margin: 15px 0; border: 1px solid #ddd; border-radius: 4px; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .label { font-weight: bold; color: #555; width: 40%; }
        .value { text-align: right; }
        .button { display: inline-block; background: #2196F3; color: white; padding: 12px 25px; text-decoration: none; border-radius: 4px; margin: 20px 0; text-align: center; }
        .button:hover { background: #1976D2; }
        .instructions { background: #fff9e6; border-left: 4px solid #ff9800; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .step { margin: 10px 0; padding: 10px; background: white; border-left: 3px solid #2196F3; padding-left: 15px; }
        .step-num { font-weight: bold; color: #2196F3; }
        .important { background: #ffebee; border-left: 4px solid #f44336; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Welcome to PRMS!</h2>
            <p style="margin: 5px 0 0 0;">Procurement Request Management System</p>
        </div>
        <div class="content">
            <div class="welcome-box">
                <p style="margin: 0; font-size: 16px;"><strong>Hello {$fullName},</strong></p>
                <p style="margin: 10px 0 0 0;">Your user account has been successfully created in the PRMS.</p>
            </div>

            <h3 style="color: #2196F3; margin-top: 20px;">Account Details</h3>
            <div class="details">
                <div class="detail-row">
                    <span class="label">Email:</span>
                    <span class="value"><strong>{$email}</strong></span>
                </div>
                <div class="detail-row">
                    <span class="label">Assigned Role:</span>
                    <span class="value"><strong>{$roleName}</strong></span>
                </div>
                <div class="detail-row">
                    <span class="label">Status:</span>
                    <span class="value"><strong>Active</strong></span>
                </div>
            </div>

            <div class="important">
                <strong>⚠️ Important Security Notice:</strong>
                <p style="margin: 10px 0 0 0;">
                    Your account requires a password change on first login. You will be prompted to set a new password when you access the system for the first time.
                </p>
            </div>

            <h3 style="color: #2196F3; margin-top: 20px;">How to Access the System</h3>
            <div class="instructions">
                <div class="step">
                    <span class="step-num">Step 1:</span> Visit the PRMS login page
                </div>
                <div class="step">
                    <span class="step-num">Step 2:</span> Enter your email address: <strong>{$email}</strong>
                </div>
                <div class="step">
                    <span class="step-num">Step 3:</span> Contact your system administrator for your temporary password
                </div>
                <div class="step">
                    <span class="step-num">Step 4:</span> After logging in, you will be required to change your password
                </div>
            </div>

            <p style="text-align: center; margin-top: 25px;">
                <a href="{$appUrl}/auth/login.php" class="button">Go to Login Page</a>
            </p>

            <h3 style="color: #2196F3; margin-top: 30px;">Your Role: {$roleName}</h3>
            <p>
                As a {$roleName}, you have been assigned specific permissions within the system. You will be able to perform tasks related to procurement management according to your role.
            </p>

            <div class="instructions">
                <p style="margin: 0;"><strong>Need Help?</strong></p>
                <p style="margin: 10px 0 0 0;">
                    If you have any questions about accessing your account or your role in the system, please contact your system administrator or the procurement department.
                </p>
            </div>

            <p style="margin-top: 30px; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 15px;">
                This is an automated notification from the Procurement Request Management System. Please do not reply to this email.
            </p>
        </div>
        <div class="footer">
            <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
        </div>
    </div>
</body>
</html>
HTML;

        return sendMail($email, $subject, $html);

    } catch (Exception $e) {
        error_log("Notify new user error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers when a branch head approves a request
 * This allows procurement to begin work immediately upon approval
 */
function notifyProcurementOfApproval(int $requestId, string $approvalStatus): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.estimated_value, pr.currency, pr.request_type,
                   pr.created_by, pr.branch_id, b.branch_name, u.full_name as requestor_name,
                   a.full_name as approver_name, pr.approved_at
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN users a ON pr.approved_by = a.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            error_log("NOTIFY PROCUREMENT: Request not found for ID $requestId");
            return false;
        }

        // Get all Procurement Officers
        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) {
            error_log("NOTIFY PROCUREMENT: No Procurement Officers found in the system");
            return false;
        }

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $statusLabel = str_replace('_', ' ', ucwords(strtolower($approvalStatus)));
        $subject = "Request Approved - Ready for Procurement: {$request['request_number']}";

        $sent = false;
        foreach ($procurementUsers as $po) {
            if (empty($po['email'])) continue;

            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .alert { background: #c8f5e0; border-left: 4px solid #198754; padding: 12px; margin: 15px 0; border-radius: 4px; color: #155724; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">✓ Request Approved - Ready for Procurement</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$po['full_name']},</p>
        
        <div class="alert">
            <strong>✓ A procurement request has been approved by the branch head and is now ready for your processing.</strong>
        </div>
        
        <div class="status-box">Approved - Ready for Procurement</div>
        
        <div class="details">
            <div class="detail-row">
                <span class="label">Request Number:</span> <strong>{$request['request_number']}</strong>
            </div>
            <div class="detail-row">
                <span class="label">Approval Status:</span> {$statusLabel}
            </div>
            <div class="detail-row">
                <span class="label">Request Type:</span> {$request['request_type']}
            </div>
            <div class="detail-row">
                <span class="label">Requestor:</span> {$request['requestor_name']}
            </div>
            <div class="detail-row">
                <span class="label">Branch:</span> {$request['branch_name']}
            </div>
            <div class="detail-row">
                <span class="label">Estimated Value:</span> {$estimatedValue}
            </div>
            <div class="detail-row">
                <span class="label">Approved By:</span> {$request['approver_name']}
            </div>
            <div class="detail-row">
                <span class="label">Approval Date:</span> {$request['approved_at']}
            </div>
        </div>
        
        <p>Please review the request details and proceed with the procurement process (RFQ, quotes, vendor selection, etc.).</p>
        
        <p>
            <a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">
                Review & Process Request
            </a>
        </p>
        
        <p style="margin-top: 20px; font-size: 12px; color: #777;">
            This is an automated notification from the Procurement Request Management System indicating that approval has been completed at the branch level.
        </p>
    </div>
    <div class="footer">
        <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
    </div>
</div></body></html>
HTML;
            if (sendMail($po['email'], $subject, $html)) {
                $sent = true;
            }
        }
        
        if ($sent) {
            error_log("NOTIFY PROCUREMENT: Successfully notified procurement officers of approval for request $requestId");
        }
        return $sent;

    } catch (Exception $e) {
        error_log("Notify procurement of approval error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers when a branch head declines a request
 * Procurement needs to know the request won't need processing
 */
function notifyProcurementOfDecline(int $requestId, string $declineReason): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency, pr.request_type,
                   pr.created_by, b.branch_name, u.full_name as requestor_name,
                   a.full_name as approver_name, pr.approved_at
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN users a ON pr.approved_by = a.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            error_log("NOTIFY PROCUREMENT DECLINE: Request not found for ID $requestId");
            return false;
        }

        // Get all Procurement Officers
        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) {
            error_log("NOTIFY PROCUREMENT DECLINE: No Procurement Officers found in the system");
            return false;
        }

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $subject = "Request Declined - Not Proceeding: {$request['request_number']}";

        $sent = false;
        foreach ($procurementUsers as $po) {
            if (empty($po['email'])) continue;

            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #dc3545, #d32f2f); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #dc3545; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .alert { background: #fdeef2; border-left: 4px solid #dc3545; padding: 12px; margin: 15px 0; border-radius: 4px; color: #721c24; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">✗ Request Declined - Not Proceeding</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$po['full_name']},</p>
        
        <div class="alert">
            <strong>✗ A procurement request has been declined by the branch head. No further processing is needed.</strong>
        </div>
        
        <div class="status-box">Declined - No Further Action</div>
        
        <div class="details">
            <div class="detail-row">
                <span class="label">Request Number:</span> <strong>{$request['request_number']}</strong>
            </div>
            <div class="detail-row">
                <span class="label">Request Type:</span> {$request['request_type']}
            </div>
            <div class="detail-row">
                <span class="label">Requestor:</span> {$request['requestor_name']}
            </div>
            <div class="detail-row">
                <span class="label">Branch:</span> {$request['branch_name']}
            </div>
            <div class="detail-row">
                <span class="label">Estimated Value:</span> {$estimatedValue}
            </div>
            <div class="detail-row">
                <span class="label">Declined By:</span> {$request['approver_name']}
            </div>
            <div class="detail-row">
                <span class="label">Decline Reason:</span>
            </div>
            <div style="margin-left: 15px; padding: 10px; background: white; border-left: 3px solid #dc3545; border-radius: 3px; margin-top: 8px;">
                {$declineReason}
            </div>
        </div>
        
        <p>No further procurement action is required for this request. You may archive or note this for your records.</p>
        
        <p>
            <a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">
                View Request Details
            </a>
        </p>
        
        <p style="margin-top: 20px; font-size: 12px; color: #777;">
            This is an automated notification from the Procurement Request Management System indicating that a request has been declined at the branch level.
        </p>
    </div>
    <div class="footer">
        <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
    </div>
</div></body></html>
HTML;
            if (sendMail($po['email'], $subject, $html)) {
                $sent = true;
            }
        }
        
        if ($sent) {
            error_log("NOTIFY PROCUREMENT: Successfully notified procurement officers of decline for request $requestId");
        }
        return $sent;

    } catch (Exception $e) {
        error_log("Notify procurement of decline error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers that a signed request has been received
 * Branch heads have printed, signed, and uploaded the procurement request document
 * Procurement can now proceed with RFQ or other workflow steps
 */
function notifySignedRequestReceived(int $requestId, string $requestNumber): bool {
    if (!notificationsEnabled()) {
        error_log("NOTIFY SIGNED REQUEST: Notifications disabled globally");
        return false;
    }

    global $pdo;
    try {
        // Get request details
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.estimated_value, pr.currency, pr.request_type,
                   pr.created_by, pr.branch_id, pr.signed_request_received_date,
                   b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            error_log("NOTIFY SIGNED REQUEST: Request not found for ID $requestId");
            return false;
        }

        // Get all Procurement Officers
        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) {
            error_log("NOTIFY SIGNED REQUEST: No Procurement Officers found in the system");
            return false;
        }

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $subject = "Signed Request Received - Ready for Processing: {$requestNumber}";

        $sent = false;
        foreach ($procurementUsers as $po) {
            if (empty($po['email'])) continue;

            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .alert { background: #c8f5e0; border-left: 4px solid #198754; padding: 12px; margin: 15px 0; border-radius: 4px; color: #155724; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">✓ Signed Request Received - Ready for Processing</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$po['full_name']},</p>
        
        <div class="alert">
            <strong>✓ The branch head has signed and uploaded the procurement request. The document is now available for your review and the request is ready for processing.</strong>
        </div>
        
        <div class="status-box">Signed Document Received - Ready for Next Steps</div>
        
        <div class="details">
            <div class="detail-row">
                <span class="label">Request Number:</span> <strong>{$request['request_number']}</strong>
            </div>
            <div class="detail-row">
                <span class="label">Request Type:</span> {$request['request_type']}
            </div>
            <div class="detail-row">
                <span class="label">Requestor:</span> {$request['requestor_name']}
            </div>
            <div class="detail-row">
                <span class="label">Branch:</span> {$request['branch_name']}
            </div>
            <div class="detail-row">
                <span class="label">Estimated Value:</span> {$estimatedValue}
            </div>
            <div class="detail-row">
                <span class="label">Signed Document Received:</span> {$request['signed_request_received_date']}
            </div>
        </div>
        
        <p style="margin-top: 20px;">
            The branch head has completed their review and provided the signed authorization for this procurement request. 
            You can now:
        </p>
        
        <ul style="margin: 15px 0; padding-left: 20px;">
            <li>Review the signed document</li>
            <li>Proceed with RFQ creation if applicable</li>
            <li>Contact vendors for quotes</li>
            <li>Begin the procurement workflow process</li>
        </ul>
        
        <p style="text-align: center; margin-top: 25px;">
            <a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">
                View Request & Start Processing
            </a>
        </p>
        
        <p style="margin-top: 20px; font-size: 12px; color: #777;">
            This is an automated notification from the Procurement Request Management System indicating that the signed request document has been received and is ready for processing.
        </p>
    </div>
    <div class="footer">
        <p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p>
    </div>
</div></body></html>
HTML;
            if (sendMail($po['email'], $subject, $html)) {
                $sent = true;
            }
        }
        
        if ($sent) {
            error_log("NOTIFY SIGNED REQUEST: Successfully notified procurement officers that signed request received for request $requestId");
        }
        return $sent;

    } catch (Exception $e) {
        error_log("Notify signed request received error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers that a request is ready for RFQ creation
 * Triggered when a request reaches RFQ_LETTER_AVAILABLE status
 */
function notifyProcurementRFQReady(int $requestId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.estimated_value, pr.currency,
                   pr.request_type, b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) return false;

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $subject = "Action Required: Create RFQ for {$request['request_number']}";

        $sent = false;
        foreach ($procurementUsers as $po) {
            if (empty($po['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #fff3cd; border-left: 4px solid #c9a227; padding: 12px; margin: 15px 0; border-radius: 4px; color: #856404; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Action Required: Create RFQ</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$po['full_name']},</p>
        <div class="alert">
            <strong>A procurement request has been fully approved and is now ready for RFQ creation.</strong>
        </div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> <strong>{$request['request_number']}</strong></div>
            <div class="detail-row"><span class="label">Requestor:</span> {$request['requestor_name']}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> {$estimatedValue}</div>
        </div>
        <p><strong>Next Step:</strong> Create and send the RFQ to vendors.</p>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request &amp; Create RFQ</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            if (sendMail($po['email'], $subject, $html)) $sent = true;
        }
        return $sent;
    } catch (Exception $e) {
        error_log("notifyProcurementRFQReady error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officer and requestor when a vendor uploads a quote
 */
function notifyQuoteUploaded(int $rfqId, string $vendorName): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.rfq_number, pr.request_id, pr.request_number, pr.created_by,
                   u.full_name as requestor_name, u.email as requestor_email
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) return false;

        $appUrl = getAppUrl();
        $subject = "Vendor Quote Received - {$data['rfq_number']}";
        $vendorNameSafe = htmlspecialchars($vendorName);

        $recipients = [];
        // Add requestor
        if (!empty($data['requestor_email'])) {
            $recipients[] = ['email' => $data['requestor_email'], 'name' => $data['requestor_name']];
        }
        // Add procurement officers
        foreach (getUsersByRole('Procurement Officer') as $po) {
            if (!empty($po['email'])) {
                $recipients[] = ['email' => $po['email'], 'name' => $po['full_name']];
            }
        }

        $sent = false;
        $seen = [];
        foreach ($recipients as $r) {
            if (isset($seen[$r['email']])) continue;
            $seen[$r['email']] = true;

            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #d1ecf1; border-left: 4px solid #0dcaf0; padding: 12px; margin: 15px 0; border-radius: 4px; color: #0c5460; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Vendor Quote Received</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$r['name']},</p>
        <div class="alert">
            <strong>A vendor has submitted a quotation for {$data['rfq_number']}.</strong>
        </div>
        <div class="details">
            <div class="detail-row"><span class="label">RFQ Number:</span> <strong>{$data['rfq_number']}</strong></div>
            <div class="detail-row"><span class="label">Request Number:</span> {$data['request_number']}</div>
            <div class="detail-row"><span class="label">Vendor:</span> {$vendorNameSafe}</div>
        </div>
        <p><a href="{$appUrl}/rfq/view.php?id={$rfqId}" class="button">View RFQ &amp; Quotes</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            if (sendMail($r['email'], $subject, $html)) $sent = true;
        }
        return $sent;
    } catch (Exception $e) {
        error_log("notifyQuoteUploaded error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify relevant users that quotes are ready for review (QUOTE_REVIEW_PENDING)
 * Notifies: Requestor, HOD, Procurement Officers
 */
function notifyQuoteReviewReady(int $requestId, int $rfqId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.created_by, u.full_name as requestor_name, u.email as requestor_email
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $appUrl = getAppUrl();
        $subject = "Action Required: Review Vendor Quotes - {$request['request_number']}";

        $recipients = [];
        if (!empty($request['requestor_email'])) {
            $recipients[] = ['email' => $request['requestor_email'], 'name' => $request['requestor_name']];
        }
        foreach (getUsersByRole('HOD') as $u) {
            if (!empty($u['email'])) $recipients[] = ['email' => $u['email'], 'name' => $u['full_name']];
        }
        foreach (getUsersByRole('Procurement Officer') as $u) {
            if (!empty($u['email'])) $recipients[] = ['email' => $u['email'], 'name' => $u['full_name']];
        }

        $sent = false;
        $seen = [];
        foreach ($recipients as $r) {
            if (isset($seen[$r['email']])) continue;
            $seen[$r['email']] = true;

            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #fff3cd; border-left: 4px solid #c9a227; padding: 12px; margin: 15px 0; border-radius: 4px; color: #856404; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Action Required: Review Vendor Quotes</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$r['name']},</p>
        <div class="alert">
            <strong>Vendor quotes are now available for review for request {$request['request_number']}.</strong>
        </div>
        <p>Please review the submitted vendor quotes and approve or provide feedback.</p>
        <p><a href="{$appUrl}/rfq/view.php?id={$rfqId}" class="button">Review Quotes Now</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            if (sendMail($r['email'], $subject, $html)) $sent = true;
        }
        return $sent;
    } catch (Exception $e) {
        error_log("notifyQuoteReviewReady error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify evaluation committee members that the evaluation stage has started
 */
function notifyEvaluationStarted(int $rfqId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.rfq_number, pr.request_number
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rfq) return false;

        // Get committee members for this RFQ
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.full_name
            FROM rfq_evaluation_committee ec
            JOIN users u ON ec.user_id = u.user_id
            WHERE ec.rfq_id = ? AND u.is_active = 1
        ");
        $stmt->execute([$rfqId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($members)) return false;

        $appUrl = getAppUrl();
        $subject = "Action Required: Evaluate RFQ {$rfq['rfq_number']}";

        $sent = false;
        foreach ($members as $m) {
            if (empty($m['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #fff3cd; border-left: 4px solid #c9a227; padding: 12px; margin: 15px 0; border-radius: 4px; color: #856404; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Action Required: RFQ Evaluation</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$m['full_name']},</p>
        <div class="alert">
            <strong>You have been assigned to evaluate {$rfq['rfq_number']} ({$rfq['request_number']}).</strong>
        </div>
        <p>The evaluation stage has started. Please review the vendor quotes and cast your vote.</p>
        <p><a href="{$appUrl}/rfq/view.php?id={$rfqId}" class="button">Start Evaluation</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            if (sendMail($m['email'], $subject, $html)) $sent = true;
        }
        return $sent;
    } catch (Exception $e) {
        error_log("notifyEvaluationStarted error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Finance Officers that a quote has been approved and funds verification/commitment is needed
 */
function notifyFinanceCommitmentNeeded(int $requestId, string $vendorName, float $quoteAmount): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.currency, b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $financeUsers = getUsersByRole('Finance Officer');
        if (empty($financeUsers)) return false;

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $formattedAmount = $currency . ' ' . number_format($quoteAmount, 2);
        $subject = "Action Required: Verify Funds & Create Commitment - {$request['request_number']}";
        $vendorNameSafe = htmlspecialchars($vendorName);

        $sent = false;
        foreach ($financeUsers as $fo) {
            if (empty($fo['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #fff3cd; border-left: 4px solid #c9a227; padding: 12px; margin: 15px 0; border-radius: 4px; color: #856404; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Action Required: Verify Funds &amp; Create Commitment</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$fo['full_name']},</p>
        <div class="alert">
            <strong>A vendor quote has been approved for {$request['request_number']}. Funds verification and commitment creation are now required.</strong>
        </div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> <strong>{$request['request_number']}</strong></div>
            <div class="detail-row"><span class="label">Requestor:</span> {$request['requestor_name']}</div>
            <div class="detail-row"><span class="label">Selected Vendor:</span> {$vendorNameSafe}</div>
            <div class="detail-row"><span class="label">Quote Amount:</span> {$formattedAmount}</div>
        </div>
        <p><a href="{$appUrl}/commitments/add.php?request_id={$requestId}" class="button">Verify Funds &amp; Create Commitment</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            if (sendMail($fo['email'], $subject, $html)) $sent = true;
        }
        return $sent;
    } catch (Exception $e) {
        error_log("notifyFinanceCommitmentNeeded error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify the first approver that a declined request has been resubmitted
 */
function notifyRequestResubmitted(int $requestId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency, pr.request_type,
                   pr.branch_id, b.branch_name, u.full_name as requestor_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $approverEmail = getApproverEmailForBranch(
            (int)$request['branch_id'],
            (float)$request['estimated_value'],
            $request['request_type']
        );
        if (!$approverEmail) return false;

        $appUrl = getAppUrl();
        $subject = "Request Resubmitted After Decline - {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .alert { background: #d1ecf1; border-left: 4px solid #0dcaf0; padding: 12px; margin: 15px 0; border-radius: 4px; color: #0c5460; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Request Resubmitted</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear Approver,</p>
        <div class="alert">
            <strong>A previously declined request has been revised and resubmitted by {$request['requestor_name']}.</strong>
        </div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> <strong>{$request['request_number']}</strong></div>
            <div class="detail-row"><span class="label">Requestor:</span> {$request['requestor_name']}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
            <div class="detail-row"><span class="label">Request Type:</span> {$request['request_type']}</div>
        </div>
        <p>This request will need to be resubmitted by the requestor before it reaches your queue. No action needed yet.</p>
        <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
        return sendMail($approverEmail, $subject, $html);
    } catch (Exception $e) {
        error_log("notifyRequestResubmitted error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Procurement Officers that funds have been verified and they need to fill the commitment form
 */
function notifyProcurementCommitmentFormNeeded(int $requestId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency,
                   b.branch_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $procurementUsers = getUsersByRole('Procurement Officer');
        if (empty($procurementUsers)) return false;

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $subject = "Funds Verified - {$request['request_number']} - Commitment Form Required";

        foreach ($procurementUsers as $pu) {
            if (empty($pu['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Funds Verified - Commitment Form Required</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$pu['full_name']},</p>
        <div class="status-box">Funds Verified - Action Required</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> {$estimatedValue}</div>
        </div>
        <p>Finance has verified that funds are available for this request. Please fill out the commitment form with the commitment date, amount, and GFMS commitment number, then submit to Finance for commitment document upload.</p>
        <p><a href="{$appUrl}/commitments/add.php?request_id={$requestId}" class="button">Fill Commitment Form</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            sendMail($pu['email'], $subject, $html);
        }

        return true;
    } catch (Exception $e) {
        error_log("notifyProcurementCommitmentFormNeeded error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify Finance Officers that Procurement has submitted the commitment form and document upload is needed
 */
function notifyFinanceCommitmentUploadNeeded(int $requestId, string $commitmentNumber): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency,
                   b.branch_name,
                   c.commitment_date, c.commitment_total, c.gfms_commitment_number
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN commitments c ON c.request_id = pr.request_id AND c.commitment_type = 'ORIGINAL'
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) return false;

        $financeUsers = getUsersByRole('Finance Officer');
        if (empty($financeUsers)) return false;

        $appUrl = getAppUrl();
        $commitmentAmount = 'JMD ' . number_format((float)($request['commitment_total'] ?? 0), 2);
        $gfmsNum = $request['gfms_commitment_number'] ? htmlspecialchars($request['gfms_commitment_number']) : 'Not provided';
        $hasForm = !empty($request['commitment_date']) || !empty($request['commitment_total']) || !empty($request['gfms_commitment_number']);
        $formStatusText = $hasForm ? 'Provided in PRMS for review.' : 'Not provided.';
        $subject = "Commitment Action Required - {$request['request_number']}";

        foreach ($financeUsers as $fu) {
            if (empty($fu['email'])) continue;
            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #fd7e14; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Commitment Action Required</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$fu['full_name']},</p>
        <div class="status-box">Create Commitment in GFMS</div>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> {$request['request_number']}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$request['branch_name']}</div>
        </div>
        <p>
            Procurement has completed their step for this request.
            Please create the commitment in GFMS, then upload the commitment document into PRMS to finalize the commitment.
        </p>
        <p>
            Optional commitment form from Procurement:
            {$formStatusText}
        </p>
        <p><a href="{$appUrl}/commitments/add.php?request_id={$requestId}" class="button">Create Commitment</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from PRMS.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;
            sendMail($fu['email'], $subject, $html);
        }

        return true;
    } catch (Exception $e) {
        error_log("notifyFinanceCommitmentUploadNeeded error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Notify the requestor that their request has been submitted successfully.
 * Sent on submission of any request type (REGULAR, PETTY_CASH, REIMBURSEMENT).
 */
function notifyRequestorSubmissionConfirmed(int $requestId): bool {
    if (!notificationsEnabled()) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency, pr.request_type,
                   b.branch_name, u.email, u.full_name
            FROM procurement_requests pr
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u ON pr.created_by = u.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request || empty($request['email'])) return false;

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = $currency . ' ' . number_format($request['estimated_value'], 2);
        $requestTypeLabel = ucwords(strtolower(str_replace('_', ' ', $request['request_type'] ?? 'Request')));

        // Build view URL based on request type
        $requestType = strtoupper($request['request_type'] ?? 'REGULAR');
        if ($requestType === 'PETTY_CASH') {
            $viewUrl = "{$appUrl}/petty_cash/view.php?request_id={$requestId}";
        } elseif ($requestType === 'REIMBURSEMENT') {
            $viewUrl = "{$appUrl}/reimbursement/view.php?request_id={$requestId}";
        } else {
            $viewUrl = "{$appUrl}/procurement/view.php?id={$requestId}";
        }

        $subject = "Request Submitted Successfully - {$request['request_number']}";

        // HTML-safe variables
        $safeFullName = he($request['full_name']);
        $safeRequestNumber = he($request['request_number']);
        $safeRequestType = he($requestTypeLabel);
        $safeBranchName = he($request['branch_name']);

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; color: #333; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .header { background: linear-gradient(90deg, #0b5e2b, #c9a227); color: white; padding: 20px; }
    .content { padding: 20px; }
    .status-box { background: #198754; color: white; padding: 15px; border-radius: 5px; text-align: center; margin: 15px 0; font-size: 18px; font-weight: bold; }
    .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
    .detail-row { margin: 8px 0; }
    .label { font-weight: bold; color: #555; }
    .button { background: #0b5e2b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 15px; }
    .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
</style></head><body>
<div class="container">
    <div class="header">
        <h2 style="margin: 0;">Request Submitted Successfully</h2>
        <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
    </div>
    <div class="content">
        <p>Dear {$safeFullName},</p>
        <div class="status-box">Submitted - Pending Review</div>
        <p>Your request has been submitted and is now in the approval workflow. You will be notified as it progresses.</p>
        <div class="details">
            <div class="detail-row"><span class="label">Request Number:</span> <strong>{$safeRequestNumber}</strong></div>
            <div class="detail-row"><span class="label">Request Type:</span> {$safeRequestType}</div>
            <div class="detail-row"><span class="label">Branch:</span> {$safeBranchName}</div>
            <div class="detail-row"><span class="label">Estimated Value:</span> {$estimatedValue}</div>
        </div>
        <p><a href="{$viewUrl}" class="button">View Request</a></p>
        <p style="margin-top: 20px; font-size: 12px; color: #777;">This is an automated notification from the Procurement Request Management System.</p>
    </div>
    <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
</div></body></html>
HTML;

        return sendMail($request['email'], $subject, $html);
    } catch (Exception $e) {
        error_log("notifyRequestorSubmissionConfirmed error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Check if RFQ auto-email distribution is enabled
 * Configurable by Procurement/administrators via Admin → Settings
 */
function rfqAutoEmailEnabled(): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute(['enable_rfq_auto_email']);
        $value = $stmt->fetchColumn();
        return $value !== false ? (bool)(int)$value : true; // Default: enabled
    } catch (Exception $e) {
        error_log("RFQ auto-email check error: {$e->getMessage()}");
        return true;
    }
}

/**
 * Notify relevant stakeholders that a request has been cancelled
 * Recipients: requestor, procurement officers, and branch approvers (HOD)
 */
function notifyRequestCancelled(int $requestId, string $cancelReason, string $previousStatus = ''): bool {
    global $pdo;

    if (!notificationsEnabled()) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.estimated_value, pr.currency, pr.request_type, pr.description,
                   u.full_name as requestor_name, u.email as requestor_email,
                   c.full_name as cancelled_by_name, b.branch_name
            FROM procurement_requests pr
            LEFT JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN users c ON pr.cancelled_by = c.user_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return false;
        }

        $appUrl = getAppUrl();
        $currency = normalizeCurrency($request['currency'] ?? 'JMD');
        $estimatedValue = number_format((float)($request['estimated_value'] ?? 0), 2);

        $safeRequestNumber = he($request['request_number']);
        $safeBranchName = he($request['branch_name'] ?? 'N/A');
        $safeDescription = he($request['description'] ?? '');
        $safeReason = he($cancelReason);
        $safeCancelledBy = he($request['cancelled_by_name'] ?? 'Unknown');
        $safePreviousStatus = he($previousStatus !== '' ? $previousStatus : 'N/A');

        $subject = "Request Cancelled: {$request['request_number']}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #6c757d; color: white; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; }
        .alert { background: #fff3e0; border-left: 4px solid #ff9800; padding: 12px; margin: 15px 0; }
        .details { background: white; padding: 15px; margin: 15px 0; border: 1px solid #ddd; border-radius: 4px; }
        .detail-row { padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; color: #555; }
        .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .button { display: inline-block; background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">Request Cancelled</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            <div class="alert">
                <strong>&#9888; Procurement request {$safeRequestNumber} has been cancelled.</strong>
            </div>
            <div class="details">
                <div class="detail-row"><span class="label">Request Number:</span> <strong>{$safeRequestNumber}</strong></div>
                <div class="detail-row"><span class="label">Branch:</span> {$safeBranchName}</div>
                <div class="detail-row"><span class="label">Estimated Value:</span> <strong>{$currency} {$estimatedValue}</strong></div>
                <div class="detail-row"><span class="label">Description:</span> {$safeDescription}</div>
                <div class="detail-row"><span class="label">Stage at Cancellation:</span> {$safePreviousStatus}</div>
                <div class="detail-row"><span class="label">Cancelled By:</span> {$safeCancelledBy}</div>
            </div>
            <h3 style="color: #ff9800; margin-top: 20px;">Reason for Cancellation:</h3>
            <p style="background: #fff3e0; padding: 12px; border-left: 4px solid #ff9800; line-height: 1.8;">{$safeReason}</p>
            <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">View Request</a></p>
            <p style="margin-top: 30px; font-size: 12px; color: #777;">This is an automated notification from the Procurement Request Management System.</p>
        </div>
        <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
    </div>
</body>
</html>
HTML;

        $sent = false;

        // Requestor
        if (!empty($request['requestor_email'])) {
            $sent = sendMail($request['requestor_email'], $subject, $html) || $sent;
        }

        // Procurement officers and branch heads (HOD)
        foreach (['Procurement Officer', 'HOD'] as $roleName) {
            foreach (getUsersByRole($roleName) as $user) {
                if (!empty($user['email']) && $user['email'] !== ($request['requestor_email'] ?? '')) {
                    $sent = sendMail($user['email'], $subject, $html) || $sent;
                }
            }
        }

        return $sent;

    } catch (Exception $e) {
        error_log("Notify request cancelled error: {$e->getMessage()}");
        return false;
    }
}

/**
 * Escalating reminder for missing post-completion documents
 * (Signed Commitment Document — Finance; Signed Purchase Order — Procurement Officer)
 *
 * @param int    $requestId       Request ID
 * @param array  $missingDocs     List of missing document labels
 * @param int    $daysOverdue     Days since workflow completion
 * @param int    $escalationLevel 1 = responsible person only, 2 = + Branch Head(s), 3 = + HOD (urgent)
 * @param array  $recipients      List of recipient emails
 * @param string $responsibleParty Label of the responsible role
 * @return bool
 */
function notifyMissingDocumentReminder(
    int $requestId,
    array $missingDocs,
    int $daysOverdue,
    int $escalationLevel,
    array $recipients,
    string $responsibleParty
): bool {
    global $pdo;

    if (!notificationsEnabled() || empty($recipients)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pr.request_number, pr.description, pr.status, pr.approved_at,
                   a.full_name as approved_by_name
            FROM procurement_requests pr
            LEFT JOIN users a ON pr.approved_by = a.user_id
            WHERE pr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return false;
        }

        $appUrl = getAppUrl();
        $isUrgent = $escalationLevel >= 3;
        $urgentPrefix = $isUrgent ? '[URGENT] ' : '';
        $subject = $urgentPrefix . "Outstanding Documents: {$request['request_number']} ({$daysOverdue} day(s) overdue)";

        $safeRequestNumber = he($request['request_number']);
        $safeTitle = he($request['description'] ?? '');
        $safeDocs = he(implode(', ', $missingDocs));
        $safeResponsible = he($responsibleParty);
        $safeApprovedBy = he($request['approved_by_name'] ?? 'N/A');
        $safeCompletedAt = he(!empty($request['approved_at']) ? date('d M Y', strtotime($request['approved_at'])) : 'N/A');
        $headerColor = $isUrgent ? '#d32f2f' : ($escalationLevel === 2 ? '#f57c00' : '#1976d2');
        $urgentBanner = $isUrgent
            ? '<div style="background:#ffebee;border-left:4px solid #d32f2f;padding:12px;margin:15px 0;"><strong>&#9888; URGENT: These documents remain outstanding after repeated reminders.</strong></div>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: {$headerColor}; color: white; padding: 20px; text-align: center; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; }
        .details { background: white; padding: 15px; margin: 15px 0; border: 1px solid #ddd; border-radius: 4px; }
        .detail-row { padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; color: #555; }
        .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        .button { display: inline-block; background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">{$urgentPrefix}Outstanding Document Reminder</h2>
            <p style="margin: 5px 0 0 0;">Government Chemist - PRMS</p>
        </div>
        <div class="content">
            {$urgentBanner}
            <p>The following required document(s) have not yet been uploaded for a completed request:</p>
            <div class="details">
                <div class="detail-row"><span class="label">Request Number:</span> <strong>{$safeRequestNumber}</strong></div>
                <div class="detail-row"><span class="label">Request Title:</span> {$safeTitle}</div>
                <div class="detail-row"><span class="label">Outstanding Document(s):</span> <strong>{$safeDocs}</strong></div>
                <div class="detail-row"><span class="label">Days Overdue:</span> <strong>{$daysOverdue}</strong></div>
                <div class="detail-row"><span class="label">Responsible Party:</span> {$safeResponsible}</div>
                <div class="detail-row"><span class="label">Approved By:</span> {$safeApprovedBy}</div>
                <div class="detail-row"><span class="label">Approval/Completion Date:</span> {$safeCompletedAt}</div>
            </div>
            <p><a href="{$appUrl}/procurement/view.php?id={$requestId}" class="button">Upload Document</a></p>
            <p style="margin-top: 30px; font-size: 12px; color: #777;">This is an automated notification from the Procurement Request Management System.</p>
        </div>
        <div class="footer"><p>&copy; Government Chemist &middot; PRMS &middot; Confidential</p></div>
    </div>
</body>
</html>
HTML;

        $sent = false;
        foreach (array_unique($recipients) as $email) {
            if (!empty($email)) {
                $sent = sendMail($email, $subject, $html) || $sent;
            }
        }
        return $sent;

    } catch (Exception $e) {
        error_log("Notify missing document reminder error: {$e->getMessage()}");
        return false;
    }
}

/**
 * ===================================
 * RFQ Quote Approval Notifications
 * ===================================
 */

/**
 * Notify specification reviewer that quotes are ready for review
 */
function notifySpecReviewerQuotesReady(int $rfqId): bool {
    if (!notificationsEnabled()) {
        return false;
    }

    if ($rfqId <= 0) {
        error_log("Notification[SpecReviewQuotesReady]: invalid RFQ ID provided");
        return false;
    }

    global $pdo;
    try {
        // Get RFQ details
        $stmt = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, pr.request_number, pr.description,
                   pr.estimated_value, r.submission_deadline
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rfq) {
            error_log("Notification[SpecReviewQuotesReady]: RFQ {$rfqId} not found");
            return false;
        }

        // Get spec reviewers
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.display_name
            FROM rfq_spec_reviewers rsr
            JOIN users u ON rsr.reviewer_id = u.user_id
            WHERE rsr.rfq_id = ? AND rsr.is_active = 1
        ");
        $stmt->execute([$rfqId]);
        $reviewers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($reviewers)) {
            error_log("Notification[SpecReviewQuotesReady]: no active specification reviewers assigned for RFQ {$rfqId}");
            return false;
        }

        $appUrl = getAppUrl();
        if ($appUrl === '') {
            error_log("Notification[SpecReviewQuotesReady]: application URL could not be resolved for RFQ {$rfqId}");
            return false;
        }

        $rfqUrl = "{$appUrl}/rfq/spec_review_approve.php?id={$rfqId}";
        $subject = "RFQ " . he($rfq['rfq_number']) . " - Quotes Ready for Specification Review";
        $html = "
            <p>Quotations have been submitted for RFQ <strong>" . he($rfq['rfq_number']) . "</strong> (Request: " . he($rfq['request_number']) . ").</p>
            <p><strong>Description:</strong> " . he($rfq['description']) . "</p>
            <p><strong>Estimated Value:</strong> " . formatCurrency($rfq['estimated_value']) . "</p>
            <p><strong>Submission Deadline:</strong> " . he($rfq['submission_deadline']) . "</p>
            <p>Please review the submitted quotes for specification compliance and provide your approval decision.</p>
            <a href='" . he($rfqUrl) . "' class='btn btn-warning'>Review Quotes</a>
        ";

        $anySent = false;
        foreach ($reviewers as $reviewer) {
            if (empty($reviewer['email']) || !filter_var($reviewer['email'], FILTER_VALIDATE_EMAIL)) {
                error_log("Notification[SpecReviewQuotesReady]: RFQ {$rfqId} skipped invalid recipient email for user {$reviewer['user_id']}");
                continue;
            }
            $sent = sendMail($reviewer['email'], $subject, $html);
            error_log("Notification[SpecReviewQuotesReady]: RFQ {$rfqId} recipient={$reviewer['email']} url={$rfqUrl} status=" . ($sent ? 'Sent' : 'Failed'));
            $anySent = $anySent || $sent;
        }

        return $anySent;
    } catch (Exception $e) {
        error_log("Error notifying spec reviewer for RFQ {$rfqId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify branch head that spec review is approved and awaiting final approval
 */
function notifyBranchHeadSpecReviewApproved(int $rfqId): bool {
    if (!notificationsEnabled()) {
        return false;
    }

    if ($rfqId <= 0) {
        error_log("Notification[BranchHeadSpecReviewApproved]: invalid RFQ ID provided");
        return false;
    }

    global $pdo;
    try {
        // Get RFQ details with spec reviewer name
        $stmt = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, pr.request_number, pr.description,
                   pr.estimated_value, r.submission_deadline, sr.display_name as spec_reviewer_name
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            LEFT JOIN users sr ON r.spec_reviewer_id = sr.user_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rfq) {
            error_log("Notification[BranchHeadSpecReviewApproved]: RFQ {$rfqId} not found");
            return false;
        }

        // Get branch head approvers
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.display_name
            FROM rfq_branch_head_approvers rbha
            JOIN users u ON rbha.approver_id = u.user_id
            WHERE rbha.rfq_id = ? AND rbha.is_active = 1
        ");
        $stmt->execute([$rfqId]);
        $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($approvers)) {
            error_log("Notification[BranchHeadSpecReviewApproved]: no active branch head approvers assigned for RFQ {$rfqId}");
            return false;
        }

        $appUrl = getAppUrl();
        if ($appUrl === '') {
            error_log("Notification[BranchHeadSpecReviewApproved]: application URL could not be resolved for RFQ {$rfqId}");
            return false;
        }

        $rfqUrl = "{$appUrl}/rfq/branch_head_approve.php?id={$rfqId}";
        $subject = "RFQ " . he($rfq['rfq_number']) . " - Awaiting Your Branch Head Approval";
        $html = "
            <p>The specification review for RFQ <strong>" . he($rfq['rfq_number']) . "</strong> has been approved.</p>
            <p><strong>Request Number:</strong> " . he($rfq['request_number']) . "</p>
            <p><strong>Description:</strong> " . he($rfq['description']) . "</p>
            <p><strong>Estimated Value:</strong> " . formatCurrency($rfq['estimated_value']) . "</p>
            <p><strong>Specification Reviewer:</strong> " . he($rfq['spec_reviewer_name']) . "</p>
            <p>As the Branch Head, please provide your final approval or clarification before supplier selection can proceed.</p>
            <a href='" . he($rfqUrl) . "' class='btn btn-primary'>Provide Approval</a>
        ";

        $anySent = false;
        foreach ($approvers as $approver) {
            if (empty($approver['email']) || !filter_var($approver['email'], FILTER_VALIDATE_EMAIL)) {
                error_log("Notification[BranchHeadSpecReviewApproved]: RFQ {$rfqId} skipped invalid recipient email for user {$approver['user_id']}");
                continue;
            }
            $sent = sendMail($approver['email'], $subject, $html);
            error_log("Notification[BranchHeadSpecReviewApproved]: RFQ {$rfqId} recipient={$approver['email']} url={$rfqUrl} status=" . ($sent ? 'Sent' : 'Failed'));
            $anySent = $anySent || $sent;
        }

        return $anySent;
    } catch (Exception $e) {
        error_log("Error notifying branch head for RFQ {$rfqId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify requestor that spec review was rejected
 */
function notifyRequestorSpecReviewRejected(int $rfqId, string $reason): bool {
    if (!notificationsEnabled()) {
        return false;
    }

    if ($rfqId <= 0) {
        error_log("Notification[RequestorSpecReviewRejected]: invalid RFQ ID provided");
        return false;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, pr.request_number, pr.created_by,
                   u.email, u.display_name, sr.display_name as spec_reviewer_name
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            JOIN users u ON pr.created_by = u.user_id
            LEFT JOIN users sr ON r.spec_reviewer_id = sr.user_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rfq) {
            error_log("Notification[RequestorSpecReviewRejected]: RFQ {$rfqId} not found");
            return false;
        }

        $appUrl = getAppUrl();
        if ($appUrl === '') {
            error_log("Notification[RequestorSpecReviewRejected]: application URL could not be resolved for RFQ {$rfqId}");
            return false;
        }

        $rfqUrl = "{$appUrl}/rfq/view.php?id={$rfqId}";
        $subject = "RFQ " . he($rfq['rfq_number']) . " - Specification Review Rejected";
        $html = "
            <p>The specification review for RFQ <strong>" . he($rfq['rfq_number']) . "</strong> has been rejected.</p>
            <p><strong>Request Number:</strong> " . he($rfq['request_number']) . "</p>
            <p><strong>Reviewed by:</strong> " . he($rfq['spec_reviewer_name']) . "</p>
            <p><strong>Reason:</strong></p>
            <p>" . nl2br(he($reason)) . "</p>
            <p>Please address the comments and resubmit revised quotes.</p>
            <a href='" . he($rfqUrl) . "' class='btn btn-info'>View RFQ Details</a>
        ";

        if (empty($rfq['email']) || !filter_var($rfq['email'], FILTER_VALIDATE_EMAIL)) {
            error_log("Notification[RequestorSpecReviewRejected]: RFQ {$rfqId} skipped invalid requestor email");
            return false;
        }

        $sent = sendMail($rfq['email'], $subject, $html);
        error_log("Notification[RequestorSpecReviewRejected]: RFQ {$rfqId} recipient={$rfq['email']} url={$rfqUrl} status=" . ($sent ? 'Sent' : 'Failed'));

        return $sent;
    } catch (Exception $e) {
        error_log("Error notifying requestor of rejection for RFQ {$rfqId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify procurement team that all approvals are complete
 */
function notifyProcurementAllApprovalsComplete(int $rfqId): bool {
    if (!notificationsEnabled()) {
        return false;
    }

    if ($rfqId <= 0) {
        error_log("Notification[ProcurementAllApprovalsComplete]: invalid RFQ ID provided");
        return false;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, pr.request_number, pr.description,
                   pr.estimated_value
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            WHERE r.rfq_id = ?
        ");
        $stmt->execute([$rfqId]);
        $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rfq) {
            error_log("Notification[ProcurementAllApprovalsComplete]: RFQ {$rfqId} not found");
            return false;
        }

        // Get procurement officers
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.display_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'Procurement Officer' AND u.is_active = 1
        ");
        $stmt->execute();
        $officers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($officers)) {
            error_log("Notification[ProcurementAllApprovalsComplete]: no active procurement officers found for RFQ {$rfqId}");
            return false;
        }

        $appUrl = getAppUrl();
        if ($appUrl === '') {
            error_log("Notification[ProcurementAllApprovalsComplete]: application URL could not be resolved for RFQ {$rfqId}");
            return false;
        }

        $rfqUrl = "{$appUrl}/rfq/view.php?id={$rfqId}";
        $subject = "RFQ " . he($rfq['rfq_number']) . " - All Approvals Complete, Ready for Supplier Selection";
        $html = "
            <p>All approvals for RFQ <strong>" . he($rfq['rfq_number']) . "</strong> have been completed.</p>
            <p><strong>Request Number:</strong> " . he($rfq['request_number']) . "</p>
            <p><strong>Description:</strong> " . he($rfq['description']) . "</p>
            <p><strong>Estimated Value:</strong> " . formatCurrency($rfq['estimated_value']) . "</p>
            <p>You can now proceed with supplier selection and award of contract.</p>
            <a href='" . he($rfqUrl) . "' class='btn btn-success'>View RFQ &amp; Select Supplier</a>
        ";

        $anySent = false;
        foreach ($officers as $officer) {
            if (empty($officer['email']) || !filter_var($officer['email'], FILTER_VALIDATE_EMAIL)) {
                error_log("Notification[ProcurementAllApprovalsComplete]: RFQ {$rfqId} skipped invalid recipient email for user {$officer['user_id']}");
                continue;
            }
            $sent = sendMail($officer['email'], $subject, $html);
            error_log("Notification[ProcurementAllApprovalsComplete]: RFQ {$rfqId} recipient={$officer['email']} url={$rfqUrl} status=" . ($sent ? 'Sent' : 'Failed'));
            $anySent = $anySent || $sent;
        }

        return $anySent;
    } catch (Exception $e) {
        error_log("Error notifying procurement of completion for RFQ {$rfqId}: " . $e->getMessage());
        return false;
    }
}

