<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/notifications.php";

$message = '';
$error = '';

/* Handle new role creation */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_role') {
    $role_name = trim($_POST['role_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$role_name) {
        $error = "Role name is required.";
    } else {
        try {
            /* Check if role already exists */
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE LOWER(name) = LOWER(?)");
            $stmt->execute([$role_name]);
            if ($stmt->fetch()) {
                $error = "Role already exists.";
            } else {
                /* Insert new role */
                $stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
                $stmt->execute([$role_name, $description]);
                $role_id = $pdo->lastInsertId();

                /* Audit log */
                logAudit($pdo, 'roles', $role_id, 'CREATE', "New role '{$role_name}' created.");

                $message = "Role '{$role_name}' created successfully.";
            }
        } catch (Exception $e) {
            $error = "Error creating role: " . htmlspecialchars(extractDbMessage($e));
        }
    }
}

/* Handle user creation */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $full_name    = trim($_POST['full_name']    ?? '');
    $email        = trim($_POST['email']        ?? '');
    $role_id      = (int)($_POST['role_id']     ?? 0);
    $job_title_id = ($_POST['job_title_id'] !== '') ? (int)$_POST['job_title_id'] : null;
    $password     = $_POST['password']          ?? '';

    if (!$full_name || !$email || !$role_id || !$password) {
        $error = "All fields are required.";
    } elseif (($passwordPolicyError = validatePasswordPolicy($password)) !== null) {
        $error = $passwordPolicyError;
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users
                (full_name, email, role_id, job_title_id, password_hash, must_change_password, is_active)
                VALUES (?, ?, ?, ?, ?, 1, 1)
            ");
            $stmt->execute([$full_name, $email, $role_id, $job_title_id, $hash]);

            $newUserId = $pdo->lastInsertId();

            /* Audit log */
            logAudit($pdo, 'users', $newUserId, 'CREATE', "User '{$full_name}' ({$email}) created by admin.");

            /* Get role name for notification */
            $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $roleStmt->execute([$role_id]);
            $roleName = $roleStmt->fetchColumn();

            /* Send welcome notification email to new user */
            notifyNewUser($newUserId, $email, $full_name, $roleName ?: 'User');

            header("Location: /users/list.php?created=1");
            exit;
        } catch (Exception $e) {
            $error = "Error creating user: " . htmlspecialchars(extractDbMessage($e));
        }
    }
}

/* Fetch all roles */
$rolesStmt = $pdo->query("SELECT id, name, description FROM roles ORDER BY name ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch job titles */
$jobTitlesReady = false;
$jobTitles = [];
try {
    $jt = $pdo->query("SELECT id, title_name FROM job_titles WHERE is_active = 1 ORDER BY sort_order, title_name");
    $jobTitles = $jt->fetchAll(PDO::FETCH_ASSOC);
    $jobTitlesReady = true;
} catch (Throwable $e) {
    // job_titles table may not exist yet; gracefully degrade
}

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
?>

<h3 class="section-title">User & Role Management</h3>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4" id="userRoleTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="user-tab" data-bs-toggle="tab" data-bs-target="#user-pane" type="button" role="tab" aria-controls="user-pane" aria-selected="true">
            👤 Add New User
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="role-tab" data-bs-toggle="tab" data-bs-target="#role-pane" type="button" role="tab" aria-controls="role-pane" aria-selected="false">
            🎭 Add New Role
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="roles-tab" data-bs-toggle="tab" data-bs-target="#roles-pane" type="button" role="tab" aria-controls="roles-pane" aria-selected="false">
            📋 View All Roles
        </button>
    </li>
</ul>

<!-- Tab Content -->
<div class="tab-content" id="userRoleTabContent">
    
    <!-- User Creation Tab -->
    <div class="tab-pane fade show active" id="user-pane" role="tabpanel" aria-labelledby="user-tab">
        <div class="row">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        ➕ Add New User
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="create_user">

                            <div class="mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control" required placeholder="John Doe">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" required placeholder="john@example.com">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Assign Role</label>
                                <select name="role_id" class="form-select" required>
                                    <option value="">-- Select a Role --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['id'] ?>">
                                            <?= htmlspecialchars($role['name']) ?>
                                            <?php if ($role['description']): ?>
                                                (<?= htmlspecialchars(substr($role['description'], 0, 30)) ?>...)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">Select the user's role to determine their permissions.</small>
                            </div>

                            <?php if ($jobTitlesReady): ?>
                            <div class="mb-3">
                                <label class="form-label">Job Title</label>
                                <select name="job_title_id" class="form-select">
                                    <option value="">— Select Job Title —</option>
                                    <?php foreach ($jobTitles as $jt): ?>
                                        <option value="<?= (int)$jt['id'] ?>">
                                            <?= htmlspecialchars($jt['title_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-1">The user's official job title.</small>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Temporary Password</label>
                                <input type="password" name="password" class="form-control" required>
                                <small class="text-muted d-block mt-1">
                                    User will be required to change this password on first login.
                                </small>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success flex-grow-1">
                                    ✓ Create User
                                </button>
                                <a href="/users/list.php" class="btn btn-outline-secondary">
                                    Back to Users
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Role Creation Tab -->
    <div class="tab-pane fade" id="role-pane" role="tabpanel" aria-labelledby="role-tab">
        <div class="row">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-info text-white">
                        ➕ Add New Role
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="create_role">

                            <div class="mb-3">
                                <label class="form-label">Role Name</label>
                                <input type="text" name="role_name" class="form-control" placeholder="e.g., Auditor, Manager" required>
                                <small class="text-muted d-block mt-1">
                                    Unique name for this role (e.g., Procurement Auditor, Vendor Manager).
                                </small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="4" placeholder="Describe the role's purpose, responsibilities, and authority level..."></textarea>
                                <small class="text-muted d-block mt-1">
                                    Explain what this role does and who should be assigned to it.
                                </small>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success flex-grow-1">
                                    ✓ Create Role
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    Clear
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View All Roles Tab -->
    <div class="tab-pane fade" id="roles-pane" role="tabpanel" aria-labelledby="roles-tab">
        <div class="row">
            <div class="col-md-12 col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-secondary text-white">
                        📋 All System Roles (<?= count($roles) ?>)
                    </div>
                    <div class="card-body">
                        <?php if (empty($roles)): ?>
                            <p class="text-muted text-center">No roles configured. Create one using the "Add New Role" tab above.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($roles as $role): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">🎭 <?= htmlspecialchars($role['name']) ?></h6>
                                                <?php if ($role['description']): ?>
                                                    <p class="mb-0 text-muted small">
                                                        <?= htmlspecialchars($role['description']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge bg-dark">ID: <?= $role['id'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>