<?php
/**
 * Reimbursement Print for Signing
 * Generates a printable PDF approval form for reimbursement requests
 * User signs this form and uploads it back for processing
 */

$REQUIRE_PERMISSION = 'print_reimbursement_approval_form';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if ($request_id <= 0) {
    pop('Invalid reimbursement request', '/reimbursement/list.php', 3000, 'error');
    exit;
}

// Fetch request details
$stmt = $pdo->prepare("
    SELECT 
        pr.*,
        b.branch_name,
        u.full_name as requestor_name,
        u.email as requestor_email
    FROM procurement_requests pr
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.request_id = ? AND pr.request_type = 'REIMBURSEMENT'
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Reimbursement request not found', '/reimbursement/list.php', 3000, 'error');
    exit;
}

// Verify authorization: requestor, HOD, Branch Head, or privileged roles
$authorizedRoles = ['Procurement Officer', 'Admin', 'SuperAdmin', 'Director HRM&A'];
$isAuthorized = (
    $_SESSION['user_id'] == $request['created_by'] ||
    in_array($_SESSION['role_name'] ?? '', $authorizedRoles)
);

if (!$isAuthorized) {
    logAudit(
        $pdo,
        'procurement_requests',
        $request_id,
        'UNAUTHORIZED_PRINT',
        'User ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
        ' attempted to print reimbursement approval form without authorization'
    );
    pop('You do not have permission to print this form', '/reimbursement/list.php', 3000, 'error');
    exit;
}

// Fetch invoices for this reimbursement
$invStmt = $pdo->prepare("
    SELECT SUM(invoice_amount) as total_amount
    FROM reimbursement_invoices
    WHERE request_id = ?
");
$invStmt->execute([$request_id]);
$invoiceData = $invStmt->fetch(PDO::FETCH_ASSOC);
$totalAmount = $invoiceData['total_amount'] ?? 0;

// Log the print event
logAudit(
    $pdo,
    'procurement_requests',
    $request_id,
    'APPROVAL_FORM_PRINTED',
    'Reimbursement approval form printed for request by ' .
    ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
    " ({$_SESSION['role_name']})"
);

// Generate PDF using Dompdf
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use Dompdf\Dompdf;

$dompdf = new Dompdf();

// Get current document control settings
$dcStmt = $pdo->prepare("SELECT * FROM doc_ctrl_settings WHERE id = 1");
$dcStmt->execute();
$docCtrl = $dcStmt->fetch(PDO::FETCH_ASSOC) ?? [];

$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #003366;
            padding-bottom: 10px;
        }
        .header-title {
            font-size: 14px;
            font-weight: bold;
            color: #003366;
        }
        .header-subtitle {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
        }
        .section-title {
            background-color: #e8f0f7;
            padding: 6px 8px;
            font-weight: bold;
            border-left: 3px solid #003366;
            margin-top: 12px;
            margin-bottom: 8px;
            font-size: 11px;
        }
        .form-group {
            margin-bottom: 8px;
        }
        .form-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        .form-col {
            display: table-cell;
            width: 50%;
            padding-right: 10px;
        }
        .form-col:last-child {
            padding-right: 0;
        }
        label {
            font-weight: bold;
            display: inline-block;
            width: 140px;
            vertical-align: top;
        }
        .value {
            display: inline-block;
            border-bottom: 1px solid #333;
            min-width: 150px;
            padding: 2px 4px;
        }
        .signature-section {
            margin-top: 20px;
            page-break-inside: avoid;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }
        .signature-table td {
            border: 1px solid #ccc;
            padding: 40px 10px 10px 10px;
            width: 50%;
            text-align: center;
            vertical-align: top;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 30px;
            font-size: 9px;
            color: #666;
        }
        .instructions {
            background-color: #fffacd;
            border: 1px solid #daa520;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 10px;
            line-height: 1.5;
        }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 9px;
            color: #666;
            text-align: center;
        }
        table { width: 100%; margin-bottom: 8px; }
        table td { padding: 3px; border-bottom: 1px solid #eee; }
        table td:first-child { font-weight: bold; width: 35%; }
        .bold { font-weight: bold; }
    </style>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        <div class="header-title">REIMBURSEMENT REQUEST - APPROVAL FORM</div>
        <div class="header-subtitle">For Official Use: Please Print, Sign, and Upload Signed Copy</div>
    </div>

    <!-- INSTRUCTIONS -->
    <div class="instructions">
        <strong>IMPORTANT INSTRUCTIONS:</strong><br>
        1. Review all information carefully<br>
        2. Print this document on official letterhead if available<br>
        3. Sign below in the designated boxes<br>
        4. Scan or photograph the signed copy<br>
        5. Upload the signed document back to the system<br>
        <strong style="color:red;">DO NOT submit with blank signature fields.</strong>
    </div>

    <!-- REQUEST INFORMATION -->
    <div class="section-title">REQUEST DETAILS</div>
    <table>
        <tr>
            <td>Request ID:</td>
            <td>REI-' . str_pad($request['request_id'], 6, '0', STR_PAD_LEFT) . '</td>
            <td>Request Date:</td>
            <td>' . date('d-M-Y', strtotime($request['request_date'])) . '</td>
        </tr>
        <tr>
            <td>Branch:</td>
            <td colspan="3">' . htmlspecialchars($request['branch_name'] ?? 'N/A') . '</td>
        </tr>
        <tr>
            <td>Requestor:</td>
            <td colspan="3">' . htmlspecialchars($request['requestor_name'] ?? 'N/A') . '</td>
        </tr>
        <tr>
            <td>Email:</td>
            <td colspan="3">' . htmlspecialchars($request['requestor_email'] ?? 'N/A') . '</td>
        </tr>
        <tr>
            <td>Description:</td>
            <td colspan="3">' . htmlspecialchars(substr($request['description'] ?? '', 0, 200)) . '</td>
        </tr>
        <tr>
            <td><strong>Total Reimbursement Amount:</strong></td>
            <td colspan="3"><strong>' . $request['currency'] . ' ' . number_format($totalAmount, 2) . '</strong></td>
        </tr>
    </table>

    <!-- APPROVAL INFORMATION -->
    <div class="section-title">AUTHORIZATION & APPROVAL</div>
    <table>
        <tr>
            <td>Status:</td>
            <td colspan="3">' . htmlspecialchars($request['status'] ?? 'DRAFT') . '</td>
        </tr>
        <tr>
            <td>Request Status:</td>
            <td colspan="3">This reimbursement request requires your signature to proceed through the approval workflow.</td>
        </tr>
    </table>

    <!-- CERTIFICATION & SIGNATURES -->
    <div class="signature-section">
        <div class="section-title">CERTIFICATION & SIGNATURES</div>
        <p style="margin-bottom: 10px;"><strong>I hereby certify that:</strong></p>
        <ul style="margin-left: 20px; margin-bottom: 10px;">
            <li>The information contained herein is true and accurate</li>
            <li>The reimbursable items and amounts comply with organizational policy</li>
            <li>All supporting documentation has been attached</li>
            <li>I authorize this reimbursement request to proceed for processing</li>
        </ul>

        <table class="signature-table">
            <tr>
                <td>
                    <strong>Requestor / Employee Signature</strong>
                    <div style="height: 40px;"></div>
                    <div class="signature-line">
                        Signature<br>
                        ' . htmlspecialchars($request['requestor_name'] ?? 'Name') . '<br>
                        Date: _______________________
                    </div>
                </td>
                <td>
                    <strong>Authorizing Officer / HOD Signature</strong>
                    <div style="height: 40px;"></div>
                    <div class="signature-line">
                        Signature<br>
                        _______________________<br>
                        Date: _______________________
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- DOCUMENT CONTROL -->
    <div class="footer">
        <strong>Document Control Information:</strong><br>
        Form Revision: ' . htmlspecialchars($docCtrl['form_revision'] ?? 'v1.0') . ' | 
        Effective Date: ' . htmlspecialchars($docCtrl['effective_date'] ?? 'N/A') . ' | 
        DCR #: ' . htmlspecialchars($docCtrl['dcr_number'] ?? 'N/A') . '<br>
        Printed on: ' . date('d-M-Y H:i:s') . ' | System: PRMS v3
    </div>
</body>
</html>
HTML;

$html = str_replace(array(
    "' . \$request['request_id'] . '",
    "' . \$request['branch_name'] . '",
    "' . \$request['requestor_name'] . '",
    "' . \$request['requestor_email'] . '",
    "' . \$request['description'] . '",
    "' . \$request['currency'] . '",
    "' . \$request['status'] . '",
    "' . \$docCtrl['form_revision'] . '",
    "' . \$docCtrl['effective_date'] . '",
    "' . \$docCtrl['dcr_number'] . '"
), array(
    htmlspecialchars($request['request_id']),
    htmlspecialchars($request['branch_name'] ?? 'N/A'),
    htmlspecialchars($request['requestor_name'] ?? 'N/A'),
    htmlspecialchars($request['requestor_email'] ?? 'N/A'),
    htmlspecialchars(substr($request['description'] ?? '', 0, 200)),
    htmlspecialchars($request['currency']),
    htmlspecialchars($request['status'] ?? 'DRAFT'),
    htmlspecialchars($docCtrl['form_revision'] ?? 'v1.0'),
    htmlspecialchars($docCtrl['effective_date'] ?? 'N/A'),
    htmlspecialchars($docCtrl['dcr_number'] ?? 'N/A')
), $html);

// Load HTML into Dompdf
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Reimbursement_Approval_Form_' . $request_id . '.pdf"');
echo $dompdf->output();
exit;
?>
