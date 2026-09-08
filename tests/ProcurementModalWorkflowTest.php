<?php
/**
 * ProcurementModalWorkflowTest
 * ============================
 * Guards the procurement view modal workflow contract for cancel/send-back
 * actions so the UI can safely submit without leaving the backdrop behind.
 */

$root = dirname(__DIR__);
$viewPath = $root . '/procurement/view.php';
$cancelPath = $root . '/procurement/cancel.php';

$view = file_get_contents($viewPath);
$cancel = file_get_contents($cancelPath);

$passed = 0;
$failed = 0;

function workflowAssert(string $name, bool $condition): void
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

echo "\n=== ProcurementModalWorkflowTest ===\n";

workflowAssert(
    'cancel modal posts with csrf token and modal cleanup hooks',
    preg_match(
        '/<form method="POST" action="\/procurement\/cancel\.php" class="modal-content js-workflow-action-form" data-no-loader>.*?name="csrf_token"/s',
        $view
    ) === 1
);

workflowAssert(
    'send back modal posts with csrf token and modal cleanup hooks',
    preg_match(
        '/<form method="POST" action="\/procurement\/send_back\.php" class="modal-content js-workflow-action-form" data-no-loader>.*?name="csrf_token"/s',
        $view
    ) === 1
);

workflowAssert(
    'view registers workflow modal submit cleanup',
    str_contains($view, "document.querySelectorAll('.js-workflow-action-form')")
    && str_contains($view, "document.querySelectorAll('.modal-backdrop').forEach")
    && str_contains($view, "document.body.classList.remove('modal-open')")
);

workflowAssert(
    'cancel handler enforces csrf validation',
    str_contains($cancel, "requireCsrfToken('/procurement/view.php?id=' . (int)\$id);")
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
