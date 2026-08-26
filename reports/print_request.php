<?php
$REQUIRE_PERMISSION = 'print_request';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/print.php";
require_once __DIR__."/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$request_id = require_print_id('request_id');

$stmt = $pdo->prepare("
    SELECT pr.*, 
           u1.full_name AS created_by_name,
           u2.full_name AS approved_by_name
    FROM procurement_requests pr
    LEFT JOIN users u1 ON pr.created_by = u1.user_id
    LEFT JOIN users u2 ON pr.approved_by = u2.user_id
    WHERE pr.request_id = ?
");
$stmt->execute([$request_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) exit("Request not found");

// Pre-format values
$reqNum      = htmlspecialchars($r['request_number']);
$reqDate     = date('d M Y', strtotime($r['request_date']));
$status      = htmlspecialchars($r['status']);
$createdBy   = htmlspecialchars($r['created_by_name'] ?? 'N/A');
$approvedBy  = htmlspecialchars($r['approved_by_name'] ?? '—');
$approvedAt  = $r['approved_at'] ? formatJamaicanDateTime($r['approved_at']) : '—';
$description = htmlspecialchars($r['description'] ?? '');
$currency    = normalizeCurrency($r['currency'] ?? 'JMD');
$currSymbol  = $currency === 'USD' ? 'US$' : '$';
$estValue    = $currency . ' ' . $currSymbol . number_format((float)($r['estimated_value'] ?? 0), 2);
$usdRate     = (float)($r['usd_rate'] ?? 0);
$usdNote     = '';
if ($currency === 'USD' && $usdRate > 0) {
    $jmdEquiv = number_format((float)($r['estimated_value'] ?? 0) * $usdRate, 2);
    $usdNote = "<br><span style='font-size:12px;color:#6c757d;'>(≈ JMD \${$jmdEquiv} @ rate {$usdRate})</span>";
}
$method      = htmlspecialchars($r['procurement_method'] ?? 'N/A');
$genDate     = date('d M Y');
$genTime     = date('g:i A');

// Status styling — uses uppercase workflow stages
$statusMap = [
    'DRAFT'                 => ['#6c757d', '#e9ecef'],
    'SUBMITTED'             => ['#0d6efd', '#cfe2ff'],
    'HOD_APPROVED'          => ['#0dcaf0', '#cff4fc'],
    'FUNDS_VERIFIED'        => ['#0d6efd', '#cfe2ff'],
    'PROCUREMENT_STAGE'     => ['#343a40', '#e9ecef'],
    'EVALUATION_STAGE'      => ['#6610f2', '#e2d9f3'],
    'COMMITTEE_RECOMMENDED' => ['#0dcaf0', '#cff4fc'],
    'GC_APPROVED'           => ['#198754', '#d1e7dd'],
    'AWARDED'               => ['#198754', '#d1e7dd'],
    'COMPLETED'             => ['#198754', '#d1e7dd'],
    'DECLINED'              => ['#dc3545', '#f8d7da'],
];
$statusKey = strtoupper($status);
$sColor = $statusMap[$statusKey][0] ?? '#6c757d';
$sBg    = $statusMap[$statusKey][1] ?? '#e9ecef';

// Watermark for declined
$watermark = '';
if (strtoupper($r['status']) === 'DECLINED') {
    $watermark = "<div style='position:fixed;top:38%;left:12%;font-size:80px;color:#dc3545;opacity:0.08;transform:rotate(-30deg);font-weight:900;letter-spacing:8px;'>DECLINED</div>";
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    color: #212529;
    font-size: 12px;
    margin: 0;
    padding: 0;
  }
</style>
</head>
<body>

{$watermark}

<!-- Header Bar -->
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:14px 24px;color:#fff;">
  <table width="100%">
    <tr>
      <td>
        <span style="font-size:16px;font-weight:700;letter-spacing:0.5px;">Department of the Government Chemist</span><br>
        <span style="font-size:10px;opacity:0.85;">Procurement Request Management System</span>
      </td>
      <td style="text-align:right;">
        <span style="font-size:10px;">Generated: {$genDate} at {$genTime}</span>
      </td>
    </tr>
  </table>
</div>

<!-- Title + Status -->
<div style="padding:20px 24px 4px;">
  <table width="100%">
    <tr>
      <td>
        <h2 style="margin:0 0 2px;font-size:22px;color:#1a1a2e;">PROCUREMENT REQUEST</h2>
        <span style="font-size:11px;color:#6c757d;">Official Procurement Request Document</span>
      </td>
      <td style="text-align:right;vertical-align:top;">
        <span style="display:inline-block;background:{$sBg};color:{$sColor};font-size:11px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.5px;">
          {$status}
        </span>
      </td>
    </tr>
  </table>
</div>

<!-- Request Details -->
<div style="padding:16px 24px;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="55%" style="vertical-align:top;">
        <div style="background:#f8f9fa;border-radius:10px;padding:16px;border-left:4px solid #0b5e2b;">
          <table cellspacing="0" cellpadding="5" style="font-size:12px;width:100%;">
            <tr>
              <td style="color:#6c757d;width:38%;">Request Number</td>
              <td style="font-weight:700;font-size:14px;">{$reqNum}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">Request Date</td>
              <td style="font-weight:600;">{$reqDate}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">Procurement Method</td>
              <td>{$method}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">Requested By</td>
              <td style="font-weight:600;">{$createdBy}</td>
            </tr>
          </table>
        </div>
      </td>
      <td width="5%"></td>
      <td width="40%" style="vertical-align:top;">
        <div style="background:#e8f5e9;border-radius:10px;padding:20px;text-align:center;">
          <span style="font-size:10px;text-transform:uppercase;color:#6c757d;font-weight:600;letter-spacing:1px;">Estimated Value</span><br>
          <span style="font-size:28px;font-weight:700;color:#0b5e2b;letter-spacing:0.5px;">{$estValue}</span>{$usdNote}
        </div>
        <div style="margin-top:10px;background:#f8f9fa;border-radius:10px;padding:12px;text-align:center;">
          <span style="font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:600;">Current Status</span><br>
          <span style="font-size:14px;font-weight:700;color:{$sColor};">{$status}</span>
        </div>
      </td>
    </tr>
  </table>
</div>

<!-- Description -->
<div style="padding:0 24px 16px;">
  <div style="background:#f8f9fa;border-radius:10px;padding:16px;">
    <h4 style="font-size:12px;color:#6c757d;text-transform:uppercase;margin:0 0 8px;font-weight:600;letter-spacing:0.5px;">Description</h4>
    <p style="margin:0;font-size:12px;line-height:1.6;color:#212529;">{$description}</p>
  </div>
</div>

<!-- Divider -->
<div style="padding:0 24px;">
  <hr style="border:none;border-top:2px solid #e9ecef;margin:4px 0 16px;">
</div>

<!-- Approval Info -->
<div style="padding:0 24px 16px;">
  <h4 style="font-size:13px;color:#1a1a2e;margin:0 0 12px;font-weight:700;">Approval Details</h4>
  <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
    <thead>
      <tr style="background:#0b5e2b;">
        <th style="padding:8px 12px;color:#fff;text-align:left;font-size:10px;">Field</th>
        <th style="padding:8px 12px;color:#fff;text-align:left;font-size:10px;">Details</th>
      </tr>
    </thead>
    <tbody>
      <tr style="background:#ffffff;">
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;color:#6c757d;">Approved By</td>
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;">{$approvedBy}</td>
      </tr>
      <tr style="background:#f8f9fa;">
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;color:#6c757d;">Approved At</td>
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;">{$approvedAt}</td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Authorization Section -->
<div style="padding:16px 24px 0;">
  <h4 style="font-size:13px;color:#1a1a2e;margin:0 0 16px;font-weight:700;">Authorization</h4>
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="45%" style="border-bottom:1px solid #212529;padding-bottom:40px;"></td>
      <td width="10%"></td>
      <td width="45%" style="border-bottom:1px solid #212529;padding-bottom:40px;"></td>
    </tr>
    <tr>
      <td style="padding-top:6px;">
        <span style="font-size:11px;color:#6c757d;">HOD / Technical Approver</span><br>
        <span style="font-size:10px;color:#adb5bd;">Signature / Date</span>
      </td>
      <td></td>
      <td style="padding-top:6px;">
        <span style="font-size:11px;color:#6c757d;">Deputy Government Chemist</span><br>
        <span style="font-size:10px;color:#adb5bd;">Signature / Date</span>
      </td>
    </tr>
  </table>
</div>

<!-- Footer -->
<div style="padding:28px 24px 12px;text-align:center;color:#adb5bd;font-size:9px;border-top:1px solid #e9ecef;margin-top:36px;">
  &copy; {$genDate} Department of the Government Chemist &middot; Confidential &middot; PIAMS
</div>

</body>
</html>
HTML;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'Helvetica');

$pdf = new Dompdf($options);
$pdf->loadHtml($html);
$pdf->setPaper('A4');
$pdf->render();
$pdf->stream("procurement_request_{$request_id}.pdf", ["Attachment" => true]);
exit;
