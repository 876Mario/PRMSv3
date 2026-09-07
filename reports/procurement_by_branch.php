<?php
$REQUIRE_PERMISSION = 'view_financial_reports';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$branchVisibilityFilter = (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole())
    ? " AND " . getProcurementVisibilitySqlCondition('pr')
    : "";

$query = "
    SELECT 
        b.branch_id,
        b.branch_name,
        COUNT(pr.request_id) as count,
        SUM(pr.estimated_value) as total_value
    FROM branches b
    LEFT JOIN procurement_requests pr ON b.branch_id = pr.branch_id{$branchVisibilityFilter}
    GROUP BY b.branch_id, b.branch_name
    ORDER BY total_value DESC
";
$data = $pdo->query($query)->fetchAll();

$totalCount = 0;
$totalValue = 0;
foreach ($data as $r) {
    $totalCount += $r['count'];
    $totalValue += $r['total_value'];
}
$maxValue = max(array_column($data, 'total_value') ?: [1]);
?>

<div class="container-fluid mt-2">

  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-bold mb-1">🏢 Procurement by Department/Branch</h2>
      <p class="text-muted mb-0">Procurement requests and spending by department or branch</p>
    </div>
    <div class="d-flex gap-2">
      <a href="/reports/export_excel.php?report=procurement_branch" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
      </a>
      <a href="/reports/export_pdf.php?report=procurement_branch" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
      </a>
    </div>
  </div>

  <!-- Summary KPI Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:#e3f2fd;">
            <span class="fs-4">📋</span>
          </div>
          <div>
            <small class="text-muted text-uppercase fw-semibold" style="font-size:.75rem;">Total Requests</small>
            <h4 class="fw-bold mb-0 text-dark"><?= $totalCount ?></h4>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:#e8f5e9;">
            <span class="fs-4">💰</span>
          </div>
          <div>
            <small class="text-muted text-uppercase fw-semibold" style="font-size:.75rem;">Total Value</small>
            <h4 class="fw-bold mb-0 text-dark"><?= money($totalValue) ?></h4>
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
            <tr class="table-light">
              <th class="text-dark ps-3" style="width:5%;">#</th>
              <th class="text-dark">🏢 Department/Branch</th>
              <th class="text-dark text-end" style="width:12%;">Requests</th>
              <th class="text-dark text-end" style="width:18%;">Total Value</th>
              <th class="text-dark" style="width:25%;">Value Distribution</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data)): ?>
            <tr>
              <td colspan="5" class="text-center py-4 text-muted">
                <i class="bi bi-inbox"></i> No branch data found
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data as $idx => $row): ?>
            <tr>
              <td class="ps-3 fw-bold text-muted" style="font-size:.85rem;"><?= $idx + 1 ?></td>
              <td class="fw-semibold"><?= htmlspecialchars($row['branch_name']) ?></td>
              <td class="text-end"><?= $row['count'] ?></td>
              <td class="text-end fw-semibold"><?= money($row['total_value']) ?></td>
              <td>
                <div style="width: 100%; background: #f0f0f0; border-radius: 4px; overflow: hidden; height: 24px;">
                  <div style="width: <?= ($row['total_value'] / $maxValue) * 100 ?>%; background: linear-gradient(90deg, #007bff, #0dcaf0); height: 100%; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px; color: white; font-size: 11px; font-weight: bold;">
                    <?= number_format(($row['total_value'] / $totalValue) * 100, 1) ?>%
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
