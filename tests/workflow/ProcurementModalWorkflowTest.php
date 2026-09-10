<?php
/**
 * ProcurementModalWorkflowTest
 * ============================
 * Guards the procurement view modal workflow contract for cancel/send-back
 * actions so the shared modal manager can safely submit without leaving the
 * backdrop behind.
 */

$root = dirname(__DIR__, 2);
$viewPath = $root . '/procurement/view.php';
$cancelPath = $root . '/procurement/cancel.php';
$pauseResumePath = $root . '/procurement/pause_resume.php';
$revertStatusPath = $root . '/procurement/revert_status.php';

$passed = 0;
$failed = 0;

function readWorkflowSource(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        echo "  FAIL  failed to load required test source: {$path}\n";
        exit(1);
    }

    return $content;
}

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

$view = readWorkflowSource($viewPath);
$cancel = readWorkflowSource($cancelPath);
$pauseResume = readWorkflowSource($pauseResumePath);
$revertStatus = readWorkflowSource($revertStatusPath);

workflowAssert(
    'cancel modal posts with csrf token and modal cleanup hooks',
    preg_match(
        '/<form\b(?=[^>]*\bmethod="POST")(?=[^>]*\baction="\/procurement\/cancel\.php")(?=[^>]*\bclass="[^"]*\bjs-workflow-action-form\b[^"]*\bjs-modal-form\b[^"]*")(?=[^>]*\bdata-no-loader(?:=(?:""|\'\'))?)[^>]*>.*?\bname="csrf_token".*?<\/form>/s',
        $view
    ) === 1
);

workflowAssert(
    'send back modal posts with csrf token and modal cleanup hooks',
    preg_match(
        '/<form\b(?=[^>]*\bmethod="POST")(?=[^>]*\baction="\/procurement\/send_back\.php")(?=[^>]*\bclass="[^"]*\bjs-workflow-action-form\b[^"]*\bjs-modal-form\b[^"]*")(?=[^>]*\bdata-no-loader(?:=(?:""|\'\'))?)[^>]*>.*?\bname="csrf_token".*?<\/form>/s',
        $view
    ) === 1
);

workflowAssert(
    'view delegates cleanup to shared modal manager contract',
    str_contains($view, 'class="modal fade js-managed-modal" id="declineModal"')
    && str_contains($view, 'class="modal fade js-managed-modal" id="cancelRequestModal"')
    && !str_contains($view, "document.querySelectorAll('.modal-backdrop').forEach")
);

workflowAssert(
    'cancel handler enforces csrf validation',
    preg_match(
        '/requireCsrfToken\(\s*\'\/procurement\/view\.php\?id=\'\s*\.\s*(?:\(int\)\s*)?\$id\s*\)\s*;/',
        $cancel
    ) === 1
);

workflowAssert(
    'pause/resume handler enforces csrf validation',
    preg_match(
        '/requireCsrfToken\(\s*\'\/procurement\/view\.php\?id=\'\s*\.\s*(?:\(int\)\s*)?\$id\s*\)\s*;/',
        $pauseResume
    ) === 1
);

workflowAssert(
    'revert handler enforces csrf validation',
    preg_match(
        '/requireCsrfToken\(\s*\'\/procurement\/view\.php\?id=\'\s*\.\s*(?:\(int\)\s*)?\$id\s*\)\s*;/',
        $revertStatus
    ) === 1
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
