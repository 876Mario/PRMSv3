<?php
$REQUIRE_PERMISSION = 'create_rfq';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

/* Get RFQ ID and optional vendor filter */
$rfq_id   = (int)($_GET['id'] ?? 0);
$vendor_id = isset($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null;

if ($rfq_id <= 0) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/*
 * Build vendor list to generate letters for.
 * Priority:
 *   1. Specific vendor_id passed in query string  → single letter
 *   2. Awarded vendor (legacy behaviour)           → single letter
 *   3. All vendors on the RFQ                      → multi-page PDF
 */
$vendorRows = [];

if ($vendor_id) {
    /* Specific vendor requested */
    $stmt = $pdo->prepare("
        SELECT r.rfq_number, r.submission_deadline,
               v.vendor_name, v.email,
               pr.request_id
        FROM rfqs r
        JOIN rfq_vendors rv ON r.rfq_id = rv.rfq_id
        JOIN vendors v ON rv.vendor_id = v.vendor_id
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        WHERE r.rfq_id = ? AND rv.rfq_vendor_id = ?
    ");
    $stmt->execute([$rfq_id, $vendor_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $vendorRows[] = $row;
}

if (empty($vendorRows)) {
    /* Try awarded vendor */
    $stmt = $pdo->prepare("
        SELECT r.rfq_number, r.submission_deadline,
               v.vendor_name, v.email,
               pr.request_id
        FROM rfqs r
        JOIN rfq_quotes q ON q.quote_id = r.awarded_quote_id
        JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
        JOIN vendors v ON rv.vendor_id = v.vendor_id
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        WHERE r.rfq_id = ?
    ");
    $stmt->execute([$rfq_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $vendorRows[] = $row;
}

if (empty($vendorRows)) {
    /* Fallback: response_status = SELECTED */
    $stmt = $pdo->prepare("
        SELECT r.rfq_number, r.submission_deadline,
               v.vendor_name, v.email,
               pr.request_id
        FROM rfqs r
        JOIN rfq_vendors rv ON r.rfq_id = rv.rfq_id
        JOIN vendors v ON rv.vendor_id = v.vendor_id
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        WHERE r.rfq_id = ?
          AND rv.response_status = 'SELECTED'
    ");
    $stmt->execute([$rfq_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $vendorRows[] = $row;
}

if (empty($vendorRows) && !$vendor_id) {
    /* Final fallback: all vendors on this RFQ */
    $stmt = $pdo->prepare("
        SELECT r.rfq_number, r.submission_deadline,
               v.vendor_name, v.email,
               pr.request_id
        FROM rfqs r
        JOIN rfq_vendors rv ON r.rfq_id = rv.rfq_id
        JOIN vendors v ON rv.vendor_id = v.vendor_id
        JOIN procurement_requests pr ON r.request_id = pr.request_id
        WHERE r.rfq_id = ?
        ORDER BY v.vendor_name
    ");
    $stmt->execute([$rfq_id]);
    $vendorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($vendorRows)) {
    pop('No vendors found for this RFQ', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Use the first row for shared RFQ / request data */
$data = $vendorRows[0];

/* Fetch Items */
$stmt = $pdo->prepare("
    SELECT item_name, specification, quantity, remarks
    FROM procurement_request_items
    WHERE request_id = ?
    ORDER BY item_id ASC
");
$stmt->execute([$data['request_id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch Requestor Information */
$stmtRequestor = $pdo->prepare("
    SELECT pr.created_by, u.full_name, u.email, u.department
    FROM procurement_requests pr
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.request_id = ?
");
$stmtRequestor->execute([$data['request_id']]);
$requestorData = $stmtRequestor->fetch(PDO::FETCH_ASSOC);
$requestorName = $requestorData ? htmlspecialchars($requestorData['full_name'] ?? 'N/A') : 'N/A';
$requestorDept = $requestorData ? htmlspecialchars($requestorData['department'] ?? '') : '';

require_once $_SERVER['DOCUMENT_ROOT'].'/lib/tcpdf/tcpdf.php';

$rfqNumber   = htmlspecialchars($data['rfq_number']);
$deadline    = date('d M Y', strtotime($data['submission_deadline']));

// =============================
// CREATE PDF
// =============================
$pdf = new TCPDF();
$pdf->SetCreator('DGC PIAMS');
$pdf->SetAuthor('Department of Government Chemist');
$pdf->SetTitle('RFQ - '.$rfqNumber);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 35);

// =============================
// GENERATE ONE PAGE PER VENDOR
// =============================
foreach ($vendorRows as $vendorRow):

$vendorName  = htmlspecialchars($vendorRow['vendor_name']);
$vendorEmail = htmlspecialchars($vendorRow['email']);

$pdf->AddPage();


// =============================
// BRANDED HEADER BAR WITH LOGOS
// =============================
$pdf->SetY(2);

// Logo paths
$mohLogo = $_SERVER['DOCUMENT_ROOT'] . '/logo/JAMAICA-2.png';
$dgcLogo = $_SERVER['DOCUMENT_ROOT'] . '/logo/cropped-Logo.png';

// Left MOH logo
if (file_exists($mohLogo)) {
    $pdf->Image($mohLogo, 12, 3, 22, 16, 'PNG');
}

// Center text header
$pdf->SetX(36);
$pdf->SetY(4);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(98, 4, 'MINISTRY OF HEALTH & WELLNESS', 0, 1, 'C');

$pdf->SetX(36);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(98, 4, 'DEPARTMENT OF GOVERNMENT CHEMIST', 0, 1, 'C');

// Right DGC logo
if (file_exists($dgcLogo)) {
    $pdf->Image($dgcLogo, 168, 2, 22, 18, 'PNG');
}

// Contact details below
$pdf->SetY(21);
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(52, 135, 67);
$pdf->Cell(0, 2.5, 'Address: Hope Complex, Hope Gardens, Kingston 6, Jamaica', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 6.5);
$pdf->SetTextColor(52, 135, 67);
$pdf->Cell(0, 2.5, 'Tel: 876-927-1829/30, 876-977-4066  |  Email: governmentchemist@flowja.com  |  Website: governmentchemist.com', 0, 1, 'C');

// Red dividing line
$pdf->SetDrawColor(201, 30, 30);
$pdf->SetLineWidth(0.5);
$pdf->Line(12, $pdf->GetY() + 1, 198, $pdf->GetY() + 1);
$pdf->Ln(3);

// Reference notice
$pdf->SetFont('helvetica', '', 6);
$pdf->SetTextColor(201, 30, 30);
$pdf->MultiCell(0, 2, 'ANY REPLY OR SUBSEQUENT REFERENCE TO THIS COMMUNICATION SHOULD BE ADDRESSED TO THE DEPARTMENT GOVERNMENT CHEMIST AND NOT TO ANY OFFICER BY NAME AND THE FOLLOWING REFERENCE NO', 0, 'C');

$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(2);


// =============================
// DOCUMENT TITLE
// =============================
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(11, 94, 43);
$pdf->Cell(0, 8, 'REQUEST FOR QUOTATION', 0, 1, 'C');

$pdf->SetDrawColor(201, 162, 39);
$pdf->SetLineWidth(0.6);
$pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(10);


// =============================
// REFERENCE & DATE
// =============================
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Date: '.date('d M Y'), 0, 1);
$pdf->Ln(2);

// Reference card
$pdf->SetFillColor(248, 249, 250);
$refY = $pdf->GetY();
$pdf->RoundedRect($pdf->GetX(), $refY, 170, 20, 3, '1111', 'F');

$pdf->SetFillColor(11, 94, 43);
$pdf->Rect($pdf->GetX(), $refY, 2, 20, 'F');

$pdf->SetY($refY + 2);
$pdf->SetX(27);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(30, 5, 'RFQ Number:', 0, 0);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(0, 5, $rfqNumber, 0, 1);

$pdf->SetX(27);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(30, 5, 'Deadline:', 0, 0);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(201, 30, 30);
$pdf->Cell(0, 5, $deadline, 0, 1);

$pdf->SetTextColor(33, 37, 41);
$pdf->SetY($refY + 24);


// =============================
// RECIPIENT
// =============================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 6, 'To:', 0, 1);

$pdf->SetFont('helvetica', '', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(0, 6, $vendorName, 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(0, 6, $vendorEmail, 0, 1);
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(6);


// =============================
// INVITATION TEXT
// =============================
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 7,
    'Dear '.$vendorName.',',
    0
);
$pdf->Ln(3);

$pdf->MultiCell(0, 7,
    'You are hereby invited to submit a quotation for the supply of the items listed below. Please ensure your response is received by the submission deadline indicated above.',
    0
);
$pdf->Ln(6);


// =============================
// ITEMS TABLE
// =============================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 8, 'Requested Items', 0, 1);

$itemHtml = '
<style>
  th { background-color: #0b5e2b; color: #ffffff; font-weight: bold; padding: 8px; }
  td { padding: 8px; border-bottom: 1px solid #e9ecef; }
</style>
<table cellpadding="6" border="1" width="100%">
  <thead>
    <tr>
      <th width="8%" align="center">#</th>
      <th width="32%">Item</th>
      <th width="28%">Specification</th>
      <th width="10%" align="center">Qty</th>
      <th width="22%">Remarks</th>
    </tr>
  </thead>
  <tbody>';

if (empty($items)) {
    $itemHtml .= '
    <tr>
        <td colspan="5" align="center" style="color:#6c757d;">No request items were found for this RFQ.</td>
    </tr>';
} else {
    foreach ($items as $idx => $item) {
        $bg = ($idx % 2 === 0) ? '#ffffff' : '#f8f9fa';
        $itemHtml .= '
        <tr style="background-color:'.$bg.';">
            <td align="center" style="color:#6c757d;">'.($idx + 1).'</td>
            <td>'.htmlspecialchars($item['item_name']).'</td>
            <td style="color:#6c757d;">'.htmlspecialchars($item['specification'] ?? '—').'</td>
            <td align="center"><b>'.htmlspecialchars((string)$item['quantity']).'</b></td>
            <td style="color:#6c757d;">'.htmlspecialchars($item['remarks'] ?? '—').'</td>
        </tr>';
    }
}

$itemHtml .= '</tbody></table>';

$pdf->SetTextColor(33, 37, 41);
$pdf->writeHTML($itemHtml, true, false, true, false, '');
$pdf->Ln(6);


// =============================
// TERMS & CONDITIONS
// =============================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 8, 'Terms & Conditions', 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(33, 37, 41);

$terms = [
    'Quotations must remain valid for a minimum of thirty (30) calendar days from the submission deadline.',
    'General Consumption Tax (GCT) must be stated separately from the unit price.',
    'Delivery timelines and any applicable warranties should be clearly indicated.',
    'The Department reserves the right to accept or reject any or all quotations without assigning reasons.',
    'Late submissions will not be considered.',
];

foreach ($terms as $i => $term) {
    $pdf->SetX(25);
    $bullet = ($i + 1).'.';
    $pdf->Cell(10, 6, $bullet, 0, 0);
    $pdf->MultiCell(145, 6, $term, 0);
    $pdf->Ln(1);
}

$pdf->Ln(6);


// =============================
// REQUESTOR AUTHORIZATION SECTION
// =============================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(0, 7, 'REQUESTOR AUTHORIZATION', 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(2);

// Requestor Name Line
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(35, 5, 'Requestor Name:', 0, 0);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Cell(65, 5, '', 'B', 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->Cell(10, 5, '', 0, 0);

// Requestor Title
$pdf->Cell(30, 5, 'Title:', 0, 0);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Cell(0, 5, '', 'B', 1);
$pdf->SetDrawColor(0, 0, 0);

// Signature Line
$pdf->Cell(35, 5, 'Signature:', 0, 0);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Cell(65, 5, '', 'B', 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->Cell(10, 5, '', 0, 0);

// Date Signed
$pdf->Cell(30, 5, 'Date:', 0, 0);
$pdf->SetDrawColor(200, 200, 200);
$pdf->Cell(0, 5, '', 'B', 1);
$pdf->SetDrawColor(0, 0, 0);

$pdf->Ln(4);


// =============================
// CLOSING & SIGNATURE
// =============================
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Yours faithfully,', 0, 1);
$pdf->Ln(16);

$pdf->SetTextColor(173, 181, 189);
$pdf->Cell(80, 0, '', 'B', 1);
$pdf->Ln(2);

$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(0, 6, 'Director, Public Procurement', 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(0, 5, 'Department of the Government Chemist', 0, 1);
$pdf->SetTextColor(33, 37, 41);


// =============================
// FOOTER BAR WITH OFFICIALS
// =============================
$pdf->Ln(10);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(4);

$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(33, 37, 41);
$pdf->MultiCell(0, 3, 'Minister of Health & Wellness- Dr. the Hon. Christopher Tufton, MP   Minister of State – The Hon. Krystal Lee, MP', 0, 'C');
$pdf->MultiCell(0, 3, 'Permanent Secretary- Mr. Errol C. Greene, OD, JP   Permanent Secretary [Special Assignment]- Mr. Dunstan E. Bryan, CD', 0, 'C');
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(0, 4, 'Government Chemist- Mrs. Yanique A. Fraser MSc, BSc.', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 7);
$pdf->SetTextColor(108, 117, 125);
$pdf->Ln(2);
$pdf->Cell(0, 3, date('d M Y'), 0, 1, 'C');

endforeach; // end vendor loop

$pdf->Output('RFQ_'.$rfqNumber.'.pdf', 'I');
exit;
