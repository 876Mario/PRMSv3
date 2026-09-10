<?php
/**
 * WorkflowModalSafetyTest
 * =======================
 * Verifies the shared modal manager contract used by workflow reason/comment
 * modals so backdrop cleanup, double-submit prevention, and CSRF protection
 * remain in place.
 */

$root = dirname(__DIR__, 2);

$files = [
    'footer' => $root . '/includes/footer.php',
    'header' => $root . '/includes/header.php',
    'modalManager' => $root . '/assets/js/ModalManager.js',
    'appNav' => $root . '/assets/js/app-nav.js',
    'appCss' => $root . '/assets/css/app.css',
    'procurementView' => $root . '/procurement/view.php',
    'procurementSendBack' => $root . '/procurement/send_back.php',
    'reimbursementView' => $root . '/reimbursement/view.php',
    'reimbursementRevert' => $root . '/reimbursement/revert_status.php',
    'pettyCashView' => $root . '/petty_cash/view.php',
    'pettyCashRevert' => $root . '/petty_cash/revert_status.php',
    'pettyCashVerify' => $root . '/petty_cash/verify_reconciliation.php',
    'pettyCashReview' => $root . '/petty_cash/review_discrepancy.php',
];

$content = [];
foreach ($files as $key => $path) {
    $content[$key] = readModalSafetySource($path);
}

$passed = 0;
$failed = 0;

function readModalSafetySource(string $path): string
{
    global $failed;

    $content = file_get_contents($path);
    if ($content === false) {
        echo "  FAIL  failed to load required test source: {$path}\n";
        $failed++;
        return '';
    }

    return $content;
}

function modalSafetyAssert(string $name, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        echo "  PASS  {$name}\n";
        $passed++;
        return;
    }

    echo "  FAIL  {$name}\n";
    $failed++;
}

echo "\n=== WorkflowModalSafetyTest ===\n";

modalSafetyAssert(
    'footer loads shared modal manager and no longer defines duplicate decline modal',
    str_contains($content['footer'], '/assets/js/ModalManager.js')
    && !str_contains($content['footer'], 'id="declineModal"')
);

modalSafetyAssert(
    'shared modal stack safety clears loader and notification overlays globally',
    str_contains($content['header'], 'window.prmsCloseNotifDropdown = function ()')
    && str_contains($content['modalManager'], 'window.PRMSPageLoader')
    && str_contains($content['modalManager'], 'window.prmsCloseNotifDropdown()')
    && str_contains($content['modalManager'], 'prepare: prepare')
    && str_contains($content['appNav'], 'window.PRMSPageLoader = {')
    && str_contains($content['appNav'], "document.addEventListener('show.bs.modal', hideLoader)")
    && str_contains($content['appCss'], 'pointer-events: none;')
    && str_contains($content['appCss'], '.modal-backdrop {')
);

modalSafetyAssert(
    'procurement reason modals use shared managed modal form contract',
    preg_match('/class="modal fade js-managed-modal" id="declineModal"/', $content['procurementView']) === 1
    && preg_match('/action="\/procurement\/cancel\.php" class="modal-content js-workflow-action-form js-modal-form" data-no-loader/', $content['procurementView']) === 1
    && !str_contains($content['procurementView'], "document.querySelectorAll('.modal-backdrop').forEach")
);

modalSafetyAssert(
    'procurement send back handler validates csrf against the view page',
    str_contains($content['procurementSendBack'], "requireCsrfToken('/procurement/view.php?id=' . \$id);")
);

modalSafetyAssert(
    'reimbursement modal forms are managed and revert includes csrf field',
    preg_match('/id="markReimbursedModal".*?<form method="post" action="\/reimbursement\/mark_reimbursed\.php" class="js-modal-form" data-no-loader>/s', $content['reimbursementView']) === 1
    && preg_match('/id="revertStageModal".*?<input type="hidden" name="csrf_token" value="\<\?= htmlspecialchars\(\$csrfToken\) \?\>">/s', $content['reimbursementView']) === 1
);

modalSafetyAssert(
    'reimbursement revert handler enforces csrf and request_id redirect',
    str_contains($content['reimbursementRevert'], "requireCsrfToken('/reimbursement/view.php?request_id=' . \$id);")
    && str_contains($content['reimbursementRevert'], '"/reimbursement/view.php?request_id={$id}"')
);

modalSafetyAssert(
    'petty cash reason modals are managed and carry csrf tokens',
    preg_match('/action="\/petty_cash\/disburse\.php" class="js-modal-form" data-no-loader/s', $content['pettyCashView']) === 1
    && preg_match('/action="\/petty_cash\/verify_reconciliation\.php" class="js-modal-form" data-no-loader/s', $content['pettyCashView']) === 1
    && preg_match('/action="\/petty_cash\/review_discrepancy\.php" class="js-modal-form" data-no-loader/s', $content['pettyCashView']) === 1
    && substr_count($content['pettyCashView'], 'name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"') >= 5
);

modalSafetyAssert(
    'petty cash footer include remains outside the final modal markup',
    preg_match(
        '/id="finalizeReconciliationModal".*?<\/form>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\?php require_once \$_SERVER\[\'DOCUMENT_ROOT\'\] \. "\/includes\/footer\.php"; \?>/s',
        $content['pettyCashView']
    ) === 1
);

modalSafetyAssert(
    'petty cash handlers enforce csrf before workflow processing',
    str_contains($content['pettyCashRevert'], "requireCsrfToken('/petty_cash/view.php?request_id=' . \$id);")
    && str_contains($content['pettyCashVerify'], 'requireCsrfToken($csrfRedirect);')
    && str_contains($content['pettyCashVerify'], 'SELECT pr.request_id')
    && str_contains($content['pettyCashReview'], 'requireCsrfToken($csrfRedirect);')
    && str_contains($content['pettyCashReview'], 'SELECT pr.request_id')
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
