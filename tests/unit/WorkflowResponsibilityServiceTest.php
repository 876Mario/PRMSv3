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
 *  5.  getStageResponsibility() – HOD_APPROVED returns HOD role (not Finance Officer).
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
 * 20.  DIRECTOR_APPROVED – Analytical & Advisory → Deputy Government Chemist.
 * 21.  DIRECTOR_APPROVED – HRM&A branch → Director HRM&A.
 * 22.  DIRECTOR_APPROVED – Executive Branch → HOD.
 * 23.  DIRECTOR_APPROVED – other branch → Director HRM&A (default).
 * 24.  DIRECTOR_APPROVED – branch not found (id=0) → Director HRM&A (default).
 * 25.  DIRECTOR_APPROVED – normalises "HRMA / Administration Branch" variant.
 * 26.  QUOTE_REVIEW_PENDING – returns Requestor + HOD as responsible_roles.
 * 27.  QUOTE_APPROVED (Quote Selected) – returns Requestor + HOD.
 * 28.  QUOTE_REVIEW_PENDING – missing requestor (no created_by) → HOD only.
 * 29.  QUOTE_REVIEW_PENDING – requestor is same person as HOD → deduplicated.
 * 30.  FUNDS_VERIFIED – Finance Officer (not Director HRM&A).
 * 31.  COMMITMENTS_PENDING – Finance Officer.
 * 32.  PO_PENDING – Procurement Officer + Director Procurement.
 * 33.  PO_APPROVED – Procurement Officer + Director Procurement.
 * 34.  RFQ_LETTER_AVAILABLE – Procurement Officer + Director Procurement.
 * 35.  INVOICE_RECEIVED – Finance Officer.
 * 36.  HOD_APPROVED – responsible role is HOD.
 * 37.  buildResponsibilityTooltip() renders multi-officer list.
 * 38.  getPipelineResponsibility() returns an entry for every stage.
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
            CREATE TABLE branches (
                branch_id   INTEGER PRIMARY KEY,
                branch_name TEXT    NOT NULL,
                is_active   INTEGER NOT NULL DEFAULT 1
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

        // Branches matching the real DB
        $pdo->exec("INSERT INTO branches VALUES (1,  'Executive Branch',           1)");
        $pdo->exec("INSERT INTO branches VALUES (5,  'HRM&A',                      1)");
        $pdo->exec("INSERT INTO branches VALUES (6,  'Analytical & Advisory',      1)");
        $pdo->exec("INSERT INTO branches VALUES (7,  'Quality Assurance Branch',   1)");
        $pdo->exec("INSERT INTO branches VALUES (22, 'HRMA / Administration Branch', 0)");

        // Roles
        $pdo->exec("INSERT INTO roles VALUES (2,  'Procurement Officer')");
        $pdo->exec("INSERT INTO roles VALUES (3,  'Finance Officer')");
        $pdo->exec("INSERT INTO roles VALUES (4,  'HOD')");
        $pdo->exec("INSERT INTO roles VALUES (11, 'Director Procurement')");
        $pdo->exec("INSERT INTO roles VALUES (12, 'Requestor')");

        // Finance Officer in branch 1
        $pdo->exec("INSERT INTO users VALUES (10, 'Jane Smith',  'jane@example.com', 3, 1, 1)");
        // HOD in branch 1 (Executive)
        $pdo->exec("INSERT INTO users VALUES (20, 'Alice Head',  'alice@example.com', 4, 1, 1)");
        // HOD in branch 6 (Analytical & Advisory)
        $pdo->exec("INSERT INTO users VALUES (21, 'Bob Head',    'bob@example.com',   4, 1, 6)");
        // Requestor (branch 1)
        $pdo->exec("INSERT INTO users VALUES (30, 'Carol Staff', 'carol@example.com', 12, 1, 1)");
        // Procurement Officer (org-wide, no branch)
        $pdo->exec("INSERT INTO users VALUES (40, 'Dave Proc',   'dave@example.com',  2, 1, NULL)");
        // Director Procurement (org-wide)
        $pdo->exec("INSERT INTO users VALUES (41, 'Eve DirProc', 'eve@example.com',   11, 1, NULL)");

        return $pdo;
    }

    /** Minimal request row for REIMBURSEMENT */
    private function makeReimbRequest(): array
    {
        return [
            'request_id'   => 99,
            'request_type' => 'REIMBURSEMENT',
            'branch_id'    => 1,
            'created_by'   => 30,
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
            'created_by'   => 30,
            'status'       => 'SUBMITTED',
        ];
    }

    /** Minimal request row for REGULAR (branch 1 = Executive) */
    private function makeRegularRequest(int $branchId = 1, int $createdBy = 30): array
    {
        return [
            'request_id'   => 10,
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

    public function testRegularHodApprovedReturnsHodRole(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured'], 'HOD_APPROVED must be configured for REGULAR');
        $this->assertStringContainsStringIgnoringCase('HOD', $resp['responsible_role'],
            'HOD_APPROVED stage must show HOD as responsible officer');
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
                'is_configured'      => true,
                'responsible_role'   => 'Finance Officer',
                'responsible_roles'  => [],
                'source_type'        => 'Assigned by job title',
                'action_description' => 'Verify funds.',
                'assigned_user'      => null,
                'completer_name'     => null,
                'completer_role'     => null,
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
        // PHPUnit 8 compatible regex check
        $this->assertRegExp('/id="wf-tip-HOD-APPROVED-/', $html);
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
    // 20-25. DIRECTOR_APPROVED – branch-based responsible officer
    // -----------------------------------------------------------------------

    public function testDirectorApprovedAnalyticalAdvisory(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(6), // branch 6 = Analytical & Advisory
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Deputy Government Chemist', $resp['responsible_role'],
            'Analytical & Advisory branch must map to Deputy Government Chemist');
    }

    public function testDirectorApprovedHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(5), // branch 5 = HRM&A
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role'],
            'HRM&A branch must map to Director HRM&A');
    }

    public function testDirectorApprovedExecutiveBranch(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(1), // branch 1 = Executive Branch
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('HOD', $resp['responsible_role'],
            'Executive Branch must map to HOD');
    }

    public function testDirectorApprovedOtherBranchDefaultsToDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(7), // branch 7 = Quality Assurance (not one of the three)
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role'],
            'Any unrecognised branch must default to Director HRM&A');
    }

    public function testDirectorApprovedNoBranchDefaultsToDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(0), // branchId = 0 → unknown
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role'],
            'Missing branch_id must default to Director HRM&A');
    }

    public function testDirectorApprovedNormalisesHrmaAdminBranchVariant(): void
    {
        // Branch 22 = "HRMA / Administration Branch" — an inactive variant
        // that must still normalise to Director HRM&A.
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(22),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Director HRM&A', $resp['responsible_role'],
            '"HRMA / Administration Branch" variant must normalise to Director HRM&A');
    }

    // -----------------------------------------------------------------------
    // 26-29. QUOTE_REVIEW_PENDING and QUOTE_APPROVED (Quote Selected)
    // -----------------------------------------------------------------------

    public function testQuoteReviewReturnsRequestorAndBranchHead(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(1, 30), // requestor=30 (Carol), branch 1 HOD=user 20 (Alice)
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertNotEmpty($resp['responsible_roles'],
            'QUOTE_REVIEW_PENDING must return a multi-officer list');

        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Requestor', $roles, 'Requestor must be in responsible_roles');
        $this->assertContains('HOD', $roles, 'HOD must be in responsible_roles');

        $users = array_column($resp['responsible_roles'], 'user');
        $this->assertContains('Carol Staff', $users, 'Requestor name must be resolved');
        $this->assertContains('Alice Head', $users, 'HOD name must be resolved');
    }

    public function testQuoteSelectedReturnsRequestorAndBranchHead(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(1, 30),
            'QUOTE_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Requestor', $roles);
        $this->assertContains('HOD', $roles);
    }

    public function testQuoteReviewMissingRequestorShowsHodOnly(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        // created_by = 0 → no requestor lookup
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(1, 0),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        // Both entries still appear (role stays even when user is null)
        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Requestor', $roles, 'Requestor role entry present even without a name');
        $this->assertContains('HOD', $roles);

        $requestorEntry = array_values(array_filter(
            $resp['responsible_roles'],
            fn($o) => $o['role'] === 'Requestor'
        ))[0];
        $this->assertNull($requestorEntry['user'],
            'User must be null when requestor cannot be found');
    }

    public function testQuoteReviewDeduplicatesWhenRequestorIsHod(): void
    {
        // User 20 (Alice Head) is both the HOD and the requestor for branch 1
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(1, 20), // created_by = 20 = Alice Head (HOD)
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        // After deduplication, 'Alice Head' must appear only once
        $users = array_filter(
            array_column($resp['responsible_roles'], 'user'),
            fn($u) => $u === 'Alice Head'
        );
        $this->assertCount(1, $users,
            'Same person appearing as both Requestor and HOD must be deduplicated');
    }

    // -----------------------------------------------------------------------
    // 30-35. Single-officer stages: correct responsible role
    // -----------------------------------------------------------------------

    public function testFundsVerifiedIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'FUNDS_VERIFIED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Finance Officer', $resp['responsible_role'],
            'FUNDS_VERIFIED must show Finance Officer');
    }

    public function testCommitmentsPendingIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'COMMITMENTS_PENDING',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertStringContainsStringIgnoringCase('Finance', $resp['responsible_role'],
            'COMMITMENTS_PENDING must show Finance Officer');
    }

    public function testPoPendingReturnsProcurementOfficerAndDirector(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'PO_PENDING',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Procurement Officer',  $roles);
        $this->assertContains('Director Procurement', $roles);
        // Verify named users are resolved
        $users = array_column($resp['responsible_roles'], 'user');
        $this->assertContains('Dave Proc',   $users, 'Procurement Officer name must be resolved');
        $this->assertContains('Eve DirProc', $users, 'Director Procurement name must be resolved');
    }

    public function testPoApprovedReturnsProcurementOfficerAndDirector(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'PO_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Procurement Officer',  $roles);
        $this->assertContains('Director Procurement', $roles);
    }

    public function testRfqLetterAvailableReturnsProcurementOfficerAndDirector(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'RFQ_LETTER_AVAILABLE',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $roles = array_column($resp['responsible_roles'], 'role');
        $this->assertContains('Procurement Officer',  $roles);
        $this->assertContains('Director Procurement', $roles);
    }

    public function testInvoiceReceivedIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'INVOICE_RECEIVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('Finance Officer', $resp['responsible_role'],
            'INVOICE_RECEIVED must show Finance Officer');
    }

    // -----------------------------------------------------------------------
    // 36. HOD_APPROVED – responsible role is HOD
    // -----------------------------------------------------------------------

    public function testHodApprovedResponsibleRoleIsHod(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRegularRequest(),
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertTrue($resp['is_configured']);
        $this->assertSame('HOD', $resp['responsible_role'],
            'HOD_APPROVED must display HOD as responsible officer');
    }

    // -----------------------------------------------------------------------
    // 37. buildResponsibilityTooltip() renders multi-officer list
    // -----------------------------------------------------------------------

    public function testTooltipRendersMultiOfficerList(): void
    {
        $html = buildResponsibilityTooltip(
            'QUOTE_REVIEW_PENDING',
            ['label' => 'Quote Review'],
            'In progress',
            [
                'is_configured'      => true,
                'responsible_role'   => 'Requestor / HOD',
                'responsible_roles'  => [
                    ['role' => 'Requestor', 'user' => 'Carol Staff'],
                    ['role' => 'HOD',       'user' => 'Alice Head'],
                ],
                'source_type'        => 'Assigned by job title',
                'action_description' => 'Review submitted quotations.',
                'assigned_user'      => null,
                'completer_name'     => null,
                'completer_role'     => null,
            ]
        );
        $this->assertStringContainsString('Carol Staff', $html,
            'Requestor name must appear in multi-officer tooltip');
        $this->assertStringContainsString('Alice Head', $html,
            'HOD name must appear in multi-officer tooltip');
        $this->assertStringContainsString('Requestor', $html);
        $this->assertStringContainsString('HOD', $html);
        // Verify "Responsible officers:" (plural) is used
        $this->assertStringContainsString('officers', $html);
    }

    public function testTooltipRendersMultiOfficerRoleNameWhenNoUser(): void
    {
        $html = buildResponsibilityTooltip(
            'PO_PENDING',
            ['label' => 'Purchase Order'],
            'Pending',
            [
                'is_configured'      => true,
                'responsible_role'   => 'Procurement Officer',
                'responsible_roles'  => [
                    ['role' => 'Procurement Officer',  'user' => null],
                    ['role' => 'Director Procurement', 'user' => null],
                ],
                'source_type'        => 'Assigned by job title',
                'action_description' => 'Generate purchase order.',
                'assigned_user'      => null,
                'completer_name'     => null,
                'completer_role'     => null,
            ]
        );
        $this->assertStringContainsString('Procurement Officer', $html);
        $this->assertStringContainsString('Director Procurement', $html);
    }

    // -----------------------------------------------------------------------
    // 38. getPipelineResponsibility() returns an entry for every stage
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
