<?php
$REQUIRE_PERMISSION = 'print_purchase_order';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/print.php";
require_once __DIR__."/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$po_id = require_print_id('po_id');

$stmt = $pdo->prepare("
    SELECT po.*, c.commitment_number,
        pr.currency,
        CASE
            WHEN (
                SELECT COUNT(*) FROM request_approvals ra
                WHERE ra.entity_type = 'PO'
                  AND ra.entity_id = po.po_id
                  AND ra.status = 'pending'
            ) = 0
            THEN 1
            ELSE 0
        END AS fully_approved
    FROM purchase_orders po
    JOIN commitments c ON po.commitment_id = c.commitment_id
    JOIN procurement_requests pr ON c.request_id = pr.request_id
    WHERE po.po_id = ?
");
$stmt->execute([$po_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) exit("PO not found");
if ((int)$r['fully_approved'] !== 1) {
    exit("PO must be fully approved before printing.");
}

/* Fetch advance payments for this PO */
$apStmt = $pdo->prepare("
    SELECT ap.payment_type, ap.payment_amount, ap.payment_date,
           ap.payment_reference, ap.payment_method, ap.supplier_name,
           ap.notes, ap.status,
           uc.full_name AS created_by_name,
           ua.full_name AS approved_by_name,
           ap.approved_at
    FROM po_advance_payments ap
    LEFT JOIN users uc ON ap.created_by  = uc.user_id
    LEFT JOIN users ua ON ap.approved_by = ua.user_id
    WHERE ap.po_id = ?
    ORDER BY ap.payment_date ASC
");
$apStmt->execute([$po_id]);
$advancePaymentsPrint = $apStmt->fetchAll(PDO::FETCH_ASSOC);
$totalAdvancePrint = array_sum(array_column(
    array_filter($advancePaymentsPrint, fn($a) => $a['status'] === 'APPROVED'),
    'payment_amount'
));

/* Build advance payments table HTML for PDF injection */
$poCurrency = (!empty($r['currency'])) ? strtoupper(trim($r['currency'])) : 'JMD';
$advancePaymentsHtml = '';
if (!empty($advancePaymentsPrint)) {
    $apRows = '';
    foreach ($advancePaymentsPrint as $ap) {
        $typeLabel = match ($ap['payment_type']) {
            'ADVANCE_PAYMENT' => 'Advance',
            'PARTIAL_PAYMENT' => 'Partial',
            'FINAL_PAYMENT'   => 'Final',
            default           => htmlspecialchars($ap['payment_type']),
        };
        $statusColor = match ($ap['status']) {
            'APPROVED'         => '#198754',
            'REJECTED'         => '#dc3545',
            'CANCELLED'        => '#6c757d',
            default            => '#e0a800',
        };
        $amt     = $poCurrency . ' ' . number_format((float)$ap['payment_amount'], 2);
        $date    = date('d M Y', strtotime($ap['payment_date']));
        $ref     = htmlspecialchars($ap['payment_reference']);
        $method  = htmlspecialchars($ap['payment_method'] ?? '—');
        $supplier = htmlspecialchars($ap['supplier_name'] ?? '—');
        $approvedBy = htmlspecialchars($ap['approved_by_name'] ?? '—');
        $approvedAt = $ap['approved_at'] ? date('d M Y', strtotime($ap['approved_at'])) : '—';
        $status  = htmlspecialchars($ap['status']);
        $apRows .= <<<ROW
<tr>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$date}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$typeLabel}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$ref}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;text-align:right;font-weight:600;">{$amt}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$method}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$supplier}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;font-weight:bold;color:{$statusColor};">{$status}</td>
  <td style="padding:6px 8px;border-bottom:1px solid #dee2e6;font-size:11px;">{$approvedBy}</td>
</tr>
ROW;
    }
    $fmtAdvTotal = $poCurrency . ' ' . number_format((float)$totalAdvancePrint, 2);
    $advancePaymentsHtml = <<<ADV
<div style="padding:0 24px 16px;">
  <h4 style="font-size:13px;color:#1a1a2e;margin:0 0 10px;font-weight:700;">Advance / Partial Payment History</h4>
  <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;">
    <thead>
      <tr style="background:#0b5e2b;color:#fff;">
        <th style="padding:8px;font-size:11px;text-align:left;">Date</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Type</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Reference</th>
        <th style="padding:8px;font-size:11px;text-align:right;">Amount</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Method</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Supplier</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Status</th>
        <th style="padding:8px;font-size:11px;text-align:left;">Approved By</th>
      </tr>
    </thead>
    <tbody>
      {$apRows}
      <tr style="background:#f8f9fa;">
        <td colspan="3" style="padding:8px;font-size:11px;font-weight:bold;">Total Approved Advance Payments</td>
        <td style="padding:8px;font-size:11px;font-weight:bold;text-align:right;color:#0b5e2b;">{$fmtAdvTotal}</td>
        <td colspan="4"></td>
      </tr>
    </tbody>
  </table>
</div>
ADV;
}
$fmtTotal   = $poCurrency . ' ' . number_format((float)$r['po_total'], 2);
$poNum      = htmlspecialchars($r['po_number']);
$commitNum  = htmlspecialchars($r['commitment_number']);
$poDate     = date('d M Y', strtotime($r['po_date']));
$status     = htmlspecialchars($r['status']);
$genDate    = date('d M Y');
$genTime    = date('g:i A');

// Status styling
$statusMap = [
    'APPROVED'  => ['#198754', '#d1e7dd'],
    'PENDING'   => ['#e0a800', '#fff3cd'],
    'CANCELLED' => ['#dc3545', '#f8d7da'],
];
$sColor = $statusMap[$status][0] ?? '#6c757d';
$sBg    = $statusMap[$status][1] ?? '#e9ecef';

// Vendor info (if available)
$vendorName = htmlspecialchars($r['vendor_name'] ?? '');
$poType     = htmlspecialchars($r['po_type'] ?? 'ORIGINAL');

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

<!-- Title + Status -->
<div style="padding:20px 24px 4px;">
  <table width="100%">
    <tr>
      <td>
        <h2 style="margin:0 0 2px;font-size:22px;color:#1a1a2e;">PURCHASE ORDER</h2>
        <span style="font-size:11px;color:#6c757d;">Official Purchase Order Document</span>
      </td>
      <td style="text-align:right;vertical-align:top;">
        <span style="display:inline-block;background:{$sBg};color:{$sColor};font-size:11px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.5px;">
          {$status}
        </span>
      </td>
    </tr>
  </table>
</div>

<!-- PO Details -->
<div style="padding:16px 24px;">
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="50%" style="vertical-align:top;">
        <div style="background:#f8f9fa;border-radius:10px;padding:16px;border-left:4px solid #0b5e2b;">
          <table cellspacing="0" cellpadding="5" style="font-size:12px;width:100%;">
            <tr>
              <td style="color:#6c757d;width:40%;">PO Number</td>
              <td style="font-weight:700;font-size:14px;">{$poNum}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">PO Date</td>
              <td style="font-weight:600;">{$poDate}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">PO Type</td>
              <td>{$poType}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">Commitment #</td>
              <td style="font-weight:600;">{$commitNum}</td>
            </tr>
            <tr>
              <td style="color:#6c757d;">Vendor</td>
              <td style="font-weight:600;">{$vendorName}</td>
            </tr>
          </table>
        </div>
      </td>
      <td width="10%"></td>
      <td width="40%" style="vertical-align:top;">
        <div style="background:#e8f5e9;border-radius:10px;padding:20px;text-align:center;">
          <span style="font-size:10px;text-transform:uppercase;color:#6c757d;font-weight:600;letter-spacing:1px;">Purchase Order Total</span><br>
          <span style="font-size:28px;font-weight:700;color:#0b5e2b;letter-spacing:0.5px;">{$fmtTotal}</span>
        </div>
        <div style="margin-top:10px;background:#f8f9fa;border-radius:10px;padding:12px;text-align:center;">
          <span style="font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:600;">Finance Approved</span><br>
          <span style="font-size:14px;font-weight:700;color:#198754;">YES</span>
        </div>
      </td>
    </tr>
  </table>
</div>

<!-- Divider -->
<div style="padding:0 24px;">
  <hr style="border:none;border-top:2px solid #e9ecef;margin:8px 0 20px;">
</div>

{$advancePaymentsHtml}

<!-- Authorization Section -->
<div style="padding:0 24px;">
  <h4 style="font-size:13px;color:#1a1a2e;margin:0 0 16px;font-weight:700;">Authorization</h4>
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="45%" style="border-bottom:1px solid #212529;padding-bottom:40px;"></td>
      <td width="10%"></td>
      <td width="45%" style="border-bottom:1px solid #212529;padding-bottom:40px;"></td>
    </tr>
    <tr>
      <td style="padding-top:6px;">
        <span style="font-size:11px;color:#6c757d;">Procurement Officer</span><br>
        <span style="font-size:10px;color:#adb5bd;">Signature / Date</span>
      </td>
      <td></td>
      <td style="padding-top:6px;">
        <span style="font-size:11px;color:#6c757d;">Finance Officer</span><br>
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
$pdf->stream("purchase_order_{$po_id}.pdf", ["Attachment" => true]);
exit;
