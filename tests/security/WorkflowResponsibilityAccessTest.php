<?php
/**
 * WorkflowResponsibilityAccessTest
 *
 * Verifies that the WorkflowResponsibilityService respects
 * authorization boundaries:
 *
 *  1. Responsibility data is returned for authorized viewers.
 *  2. E-mail addresses are never returned in any field.
 *  3. Completer name is visible to any viewer already authorized for the request.
 *  4. Unconfigured stages return a safe fallback regardless of role.
 *  5. Admin and SuperAdmin receive full responsibility details.
 *  6. Requestor receives only permitted details (role, no internal
 *     assigned-user lookup from another department's branch).
 *  7. The service never exposes PDO-level parameters via client input
 *     (parameter binding verified by the test data NOT leaking).
 *  8. Reimbursement pipeline: every stage returns a responsible role.
 *  9. Petty cash pipeline: every stage returns a responsible role.
 * 10. Pending stages do not leak the assigned user from a different branch.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowResponsibilityService.php';
require_once dirname(__DIR__, 2) . '/includes/workflow_pipeline.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';

class WorkflowResponsibilityAccessTest extends PHPUnit\Framework\TestCase
{
    // -----------------------------------------------------------------------
    // Helper: in-memory SQLite
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE users (
                user_id   INTEGER PRIMARY KEY,
                full_name TEXT    NOT NULL,
                email     TEXT    NOT NULL DEFAULT 'secret@internal.gov',
                role_id   INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                branch_id INTEGER DEFAULT NULL
            )
        ");
        $pdo->exec("
            CREATE TABLE roles (
                id   INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ");
        $pdo->exec("
            CREATE TABLE request_approvals (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id  INTEGER NOT NULL,
                role        TEXT,
                stage_order INTEGER NOT NULL DEFAULT 1,
                status      TEXT    NOT NULL DEFAULT 'pending',
                approved_by INTEGER DEFAULT NULL
            )
        ");

        // Finance Officer in branch 1
        $pdo->exec("INSERT INTO roles VALUES (3, 'Finance Officer')");
        $pdo->exec("INSERT INTO users VALUES (10, 'Alice Finance', 'alice@gov.goc.jm', 3, 1, 1)");

        // Finance Officer in branch 2 (a different dept)
        $pdo->exec("INSERT INTO users VALUES (11, 'Bob Finance', 'bob@gov.goc.jm', 3, 1, 2)");

        return $pdo;
    }

    private function makePcRequest(int $branchId = 1): array
    {
        return [
            'request_id'   => 50,
            'request_type' => 'PETTY_CASH',
            'branch_id'    => $branchId,
            'status'       => 'SUBMITTED',
        ];
    }

    private function makeReimbRequest(int $branchId = 1): array
    {
        return [
            'request_id'   => 99,
            'request_type' => 'REIMBURSEMENT',
            'branch_id'    => $branchId,
            'status'       => 'SUBMITTED',
        ];
    }

    // -----------------------------------------------------------------------
    // 1. Responsibility data returned for authorized viewers
    // -----------------------------------------------------------------------

    public function testResponsibilityReturnedForAuthorizedViewer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Finance Officer'
        );
        $this->assertIsArray($resp);
        $this->assertArrayHasKey('responsible_role', $resp);
        $this->assertNotEmpty($resp['responsible_role']);
    }

    // -----------------------------------------------------------------------
    // 2. E-mail addresses are never returned
    // -----------------------------------------------------------------------

    public function testEmailAddressNeverReturnedInResponsibility(): void
    {
        $pdo = $this->makePdo();
        // Approved stage with known user
        $pdo->exec("
            INSERT INTO request_approvals (request_id, role, stage_order, status, approved_by)
            VALUES (99, 'Finance Officer', 1, 'approved', 10)
        ");

        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'FUNDS_VERIFIED',
            [['role' => 'Finance Officer', 'stage_order' => 1, 'status' => 'approved', 'approved_by' => 10]],
            'Admin',
            true
        );

        $allValues = array_filter(array_values($resp), fn($v) => is_string($v));
        foreach ($allValues as $val) {
            $this->assertStringNotContainsString('@', $val,
                'Responsibility data must never contain an e-mail address');
        }
    }

    // -----------------------------------------------------------------------
    // 3. Completer visible to any authorized viewer
    // -----------------------------------------------------------------------

    public function testCompleterVisibleToRequestorOnOwnRequest(): void
    {
        $pdo = $this->makePdo();
        $pdo->exec("
            INSERT INTO request_approvals (request_id, role, stage_order, status, approved_by)
            VALUES (99, 'Finance Officer', 1, 'approved', 10)
        ");

        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'FUNDS_VERIFIED',
            [['role' => 'Finance Officer', 'stage_order' => 1, 'status' => 'approved', 'approved_by' => 10]],
            'Requestor',
            true
        );

        $this->assertNotNull($resp['completer_name'],
            'Requestor already on the view page may see who completed a stage');
        $this->assertSame('Alice Finance', $resp['completer_name']);
    }

    // -----------------------------------------------------------------------
    // 4. Unconfigured stage returns safe fallback for any role
    // -----------------------------------------------------------------------

    /** @dataProvider allRolesProvider */
    public function testUnconfiguredStageSafeFallbackForAnyRole(string $role): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'TOTALLY_MADE_UP_STAGE',
            [],
            $role
        );
        $this->assertFalse($resp['is_configured']);
        $this->assertSame('Awaiting system assignment', $resp['source_type']);
        $this->assertNull($resp['completer_name']);
    }

    public static function allRolesProvider(): array
    {
        return [
            ['Requestor'],
            ['HOD'],
            ['Finance Officer'],
            ['Procurement Officer'],
            ['Director HRM&A'],
            ['Deputy Government Chemist'],
            ['Admin'],
            ['SuperAdmin'],
        ];
    }

    // -----------------------------------------------------------------------
    // 5. Admin receives full responsibility details
    // -----------------------------------------------------------------------

    public function testAdminReceivesFullDetails(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertNotEmpty($resp['responsible_role']);
        $this->assertNotEmpty($resp['action_description']);
    }

    // -----------------------------------------------------------------------
    // 6. Requestor receives role info (basic details always visible)
    // -----------------------------------------------------------------------

    public function testRequestorReceivesRoleInfo(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Requestor'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertNotEmpty($resp['responsible_role']);
    }

    // -----------------------------------------------------------------------
    // 7. SQL injection attempt via stageStatus does not leak data
    // -----------------------------------------------------------------------

    public function testSqlInjectionAttemptInStageStatusIsSafe(): void
    {
        $svc = new WorkflowResponsibilityService($this->makePdo());

        // An attacker-controlled stageStatus that contains SQL
        $maliciousStage = "'; DROP TABLE users; --";

        // Should simply return is_configured=false, not throw or leak
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            $maliciousStage,
            [],
            'Admin'
        );
        $this->assertFalse($resp['is_configured'],
            'SQL injection in stageStatus must return is_configured=false');
        $this->assertNull($resp['completer_name']);
    }

    // -----------------------------------------------------------------------
    // 8. Reimbursement pipeline: every stage has a responsible role
    // -----------------------------------------------------------------------

    public function testAllReimbursementStagesHaveResponsibleRole(): void
    {
        $svc = new WorkflowResponsibilityService($this->makePdo());

        foreach (getReimbursementPipeline() as $stage) {
            $resp = $svc->getStageResponsibility(
                $this->makeReimbRequest(),
                $stage['status'],
                [],
                'Finance Officer'
            );
            $this->assertTrue(
                $resp['is_configured'],
                "Reimbursement stage {$stage['status']} must have is_configured=true"
            );
            $this->assertNotEmpty(
                $resp['responsible_role'],
                "Reimbursement stage {$stage['status']} must have a responsible_role"
            );
        }
    }

    // -----------------------------------------------------------------------
    // 9. Petty cash pipeline: every stage has a responsible role
    // -----------------------------------------------------------------------

    public function testAllPettyCashStagesHaveResponsibleRole(): void
    {
        $svc = new WorkflowResponsibilityService($this->makePdo());

        foreach (getPettyCashPipeline() as $stage) {
            $resp = $svc->getStageResponsibility(
                $this->makePcRequest(),
                $stage['status'],
                [],
                'Finance Officer'
            );
            $this->assertTrue(
                $resp['is_configured'],
                "Petty cash stage {$stage['status']} must have is_configured=true"
            );
            $this->assertNotEmpty(
                $resp['responsible_role'],
                "Petty cash stage {$stage['status']} must have a responsible_role"
            );
        }
    }

    // -----------------------------------------------------------------------
    // 10. Pending stage does not leak assigned user from a different branch
    // -----------------------------------------------------------------------

    public function testPendingStageDoesNotLeakUserFromDifferentBranch(): void
    {
        // Request is in branch 1; Finance Officer in branch 2 (Bob) should NOT appear
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            // branchId = 1; only Alice (branch 1) should match
            $this->makeReimbRequest(1),
            'SUBMITTED',
            [],
            'Requestor'
        );
        // With two Finance Officers across branches, the lookup returns exactly one
        // match for branch 1 (Alice), so assigned_user should be 'Alice Finance'.
        // Bob from branch 2 must NOT appear.
        if ($resp['assigned_user'] !== null) {
            $this->assertSame('Alice Finance', $resp['assigned_user'],
                'Only the branch-scoped Finance Officer should be returned');
            $this->assertStringNotContainsString('Bob', $resp['assigned_user']);
        }
    }
}
