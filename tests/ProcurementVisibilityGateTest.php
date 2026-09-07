<?php
/**
 * ProcurementVisibilityGateTest
 * =============================
 * Validates role-based request visibility around the Director approval gate.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../config/workflow.php';
require_once __DIR__ . '/../config/helper.php';

$passed = 0;
$failed = 0;

function pvAssert(string $name, bool $condition): void
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

function pvSession(int $userId, string $role): void
{
    $_SESSION['user_id'] = $userId;
    $_SESSION['role_name'] = $role;
}

echo "\n=== ProcurementVisibilityGateTest ===\n";

$preDirector = ['request_id' => 1001, 'created_by' => 20, 'status' => 'SUBMITTED'];
$postDirector = ['request_id' => 1002, 'created_by' => 20, 'status' => 'DIRECTOR_APPROVED'];

pvSession(20, 'Requestor');
pvAssert('Request creator can access own pre-director request', canCurrentUserAccessRequestRecord($preDirector));

pvSession(31, 'HOD');
pvAssert('Supervisor (HOD) can access pre-director request', canCurrentUserAccessRequestRecord($preDirector));

pvSession(32, 'Branch Head');
pvAssert('Manager (Branch Head) can access pre-director request', canCurrentUserAccessRequestRecord($preDirector));

pvSession(33, 'Director HRM&A');
pvAssert('Director can access pre-director request', canCurrentUserAccessRequestRecord($preDirector));

pvSession(34, 'Procurement Officer');
pvAssert('Procurement Officer cannot access pre-director request', !canCurrentUserAccessRequestRecord($preDirector));
pvAssert('Procurement Officer can access director-approved request', canCurrentUserAccessRequestRecord($postDirector));

pvSession(35, 'Procurement Manager');
pvAssert('Procurement Manager cannot access pre-director request', !canCurrentUserAccessRequestRecord($preDirector));
pvAssert('Procurement Manager can access director-approved request', canCurrentUserAccessRequestRecord($postDirector));

pvSession(37, 'Procurement');
pvAssert('Procurement cannot access pre-director request', !canCurrentUserAccessRequestRecord($preDirector));
pvAssert('Procurement can access director-approved request', canCurrentUserAccessRequestRecord($postDirector));

pvSession(36, 'Admin');
pvAssert('System Administrator can access pre-director request', canCurrentUserAccessRequestRecord($preDirector));

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
