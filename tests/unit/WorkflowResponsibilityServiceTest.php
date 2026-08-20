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
     * PDO pre-loaded with the full role/branch fixture set needed to test
     * the branch-based / multi-officer responsibility logic.
     */
    private function makeFullPdo(): PDO
    {
        $pdo = $this->makePdo();

        $pdo->exec("INSERT INTO roles VALUES (4, 'HOD')");
        $pdo->exec("INSERT INTO roles VALUES (9, 'Deputy Government Chemist')");
        $pdo->exec("INSERT INTO roles VALUES (10, 'Director HRM&A')");
        $pdo->exec("INSERT INTO roles VALUES (2, 'Procurement Officer')");
        $pdo->exec("INSERT INTO roles VALUES (11, 'Director Procurement')");
        $pdo->exec("INSERT INTO roles VALUES (12, 'Requestor')");

        $pdo->exec("INSERT INTO branches VALUES (1, 'Executive Branch', 1)");
        $pdo->exec("INSERT INTO branches VALUES (5, 'HRM&A', 1)");
        $pdo->exec("INSERT INTO branches VALUES (6, 'Analytical & Advisory', 1)");
        $pdo->exec("INSERT INTO branches VALUES (7, 'Department of Government Chemist', 1)");
        $pdo->exec("INSERT INTO branches VALUES (99, 'Some Random Branch', 1)");

        // HOD for branch 1 (Executive) and branch 5 (HRM&A)
        $pdo->exec("INSERT INTO users VALUES (20, 'Harry HOD', 'harry@example.com', 4, 1, 1)");
        $pdo->exec("INSERT INTO users VALUES (21, 'Helen HOD', 'helen@example.com', 4, 1, 5)");

        // Org-wide roles
        $pdo->exec("INSERT INTO users VALUES (30, 'Diana DGC', 'diana@example.com', 9, 1, NULL)");
        $pdo->exec("INSERT INTO users VALUES (31, 'Derek Director HRMA', 'derek@example.com', 10, 1, NULL)");
        $pdo->exec("INSERT INTO users VALUES (32, 'Paula Procurement', 'paula@example.com', 2, 1, NULL)");
        $pdo->exec("INSERT INTO users VALUES (33, 'Dexter Director Procurement', 'dexter@example.com', 11, 1, NULL)");

        // Requestor (branch 1)
        $pdo->exec("INSERT INTO users VALUES (40, 'Rita Requestor', 'rita@example.com', 12, 1, 1)");

        return $pdo;
    }

    private function makeRegularRequestFor(int $branchId, int $createdBy = 40): array
    {
        return [
            'request_id'   => 10,
            'request_type' => 'REGULAR',
            'branch_id'    => $branchId,
            'created_by'   => $createdBy,
            'status'       => 'SUBMITTED',
        ];
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
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured'], 'HOD_APPROVED must be configured for REGULAR');
        $this->assertNotEmpty($resp['responsible_role']);
        $this->assertStringContainsStringIgnoringCase('HOD', $resp['responsible_role'],
            'HOD Approved must be owned by the branch HOD (or GC-designated fallback)');
    }

    // -----------------------------------------------------------------------
    // Branch-based / multi-officer responsibility logic (new requirements)
    // -----------------------------------------------------------------------

    public function testNormalizeBranchNameHandlesKnownVariants(): void
    {
        $this->assertSame(
            WorkflowResponsibilityService::normalizeBranchName('Analytical & Advisory'),
            WorkflowResponsibilityService::normalizeBranchName('Analytical and Advisory Branch')
        );
        $this->assertSame(
            WorkflowResponsibilityService::normalizeBranchName('HRM&A'),
            WorkflowResponsibilityService::normalizeBranchName('HRMA')
        );
        $this->assertSame(
            WorkflowResponsibilityService::normalizeBranchName('Executive Branch'),
            WorkflowResponsibilityService::normalizeBranchName('Executive')
        );
    }

    public function testResolveDirectorApprovedRoleForAnalyticalAdvisory(): void
    {
        $this->assertSame(
            'Deputy Government Chemist',
            WorkflowResponsibilityService::resolveDirectorApprovedRole('Analytical & Advisory')
        );
    }

    public function testResolveDirectorApprovedRoleForHrma(): void
    {
        $this->assertSame(
            'Director HRM&A',
            WorkflowResponsibilityService::resolveDirectorApprovedRole('HRMA')
        );
    }

    public function testResolveDirectorApprovedRoleForExecutive(): void
    {
        $this->assertSame(
            'HOD',
            WorkflowResponsibilityService::resolveDirectorApprovedRole('Executive Branch')
        );
    }

    public function testResolveDirectorApprovedRoleFallsBackForUnknownBranch(): void
    {
        $this->assertSame(
            'Director HRM&A',
            WorkflowResponsibilityService::resolveDirectorApprovedRole('Some Random Branch')
        );
        $this->assertSame(
            'Director HRM&A',
            WorkflowResponsibilityService::resolveDirectorApprovedRole(null)
        );
    }

    /** @dataProvider directorApprovedBranchProvider */
    public function testDirectorApprovedResolvesResponsibleUserPerBranch(int $branchId, string $expectedName): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor($branchId),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString($expectedName, (string) $resp['assigned_user']);
    }

    public static function directorApprovedBranchProvider(): array
    {
        return [
            'Analytical & Advisory -> Deputy Government Chemist' => [6, 'Diana DGC'],
            'HRM&A -> Director HRM&A'                            => [5, 'Derek Director HRMA'],
            'Executive -> HOD'                                   => [1, 'Harry HOD'],
            'Unrecognized branch -> Director HRM&A (fallback)'   => [99, 'Derek Director HRMA'],
        ];
    }

    public function testHodApprovedUsesBranchHodWhenAvailable(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            'HOD_APPROVED',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Harry HOD', (string) $resp['assigned_user']);
    }

    public function testHodApprovedFallsBackToGovernmentChemistWhenNoBranchHod(): void
    {
        // Branch 7 (Department of Government Chemist) has no HOD user seeded.
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(7),
            'HOD_APPROVED',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsString('Diana DGC', (string) $resp['assigned_user']);
        $this->assertStringContainsString('Deputy Government Chemist', $resp['responsible_role']);
    }

    public function testFundsVerifiedIsAlwaysFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            'FUNDS_VERIFIED',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role']);
    }

    public function testCommitmentFormIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            'COMMITMENTS_PENDING',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role']);
    }

    public function testInvoiceIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            'INVOICE_RECEIVED',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role']);
    }

    /** @dataProvider quoteStageProvider */
    public function testQuoteStagesIncludeRequestorAndBranchHead(string $stageStatus): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            $stageStatus,
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertCount(2, $resp['assigned_officers']);
        $this->assertStringContainsString('Rita Requestor', (string) $resp['assigned_user']);
        $this->assertStringContainsString('Harry HOD', (string) $resp['assigned_user']);
    }

    public static function quoteStageProvider(): array
    {
        return [
            'Quote Review'   => ['QUOTE_REVIEW_PENDING'],
            'Quote Selected' => ['QUOTE_APPROVED'],
        ];
    }

    /** @dataProvider procurementDirectorStageProvider */
    public function testPoAndRfqIncludeProcurementAndDirectorProcurement(string $stageStatus): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            $stageStatus,
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $this->assertCount(2, $resp['assigned_officers']);
        $this->assertStringContainsString('Paula Procurement', (string) $resp['assigned_user']);
        $this->assertStringContainsString('Dexter Director Procurement', (string) $resp['assigned_user']);
    }

    public static function procurementDirectorStageProvider(): array
    {
        return [
            'RFQ Letters'    => ['RFQ_LETTER_AVAILABLE'],
            'Purchase Order' => ['PO_PENDING'],
        ];
    }

    public function testMissingRequestorIsHandledGracefully(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1, 99999), // non-existent requestor id
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $officers = $resp['assigned_officers'];
        $this->assertCount(2, $officers);
        $requestorOfficer = array_values(array_filter($officers, fn($o) => $o['role'] === 'Requestor'))[0];
        $this->assertNull($requestorOfficer['name'], 'Missing requestor must resolve to a null name, not an error');
    }

    public function testMissingBranchHeadIsHandledGracefully(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makeFullPdo());
        // Branch 99 has no HOD seeded.
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(99),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $this->assertTrue($resp['is_configured']);
        $officers = $resp['assigned_officers'];
        $branchHeadOfficer = array_values(array_filter($officers, fn($o) => $o['role'] === 'Branch Head'))[0];
        $this->assertNull($branchHeadOfficer['name']);
    }

    public function testDuplicateOfficersAreNotShownTwice(): void
    {
        $pdo = $this->makeFullPdo();
        // Make the requestor (user 40) also the HOD of branch 1, duplicating Harry HOD.
        $pdo->exec("UPDATE users SET role_id = 4 WHERE user_id = 40");

        $svc  = new WorkflowResponsibilityService($pdo);
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequestFor(1),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        // With user 40 now also holding the HOD role, findRoleUser() for branch 1
        // sees two HOD users (40 and 20) and returns null (ambiguous) — so the
        // resolved officer names should not contain a duplicate "Rita Requestor".
        $names = array_filter(array_column($resp['assigned_officers'], 'name'));
        $this->assertSame(array_values($names), array_values(array_unique($names)),
            'Resolved officer names must not contain duplicates');
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
