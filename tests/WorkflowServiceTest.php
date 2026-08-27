<?php

/**
 * Test WorkflowService revert functionality
 * 
 * Verifies that the dynamic revert workflow correctly:
 * - Identifies valid backward transitions for each request type
 * - Prevents invalid reverts
 * - Handles terminal states correctly
 * - Returns appropriate stage owners
 */

require_once __DIR__ . '/../services/WorkflowService.php';

class WorkflowServiceTest
{
    private WorkflowService $service;
    private array $results = [];

    public function __construct()
    {
        // Create a mock PDO that won't be used for read-only tests
        try {
            $pdo = new PDO('sqlite::memory:');
        } catch (Exception $e) {
            // If sqlite not available, tests that need DB will be skipped
            $pdo = null;
        }
        $this->service = new WorkflowService($pdo);
    }

    public function runAllTests(): void
    {
        echo "\n=== WorkflowService Revert Tests ===\n\n";
        
        // Test 1: REGULAR workflow revert targets
        $this->testRegularWorkflowReverts();
        
        // Test 2: PETTY_CASH workflow revert targets
        $this->testPettyCashWorkflowReverts();
        
        // Test 3: REIMBURSEMENT workflow revert targets
        $this->testReimbursementWorkflowReverts();
        
        // Test 4: Terminal states cannot be reverted
        $this->testTerminalStatesCannotRevert();
        
        // Test 5: Backward transition detection
        $this->testBackwardTransitionDetection();
        
        // Test 6: Stage owner resolution
        $this->testStageOwnerResolution();
        
        // Print summary
        $this->printSummary();
    }

    private function testRegularWorkflowReverts(): void
    {
        echo "Test 1: REGULAR Workflow Reverts\n";
        echo str_repeat("-", 50) . "\n";
        
        // Test case: Finance Approval can revert to Branch Head Approval
        $targets = $this->service->getValidRevertTargets('REGULAR', 'DIRECTOR_APPROVED');
        $this->assertContains('HOD_APPROVED', $targets, 'DIRECTOR_APPROVED should allow revert to HOD_APPROVED');
        $this->assertContains('SUBMITTED', $targets, 'DIRECTOR_APPROVED should allow revert to SUBMITTED');
        
        // Test case: Payment Processing can revert
        $targets = $this->service->getValidRevertTargets('REGULAR', 'COMMITMENT_APPROVED');
        $this->assertContains('COMMITMENTS_PENDING', $targets, 'COMMITMENT_APPROVED should allow revert to COMMITMENTS_PENDING');
        
        // Test case: RFQ stages
        $targets = $this->service->getValidRevertTargets('REGULAR', 'QUOTE_APPROVED');
        $this->assertContains('QUOTE_BRANCH_HEAD_APPROVAL_PENDING', $targets, 'QUOTE_APPROVED should allow revert to QUOTE_BRANCH_HEAD_APPROVAL_PENDING');
        $this->assertContains('RFQ_LETTER_AVAILABLE', $targets, 'QUOTE_APPROVED should allow revert to RFQ_LETTER_AVAILABLE');
        
        echo "\n";
    }

    private function testPettyCashWorkflowReverts(): void
    {
        echo "Test 2: PETTY_CASH Workflow Reverts\n";
        echo str_repeat("-", 50) . "\n";
        
        // Test case: Finance Authorized can revert to Funds Verified
        $targets = $this->service->getValidRevertTargets('PETTY_CASH', 'FINANCE_AUTHORIZED');
        $this->assertContains('FUNDS_VERIFIED', $targets, 'FINANCE_AUTHORIZED should allow revert to FUNDS_VERIFIED');
        $this->assertContains('SUBMITTED', $targets, 'FINANCE_AUTHORIZED should allow revert to SUBMITTED');
        
        // Test case: Disbursed can revert
        $targets = $this->service->getValidRevertTargets('PETTY_CASH', 'DISBURSED');
        $this->assertContains('FINANCE_AUTHORIZED', $targets, 'DISBURSED should allow revert to FINANCE_AUTHORIZED');
        
        // Test case: Pending Reconciliation can revert
        $targets = $this->service->getValidRevertTargets('PETTY_CASH', 'PENDING_RECONCILIATION');
        $this->assertContains('DISBURSED', $targets, 'PENDING_RECONCILIATION should allow revert to DISBURSED');
        
        // Test case: Procurement Verified can revert
        $targets = $this->service->getValidRevertTargets('PETTY_CASH', 'PROCUREMENT_VERIFIED');
        $this->assertContains('PENDING_RECONCILIATION', $targets, 'PROCUREMENT_VERIFIED should allow revert to PENDING_RECONCILIATION');
        
        echo "\n";
    }

    private function testReimbursementWorkflowReverts(): void
    {
        echo "Test 3: REIMBURSEMENT Workflow Reverts\n";
        echo str_repeat("-", 50) . "\n";
        
        // Test case: Invoice Verified can revert to Invoice Submitted
        $targets = $this->service->getValidRevertTargets('REIMBURSEMENT', 'INVOICE_VERIFIED');
        $this->assertContains('INVOICE_SUBMITTED', $targets, 'INVOICE_VERIFIED should allow revert to INVOICE_SUBMITTED');
        $this->assertContains('FUNDS_VERIFIED', $targets, 'INVOICE_VERIFIED should allow revert to FUNDS_VERIFIED');
        
        // Test case: Approved can revert
        $targets = $this->service->getValidRevertTargets('REIMBURSEMENT', 'APPROVED');
        $this->assertContains('INVOICE_VERIFIED', $targets, 'APPROVED should allow revert to INVOICE_VERIFIED');
        $this->assertContains('FUNDS_VERIFIED', $targets, 'APPROVED should allow revert to FUNDS_VERIFIED');
        
        // Test case: Reimbursed can revert
        $targets = $this->service->getValidRevertTargets('REIMBURSEMENT', 'REIMBURSED');
        $this->assertContains('APPROVED', $targets, 'REIMBURSED should allow revert to APPROVED');
        
        // Test case: Funds Verified can revert
        $targets = $this->service->getValidRevertTargets('REIMBURSEMENT', 'FUNDS_VERIFIED');
        $this->assertContains('SUBMITTED', $targets, 'FUNDS_VERIFIED should allow revert to SUBMITTED');
        
        echo "\n";
    }

    private function testTerminalStatesCannotRevert(): void
    {
        echo "Test 4: Terminal States Cannot Revert\n";
        echo str_repeat("-", 50) . "\n";
        
        // Test all request types
        foreach (['REGULAR', 'PETTY_CASH', 'REIMBURSEMENT'] as $type) {
            $targets = $this->service->getValidRevertTargets($type, 'COMPLETED');
            $this->assertEquals(0, count($targets), "{$type}: COMPLETED should have no revert targets");
            
            $targets = $this->service->getValidRevertTargets($type, 'DECLINED');
            $this->assertEquals(0, count($targets), "{$type}: DECLINED should have no revert targets");
            
            $targets = $this->service->getValidRevertTargets($type, 'DRAFT');
            $this->assertEquals(0, count($targets), "{$type}: DRAFT should have no revert targets");
        }
        
        echo "\n";
    }

    private function testBackwardTransitionDetection(): void
    {
        echo "Test 5: Backward Transition Detection\n";
        echo str_repeat("-", 50) . "\n";
        
        // REGULAR backwards
        $this->assertTrue(
            $this->service->isBackwardTransition('REGULAR', 'DIRECTOR_APPROVED', 'HOD_APPROVED'),
            'DIRECTOR_APPROVED -> HOD_APPROVED should be backward'
        );
        
        // PETTY_CASH backwards
        $this->assertTrue(
            $this->service->isBackwardTransition('PETTY_CASH', 'FINANCE_AUTHORIZED', 'FUNDS_VERIFIED'),
            'FINANCE_AUTHORIZED -> FUNDS_VERIFIED should be backward'
        );
        
        // REIMBURSEMENT backwards
        $this->assertTrue(
            $this->service->isBackwardTransition('REIMBURSEMENT', 'APPROVED', 'INVOICE_VERIFIED'),
            'APPROVED -> INVOICE_VERIFIED should be backward'
        );
        
        // Forward transitions should not be backward
        $this->assertFalse(
            $this->service->isBackwardTransition('REGULAR', 'HOD_APPROVED', 'DIRECTOR_APPROVED'),
            'HOD_APPROVED -> DIRECTOR_APPROVED should be forward'
        );
        
        $this->assertFalse(
            $this->service->isBackwardTransition('PETTY_CASH', 'SUBMITTED', 'FUNDS_VERIFIED'),
            'SUBMITTED -> FUNDS_VERIFIED should be forward'
        );
        
        echo "\n";
    }

    private function testStageOwnerResolution(): void
    {
        echo "Test 6: Stage Owner Resolution\n";
        echo str_repeat("-", 50) . "\n";
        
        // Test REGULAR stage owners
        $targets = $this->service->getValidRevertTargets('REGULAR', 'DIRECTOR_APPROVED');
        foreach ($targets as $target) {
            $this->assertNotEmpty($target['stage_owners'], "Stage {$target['status']} should have owners");
            echo "✓ {$target['status']}: " . implode(', ', $target['stage_owners']) . "\n";
        }
        
        // Test PETTY_CASH stage owners
        $targets = $this->service->getValidRevertTargets('PETTY_CASH', 'FINANCE_AUTHORIZED');
        foreach ($targets as $target) {
            $this->assertNotEmpty($target['stage_owners'], "Stage {$target['status']} should have owners");
            echo "✓ {$target['status']}: " . implode(', ', $target['stage_owners']) . "\n";
        }
        
        // Test REIMBURSEMENT stage owners
        $targets = $this->service->getValidRevertTargets('REIMBURSEMENT', 'APPROVED');
        foreach ($targets as $target) {
            $this->assertNotEmpty($target['stage_owners'], "Stage {$target['status']} should have owners");
            echo "✓ {$target['status']}: " . implode(', ', $target['stage_owners']) . "\n";
        }
        
        echo "\n";
    }

    // Helper assertion methods
    private function assertContains(string $needle, array $haystack, string $message): void
    {
        $statuses = array_column($haystack, 'status');
        if (in_array($needle, $statuses)) {
            echo "✓ PASS: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'PASS'];
        } else {
            echo "✗ FAIL: {$message}\n";
            echo "  Expected: {$needle}\n";
            echo "  Got: " . implode(', ', $statuses) . "\n";
            $this->results[] = ['test' => $message, 'status' => 'FAIL'];
        }
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            echo "✓ PASS: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'PASS'];
        } else {
            echo "✗ FAIL: {$message}\n";
            echo "  Expected: {$expected}\n";
            echo "  Got: {$actual}\n";
            $this->results[] = ['test' => $message, 'status' => 'FAIL'];
        }
    }

    private function assertTrue($condition, string $message): void
    {
        if ($condition) {
            echo "✓ PASS: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'PASS'];
        } else {
            echo "✗ FAIL: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'FAIL'];
        }
    }

    private function assertFalse($condition, string $message): void
    {
        if (!$condition) {
            echo "✓ PASS: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'PASS'];
        } else {
            echo "✗ FAIL: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'FAIL'];
        }
    }

    private function assertNotEmpty($value, string $message): void
    {
        if (!empty($value)) {
            $this->results[] = ['test' => $message, 'status' => 'PASS'];
        } else {
            echo "✗ FAIL: {$message}\n";
            $this->results[] = ['test' => $message, 'status' => 'FAIL'];
        }
    }

    private function printSummary(): void
    {
        $passed = count(array_filter($this->results, fn($r) => $r['status'] === 'PASS'));
        $failed = count(array_filter($this->results, fn($r) => $r['status'] === 'FAIL'));
        $total = count($this->results);
        
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Test Summary\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total Tests: {$total}\n";
        echo "Passed: {$passed} ✓\n";
        echo "Failed: {$failed} " . ($failed > 0 ? '✗' : '') . "\n";
        echo "Success Rate: " . round(($passed / $total) * 100, 2) . "%\n";
        echo str_repeat("=", 50) . "\n\n";
    }
}

// Run tests
try {
    $test = new WorkflowServiceTest();
    $test->runAllTests();
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
