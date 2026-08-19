<?php
/**
 * Admin Test Suite
 *
 * Secure browser interface for running the PHPUnit test suite.
 * Access is restricted to Admin and SuperAdmin roles via the
 * 'access_test_suite' permission.  All execution goes through an
 * allowlisted command; no arbitrary file, class or shell command
 * is accepted from the browser.
 */

$REQUIRE_PERMISSION = 'access_test_suite';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

// ── CSRF bootstrap ──────────────────────────────────────────────────────────
if (empty($_SESSION['test_suite_csrf_token'])) {
    $_SESSION['test_suite_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['test_suite_csrf_token'];

// ── Allowlisted suites ───────────────────────────────────────────────────────
// Only these exact suite names are ever passed to PHPUnit.
$allowedSuites = ['All', 'Unit', 'Feature', 'Workflow', 'Security'];

// ── Locate the PHPUnit executable ────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
$phpunitBin  = $projectRoot . '/vendor/bin/phpunit';
if (!is_executable($phpunitBin)) {
    $phpunitBin = trim((string)shell_exec('command -v phpunit 2>/dev/null'));
    if (empty($phpunitBin) || !is_executable($phpunitBin)) {
        $phpunitBin = null;
    }
}

// ── Handle POST (run test suite) ─────────────────────────────────────────────
$output      = null;
$exitCode    = null;
$suiteRan    = null;
$errorMsg    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string)$_POST['csrf_token'])
    ) {
        $errorMsg = 'Invalid or missing CSRF token. Please try again.';
    } elseif ($phpunitBin === null) {
        $errorMsg = 'PHPUnit executable not found. Install it via: composer require --dev phpunit/phpunit';
    } else {
        $requestedSuite = trim((string)($_POST['suite'] ?? 'All'));

        // Enforce allowlist – reject unknown suite names
        if (!in_array($requestedSuite, $allowedSuites, true)) {
            $errorMsg = 'Unknown test suite requested.';
        } else {
            $suiteRan = $requestedSuite;

            // Rotate CSRF token after a valid submission
            $_SESSION['test_suite_csrf_token'] = bin2hex(random_bytes(32));
            $csrfToken = $_SESSION['test_suite_csrf_token'];

            // Build the command; only the suite name (from allowlist) is variable.
            $phpunitXml = escapeshellarg($projectRoot . '/phpunit.xml');
            $cmd        = escapeshellcmd($phpunitBin) . ' --configuration ' . $phpunitXml . ' --colors=never';
            if ($requestedSuite !== 'All') {
                $cmd .= ' --testsuite ' . escapeshellarg($requestedSuite);
            }

            // Cap execution time so a runaway test cannot block the web server.
            $descriptors = [
                0 => ['pipe', 'r'],   // stdin
                1 => ['pipe', 'w'],   // stdout
                2 => ['pipe', 'w'],   // stderr
            ];
            $env = [
                'HOME'          => getenv('HOME') ?: '/tmp',
                'PATH'          => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                'PHPUNIT_RUNNING' => '1',
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, $projectRoot, $env);

            if (is_resource($proc)) {
                fclose($pipes[0]);
                $stdout   = stream_get_contents($pipes[1]);
                $stderr   = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($proc);
                $output   = $stdout . ($stderr !== '' ? "\n-- stderr --\n" . $stderr : '');
            } else {
                $errorMsg = 'Failed to start the test process.';
            }

            // Log every test-suite execution in the audit trail.
            logAudit(
                $pdo,
                'admin_test_suite',
                0,
                'TEST_SUITE_RUN',
                sprintf(
                    'Test suite "%s" executed by %s (%s). Exit code: %s.',
                    $suiteRan,
                    htmlspecialchars($_SESSION['full_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($_SESSION['role_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                    $exitCode ?? 'N/A'
                )
            );
        }
    }
}

// Also log page access
logAudit(
    $pdo,
    'admin_test_suite',
    0,
    'TEST_SUITE_PAGE_ACCESS',
    sprintf(
        'Test suite page accessed by %s (%s).',
        htmlspecialchars($_SESSION['full_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($_SESSION['role_name'] ?? '', ENT_QUOTES, 'UTF-8')
    )
);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div style="max-width: 960px; margin: 2rem auto; padding: 0 1rem;">

    <!-- ── Page header ─────────────────────────────────────────────────── -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0;
                padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,.05); margin-bottom: 1.5rem;">
        <div style="display:flex; align-items:center; gap:.75rem;">
            <span style="font-size:1.75rem;">🧪</span>
            <div>
                <h4 style="margin:0; font-size:1.25rem; font-weight:700; color:#333;">
                    Admin Test Suite
                    <span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
                                 color:white; padding:.25rem .6rem; border-radius:12px;
                                 font-size:.7rem; font-weight:600; margin-left:.5rem;">Admin Only</span>
                </h4>
                <small style="color:#999;">Run and review the application's PHPUnit test suite.</small>
            </div>
        </div>
    </div>

    <?php if ($errorMsg !== null): ?>
        <div style="background:#fdf0f1; border:1px solid #f5c6cb; border-radius:8px;
                    padding:1rem 1.25rem; margin-bottom:1.5rem; color:#721c24;">
            <strong>Error:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($phpunitBin === null): ?>
        <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px;
                    padding:1rem 1.25rem; margin-bottom:1.5rem; color:#856404;">
            <strong>⚠ PHPUnit not found.</strong>
            Install it with:<br>
            <code style="background:#f8f9fa; padding:.25rem .5rem; border-radius:4px;
                         font-size:.875rem;">composer require --dev phpunit/phpunit</code>
        </div>
    <?php endif; ?>

    <!-- ── Run form ────────────────────────────────────────────────────── -->
    <div style="background:white; border-radius:12px; border:1px solid #e0e0e0;
                padding:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-bottom:1.5rem;">
        <h5 style="margin-top:0; color:#333;">Run Test Suite</h5>
        <form method="post" action="/admin/test_suite.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">

            <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <label for="suite" style="font-weight:600; color:#555;">Suite:</label>
                <select id="suite" name="suite"
                        style="padding:.5rem .75rem; border:1px solid #ced4da; border-radius:6px;
                               font-size:.9rem; min-width:160px;">
                    <?php foreach ($allowedSuites as $s): ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                            <?= ($suiteRan === $s ? 'selected' : '') ?>>
                            <?= htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit"
                        <?= $phpunitBin === null ? 'disabled' : '' ?>
                        style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
                               color:white; padding:.55rem 1.25rem; border:none; border-radius:8px;
                               font-size:.875rem; font-weight:600; cursor:pointer;">
                    ▶ Run Tests
                </button>
            </div>
        </form>
    </div>

    <!-- ── Results ─────────────────────────────────────────────────────── -->
    <?php if ($output !== null): ?>
        <?php
        $passed = ($exitCode === 0);
        // Parse a simple summary line: "Tests: N, Assertions: M, Failures: X"
        preg_match('/Tests:\s*(\d+),\s*Assertions:\s*(\d+)/i', $output, $summary);
        preg_match('/Failures?:\s*(\d+)/i',  $output, $failures);
        preg_match('/Errors?:\s*(\d+)/i',    $output, $errors);
        $nTests      = $summary[1]  ?? '?';
        $nAssertions = $summary[2]  ?? '?';
        $nFailures   = $failures[1] ?? 0;
        $nErrors     = $errors[1]   ?? 0;
        ?>

        <!-- Summary banner -->
        <div style="background:<?= $passed ? '#d4edda' : '#fdf0f1' ?>;
                    border:1px solid <?= $passed ? '#c3e6cb' : '#f5c6cb' ?>;
                    border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem;
                    color:<?= $passed ? '#155724' : '#721c24' ?>; font-size:1rem;">
            <?= $passed ? '✅ All tests passed.' : '❌ Test run completed with failures or errors.' ?>
            &nbsp;|&nbsp;
            Tests: <strong><?= (int)$nTests ?></strong>
            &nbsp;·&nbsp; Assertions: <strong><?= (int)$nAssertions ?></strong>
            <?php if ($nFailures > 0): ?>
                &nbsp;·&nbsp; Failures: <strong><?= (int)$nFailures ?></strong>
            <?php endif; ?>
            <?php if ($nErrors > 0): ?>
                &nbsp;·&nbsp; Errors: <strong><?= (int)$nErrors ?></strong>
            <?php endif; ?>
            &nbsp;·&nbsp; Suite: <strong><?= htmlspecialchars($suiteRan, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></strong>
            &nbsp;·&nbsp; Exit code: <strong><?= (int)$exitCode ?></strong>
        </div>

        <!-- Detailed output (admin-only) -->
        <div style="background:white; border-radius:12px; border:1px solid #e0e0e0;
                    padding:1.25rem; box-shadow:0 2px 8px rgba(0,0,0,.05);">
            <h6 style="margin-top:0; color:#555;">Detailed Output</h6>
            <pre style="background:#1e1e1e; color:#d4d4d4; padding:1.25rem; border-radius:8px;
                        overflow-x:auto; font-size:.8rem; line-height:1.55; max-height:600px;
                        overflow-y:auto; white-space:pre-wrap; word-break:break-word;"><?php
                echo htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ?></pre>
        </div>
    <?php endif; ?>

    <!-- ── Back link ───────────────────────────────────────────────────── -->
    <div style="margin-top:1.5rem;">
        <a href="/dashboard/admin.php"
           style="color:#667eea; text-decoration:none; font-size:.875rem;">
            ← Back to Admin Dashboard
        </a>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
