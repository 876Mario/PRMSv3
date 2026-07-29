<?php
use Dompdf\Dompdf;
use Dompdf\Options;

session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pdfHeader(string $title, string $subtitle): string
{
    $date = date('d M Y');
    $time = date('g:i A');
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:11px;margin:0;padding:0;}
table{border-collapse:collapse;width:100%;}
th{background:#0b5e2b;color:#fff;padding:7px 8px;text-align:left;font-size:10px;}
td{padding:6px 8px;border-bottom:1px solid #e9ecef;font-size:10px;}
tr:nth-child(even) td{background:#f8f9fa;}
.text-right{text-align:right;}
.text-center{text-align:center;}
.badge{display:inline-block;padding:2px 6px;border-radius:3px;font-size:9px;}
</style></head><body>
<div style="background:linear-gradient(90deg,#0b5e2b,#c9a227);padding:14px 20px;color:#fff;">
<table><tr>
<td><span style="font-size:17px;font-weight:700;">Government Chemist - PRMS</span><br>
<span style="font-size:10px;opacity:0.85;">Procurement &amp; Resource Management System</span></td>
<td style="text-align:right;font-size:10px;">Generated: {$date} at {$time}</td>
</tr></table></div>
<div style="padding:14px 20px 8px;">
<h2 style="margin:0 0 3px;font-size:17px;color:#1a1a2e;">{$title}</h2>
<p style="margin:0;color:#6c757d;font-size:10px;">{$subtitle}</p>
</div>
<div style="padding:0 20px;">
HTML;
}

function pdfFooter(): string
{
    $year = date('Y');
    return <<<HTML
</div>
<div style="padding:14px 20px 10px;text-align:center;color:#adb5bd;font-size:9px;border-top:1px solid #e9ecef;margin-top:16px;">
&copy; {$year} Government Chemist &middot; Confidential &middot; PRMS
</div></body></html>
HTML;
}

function streamPdf(string $html, string $filename): void
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => false]);
}

// ─── Report dispatch ──────────────────────────────────────────────────────────

try {
    $report   = $_GET['report'] ?? '';
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
    $html     = '';
    $filename = 'inventory_report.pdf';

    switch ($report) {

        // ── Reorder Report ──────────────────────────────────────────────────
        case 'reorder':
            $catF = (int) ($_GET['category_id'] ?? 0);
            $locF = (int) ($_GET['location_id']  ?? 0);
            $where  = "i.reorder_level > 0 AND i.item_status = 'ACTIVE'";
            $params = [];
            if ($catF > 0) { $where .= " AND i.category_id = ?"; $params[] = $catF; }
            if ($locF > 0) { $where .= " AND sl.location_id = ?"; $params[] = $locF; }
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       COALESCE(SUM(sl.quantity_on_hand), 0) AS qty_on_hand,
                       i.reorder_level, i.reorder_quantity,
                       i.reorder_level - COALESCE(SUM(sl.quantity_on_hand), 0) AS shortfall
                FROM inv_items i
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_stock sl ON sl.item_id = i.item_id
                WHERE $where
                GROUP BY i.item_id, i.item_code, i.item_name, c.category_name, i.reorder_level, i.reorder_quantity
                HAVING COALESCE(SUM(sl.quantity_on_hand), 0) <= i.reorder_level
                ORDER BY shortfall DESC
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['qty_on_hand'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['reorder_level'], 2) . '</td>'
                    . '<td class="text-right" style="color:#dc3545;font-weight:bold;">' . number_format((float)$r['shortfall'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['reorder_quantity'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Reorder Report', "Items at or below reorder level &mdash; as of {$dateTo}")
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th class="text-right">On Hand</th><th class="text-right">Reorder Level</th><th class="text-right">Shortfall</th><th class="text-right">Reorder Qty</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No items below reorder level</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'reorder_report_' . date('Ymd') . '.pdf';
            break;

        // ── Expiry Report ───────────────────────────────────────────────────
        case 'expiry':
            $daysAhead = max(1, (int) ($_GET['days_ahead'] ?? 90));
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, s.batch_lot_number,
                       s.expiry_date, s.quantity_on_hand,
                       l.location_code, c.category_name,
                       DATEDIFF(s.expiry_date, CURDATE()) AS days_remaining
                FROM inv_stock s
                JOIN inv_items i ON s.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_locations l ON s.location_id = l.location_id
                WHERE s.expiry_date IS NOT NULL
                  AND s.quantity_on_hand > 0
                  AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                ORDER BY s.expiry_date ASC
            ");
            $rows->execute([$daysAhead]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $d = (int) $r['days_remaining'];
                $color = $d < 0 ? '#dc3545' : ($d <= 30 ? '#fd7e14' : '#198754');
                $label = $d < 0 ? 'EXPIRED' : ($d === 0 ? 'Today' : "{$d}d");
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['batch_lot_number'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['expiry_date']) . '</td>'
                    . '<td style="color:' . $color . ';font-weight:bold;">' . $label . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['quantity_on_hand'], 2) . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Expiry Report', "Items expiring within {$daysAhead} days")
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Batch/Lot</th><th>Expiry Date</th><th>Status</th><th class="text-right">Qty</th><th>Location</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No expiring items found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'expiry_report_' . date('Ymd') . '.pdf';
            break;

        // ── Write-Down Report ───────────────────────────────────────────────
        case 'write_down':
            $rows = $pdo->prepare("
                SELECT wd.write_down_number, wd.write_down_date, wd.reason,
                       wd.total_original_value, wd.total_written_down_value,
                       (wd.total_original_value - wd.total_written_down_value) AS value_reduction,
                       u.full_name AS approved_by_name, wd.status
                FROM inv_write_downs wd
                LEFT JOIN users u ON wd.approved_by = u.user_id
                WHERE wd.write_down_date BETWEEN ? AND ?
                  AND wd.status = 'APPROVED'
                ORDER BY wd.write_down_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['write_down_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['write_down_date']) . '</td>'
                    . '<td>' . htmlspecialchars($r['reason'] ?? '-') . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_original_value'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_written_down_value'], 2) . '</td>'
                    . '<td class="text-right" style="color:#dc3545;">($' . number_format((float)$r['value_reduction'], 2) . ')</td>'
                    . '<td>' . htmlspecialchars($r['approved_by_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Write-Down Report', "Approved write-downs: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Ref #</th><th>Date</th><th>Reason</th><th class="text-right">Original Value</th><th class="text-right">Written-Down Value</th><th class="text-right">Value Reduction</th><th>Approved By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No write-downs in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'write_down_report_' . date('Ymd') . '.pdf';
            break;

        // ── Audit Exceptions ────────────────────────────────────────────────
        case 'audit_exceptions':
            $catF   = (int) ($_GET['category_id'] ?? 0);
            $where  = "ca.status = 'COMPLETED'";
            $params = [];
            if ($catF > 0) { $where .= " AND i.category_id = ?"; $params[] = $catF; }
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       l.location_code, ca.count_date,
                       cal.system_quantity, cal.counted_quantity,
                       (cal.counted_quantity - cal.system_quantity) AS variance
                FROM inv_count_audit_lines cal
                JOIN inv_count_audits ca ON cal.audit_id = ca.audit_id
                JOIN inv_items i ON cal.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_locations l ON ca.location_id = l.location_id
                WHERE $where AND cal.counted_quantity <> cal.system_quantity
                ORDER BY ABS(cal.counted_quantity - cal.system_quantity) DESC
                LIMIT 2000
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $v = (float) $r['variance'];
                $color = $v < 0 ? '#dc3545' : '#198754';
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['count_date']) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['system_quantity'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['counted_quantity'], 2) . '</td>'
                    . '<td class="text-right" style="color:' . $color . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . number_format($v, 2) . '</td></tr>';
            }
            $html = pdfHeader('Audit Exceptions', "Count discrepancies &mdash; {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Location</th><th>Date</th><th class="text-right">System Qty</th><th class="text-right">Counted Qty</th><th class="text-right">Variance</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No exceptions found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'audit_exceptions_' . date('Ymd') . '.pdf';
            break;

        // ── Disposal Register ───────────────────────────────────────────────
        case 'disposal':
            $rows = $pdo->prepare("
                SELECT d.disposal_number, d.disposal_date, d.disposal_method,
                       d.total_book_value, d.total_proceeds, d.status,
                       u.full_name AS approved_by_name
                FROM inv_disposals d
                LEFT JOIN users u ON d.approved_by = u.user_id
                WHERE d.disposal_date BETWEEN ? AND ?
                ORDER BY d.disposal_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['disposal_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['disposal_date']) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['disposal_method'] ?? '-')) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_book_value'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_proceeds'], 2) . '</td>'
                    . '<td>' . htmlspecialchars($r['status']) . '</td>'
                    . '<td>' . htmlspecialchars($r['approved_by_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Disposal Register', "Disposal records: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Ref #</th><th>Date</th><th>Method</th><th class="text-right">Book Value</th><th class="text-right">Proceeds</th><th>Status</th><th>Approved By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No disposals in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'disposal_register_' . date('Ymd') . '.pdf';
            break;

        // ── Donation Register ───────────────────────────────────────────────
        case 'donation':
            $rows = $pdo->prepare("
                SELECT g.grn_number, g.received_date,
                       COALESCE(g.donor_name, v.vendor_name, 'Unknown') AS donor,
                       COUNT(gi.grn_item_id) AS item_lines,
                       SUM(gi.quantity_received * gi.unit_cost) AS total_value
                FROM inv_goods_received g
                LEFT JOIN inv_grn_items gi ON g.grn_id = gi.grn_id
                LEFT JOIN vendors v ON g.supplier_vendor_id = v.vendor_id
                WHERE g.is_donation = 1
                  AND g.received_date BETWEEN ? AND ?
                GROUP BY g.grn_id, g.grn_number, g.received_date, donor
                ORDER BY g.received_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['grn_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['received_date']) . '</td>'
                    . '<td>' . htmlspecialchars($r['donor']) . '</td>'
                    . '<td class="text-right">' . (int)$r['item_lines'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Donation Register', "Donations received: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>GRN #</th><th>Date</th><th>Donor</th><th class="text-right">Lines</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="5" style="text-align:center;color:#6c757d;">No donations in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'donation_register_' . date('Ymd') . '.pdf';
            break;

        // ── Goods Received Register ─────────────────────────────────────────
        case 'goods_received':
            $rows = $pdo->prepare("
                SELECT g.grn_number, g.received_date,
                       COALESCE(v.vendor_name, 'Unknown') AS supplier,
                       g.inspection_result,
                       COUNT(gi.grn_item_id) AS lines,
                       SUM(gi.quantity_received * gi.unit_cost) AS total_value
                FROM inv_goods_received g
                LEFT JOIN inv_grn_items gi ON g.grn_id = gi.grn_id
                LEFT JOIN vendors v ON g.supplier_vendor_id = v.vendor_id
                WHERE g.is_donation = 0
                  AND g.received_date BETWEEN ? AND ?
                GROUP BY g.grn_id, g.grn_number, g.received_date, supplier, g.inspection_result
                ORDER BY g.received_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['grn_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['received_date']) . '</td>'
                    . '<td>' . htmlspecialchars($r['supplier']) . '</td>'
                    . '<td>' . htmlspecialchars($r['inspection_result'] ?? '-') . '</td>'
                    . '<td class="text-right">' . (int)$r['lines'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Goods Received Register', "GRN records: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>GRN #</th><th>Date</th><th>Supplier</th><th>Inspection</th><th class="text-right">Lines</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No GRNs in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'grn_register_' . date('Ymd') . '.pdf';
            break;

        // ── Issue Register ──────────────────────────────────────────────────
        case 'issue_register':
            $rows = $pdo->prepare("
                SELECT iss.issue_number, iss.issue_date,
                       COALESCE(b.branch_name, iss.issued_to_department) AS department,
                       u.full_name AS issued_to_name,
                       COUNT(isl.issue_line_id) AS lines,
                       SUM(isl.quantity_issued * isl.unit_cost) AS total_value
                FROM inv_issues iss
                LEFT JOIN inv_issue_lines isl ON iss.issue_id = isl.issue_id
                LEFT JOIN users u ON iss.issued_to_user_id = u.user_id
                LEFT JOIN branches b ON iss.issued_to_branch_id = b.branch_id
                WHERE iss.status = 'ISSUED'
                  AND iss.issue_date BETWEEN ? AND ?
                GROUP BY iss.issue_id, iss.issue_number, iss.issue_date, department, issued_to_name
                ORDER BY iss.issue_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['issue_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['issue_date']) . '</td>'
                    . '<td>' . htmlspecialchars($r['department'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['issued_to_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . (int)$r['lines'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Issue Register', "Issues: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Issue #</th><th>Date</th><th>Department</th><th>Issued To</th><th class="text-right">Lines</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No issues in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'issue_register_' . date('Ymd') . '.pdf';
            break;

        // ── Transfer Register ───────────────────────────────────────────────
        case 'transfer_register':
            $rows = $pdo->prepare("
                SELECT t.transfer_number, t.transfer_date,
                       fl.location_code AS from_location,
                       tl.location_code AS to_location,
                       t.status,
                       COUNT(tli.transfer_line_id) AS lines,
                       SUM(tli.quantity_transferred * tli.unit_cost) AS total_value
                FROM inv_transfers t
                LEFT JOIN inv_transfer_lines tli ON t.transfer_id = tli.transfer_id
                LEFT JOIN inv_locations fl ON t.from_location_id = fl.location_id
                LEFT JOIN inv_locations tl ON t.to_location_id = tl.location_id
                WHERE t.transfer_date BETWEEN ? AND ?
                GROUP BY t.transfer_id, t.transfer_number, t.transfer_date, from_location, to_location, t.status
                ORDER BY t.transfer_date DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['transfer_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['transfer_date']) . '</td>'
                    . '<td>' . htmlspecialchars($r['from_location'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['to_location'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['status']) . '</td>'
                    . '<td class="text-right">' . (int)$r['lines'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Transfer Register', "Transfers: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Transfer #</th><th>Date</th><th>From</th><th>To</th><th>Status</th><th class="text-right">Lines</th><th class="text-right">Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No transfers in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'transfer_register_' . date('Ymd') . '.pdf';
            break;

        // ── Stock Valuation ─────────────────────────────────────────────────
        case 'stock_valuation':
            $catF   = (int) ($_GET['category_id'] ?? 0);
            $where  = "s.quantity_on_hand > 0";
            $params = [];
            if ($catF > 0) { $where .= " AND i.category_id = ?"; $params[] = $catF; }
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       SUM(s.quantity_on_hand) AS total_qty,
                       AVG(s.unit_cost) AS avg_cost,
                       SUM(s.quantity_on_hand * s.unit_cost) AS total_value
                FROM inv_stock s
                JOIN inv_items i ON s.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                WHERE $where
                GROUP BY i.item_id, i.item_code, i.item_name, c.category_name
                ORDER BY total_value DESC
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $totalVal = array_sum(array_column($rows, 'total_value'));
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['total_qty'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['avg_cost'], 4) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Stock Valuation Report', "Total portfolio value: $" . number_format($totalVal, 2) . " &mdash; as of " . date('d M Y'))
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th class="text-right">Qty on Hand</th><th class="text-right">Avg Cost</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No stock found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'stock_valuation_' . date('Ymd') . '.pdf';
            break;

        // ── Obsolete Stock ──────────────────────────────────────────────────
        case 'obsolete_stock':
            $months = max(1, (int) ($_GET['months'] ?? 12));
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       SUM(s.quantity_on_hand) AS qty_on_hand,
                       SUM(s.quantity_on_hand * s.unit_cost) AS total_value,
                       MAX(t.last_txn) AS last_transaction
                FROM inv_stock s
                JOIN inv_items i ON s.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN (SELECT item_id, MAX(created_at) AS last_txn FROM inv_transactions GROUP BY item_id) t ON t.item_id = i.item_id
                WHERE s.quantity_on_hand > 0
                  AND (t.last_txn IS NULL OR t.last_txn < DATE_SUB(NOW(), INTERVAL ? MONTH))
                GROUP BY i.item_id, i.item_code, i.item_name, c.category_name
                ORDER BY total_value DESC
            ");
            $rows->execute([$months]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['qty_on_hand'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td>'
                    . '<td>' . ($r['last_transaction'] ? htmlspecialchars(date('Y-m-d', strtotime($r['last_transaction']))) : 'Never') . '</td></tr>';
            }
            $html = pdfHeader('Obsolete Stock Report', "Items with no movement for {$months}+ months")
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th class="text-right">Qty on Hand</th><th class="text-right">Value</th><th>Last Transaction</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No obsolete stock found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'obsolete_stock_' . date('Ymd') . '.pdf';
            break;

        // ── Slow Moving Stock ───────────────────────────────────────────────
        case 'slow_moving':
            $days     = max(1, (int) ($_GET['days'] ?? 180));
            $statusF  = $_GET['item_status'] ?? 'ACTIVE';
            $catF     = (int) ($_GET['category_id'] ?? 0);
            $catWhere = $catF > 0 ? ' AND i.category_id = ?' : '';
            $qParams  = [$days, $days, $statusF];
            if ($catF > 0) $qParams[] = $catF;
            $rows = $pdo->prepare("
                SELECT item_code, item_name, category_name, qty_on_hand, total_value, txn_count, avg_daily_movement
                FROM (
                    SELECT i.item_code, i.item_name, c.category_name,
                           COALESCE(SUM(s.quantity_on_hand), 0) AS qty_on_hand,
                           COALESCE(SUM(s.quantity_on_hand * s.unit_cost), 0) AS total_value,
                           COUNT(t.transaction_id) AS txn_count,
                           COUNT(t.transaction_id) / ? AS avg_daily_movement
                    FROM inv_items i
                    LEFT JOIN inv_categories c ON i.category_id = c.category_id
                    LEFT JOIN inv_stock s ON s.item_id = i.item_id
                    LEFT JOIN inv_transactions t ON t.item_id = i.item_id AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    WHERE i.item_status = ?{$catWhere}
                    GROUP BY i.item_id, i.item_code, i.item_name, c.category_name
                    HAVING txn_count < 5 AND qty_on_hand > 0
                ) sub
                ORDER BY avg_daily_movement ASC, total_value DESC
            ");
            $rows->execute($qParams);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['qty_on_hand'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td>'
                    . '<td class="text-right">' . (int)$r['txn_count'] . '</td></tr>';
            }
            $html = pdfHeader('Slow Moving Stock Report', "Items with fewer than 5 transactions in the last {$days} days")
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th class="text-right">Qty on Hand</th><th class="text-right">Value</th><th class="text-right">Transactions</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No slow-moving items found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'slow_moving_stock_' . date('Ymd') . '.pdf';
            break;

        // ── Emergency Stock ─────────────────────────────────────────────────
        case 'emergency_stock':
            $locF   = (int) ($_GET['location_id'] ?? 0);
            $where  = "s.is_emergency_reserve = 1 AND s.quantity_on_hand > 0";
            $params = [];
            if ($locF > 0) { $where .= " AND s.location_id = ?"; $params[] = $locF; }
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       l.location_code,
                       SUM(s.quantity_on_hand) AS qty_on_hand,
                       SUM(s.quantity_on_hand * s.unit_cost) AS total_value,
                       i.reorder_point AS min_reserve
                FROM inv_stock s
                JOIN inv_items i ON s.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_locations l ON s.location_id = l.location_id
                WHERE $where
                GROUP BY i.item_id, i.item_code, i.item_name, c.category_name, l.location_code, l.location_id, i.reorder_point
                ORDER BY qty_on_hand ASC
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['qty_on_hand'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['min_reserve'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Emergency Stock Report', "Emergency reserve inventory as of " . date('d M Y'))
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Location</th><th class="text-right">Qty on Hand</th><th class="text-right">Min Reserve</th><th class="text-right">Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No emergency stock found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'emergency_stock_' . date('Ymd') . '.pdf';
            break;

        // ── Inventory Expense ───────────────────────────────────────────────
        case 'inventory_expense':
            $rows = $pdo->prepare("
                SELECT c.category_name,
                       SUM(t.quantity * t.unit_cost) AS total_expense,
                       COUNT(t.transaction_id) AS txn_count
                FROM inv_transactions t
                JOIN inv_items i ON t.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                WHERE t.transaction_type = 'ISSUE'
                  AND t.created_at BETWEEN ? AND ?
                GROUP BY i.category_id, c.category_name
                ORDER BY total_expense DESC
            ");
            $rows->execute([$dateFrom, $dateTo . ' 23:59:59']);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $totalExp = array_sum(array_column($rows, 'total_expense'));
            $trs = '';
            foreach ($rows as $r) {
                $pct = $totalExp > 0 ? ($r['total_expense'] / $totalExp * 100) : 0;
                $trs .= '<tr><td>' . htmlspecialchars($r['category_name'] ?? 'Uncategorised') . '</td>'
                    . '<td class="text-right">' . (int)$r['txn_count'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_expense'], 2) . '</td>'
                    . '<td class="text-right">' . number_format($pct, 1) . '%</td></tr>';
            }
            $html = pdfHeader('Inventory Expense Report', "Issue expenses by category: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Category</th><th class="text-right">Issues</th><th class="text-right">Total Expense</th><th class="text-right">% of Total</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="4" style="text-align:center;color:#6c757d;">No expense data in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'inventory_expense_' . date('Ymd') . '.pdf';
            break;

        // ── Approval Turnaround ─────────────────────────────────────────────
        case 'approval_turnaround':
            $rows = $pdo->prepare("
                SELECT pr.request_number, pr.request_type, pr.status,
                       pr.created_at, pr.updated_at,
                       DATEDIFF(pr.updated_at, pr.created_at) AS days_to_complete,
                       u.full_name AS requested_by_name
                FROM procurement_requests pr
                LEFT JOIN users u ON pr.requested_by = u.user_id
                WHERE pr.status IN ('APPROVED','COMPLETED','REJECTED')
                  AND DATE(pr.created_at) BETWEEN ? AND ?
                ORDER BY days_to_complete DESC
                LIMIT 2000
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $d = (int) $r['days_to_complete'];
                $color = $d <= 3 ? '#198754' : ($d <= 7 ? '#fd7e14' : '#dc3545');
                $trs .= '<tr><td>' . htmlspecialchars($r['request_number']) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['request_type'])) . '</td>'
                    . '<td>' . htmlspecialchars($r['status']) . '</td>'
                    . '<td>' . htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) . '</td>'
                    . '<td style="color:' . $color . ';font-weight:bold;">' . $d . ' days</td>'
                    . '<td>' . htmlspecialchars($r['requested_by_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Approval Turnaround Report', "Request processing times: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Request #</th><th>Type</th><th>Status</th><th>Date</th><th>Days to Complete</th><th>Requested By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="6" style="text-align:center;color:#6c757d;">No completed requests in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'approval_turnaround_' . date('Ymd') . '.pdf';
            break;

        // ── Asset Movement Register ─────────────────────────────────────────
        case 'asset_movement':
            $serialFilter = (int) ($_GET['serial_id'] ?? 0);
            $statusFilter = $_GET['status'] ?? '';
            $where  = "am.moved_at BETWEEN ? AND ?";
            $params = [$dateFrom, $dateTo . ' 23:59:59'];
            if ($serialFilter > 0) { $where .= " AND am.serial_id = ?"; $params[] = $serialFilter; }
            if ($statusFilter !== '') { $where .= " AND am.lifecycle_status = ?"; $params[] = $statusFilter; }
            $rows = $pdo->prepare("
                SELECT am.moved_at, sn.serial_number, sn.dgc_asset_number,
                       i.item_code, i.item_name,
                       fl.location_code AS from_location_code,
                       tl.location_code AS to_location_code,
                       am.lifecycle_status, am.movement_reason,
                       u.full_name AS moved_by_name
                FROM inv_asset_movements am
                JOIN inv_serial_numbers sn ON am.serial_id = sn.serial_id
                JOIN inv_items i ON sn.item_id = i.item_id
                LEFT JOIN inv_locations fl ON am.from_location_id = fl.location_id
                LEFT JOIN inv_locations tl ON am.to_location_id = tl.location_id
                LEFT JOIN users u ON am.moved_by = u.user_id
                WHERE $where
                ORDER BY am.moved_at DESC
                LIMIT 2000
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars(date('Y-m-d', strtotime($r['moved_at']))) . '</td>'
                    . '<td>' . htmlspecialchars($r['serial_number']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_code'] . ' ' . $r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['from_location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['to_location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['lifecycle_status'])) . '</td>'
                    . '<td>' . htmlspecialchars($r['moved_by_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Asset Movement Register', "Asset movements: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Date</th><th>Serial #</th><th>Item</th><th>From</th><th>To</th><th>Status</th><th>By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No movement records found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'asset_movement_' . date('Ymd') . '.pdf';
            break;

        // ── Shrinkage & Loss ────────────────────────────────────────────────
        case 'shrinkage_loss':
            $reasonF   = $_GET['reason_code'] ?? '';
            $adjWhere  = "a.adjustment_type = 'LOSS' AND a.status = 'COMPLETED' AND a.created_at BETWEEN ? AND ?";
            $adjParams = [$dateFrom, $dateTo . ' 23:59:59'];
            if ($reasonF !== '') { $adjWhere .= " AND a.reason_code = ?"; $adjParams[] = $reasonF; }
            $adjs = $pdo->prepare("
                SELECT a.adjustment_number, a.reason_code, a.total_value_impact, a.created_at,
                       u.full_name AS requested_by_name, l.location_code
                FROM inv_adjustments a
                LEFT JOIN users u ON a.requested_by = u.user_id
                LEFT JOIN inv_locations l ON a.location_id = l.location_id
                WHERE $adjWhere ORDER BY a.created_at DESC
            ");
            $adjs->execute($adjParams);
            $adjs = $adjs->fetchAll(PDO::FETCH_ASSOC);
            $incs = $pdo->prepare("
                SELECT i.incident_number, i.incident_type, i.total_estimated_loss,
                       i.incident_date, i.status, u.full_name AS reported_by_name, l.location_code
                FROM inv_incidents i
                LEFT JOIN users u ON i.reported_by = u.user_id
                LEFT JOIN inv_locations l ON i.location_id = l.location_id
                WHERE i.incident_date BETWEEN ? AND ?
                ORDER BY i.incident_date DESC
            ");
            $incs->execute([$dateFrom, $dateTo]);
            $incs = $incs->fetchAll(PDO::FETCH_ASSOC);
            $adjLoss = array_sum(array_column($adjs, 'total_value_impact'));
            $incLoss = array_sum(array_column($incs, 'total_estimated_loss'));
            $adjRows = '';
            foreach ($adjs as $a) {
                $adjRows .= '<tr><td>' . htmlspecialchars($a['adjustment_number']) . '</td>'
                    . '<td>' . htmlspecialchars(date('Y-m-d', strtotime($a['created_at']))) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $a['reason_code'])) . '</td>'
                    . '<td>' . htmlspecialchars($a['location_code'] ?? '-') . '</td>'
                    . '<td class="text-right" style="color:#dc3545;">($' . number_format(abs((float)$a['total_value_impact']), 2) . ')</td></tr>';
            }
            $incRows = '';
            foreach ($incs as $inc) {
                $incRows .= '<tr><td>' . htmlspecialchars($inc['incident_number']) . '</td>'
                    . '<td>' . htmlspecialchars($inc['incident_date']) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $inc['incident_type'])) . '</td>'
                    . '<td>' . htmlspecialchars($inc['location_code'] ?? '-') . '</td>'
                    . '<td class="text-right" style="color:#dc3545;">($' . number_format((float)$inc['total_estimated_loss'], 2) . ')</td></tr>';
            }
            $html = pdfHeader('Shrinkage &amp; Loss Report', "Period: {$dateFrom} to {$dateTo} | Adj Loss: $" . number_format($adjLoss, 2) . " | Incident Loss: $" . number_format($incLoss, 2))
                . '<p style="font-weight:bold;margin:8px 0 4px;">Stock Adjustment Losses</p>'
                . '<table><thead><tr><th>Adjustment #</th><th>Date</th><th>Reason</th><th>Location</th><th class="text-right">Value Impact</th></tr></thead><tbody>'
                . ($adjRows ?: '<tr><td colspan="5" style="text-align:center;color:#6c757d;">No adjustment losses</td></tr>')
                . '</tbody></table>'
                . '<p style="font-weight:bold;margin:14px 0 4px;">Incident / Loss Reports</p>'
                . '<table><thead><tr><th>Incident #</th><th>Date</th><th>Type</th><th>Location</th><th class="text-right">Est. Loss</th></tr></thead><tbody>'
                . ($incRows ?: '<tr><td colspan="5" style="text-align:center;color:#6c757d;">No incidents</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'shrinkage_loss_' . date('Ymd') . '.pdf';
            break;

        // ── Supplier Performance ────────────────────────────────────────────
        case 'supplier_performance':
            $rows = $pdo->prepare("
                SELECT COALESCE(v.vendor_name, 'Unknown Supplier') AS supplier_name,
                       COUNT(DISTINCT g.grn_id) AS grn_count,
                       SUM(gi.quantity_received) AS total_received,
                       SUM(gi.quantity_accepted) AS total_accepted,
                       SUM(gi.quantity_rejected) AS total_rejected,
                       SUM(gi.quantity_received * gi.unit_cost) AS total_value
                FROM inv_goods_received g
                LEFT JOIN inv_grn_items gi ON g.grn_id = gi.grn_id
                LEFT JOIN vendors v ON g.supplier_vendor_id = v.vendor_id
                WHERE g.received_date BETWEEN ? AND ?
                  AND g.is_donation = 0
                GROUP BY v.vendor_id, COALESCE(v.vendor_name, 'Unknown Supplier')
                ORDER BY total_value DESC
            ");
            $rows->execute([$dateFrom, $dateTo]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $pct = $r['total_received'] > 0 ? ($r['total_accepted'] / $r['total_received'] * 100) : 0;
                $color = $pct >= 95 ? '#198754' : ($pct >= 80 ? '#fd7e14' : '#dc3545');
                $trs .= '<tr><td>' . htmlspecialchars($r['supplier_name']) . '</td>'
                    . '<td class="text-right">' . (int)$r['grn_count'] . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['total_received'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['total_accepted'], 2) . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['total_rejected'], 2) . '</td>'
                    . '<td class="text-right" style="color:' . $color . ';font-weight:bold;">' . number_format($pct, 1) . '%</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Supplier Performance Report', "Period: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Supplier</th><th class="text-right">GRNs</th><th class="text-right">Received</th><th class="text-right">Accepted</th><th class="text-right">Rejected</th><th class="text-right">Accept %</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="7" style="text-align:center;color:#6c757d;">No supplier data in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'supplier_performance_' . date('Ymd') . '.pdf';
            break;

        // ── User Activity ───────────────────────────────────────────────────
        case 'user_activity':
            $userF = (int) ($_GET['user_id'] ?? 0);
            $typeF = $_GET['transaction_type'] ?? '';
            $where  = "t.created_at BETWEEN ? AND ?";
            $params = [$dateFrom, $dateTo . ' 23:59:59'];
            if ($userF > 0)    { $where .= " AND t.performed_by = ?";     $params[] = $userF; }
            if ($typeF !== '') { $where .= " AND t.transaction_type = ?"; $params[] = $typeF; }
            $rows = $pdo->prepare("
                SELECT u.full_name AS user_name,
                       t.transaction_type,
                       COUNT(*) AS txn_count,
                       SUM(t.quantity * t.unit_cost) AS total_value
                FROM inv_transactions t
                LEFT JOIN users u ON t.performed_by = u.user_id
                WHERE $where
                GROUP BY u.user_id, u.full_name, t.transaction_type
                ORDER BY total_value DESC
                LIMIT 2000
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['user_name'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['transaction_type'])) . '</td>'
                    . '<td class="text-right">' . (int)$r['txn_count'] . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('User Activity Report', "Period: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>User</th><th>Transaction Type</th><th class="text-right">Count</th><th class="text-right">Total Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="4" style="text-align:center;color:#6c757d;">No activity in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'user_activity_' . date('Ymd') . '.pdf';
            break;

        // ── Traceability Report ─────────────────────────────────────────────
        case 'traceability_report':
            $searchBatch  = trim($_GET['batch']  ?? '');
            $searchSerial = trim($_GET['serial'] ?? '');
            $itemId       = (int) ($_GET['item_id'] ?? 0);
            if ($searchBatch === '' && $searchSerial === '' && $itemId === 0) {
                http_response_code(400);
                exit('No search criteria provided for traceability report.');
            }
            $where  = "1=1";
            $params = [];
            if ($searchBatch !== '')  { $where .= " AND t.batch_lot_number = ?"; $params[] = $searchBatch; }
            if ($searchSerial !== '') { $where .= " AND t.serial_number = ?";    $params[] = $searchSerial; }
            if ($itemId > 0)          { $where .= " AND t.item_id = ?";          $params[] = $itemId; }
            $rows = $pdo->prepare("
                SELECT t.created_at, t.transaction_type, i.item_code, i.item_name,
                       t.batch_lot_number, t.serial_number, t.expiry_date,
                       l.location_code, t.reference_number, t.quantity,
                       u.full_name AS user_name
                FROM inv_transactions t
                JOIN inv_items i ON t.item_id = i.item_id
                LEFT JOIN inv_locations l ON t.location_id = l.location_id
                LEFT JOIN users u ON t.performed_by = u.user_id
                WHERE $where ORDER BY t.created_at ASC
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $subtitle = $searchBatch !== '' ? "Batch: {$searchBatch}" : ($searchSerial !== '' ? "Serial: {$searchSerial}" : "Item ID: {$itemId}");
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['transaction_type'])) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['batch_lot_number'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['quantity'], 2) . '</td>'
                    . '<td>' . htmlspecialchars($r['user_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Batch / Serial Traceability', $subtitle)
                . '<table><thead><tr><th>Date</th><th>Type</th><th>Item</th><th>Batch/Lot</th><th>Location</th><th>Reference</th><th class="text-right">Qty</th><th>By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="8" style="text-align:center;color:#6c757d;">No matching transactions</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'traceability_' . date('Ymd') . '.pdf';
            break;

        // ── Asset Register ──────────────────────────────────────────────────
        case 'asset_register':
            $rows = $pdo->prepare("
                SELECT i.item_code, i.item_name, c.category_name,
                       ad.asset_code, ad.acquired_date, ad.asset_condition,
                       COALESCE(ad.custodian_name, adcu.full_name) AS custodian_display,
                       b.branch_name AS department_name,
                       COALESCE(st.total_qty, 0) AS total_qty,
                       COALESCE(st.total_value, 0) AS total_value
                FROM inv_items i
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id
                LEFT JOIN users adcu ON ad.custodian_user_id = adcu.user_id
                LEFT JOIN branches b ON ad.department_branch_id = b.branch_id
                LEFT JOIN (
                    SELECT item_id,
                           SUM(quantity_on_hand) AS total_qty,
                           SUM(quantity_on_hand * unit_cost) AS total_value
                    FROM inv_stock GROUP BY item_id
                ) st ON st.item_id = i.item_id
                WHERE i.item_status = 'ACTIVE'
                ORDER BY total_value DESC, i.item_name ASC
                LIMIT 2000
            ");
            $rows->execute();
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['asset_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['department_name'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['custodian_display'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['asset_condition'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['acquired_date'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['total_qty'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td></tr>';
            }
            $html = pdfHeader('Asset Register Report', "All active assets and inventory as of " . date('d M Y'))
                . '<table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Asset Tag</th><th>Department</th><th>Custodian</th><th>Condition</th><th>Acquired</th><th class="text-right">Qty</th><th class="text-right">Value</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="10" style="text-align:center;color:#6c757d;">No assets found</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'asset_register_' . date('Ymd') . '.pdf';
            break;

        // ── Transaction History ─────────────────────────────────────────────
        case 'transaction_history':
            $itemF    = (int) ($_GET['item_id'] ?? 0);
            $locF     = (int) ($_GET['location_id'] ?? 0);
            $typeF    = $_GET['transaction_type'] ?? '';
            $where    = "t.created_at BETWEEN ? AND ?";
            $params   = [$dateFrom, $dateTo . ' 23:59:59'];
            if ($itemF > 0)    { $where .= " AND t.item_id = ?";           $params[] = $itemF; }
            if ($locF > 0)     { $where .= " AND t.location_id = ?";       $params[] = $locF; }
            if ($typeF !== '') { $where .= " AND t.transaction_type = ?";  $params[] = $typeF; }
            $rows = $pdo->prepare("
                SELECT t.created_at, t.transaction_type, i.item_code, i.item_name,
                       l.location_code, t.reference_number, t.quantity,
                       (t.quantity * t.unit_cost) AS line_value,
                       u.full_name AS user_name
                FROM inv_transactions t
                JOIN inv_items i ON t.item_id = i.item_id
                LEFT JOIN inv_locations l ON t.location_id = l.location_id
                LEFT JOIN users u ON t.performed_by = u.user_id
                WHERE $where
                ORDER BY t.created_at DESC
                LIMIT 2000
            ");
            $rows->execute($params);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
            $trs = '';
            foreach ($rows as $r) {
                $trs .= '<tr><td>' . htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) . '</td>'
                    . '<td>' . htmlspecialchars(str_replace('_', ' ', $r['transaction_type'])) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_code']) . '</td>'
                    . '<td>' . htmlspecialchars($r['item_name']) . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['reference_number'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['quantity'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['line_value'], 2) . '</td>'
                    . '<td>' . htmlspecialchars($r['user_name'] ?? '-') . '</td></tr>';
            }
            $html = pdfHeader('Transaction History', "Period: {$dateFrom} to {$dateTo}")
                . '<table><thead><tr><th>Date</th><th>Type</th><th>Code</th><th>Item</th><th>Location</th><th>Ref</th><th class="text-right">Qty</th><th class="text-right">Value</th><th>By</th></tr></thead><tbody>'
                . ($trs ?: '<tr><td colspan="9" style="text-align:center;color:#6c757d;">No transactions in period</td></tr>')
                . '</tbody></table>'
                . pdfFooter();
            $filename = 'transaction_history_' . date('Ymd') . '.pdf';
            break;

        // ── Inventory Report by Location ────────────────────────────────────
        case 'location_inventory':
            $locF       = (int)  ($_GET['location_id']   ?? 0);
            $catF       = (int)  ($_GET['category_id']   ?? 0);
            $statusF    = trim($_GET['status']            ?? '');
            $acqFrom    = trim($_GET['acquired_from']     ?? '');
            $acqTo      = trim($_GET['acquired_to']       ?? '');
            $searchText = trim($_GET['search']            ?? '');

            // Check tables
            $adExistsStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inv_asset_details'"
            );
            $adReady = (int) $adExistsStmt->fetchColumn() > 0;

            $brExistsStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches'"
            );
            $brReady = (int) $brExistsStmt->fetchColumn() > 0;

            $snExistsStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inv_serial_numbers'"
            );
            $snReady = (int) $snExistsStmt->fetchColumn() > 0;

            $adJoin = $adReady
                ? "LEFT JOIN inv_asset_details ad ON ad.item_id = i.item_id"
                  . ($brReady ? " LEFT JOIN branches b ON ad.department_branch_id = b.branch_id" : "")
                  . " LEFT JOIN users u ON ad.custodian_user_id = u.user_id"
                : "";

            $adSelect = $adReady
                ? "ad.asset_code AS asset_tag, ad.serial_number AS asset_serial,
                   ad.acquired_date, ad.asset_status,
                   COALESCE(ad.custodian_name, u.full_name) AS officer_name,"
                  . ($brReady ? " b.branch_name AS department_name," : " NULL AS department_name,")
                : "NULL AS asset_tag, NULL AS asset_serial,
                   NULL AS acquired_date, NULL AS asset_status,
                   NULL AS officer_name, NULL AS department_name,";

            /* ── WHERE — mirrors location_inventory.php exactly ─────────── */
            $where  = [];
            $params = [];
            if ($locF > 0) {
                $where[] = "sl.location_id = ?"; $params[] = $locF;
                $where[] = "sl.quantity_on_hand > 0";
            } elseif ($adReady) {
                $where[] = "(sl.quantity_on_hand > 0 OR (ad.asset_detail_id IS NOT NULL AND COALESCE(ad.is_disposed, 0) = 0))";
            } else {
                $where[] = "sl.quantity_on_hand > 0";
            }
            if ($catF > 0) { $where[] = "i.category_id = ?"; $params[] = $catF; }
            if ($statusF !== '') {
                $sc = ["sl.stock_status = ?"];
                $params[] = $statusF;
                if ($adReady) { $sc[] = "ad.asset_status = ?"; $params[] = $statusF; }
                $where[] = '(' . implode(' OR ', $sc) . ')';
            }
            if ($acqFrom !== '' && $adReady) { $where[] = "ad.acquired_date >= ?"; $params[] = $acqFrom; }
            if ($acqTo   !== '' && $adReady) { $where[] = "ad.acquired_date <= ?"; $params[] = $acqTo; }
            if ($searchText !== '') {
                $s = "%$searchText%";
                $searchClauses = [
                    "i.item_name LIKE ?",
                    "i.item_code LIKE ?",
                    "i.description LIKE ?",
                ];
                $params[] = $s; $params[] = $s; $params[] = $s;
                if ($adReady) {
                    $searchClauses[] = "ad.serial_number LIKE ?";
                    $searchClauses[] = "ad.asset_code LIKE ?";
                    $params[] = $s; $params[] = $s;
                }
                if ($snReady) {
                    $searchClauses[] = "EXISTS (SELECT 1 FROM inv_serial_numbers sn WHERE sn.item_id = i.item_id AND sn.serial_number LIKE ?)";
                    $params[] = $s;
                }
                $where[] = '(' . implode(' OR ', $searchClauses) . ')';
            }

            $whereClause = implode(' AND ', $where);

            // Resolve location label
            $locationLabel = 'All Locations';
            if ($locF > 0) {
                $ls = $pdo->prepare("SELECT location_code, site_name FROM inv_locations WHERE location_id = ?");
                $ls->execute([$locF]);
                $ld = $ls->fetch(PDO::FETCH_ASSOC);
                if ($ld) {
                    $locationLabel = $ld['location_code'] . ($ld['site_name'] ? ' — ' . $ld['site_name'] : '');
                }
            }

            $unitCostExpr = $adReady
                ? "COALESCE(sl.unit_cost, ad.balance_value, ad.purchase_cost, ad.bos_value, 0)"
                : "COALESCE(sl.unit_cost, 0)";

            $rowsStmt = $pdo->prepare("
                SELECT
                    i.item_code,
                    i.item_name,
                    i.description,
                    c.category_name,
                    l.location_code,
                    CONCAT_WS(' > ',
                        NULLIF(l.site_name, ''),
                        NULLIF(l.building, ''),
                        NULLIF(l.floor, ''),
                        NULLIF(l.room_storage_area, '')
                    ) AS location_path,
                    COALESCE(sl.quantity_on_hand, 1) AS quantity_on_hand,
                    $unitCostExpr AS unit_cost,
                    (COALESCE(sl.quantity_on_hand, 1) * $unitCostExpr) AS total_value,
                    sl.stock_status,
                    $adSelect
                    i.item_status
                FROM inv_items i
                LEFT JOIN inv_stock sl ON sl.item_id = i.item_id
                LEFT JOIN inv_categories c ON i.category_id = c.category_id
                LEFT JOIN inv_locations l ON l.location_id = sl.location_id
                $adJoin
                WHERE $whereClause
                ORDER BY (l.location_code IS NULL), l.location_code, i.item_name
                LIMIT 3000
            ");
            $rowsStmt->execute($params);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Totals
            $grandTotal = 0.0;
            $grandQty   = 0.0;
            foreach ($rows as $r) {
                $grandTotal += (float) $r['total_value'];
                $grandQty   += (float) $r['quantity_on_hand'];
            }

            $userName = $_SESSION['full_name'] ?? 'System';

            $trs = '';
            $currentLoc  = null;
            $locSubTotal = 0.0;
            $locSubQty   = 0.0;
            $n = 1;
            foreach ($rows as $r) {
                if ($locF === 0 && $r['location_code'] !== $currentLoc) {
                    if ($currentLoc !== null) {
                        $trs .= '<tr style="background:#d4edda;font-weight:bold;">'
                            . '<td colspan="5" style="text-align:right;">Subtotal — ' . htmlspecialchars($currentLoc) . ':</td>'
                            . '<td style="text-align:right;">' . number_format($locSubQty, 2) . '</td>'
                            . '<td></td>'
                            . '<td style="text-align:right;">$' . number_format($locSubTotal, 2) . '</td>'
                            . '<td colspan="5"></td></tr>';
                    }
                    $currentLoc  = $r['location_code'];
                    $locSubTotal = 0.0;
                    $locSubQty   = 0.0;
                }
                $locSubTotal += (float) $r['total_value'];
                $locSubQty   += (float) $r['quantity_on_hand'];
                $displayStatus = $r['asset_status'] ?: $r['stock_status'];
                $trs .= '<tr>'
                    . '<td>' . $n++ . '</td>'
                    . '<td><b>' . htmlspecialchars($r['item_name']) . '</b><br><small style="color:#6c757d;">' . htmlspecialchars($r['item_code']) . '</small></td>'
                    . '<td style="font-size:9px;">' . htmlspecialchars(mb_strimwidth($r['description'] ?? '', 0, 60, '…')) . '</td>'
                    . '<td>' . htmlspecialchars($r['asset_serial'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['asset_tag'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['category_name'] ?? '-') . '</td>'
                    . '<td class="text-right">' . number_format((float)$r['quantity_on_hand'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['unit_cost'], 2) . '</td>'
                    . '<td class="text-right">$' . number_format((float)$r['total_value'], 2) . '</td>'
                    . '<td>' . htmlspecialchars($r['department_name'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['officer_name'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['location_code'] ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($displayStatus ?? '-') . '</td>'
                    . '<td>' . htmlspecialchars($r['acquired_date'] ?? '-') . '</td>'
                    . '</tr>';
            }
            if ($locF === 0 && $currentLoc !== null) {
                $trs .= '<tr style="background:#d4edda;font-weight:bold;">'
                    . '<td colspan="5" style="text-align:right;">Subtotal — ' . htmlspecialchars($currentLoc) . ':</td>'
                    . '<td style="text-align:right;">' . number_format($locSubQty, 2) . '</td>'
                    . '<td></td>'
                    . '<td style="text-align:right;">$' . number_format($locSubTotal, 2) . '</td>'
                    . '<td colspan="5"></td></tr>';
            }
            if (!empty($rows)) {
                $trs .= '<tr style="background:#0b5e2b;color:#fff;font-weight:bold;">'
                    . '<td colspan="5" style="text-align:right;">Grand Total:</td>'
                    . '<td style="text-align:right;">' . number_format($grandQty, 2) . '</td>'
                    . '<td></td>'
                    . '<td style="text-align:right;">$' . number_format($grandTotal, 2) . '</td>'
                    . '<td colspan="5"></td></tr>';
            }

            $emptyRow = '<tr><td colspan="14" style="text-align:center;color:#6c757d;padding:16px;">No inventory records found</td></tr>';

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:\'Helvetica\',\'Arial\',sans-serif;color:#212529;font-size:9px;margin:0;padding:0;}
table{border-collapse:collapse;width:100%;}
th{background:#0b5e2b;color:#fff;padding:5px 6px;text-align:left;font-size:8.5px;}
td{padding:4px 6px;border-bottom:1px solid #e9ecef;font-size:8.5px;vertical-align:top;}
tr:nth-child(even) td{background:#f8f9fa;}
.text-right{text-align:right;}
</style></head><body>
<div style="background:linear-gradient(90deg,#0b5e2b,#c9a227);padding:12px 16px;color:#fff;">
<table><tr>
<td><span style="font-size:15px;font-weight:700;">Government Chemist — PRMS</span><br>
<span style="font-size:9px;opacity:0.85;">Procurement &amp; Resource Management System</span></td>
<td style="text-align:right;font-size:9px;">
Generated: ' . date('d M Y \a\t g:i A') . '<br>
Generated By: ' . htmlspecialchars($userName) . '
</td>
</tr></table></div>
<div style="padding:10px 16px 6px;">
<h2 style="margin:0 0 3px;font-size:14px;color:#1a1a2e;">Inventory Report by Location</h2>
<p style="margin:0;color:#6c757d;font-size:9px;">Location: <b>' . htmlspecialchars($locationLabel) . '</b>'
. ($statusF  !== '' ? ' &nbsp;&bull;&nbsp; Status: <b>' . htmlspecialchars($statusF) . '</b>'   : '')
. ($searchText !== '' ? ' &nbsp;&bull;&nbsp; Search: <b>&ldquo;' . htmlspecialchars($searchText) . '&rdquo;</b>' : '')
. ' &mdash; Report Date: ' . date('d M Y') . '</p>
</div>
<div style="padding:4px 16px 8px;font-size:9px;">
<table style="width:auto;border:0;"><tr>
<td style="padding:0 16px 0 0;border:0;background:none;">Total Items: <b>' . number_format(count($rows)) . '</b></td>
<td style="padding:0 16px 0 0;border:0;background:none;">Total Qty: <b>' . number_format($grandQty, 2) . '</b></td>
<td style="padding:0;border:0;background:none;">Total Value: <b>$' . number_format($grandTotal, 2) . '</b></td>
</tr></table>
</div>
<div style="padding:0 16px;">
<table>
<thead><tr>
<th>#</th><th>Item / Asset Name</th><th>Description</th>
<th>Serial No.</th><th>Asset Tag</th><th>Category</th>
<th class="text-right">Qty</th><th class="text-right">Unit Cost</th><th class="text-right">Total Value</th>
<th>Department</th><th>Officer / User</th><th>Location</th><th>Status</th><th>Acq. Date</th>
</tr></thead>
<tbody>' . ($trs ?: $emptyRow) . '</tbody>
</table>
</div>
<div style="padding:10px 16px;text-align:center;color:#adb5bd;font-size:8px;border-top:1px solid #e9ecef;margin-top:12px;">
&copy; ' . date('Y') . ' Government Chemist &middot; Confidential &middot; PRMS
</div></body></html>';

            $dompdfOptions = new Options();
            $dompdfOptions->set('isRemoteEnabled', false);
            $dompdfOptions->set('isHtml5ParserEnabled', true);
            $dompdf2 = new \Dompdf\Dompdf($dompdfOptions);
            $dompdf2->loadHtml($html);
            $dompdf2->setPaper('A4', 'landscape');
            $dompdf2->render();
            $dompdf2->stream('location_inventory_' . date('Ymd') . '.pdf', ['Attachment' => false]);
            exit;

        default:
            http_response_code(400);
            exit('Unknown report type: ' . htmlspecialchars($report));
    }

    streamPdf($html, $filename);

} catch (Throwable $e) {
    error_log('inventory/reports/export_pdf.php error: ' . $e->getMessage());
    http_response_code(500);
    exit('An error occurred while generating the report. Please try again.');
}
