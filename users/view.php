<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';

$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    pop("Invalid user.", "/users/list.php");
    exit;
}

/* ===============================
   Fetch User
================================ */
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name, jt.title_name AS job_title_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN job_titles jt ON u.job_title_id = jt.id
    WHERE u.user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    pop("User not found.", "/users/list.php");
    exit;
}

/* ===============================
   Fetch Role Permissions
================================ */
$stmt = $pdo->prepare("
    SELECT p.name
    FROM role_permissions rp
    JOIN permissions p ON rp.permission_id = p.id
    WHERE rp.role_id = ?
");
$stmt->execute([$user['role_id']]);
$rolePermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

/* ===============================
   Fetch User Overrides
================================ */
$stmt = $pdo->prepare("
    SELECT p.name, up.is_granted, up.expires_at
    FROM user_permissions up
    JOIN permissions p ON up.permission_id = p.id
    WHERE up.user_id = ?
");
$stmt->execute([$user_id]);
$overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   Fetch All Roles (for changing)
================================ */
$rolesStmt = $pdo->query("SELECT id, name FROM roles ORDER BY name ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   Fetch Job Titles (for changing)
================================ */
$jobTitles = [];
try {
    $jobTitles = $pdo->query("SELECT id, title_name FROM job_titles WHERE is_active = 1 ORDER BY sort_order, title_name")
                     ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // graceful degradation
}
?>

<style>
    .info-card {
        background: white;
        border-radius: 10px;
        border: 1px solid #e0e0e0;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 1rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .info-row:last-child {
        border-bottom: none;
    }
    .info-label {
        font-weight: 600;
        color: #666;
        min-width: 150px;
    }
    .info-value {
        color: #1a1a1a;
        text-align: right;
        flex: 1;
    }
    .badge-gradient {
        display: inline-block;
        padding: 0.35rem 0.75rem;
        border-radius: 5px;
        font-size: 0.85rem;
        font-weight: 500;
        color: white;
    }
    .badge-success {
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    }
    .badge-danger {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }
    .badge-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .badge-warning {
        background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    }
    .permissions-container {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin: 1rem 0;
    }
    .permission-badge {
        display: inline-block;
        padding: 0.4rem 0.85rem;
        background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        color: white;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    .btn-modern {
        padding: 0.6rem 1.2rem;
        border-radius: 6px;
        font-weight: 500;
        border: none;
        transition: all 0.3s ease;
        text-decoration: none !important;
        display: inline-block;
    }
    .btn-primary-gradient {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white !important;
    }
    .btn-primary-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        color: white;
    }
    .btn-info-gradient {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white !important;
    }
    .btn-info-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
        color: white;
    }
    .btn-warning-gradient {
        background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        color: white !important;
    }
    .btn-warning-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(250, 112, 154, 0.4);
        color: white;
    }
    .btn-secondary-gradient {
        background: white;
        color: #667eea !important;
        border: 1px solid #e0e0e0;
    }
    .btn-secondary-gradient:hover {
        background: #f8f9fa;
        border-color: #667eea;
    }
    .overrides-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    .overrides-table thead {
        background: #f8f9fa;
        border-bottom: 2px solid #e0e0e0;
    }
    .overrides-table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        color: #333;
    }
    .overrides-table td {
        padding: 1rem;
        border-bottom: 1px solid #f0f0f0;
        color: #1a1a1a;
    }
    .overrides-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    .btn-modern {
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .modal-header {
        border-radius: 8px 8px 0 0;
    }
    .modal-content {
        border-radius: 8px !important;
    }
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-lg-12">
            <h2 style="font-weight: 700; color: #1a1a1a; margin-bottom: 0.5rem;">👤 User Details</h2>
            <p style="color: #666; font-size: 0.95rem; margin-bottom: 1.5rem;">View and manage user profile information, roles, and permissions</p>
        </div>
    </div>

    <!-- User Information Card -->
    <div class="info-card">
        <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h4 style="font-weight: 600; color: #1a1a1a; margin: 0;"><i class="bi bi-person-circle"></i> Profile Information</h4>
            <div style="display: flex; gap: 0.5rem;">
                <button class="btn-modern btn-warning-gradient" style="padding: 0.4rem 0.9rem; font-size: 0.85rem;" data-bs-toggle="modal" data-bs-target="#roleModal">
                    <i class="bi bi-shuffle"></i> Change Role
                </button>
                <?php if (!empty($jobTitles)): ?>
                <button class="btn-modern" style="padding: 0.4rem 0.9rem; font-size: 0.85rem; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;" data-bs-toggle="modal" data-bs-target="#jobTitleModal">
                    <i class="bi bi-briefcase"></i> Change Job Title
                </button>
                <?php endif; ?>
                <button class="btn-modern" style="padding: 0.4rem 0.9rem; font-size: 0.85rem; background: <?= $user['is_active'] ? '#f093fb' : '#43e97b' ?>; color: white;" data-bs-toggle="modal" data-bs-target="#statusModal">
                    <i class="bi bi-toggle-<?= $user['is_active'] ? 'on' : 'off' ?>"></i> <?= $user['is_active'] ? 'Disable' : 'Enable' ?> User
                </button>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-person"></i> Full Name</div>
            <div class="info-value"><strong><?= htmlspecialchars($user['full_name']) ?></strong></div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-envelope"></i> Email</div>
            <div class="info-value"><code style="background: #f8f9fa; padding: 0.3rem 0.5rem; border-radius: 4px; color: #667eea;"><?= htmlspecialchars($user['email']) ?></code></div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-shield"></i> Role</div>
            <div class="info-value">
                <span class="badge-gradient badge-primary">
                    <?= htmlspecialchars($user['role_name']) ?>
                </span>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-briefcase"></i> Job Title</div>
            <div class="info-value">
                <?php if (!empty($user['job_title_name'])): ?>
                    <span class="badge-gradient badge-primary" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <?= htmlspecialchars($user['job_title_name']) ?>
                    </span>
                <?php else: ?>
                    <span style="color: #999;">Not assigned</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-check-circle"></i> Status</div>
            <div class="info-value">
                <?= $user['is_active']
                    ? '<span class="badge-gradient badge-success">Active</span>'
                    : '<span class="badge-gradient badge-danger">Disabled</span>' ?>
            </div>
        </div>

        <?php
        $isLocked = !empty($user['lock_until']) && strtotime($user['lock_until']) > time();
        ?>
        <div class="info-row">
            <div class="info-label"><i class="bi bi-shield-lock"></i> Account Lock</div>
            <div class="info-value">
                <?php if ($isLocked): ?>
                    <span class="badge-gradient badge-warning" style="margin-right: 0.5rem;">🔒 Locked</span>
                    <small class="text-muted">until <?= date('d M Y, h:i A', strtotime($user['lock_until'])) ?></small>
                    <small class="text-muted d-block mt-1">Failed attempts: <?= (int)$user['failed_attempts'] ?></small>
                <?php elseif ((int)($user['failed_attempts'] ?? 0) > 0): ?>
                    <span style="color: #ff9800; font-weight: 500;">⚠️ <?= (int)$user['failed_attempts'] ?> failed attempt<?= $user['failed_attempts'] > 1 ? 's' : '' ?></span>
                <?php else: ?>
                    <span style="color: #999;">No issues</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-calendar-event"></i> Created At</div>
            <div class="info-value"><?= htmlspecialchars($user['created_at'] ?? '—') ?></div>
        </div>
    </div>

    <!-- Role Permissions Card -->
    <div class="info-card">
        <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <h4 style="font-weight: 600; color: #1a1a1a; margin: 0;"><i class="bi bi-key"></i> Role Permissions (Inherited)</h4>
        </div>

        <?php if (empty($rolePermissions)): ?>
            <p style="color: #999; margin: 0;"><i class="bi bi-info-circle"></i> No permissions assigned to this role.</p>
        <?php else: ?>
            <div class="permissions-container">
                <?php foreach ($rolePermissions as $perm): ?>
                    <span class="permission-badge">
                        <i class="bi bi-check-lg"></i> <?= htmlspecialchars($perm) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- User Overrides Card -->
    <div class="info-card">
        <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <h4 style="font-weight: 600; color: #1a1a1a; margin: 0;"><i class="bi bi-lightning"></i> User Permission Overrides</h4>
        </div>

        <?php if (empty($overrides)): ?>
            <p style="color: #999; margin: 0;"><i class="bi bi-info-circle"></i> No user-level overrides configured.</p>
        <?php else: ?>
            <table class="overrides-table">
                <thead>
                    <tr>
                        <th><i class="bi bi-key"></i> Permission</th>
                        <th><i class="bi bi-toggle-on"></i> Override</th>
                        <th><i class="bi bi-calendar-x"></i> Expires</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overrides as $o): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($o['name']) ?></strong></td>
                        <td>
                            <?= $o['is_granted']
                                ? '<span class="badge-gradient badge-success">Force Allow</span>'
                                : '<span class="badge-gradient badge-danger">Force Deny</span>' ?>
                        </td>
                        <td>
                            <?= $o['expires_at']
                                ? date('d M Y, h:i A', strtotime($o['expires_at']))
                                : '<span style="color: #999;">No Expiry</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Action Buttons -->
    <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-bottom: 2rem; flex-wrap: wrap;">
        <a href="/users/permissions.php?id=<?= (int)$user_id ?>"
           class="btn-modern btn-primary-gradient">
           <i class="bi bi-key"></i> Manage Permissions
        </a>

        <a href="/users/reset_password.php?id=<?= (int)$user_id ?>"
           class="btn-modern btn-warning-gradient">
           <i class="bi bi-key-fill"></i> Reset Password
        </a>

        <?php if ($isLocked || (int)($user['failed_attempts'] ?? 0) > 0): ?>
        <form method="post" action="/users/unlock.php" class="d-inline"
              onsubmit="return confirm('Unlock account and reset failed login attempts for <?= htmlspecialchars($user['full_name']) ?>?')">
            <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
            <button type="submit" class="btn-modern" style="background: linear-gradient(135deg, #ff9800 0%, #ff5722 100%); color: white;">
                <i class="bi bi-unlock"></i> Unlock Account
            </button>
        </form>
        <?php endif; ?>

        <a href="/users/list.php"
           class="btn-modern btn-secondary-gradient">
           <i class="bi bi-arrow-left"></i> Back to Users
        </a>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Change Role
═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h5 class="modal-title" id="roleModalLabel"><i class="bi bi-shuffle"></i> Change User Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="roleForm" action="/users/update_role.php" method="POST">
                    <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                    
                    <p style="color: #666; margin-bottom: 1.5rem;">
                        Select a new role for <strong><?= htmlspecialchars($user['full_name']) ?></strong>. This will update their access permissions immediately.
                    </p>

                    <div class="mb-3">
                        <label for="roleSelect" class="form-label fw-600">New Role</label>
                        <select class="form-select form-select-lg" name="role_id" id="roleSelect" required style="border-color: #e0e0e0;">
                            <option value="">— Select a Role —</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= (int)$role['id'] ?>" <?= $role['id'] == $user['role_id'] ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($role['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="alert alert-info border-0" style="background-color: #e3f2fd; color: #1565c0;">
                        <i class="bi bi-info-circle me-2"></i>
                        <small><strong>Note:</strong> You cannot change your own role. This action will be audited.</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; font-weight: 600;" onclick="document.getElementById('roleForm').submit();">
                    <i class="bi bi-check-lg me-1"></i> Update Role
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Change Job Title
═══════════════════════════════════════════════════════ -->
<?php if (!empty($jobTitles)): ?>
<div class="modal fade" id="jobTitleModal" tabindex="-1" aria-labelledby="jobTitleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                <h5 class="modal-title" id="jobTitleModalLabel"><i class="bi bi-briefcase"></i> Change Job Title</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="jobTitleForm" action="/users/update_job_title.php" method="POST">
                    <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                    <p style="color: #666; margin-bottom: 1.5rem;">
                        Select a job title for <strong><?= htmlspecialchars($user['full_name']) ?></strong>.
                    </p>
                    <div class="mb-3">
                        <label for="jobTitleSelect" class="form-label fw-600">Job Title</label>
                        <select class="form-select form-select-lg" name="job_title_id" id="jobTitleSelect" style="border-color: #e0e0e0;">
                            <option value="">— Remove / Clear Job Title —</option>
                            <?php foreach ($jobTitles as $jt): ?>
                                <option value="<?= (int)$jt['id'] ?>" <?= $jt['id'] == ($user['job_title_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($jt['title_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary"
                        style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border: none; font-weight: 600;"
                        onclick="document.getElementById('jobTitleForm').submit();">
                    <i class="bi bi-check-lg me-1"></i> Update Job Title
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Toggle User Status
═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom" style="background: <?= $user['is_active'] ? 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)' : 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)' ?>; color: white;">
                <h5 class="modal-title" id="statusModalLabel"><i class="bi bi-exclamation-triangle"></i> <?= $user['is_active'] ? 'Disable' : 'Enable' ?> User Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="statusForm" action="/users/toggle_status.php" method="POST">
                    <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
                    <input type="hidden" name="current" value="<?= $user['is_active'] ? 1 : 0 ?>">
                    
                    <?php if ($user['is_active']): ?>
                        <div class="alert alert-warning border-0" style="background-color: #fff3cd; color: #856404;">
                            <i class="bi bi-exclamation-circle me-2"></i>
                            <strong>Disable Account?</strong>
                        </div>
                        <p style="color: #666; margin-bottom: 1rem;">
                            This user will no longer be able to access the system. Their active sessions will remain valid until they expire, but they won't be able to log in again.
                        </p>
                        <p style="color: #999; font-size: 0.9rem;">
                            <strong><?= htmlspecialchars($user['full_name']) ?></strong> (<?= htmlspecialchars($user['email']) ?>)
                        </p>
                    <?php else: ?>
                        <div class="alert alert-success border-0" style="background-color: #d4edda; color: #155724;">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong>Re-enable Account?</strong>
                        </div>
                        <p style="color: #666; margin-bottom: 1rem;">
                            This user will be able to log in and access the system again with their current role and permissions.
                        </p>
                        <p style="color: #999; font-size: 0.9rem;">
                            <strong><?= htmlspecialchars($user['full_name']) ?></strong> (<?= htmlspecialchars($user['email']) ?>)
                        </p>
                    <?php endif; ?>
                </form>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="<?= $user['is_active'] ? 'background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border: none;' : 'background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none;' ?> font-weight: 600;" onclick="document.getElementById('statusForm').submit();">
                    <i class="bi bi-toggle-<?= $user['is_active'] ? 'on' : 'off' ?> me-1"></i> <?= $user['is_active'] ? 'Disable User' : 'Enable User' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
