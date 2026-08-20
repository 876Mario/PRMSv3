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
        $pdo->exec("
            CREATE TABLE branches (
                branch_id   INTEGER PRIMARY KEY,
                branch_name TEXT NOT NULL,
                is_active   INTEGER NOT NULL DEFAULT 1
            )
        ");

        // Seed a Finance Officer user in branch 1
        $pdo->exec("INSERT INTO roles VALUES (3, 'Finance Officer')");
        $pdo->exec("INSERT INTO users VALUES (10, 'Jane Smith', 'jane@example.com', 3, 1, 1)");

        return $pdo;
    }

    /**
     * Extended fixture used by the branch/officer-resolution test group:
     * seeds branches 1 (Executive), 5 (HRM&A), 6 (Analytical & Advisory),
     * 7 (Registry — an "other" branch), plus one user per relevant role.
     */
    private function makeFullPdo(): PDO
    {
        $pdo = $this->makePdo();

        $pdo->exec("
            INSERT INTO branches (branch_id, branch_name, is_active) VALUES
                (1, 'Executive Branch', 1),
                (5, 'HRM&A', 1),
                (6, 'Analytical & Advisory', 1),
                (7, 'Registry', 1)
        ");

        $pdo->exec("INSERT INTO roles VALUES (4, 'HOD')");
        $pdo->exec("INSERT INTO roles VALUES (9, 'Deputy Government Chemist')");
        $pdo->exec("INSERT INTO roles VALUES (10, 'Director HRM&A')");
        $pdo->exec("INSERT INTO roles VALUES (2, 'Procurement Officer')");
        $pdo->exec("INSERT INTO roles VALUES (11, 'Director Procurement')");
        $pdo->exec("INSERT INTO roles VALUES (12, 'Requestor')");
        $pdo->exec("INSERT INTO roles VALUES (13, 'Government Chemist')");

        $pdo->exec("INSERT INTO users VALUES (20, 'Hazel HOD', 'hazel@example.com', 4, 1, 1)");        // HOD, Executive branch
        $pdo->exec("INSERT INTO users VALUES (21, 'Rita RegistryHOD', 'rita@example.com', 4, 1, 7)");  // HOD, Registry branch
        $pdo->exec("INSERT INTO users VALUES (22, 'Derek DGC', 'derek@example.com', 9, 1, null)");     // Deputy Government Chemist
        $pdo->exec("INSERT INTO users VALUES (23, 'Diana Director', 'diana@example.com', 10, 1, null)"); // Director HRM&A
        $pdo->exec("INSERT INTO users VALUES (24, 'Pat Procurement', 'pat@example.com', 2, 1, null)"); // Procurement Officer
        $pdo->exec("INSERT INTO users VALUES (25, 'Debra DirProc', 'debra@example.com', 11, 1, null)"); // Director Procurement
        $pdo->exec("INSERT INTO users VALUES (26, 'Rachel Requestor', 'rachel@example.com', 12, 1, 7)"); // Requestor, Registry branch

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

    /** REGULAR request row with a specific branch and requestor id */
    private function makeRequest(int $branchId, int $createdBy = 0): array
    {
        return [
            'request_id'   => 100,
            'request_type' => 'REGULAR',
            'branch_id'    => $branchId,
            'created_by'   => $createdBy,
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
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured'], 'HOD_APPROVED must be configured for REGULAR');
        $this->assertNotEmpty($resp['responsible_role']);
        // Branch 1 has no seeded branches table row in this minimal fixture,
        // so it falls back to the generic HOD rule (not the Executive-branch
        // Government Chemist special case).
        $this->assertStringContainsStringIgnoringCase('HOD', $resp['responsible_role']);
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

    // =========================================================================
    // Branch-aware / multi-officer responsibility rules
    // (Director Approved, HOD Approved, Quote Review, Quote Selected,
    //  Funds Verified, Commitment Form, Purchase Order, RFQ Letters, Invoice)
    // =========================================================================

    // -----------------------------------------------------------------------
    // Director Approved: branch-dependent officer
    // -----------------------------------------------------------------------

    public function testDirectorApprovedAnalyticalAdvisoryBranchIsDeputyGovernmentChemist(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(6), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Deputy Government Chemist', $resp['responsible_role']);
        $this->assertSame('Derek DGC', $resp['assigned_user']);
    }

    public function testDirectorApprovedHrmaBranchIsDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(5), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
        $this->assertSame('Diana Director', $resp['assigned_user']);
    }

    public function testDirectorApprovedExecutiveBranchIsHod(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(1), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('HOD', $resp['responsible_role']);
        $this->assertSame('Hazel HOD', $resp['assigned_user']);
    }

    public function testDirectorApprovedUnknownBranchFallsBackToDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        // Branch 7 ("Registry") is not one of the three named branches.
        $resp = $svc->getStageResponsibility($this->makeRequest(7), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
        $this->assertSame('Diana Director', $resp['assigned_user']);
    }

    public function testDirectorApprovedHandlesHrmaVariantSpellings(): void
    {
        $pdo = $this->makeFullPdo();
        // Replace branch 5's name with a differently-formatted variant.
        $pdo->exec("UPDATE branches SET branch_name = 'HRMA' WHERE branch_id = 5");
        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(5), 'DIRECTOR_APPROVED', [], 'Admin');
        $this->assertSame('Director HRM&A', $resp['responsible_role']);

        $pdo2 = $this->makeFullPdo();
        $pdo2->exec("UPDATE branches SET branch_name = '  hrm & a branch ' WHERE branch_id = 5");
        $svc2  = new WorkflowResponsibilityService($pdo2);
        $resp2 = $svc2->getStageResponsibility($this->makeRequest(5), 'DIRECTOR_APPROVED', [], 'Admin');
        $this->assertSame('Director HRM&A', $resp2['responsible_role']);
    }

    public function testDirectorApprovedHandlesAnalyticalAdvisoryVariantSpellings(): void
    {
        $pdo = $this->makeFullPdo();
        $pdo->exec("UPDATE branches SET branch_name = 'Analytical and Advisory Branch' WHERE branch_id = 6");
        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(6), 'DIRECTOR_APPROVED', [], 'Admin');
        $this->assertSame('Deputy Government Chemist', $resp['responsible_role']);
    }

    public function testDirectorApprovedMissingBranchNameFallsBackGracefully(): void
    {
        // Branch id that has no row at all in the branches table.
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(999), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
        // Director HRM&A is an organisation-wide role, so the unique
        // Director HRM&A user is still resolved even though branch 999
        // doesn't exist — only branch-scoped roles depend on a valid branch.
        $this->assertSame('Diana Director', $resp['assigned_user']);
    }

    public function testDirectorApprovedMissingAssignedUserHandledGracefully(): void
    {
        $pdo = $this->makeFullPdo();
        $pdo->exec("DELETE FROM users WHERE user_id = 23"); // remove Director HRM&A user
        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(5), 'DIRECTOR_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured'], 'Role must remain configured even when no user matches');
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
        $this->assertNull($resp['assigned_user']);
        $this->assertSame([], $resp['assigned_users']);
    }

    // -----------------------------------------------------------------------
    // HOD Approved: Government Chemist for Executive Branch, HOD elsewhere
    // -----------------------------------------------------------------------

    public function testHodApprovedExecutiveBranchIsGovernmentChemist(): void
    {
        $pdo = $this->makeFullPdo();
        $pdo->exec("INSERT INTO users VALUES (30, 'Greg GC', 'greg@example.com', 13, 1, null)");
        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(1), 'HOD_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Government Chemist', $resp['responsible_role']);
        $this->assertSame('Greg GC', $resp['assigned_user']);
    }

    public function testHodApprovedNonExecutiveBranchIsHod(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(7), 'HOD_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('HOD', $resp['responsible_role']);
        $this->assertSame('Rita RegistryHOD', $resp['assigned_user']);
    }

    public function testHodApprovedMissingGovernmentChemistUserHandledGracefully(): void
    {
        // No 'Government Chemist' user seeded in this fixture.
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(1), 'HOD_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Government Chemist', $resp['responsible_role']);
        $this->assertNull($resp['assigned_user']);
    }

    // -----------------------------------------------------------------------
    // Quote Review / Quote Selected: Requestor + Branch Head
    // -----------------------------------------------------------------------

    /** @dataProvider quoteStageProvider */
    public function testQuoteStageReturnsRequestorAndBranchHead(string $stage): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(7, 26), $stage, [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Requestor', $resp['responsible_role']);
        $this->assertStringContainsString('Branch Head', $resp['responsible_role']);
        $this->assertContains('Rachel Requestor', $resp['assigned_users']);
        $this->assertContains('Rita RegistryHOD', $resp['assigned_users']);
        $this->assertCount(2, $resp['assigned_users'], 'Requestor and branch head must not be duplicated');
    }

    public static function quoteStageProvider(): array
    {
        return [
            'Quote Review'   => ['QUOTE_REVIEW_PENDING'],
            'Quote Selected' => ['QUOTE_APPROVED'],
        ];
    }

    public function testQuoteStageDedupesWhenRequestorIsAlsoBranchHead(): void
    {
        $pdo = $this->makeFullPdo();
        // Rachel Requestor (26) is also the HOD for branch 7 in this scenario:
        // update the HOD user's name to match the requestor's, simulating the
        // same physical person filling both roles.
        $pdo->exec("UPDATE users SET full_name = 'Rachel Requestor' WHERE user_id = 21");

        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(7, 26), 'QUOTE_REVIEW_PENDING', [], 'Admin');

        $this->assertCount(1, $resp['assigned_users'], 'Duplicate officer names must not be repeated');
        $this->assertSame('Rachel Requestor', $resp['assigned_user']);
    }

    public function testQuoteStageMissingRequestorHandledGracefully(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        // No created_by supplied (0) — requestor cannot be resolved.
        $resp = $svc->getStageResponsibility($this->makeRequest(7, 0), 'QUOTE_APPROVED', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Requestor', $resp['responsible_role']);
        $this->assertStringContainsString('Branch Head', $resp['responsible_role']);
        // Only the branch head could be resolved to a name.
        $this->assertSame(['Rita RegistryHOD'], $resp['assigned_users']);
    }

    public function testQuoteStageMissingBranchHeadHandledGracefully(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        // Branch 6 (Analytical & Advisory) has no seeded HOD/Branch Head user.
        $resp = $svc->getStageResponsibility($this->makeRequest(6, 26), 'QUOTE_REVIEW_PENDING', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame(['Rachel Requestor'], $resp['assigned_users']);
    }

    // -----------------------------------------------------------------------
    // Funds Verified / Commitment Form / Invoice: Finance Officer
    // -----------------------------------------------------------------------

    /** @dataProvider financeOfficerStageProvider */
    public function testFinanceOfficerOwnsStage(string $stage): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(1), $stage, [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Finance Officer', $resp['responsible_role']);
    }

    public static function financeOfficerStageProvider(): array
    {
        return [
            'Funds Verified'  => ['FUNDS_VERIFIED'],
            'Commitment Form' => ['COMMITMENTS_PENDING'],
            'Invoice'         => ['INVOICE_RECEIVED'],
        ];
    }

    // -----------------------------------------------------------------------
    // Purchase Order / RFQ Letters: Procurement Officer + Director Procurement
    // -----------------------------------------------------------------------

    /** @dataProvider procurementDirectorStageProvider */
    public function testProcurementAndDirectorOwnStage(string $stage): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(1), $stage, [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Procurement Officer', $resp['responsible_role']);
        $this->assertStringContainsString('Director Procurement', $resp['responsible_role']);
        $this->assertContains('Pat Procurement', $resp['assigned_users']);
        $this->assertContains('Debra DirProc', $resp['assigned_users']);
        $this->assertCount(2, $resp['assigned_users']);
    }

    public static function procurementDirectorStageProvider(): array
    {
        return [
            'RFQ Letters'    => ['RFQ_LETTER_AVAILABLE'],
            'Purchase Order' => ['PO_PENDING'],
        ];
    }

    public function testProcurementAndDirectorHandleMissingDirectorGracefully(): void
    {
        $pdo = $this->makeFullPdo();
        $pdo->exec("DELETE FROM users WHERE user_id = 25"); // remove Director Procurement
        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility($this->makeRequest(1), 'PO_PENDING', [], 'Admin');

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Director Procurement', $resp['responsible_role']);
        $this->assertSame(['Pat Procurement'], $resp['assigned_users']);
    }

    // -----------------------------------------------------------------------
    // Branch name normalization
    // -----------------------------------------------------------------------

    public function testClassifyBranchHandlesKnownVariants(): void
    {
        $this->assertSame('HRMA', WorkflowResponsibilityService::classifyBranch('HRM&A'));
        $this->assertSame('HRMA', WorkflowResponsibilityService::classifyBranch('HRMA'));
        $this->assertSame('HRMA', WorkflowResponsibilityService::classifyBranch('  hrm & a  Branch '));
        $this->assertSame(
            'ANALYTICAL_ADVISORY',
            WorkflowResponsibilityService::classifyBranch('Analytical & Advisory')
        );
        $this->assertSame(
            'ANALYTICAL_ADVISORY',
            WorkflowResponsibilityService::classifyBranch('analytical and advisory branch')
        );
        $this->assertSame('EXECUTIVE', WorkflowResponsibilityService::classifyBranch('Executive Branch'));
        $this->assertSame('OTHER', WorkflowResponsibilityService::classifyBranch('Registry'));
        $this->assertSame('OTHER', WorkflowResponsibilityService::classifyBranch(null));
        $this->assertSame('OTHER', WorkflowResponsibilityService::classifyBranch(''));
    }
}
