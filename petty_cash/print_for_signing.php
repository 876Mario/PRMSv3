<?php
/**
 * Petty Cash Print for Signing
 * Generates a printable PDF approval form for petty cash requests
 * User signs this form and uploads it back for processing
 */

$REQUIRE_PERMISSION = 'print_petty_cash_approval_form';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if ($request_id <= 0) {
    pop('Invalid petty cash request', '/petty_cash/list.php', 3000, 'error');
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
    WHERE pr.request_id = ? AND pr.request_type = 'PETTY_CASH'
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    pop('Petty cash request not found', '/petty_cash/list.php', 3000, 'error');
    exit;
}

// Verify authorization
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
        ' attempted to print petty cash approval form without authorization'
    );
    pop('You do not have permission to print this form', '/petty_cash/list.php', 3000, 'error');
    exit;
}

// Log the print event
logAudit(
    $pdo,
    'procurement_requests',
    $request_id,
    'APPROVAL_FORM_PRINTED',
    'Petty cash approval form printed by ' .
    ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
    " ({$_SESSION['role_name']})"
);

// Generate PDF using Dompdf
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use Dompdf\Dompdf;

$options = new \Dompdf\Options();
$options->setDefaultFont('DejaVu Sans');
$dompdf = new Dompdf($options);

// Get current document control settings
$dcStmt = $pdo->prepare("SELECT * FROM doc_ctrl_settings WHERE id = 1");
$dcStmt->execute();
$docCtrl = $dcStmt->fetch(PDO::FETCH_ASSOC) ?? [];

// Pre-compute all dynamic values before building HTML
$tplRequestId     = 'PC-' . str_pad((string)$request['request_id'], 6, '0', STR_PAD_LEFT);
$tplRequestDate   = date('d-M-Y', strtotime($request['request_date']));
$tplBranch        = htmlspecialchars($request['branch_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplRequestor     = htmlspecialchars($request['requestor_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplEmail         = htmlspecialchars($request['requestor_email'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplDescription   = htmlspecialchars(mb_substr($request['description'] ?? '', 0, 200), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplCurrency      = htmlspecialchars($request['currency'] ?? 'JMD', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplAmount        = number_format((float)($request['estimated_value'] ?? 0), 2);
$tplStatus        = htmlspecialchars($request['status'] ?? 'DRAFT', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplFormRevision  = htmlspecialchars($docCtrl['form_revision'] ?? 'v1.0', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplEffectiveDate = htmlspecialchars($docCtrl['effective_date'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplDcrNumber     = htmlspecialchars($docCtrl['dcr_number'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tplPrintedOn     = date('d-M-Y H:i:s');
$tplReconcileDays = htmlspecialchars((string)(isset($_SESSION['petty_cash_reconcile_days']) ? (int)$_SESSION['petty_cash_reconcile_days'] : 7), ENT_QUOTES | ENT_HTML5, 'UTF-8');

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
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
            background-color: #f0e8f7;
            padding: 6px 8px;
            font-weight: bold;
            border-left: 3px solid #7d3c98;
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
        <div class="header-title">PETTY CASH REQUEST - APPROVAL FORM</div>
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
            <td>{$tplRequestId}</td>
            <td>Request Date:</td>
            <td>{$tplRequestDate}</td>
        </tr>
        <tr>
            <td>Branch:</td>
            <td colspan="3">{$tplBranch}</td>
        </tr>
        <tr>
            <td>Requestor:</td>
            <td colspan="3">{$tplRequestor}</td>
        </tr>
        <tr>
            <td>Email:</td>
            <td colspan="3">{$tplEmail}</td>
        </tr>
        <tr>
            <td>Description/Purpose:</td>
            <td colspan="3">{$tplDescription}</td>
        </tr>
        <tr>
            <td><strong>Petty Cash Amount:</strong></td>
            <td colspan="3"><strong>{$tplCurrency} {$tplAmount}</strong></td>
        </tr>
    </table>

    <!-- AUTHORIZATION INFORMATION -->
    <div class="section-title">AUTHORIZATION DETAILS</div>
    <table>
        <tr>
            <td>Status:</td>
            <td colspan="3">{$tplStatus}</td>
        </tr>
        <tr>
            <td>Request Status:</td>
            <td colspan="3">This petty cash request requires your signature to proceed for disbursal.</td>
        </tr>
    </table>

    <!-- TERMS & CONDITIONS -->
    <div class="section-title">TERMS &amp; CONDITIONS</div>
    <p style="margin-left: 10px; margin-bottom: 8px;">
        The authorized officer certifies that:
    </p>
    <ul style="margin-left: 30px; margin-bottom: 10px; font-size: 10px;">
        <li>The petty cash will be used for approved business expenses only</li>
        <li>Proper receipts/documentation will be retained for reconciliation</li>
        <li>The amount is reasonable for the stated purpose</li>
        <li>The recipient(s) have been identified and confirmed</li>
    </ul>

    <!-- CERTIFICATION & SIGNATURES -->
    <div class="signature-section">
        <div class="section-title">AUTHORIZATION &amp; SIGNATURES</div>
        <p style="margin-bottom: 10px;"><strong>Authorization and Certification:</strong></p>
        <p style="margin-left: 10px; margin-bottom: 10px; font-size: 10px;">
            I hereby authorize and approve the disbursal of the petty cash amount shown above,
            subject to compliance with organizational petty cash policies and procedures.
        </p>

        <table class="signature-table">
            <tr>
                <td>
                    <strong>Requestor / Recipient Signature</strong>
                    <div style="height: 40px;"></div>
                    <div class="signature-line">
                        Signature<br>
                        {$tplRequestor}<br>
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

    <!-- RECONCILIATION NOTICE -->
    <div style="background-color: #e8f5e9; border: 1px solid #4caf50; padding: 8px; margin-top: 15px; font-size: 9px;">
        <strong>RECONCILIATION REQUIREMENT:</strong> All petty cash disbursals must be reconciled within
        {$tplReconcileDays} days with supporting documentation.
    </div>

    <!-- DOCUMENT CONTROL -->
    <div class="footer">
        <strong>Document Control Information:</strong><br>
        Form Revision: {$tplFormRevision} |
        Effective Date: {$tplEffectiveDate} |
        DCR #: {$tplDcrNumber}<br>
        Printed on: {$tplPrintedOn} | System: PIAMS v3
    </div>
</body>
</html>
HTML;

// Load HTML into Dompdf
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Petty_Cash_Approval_Form_' . $request_id . '.pdf"');
echo $dompdf->output();
exit;
?>
