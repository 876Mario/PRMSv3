<?php
$REQUIRE_PERMISSION = 'view_financial_reports';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$data = $pdo->query("
    SELECT branch_name, total_invoiced, total_paid, outstanding
    FROM vw_branch_outstanding
    ORDER BY branch_name
")->fetchAll(PDO::FETCH_ASSOC);

$totalsStmt = $pdo->query("
    SELECT
        COALESCE(SUM(total_invoiced), 0) AS grand_invoiced,
        COALESCE(SUM(total_paid), 0) AS grand_paid,
        COALESCE(SUM(outstanding), 0) AS grand_outstanding
    FROM vw_branch_outstanding
");
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$grandInvoiced = (float) ($totals['grand_invoiced'] ?? 0);
$grandPaid = (float) ($totals['grand_paid'] ?? 0);
$grandOutstanding = (float) ($totals['grand_outstanding'] ?? 0);
?>

<div class="container-fluid mt-2">

  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-bold mb-1">🏦 Branch Financial Summary</h2>
      <p class="text-muted mb-0">Invoiced, paid &amp; outstanding breakdown by branch</p>
    </div>
    <div>
      <a href="/reports/branch_outstanding.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-bar-chart me-1"></i> Outstanding Report
      </a>
    </div>
  </div>

  <!-- KPI Cards -->
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
              <th class="text-light" style="width:20%;">Paid / Invoiced</th>
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
            <?php foreach ($data as $i => $b):
              $paidPct = $b['total_invoiced'] > 0 ? round(($b['total_paid'] / $b['total_invoiced']) * 100) : 0;
              $outPct  = 100 - $paidPct;
            ?>
            <tr>
              <td class="ps-3 text-muted fw-bold"><?= $i + 1 ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($b['branch_name']) ?></td>
              <td class="text-end"><?= money($b['total_invoiced']) ?></td>
              <td class="text-end text-success"><?= money($b['total_paid']) ?></td>
              <td class="text-end fw-bold <?= $b['outstanding'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= money($b['outstanding']) ?>
              </td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress rounded-pill flex-grow-1" style="height:10px;">
                    <div class="progress-bar bg-success rounded-pill"
                         role="progressbar"
                         style="width:<?= $paidPct ?>%"
                         aria-valuenow="<?= $paidPct ?>"
                         aria-valuemin="0"
                         aria-valuemax="100">
                    </div>
                    <div class="progress-bar bg-danger rounded-pill"
                         role="progressbar"
                         style="width:<?= $outPct ?>%">
                    </div>
                  </div>
                  <small class="text-muted" style="min-width:38px;font-size:.75rem;"><?= $paidPct ?>%</small>
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
              <td>
                <?php $totalPaidPct = $grandInvoiced > 0 ? round(($grandPaid / $grandInvoiced) * 100) : 0; ?>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress rounded-pill flex-grow-1" style="height:10px;">
                    <div class="progress-bar bg-success rounded-pill" style="width:<?= $totalPaidPct ?>%"></div>
                    <div class="progress-bar bg-danger rounded-pill" style="width:<?= 100 - $totalPaidPct ?>%"></div>
                  </div>
                  <small class="text-muted" style="min-width:38px;font-size:.75rem;"><?= $totalPaidPct ?>%</small>
                </div>
              </td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
