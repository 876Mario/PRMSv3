<?php
$REQUIRE_PERMISSION = 'view_financial_reports';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$data = $pdo->query("
    SELECT branch_name, total_invoiced, total_paid, outstanding
    FROM vw_branch_outstanding
    ORDER BY outstanding DESC, branch_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalsStmt = $pdo->query("
    SELECT
        COALESCE(SUM(total_invoiced), 0) AS grand_invoiced,
        COALESCE(SUM(total_paid), 0) AS grand_paid,
        COALESCE(SUM(outstanding), 0) AS grand_outstanding,
        COALESCE(MAX(outstanding), 0) AS max_outstanding
    FROM vw_branch_outstanding
");
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$grandInvoiced = (float) ($totals['grand_invoiced'] ?? 0);
$grandPaid = (float) ($totals['grand_paid'] ?? 0);
$grandOutstanding = (float) ($totals['grand_outstanding'] ?? 0);
$maxOutstanding = max((float) ($totals['max_outstanding'] ?? 0), 1.0);
?>

<div class="container-fluid mt-2">

  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-bold mb-1">🏢 Branch Outstanding Report</h2>
      <p class="text-muted mb-0">Financial overview of invoiced, paid &amp; outstanding amounts per branch</p>
    </div>
    <div class="d-flex gap-2">
      <a href="/reports/export_excel.php" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
      </a>
      <a href="/reports/export_pdf.php" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
      </a>
    </div>
  </div>

  <!-- Summary KPI Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:#e8f5e9;">
            <span class="fs-4">📄</span>
          </div>
          <div>
            <small class="text-muted text-uppercase fw-semibold" style="font-size:.75rem;">Total Invoiced</small>
            <h4 class="fw-bold mb-0 text-dark"><?= money($grandInvoiced) ?></h4>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:#e3f2fd;">
            <span class="fs-4">💰</span>
          </div>
          <div>
            <small class="text-muted text-uppercase fw-semibold" style="font-size:.75rem;">Total Paid</small>
            <h4 class="fw-bold mb-0 text-success"><?= money($grandPaid) ?></h4>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:#fce4ec;">
            <span class="fs-4">⚠️</span>
          </div>
          <div>
            <small class="text-muted text-uppercase fw-semibold" style="font-size:.75rem;">Total Outstanding</small>
            <h4 class="fw-bold mb-0 text-danger"><?= money($grandOutstanding) ?></h4>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Data Table -->
  <div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead>
            <tr class="table-dark">
              <th class="text-light ps-3" style="width:5%;">#</th>
              <th class="text-light">🏢 Branch</th>
              <th class="text-light text-end">📄 Total Invoiced</th>
              <th class="text-light text-end">💰 Total Paid</th>
              <th class="text-light text-end">⚠️ Outstanding</th>
              <th class="text-light" style="width:22%;">Balance Bar</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4">
                <span style="font-size:2em;">📭</span><br>
                No branch data available.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data as $i => $r):
              $pct = $maxOutstanding > 0 ? round(($r['outstanding'] / $maxOutstanding) * 100) : 0;
              $barColor = $pct > 75 ? 'bg-danger' : ($pct > 40 ? 'bg-warning' : 'bg-success');
            ?>
            <tr>
              <td class="ps-3 text-muted fw-bold"><?= $i + 1 ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($r['branch_name']) ?></td>
              <td class="text-end"><?= money($r['total_invoiced']) ?></td>
              <td class="text-end text-success"><?= money($r['total_paid']) ?></td>
              <td class="text-end fw-bold <?= $r['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= money($r['outstanding']) ?>
              </td>
              <td>
                <div class="progress rounded-pill" style="height:10px;">
                  <div class="progress-bar <?= $barColor ?> rounded-pill"
                       role="progressbar"
                       style="width:<?= $pct ?>%"
                       aria-valuenow="<?= $pct ?>"
                       aria-valuemin="0"
                       aria-valuemax="100"
                       title="<?= $pct ?>% of highest outstanding">
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
          <?php if (!empty($data)): ?>
          <tfoot>
            <tr class="table-light fw-bold">
              <td class="ps-3" colspan="2">Grand Total (<?= count($data) ?> branches)</td>
              <td class="text-end"><?= money($grandInvoiced) ?></td>
              <td class="text-end text-success"><?= money($grandPaid) ?></td>
              <td class="text-end text-danger"><?= money($grandOutstanding) ?></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
