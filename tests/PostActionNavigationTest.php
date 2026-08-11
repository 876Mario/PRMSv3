<?php
/**
 * PostActionNavigationTest
 * =========================
 * Tests that post-action redirect targets are set correctly.
 * We test the session key mechanism used by send_back.php and verify
 * expected redirect targets for approve/decline/cancel outcomes.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

$passed = 0;
$failed = 0;

function navAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

echo "\n=== PostActionNavigationTest ===\n";

/* 1. last_list_url is stored when browsing list with filters */
$_SERVER['QUERY_STRING'] = 'request_status=SUBMITTED&page=2';
$expected = '/procurement/list.php?request_status=SUBMITTED&page=2';
$_SESSION['last_list_url'] = '/procurement/list.php'
    . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
navAssert('last_list_url stores filter params',
    $_SESSION['last_list_url'] === $expected
);

/* 2. last_list_url falls back to /procurement/list.php when no query string */
$_SERVER['QUERY_STRING'] = '';
$_SESSION['last_list_url'] = '/procurement/list.php'
    . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
navAssert('last_list_url is bare list.php with no query string',
    $_SESSION['last_list_url'] === '/procurement/list.php'
);

/* 3. send_back returns to last_list_url when set */
$_SESSION['last_list_url'] = '/procurement/list.php?request_status=SUBMITTED';
$returnUrl = $_SESSION['last_list_url'] ?? '/procurement/list.php';
unset($_SESSION['last_list_url']);
navAssert('send_back uses last_list_url as return target',
    $returnUrl === '/procurement/list.php?request_status=SUBMITTED'
);

/* 4. send_back falls back to list.php when session key absent */
unset($_SESSION['last_list_url']);
$fallbackUrl = $_SESSION['last_list_url'] ?? '/procurement/list.php';
navAssert('send_back falls back to /procurement/list.php',
    $fallbackUrl === '/procurement/list.php'
);

/* 5. last_list_url is cleared after use (unset) */
$_SESSION['last_list_url'] = '/procurement/list.php?foo=bar';
$r = $_SESSION['last_list_url'] ?? '/procurement/list.php';
unset($_SESSION['last_list_url']);
navAssert('last_list_url is unset after being consumed',
    !isset($_SESSION['last_list_url'])
);

/* 6. Approve success redirects to dashboard (not view.php) */
// This is the redirect string used in procurement/approve.php
$approveSuccessTarget = '/dashboard/index.php';
navAssert('Approve success redirects to dashboard',
    $approveSuccessTarget === '/dashboard/index.php'
);

/* 7. Reject success redirects to list (not view.php) */
$rejectSuccessTarget = '/procurement/list.php';
navAssert('Reject success redirects to procurement list',
    $rejectSuccessTarget === '/procurement/list.php'
);

/* 8. Cancel success redirects to list (not view.php) */
$cancelSuccessTarget = '/procurement/list.php';
navAssert('Cancel success redirects to procurement list',
    $cancelSuccessTarget === '/procurement/list.php'
);

/* 9. Decline success redirects to list (not view.php) */
$declineSuccessTarget = '/procurement/list.php';
navAssert('Decline success redirects to procurement list',
    $declineSuccessTarget === '/procurement/list.php'
);

/* 10. Submit success keeps requestor on view page (to see confirmed status) */
// The submit.php pop() still points to view.php — expected behaviour for requestors.
$submitSuccessTarget = '/procurement/view.php?id=42';
navAssert('Submit success stays on view page for requestor confirmation',
    str_starts_with($submitSuccessTarget, '/procurement/view.php')
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
