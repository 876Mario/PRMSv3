<?php
$REQUIRE_PERMISSION = 'view_vendors';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

/* ===============================
   Search & Filter
================================ */
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(vendor_name LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status)) {
    $where[] = "status = :status";
    $params[':status'] = $status;
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

/* ===============================
   Stats (all vendors, unfiltered)
================================ */
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'INACTIVE' THEN 1 ELSE 0 END) as inactive
    FROM vendors
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Pagination
extract(getPaginationParams(20));

// Filtered count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vendors $whereSQL");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

/* ===============================
   Vendors List
================================ */
$stmt = $pdo->prepare("
    SELECT vendor_id, vendor_name, email, phone, status, total_awards
    FROM vendors
    $whereSQL
    ORDER BY vendor_name ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<style>
    .card {
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }
    
    .form-control, .form-select {
        transition: all 0.2s ease;
        box-shadow: none !important;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
    }
    
    .btn {
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    table tbody tr:hover td {
        background-color: #f9f9f9 !important;
    }
</style>

<div class="mb-5">

<!-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">🏢 Vendor Master List</h2>
        <p class="text-muted mb-0">Manage vendor information and profiles</p>
    </div>
    <a href="/vendors/add.php" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; padding: 0.5rem 1rem; font-weight: 600;">
        <i class="bi bi-plus-circle me-1"></i>Add Vendor
    </a>
</div>

<!-- ═══════════════════════════════════════════════════════
     KPI SUMMARY CARDS
═══════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Total Vendors</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['total'] ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">📊</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Active</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['active'] ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">✅</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Inactive</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= (int)$stats['inactive'] ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">⏸️</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 h-100" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 small" style="opacity: 0.9;">Showing</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= count($vendors) ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">🎯</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     FILTERS
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <div class="d-flex align-items-center gap-2 py-2">
            <i class="bi bi-funnel" style="font-size: 1.2rem; color: #667eea;"></i>
            <h6 class="mb-0" style="font-weight: 600; color: #1a1a1a;">Search & Filter</h6>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-5">
                <label class="form-label small text-muted" style="font-weight: 600;">Search</label>
                <input type="text" name="search" 
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Vendor name, email, or phone..."
                       class="form-control"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted" style="font-weight: 600;">Status</label>
                <select name="status" class="form-select" style="border-radius: 6px; border: 1px solid #e0e0e0;">
                    <option value="">All Status</option>
                    <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                    <option value="INACTIVE" <?= $status === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/vendors/list.php" class="btn btn-outline-secondary" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VENDORS TABLE
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead style="background-color: #f8f9fa; border-bottom: 2px solid #e0e0e0;">
                <tr>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Vendor Name</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Email</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Phone</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none;">Status</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: right;">Awards</th>
                    <th style="padding: 1rem; font-weight: 600; color: #1a1a1a; border: none; text-align: center; width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($vendors)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5" style="border: none;">
                        <p style="color: #999; font-size: 1rem;">
                            <i class="bi bi-inbox" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 0.5rem;"></i>
                            No vendors found
                        </p>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($vendors as $v): 
    // Determine row styling based on status
    $rowBg = $v['status'] === 'ACTIVE' ? '#e8f5e9' : '#ffebee';
?>
                <tr style="background-color: <?= $rowBg ?>; border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 1rem; border: none;">
                        <strong style="color: #667eea;"><?= htmlspecialchars($v['vendor_name']) ?></strong>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <small class="text-muted"><?= htmlspecialchars($v['email']) ?></small>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <small class="text-muted"><?= htmlspecialchars($v['phone'] ?? '—') ?></small>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <span class="badge" style="background-color: <?= $v['status'] === 'ACTIVE' ? '#4caf50' : '#f44336' ?>; color: white; padding: 0.35rem 0.75rem;">
                            <?= $v['status'] === 'ACTIVE' ? '✓ Active' : '✗ Inactive' ?>
                        </span>
                    </td>
                    <td style="padding: 1rem; border: none; text-align: right; font-weight: 600; color: #1a1a1a;">
                        <?= (int)$v['total_awards'] ?>
                    </td>
                    <td style="padding: 1rem; border: none; text-align: center;">
                        <a href="/vendors/view.php?id=<?= $v['vendor_id'] ?>"
                           class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem; margin-right: 0.25rem;" title="View">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="/vendors/edit.php?id=<?= $v['vendor_id'] ?>"
                           class="btn btn-sm" style="background: #ff9800; color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem; margin-right: 0.25rem;" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="/vendors/delete.php?id=<?= $v['vendor_id'] ?>"
                           class="btn btn-sm" style="background: #f44336; color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem;" title="Delete"
                           onclick="return confirm('Are you sure you want to delete <?= htmlspecialchars($v['vendor_name'], ENT_QUOTES) ?>? This cannot be undone.');">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="text-align: center; margin-top: 2rem; padding: 1rem; background-color: #f8f9fa; border-radius: 8px; border: 1px solid #e0e0e0;">
    <small style="color: #666; font-weight: 500;">
        📊 Total: <strong><?= (int)$stats['total'] ?></strong> | Active: <strong><?= (int)$stats['active'] ?></strong>
    </small>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>
<?php endif; ?>

</div>


<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
