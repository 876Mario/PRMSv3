<?php
$REQUIRE_PERMISSION = 'award_vendor';

require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

$rfq_id = (int)($_GET['id'] ?? 0);

if (!$rfq_id) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.rfq_number,
           v.vendor_name, v.email
    FROM rfqs r
    JOIN rfq_quotes q ON r.awarded_quote_id = q.quote_id
    JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    pop('Award data not found', '/rfq/view.php?id='.$rfq_id, POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'].'/lib/tcpdf/tcpdf.php';

$vendorName = htmlspecialchars($data['vendor_name']);
$vendorEmail = htmlspecialchars($data['email']);
$rfqNumber  = htmlspecialchars($data['rfq_number']);
$awardDate  = date('d M Y');

// =============================
// CREATE PDF
// =============================
$pdf = new TCPDF();
$pdf->SetCreator('DGC PIAMS');
$pdf->SetAuthor('Department of Government Chemist');
$pdf->SetTitle('Letter of Award - '.$rfqNumber);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 35);

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
$pdf->Cell(0, 8, 'LETTER OF AWARD', 0, 1, 'C');

$pdf->SetDrawColor(201, 162, 39);
$pdf->SetLineWidth(0.6);
$pdf->Line(70, $pdf->GetY(), 140, $pdf->GetY());
$pdf->SetLineWidth(0.2);

$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(10);


// =============================
// DATE & REFERENCE
// =============================
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 6, 'Date: '.$awardDate, 0, 1);
$pdf->Ln(2);

// Reference card
$pdf->SetFillColor(248, 249, 250);
$refY = $pdf->GetY();
$pdf->RoundedRect($pdf->GetX(), $refY, 170, 14, 3, '1111', 'F');

$pdf->SetFillColor(11, 94, 43);
$pdf->Rect($pdf->GetX(), $refY, 2, 14, 'F');

$pdf->SetY($refY + 2);
$pdf->SetX(27);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(25, 5, 'Reference:', 0, 0);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(0, 5, 'RFQ '.$rfqNumber, 0, 1);

$pdf->SetY($refY + 18);


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
// SUBJECT LINE
// =============================
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(20, 6, 'Re:', 0, 0);
$pdf->SetFont('helvetica', 'BI', 11);
$pdf->Cell(0, 6, 'Notification of Award - RFQ '.$rfqNumber, 0, 1);
$pdf->Ln(6);


// =============================
// LETTER BODY
// =============================
$pdf->SetFont('helvetica', '', 11);

$pdf->MultiCell(0, 7,
    'Dear '.$vendorName.',',
    0
);
$pdf->Ln(4);

$pdf->MultiCell(0, 7,
    'We are pleased to inform you that, following a thorough evaluation of all responses received for the above-referenced Request for Quotation, your submission has been assessed as the most responsive and economically advantageous.',
    0
);
$pdf->Ln(3);

$pdf->MultiCell(0, 7,
    'You are hereby notified that the Department of the Government Chemist has resolved to award the referenced procurement to your organisation.',
    0
);
$pdf->Ln(3);

// Acceptance notice (highlighted)
$pdf->SetFillColor(255, 243, 205);
$noticeY = $pdf->GetY();
$pdf->RoundedRect($pdf->GetX(), $noticeY, 170, 14, 3, '1111', 'F');

$pdf->SetFillColor(201, 162, 39);
$pdf->Rect($pdf->GetX(), $noticeY, 2, 14, 'F');

$pdf->SetY($noticeY + 3);
$pdf->SetX(27);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(133, 100, 4);
$pdf->MultiCell(158, 5,
    'Please confirm your acceptance of this award in writing within three (3) business days of receipt of this letter.',
    0
);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetY($noticeY + 18);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 7,
    'A formal Purchase Order will be issued following your written confirmation. You will be expected to comply with all terms and conditions stated in the original RFQ documentation.',
    0
);
$pdf->Ln(3);

$pdf->MultiCell(0, 7,
    'We look forward to a successful engagement with your organisation.',
    0
);
$pdf->Ln(8);


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
$pdf->Cell(0, 6, 'Deputy Government Chemist', 0, 1);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(0, 5, 'Department of the Government Chemist', 0, 1);
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(12);


// =============================
// VENDOR ACCEPTANCE SECTION
// =============================
$pdf->SetFillColor(248, 249, 250);
$accY = $pdf->GetY();
$pdf->RoundedRect($pdf->GetX(), $accY, 170, 36, 3, '1111', 'F');

$pdf->SetY($accY + 3);
$pdf->SetX(25);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 6, 'Vendor Acceptance', 0, 1);

$pdf->SetX(25);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(0, 5, 'I hereby confirm acceptance of this award on behalf of the above-named organisation.', 0, 1);
$pdf->Ln(4);

$pdf->SetX(25);
$pdf->SetTextColor(173, 181, 189);
$pdf->Cell(60, 6, '______________________________', 0, 0);
$pdf->Cell(20, 6, '', 0, 0);
$pdf->Cell(60, 6, '______________', 0, 1);

$pdf->SetX(25);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(60, 4, 'Authorised Signature & Stamp', 0, 0);
$pdf->Cell(20, 4, '', 0, 0);
$pdf->Cell(60, 4, 'Date', 0, 1);

$pdf->SetTextColor(33, 37, 41);
$pdf->SetY($accY + 40);


// =============================
// FOOTER BAR WITH OFFICIALS
// =============================
$pdf->Ln(4);
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
$pdf->Cell(0, 3, $awardDate, 0, 1, 'C');


$pdf->Output('LOA_'.$rfqNumber.'.pdf', 'I');
exit;
