<?php
/**
 * WorkflowResponsibilityOfficerMappingTest
 *
 * Focused coverage for the branch-aware / multi-officer responsibility
 * rules added to WorkflowResponsibilityService:
 *
 *  1.  Director Approved — Analytical and Advisory Branch → Deputy Government Chemist.
 *  2.  Director Approved — HRM&A Branch → Director HRM&A.
 *  3.  Director Approved — Executive Branch → HOD.
 *  4.  Director Approved — unrecognised branch falls back to Director HRM&A.
 *  5.  Director Approved — branch-name normalization (spacing/case/"HRMA" variant).
 *  6.  Quote Review — requestor + branch head both listed.
 *  7.  Quote Selected — requestor + branch head both listed.
 *  8.  Quote Review / Selected — duplicate officer removed when requestor IS the branch head.
 *  9.  Funds Verified — Finance Officer.
 * 10.  Commitment Form — Finance Officer.
 * 11.  Purchase Order — Procurement Officer + Director Procurement.
 * 12.  RFQ Letters — Procurement Officer + Director Procurement.
 * 13.  Invoice — Finance Officer.
 * 14.  HOD Approved — Analytical and Advisory Branch → Government Chemist.
 * 15.  HOD Approved — other branches → branch's designated HOD.
 * 16.  Missing requestor user gracefully falls back to a null name (no error).
 * 17.  Missing role holder (nobody assigned) gracefully falls back to a null name.
 * 18.  Tooltip renders "Not yet assigned" when an officer cannot be resolved.
 * 19.  Tooltip lists multiple distinct officers without duplication.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowResponsibilityService.php';
require_once dirname(__DIR__, 2) . '/includes/workflow_pipeline.php';

class WorkflowResponsibilityOfficerMappingTest extends PHPUnit\Framework\TestCase
{
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
                approved_by INTEGER DEFAULT NULL
            )
        ");

        $pdo->exec("INSERT INTO roles VALUES (1, 'HOD')");
        $pdo->exec("INSERT INTO roles VALUES (2, 'Branch Head')");
        $pdo->exec("INSERT INTO roles VALUES (3, 'Finance Officer')");
        $pdo->exec("INSERT INTO roles VALUES (4, 'Deputy Government Chemist')");
        $pdo->exec("INSERT INTO roles VALUES (5, 'Director HRM&A')");
        $pdo->exec("INSERT INTO roles VALUES (6, 'Government Chemist')");
        $pdo->exec("INSERT INTO roles VALUES (7, 'Procurement Officer')");
        $pdo->exec("INSERT INTO roles VALUES (8, 'Director Procurement')");
        $pdo->exec("INSERT INTO roles VALUES (9, 'Requestor')");

        // Branch 1 = Executive, Branch 5 = HRM&A, Branch 6 = Analytical & Advisory
        $pdo->exec("INSERT INTO users VALUES (100, 'Helen HOD',        'h@x.gov', 1, 1, 1)");   // HOD of Executive
        $pdo->exec("INSERT INTO users VALUES (101, 'Bella BranchHead', 'b@x.gov', 2, 1, 3)");   // Branch Head of branch 3
        $pdo->exec("INSERT INTO users VALUES (102, 'Fiona Finance',    'f@x.gov', 3, 1, 3)");   // Finance Officer of branch 3
        $pdo->exec("INSERT INTO users VALUES (103, 'Derek DGC',        'd@x.gov', 4, 1, null)"); // Deputy Government Chemist (org-wide)
        $pdo->exec("INSERT INTO users VALUES (104, 'Diana Director',   'di@x.gov', 5, 1, null)"); // Director HRM&A (org-wide)
        $pdo->exec("INSERT INTO users VALUES (105, 'Gary Chemist',     'g@x.gov', 6, 1, null)"); // Government Chemist (org-wide)
        $pdo->exec("INSERT INTO users VALUES (106, 'Pete Procurement', 'p@x.gov', 7, 1, null)"); // Procurement Officer (org-wide)
        $pdo->exec("INSERT INTO users VALUES (107, 'Debra DirProc',    'de@x.gov', 8, 1, null)"); // Director Procurement (org-wide)
        $pdo->exec("INSERT INTO users VALUES (108, 'Rachel Requestor', 'r@x.gov', 9, 1, 3)");    // Requestor, branch 3
        // Requestor who is also the branch head of branch 3 (duplicate scenario)
        $pdo->exec("UPDATE users SET full_name = 'Bella BranchHead' WHERE user_id = 101");

        return $pdo;
    }

    private function makeRequest(array $overrides = []): array
    {
        return array_merge([
            'request_id'   => 1,
            'request_type' => 'REGULAR',
            'branch_id'    => 3,
            'branch_name'  => 'Some Other Branch',
            'created_by'   => 108,
            'status'       => 'SUBMITTED',
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // 1-5. Director Approved — branch mapping
    // -----------------------------------------------------------------------

    public function testDirectorApprovedAnalyticalAdvisoryIsDeputyGovernmentChemist(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 6, 'branch_name' => 'Analytical & Advisory']),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('Deputy Government Chemist', $resp['responsible_role']);
        $this->assertSame('Derek DGC', $resp['assigned_user']);
    }

    public function testDirectorApprovedHrmaIsDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 5, 'branch_name' => 'HRM&A']),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
        $this->assertSame('Diana Director', $resp['assigned_user']);
    }

    public function testDirectorApprovedExecutiveIsHod(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 1, 'branch_name' => 'Executive Branch']),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('HOD', $resp['responsible_role']);
        $this->assertSame('Helen HOD', $resp['assigned_user']);
    }

    public function testDirectorApprovedUnrecognisedBranchFallsBackToDirectorHrma(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 99, 'branch_name' => 'Some Unmapped Branch']),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('Director HRM&A', $resp['responsible_role']);
    }

    /** @dataProvider hrmaVariantProvider */
    public function testDirectorApprovedNormalizesHrmaBranchNameVariants(string $branchName): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 5, 'branch_name' => $branchName]),
            'DIRECTOR_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame(
            'Director HRM&A',
            $resp['responsible_role'],
            "Branch name variant '{$branchName}' must normalize to HRM&A"
        );
    }

    public static function hrmaVariantProvider(): array
    {
        return [
            ['hrm&a'],
            ['HRMA'],
            ['  HRM & A  '],
            ['Hrm&a Branch'],
            ['hrma branch'],
        ];
    }

    // -----------------------------------------------------------------------
    // 6-8. Quote Review / Quote Selected — requestor + branch head
    // -----------------------------------------------------------------------

    public function testQuoteReviewListsRequestorAndBranchHead(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $roles = array_column($resp['responsible_officers'], 'role');
        $names = array_column($resp['responsible_officers'], 'name');

        $this->assertContains('Requestor', $roles);
        $this->assertContains('Branch Head', $roles);
        $this->assertContains('Rachel Requestor', $names);
        $this->assertContains('Bella BranchHead', $names);
    }

    public function testQuoteSelectedListsRequestorAndBranchHead(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(),
            'QUOTE_APPROVED',
            [],
            'Admin'
        );

        $roles = array_column($resp['responsible_officers'], 'role');
        $this->assertContains('Requestor', $roles);
        $this->assertContains('Branch Head', $roles);
    }

    public function testQuoteReviewDeduplicatesWhenRequestorIsBranchHead(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        // user 101 (Bella BranchHead) is both the Branch Head of branch 3
        // AND, in this scenario, the requestor who submitted the request.
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['created_by' => 101]),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $this->assertCount(
            1,
            $resp['responsible_officers'],
            'Requestor and Branch Head resolving to the same person must be shown once'
        );
        $this->assertSame('Bella BranchHead', $resp['responsible_officers'][0]['name']);
        $this->assertStringContainsString('Requestor', $resp['responsible_officers'][0]['role']);
        $this->assertStringContainsString('Branch Head', $resp['responsible_officers'][0]['role']);
    }

    // -----------------------------------------------------------------------
    // 9, 10, 13. Finance Officer stages
    // -----------------------------------------------------------------------

    public function testFundsVerifiedIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(), 'FUNDS_VERIFIED', [], 'Admin');
        $this->assertSame('Finance Officer', $resp['responsible_role']);
        $this->assertSame('Fiona Finance', $resp['assigned_user']);
    }

    public function testCommitmentFormIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(), 'COMMITMENTS_PENDING', [], 'Admin');
        $this->assertSame('Finance Officer', $resp['responsible_role']);
    }

    public function testInvoiceIsFinanceOfficer(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(), 'INVOICE_RECEIVED', [], 'Admin');
        $this->assertSame('Finance Officer', $resp['responsible_role']);
    }

    // -----------------------------------------------------------------------
    // 11, 12. Purchase Order / RFQ Letters — Procurement Officer + Director Procurement
    // -----------------------------------------------------------------------

    public function testPurchaseOrderListsProcurementAndDirectorProcurement(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(), 'PO_PENDING', [], 'Admin');

        $roles = array_column($resp['responsible_officers'], 'role');
        $names = array_column($resp['responsible_officers'], 'name');

        $this->assertContains('Procurement Officer', $roles);
        $this->assertContains('Director Procurement', $roles);
        $this->assertContains('Pete Procurement', $names);
        $this->assertContains('Debra DirProc', $names);
    }

    public function testRfqLettersListsProcurementAndDirectorProcurement(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility($this->makeRequest(), 'RFQ_LETTER_AVAILABLE', [], 'Admin');

        $roles = array_column($resp['responsible_officers'], 'role');
        $this->assertContains('Procurement Officer', $roles);
        $this->assertContains('Director Procurement', $roles);
    }

    // -----------------------------------------------------------------------
    // 14, 15. HOD Approved — branch mapping
    // -----------------------------------------------------------------------

    public function testHodApprovedAnalyticalAdvisoryIsGovernmentChemist(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 6, 'branch_name' => 'Analytical and Advisory']),
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('Government Chemist', $resp['responsible_role']);
        $this->assertSame('Gary Chemist', $resp['assigned_user']);
    }

    public function testHodApprovedOtherBranchIsDesignatedHod(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 1, 'branch_name' => 'Executive Branch']),
            'HOD_APPROVED',
            [],
            'Admin'
        );
        $this->assertSame('HOD', $resp['responsible_role']);
        $this->assertSame('Helen HOD', $resp['assigned_user']);
    }

    // -----------------------------------------------------------------------
    // 16, 17. Missing users / missing role holders
    // -----------------------------------------------------------------------

    public function testMissingRequestorResolvesToNullNameWithoutError(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['created_by' => 999999]),
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $requestorEntries = array_values(array_filter(
            $resp['responsible_officers'],
            fn(array $o) => str_contains($o['role'], 'Requestor')
        ));
        $this->assertNotEmpty($requestorEntries);
        $this->assertNull($requestorEntries[0]['name']);
    }

    public function testMissingBranchHeadResolvesToNullNameWithoutError(): void
    {
        $svc  = new WorkflowResponsibilityService($this->makePdo());
        $resp = $svc->getStageResponsibility(
            $this->makeRequest(['branch_id' => 999]), // no Branch Head seeded for branch 999
            'QUOTE_REVIEW_PENDING',
            [],
            'Admin'
        );

        $branchHeadEntries = array_values(array_filter(
            $resp['responsible_officers'],
            fn(array $o) => str_contains($o['role'], 'Branch Head')
        ));
        $this->assertNotEmpty($branchHeadEntries);
        $this->assertNull($branchHeadEntries[0]['name']);
    }

    // -----------------------------------------------------------------------
    // 18, 19. Tooltip rendering
    // -----------------------------------------------------------------------

    public function testTooltipRendersNotYetAssignedForUnresolvedOfficer(): void
    {
        $html = buildResponsibilityTooltip(
            'PO_PENDING',
            ['label' => 'PO Created'],
            'Pending',
            [
                'is_configured'        => true,
                'responsible_role'     => 'Procurement Officer / Director Procurement',
                'source_type'          => 'Assigned by job title',
                'action_description'   => '',
                'assigned_user'        => null,
                'completer_name'       => null,
                'completer_role'       => null,
                'responsible_officers' => [
                    ['role' => 'Procurement Officer', 'name' => null],
                    ['role' => 'Director Procurement', 'name' => 'Debra DirProc'],
                ],
            ]
        );

        $this->assertStringContainsString('Not yet assigned', $html);
        $this->assertStringContainsString('Debra DirProc', $html);
    }

    public function testTooltipListsMultipleOfficersWithoutDuplication(): void
    {
        $html = buildResponsibilityTooltip(
            'QUOTE_REVIEW_PENDING',
            ['label' => 'Quote Review'],
            'Pending',
            [
                'is_configured'        => true,
                'responsible_role'     => 'Requestor / Branch Head',
                'source_type'          => 'Assigned to',
                'action_description'   => '',
                'assigned_user'        => 'Bella BranchHead',
                'completer_name'       => null,
                'completer_role'       => null,
                'responsible_officers' => [
                    ['role' => 'Requestor / Branch Head', 'name' => 'Bella BranchHead'],
                ],
            ]
        );

        $this->assertSame(1, substr_count($html, 'Bella BranchHead'));
    }
}
