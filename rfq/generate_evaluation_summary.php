<?php
$REQUIRE_PERMISSION = 'view_rfq_evaluations';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';

require_once $_SERVER['DOCUMENT_ROOT'].'/lib/tcpdf/tcpdf.php';


$rfq_id = (int)($_GET['id'] ?? 0);
if (!$rfq_id) {
    pop('Invalid RFQ', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}


// =============================
// FETCH RFQ DATA
// =============================
$stmt = $pdo->prepare("
    SELECT r.rfq_number, pr.request_number
    FROM rfqs r
    JOIN procurement_requests pr ON r.request_id = pr.request_id
    WHERE r.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$rfq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rfq) {
    pop('RFQ not found', '/rfq/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}


// =============================
// FETCH SCORING RESULTS
// =============================

// First try rfq_scores table (formal scoring)
$stmt = $pdo->prepare("
    SELECT 
        v.vendor_name,
        SUM(s.total_score) as total_score
    FROM rfq_scores s
    JOIN rfq_vendors rv ON s.rfq_vendor_id = rv.rfq_vendor_id
    JOIN vendors v ON rv.vendor_id = v.vendor_id
    WHERE s.rfq_id = ?
    GROUP BY rv.rfq_vendor_id
    ORDER BY total_score DESC
");
$stmt->execute([$rfq_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no formal scores, build results from votes + quotes
$usedVoteData = false;
if (empty($results)) {
    $usedVoteData = true;
    $stmt = $pdo->prepare("
        SELECT 
            v.vendor_name,
            rv.rfq_vendor_id,
            COALESCE(q.quote_amount, 0) as quote_amount,
            COALESCE(q.gct_amount, 0) as gct_amount,
            COALESCE(q.review_status, 'PENDING') as review_status,
            COUNT(DISTINCT votes.vote_id) as vote_count
        FROM rfq_vendors rv
        JOIN vendors v ON rv.vendor_id = v.vendor_id
        LEFT JOIN rfq_quotes q ON q.rfq_vendor_id = rv.rfq_vendor_id
        LEFT JOIN rfq_votes votes ON votes.rfq_vendor_id = rv.rfq_vendor_id AND votes.rfq_id = rv.rfq_id
        WHERE rv.rfq_id = ?
        GROUP BY rv.rfq_vendor_id, v.vendor_name, q.quote_amount, q.gct_amount, q.review_status
        ORDER BY vote_count DESC, q.quote_amount ASC
    ");
    $stmt->execute([$rfq_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate a composite score: votes weight + price competitiveness
    if (!empty($results)) {
        $maxVotes = max(array_column($results, 'vote_count') ?: [1]);
        $maxVotes = $maxVotes > 0 ? $maxVotes : 1;
        $amounts = array_filter(array_column($results, 'quote_amount'), fn($a) => $a > 0);
        $minAmount = !empty($amounts) ? min($amounts) : 1;
        
        foreach ($results as &$row) {
            $voteScore = ($row['vote_count'] / $maxVotes) * 60; // 60% weight to votes
            $priceScore = ($row['quote_amount'] > 0 && $minAmount > 0) 
                ? ($minAmount / $row['quote_amount']) * 40  // 40% weight to price 
                : 0;
            $row['total_score'] = round($voteScore + $priceScore, 2);
        }
        unset($row);
        
        // Re-sort by total_score descending
        usort($results, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
    }
}

$winner = $results[0] ?? null;


// =============================
// FETCH COMMITTEE MEMBERS
// =============================
$stmt = $pdo->prepare("
    SELECT u.full_name
    FROM rfq_evaluation_committee ec
    JOIN users u ON ec.user_id = u.user_id
    WHERE ec.rfq_id = ?
");
$stmt->execute([$rfq_id]);
$committee = $stmt->fetchAll(PDO::FETCH_ASSOC);


// =============================
// CREATE PDF
// =============================
$pdf = new TCPDF();
$pdf->SetCreator('DGC PIAMS');
$pdf->SetAuthor('Department of Government Chemist');
$pdf->SetTitle('Evaluation Summary Report');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);

$pdf->AddPage();


// =============================
// BRANDED HEADER BAR
// =============================
$pdf->SetFillColor(11, 94, 43);
$pdf->Rect(0, 0, 210, 18, 'F');

// Gold accent line
$pdf->SetFillColor(201, 162, 39);
$pdf->Rect(0, 18, 210, 2, 'F');

$pdf->SetY(4);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 6, 'DEPARTMENT OF THE GOVERNMENT CHEMIST', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 5, 'Procurement Request Management System', 0, 1, 'C');

$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(10);


// =============================
// REPORT TITLE + STATUS BADGE
// =============================
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 8, 'Tender Evaluation Summary Report', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(0, 5, 'Official evaluation scoring and recommendation document', 0, 1, 'C');
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(6);


// =============================
// RFQ DETAILS CARD
// =============================
$pdf->SetFillColor(248, 249, 250);
$pdf->RoundedRect($pdf->GetX(), $pdf->GetY(), 180, 28, 3, '1111', 'F');

// Green left border
$pdf->SetFillColor(11, 94, 43);
$pdf->Rect($pdf->GetX(), $pdf->GetY(), 2, 28, 'F');

$startY = $pdf->GetY() + 4;
$pdf->SetY($startY);
$pdf->SetX(22);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(35, 6, 'RFQ Number:', 0, 0);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(60, 6, $rfq['rfq_number'], 0, 0);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(35, 6, 'Date:', 0, 0);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 6, date('d M Y'), 0, 1);

$pdf->SetX(22);
$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(35, 6, 'Request Number:', 0, 0);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(60, 6, $rfq['request_number'], 0, 0);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(108, 117, 125);
$pdf->Cell(35, 6, 'Vendors Evaluated:', 0, 0);
$pdf->SetTextColor(33, 37, 41);
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(40, 6, count($results), 0, 1);

$pdf->SetY($startY + 24);
$pdf->Ln(4);


// =============================
// SCORE TABLE
// =============================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 8, $usedVoteData ? 'Vendor Evaluation Results (Vote & Quote Based)' : 'Vendor Scoring Results', 0, 1);

if (!empty($results)) {
    $maxScore = (float)($results[0]['total_score'] ?? 1);
    if ($maxScore <= 0) $maxScore = 1;

    if ($usedVoteData) {
        // Vote-based table with quote amounts and vote counts
        $scoreHtml = '
        <style>
          th { background-color: #0b5e2b; color: #ffffff; font-weight: bold; padding: 8px; }
          td { padding: 8px; border-bottom: 1px solid #e9ecef; }
        </style>
        <table cellpadding="6" border="0" width="100%">
          <tr>
            <th width="6%" align="center">#</th>
            <th width="28%">Vendor</th>
            <th width="18%" align="center">Quote ($)</th>
            <th width="14%" align="center">Votes</th>
            <th width="14%" align="center">Review</th>
            <th width="10%" align="center">Score</th>
            <th width="10%" align="center">Rating</th>
          </tr>';
        
        foreach ($results as $idx => $row) {
            $rank = $idx + 1;
            $bg = ($idx % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $isWinner = ($idx === 0 && $row['total_score'] > 0);
            $highlight = $isWinner ? ' style="background-color:#d1e7dd;font-weight:bold;"' : ' style="background-color:'.$bg.';"';
            $badge = $isWinner ? ' ★' : '';
            $score = number_format($row['total_score'], 2);
            $pct = $maxScore > 0 ? round(($row['total_score'] / $maxScore) * 100) : 0;
            $amount = $row['quote_amount'] > 0 ? number_format($row['quote_amount'], 2) : '—';
            $votes = (int)($row['vote_count'] ?? 0);
            $review = match($row['review_status'] ?? 'PENDING') {
                'MEETS_REQUIREMENTS' => 'Approved',
                'DOES_NOT_MEET' => 'Rejected',
                default => 'Pending',
            };
            $reviewColor = match($row['review_status'] ?? 'PENDING') {
                'MEETS_REQUIREMENTS' => '#198754',
                'DOES_NOT_MEET' => '#dc3545',
                default => '#6c757d',
            };
            
            $scoreHtml .= '
            <tr'.$highlight.'>
                <td align="center" style="color:#6c757d;">'.$rank.'</td>
                <td>'.htmlspecialchars($row['vendor_name']).$badge.'</td>
                <td align="center">$'.$amount.'</td>
                <td align="center">'.$votes.'</td>
                <td align="center" style="color:'.$reviewColor.';">'.$review.'</td>
                <td align="center">'.$score.'</td>
                <td align="center">'.$pct.'%</td>
            </tr>';
        }
    } else {
        // Original formal scoring table
        $scoreHtml = '
        <style>
          th { background-color: #0b5e2b; color: #ffffff; font-weight: bold; padding: 8px; }
          td { padding: 8px; border-bottom: 1px solid #e9ecef; }
        </style>
        <table cellpadding="6" border="0" width="100%">
          <tr>
            <th width="8%" align="center">#</th>
            <th width="47%">Vendor</th>
            <th width="20%" align="center">Total Score</th>
            <th width="25%" align="center">Rating</th>
          </tr>';
        
        foreach ($results as $idx => $row) {
            $rank = $idx + 1;
            $bg = ($idx % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $isWinner = ($winner && $row['vendor_name'] === $winner['vendor_name']);
            $highlight = $isWinner ? ' style="background-color:#d1e7dd;font-weight:bold;"' : ' style="background-color:'.$bg.';"';
            $badge = $isWinner ? ' ★' : '';
            $score = number_format($row['total_score'], 2);
            $pct = $maxScore > 0 ? round(($row['total_score'] / $maxScore) * 100) : 0;
            
            $scoreHtml .= '
            <tr'.$highlight.'>
                <td align="center" style="color:#6c757d;">'.$rank.'</td>
                <td>'.htmlspecialchars($row['vendor_name']).$badge.'</td>
                <td align="center">'.$score.'</td>
                <td align="center">'.$pct.'%</td>
            </tr>';
        }
    }
    
    $scoreHtml .= '</table>';
    
    $pdf->SetTextColor(33, 37, 41);
    $pdf->writeHTML($scoreHtml, true, false, true, false, '');
    
    if ($usedVoteData) {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(0, 5, 'Score = 60% committee votes + 40% price competitiveness. Formal scoring table (rfq_scores) was not populated.', 0, 1);
        $pdf->SetTextColor(33, 37, 41);
    }
} else {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 8, 'No vendors have been evaluated for this RFQ yet.', 0, 1);
    $pdf->SetTextColor(33, 37, 41);
}
$pdf->Ln(6);


// =============================
// WINNER / RECOMMENDATION
// =============================
if ($winner) {
    $pdf->SetFillColor(209, 231, 221);
    $recY = $pdf->GetY();
    $pdf->RoundedRect($pdf->GetX(), $recY, 180, 20, 3, '1111', 'F');

    $pdf->SetY($recY + 3);
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(21, 87, 36);
    $pdf->Cell(0, 6, 'RECOMMENDED VENDOR', 0, 1);

    $pdf->SetX(20);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(33, 37, 41);
    
    if ($usedVoteData) {
        $voteInfo = isset($winner['vote_count']) ? $winner['vote_count'].' committee vote(s)' : '';
        $quoteInfo = isset($winner['quote_amount']) && $winner['quote_amount'] > 0 ? ', quote: $'.number_format($winner['quote_amount'], 2) : '';
        $pdf->MultiCell(170, 5,
            htmlspecialchars($winner['vendor_name']).' received the most committee support ('.$voteInfo.$quoteInfo.') with a composite score of '.number_format($winner['total_score'], 2).' and is hereby recommended for award.',
            0
        );
    } else {
        $pdf->MultiCell(170, 5,
            htmlspecialchars($winner['vendor_name']).' achieved the highest evaluated score ('.number_format($winner['total_score'], 2).') and is hereby recommended for award.',
            0
        );
    }

    $pdf->SetY($recY + 24);
    $pdf->Ln(4);
}


// =============================
// COMMITTEE SIGNATURES
// =============================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 8, 'Evaluation Committee Signatures', 0, 1);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(33, 37, 41);

foreach ($committee as $member) {
    $pdf->Cell(70, 6, htmlspecialchars($member['full_name']), 0, 0);
    $pdf->SetTextColor(173, 181, 189);
    $pdf->Cell(70, 6, '______________________________', 0, 0);
    $pdf->SetTextColor(173, 181, 189);
    $pdf->Cell(40, 6, '______________', 0, 1);
    $pdf->SetTextColor(33, 37, 41);
}

// Labels row
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(173, 181, 189);
$pdf->Cell(70, 4, 'Name', 0, 0);
$pdf->Cell(70, 4, 'Signature', 0, 0);
$pdf->Cell(40, 4, 'Date', 0, 1);
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(8);


// =============================
// APPROVAL SECTION
// =============================
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 8, 'Approval by Deputy Government Chemist', 0, 1);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(33, 37, 41);
$pdf->Cell(70, 6, 'Deputy Government Chemist', 0, 0);
$pdf->SetTextColor(173, 181, 189);
$pdf->Cell(70, 6, '______________________________', 0, 0);
$pdf->Cell(40, 6, '______________', 0, 1);

$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(70, 4, '', 0, 0);
$pdf->Cell(70, 4, 'Signature', 0, 0);
$pdf->Cell(40, 4, 'Date', 0, 1);
$pdf->SetTextColor(33, 37, 41);
$pdf->Ln(10);


// =============================
// COMPLIANCE CERTIFICATION
// =============================
$pdf->SetFillColor(248, 249, 250);
$certY = $pdf->GetY();
$pdf->RoundedRect($pdf->GetX(), $certY, 180, 24, 3, '1111', 'F');

$pdf->SetY($certY + 3);
$pdf->SetX(20);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetTextColor(26, 26, 46);
$pdf->Cell(0, 6, 'Compliance Certification', 0, 1);

$pdf->SetX(20);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(108, 117, 125);
$pdf->MultiCell(168, 5,
    "We certify that this evaluation was conducted in accordance with the Department of Government Chemist Procurement Standard Operating Procedures and Government of Jamaica Procurement Guidelines.",
    0
);

$pdf->SetY($certY + 28);


// =============================
// FOOTER BAR
// =============================
$pdf->Ln(6);
$pdf->SetDrawColor(233, 236, 239);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(4);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(173, 181, 189);
$pdf->Cell(0, 4, date('d M Y').' | Department of the Government Chemist | Confidential | PIAMS', 0, 1, 'C');


$pdf->Output('Evaluation_Summary_'.$rfq['rfq_number'].'.pdf', 'I');
exit;
