<?php
/**
 * Print Procurement Request for Signing by Branch Head
 * Generates a clean PDF document that branch heads can print, sign, and return
 */
$REQUIRE_PERMISSION = 'print_procurement_approval_form';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once __DIR__ . '/print_for_signing_helpers.php';

// Check if Dompdf library is installed
$autoloadPath = __DIR__."/../vendor/autoload.php";
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    exit('Error: Required dependencies are not installed. Please contact the system administrator to run "composer install".');
}

require_once $autoloadPath;

use Dompdf\Dompdf;
use Dompdf\Options;

function logPrintForSigning(string $message, array $context = []): void
{
    $suffix = '';
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $suffix = $encoded !== false ? " | {$encoded}" : '';
    }
    error_log("[print_for_signing] {$message}{$suffix}");
}

// Get request ID from GET parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit('Invalid request ID');
}

$request_id = (int)$_GET['id'];
logPrintForSigning('Print request received', ['request_id' => $request_id]);

try {
    $typeStmt = $pdo->prepare("
        SELECT request_type
        FROM procurement_requests
        WHERE request_id = ?
        LIMIT 1
    ");
    $typeStmt->execute([$request_id]);
    $requestType = strtoupper((string)($typeStmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    logPrintForSigning('Failed to detect request type', [
        'request_id' => $request_id,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    exit('Unable to generate print document right now. Please try again later.');
}

if ($requestType === '') {
    http_response_code(404);
    exit('Request not found');
}

logPrintForSigning('Request type detected', ['request_id' => $request_id, 'request_type' => $requestType]);

if ($requestType !== 'REGULAR') {
    $module = requestTypeToModule($requestType);
    $targetPath = '/' . $module . '/print_for_signing.php';
    $targetFile = $_SERVER['DOCUMENT_ROOT'] . $targetPath;

    if ($module !== '' && is_file($targetFile)) {
        if (realpath($targetFile) !== realpath(__FILE__)) {
            logPrintForSigning('Routing to request-type print endpoint', [
                'request_id' => $request_id,
                'request_type' => $requestType,
                'target' => $targetPath
            ]);
            header('Location: ' . $targetPath . '?' . http_build_query([
                'request_id' => $request_id,
                'id' => $request_id
            ]));
            exit;
        }

        logPrintForSigning('Current endpoint handles request type directly', [
            'request_id' => $request_id,
            'request_type' => $requestType,
            'target' => $targetPath
        ]);
    } else {
        logPrintForSigning('No request-type endpoint found; failing closed', [
            'request_id' => $request_id,
            'request_type' => $requestType,
            'expected_target' => $targetPath
        ]);
        http_response_code(404);
        exit('Print endpoint is not configured for this request type.');
    }
}

// Fetch request details
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
        WHERE pr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    logPrintForSigning('Failed to fetch request details', [
        'request_id' => $request_id,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    exit('Unable to load request details for printing right now.');
}

if (!$r) {
    http_response_code(404);
    exit('Request not found');
}

$requestType = strtoupper((string)($r['request_type'] ?? $requestType));

// ── Document Control Settings ────────────────────────────────────────────────
// If the request already has a stored snapshot, use it (historical integrity).
// Otherwise fetch the current admin settings, validate them, and persist a snapshot.
$docCtrlFormRevision  = $r['doc_ctrl_form_revision']  ?? null;
$docCtrlEffectiveDate = $r['doc_ctrl_effective_date']  ?? null;
$docCtrlDcrNumber     = $r['doc_ctrl_dcr_number']      ?? null;

if (empty($docCtrlFormRevision) && empty($docCtrlEffectiveDate) && empty($docCtrlDcrNumber)) {
    // No snapshot yet – fetch current settings
    $dc = loadDocControlSettings($pdo, $requestType);

    $missing = [];
    if (empty($dc['form_revision']))  $missing[] = '<strong>Form Revision</strong>';
    if (empty($dc['effective_date'])) $missing[] = '<strong>Effective Date</strong>';
    if (empty($dc['dcr_number']))     $missing[] = '<strong>DCR Number</strong>';

    if (!empty($missing)) {
        http_response_code(422);
        exit('Cannot generate Print Request for Signing: the following Document Control field(s) have not been configured by an administrator: '
            . implode(', ', $missing)
            . '. Please ask an Administrator to set these values in Admin &rsaquo; Settings &rsaquo; Document Control Settings.');
    }

    // Persist snapshot so future views of this request show the same values
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
    } catch (Throwable $e) {
        logPrintForSigning('Failed to persist doc-control snapshot', [
            'request_id' => $request_id,
            'request_type' => $requestType,
            'error' => $e->getMessage()
        ]);
        // Non-fatal – continue generating the PDF even if snapshot save fails
    }

    $docCtrlFormRevision  = $dc['form_revision'];
    $docCtrlEffectiveDate = $dc['effective_date'];
    $docCtrlDcrNumber     = $dc['dcr_number'];
}

$docCtrlEffectiveDateFmt = !empty($docCtrlEffectiveDate)
    ? date('d M Y', strtotime($docCtrlEffectiveDate))
    : '';


// Fetch request items
try {
    $itemStmt = $pdo->prepare("
        SELECT item_name, specification, quantity, remarks
        FROM procurement_request_items
        WHERE request_id = ?
        ORDER BY item_id ASC
    ");
    $itemStmt->execute([$request_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    logPrintForSigning('Failed to fetch request items', [
        'request_id' => $request_id,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    exit('Unable to load request items for printing right now.');
}

// Pre-format values
try {
    $requestNumber = (string)($r['request_number'] ?? '');
    if ($requestNumber === '') {
        $requestNumber = 'REQ-' . str_pad((string)$request_id, 6, '0', STR_PAD_LEFT);
    }
    $reqNum = htmlspecialchars($requestNumber);
    $reqDate = !empty($r['request_date']) ? date('d M Y', strtotime((string)$r['request_date'])) : 'N/A';
    $branchName = htmlspecialchars($r['branch_name'] ?? 'N/A');
    $createdBy = htmlspecialchars($r['created_by_name'] ?? 'N/A');
    $description = htmlspecialchars($r['description'] ?? '');
    $currency = normalizeCurrency($r['currency'] ?? 'JMD');
    $currSymbol = $currency === 'USD' ? 'US$' : '$';
    $estValue = $currency . ' ' . $currSymbol . number_format((float)($r['estimated_value'] ?? 0), 2);
    $procMethod = htmlspecialchars($r['procurement_method'] ?? 'N/A');
    $genDate = date('d M Y');
    $genTime = date('g:i A');
    $dcFormRevisionHtml  = htmlspecialchars($docCtrlFormRevision ?? '');
    $dcEffectiveDateHtml = htmlspecialchars($docCtrlEffectiveDateFmt);
    $dcDcrNumberHtml     = htmlspecialchars($docCtrlDcrNumber ?? '');
} catch (Throwable $e) {
    logPrintForSigning('Failed to prepare template values', [
        'request_id' => $request_id,
        'request_type' => $requestType,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    exit('Unable to prepare print data right now.');
}

// Build items list HTML
$itemsHtml = '';
if (!empty($items)) {
    $itemsHtml = '<table class="items-table" width="100%" cellspacing="0" cellpadding="0">';
    $itemsHtml .= '<thead><tr style="background:#0b5e2b;"><th style="padding:8px;color:#fff;text-align:left;font-size:11px;">Item Name</th><th style="padding:8px;color:#fff;text-align:left;font-size:11px;">Specification</th><th style="padding:8px;color:#fff;text-align:center;font-size:11px;">Qty</th><th style="padding:8px;color:#fff;text-align:left;font-size:11px;">Remarks</th></tr></thead>';
    $itemsHtml .= '<tbody>';
    foreach ($items as $idx => $item) {
        $bgColor = ($idx % 2 === 0) ? '#ffffff' : '#f8f9fa';
        $itemName = htmlspecialchars($item['item_name'] ?? '');
        $spec = htmlspecialchars($item['specification'] ?? '');
        $qty = htmlspecialchars($item['quantity'] ?? '');
        $remarks = htmlspecialchars($item['remarks'] ?? '');
        $itemsHtml .= "<tr style='background:{$bgColor};'>";
        $itemsHtml .= "<td style='padding:8px;border-bottom:1px solid #e9ecef;font-size:11px;'>$itemName</td>";
        $itemsHtml .= "<td style='padding:8px;border-bottom:1px solid #e9ecef;font-size:10px;'>$spec</td>";
        $itemsHtml .= "<td style='padding:8px;border-bottom:1px solid #e9ecef;font-size:11px;text-align:center;'>$qty</td>";
        $itemsHtml .= "<td style='padding:8px;border-bottom:1px solid #e9ecef;font-size:10px;'>$remarks</td>";
        $itemsHtml .= '</tr>';
    }
    $itemsHtml .= '</tbody></table>';
} else {
    $itemsHtml = '<p style="color:#6c757d;font-size:11px;">No items listed</p>';
}

// HTML for PDF
$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    color: #212529;
    font-size: 11px;
    margin: 0;
    padding: 0;
  }
  .page-break { page-break-after: always; }
  /* Allow long item listings to flow onto additional pages
     without truncating rows; repeat the header on each page */
  table.items-table { page-break-inside: auto; border-collapse: collapse; margin-top: 8px; width: 100%; }
  table.items-table thead { display: table-header-group; }
  table.items-table tr { page-break-inside: avoid; page-break-after: auto; }
  table.items-table td { word-wrap: break-word; }
  .signature-section { page-break-inside: avoid; }
</style>
</head>
<body>

<!-- Header Bar -->
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:12px 20px;color:#fff;margin-bottom:4px;">
  <table width="100%">
    <tr>
      <td>
        <span style="font-size:14px;font-weight:700;">Department of the Government Chemist</span><br>
        <span style="font-size:9px;opacity:0.85;">Procurement Request Management System</span>
      </td>
      <td style="text-align:right;font-size:9px;">
        $genDate at $genTime
      </td>
    </tr>
  </table>
</div>

<!-- Title -->
<div style="padding:14px 20px 8px;border-bottom:2px solid #0b5e2b;">
  <h2 style="margin:0;font-size:18px;color:#0b5e2b;">PROCUREMENT REQUEST FOR APPROVAL</h2>
  <p style="margin:4px 0 0;font-size:9px;color:#6c757d;">Please print this document, review carefully, sign below, and upload the signed copy.</p>
</div>

<!-- Document Control Box -->
<div style="padding:8px 20px;background:#fff8e1;border-bottom:2px solid #c9a227;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td style="font-size:8px;text-transform:uppercase;color:#7b5e00;font-weight:700;letter-spacing:0.5px;padding-bottom:4px;" colspan="3">
        Document Control Information
      </td>
    </tr>
    <tr>
      <td width="33%" style="font-size:10px;padding:3px 8px 3px 0;">
        <span style="color:#6c757d;font-weight:600;">Form Revision:</span>&nbsp;
        <strong style="color:#333;">$dcFormRevisionHtml</strong>
      </td>
      <td width="33%" style="font-size:10px;padding:3px 8px;">
        <span style="color:#6c757d;font-weight:600;">Effective Date:</span>&nbsp;
        <strong style="color:#333;">$dcEffectiveDateHtml</strong>
      </td>
      <td width="34%" style="font-size:10px;padding:3px 0 3px 8px;">
        <span style="color:#6c757d;font-weight:600;">DCR Number:</span>&nbsp;
        <strong style="color:#333;">$dcDcrNumberHtml</strong>
      </td>
    </tr>
  </table>
</div>

<!-- Request Information Section -->
<div style="padding:14px 20px;background:#f8f9fa;margin-bottom:4px;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="50%">
        <table cellspacing="0" cellpadding="3" style="font-size:10px;width:100%;">
          <tr>
            <td style="color:#6c757d;font-weight:600;width:40%;">Request #:</td>
            <td style="font-weight:700;font-size:12px;">$reqNum</td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;">Request Date:</td>
            <td style="font-weight:600;">$reqDate</td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;">Branch:</td>
            <td style="font-weight:600;">$branchName</td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;">Requested By:</td>
            <td style="font-weight:600;">$createdBy</td>
          </tr>
        </table>
      </td>
      <td width="50%">
        <div style="background:#e8f5e9;border-radius:8px;padding:12px;text-align:center;margin-left:8px;">
          <span style="font-size:8px;text-transform:uppercase;color:#2e7d32;font-weight:700;letter-spacing:0.5px;">Estimated Value</span><br>
          <span style="font-size:16px;font-weight:700;color:#0b5e2b;">$estValue</span>
        </div>
      </td>
    </tr>
  </table>
</div>

<!-- Description Section -->
<div style="padding:10px 20px;">
  <h4 style="font-size:11px;color:#0b5e2b;margin:0 0 6px;font-weight:700;text-transform:uppercase;">Description / Purpose</h4>
  <div style="background:#f8f9fa;padding:10px;border-left:3px solid #0b5e2b;border-radius:4px;font-size:10px;line-height:1.5;">
    $description
  </div>
</div>

<!-- Items Section -->
<div style="padding:10px 20px;">
  <h4 style="font-size:11px;color:#0b5e2b;margin:0 0 6px;font-weight:700;text-transform:uppercase;">Request Items</h4>
  $itemsHtml
</div>

<!-- Additional Details Section -->
<div style="padding:10px 20px;background:#f8f9fa;margin:6px 0;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="50%">
        <h4 style="font-size:10px;color:#6c757d;margin:0 0 4px;font-weight:700;">Procurement Method</h4>
        <p style="margin:0;font-size:10px;font-weight:600;">$procMethod</p>
      </td>
      <td width="50%">
        <h4 style="font-size:10px;color:#6c757d;margin:0 0 4px;font-weight:700;">Currency</h4>
        <p style="margin:0;font-size:10px;font-weight:600;">$currency</p>
      </td>
    </tr>
  </table>
</div>

<!-- Signature Section -->
<div class="signature-section" style="padding:20px 20px;margin-top:20px;border-top:2px solid #e9ecef;">
  <h4 style="font-size:11px;color:#0b5e2b;margin:0 0 20px;font-weight:700;text-transform:uppercase;">Authorization By Branch Head</h4>
  
  <!-- Signature Block -->
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="60%">
        <table cellspacing="0" cellpadding="0" style="font-size:9px;width:100%;">
          <tr>
            <td style="border-bottom:2px solid #212529;height:50px;"></td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;padding-top:2px;">Signature</td>
          </tr>
          <tr>
            <td style="height:34px;"></td>
          </tr>
          <tr>
            <td style="border-bottom:2px solid #212529;height:0;"></td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;padding-top:2px;">Printed Name</td>
          </tr>
          <tr>
            <td style="height:34px;"></td>
          </tr>
          <tr>
            <td style="border-bottom:2px solid #212529;height:0;"></td>
          </tr>
          <tr>
            <td style="color:#6c757d;font-weight:600;padding-top:2px;">Date</td>
          </tr>
        </table>
      </td>
      <td width="5%"></td>
      <td width="35%" style="vertical-align:top;">
        <div style="background:#fff3cd;border-left:3px solid #ffc107;padding:8px;border-radius:4px;font-size:9px;">
          <strong>Important:</strong> By signing below, you confirm that you have reviewed this procurement request and approve its proceed to procurement processing.
        </div>
      </td>
    </tr>
  </table>
</div>


<!-- Requestor Signature Section -->
<div class="signature-section" style="padding:20px 20px;margin-top:10px;border-top:2px solid #e9ecef;">
  <h4 style="font-size:11px;color:#0b5e2b;margin:0 0 20px;font-weight:700;text-transform:uppercase;">Requestor Signature</h4>

  <table cellspacing="0" cellpadding="0" style="font-size:9px;width:60%;">
    <tr>
      <td style="color:#6c757d;font-weight:600;width:30%;padding-bottom:2px;">Requestor Name:</td>
      <td style="border-bottom:2px solid #212529;font-weight:700;font-size:11px;">$createdBy</td>
    </tr>
    <tr>
      <td style="height:34px;"></td>
      <td style="height:34px;"></td>
    </tr>
    <tr>
      <td style="color:#6c757d;font-weight:600;padding-bottom:2px;">Signature:</td>
      <td style="border-bottom:2px solid #212529;"></td>
    </tr>
    <tr>
      <td style="height:34px;"></td>
      <td style="height:34px;"></td>
    </tr>
    <tr>
      <td style="color:#6c757d;font-weight:600;padding-bottom:2px;">Date:</td>
      <td style="border-bottom:2px solid #212529;"></td>
    </tr>
  </table>
</div>

<!-- Instructions -->
<div style="padding:10px 20px;background:#e3f2fd;border-left:3px solid #2196f3;margin-top:10px;border-radius:4px;">
  <h4 style="margin:0 0 4px;font-size:10px;color:#1565c0;font-weight:700;">NEXT STEPS:</h4>
  <ol style="margin:0;padding-left:16px;font-size:9px;line-height:1.6;">
    <li>Print this document</li>
    <li>Review the request details carefully</li>
    <li>Sign and date in the "Authorization by Branch Head" section</li>
    <li>Have the Requestor sign and date in the "Requestor Signature" section</li>
    <li>Scan or photograph the signed document</li>
    <li>Upload the signed copy via the system</li>
    <li>Procurement will then review and proceed with processing</li>
  </ol>
</div>

<!-- Footer -->
<div style="padding:10px 20px;text-align:center;color:#adb5bd;font-size:8px;border-top:1px solid #e9ecef;margin-top:20px;">
  Department of the Government Chemist &middot; PIAMS &middot; Confidential &middot; $genDate
</div>

</body>
</html>
HTML;

// Generate PDF
try {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');

    $pdf = new Dompdf($options);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();
    $pdf->stream("procurement_request_{$request_id}_for_signing.pdf", ["Attachment" => false]);
} catch (Throwable $e) {
    logPrintForSigning('PDF generation failed', [
        'request_id' => $request_id,
        'request_type' => $requestType,
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    exit('Unable to generate PDF right now. Please try again later.');
}
exit;