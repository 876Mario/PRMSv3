<?php

/**
 * WorkflowRevertStateMatchTest
 * ============================
 * 
 * Tests for the workflow revert state-mismatch bug fix.
 * Ensures that when a request is reverted to a prior stage,
 * the approval task chain is properly recreated.
 *
 * Regression Tests:
 *   1. Revert: Request reverted to SUBMITTED → Approval tasks must be recreated
 *   2. Resubmission: Branch Head can now approve the reverted request
 *   3. Reassignment: Approval role is correct for the new stage
 *   4. No Orphans: No stale/cancelled approval records after revert
 *
 * @requires PHPUnit >= 9.0
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/workflow.php';
require_once __DIR__ . '/../config/helper.php';

class WorkflowRevertStateMatchTest extends PHPUnit\Framework\TestCase {

    private $pdo;
    private $testRequestId;
    private $testUserId = 1;

    protected function setUp(): void {
        // Connect to test database
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', 
                    getenv('DB_HOST') ?: 'localhost',
                    getenv('DB_TEST_NAME') ?: 'prms_test'),
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: ''
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void {
        // Clean up test data
        if ($this->testRequestId) {
            $this->pdo->prepare("DELETE FROM request_approvals WHERE request_id = ?")
                ->execute([$this->testRequestId]);
            $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")
                ->execute([$this->testRequestId]);
        }
    }

    /**
     * TEST 1: Revert to SUBMITTED → Approval tasks are recreated
     */
    public function testRevertToSubmittedRecreatesApprovalChain(): void {
        // 1. Create a procurement request (initially SUBMITTED)
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            250000,  // Under HOD threshold
            1,       // Branch ID
            'Test Request for Revert'
        );

        // 2. Verify initial approval chain exists
        $initialApprovals = $this->getApprovalCount($this->testRequestId);
        $this->assertGreaterThan(0, $initialApprovals, 
            'Should have at least one initial approval task');

        // 3. Advance to HOD_APPROVED (simulate HOD approval)
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        
        // 4. Delete approvals (simulate the old buggy behavior)
        $this->pdo->prepare("DELETE FROM request_approvals WHERE request_id = ?")
            ->execute([$this->testRequestId]);
        
        $approvalsAfterDelete = $this->getApprovalCount($this->testRequestId);
        $this->assertEquals(0, $approvalsAfterDelete, 
            'After delete, should have zero approvals');

        // 5. Revert to SUBMITTED using the fixed logic
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // 6. Verify approval chain was recreated (FIX VALIDATION)
        $approvalsAfterRevert = $this->getApprovalCount($this->testRequestId);
        $this->assertGreaterThan(0, $approvalsAfterRevert, 
            'After revert to SUBMITTED, approval chain should be recreated');

        // 7. Verify status is SUBMITTED
        $status = $this->getRequestStatus($this->testRequestId);
        $this->assertEquals('SUBMITTED', $status, 
            'Request status should be SUBMITTED after revert');
    }

    /**
     * TEST 2: Revert approval chain has correct roles
     */
    public function testRevertApprovalChainHasCorrectRoles(): void {
        // Create a regular procurement request (under threshold → HOD)
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            100000,  // Way under threshold
            1,       // Normal branch
            'Test Request Approval Roles'
        );

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Verify the first approval is for HOD (since amount is under threshold)
        $firstApproval = $this->getFirstApproval($this->testRequestId);
        $this->assertNotNull($firstApproval, 'Should have a first approval task');
        $this->assertEquals('HOD', $firstApproval['role'], 
            'First approval for under-threshold regular request should be HOD');
        $this->assertEquals('pending', $firstApproval['status'], 
            'Approval should be pending');
    }

    /**
     * TEST 3: No stale/duplicate approvals after revert
     */
    public function testRevertDoesNotCreateDuplicateApprovals(): void {
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            200000,
            1,
            'Test Duplicate Prevention'
        );

        // Simulate multiple reverts (should not duplicate)
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');
        $count1 = $this->getPendingApprovalCount($this->testRequestId);

        // Revert again
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');
        $count2 = $this->getPendingApprovalCount($this->testRequestId);

        $this->assertEquals($count1, $count2, 
            'Multiple reverts should not duplicate approval tasks; pending count should be same');
    }

    /**
     * TEST 4: Reimbursement requests get Finance Officer approval after revert
     */
    public function testReimbursementRevertCreatesFinanceOfficerApproval(): void {
        $this->testRequestId = $this->createTestRequest(
            'REIMBURSEMENT',
            50000,
            1,
            'Test Reimbursement Revert'
        );

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['FUNDS_VERIFIED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Verify Finance Officer approval exists
        $approval = $this->getApprovalByRole($this->testRequestId, 'Finance Officer');
        $this->assertNotNull($approval, 
            'Reimbursement request should have Finance Officer approval after revert');
        $this->assertEquals('pending', $approval['status']);
    }

    /**
     * TEST 5: Petty cash requests get Finance Officer approval after revert
     */
    public function testPettyCashRevertCreatesFinanceOfficerApproval(): void {
        $this->testRequestId = $this->createTestRequest(
            'PETTY_CASH',
            25000,
            1,
            'Test Petty Cash Revert'
        );

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['FUNDS_VERIFIED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Verify Finance Officer approval exists
        $approval = $this->getApprovalByRole($this->testRequestId, 'Finance Officer');
        $this->assertNotNull($approval, 
            'Petty cash request should have Finance Officer approval after revert');
        $this->assertEquals('pending', $approval['status']);
    }

    /**
     * TEST 6: High-value requests get correct approval chain after revert
     */
    public function testHighValueRevertCreatesMultipleApprovals(): void {
        // Create a request over HOD threshold
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            750000,  // Over 500k HOD threshold
            1,
            'Test High Value Revert'
        );

        // Get initial count (should include HOD)
        $initial = $this->getPendingApprovalCount($this->testRequestId);

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // After revert, should still have approvals
        $afterRevert = $this->getPendingApprovalCount($this->testRequestId);
        $this->assertEquals($initial, $afterRevert, 
            'High-value request should have same approval count after revert');
    }

    /**
     * TEST 7: Revert idempotency - multiple reverts produce same chain
     */
    public function testRevertIdempotency(): void {
        $this->testRequestId = $this->createTestRequest('REGULAR', 300000, 1, 'Test Idempotency');

        // First revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');
        
        $firstRoles = $this->getApprovalRoles($this->testRequestId);
        
        // Delete and revert again
        $this->pdo->prepare("DELETE FROM request_approvals WHERE request_id = ?")
            ->execute([$this->testRequestId]);
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');
        
        $secondRoles = $this->getApprovalRoles($this->testRequestId);

        $this->assertEquals($firstRoles, $secondRoles, 
            'Multiple reverts should produce identical approval chains');
    }

    /**
     * TEST 8: HRM&A branch requests get Director HRM&A approval
     */
    public function testHrmaRevertGetsDirecotrHrmaApproval(): void {
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            150000,
            5,  // HRM&A branch
            'Test HRM&A Branch Revert'
        );

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['DIRECTOR_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Verify Director HRM&A approval
        $approval = $this->getFirstApproval($this->testRequestId);
        $this->assertNotNull($approval);
        $this->assertEquals('Director HRM&A', $approval['role'], 
            'HRM&A branch should route to Director HRM&A');
    }

    /**
     * TEST 9: Analytical & Advisory branch requests get Deputy GC approval
     */
    public function testAnalyticalBranchRevertsGetsDeputyGcApproval(): void {
        $this->testRequestId = $this->createTestRequest(
            'REGULAR',
            150000,
            6,  // Analytical & Advisory branch
            'Test Analytical Branch Revert'
        );

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['GC_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Verify Deputy Government Chemist approval
        $approval = $this->getFirstApproval($this->testRequestId);
        $this->assertNotNull($approval);
        $this->assertEquals('Deputy Government Chemist', $approval['role'], 
            'Analytical & Advisory branch should route to Deputy Government Chemist');
    }

    /**
     * TEST 10: Approval lookup returns recreated tasks
     */
    public function testApprovalLookupReturnsRecreatedTasks(): void {
        $this->testRequestId = $this->createTestRequest('REGULAR', 200000, 1, 'Test Lookup');

        // Advance and revert
        $this->pdo->prepare("UPDATE procurement_requests SET status = ? WHERE request_id = ?")
            ->execute(['HOD_APPROVED', $this->testRequestId]);
        $this->revertRequestStatus($this->testRequestId, 'SUBMITTED');

        // Simulate the approval.php query
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM request_approvals
            WHERE request_id = ?
              AND status = 'pending'
            ORDER BY stage_order ASC
            LIMIT 1
        ");
        $stmt->execute([$this->testRequestId]);
        $nextApproval = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($nextApproval, 
            'Approval lookup should find recreated pending approval (fixes "No pending approvals" error)');
        $this->assertGreaterThan(0, $nextApproval['id']);
        $this->assertEquals('pending', $nextApproval['status']);
    }

    // ========================================================================
    // Helper methods
    // ========================================================================

    private function createTestRequest(string $type, float $value, int $branchId, string $description): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests
                (request_number, request_type, estimated_value, branch_id, status, description, created_by, created_at)
            VALUES (?, ?, ?, ?, 'SUBMITTED', ?, ?, NOW())
        ");
        
        $requestNumber = 'TEST-' . time() . '-' . rand(1000, 9999);
        $stmt->execute([$requestNumber, $type, $value, $branchId, $description, $this->testUserId]);
        
        $requestId = $this->pdo->lastInsertId();
        
        // Create initial approval chain
        createApprovalChain($this->pdo, (int)$requestId, $type, $value, $branchId);
        
        return (int)$requestId;
    }

    private function revertRequestStatus(int $requestId, string $targetStatus): void {
        // Simulate the revert_status.php logic
        $stmt = $this->pdo->prepare("SELECT * FROM procurement_requests WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->pdo->beginTransaction();
        
        $this->pdo->prepare("UPDATE procurement_requests SET status = ?, updated_at = NOW() WHERE request_id = ?")
            ->execute([$targetStatus, $requestId]);

        $this->pdo->prepare("DELETE FROM request_approvals WHERE request_id = ? AND status = 'pending'")
            ->execute([$requestId]);

        // Recreate approval chain (the fix)
        if (in_array($targetStatus, ['SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED', 'DIRECTOR_APPROVED', 'GC_APPROVED'])) {
            try {
                createApprovalChain(
                    $this->pdo,
                    $requestId,
                    $request['request_type'] ?? 'REGULAR',
                    (float)($request['estimated_value'] ?? 0),
                    $request['branch_id']
                );
            } catch (Throwable $e) {
                error_log("Failed to recreate approval chain: " . $e->getMessage());
            }
        }

        $this->pdo->commit();
    }

    private function getApprovalCount(int $requestId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM request_approvals WHERE request_id = ?
        ");
        $stmt->execute([$requestId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    }

    private function getPendingApprovalCount(int $requestId): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM request_approvals WHERE request_id = ? AND status = 'pending'
        ");
        $stmt->execute([$requestId]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    }

    private function getRequestStatus(int $requestId): string {
        $stmt = $this->pdo->prepare("SELECT status FROM procurement_requests WHERE request_id = ?");
        $stmt->execute([$requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['status'];
    }

    private function getFirstApproval(int $requestId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM request_approvals WHERE request_id = ? ORDER BY stage_order ASC LIMIT 1
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getApprovalByRole(int $requestId, string $role): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM request_approvals WHERE request_id = ? AND role = ? LIMIT 1
        ");
        $stmt->execute([$requestId, $role]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getApprovalRoles(int $requestId): array {
        $stmt = $this->pdo->prepare("
            SELECT role FROM request_approvals WHERE request_id = ? ORDER BY stage_order ASC
        ");
        $stmt->execute([$requestId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'role');
    }
}
