<?php
$REQUIRE_PERMISSION = 'view_inventory_reports';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$typeF    = $_GET['transfer_type'] ?? '';
$statusF  = $_GET['status'] ?? '';

$where  = "t.transfer_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($typeF !== '')   { $where .= " AND t.transfer_type = ?";   $params[] = $typeF; }
if ($statusF !== '') { $where .= " AND t.status = ?"; $params[] = $statusF; }

extract(getPaginationParams(25));

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT t.transfer_id) FROM inv_transfers t WHERE $where");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$totalsStmt = $pdo->prepare("
    SELECT COALESCE(SUM(ti.quantity * ti.unit_cost), 0) AS grand_total
    FROM inv_transfers t
    LEFT JOIN inv_transfer_items ti ON t.transfer_id = ti.transfer_id
    WHERE $where
");
$totalsStmt->execute($params);
$grandTotal = (float) $totalsStmt->fetchColumn();

$rowsStmt = $pdo->prepare("
    SELECT t.transfer_id, t.transfer_number, t.transfer_date, t.transfer_type, t.status,
           t.financial_secretary_approval,
           ls.location_code AS from_location, ld.location_code AS to_location,
           u.full_name AS requested_by_name,
           COUNT(ti.transfer_item_id) AS line_count,
           SUM(ti.quantity * ti.unit_cost) AS total_value
    FROM inv_transfers t
    LEFT JOIN inv_locations ls ON t.source_location_id = ls.location_id
    LEFT JOIN inv_locations ld ON t.destination_location_id = ld.location_id
    LEFT JOIN users u ON t.requested_by = u.user_id
    LEFT JOIN inv_transfer_items ti ON t.transfer_id = ti.transfer_id
    WHERE $where
    GROUP BY t.transfer_id
    ORDER BY t.transfer_date DESC, t.transfer_id DESC
    LIMIT $perPage OFFSET $offset
");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$pdfUrl = '/inventory/reports/export_pdf.php?report=transfer_register&' . http_build_query($_GET);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-left-right"></i> Transfer Register</h2>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($pdfUrl) ?>" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf"></i> Export PDF</a>
        <a href="/inventory/reports/" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Reports</a>
    </div>
</div>

<form class="row g-2 mb-4">
    <div class="col-md-2"><input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>"></div>
    <div class="col-md-2"><input type="date" name="date_to"   class="form-control" value="<?= htmlspecialchars($dateTo) ?>"></div>
    <div class="col-md-2">
        <select name="transfer_type" class="form-select">
            <option value="">All Types</option>
            <option value="INTERNAL"     <?= $typeF === 'INTERNAL'     ? 'selected' : '' ?>>Internal</option>
            <option value="INTER_BRANCH" <?= $typeF === 'INTER_BRANCH' ? 'selected' : '' ?>>Inter-Branch</option>
            <option value="INTER_MDA"    <?= $typeF === 'INTER_MDA'    ? 'selected' : '' ?>>Inter-MDA</option>
        </select>
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <?php foreach (['DRAFT','PENDING_APPROVAL','PENDING_FS_APPROVAL','APPROVED','IN_TRANSIT','COMPLETED','RECEIVED_WITH_DISCREPANCY','CANCELLED','REJECTED'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-dark w-100"><i class="bi bi-funnel"></i> Filter</button></div>
</form>

<div class="alert alert-info mb-3">
    <strong><?= $totalRows ?></strong> transfers &nbsp;|&nbsp;
    <strong>Total Transfer Value: $<?= number_format($grandTotal, 2) ?></strong>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>Transfer #</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>FS Approved</th>
                        <th>Lines</th>
                        <th class="text-end">Value</th>
                        <th>Requested By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No records found</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="/inventory/transfers/view.php?id=<?= $r['transfer_id'] ?>"><?= htmlspecialchars($r['transfer_number']) ?></a></td>
                        <td><?= htmlspecialchars($r['transfer_date']) ?></td>
                        <td>
                            <?php $tc = match($r['transfer_type']) {
                                'INTERNAL'     => 'secondary',
                                'INTER_BRANCH' => 'info',
                                'INTER_MDA'    => 'warning',
                                default        => 'secondary'
                            }; ?>
                            <span class="badge bg-<?= $tc ?>"><?= str_replace('_', ' ', $r['transfer_type']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($r['from_location'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['to_location'] ?? '-') ?></td>
                        <td>
                            <?php $sc = match($r['status']) {
                                'COMPLETED'  => 'success',
                                'RECEIVED_WITH_DISCREPANCY' => 'warning',
                                'REJECTED','CANCELLED' => 'danger',
                                'IN_TRANSIT' => 'info',
                                'APPROVED'   => 'primary',
                                default      => 'secondary'
                            }; ?>
                            <span class="badge bg-<?= $sc ?>"><?= str_replace('_', ' ', $r['status']) ?></span>
                        </td>
                        <td class="text-center">
                            <?= $r['financial_secretary_approval']
                                ? '<i class="bi bi-check-circle text-success"></i>'
                                : '<span class="text-muted">N/A</span>' ?>
                        </td>
                        <td class="text-center"><?= $r['line_count'] ?></td>
                        <td class="text-end">$<?= number_format($r['total_value'] ?? 0, 2) ?></td>
                        <td><?= htmlspecialchars($r['requested_by_name'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if ($totalRows > 0): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="8" class="text-end fw-bold">Grand Total:</td>
                        <td class="text-end fw-bold">$<?= number_format($grandTotal, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php renderShowingInfo($page, $perPage, $totalRows); ?>
<?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
