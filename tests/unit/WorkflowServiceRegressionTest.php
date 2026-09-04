<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/workflow.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowService.php';

final class WorkflowServiceRegressionTest extends PHPUnit\Framework\TestCase
{
    public function testWorkflowConfigAllowsReturnedForCorrectionForReimbursementAndPettyCash(): void
    {
        $this->assertTrue(canReimbursementTransition('SUBMITTED', 'RETURNED_FOR_CORRECTION'));
        $this->assertTrue(canPettyCashTransition('FUNDS_VERIFIED', 'RETURNED_FOR_CORRECTION'));
    }

    public function testAwardedRevertTargetsNowIncludeControlledRecoveryStages(): void
    {
        $service = new WorkflowService(null);
        $targets = array_column($service->getValidRevertTargets('REGULAR', 'AWARDED'), 'status');

        $this->assertContains('GC_APPROVED', $targets);
        $this->assertContains('COMMITTEE_RECOMMENDED', $targets);
        $this->assertContains('PROCUREMENT_STAGE', $targets);
    }

    public function testReimbursementRevertToFundsVerifiedDoesNotRecreateApprovalChain(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE procurement_requests (request_id INTEGER PRIMARY KEY, request_type TEXT, estimated_value REAL, branch_id INTEGER, status TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE request_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER,
            role TEXT,
            approved_by INTEGER,
            status TEXT,
            approved_at TEXT,
            entity_type TEXT,
            entity_id INTEGER,
            stage_order INTEGER,
            rejection_reason TEXT,
            comments TEXT,
            created_at TEXT,
            notes TEXT
        )");

        $pdo->exec("INSERT INTO procurement_requests (request_id, request_type, estimated_value, branch_id, status) VALUES (1, 'REIMBURSEMENT', 100, 1, 'APPROVED')");
        $pdo->exec("INSERT INTO request_approvals (request_id, role, status, entity_type, entity_id, stage_order) VALUES (1, 'Finance Officer', 'pending', 'REQUEST', 1, 1)");

        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

        $service = new WorkflowService($pdo);
        $this->assertTrue($service->executeRevert(1, 'REIMBURSEMENT', 'APPROVED', 'FUNDS_VERIFIED', 'Need invoice review', 99, 'Admin', 'Test Admin'));

        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM request_approvals WHERE request_id = 1 AND status = 'pending'")->fetchColumn();
        $status = $pdo->query("SELECT status FROM procurement_requests WHERE request_id = 1")->fetchColumn();

        $this->assertSame('FUNDS_VERIFIED', $status);
        $this->assertSame(0, $pendingCount);
    }
}
