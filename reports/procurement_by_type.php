<?php
$REQUIRE_PERMISSION = 'view_financial_reports';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";

$visibilityWhere = (function_exists('isProcurementVisibilityRestrictedRole')
    && function_exists('getProcurementVisibilitySqlCondition')
    && isProcurementVisibilityRestrictedRole())
    ? "WHERE " . getProcurementVisibilitySqlCondition('procurement_requests')
    : "";

$query = "
    SELECT 
        COALESCE(procurement_method, 'UNSPECIFIED') as type,
        COUNT(*) as count,
        SUM(estimated_value) as total_value
    FROM procurement_requests
    {$visibilityWhere}
    GROUP BY procurement_method
    ORDER BY total_value DESC
";
$data = $pdo->query($query)->fetchAll();

$totalCount = 0;
$totalValue = 0;
foreach ($data as $r) {
    $totalCount += $r['count'];
    $totalValue += $r['total_value'];
}

// Define type labels
$typeLabels = [
    'SINGLE_SOURCE' => 'Single Source',
    'RESTRICTED_BIDDING' => 'Restricted Bidding',
    'NATIONAL_COMPETITIVE' => 'National Competitive',
    'INTERNATIONAL_COMPETITIVE' => 'International Competitive',
    'UNSPECIFIED' => 'Unspecified'
];
?>

<div class="container-fluid mt-2">

  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-bold mb-1">📁 Procurement by Type</h2>
      <p class="text-muted mb-0">Procurement methods and their distribution</p>
    </div>
    <div class="d-flex gap-2">
      <a href="/reports/export_excel.php?report=procurement_type" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
      </a>
      <a href="/reports/export_pdf.php?report=procurement_type" class="btn btn-outline-danger btn-sm">
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

  <!-- Chart -->
  <div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
      <h5 class="card-title mb-3">Procurement Type Distribution</h5>
      <canvas id="typeChart"></canvas>
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
              <th class="text-dark">Procurement Method</th>
              <th class="text-dark text-end" style="width:15%;">Count</th>
              <th class="text-dark text-end" style="width:20%;">Total Value</th>
              <th class="text-dark text-end" style="width:15%;">% of Value</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data)): ?>
            <tr>
              <td colspan="5" class="text-center py-4 text-muted">
                <i class="bi bi-inbox"></i> No procurement data found
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data as $idx => $row): ?>
            <tr>
              <td class="ps-3 fw-bold text-muted" style="font-size:.85rem;"><?= $idx + 1 ?></td>
              <td>
                <span class="badge bg-primary" style="padding: 8px 12px;">
                  <?= $typeLabels[$row['type']] ?? $row['type'] ?>
                </span>
              </td>
              <td class="text-end fw-semibold"><?= $row['count'] ?></td>
              <td class="text-end"><?= money($row['total_value']) ?></td>
              <td class="text-end"><?= number_format(($row['total_value'] / $totalValue) * 100, 2) ?>%</td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php
// Pre-compute the labels for the chart
$chartLabels = [];
$chartValues = [];
foreach ($data as $d) {
    $chartLabels[] = $typeLabels[$d['type']] ?? $d['type'];
    $chartValues[] = $d['total_value'];
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('typeChart').getContext('2d');
    const chartData = {
        labels: <?php echo json_encode($chartLabels); ?>,
        datasets: [{
            label: 'Total Value',
            data: <?php echo json_encode($chartValues); ?>,
            backgroundColor: [
                '#007bff', '#28a745', '#dc3545', '#ff9800', '#6c757d'
            ],
            borderColor: '#fff',
            borderWidth: 2
        }]
    };
    
    new Chart(ctx, {
        type: 'bar',
        data: chartData,
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Value: ' + new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'}).format(context.parsed.x);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true
                }
            }
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
