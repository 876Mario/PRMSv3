<?php
$REQUIRE_PERMISSION = 'print_invoice';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/print.php";
require_once __DIR__."/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$invoice_id = require_print_id('invoice_id');

/* Invoice */
$stmt = $pdo->prepare("
    SELECT i.*, po.po_number
    FROM invoices i
    JOIN purchase_orders po ON i.po_id = po.po_id
    WHERE i.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$i = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$i) exit("Invoice not found");

/* Payments */
$pstmt = $pdo->prepare("SELECT * FROM payments WHERE invoice_id = ?");
$pstmt->execute([$invoice_id]);
$payments = $pstmt->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($payments, 'payment_amount'));
$balance   = $i['invoice_amount'] - $totalPaid;
$paidPct   = $i['invoice_amount'] > 0 ? round(($totalPaid / $i['invoice_amount']) * 100) : 0;

$fmtAmount  = '$' . number_format($i['invoice_amount'], 2);
$fmtPaid    = '$' . number_format($totalPaid, 2);
$fmtBalance = '$' . number_format($balance, 2);
$invDate    = date('d M Y', strtotime($i['invoice_date']));
$genDate    = date('d M Y');
$genTime    = date('g:i A');

$statusLabel = $balance <= 0 ? 'PAID IN FULL' : 'BALANCE DUE';
$statusColor = $balance <= 0 ? '#198754' : '#dc3545';
$statusBg    = $balance <= 0 ? '#d1e7dd' : '#f8d7da';

/* Payment rows */
$paymentRows = '';
foreach ($payments as $idx => $p) {
    $bg   = ($idx % 2 === 0) ? '#ffffff' : '#f8f9fa';
    $num  = $idx + 1;
    $date = date('d M Y', strtotime($p['payment_date']));
    $ref  = htmlspecialchars($p['payment_reference']);
    $amt  = '$' . number_format($p['payment_amount'], 2);
    $paymentRows .= <<<ROW
      <tr style="background:{$bg};">
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;">{$num}</td>
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{$date}</td>
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{$ref}</td>
        <td style="padding:8px 12px;border-bottom:1px solid #e9ecef;text-align:right;font-weight:600;color:#198754;">{$amt}</td>
      </tr>
ROW;
}

if (empty($payments)) {
    $paymentRows = '<tr><td colspan="4" style="padding:16px;text-align:center;color:#6c757d;">No payments recorded</td></tr>';
}

$invNum = htmlspecialchars($i['invoice_number']);
$poNum  = htmlspecialchars($i['po_number'] ?? '');
$payCount = count($payments);

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

<!-- Invoice Title + Status -->
<div style="padding:20px 24px 4px;">
  <table width="100%">
    <tr>
      <td>
        <h2 style="margin:0 0 2px;font-size:22px;color:#1a1a2e;">INVOICE</h2>
        <span style="font-size:11px;color:#6c757d;">Tax Invoice / Payment Record</span>
      </td>
      <td style="text-align:right;vertical-align:top;">
        <span style="display:inline-block;background:{$statusBg};color:{$statusColor};font-size:11px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.5px;">
          {$statusLabel}
        </span>
      </td>
    </tr>
  </table>
</div>

<!-- Invoice Details -->
<div style="padding:16px 24px;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="50%" style="vertical-align:top;">
        <table cellspacing="0" cellpadding="4" style="font-size:12px;">
          <tr>
            <td style="color:#6c757d;padding-right:12px;">Invoice #</td>
            <td style="font-weight:700;">{$invNum}</td>
          </tr>
          <tr>
            <td style="color:#6c757d;padding-right:12px;">PO #</td>
            <td style="font-weight:600;">{$poNum}</td>
          </tr>
          <tr>
            <td style="color:#6c757d;padding-right:12px;">Invoice Date</td>
            <td>{$invDate}</td>
          </tr>
        </table>
      </td>
      <td width="50%" style="vertical-align:top;">
        <table width="100%" cellspacing="0" cellpadding="0">
          <tr>
            <td width="33%" style="padding:0 4px 0 0;">
              <div style="background:#e8f5e9;border-radius:8px;padding:10px 12px;text-align:center;">
                <span style="font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:600;">Invoice Amount</span><br>
                <span style="font-size:16px;font-weight:700;color:#212529;">{$fmtAmount}</span>
              </div>
            </td>
            <td width="33%" style="padding:0 4px;">
              <div style="background:#e3f2fd;border-radius:8px;padding:10px 12px;text-align:center;">
                <span style="font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:600;">Total Paid</span><br>
                <span style="font-size:16px;font-weight:700;color:#198754;">{$fmtPaid}</span>
              </div>
            </td>
            <td width="33%" style="padding:0 0 0 4px;">
              <div style="background:{$statusBg};border-radius:8px;padding:10px 12px;text-align:center;">
                <span style="font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:600;">Balance</span><br>
                <span style="font-size:16px;font-weight:700;color:{$statusColor};">{$fmtBalance}</span>
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</div>

<!-- Progress Bar -->
<div style="padding:0 24px 16px;">
  <div style="background:#e9ecef;border-radius:8px;height:12px;overflow:hidden;">
    <div style="background:#198754;height:100%;width:{$paidPct}%;border-radius:8px;"></div>
  </div>
  <div style="text-align:right;font-size:10px;color:#6c757d;margin-top:2px;">{$paidPct}% paid</div>
</div>

<!-- Payments Table -->
<div style="padding:0 24px;">
  <h4 style="font-size:13px;color:#1a1a2e;margin:0 0 8px;font-weight:700;">Payment History ({$payCount})</h4>
  <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
    <thead>
      <tr style="background:#0b5e2b;">
        <th style="padding:8px 12px;color:#fff;text-align:center;font-size:10px;width:6%;">#</th>
        <th style="padding:8px 12px;color:#fff;text-align:left;font-size:10px;">Date</th>
        <th style="padding:8px 12px;color:#fff;text-align:left;font-size:10px;">Reference</th>
        <th style="padding:8px 12px;color:#fff;text-align:right;font-size:10px;">Amount</th>
      </tr>
    </thead>
    <tbody>
      {$paymentRows}
    </tbody>
    <tfoot>
      <tr style="background:#e9ecef;font-weight:700;">
        <td style="padding:8px 12px;" colspan="3">Total Paid</td>
        <td style="padding:8px 12px;text-align:right;color:#198754;">{$fmtPaid}</td>
      </tr>
      <tr style="background:#f8f9fa;font-weight:700;">
        <td style="padding:8px 12px;" colspan="3">Balance Due</td>
        <td style="padding:8px 12px;text-align:right;color:{$statusColor};">{$fmtBalance}</td>
      </tr>
    </tfoot>
  </table>
</div>

<!-- Footer -->
<div style="padding:24px 24px 12px;text-align:center;color:#adb5bd;font-size:9px;border-top:1px solid #e9ecef;margin-top:28px;">
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
$pdf->stream("invoice_{$invoice_id}.pdf", ["Attachment" => true]);
exit;
