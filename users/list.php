<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/includes/pagination.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* ================================
   Fetch Roles (Dynamic)
================================ */
$rolesStmt = $pdo->query("SELECT id, name FROM roles ORDER BY name ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ================================
   Fetch Job Titles (for filter)
================================ */
$jobTitles = [];
try {
    $jobTitles = $pdo->query("SELECT id, title_name FROM job_titles WHERE is_active = 1 ORDER BY sort_order, title_name")
                     ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // graceful degradation if table not yet created
}

/* ================================
   Filters
================================ */
$where = [];
$params = [];

/* Search */
if (!empty($_GET['q'])) {
    $where[] = "(u.full_name LIKE :q OR u.email LIKE :q)";
    $params[':q'] = '%'.$_GET['q'].'%';
}

/* Role filter (by role_id now) */
if (!empty($_GET['role'])) {
    $where[] = "u.role_id = :role_id";
    $params[':role_id'] = (int)$_GET['role'];
}

/* Job Title filter */
if (!empty($_GET['job_title'])) {
    $where[] = "u.job_title_id = :job_title_id";
    $params[':job_title_id'] = (int)$_GET['job_title'];
}

/* Status filter */
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $where[] = "u.is_active = :status";
    $params[':status'] = (int)$_GET['status'];
}

$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

// Pagination
extract(getPaginationParams(20));

/* ================================
   Count filtered users
================================ */
$countSql = "SELECT COUNT(*) FROM users u LEFT JOIN roles r ON u.role_id = r.id $whereSQL";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

/* ================================
   Fetch users
================================ */
$sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.is_active,
        u.role_id,
        u.failed_attempts,
        u.lock_until,
        r.name AS role_name,
        jt.title_name AS job_title_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN job_titles jt ON u.job_title_id = jt.id
    $whereSQL
    ORDER BY u.full_name
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Calculate stats */
$totalStmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $totalStmt->fetch()['total'];

$activeStmt = $pdo->query("SELECT COUNT(*) as active FROM users WHERE is_active = 1");
$activeUsers = $activeStmt->fetch()['active'];

$disabledUsers = $totalUsers - $activeUsers;

$rolesCountStmt = $pdo->query("
    SELECT COUNT(DISTINCT role_id) as role_count FROM users WHERE role_id IS NOT NULL
");
$roleCount = $rolesCountStmt->fetch()['role_count'];

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
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
        <h2 class="mb-1" style="font-weight: 700; color: #1a1a1a;">👥 System Users</h2>
        <p class="text-muted mb-0">Manage user accounts and permissions</p>
    </div>
    <a href="/users/add.php" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; padding: 0.5rem 1rem; font-weight: 600;">
        <i class="bi bi-plus-circle me-1"></i>Add User
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
                        <p class="mb-1 small" style="opacity: 0.9;">Total Users</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= $totalUsers ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">👥</div>
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
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= $activeUsers ?></h4>
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
                        <p class="mb-1 small" style="opacity: 0.9;">Disabled</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= $disabledUsers ?></h4>
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
                        <p class="mb-1 small" style="opacity: 0.9;">Role Types</p>
                        <h4 class="mb-0" style="font-weight: 700; font-size: 2rem;"><?= $roleCount ?></h4>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.3;">🎭</div>
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
            <div class="col-md-4">
                <label class="form-label small text-muted" style="font-weight: 600;">Search</label>
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                       class="form-control"
                       placeholder="Name or email"
                       style="border-radius: 6px; border: 1px solid #e0e0e0;">
            </div>

            <div class="col-md-3">
                <label class="form-label small text-muted" style="font-weight: 600;">Role</label>
                <select name="role" class="form-select" style="border-radius: 6px; border: 1px solid #e0e0e0;">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"
                            <?= ($_GET['role'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($jobTitles)): ?>
            <div class="col-md-3">
                <label class="form-label small text-muted" style="font-weight: 600;">Job Title</label>
                <select name="job_title" class="form-select" style="border-radius: 6px; border: 1px solid #e0e0e0;">
                    <option value="">All Job Titles</option>
                    <?php foreach ($jobTitles as $jt): ?>
                        <option value="<?= (int)$jt['id'] ?>"
                            <?= ($_GET['job_title'] ?? '') == $jt['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($jt['title_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2">
                <label class="form-label small text-muted" style="font-weight: 600;">Status</label>
                <select name="status" class="form-select" style="border-radius: 6px; border: 1px solid #e0e0e0;">
                    <option value="">All Status</option>
                    <option value="1" <?= ($_GET['status'] ?? '') === '1' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="0" <?= ($_GET['status'] ?? '') === '0' ? 'selected' : '' ?>>
                        Disabled
                    </option>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary flex-grow-1" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-search me-2"></i>Filter
                </button>
                <a href="/users/list.php" class="btn btn-outline-secondary" style="border-radius: 6px; font-weight: 600;">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     USERS TABLE
═══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div style="overflow: auto;">
        <table class="table table-hover mb-0" style="border-collapse: collapse;">
            <thead class="table-dark">
                <tr>
                    <th >Name</th>
                    <th >Email</th>
                    <th >Role</th>
                    <th >Job Title</th>
                    <th >Status</th>
                    <th >Actions</th>
                </tr>
            </thead>
            <tbody>

<?php if (empty($users)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5" style="border: none;">
                        <p style="color: #999; font-size: 1rem;">
                            <i class="bi bi-inbox" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 0.5rem;"></i>
                            No users found
                        </p>
                    </td>
                </tr>
<?php else: ?>

<?php foreach ($users as $u): 
    // Determine row styling based on status
    $rowBg = $u['is_active'] ? '#e8f5e9' : '#ffebee';
?>
                <tr style="background-color: <?= $rowBg ?>; border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 1rem; border: none;">
                        <strong style="color: #667eea;"><?= htmlspecialchars($u['full_name']) ?></strong>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <span class="badge" style="background-color: #667eea; color: white; padding: 0.35rem 0.75rem;">
                            <?= htmlspecialchars($u['role_name'] ?? 'No Role') ?>
                        </span>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <?php if (!empty($u['job_title_name'])): ?>
                        <span class="badge bg-secondary" style="padding: 0.35rem 0.75rem;">
                            <?= htmlspecialchars($u['job_title_name']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 1rem; border: none;">
                        <span class="badge" style="background-color: <?= $u['is_active'] ? '#4caf50' : '#f44336' ?>; color: white; padding: 0.35rem 0.75rem;">
                            <?= $u['is_active'] ? '✓ Active' : '✗ Disabled' ?>
                        </span>
                        <?php
                        $isLocked = !empty($u['lock_until']) && strtotime($u['lock_until']) > time();
                        if ($isLocked): ?>
                            <span class="badge" style="background-color: #ff9800; color: white; padding: 0.35rem 0.75rem; margin-left: 0.25rem;" title="Locked until <?= htmlspecialchars($u['lock_until']) ?>">
                                🔒 Locked
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 1rem; border: none; text-align: center;">
                        <div class="dropdown">
                            <button class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 4px; padding: 0.35rem 0.75rem;" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="/users/view.php?id=<?= (int)$u['user_id'] ?>">
                                        <i class="bi bi-eye me-2"></i>View Profile
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/users/permissions.php?id=<?= (int)$u['user_id'] ?>">
                                        <i class="bi bi-shield-lock me-2"></i>Permissions
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/users/reset_password.php?id=<?= (int)$u['user_id'] ?>"
                                       onclick="return confirm('Reset password for this user?')">
                                       <i class="bi bi-key me-2"></i>Reset Password
                                    </a>
                                </li>
                                <?php
                                $isLockedMenu = !empty($u['lock_until']) && strtotime($u['lock_until']) > time();
                                if ($isLockedMenu): ?>
                                <li>
                                    <form method="post" action="/users/unlock.php"
                                          onsubmit="return confirm('Unlock account for <?= htmlspecialchars($u['full_name']) ?>?')">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                        <button type="submit" class="dropdown-item text-warning">
                                            <i class="bi bi-unlock me-2"></i>Unlock Account
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post"
                                          action="/users/delete.php"
                                          onsubmit="return confirm('Permanently delete user <?= htmlspecialchars($u['full_name']) ?>? This cannot be undone.')"
                                          class="d-inline w-100">
                                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
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
        👥 Total: <strong><?= $totalUsers ?></strong> | Active: <strong><?= $activeUsers ?></strong> | Roles: <strong><?= $roleCount ?></strong>
    </small>
</div>

<?php if ($totalRows > 0): ?>
<div class="mt-3">
    <?php renderShowingInfo($page, $perPage, $totalRows); ?>
    <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
</div>
<?php endif; ?>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
