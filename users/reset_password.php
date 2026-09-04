<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT']."/config/page_guard.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";

/* Admin only */

$userId = $_GET['id'] ?? null;
if (!$userId) {
    pop('Invalid request', '/users/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

/* Fetch user */
$stmt = $pdo->prepare("
    SELECT user_id, full_name, email
    FROM users
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    pop('User not found', '/users/list.php', POP_DEFAULT_DELAY_MS, 'error');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_POST['password'] !== $_POST['confirm']) {
        $error = "Passwords do not match.";
    } elseif (($passwordPolicyError = validatePasswordPolicy($_POST['password'])) !== null) {
        $error = $passwordPolicyError;
    } else {

        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

        try {
        /* Update password + force change */
        $pdo->prepare("
            UPDATE users
            SET password_hash = ?,
                must_change_password = 1
            WHERE user_id = ?
        ")->execute([$hash, $userId]);

        /* Audit log */
        $pdo->prepare("
            INSERT INTO audit_log
            (table_name, record_id, action, changed_by, notes)
            VALUES ('users', ?, 'ADMIN_PASSWORD_RESET', ?, 'Admin reset user password')
        ")->execute([$userId, $_SESSION['user_id']]);

        header("Location: /users/list.php?reset=1");
        exit;
        } catch (Throwable $e) {
            $error = extractDbMessage($e);
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT']."/includes/header.php";
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
        padding: 1rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .info-row:last-child {
        border-bottom: none;
    }
    .info-label {
        font-weight: 600;
        color: #666;
        margin-bottom: 0.3rem;
    }
    .info-value {
        color: #1a1a1a;
        font-size: 1.1rem;
        word-break: break-word;
    }
    .form-card {
        background: white;
        border-radius: 10px;
        border: 1px solid #e0e0e0;
        padding: 2rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        max-width: 500px;
    }
    .form-group {
        margin-bottom: 1.5rem;
    }
    .form-label {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 0.5rem;
        display: block;
    }
    .form-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        border: 1px solid #d0d0d0;
        font-size: 1rem;
        transition: all 0.3s ease;
        font-family: inherit;
    }
    .form-input:hover {
        border-color: #667eea;
    }
    .form-input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    .alert-box {
        border-radius: 8px;
        padding: 1rem;
        border-left: 4px solid;
        margin-bottom: 1.5rem;
    }
    .alert-info-custom {
        background: #f0f7ff;
        border-left-color: #4facfe;
    }
    .alert-danger-custom {
        background: #fff5f5;
        border-left-color: #f5576c;
        color: #c41e3a;
    }
    .btn-modern {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        border: none;
        transition: all 0.3s ease;
        text-decoration: none !important;
        display: inline-block;
        cursor: pointer;
    }
    .btn-primary-gradient {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white !important;
    }
    .btn-primary-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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
    .btn-group {
        display: flex;
        gap: 1rem;
        margin-top: 2rem;
    }
</style>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-lg-12">
            <a href="/users/list.php" style="display: inline-block; margin-bottom: 1rem; color: #667eea; text-decoration: none; font-weight: 500;">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>
            <h2 style="font-weight: 700; color: #1a1a1a; margin-bottom: 0.5rem;">🔑 Reset User Password</h2>
            <p style="color: #666; font-size: 0.95rem; margin-bottom: 2rem;">Set a temporary password for the user. They will be required to change it on their next login.</p>
        </div>
    </div>

    <!-- User Info Card -->
    <div class="info-card" style="max-width: 500px; margin-bottom: 2rem;">
        <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <h4 style="font-weight: 600; color: #1a1a1a; margin: 0;"><i class="bi bi-person-circle"></i> User Account</h4>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-person"></i> Full Name</div>
            <div class="info-value"><?= htmlspecialchars($user['full_name']) ?></div>
        </div>

        <div class="info-row">
            <div class="info-label"><i class="bi bi-envelope"></i> Email</div>
            <div class="info-value"><code style="background: #f8f9fa; padding: 0.3rem 0.5rem; border-radius: 4px; color: #667eea;"><?= htmlspecialchars($user['email']) ?></code></div>
        </div>
    </div>

    <!-- Reset Password Form -->
    <div class="form-card">
        <div style="margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
            <h4 style="font-weight: 600; color: #1a1a1a; margin: 0;"><i class="bi bi-key-fill"></i> Set New Password</h4>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-box alert-danger-custom">
                <i class="bi bi-exclamation-triangle"></i> <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label class="form-label"><i class="bi bi-lock"></i> Temporary Password</label>
                <input type="password" name="password" class="form-input" placeholder="Enter temporary password" required>
                <small style="color: #999; display: block; margin-top: 0.3rem;">Use a strong, temporary password</small>
            </div>

            <div class="form-group">
                <label class="form-label"><i class="bi bi-lock-check"></i> Confirm Password</label>
                <input type="password" name="confirm" class="form-input" placeholder="Re-enter temporary password" required>
                <small style="color: #999; display: block; margin-top: 0.3rem;">Must match the password above</small>
            </div>

            <div class="alert-box alert-info-custom">
                <strong><i class="bi bi-info-circle"></i> Important:</strong> The user will be required to change this password on their next login.
            </div>

            <div class="btn-group">
                <button type="submit" class="btn-modern btn-primary-gradient">
                    <i class="bi bi-shield-check"></i> Reset Password
                </button>
                <a href="/users/list.php" class="btn-modern btn-secondary-gradient">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT']."/includes/footer.php"; ?>
