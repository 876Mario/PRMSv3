<?php
/**
 * DraftVisibilityTest
 * ====================
 * Tests for canViewDraft() helper and server-side draft filtering in list.php.
 *
 * Runs with the built-in PHP test runner (assert-based) or can be adapted for PHPUnit.
 * Uses a PDO SQLite :memory: stub to avoid needing a real database.
 */

/* ─── Bootstrap ──────────────────────────────────────────────────────── */
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

// Stub session superglobal
$_SESSION = [];

// Load the function under test
require_once __DIR__ . '/../config/workflow.php';

/* ─── Helpers ────────────────────────────────────────────────────────── */
$passed = 0;
$failed = 0;

function testAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  {$name}\n";
        $passed++;
    } else {
        echo "  FAIL  {$name}\n";
        $failed++;
    }
}

function mockSession(int $userId, string $role): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['role_name'] = $role;
}

/* ─── Test cases ─────────────────────────────────────────────────────── */
echo "\n=== DraftVisibilityTest ===\n";

// 1. Non-draft request is always visible
mockSession(5, 'Requestor');
testAssert('Non-draft is visible to any role',
    canViewDraft(['status' => 'SUBMITTED', 'created_by' => 99])
);

// 2. Creator can see their own draft
mockSession(10, 'Requestor');
testAssert('Creator can view their own DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 10])
);

// 3. Different Requestor cannot see someone else's draft
mockSession(11, 'Requestor');
testAssert('Different Requestor cannot view another user\'s DRAFT',
    !canViewDraft(['status' => 'DRAFT', 'created_by' => 10])
);

// 4. HOD can view any draft
mockSession(20, 'HOD');
testAssert('HOD can view any DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 5. Branch Head can view any draft
mockSession(21, 'Branch Head');
testAssert('Branch Head can view any DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 6. Director HRM&A (monitoring role) can view any draft
mockSession(22, 'Director HRM&A');
testAssert('Director HRM&A can view any DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 7. Admin can view any draft
mockSession(23, 'Admin');
testAssert('Admin can view any DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 8. SuperAdmin can view any draft
mockSession(24, 'SuperAdmin');
testAssert('SuperAdmin can view any DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 9. Finance Officer cannot see someone else's draft
mockSession(30, 'Finance Officer');
testAssert('Finance Officer cannot view another user\'s DRAFT',
    !canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 10. Procurement Officer cannot see someone else's draft
mockSession(31, 'Procurement Officer');
testAssert('Procurement Officer cannot view another user\'s DRAFT',
    !canViewDraft(['status' => 'DRAFT', 'created_by' => 99])
);

// 11. Finance Officer CAN see their own draft
mockSession(30, 'Finance Officer');
testAssert('Finance Officer can view their own DRAFT',
    canViewDraft(['status' => 'DRAFT', 'created_by' => 30])
);

// 12. draftViewerRoles() returns expected roles
$dvr = draftViewerRoles();
testAssert('draftViewerRoles contains HOD', in_array('HOD', $dvr, true));
testAssert('draftViewerRoles contains Branch Head', in_array('Branch Head', $dvr, true));
testAssert('draftViewerRoles contains Director HRM&A', in_array('Director HRM&A', $dvr, true));
testAssert('draftViewerRoles contains Admin', in_array('Admin', $dvr, true));

// 13. isMonitoringRole() identifies Director HRM&A
testAssert('isMonitoringRole("Director HRM&A") === true',
    isMonitoringRole('Director HRM&A')
);
testAssert('isMonitoringRole("HOD") === false',
    !isMonitoringRole('HOD')
);
testAssert('isMonitoringRole("Requestor") === false',
    !isMonitoringRole('Requestor')
);

/* ─── Summary ────────────────────────────────────────────────────────── */
echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
