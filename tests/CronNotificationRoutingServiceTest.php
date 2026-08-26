<?php
/**
 * CronNotificationRoutingServiceTest
 * ==================================
 * Isolated tests for scheduled-notification recipient routing.
 */

require_once __DIR__ . '/../services/CronNotificationRoutingService.php';

$passed = 0;
$failed = 0;

function cnrAssert(string $name, bool $condition): void
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("
    CREATE TABLE roles (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        branch_id INTEGER,
        is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE request_approvals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        entity_type TEXT DEFAULT 'REQUEST',
        stage_order INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE rfqs (
        rfq_id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        requestor_spec_review_status TEXT DEFAULT 'PENDING',
        branch_head_approval_status TEXT DEFAULT 'PENDING'
    );
");

$roles = [
    1 => 'Requestor',
    2 => 'Director HRM&A',
    3 => 'Branch Head',
    4 => 'Procurement Officer',
    5 => 'Finance Officer',
    6 => 'Admin',
    7 => 'Property Management Officer',
    8 => 'Regular User',
];
foreach ($roles as $id => $name) {
    $pdo->prepare("INSERT INTO roles (id, name) VALUES (?, ?)")->execute([$id, $name]);
}

$users = [
    [1, 'Requestor', 'requestor@test.local', 1, 10, 1],
    [2, 'Director', 'director@test.local', 2, null, 1],
    [3, 'Branch Head', 'branch@test.local', 3, 10, 1],
    [4, 'Procurement Officer', 'procurement@test.local', 4, null, 1],
    [5, 'Finance Officer', 'finance@test.local', 5, 10, 1],
    [6, 'Admin', 'admin@test.local', 6, null, 1],
    [7, 'PMO One', 'pmo1@test.local', 7, null, 1],
    [8, 'PMO Two', 'pmo2@test.local', 7, null, 1],
    [9, 'Regular User', 'regular@test.local', 8, null, 1],
    [10, 'Other Branch Head', 'other-branch@test.local', 3, 11, 1],
];
foreach ($users as $user) {
    $pdo->prepare("
        INSERT INTO users (user_id, full_name, email, role_id, branch_id, is_active)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute($user);
}

echo "\n=== CronNotificationRoutingServiceTest ===\n";

$inventoryRecipients = CronNotificationRoutingService::getInventoryAlertRecipients($pdo, null, 'REORDER');
cnrAssert('Inventory alerts include PMO One', isset($inventoryRecipients[7]));
cnrAssert('Inventory alerts include PMO Two', isset($inventoryRecipients[8]));
cnrAssert('Inventory alerts exclude regular users', !isset($inventoryRecipients[9]));
cnrAssert('Inventory alerts exclude admins by default', !isset($inventoryRecipients[6]));

$pdo->prepare("INSERT INTO rfqs (request_id, requestor_spec_review_status, branch_head_approval_status) VALUES (101, 'PENDING', 'PENDING')")->execute();
$requestorReview = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 101,
    'status' => 'QUOTE_REQUESTOR_REVIEW_PENDING',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Requestor review overdue routes only to requestor', array_keys($requestorReview) === [1]);

$pdo->prepare("UPDATE rfqs SET requestor_spec_review_status = 'APPROVED' WHERE request_id = 101")->execute();
$completedRequestorReview = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 101,
    'status' => 'QUOTE_REQUESTOR_REVIEW_PENDING',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Completed requestor review generates no reminder', $completedRequestorReview === []);

$branchHeadReview = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 101,
    'status' => 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Branch Head approval routes to branch head only', array_keys($branchHeadReview) === [3]);
cnrAssert('Branch Head approval excludes other branch head', !isset($branchHeadReview[10]));

$pdo->prepare("INSERT INTO request_approvals (request_id, role, status, stage_order) VALUES (202, 'Director HRM&A', 'pending', 1)")->execute();
$directorApproval = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 202,
    'status' => 'SUBMITTED',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Director approval overdue routes to assigned director role', array_keys($directorApproval) === [2]);
cnrAssert('Director approval overdue excludes admin', !isset($directorApproval[6]));

$pdo->prepare("UPDATE request_approvals SET status = 'approved' WHERE request_id = 202")->execute();
$completedDirectorApproval = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 202,
    'status' => 'COMPLETED',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Completed request generates no overdue recipients', $completedDirectorApproval === []);

$pdo->prepare("INSERT INTO request_approvals (request_id, role, status, stage_order) VALUES (303, 'Finance Officer', 'pending', 1)")->execute();
$financeApproval = CronNotificationRoutingService::getOverdueActionRecipients($pdo, [
    'request_id' => 303,
    'status' => 'HOD_APPROVED',
    'branch_id' => 10,
    'created_by' => 1,
]);
cnrAssert('Finance verification routes to branch finance officer', array_keys($financeApproval) === [5]);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
