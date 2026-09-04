<?php
/**
 * PHPUnit Bootstrap
 *
 * Sets up the environment for running the PIAMS test suite.
 * Loaded by PHPUnit before any test is executed.
 */

// Autoload Composer dependencies (including PHPUnit itself)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!defined('UNIT_TESTING')) {
    define('UNIT_TESTING', true);
}

// Point DOCUMENT_ROOT at the project root so page-level includes resolve
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

// Suppress headers in unit-test context
if (!function_exists('header')) {
    function header(string $header, bool $replace = true, int $response_code = 0): void {}
}

// Seed an empty session superglobal so workflow helpers that read $_SESSION
// do not emit notices.
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// Minimal helper stubs used throughout the application but not relevant to
// individual unit tests.  Only defined when the real function is not yet loaded.
if (!function_exists('pop')) {
    function pop(string $msg, string $redirect = '/', int $delay = 3000, string $type = 'info'): void {}
}
if (!function_exists('modalPop')) {
    function modalPop(string $title, string $msg, string $redirect = '/', string $type = 'info'): void {}
}
if (!function_exists('logAudit')) {
    function logAudit($pdo, string $table, int $id, string $action, string $detail = ''): void {}
}
if (!function_exists('extractDbMessage')) {
    function extractDbMessage(\Throwable $e): string { return $e->getMessage(); }
}
if (!function_exists('normalizeCurrency')) {
    function normalizeCurrency(string $code): string { return $code; }
}
if (!function_exists('has_permission')) {
    /**
     * Default stub: grants every permission so tests that want restricted
     * behaviour must override $_SESSION['role_name'] explicitly.
     */
    function has_permission(string $perm): bool
    {
        $role = $_SESSION['role_name'] ?? '';
        if (in_array($role, ['Admin', 'SuperAdmin'], true)) {
            return true;
        }
        // Grant specific permissions that are explicitly assigned
        $granted = $_SESSION['_granted_permissions'] ?? [];
        return in_array($perm, $granted, true);
    }
}
if (!function_exists('require_permission')) {
    function require_permission(string $perm): void {}
}

if (!function_exists('hasPermission')) {
    function hasPermission(string $perm): bool
    {
        return has_permission($perm);
    }
}
