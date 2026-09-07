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
        status,
        COUNT(*) as count,
        SUM(estimated_value) as total_value
    FROM procurement_requests
    {$visibilityWhere}
    GROUP BY status
    ORDER BY count DESC
";
$data = $pdo->query($query)->fetchAll();

$totalCount = 0;
$totalValue = 0;
foreach ($data as $r) {
    $totalCount += $r['count'];
    $totalValue += $r['total_value'];
}

// Define status colors
$statusColors = [
    'DRAFT' => '#6c757d',
    'SUBMITTED' => '#0dcaf0',
    'PENDING_APPROVAL' => '#ff9800',
    'APPROVED' => '#28a745',
    'REJECTED' => '#dc3545',
    'IN_PROCESS' => '#007bff',
    'COMPLETED' => '#20c997',
    'CANCELLED' => '#6c757d'
];
?>

<div class="container-fluid mt-2">

  <!-- Page Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
      <h2 class="fw-bold mb-1">📊 Procurement by Status</h2>
      <p class="text-muted mb-0">Distribution of procurement requests across different statuses</p>
    </div>
    <div class="d-flex gap-2">
      <a href="/reports/export_excel.php?report=procurement_status" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
      </a>
      <a href="/reports/export_pdf.php?report=procurement_status" class="btn btn-outline-danger btn-sm">
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
      <h5 class="card-title mb-3">Distribution Chart</h5>
      <canvas id="statusChart"></canvas>
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
              <th class="text-dark">Status</th>
              <th class="text-dark text-end" style="width:15%;">Count</th>
              <th class="text-dark text-end" style="width:20%;">Total Value</th>
              <th class="text-dark text-end" style="width:15%;">% of Total</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data)): ?>
            <tr>
              <td colspan="5" class="text-center py-4 text-muted">
                <i class="bi bi-inbox"></i> No procurement requests found
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data as $idx => $row): ?>
            <tr>
              <td class="ps-3 fw-bold text-muted" style="font-size:.85rem;"><?= $idx + 1 ?></td>
              <td>
                <span class="badge" style="background-color: <?= $statusColors[$row['status']] ?? '#6c757d' ?>; padding: 8px 12px;">
                  <?= ucfirst(strtolower(str_replace('_', ' ', $row['status']))) ?>
                </span>
              </td>
              <td class="text-end fw-semibold"><?= $row['count'] ?></td>
              <td class="text-end"><?= money($row['total_value']) ?></td>
              <td class="text-end"><?= number_format(($row['count'] / $totalCount) * 100, 2) ?>%</td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('statusChart').getContext('2d');
    const chartData = {
        labels: [<?php echo implode(',', array_map(fn($d) => '"'.ucfirst(strtolower(str_replace('_', ' ', $d['status']))).'"', $data)); ?>],
        datasets: [{
            label: 'Procurement Requests',
            data: [<?php echo implode(',', array_column($data, 'count')); ?>],
            backgroundColor: [
                '#007bff', '#28a745', '#dc3545', '#ff9800', '#6c757d', '#20c997', '#0dcaf0', '#ffc107'
            ],
            borderColor: '#fff',
            borderWidth: 2
        }]
    };
    
    new Chart(ctx, {
        type: 'doughnut',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 15, font: { size: 12 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.parsed.y + ' requests';
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
