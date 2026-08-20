<?php
/**
 * WorkflowResponsibilityServiceTest
 *
 * Unit tests for WorkflowResponsibilityService and the reusable
 * workflow pipeline rendering helpers.
 *
 * Tests cover:
 *  1.  Static responsibility map covers all four workflow types.
 *  2.  Every pipeline stage for REIMBURSEMENT has a configured entry.
 *  3.  Every pipeline stage for PETTY_CASH has a configured entry.
 *  4.  Every pipeline stage for SERVICE_CONTRACT has a configured entry.
 *  5.  getStageResponsibility() returns correct role for a REGULAR stage.
 *  6.  getStageResponsibility() returns correct role for REIMBURSEMENT.
 *  7.  getStageResponsibility() returns correct role for PETTY_CASH.
 *  8.  Missing stage falls back gracefully (is_configured = false).
 *  9.  Completed stage populates completer_name from request_approvals.
 * 10.  Pending stage does NOT populate completer_name.
 * 11.  buildResponsibilityTooltip() renders fallback for unconfigured stage.
 * 12.  buildResponsibilityTooltip() renders role for configured stage.
 * 13.  buildResponsibilityTooltip() renders completer_name on completed stage.
 * 14.  renderWorkflowPipelineStage() contains aria-describedby.
 * 15.  renderWorkflowPipelineStage() contains tooltip id.
 * 16.  renderWorkflowPipelineStage() marks completed stage with check icon.
 * 17.  renderWorkflowPipelineStage() marks current stage with arrow icon.
 * 18.  renderWorkflowPipelineStage() marks pending stage with stage number.
 * 19.  No PHP warnings for missing assignments.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowResponsibilityService.php';
require_once dirname(__DIR__, 2) . '/includes/workflow_pipeline.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';

class WorkflowResponsibilityServiceTest extends PHPUnit\Framework\TestCase
{
    // -----------------------------------------------------------------------
    // Helper – build in-memory SQLite DB with the tables needed by the service
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE users (
                user_id   INTEGER PRIMARY KEY,
                full_name TEXT    NOT NULL,
                email     TEXT    NOT NULL DEFAULT '',
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
                approved_by INTEGER DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL
            )
        ");

        // Seed a Finance Officer user in branch 1
        $pdo->exec("INSERT INTO roles VALUES (3, 'Finance Officer')");
        $pdo->exec("INSERT INTO users VALUES (10, 'Jane Smith', 'jane@example.com', 3, 1, 1)");

        return $pdo;
    }

    /** Minimal request row for REIMBURSEMENT */
    private function makeReimbRequest(): array
    {
        return [
            'request_id'   => 99,
            'request_type' => 'REIMBURSEMENT',
            'branch_id'    => 1,
            'status'       => 'SUBMITTED',
        ];
    }

    /** Minimal request row for PETTY_CASH */
    private function makePcRequest(): array
    {
        return [
            'request_id'   => 50,
            'request_type' => 'PETTY_CASH',
            'branch_id'    => 1,
            'status'       => 'SUBMITTED',
        ];
    }

    /** Minimal request row for REGULAR */
    private function makeRegularRequest(): array
    {
        return [
            'request_id'   => 10,
            'request_type' => 'REGULAR',
            'branch_id'    => 1,
            'status'       => 'SUBMITTED',
        ];
    }

    // -----------------------------------------------------------------------
    // 1. Static map completeness
    // -----------------------------------------------------------------------

    public function testStaticMapCoversAllWorkflowTypes(): void
    {
        $map = WorkflowResponsibilityService::getStaticResponsibilityMap();

        foreach (['REGULAR', 'REIMBURSEMENT', 'PETTY_CASH', 'SERVICE_CONTRACT'] as $type) {
            $this->assertArrayHasKey(
                $type, $map,
                "Static map must contain entries for workflow type {$type}"
            );
        }
    }

    /** @dataProvider reimbursementStageProvider */
    public function testReimbursementStageIsConfigured(string $status): void
    {
        $map = WorkflowResponsibilityService::getStaticResponsibilityMap();
        $this->assertArrayHasKey(
            $status, $map['REIMBURSEMENT'],
            "REIMBURSEMENT stage {$status} must have a configured responsibility entry"
        );
        $this->assertNotEmpty(
            $map['REIMBURSEMENT'][$status]['role'],
            "REIMBURSEMENT stage {$status} must have a non-empty responsible_role"
        );
    }

    public static function reimbursementStageProvider(): array
    {
        return array_map(
            fn(array $s) => [$s['status']],
            getReimbursementPipeline()
        );
    }

    /** @dataProvider pettyCashStageProvider */
    public function testPettyCashStageIsConfigured(string $status): void
    {
        $map = WorkflowResponsibilityService::getStaticResponsibilityMap();
        $this->assertArrayHasKey(
            $status, $map['PETTY_CASH'],
            "PETTY_CASH stage {$status} must have a configured responsibility entry"
        );
        $this->assertNotEmpty(
            $map['PETTY_CASH'][$status]['role'],
            "PETTY_CASH stage {$status} must have a non-empty responsible_role"
        );
    }

    public static function pettyCashStageProvider(): array
    {
        return array_map(
            fn(array $s) => [$s['status']],
            getPettyCashPipeline()
        );
    }

    /** @dataProvider serviceContractStageProvider */
    public function testServiceContractStageIsConfigured(string $status): void
    {
        $map = WorkflowResponsibilityService::getStaticResponsibilityMap();
        $this->assertArrayHasKey(
            $status, $map['SERVICE_CONTRACT'],
            "SERVICE_CONTRACT stage {$status} must have a configured responsibility entry"
        );
    }

    public static function serviceContractStageProvider(): array
    {
        return array_map(
            fn(array $s) => [$s['status']],
            getServiceContractPipeline()
        );
    }

    // -----------------------------------------------------------------------
    // 5-7. getStageResponsibility() correct role resolution
    // -----------------------------------------------------------------------

    public function testRegularStageReturnsCorrectRole(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'FUNDS_VERIFIED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured'], 'FUNDS_VERIFIED must be configured for REGULAR');
        $this->assertNotEmpty($resp['responsible_role']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role'],
            'Finance Officer should own the FUNDS_VERIFIED gate');
    }

    public function testReimbursementSubmittedReturnsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Requestor'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role']);
    }

    public function testPettyCashFundsVerifiedReturnsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makePcRequest(),
            'FUNDS_VERIFIED',
            [],
            'Requestor'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role']);
    }

    // -----------------------------------------------------------------------
    // 8. Missing stage graceful fallback
    // -----------------------------------------------------------------------

    public function testMissingStageReturnsNotConfigured(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'NONEXISTENT_STAGE_XYZ',
            [],
            'Admin'
        );
        $this->assertFalse(
            $resp['is_configured'],
            'Nonexistent stage must return is_configured = false'
        );
        $this->assertSame('Awaiting system assignment', $resp['source_type']);
    }

    // -----------------------------------------------------------------------
    // 9. Completed stage shows completer name
    // -----------------------------------------------------------------------

    public function testCompletedStageReturnsCompleterName(): void
    {
        $pdo = $this->makePdo();

        // Insert an approved HOD_APPROVED row (role = HOD, approved_by = user 10)
        $pdo->exec("
            INSERT INTO request_approvals (request_id, role, stage_order, status, approved_by)
            VALUES (99, 'Finance Officer', 1, 'approved', 10)
        ");

        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility(
            ['request_id' => 99, 'request_type' => 'REIMBURSEMENT', 'branch_id' => 1, 'status' => 'COMPLETED'],
            'FUNDS_VERIFIED',
            [['role' => 'Finance Officer', 'stage_order' => 1, 'status' => 'approved', 'approved_by' => 10]],
            'Admin',
            true // isCompleted
        );

        $this->assertNotNull($resp['completer_name'], 'Completed stage must return completer_name');
        $this->assertSame('Jane Smith', $resp['completer_name']);
    }

    // -----------------------------------------------------------------------
    // 10. Pending stage does NOT populate completer_name
    // -----------------------------------------------------------------------

    public function testPendingStageDoesNotReturnCompleterName(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Admin',
            false // not completed
        );
        $this->assertNull(
            $resp['completer_name'],
            'Pending stage must not return a completer_name'
        );
    }

    // -----------------------------------------------------------------------
    // 11. buildResponsibilityTooltip() fallback for unconfigured stage
    // -----------------------------------------------------------------------

    public function testTooltipFallbackForUnconfiguredStage(): void
    {
        $html = buildResponsibilityTooltip(
            'FAKE_STAGE',
            ['label' => 'Fake Stage'],
            'Pending',
            ['is_configured' => false]
        );
        $this->assertStringContainsString('not configured', $html);
    }

    // -----------------------------------------------------------------------
    // 12. buildResponsibilityTooltip() renders role for configured stage
    // -----------------------------------------------------------------------

    public function testTooltipRendersRoleForConfiguredStage(): void
    {
        $html = buildResponsibilityTooltip(
            'SUBMITTED',
            ['label' => 'Submitted'],
            'In progress',
            [
                'is_configured'    => true,
                'responsible_role' => 'Finance Officer',
                'source_type'      => 'Assigned by job title',
                'action_description' => 'Verify funds.',
                'assigned_user'    => null,
                'completer_name'   => null,
                'completer_role'   => null,
            ]
        );
        $this->assertStringContainsString('Finance Officer', $html);
        $this->assertStringContainsString('Verify funds', $html);
    }

    // -----------------------------------------------------------------------
    // 13. buildResponsibilityTooltip() renders completer on completed stage
    // -----------------------------------------------------------------------

    public function testTooltipRendersCompleterOnCompletedStage(): void
    {
        $html = buildResponsibilityTooltip(
            'FUNDS_VERIFIED',
            ['label' => 'Funds Verified'],
            'Completed',
            [
                'is_configured'    => true,
                'responsible_role' => 'Finance Officer',
                'source_type'      => 'Assigned by job title',
                'action_description' => '',
                'assigned_user'    => null,
                'completer_name'   => 'Jane Smith',
                'completer_role'   => 'Finance Officer',
            ]
        );
        $this->assertStringContainsString('Jane Smith', $html);
        $this->assertStringContainsString('Completed by', $html);
    }

    // -----------------------------------------------------------------------
    // 14-18. renderWorkflowPipelineStage() HTML structure
    // -----------------------------------------------------------------------

    public function testStageHtmlContainsAriaDescribedby(): void
    {
        $html = renderWorkflowPipelineStage(
            'SUBMITTED', ['label' => 'Submitted', 'icon' => 'bi-send'],
            1, 5, 0, []
        );
        $this->assertStringContainsString('aria-describedby', $html);
    }

    public function testStageHtmlContainsTooltipId(): void
    {
        $html = renderWorkflowPipelineStage(
            'HOD_APPROVED', ['label' => 'HOD Approved', 'icon' => 'bi-person-check'],
            2, 5, 0, []
        );
        $this->assertMatchesRegularExpression('/id="wf-tip-HOD-APPROVED-/', $html);
    }

    public function testCompletedStageRendersCheckIcon(): void
    {
        $html = renderWorkflowPipelineStage(
            'HOD_APPROVED', ['label' => 'HOD Approved', 'icon' => 'bi-person-check'],
            1, 5, 3, // currentIdx=3 means idx=1 is completed
            []
        );
        $this->assertStringContainsString('bi-check-lg', $html);
        $this->assertStringContainsString('bg-success', $html);
    }

    public function testCurrentStageRendersArrowIcon(): void
    {
        $html = renderWorkflowPipelineStage(
            'HOD_APPROVED', ['label' => 'HOD Approved', 'icon' => 'bi-person-check'],
            2, 5, 2, // currentIdx=2 means this stage IS current
            []
        );
        $this->assertStringContainsString('bi-arrow-right', $html);
        $this->assertStringContainsString('bg-primary', $html);
    }

    public function testPendingStageRendersStageNumber(): void
    {
        $html = renderWorkflowPipelineStage(
            'COMPLETED', ['label' => 'Complete', 'icon' => 'bi-check-circle'],
            4, 5, 1, // currentIdx=1 means idx=4 is pending
            []
        );
        // Stage number is idx+1 = 5
        $this->assertStringContainsString('>5<', $html);
    }

    // -----------------------------------------------------------------------
    // 19. No PHP warnings for missing assignments (null-safety)
    // -----------------------------------------------------------------------

    public function testNoWarningsForMissingAssignments(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        // Empty $request and empty $approvals — must not trigger PHP notices
        $resp = $svc->getStageResponsibility([], 'SUBMITTED', [], 'Admin');
        $this->assertIsArray($resp);
        $this->assertArrayHasKey('is_configured', $resp);
    }

    // -----------------------------------------------------------------------
    // Bonus: getPipelineResponsibility() returns an entry for every stage
    // -----------------------------------------------------------------------

    public function testGetPipelineResponsibilityReturnsAllStages(): void
    {
        $svc = new WorkflowResponsibilityService($this->makePdo());

        $pipelineRaw = getReimbursementPipeline();
        $pipeline = [];
        foreach ($pipelineRaw as $s) {
            $pipeline[$s['status']] = ['label' => $s['label'], 'icon' => $s['icon']];
        }

        $result = $svc->getPipelineResponsibility(
            $pipeline,
            $this->makeReimbRequest(),
            'SUBMITTED',
            [],
            'Finance Officer'
        );

        foreach (array_keys($pipeline) as $stageKey) {
            $this->assertArrayHasKey(
                $stageKey, $result,
                "getPipelineResponsibility() must return an entry for stage {$stageKey}"
            );
        }
    }
}
