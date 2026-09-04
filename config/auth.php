<?php
$httpsEnabled = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $httpsEnabled,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/app.php';

// Timezone already set in app.php - no need to set again

/* Layer 2: Session hijacking protection */
if (
    isset($_SESSION['ip_address'], $_SESSION['user_agent']) &&
    (
        $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR'] ||
        $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']
    )
) {
    session_unset();
    session_destroy();
    header("Location: /auth/login.php?security=1");
    exit;
}

/* Inactivity timeout (seconds) */
$timeout = 900; // 15 minutes

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: /auth/login.php?timeout=1");
    exit;
}

$_SESSION['LAST_ACTIVITY'] = time();

/* Auth check */
if (!isset($_SESSION['user_id'])) {
    header("Location: /auth/login.php");
    exit;
}

/* Load acting-role helpers (available on every page) */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/acting_roles.php';

/**
 * Access policy helpers.
 * - Uses pop() for consistent user feedback + safe redirects.
 * - Admin is treated as a super-role (always allowed).
 *
 * Configure defaults via:
 *   define('POP_DEFAULT_DELAY_MS', 1400);
 *   define('POLICY_DENY_FALLBACK_REDIRECT', '/');
 */

require_once $_SERVER['DOCUMENT_ROOT'] . "/config/helper.php";

require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";

/**
 * Check if logged in user has a specific permission
 */


function has_permission($permission_name) {
    global $pdo;

if ($_SESSION['role_name'] === 'SuperAdmin') {
    return true;
}
if ($_SESSION['role_name'] === 'Admin') {
    return true;
}


    if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
        return false;
    }

    $user_id = $_SESSION['user_id'];
    $role_id = $_SESSION['role_id'];

    /* Get permission ID */
    $stmt = $pdo->prepare("SELECT id FROM permissions WHERE name = ?");
    $stmt->execute([$permission_name]);
    $permission_id = $stmt->fetchColumn();

    if (!$permission_id) return false;

    /* 1️⃣ Check user override (not expired) */
    $stmt = $pdo->prepare("
        SELECT is_granted
        FROM user_permissions
        WHERE user_id = ?
          AND permission_id = ?
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([$user_id, $permission_id]);
    $override = $stmt->fetchColumn();

    if ($override !== false) {
        return (bool)$override;
    }

    /* 2️⃣ Primary role permission */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM role_permissions
        WHERE role_id = ?
          AND permission_id = ?
    ");
    $stmt->execute([$role_id, $permission_id]);

    if ($stmt->fetchColumn() > 0) {
        return true;
    }

    /* 3️⃣ Additional roles via user_roles table (multi-role support) */
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM user_roles ur
            INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
            INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
            WHERE ur.user_id = ?
              AND rp.permission_id = ?
              AND ur.role_id != ?
        ");
        $stmt->execute([$user_id, $permission_id, $role_id]);
        return $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        // user_roles table may not exist yet — fall back gracefully
        return false;
    }
}




/**
 * Require a permission to access page
 */
function require_permission(string $permission)
{
    if (!has_permission($permission)) {

        pop(
            'You do not have permission to access this page.',
            '/dashboard/index.php',
            2000,
            'error'
        );

        exit;
    }
}

?>