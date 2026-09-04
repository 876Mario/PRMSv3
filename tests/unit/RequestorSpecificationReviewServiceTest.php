<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';
require_once dirname(__DIR__, 2) . '/services/RequestorSpecificationReviewService.php';
require_once dirname(__DIR__, 2) . '/services/RFQQuoteApprovalService.php';

class RequestorSpecificationReviewServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedData();
        $_SESSION = ['user_id' => 10, 'role_name' => 'Requestor', 'full_name' => 'Rachel Requestor', '_granted_permissions' => []];
        unset($_SERVER['DOCUMENT_ROOT']);
        $GLOBALS['pdo'] = $this->pdo;
    }

    public function testWorkflowTransitionRenameChainStillValid(): void
    {
        $this->assertTrue(canTransition('QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING'));
        $this->assertTrue(canTransition('QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED'));
        $this->assertTrue(canTransition('QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'));
        $this->assertTrue(canTransition('QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED'));
    }

    public function testMandatoryCommentsRequiredForDoesNotMeetSpecifications(): void
    {
        $service = new RequestorSpecificationReviewService($this->pdo, 10, 'Requestor');

        $this->expectException(InvalidArgumentException::class);
        $service->submitRequestorReview(1, 'DOES_NOT_MEET_SPECIFICATIONS', 'no', 1001);
    }

    public function testOnlyOriginalRequestorMaySubmitWithoutOverride(): void
    {
        $_SESSION = ['user_id' => 20, 'role_name' => 'Procurement Officer', 'full_name' => 'Peter Procurement', '_granted_permissions' => []];
        $service = new RequestorSpecificationReviewService($this->pdo, 20, 'Procurement Officer');

        $this->expectException(RuntimeException::class);
        $service->submitRequestorReview(1, 'MEETS_SPECIFICATIONS', 'Matches the request.', 1001);
    }

    public function testOverrideUserMaySubmitWithReason(): void
    {
        $_SESSION = ['user_id' => 20, 'role_name' => 'Admin', 'full_name' => 'Ada Admin', '_granted_permissions' => ['override_requestor_review']];
        $service = new RequestorSpecificationReviewService($this->pdo, 20, 'Admin');

        $this->assertTrue($service->submitRequestorReview(1, 'MEETS_SPECIFICATIONS', 'Matches the request.', 1001, 'Requestor account unavailable'));

        $status = $this->pdo->query("SELECT requestor_spec_review_status FROM rfqs WHERE rfq_id = 1")->fetchColumn();
        $this->assertSame('APPROVED', $status);
        $requestStatus = $this->pdo->query("SELECT status FROM procurement_requests WHERE request_id = 1")->fetchColumn();
        $this->assertSame('QUOTE_BRANCH_HEAD_APPROVAL_PENDING', $requestStatus);
    }

    public function testRepeatedRequestorSubmissionIsRejectedAfterRouting(): void
    {
        $service = new RequestorSpecificationReviewService($this->pdo, 10, 'Requestor');
        $this->assertTrue($service->submitRequestorReview(1, 'MEETS_SPECIFICATIONS', 'Matches the request.', 1001));

        $this->expectException(RuntimeException::class);
        $service->submitRequestorReview(1, 'MEETS_SPECIFICATIONS', 'Duplicate submission.', 1001);
    }

    public function testRequestorReviewHistoryIsAppendOnlyByCodeInspection(): void
    {
        $serviceSource = file_get_contents(dirname(__DIR__, 2) . '/services/RequestorSpecificationReviewService.php');
        $this->assertStringNotContainsString('UPDATE rfq_requestor_reviews', $serviceSource);
        $this->assertStringNotContainsString('DELETE FROM rfq_requestor_reviews', $serviceSource);
    }

    public function testBranchHeadDecisionRequiresCommentsOnRejectAndReturn(): void
    {
        $_SESSION = ['user_id' => 30, 'role_name' => 'HOD', 'full_name' => 'Helen HOD', '_granted_permissions' => []];
        $service = new RFQQuoteApprovalService($this->pdo, 30, 'HOD');

        $this->expectException(InvalidArgumentException::class);
        $service->decideBranchHeadApproval(2, 'REJECT', 'no', 2001, true);
    }

    public function testBranchHeadApproveRequiresConfirmationCheckbox(): void
    {
        $_SESSION = ['user_id' => 30, 'role_name' => 'HOD', 'full_name' => 'Helen HOD', '_granted_permissions' => []];
        $service = new RFQQuoteApprovalService($this->pdo, 30, 'HOD');

        $this->expectException(InvalidArgumentException::class);
        $service->decideBranchHeadApproval(2, 'APPROVE', 'Approved', 2001, false);
    }

    public function testPendingBranchHeadApprovalsAreScopedToAuthorizedBranchHead(): void
    {
        $_SESSION = ['user_id' => 30, 'role_name' => 'HOD', 'full_name' => 'Helen HOD', '_granted_permissions' => []];
        $service = new RFQQuoteApprovalService($this->pdo, 30, 'HOD');

        $rows = $service->getPendingBranchHeadApprovals();

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['rfq_id']);
    }

    public function testPendingBranchHeadApprovalsAreHiddenFromUnauthorizedUser(): void
    {
        $_SESSION = ['user_id' => 20, 'role_name' => 'Procurement Officer', 'full_name' => 'Peter Procurement', '_granted_permissions' => []];
        $service = new RFQQuoteApprovalService($this->pdo, 20, 'Procurement Officer');

        $this->assertSame([], $service->getPendingBranchHeadApprovals());
    }

    public function testPendingBranchHeadApprovalsAreVisibleToOverrideUser(): void
    {
        $_SESSION = ['user_id' => 40, 'role_name' => 'Admin', 'full_name' => 'Ada Admin', '_granted_permissions' => ['override_branch_head_approval']];
        $service = new RFQQuoteApprovalService($this->pdo, 40, 'Admin');

        $rows = $service->getPendingBranchHeadApprovals();

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['rfq_id']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, display_name TEXT, email TEXT, role_id INTEGER, is_active INTEGER, branch_id INTEGER)");
        $this->pdo->exec("CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT)");
        $this->pdo->exec("CREATE TABLE procurement_requests (request_id INTEGER PRIMARY KEY, request_number TEXT, description TEXT, estimated_value REAL, created_by INTEGER, branch_id INTEGER, status TEXT)");
        $this->pdo->exec("CREATE TABLE rfqs (
            rfq_id INTEGER PRIMARY KEY,
            request_id INTEGER,
            rfq_number TEXT,
            created_by INTEGER,
            created_at TEXT,
            submission_deadline TEXT,
            requestor_spec_review_status TEXT,
            requestor_reviewer_id INTEGER,
            requestor_reviewed_at TEXT,
            requestor_review_comments TEXT,
            branch_head_approval_status TEXT,
            branch_head_approver_id INTEGER,
            branch_head_approved_at TEXT,
            branch_head_comments TEXT
        )");
        $this->pdo->exec("CREATE TABLE vendors (vendor_id INTEGER PRIMARY KEY, vendor_name TEXT, contact_person TEXT, email TEXT)");
        $this->pdo->exec("CREATE TABLE rfq_vendors (rfq_vendor_id INTEGER PRIMARY KEY, rfq_id INTEGER, vendor_id INTEGER)");
        $this->pdo->exec("CREATE TABLE rfq_quotes (
            quote_id INTEGER PRIMARY KEY,
            rfq_vendor_id INTEGER,
            quote_amount REAL,
            gct_amount REAL,
            quote_file TEXT,
            review_status TEXT,
            review_comments TEXT,
            submitted_at TEXT,
            currency TEXT,
            usd_rate REAL,
            is_selected INTEGER,
            is_deleted INTEGER DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE rfq_requestor_reviews (
            rfq_requestor_review_id INTEGER PRIMARY KEY AUTOINCREMENT,
            rfq_id INTEGER,
            requestor_id INTEGER,
            review_outcome TEXT,
            comments TEXT,
            review_date TEXT,
            created_at TEXT,
            updated_at TEXT
        )");
        $this->pdo->exec("CREATE TABLE rfq_quote_approvals (
            approval_id INTEGER PRIMARY KEY AUTOINCREMENT,
            rfq_id INTEGER,
            quote_id INTEGER,
            approval_stage TEXT,
            approver_id INTEGER,
            approver_role TEXT,
            action TEXT,
            comments TEXT,
            rejection_reason TEXT,
            requestor_notes TEXT,
            vendor_submission_details TEXT,
            created_at TEXT
        )");
        $this->pdo->exec("CREATE TABLE audit_log (
            audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
            table_name TEXT,
            record_id INTEGER,
            action TEXT,
            changed_by TEXT,
            change_date TEXT,
            notes TEXT,
            approval_stage TEXT,
            approval_action TEXT,
            approval_comments TEXT,
            requestor_review_outcome TEXT,
            specification_comparison TEXT
        )");
    }

    private function seedData(): void
    {
        $this->pdo->exec("INSERT INTO roles VALUES (1, 'Requestor'), (2, 'Procurement Officer'), (3, 'HOD'), (4, 'Admin')");
        $this->pdo->exec("INSERT INTO branches VALUES (3, 'Central Laboratory')");
        $this->pdo->exec("INSERT INTO users VALUES
            (10, 'Rachel Requestor', 'Rachel Requestor', 'rachel@example.com', 1, 1, 3),
            (20, 'Peter Procurement', 'Peter Procurement', 'proc@example.com', 2, 1, 3),
            (30, 'Helen HOD', 'Helen HOD', 'hod@example.com', 3, 1, 3),
            (40, 'Ada Admin', 'Ada Admin', 'admin@example.com', 4, 1, 3)");
        $this->pdo->exec("INSERT INTO procurement_requests VALUES
            (1, 'PR-001', 'Microscope accessories', 125000, 10, 3, 'QUOTE_REQUESTOR_REVIEW_PENDING'),
            (2, 'PR-002', 'Laboratory reagents', 150000, 10, 3, 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING')");
        $this->pdo->exec("INSERT INTO rfqs VALUES
            (1, 1, 'RFQ-001', 20, '2026-08-21 09:00:00', '2026-08-30 12:00:00', 'PENDING', NULL, NULL, NULL, 'PENDING', NULL, NULL, NULL),
            (2, 2, 'RFQ-002', 20, '2026-08-21 09:00:00', '2026-08-30 12:00:00', 'APPROVED', 10, '2026-08-21 10:00:00', 'Looks compliant', 'PENDING', NULL, NULL, NULL)");
        $this->pdo->exec("INSERT INTO vendors VALUES (1, 'Acme Scientific', 'Lana Vendor', 'vendor@example.com')");
        $this->pdo->exec("INSERT INTO rfq_vendors VALUES (100, 1, 1), (200, 2, 1)");
        $this->pdo->exec("INSERT INTO rfq_quotes VALUES
            (1001, 100, 100000, 15000, 'quote-a.pdf', 'MEETS_REQUIREMENTS', 'All criteria met', '2026-08-21 08:00:00', 'JMD', NULL, 1, 0),
            (2001, 200, 120000, 18000, 'quote-b.pdf', 'MEETS_REQUIREMENTS', 'All criteria met', '2026-08-21 08:30:00', 'JMD', NULL, 1, 0)");
    }
}
