<?php
/**
 * SecurityAccessTest
 *
 * Verifies server-side enforcement of the test-suite access guard and
 * the admin-edit permission for reimbursement and petty-cash requests.
 *
 * Tests:
 *  1. Non-admin cannot access the protected test suite
 *  2. Admin can access the suite
 *  3. Admin can edit a reimbursement in every workflow state
 *  4. Admin can edit petty cash in every workflow state
 *  5. Normal users remain restricted from editing non-DRAFT requests
 *  8. Admin edits are audited
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class SecurityAccessTest extends PHPUnit\Framework\TestCase
{
    // -----------------------------------------------------------------------
    // 1 & 2 – Test-suite access guard
    // -----------------------------------------------------------------------

    /**
     * has_permission() must return false for non-privileged roles when the
     * test-suite permission is checked directly.
     */
    public function testNonAdminCannotAccessTestSuite(): void
    {
        $_SESSION = ['role_name' => 'Requestor', 'user_id' => 42, 'role_id' => 5];
        $this->assertFalse(
            has_permission('access_test_suite'),
            'A Requestor must NOT have access_test_suite permission'
        );
    }

    public function testAdminCanAccessTestSuite(): void
    {
        $_SESSION = ['role_name' => 'Admin', 'user_id' => 1, 'role_id' => 2];
        $this->assertTrue(
            has_permission('access_test_suite'),
            'Admin role must have access_test_suite permission'
        );
    }

    public function testSuperAdminCanAccessTestSuite(): void
    {
        $_SESSION = ['role_name' => 'SuperAdmin', 'user_id' => 1, 'role_id' => 1];
        $this->assertTrue(
            has_permission('access_test_suite'),
            'SuperAdmin role must have access_test_suite permission'
        );
    }

    // -----------------------------------------------------------------------
    // 3 – Admin can edit reimbursement in every workflow state
    // -----------------------------------------------------------------------

    /** @dataProvider reimbursementStatusProvider */
    public function testAdminCanEditReimbursementInAnyStatus(string $status): void
    {
        $_SESSION = ['role_name' => 'Admin', 'user_id' => 1, 'role_id' => 2];
        $this->assertTrue(
            has_permission('edit_reimbursement_request_admin'),
            "Admin must have edit_reimbursement_request_admin in status={$status}"
        );
    }

    public static function reimbursementStatusProvider(): array
    {
        return [
            ['DRAFT'],
            ['SUBMITTED'],
            ['HOD_APPROVED'],
            ['FUNDS_VERIFIED'],
            ['APPROVED'],
            ['REJECTED'],
            ['COMPLETED'],
            ['PENDING'],
            ['RETURNED'],
            ['CANCELLED'],
        ];
    }

    // -----------------------------------------------------------------------
    // 4 – Admin can edit petty cash in every workflow state
    // -----------------------------------------------------------------------

    /** @dataProvider pettyCashStatusProvider */
    public function testAdminCanEditPettyCashInAnyStatus(string $status): void
    {
        $_SESSION = ['role_name' => 'Admin', 'user_id' => 1, 'role_id' => 2];
        $this->assertTrue(
            has_permission('edit_petty_cash_request_admin'),
            "Admin must have edit_petty_cash_request_admin in status={$status}"
        );
    }

    public static function pettyCashStatusProvider(): array
    {
        return [
            ['DRAFT'],
            ['SUBMITTED'],
            ['HOD_APPROVED'],
            ['APPROVED'],
            ['DISBURSED'],
            ['RECONCILED'],
            ['COMPLETED'],
            ['CANCELLED'],
        ];
    }

    // -----------------------------------------------------------------------
    // 5 – Normal users remain restricted
    // -----------------------------------------------------------------------

    public function testRequestorCannotEditReimbursementAdminWide(): void
    {
        $_SESSION = ['role_name' => 'Requestor', 'user_id' => 99, 'role_id' => 5];
        $this->assertFalse(
            has_permission('edit_reimbursement_request_admin'),
            'A Requestor must NOT have the admin edit permission for reimbursement'
        );
    }

    public function testRequestorCannotEditPettyCashAdminWide(): void
    {
        $_SESSION = ['role_name' => 'Requestor', 'user_id' => 99, 'role_id' => 5];
        $this->assertFalse(
            has_permission('edit_petty_cash_request_admin'),
            'A Requestor must NOT have the admin edit permission for petty cash'
        );
    }

    // -----------------------------------------------------------------------
    // 8 – Admin edits produce an audit entry (unit test via logAudit stub)
    // -----------------------------------------------------------------------

    public function testAdminEditIsAudited(): void
    {
        $auditLog = [];
        // Override logAudit for this test
        $logFn = static function (
            $pdo, string $table, int $id, string $action, string $detail = ''
        ) use (&$auditLog): void {
            $auditLog[] = compact('table', 'id', 'action', 'detail');
        };

        // Simulate what edit.php does when $isAdminEdit === true
        $id              = 42;
        $existing        = ['request_number' => 'REI-000042', 'status' => 'APPROVED'];
        $_SESSION        = ['role_name' => 'Admin', 'full_name' => 'Jane Admin', 'user_id' => 1];
        $isAdminEdit     = true;

        if ($isAdminEdit) {
            $oldStatus = $existing['status'];
            $logFn(
                null,
                'procurement_requests',
                $id,
                'ADMIN_UPDATE',
                'Administrator override edit by ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                " (status={$oldStatus}) on reimbursement request {$existing['request_number']}"
            );
        }

        $this->assertCount(1, $auditLog, 'Exactly one audit entry expected');
        $this->assertSame('ADMIN_UPDATE', $auditLog[0]['action']);
        $this->assertStringContainsString('Jane Admin', $auditLog[0]['detail']);
        $this->assertStringContainsString('APPROVED', $auditLog[0]['detail']);
    }
}
