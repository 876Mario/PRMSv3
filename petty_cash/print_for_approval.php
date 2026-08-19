<?php
/**
 * Print Petty Cash Reconciliation for Approval
 * 
 * Generates a clean PDF document for reconciliation review and approval
 * This is petty-cash-specific with disbursement details, reconciliation summary, and signatures
 */

$REQUIRE_PERMISSION = 'view_requests';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

// Check if Dompdf library is installed
$autoloadPath = __DIR__."/../vendor/autoload.php";
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    exit('Error: Required dependencies are not installed. Please contact the system administrator to run "composer install".');
}

require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

// Get request ID from GET parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit('Invalid request ID');
}

$request_id = (int)$_GET['id'];

// Verify request exists and user has permission
try {
    $stmt = $pdo->prepare("
        SELECT pr.*, 
               b.branch_name,
               u1.full_name AS created_by_name,
               u2.full_name AS approved_by_name
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u1 ON pr.created_by = u1.user_id
        LEFT JOIN users u2 ON pr.approved_by = u2.user_id
        WHERE pr.request_id = ? AND pr.request_type = 'PETTY_CASH'
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit('Error fetching request details: ' . htmlspecialchars($e->getMessage()));
}

if (!$request) {
    http_response_code(404);
    exit('Petty cash request not found or invalid request type');
}

// Authorize user to view this request
$isRequestor = ($_SESSION['user_id'] == $request['created_by']);
$isAuthorizedRole = in_array($_SESSION['role_name'] ?? '', 
    ['Finance Officer', 'Procurement Officer', 'Director HRM&A', 'Admin', 'SuperAdmin']);

if (!$isRequestor && !$isAuthorizedRole) {
    http_response_code(403);
    logAudit($pdo, 'procurement_requests', $request_id, 'ACCESS_DENIED',
        'User ' . ($_SESSION['full_name'] ?? 'Unknown') . ' attempted unauthorized access to petty cash request print');
    exit('You do not have permission to print this petty cash request');
}

// Load document control settings
try {
    $dcStmt = $pdo->query("
        SELECT * FROM doc_ctrl_settings 
        WHERE request_type = 'PETTY_CASH'
        LIMIT 1
    ");
    $dc = $dcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $dc = [];
}

// Validate document control settings
$missing = [];
if (empty($dc['form_revision']))  $missing[] = '<strong>Form Revision</strong>';
if (empty($dc['effective_date'])) $missing[] = '<strong>Effective Date</strong>';
if (empty($dc['dcr_number']))     $missing[] = '<strong>DCR Number</strong>';

if (!empty($missing)) {
    http_response_code(422);
    exit('Cannot generate Print Request for Approval: the following Document Control field(s) have not been configured by an administrator: '
        . implode(', ', $missing)
        . '. Please ask an Administrator to set these values in Admin › Settings › Document Control Settings.');
}

// Persist document control snapshot
try {
    $snapStmt = $pdo->prepare("
        UPDATE procurement_requests
        SET doc_ctrl_form_revision  = ?,
            doc_ctrl_effective_date = ?,
            doc_ctrl_dcr_number     = ?
        WHERE request_id = ?
    ");
    $snapStmt->execute([
        $dc['form_revision'],
        $dc['effective_date'],
        $dc['dcr_number'],
        $request_id,
    ]);
} catch (Exception $e) {
    // Non-fatal – continue generating PDF even if snapshot save fails
    error_log("Warning: Failed to save document control snapshot: " . $e->getMessage());
}

// Fetch petty cash disbursement details
try {
    $disburseStmt = $pdo->prepare("
        SELECT pcd.*, u.full_name as disbursed_by_name
        FROM petty_cash_disbursements pcd
        LEFT JOIN users u ON pcd.disbursed_by = u.user_id
        WHERE pcd.request_id = ?
        ORDER BY pcd.created_at DESC
        LIMIT 1
    ");
    $disburseStmt->execute([$request_id]);
    $disbursement = $disburseStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $disbursement = null;
}

// Fetch reconciliation if exists
$reconciliation = null;
if ($disbursement) {
    try {
        $reconcileStmt = $pdo->prepare("
            SELECT pr.*, u.full_name as submitted_by_name, u2.full_name as verified_by_name
            FROM petty_cash_reconciliations pr
            LEFT JOIN users u ON pr.submitted_by = u.user_id
            LEFT JOIN users u2 ON pr.verified_by = u2.user_id
            WHERE pr.disburse_id = ?
            ORDER BY pr.created_at DESC
            LIMIT 1
        ");
        $reconcileStmt->execute([$disbursement['disburse_id']]);
        $reconciliation = $reconcileStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reconciliation = null;
    }
}

// Fetch document count
$docCount = 0;
if ($reconciliation) {
    try {
        $docStmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM petty_cash_reconciliation_documents
            WHERE reconcile_id = ? AND is_deleted = 0
        ");
        $docStmt->execute([$reconciliation['reconcile_id']]);
        $docCount = $docStmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        $docCount = 0;
    }
}

// Generate HTML content for PDF
$html = '';
$html .= '<div style="background-color: #1f4788; color: white; padding: 20px; text-align: center; margin-bottom: 20px; border-bottom: 3px solid #d4af37;">';
$html .= '<h1 style="margin: 0; font-size: 24px; font-weight: bold;">GOVERNMENT CHEMIST DIRECTORATE</h1>';
$html .= '<h2 style="margin: 10px 0 0 0; font-size: 18px; font-weight: normal;">PETTY CASH RECONCILIATION FOR APPROVAL</h2>';
$html .= '</div>';

$html .= '<div style="text-align: right; font-size: 11px; margin-bottom: 15px; color: #666;">';
$html .= 'Generated: ' . date('Y-m-d H:i:s') . '</div>';

// Document Control Section
$html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 11px; border: 1px solid #ccc;">';
$html .= '<tr style="background-color: #f5f5f5;">';
$html .= '<td style="padding: 8px; border-right: 1px solid #ccc; font-weight: bold;">Form Revision:</td>';
$html .= '<td style="padding: 8px;">'. htmlspecialchars($dc['form_revision'] ?? '') .'</td>';
$html .= '<td style="padding: 8px; border-right: 1px solid #ccc; border-left: 1px solid #ccc; font-weight: bold;">Effective Date:</td>';
$html .= '<td style="padding: 8px;">'. htmlspecialchars($dc['effective_date'] ?? '') .'</td>';
$html .= '</tr>';
$html .= '<tr style="background-color: #f9f9f9;">';
$html .= '<td colspan="4" style="padding: 8px; border-top: 1px solid #ccc; font-weight: bold;">DCR Number: '. htmlspecialchars($dc['dcr_number'] ?? '') .'</td>';
$html .= '</tr>';
$html .= '</table>';

// Request Information Section
$html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">';
$html .= '<tr style="background-color: #e8e8e8;">';
$html .= '<td colspan="4" style="padding: 8px; font-weight: bold; border: 1px solid #999;">REQUEST INFORMATION</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Request Number:</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 25%;">'. htmlspecialchars($request['request_number'] ?? 'N/A') .'</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Request Date:</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 25%;">'. htmlspecialchars(substr($request['request_date'], 0, 10) ?? 'N/A') .'</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Branch:</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. htmlspecialchars($request['branch_name'] ?? 'N/A') .'</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Requestor:</td>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. htmlspecialchars($request['created_by_name'] ?? 'N/A') .'</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td colspan="4" style="padding: 8px; border: 1px solid #ccc;">';
$html .= '<strong>Request Status:</strong> '. htmlspecialchars($request['status'] ?? 'Unknown') .'</td>';
$html .= '</tr>';
$html .= '</table>';

// Disbursement Details
if ($disbursement) {
    $html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">';
    $html .= '<tr style="background-color: #e8e8e8;">';
    $html .= '<td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">DISBURSEMENT DETAILS</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 50%; font-weight: bold;">Amount Authorized:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 50%;">'. number_format($disbursement['amount_authorized'] ?? 0, 2) .' '. htmlspecialchars($request['currency'] ?? 'USD') .'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Disbursed By:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. htmlspecialchars($disbursement['disbursed_by_name'] ?? 'N/A') .' on '. htmlspecialchars(substr($disbursement['disbursement_date'], 0, 10) ?? '') .'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Reconciliation Deadline:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc;">24 hours from disbursement (' . htmlspecialchars(substr($disbursement['disbursement_deadline'], 0, 16) ?? '') . ')</td>';
    $html .= '</tr>';
    $html .= '</table>';
}

// Reconciliation Details
if ($reconciliation) {
    $html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">';
    $html .= '<tr style="background-color: #e8e8e8;">';
    $html .= '<td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">RECONCILIATION SUMMARY</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 50%; font-weight: bold;">Purchase Amount:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; width: 50%;">'. number_format($reconciliation['purchase_amount'] ?? 0, 2) .' '. htmlspecialchars($request['currency'] ?? 'USD') .'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Change Returned:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. number_format($reconciliation['change_amount'] ?? 0, 2) .' '. htmlspecialchars($request['currency'] ?? 'USD') .'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Submission Deadline Met:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. ($reconciliation['submission_deadline_met'] ? 'Yes' : 'No ('. htmlspecialchars($reconciliation['hours_from_disbursement'] ?? 0) .' hours after deadline)') .'</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Supporting Documents:</td>';
    $html .= '<td style="padding: 8px; border: 1px solid #ccc;">'. $docCount .' attached</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td colspan="2" style="padding: 8px; border: 1px solid #ccc;">';
    $html .= '<strong>Reconciliation Notes:</strong><br/>'. nl2br(htmlspecialchars($reconciliation['reconciliation_notes'] ?? 'None')) .'</td>';
    $html .= '</tr>';
    $html .= '</table>';
}

// Purpose/Description
$html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">';
$html .= '<tr style="background-color: #e8e8e8;">';
$html .= '<td style="padding: 8px; font-weight: bold; border: 1px solid #999;">PURPOSE / DESCRIPTION</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td style="padding: 15px; border: 1px solid #ccc; min-height: 80px;">';
$html .= nl2br(htmlspecialchars($request['description'] ?? ''));
$html .= '</td>';
$html .= '</tr>';
$html .= '</table>';

// Required Actions
$html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">';
$html .= '<tr style="background-color: #e8e8e8;">';
$html .= '<td style="padding: 8px; font-weight: bold; border: 1px solid #999;">REQUIRED ACTIONS</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td style="padding: 8px; border: 1px solid #ccc;">';
$html .= '<strong>1. Procurement Officer</strong> - Verify all supporting documents are provided and reconciliation is accurate<br/>';
$html .= '<strong>2. Finance Officer</strong> - Verify amount reconciliation and approve for payment<br/>';
$html .= '<strong>3. Director</strong> - Final authorization for completion and payment release';
$html .= '</td>';
$html .= '</tr>';
$html .= '</table>';

// Authorization Signatures
$html .= '<table style="width: 100%; margin-bottom: 20px; font-size: 11px; border-collapse: collapse;">';
$html .= '<tr style="background-color: #e8e8e8;">';
$html .= '<td colspan="3" style="padding: 8px; font-weight: bold; border: 1px solid #999;">AUTHORIZATION & APPROVAL SIGNATURES</td>';
$html .= '</tr>';
$html .= '<tr>';
$html .= '<td style="padding: 12px; border: 1px solid #ccc; width: 33%; text-align: center;">';
$html .= '<strong>Procurement Officer</strong><br/>(Verify Documents)<br/><br/><br/>';
$html .= '___________________________<br/>';
$html .= 'Signature & Date';
$html .= '</td>';
$html .= '<td style="padding: 12px; border: 1px solid #ccc; width: 33%; text-align: center;">';
$html .= '<strong>Finance Officer</strong><br/>(Verify Amounts)<br/><br/><br/>';
$html .= '___________________________<br/>';
$html .= 'Signature & Date';
$html .= '</td>';
$html .= '<td style="padding: 12px; border: 1px solid #ccc; width: 34%; text-align: center;">';
$html .= '<strong>Director Approval</strong><br/>(Final Authorization)<br/><br/><br/>';
$html .= '___________________________<br/>';
$html .= 'Signature & Date';
$html .= '</td>';
$html .= '</tr>';
$html .= '</table>';

// Footer
$html .= '<div style="margin-top: 30px; padding-top: 15px; border-top: 2px solid #ccc; font-size: 10px; color: #666;">';
$html .= '<p style="margin: 5px 0;"><strong>CONFIDENTIAL</strong> - This document contains confidential financial information. Unauthorized copying or distribution is prohibited.</p>';
$html .= '<p style="margin: 5px 0;">For questions about this petty cash reconciliation, please contact the Procurement Office.</p>';
$html .= '</div>';

// Generate PDF
try {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('logOutputFile', sys_get_temp_dir() . '/dompdf.log');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Log the print event
    logAudit($pdo, 'request_documents', null, 'PRINT_EVENT',
        'Petty cash request ' . htmlspecialchars($request['request_number']) . ' printed for approval by ' . ($_SESSION['full_name'] ?? 'Unknown'));
    
    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="PETTY_CASH_' . htmlspecialchars($request['request_number']) . '_' . date('Ymd_His') . '.pdf"');
    echo $dompdf->output();
    
} catch (Exception $e) {
    http_response_code(500);
    exit('Error generating PDF: ' . htmlspecialchars($e->getMessage()));
}
?>
