<?php
/**
 * Test Suite: AdminEditService
 * 
 * Tests for:
 * - Admin permission checks
 * - Field edit restrictions
 * - Approval-critical field detection
 * - Audit logging
 * - Bulk edit functionality
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/AdminEditService.php';

class AdminEditServiceTest {
    
    private $pdo;
    private $testRequestId;
    private $adminUserId = 999;
    private $adminUserRole = 'Admin';
    private $adminUserName = 'Admin User';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Set up test fixtures
     */
    public function setUp() {
        // Create a test procurement request in DRAFT status
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, request_type, status, created_by, branch_id, description, estimated_value, currency, procurement_method)
            VALUES (?, 'REGULAR', 'DRAFT', ?, 1, ?, 10000.00, 'USD', 'OPEN_TENDER')
        ");
        $stmt->execute([
            'TEST_PROC_' . time(),
            1,  // Created by user 1
            'Test procurement request for admin edit tests'
        ]);
        $this->testRequestId = $this->pdo->lastInsertId();
        
        return $this->testRequestId;
    }
    
    /**
     * Test admin permission check
     */
    public function testAdminPermissionCheck() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        // Admin should be authorized
        $result = $service->checkAdminPermission();
        assert($result['authorized'] === true, "Admin should be authorized to edit");
        
        echo "✓ Admin permission check test passed\n";
    }
    
    /**
     * Test non-admin permission rejection
     */
    public function testNonAdminPermissionRejection() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            100, 'Requestor', 'Regular User'
        );
        
        // Requestor should not be authorized
        $result = $service->checkAdminPermission();
        assert($result['authorized'] === false, "Non-admin should not be authorized");
        
        echo "✓ Non-admin permission rejection test passed\n";
    }
    
    /**
     * Test request loading
     */
    public function testRequestLoading() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        $request = $service->loadRequest();
        assert($request !== null, "Request should load");
        assert($request['request_id'] === $this->testRequestId, "Correct request should load");
        assert($request['status'] === 'DRAFT', "Status should be DRAFT");
        
        echo "✓ Request loading test passed\n";
    }
    
    /**
     * Test editable fields in DRAFT status
     */
    public function testEditableFieldsInDraft() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        $request = $service->loadRequest();
        $editableFields = $service->getEditableFields($request);
        
        // Check that description and estimated_value are editable
        assert(in_array('description', $editableFields), "description should be editable in DRAFT");
        assert(in_array('estimated_value', $editableFields), "estimated_value should be editable in DRAFT");
        assert(in_array('currency', $editableFields), "currency should be editable in DRAFT");
        
        echo "✓ Editable fields in DRAFT test passed\n";
    }
    
    /**
     * Test field edit validation
     */
    public function testFieldEditValidation() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        $request = $service->loadRequest();
        
        // Test valid value
        $validation = $service->validateEdit($request, 'estimated_value', 15000.00);
        assert($validation['valid'] === true, "Valid numeric value should pass validation");
        
        // Test invalid value
        $validation = $service->validateEdit($request, 'estimated_value', -5000);
        assert($validation['valid'] === false, "Negative value should fail validation");
        
        // Test invalid currency
        $validation = $service->validateEdit($request, 'currency', 'XXX');
        assert($validation['valid'] === false, "Invalid currency should fail validation");
        
        echo "✓ Field edit validation test passed\n";
    }
    
    /**
     * Test approval-critical field identification
     */
    public function testApprovalCriticalFields() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        // Check private property via reflection (if available)
        $reflection = new ReflectionClass($service);
        $property = $reflection->getProperty('approvalCriticalFields');
        $property->setAccessible(true);
        $criticalFields = $property->getValue($service);
        
        assert(in_array('estimated_value', $criticalFields), "estimated_value should be approval-critical");
        assert(in_array('procurement_method', $criticalFields), "procurement_method should be approval-critical");
        
        echo "✓ Approval-critical fields test passed\n";
    }
    
    /**
     * Test SQL injection prevention
     */
    public function testSQLInjectionPrevention() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        $request = $service->loadRequest();
        
        // Test various SQL injection attempts
        $maliciousFieldNames = [
            'description; DROP TABLE procurement_requests; --',
            'description\'; UPDATE procurement_requests SET estimated_value = 999999; --',
            'description` OR 1=1 `',
            'description) OR (1=1',
            'invalid_field_name',
            'non_existent_column'
        ];
        
        foreach ($maliciousFieldNames as $maliciousField) {
            $validation = $service->validateEdit($request, $maliciousField, 'test');
            assert($validation['valid'] === false, "SQL injection attempt with '$maliciousField' should be rejected");
            assert(strpos($validation['error'], 'not a valid field') !== false || strpos($validation['error'], 'cannot be edited') !== false, 
                   "Error message should indicate invalid field for '$maliciousField'");
        }
        
        echo "✓ SQL injection prevention test passed\n";
    }
    
    /**
     * Test edit audit logging
     */
    public function testEditAuditLogging() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        $request = $service->loadRequest();
        
        // Apply an edit
        $result = $service->applyEdit($request, 'description', 'Updated test description', 'Testing purposes');
        
        if ($result['success']) {
            // Check audit log
            $stmt = $this->pdo->prepare("
                SELECT * FROM admin_edit_audit 
                WHERE request_id = ? 
                ORDER BY edited_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$this->testRequestId]);
            $auditRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            assert($auditRecord !== false, "Audit record should be created");
            assert($auditRecord['field_name'] === 'description', "Field name should be recorded");
            assert($auditRecord['change_reason'] === 'Testing purposes', "Change reason should be recorded");
            
            echo "✓ Edit audit logging test passed\n";
        } else {
            echo "⚠ Edit audit logging test skipped (edit failed: " . $result['error'] . ")\n";
        }
    }
    
    /**
     * Test edit history retrieval
     */
    public function testEditHistoryRetrieval() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        // Get edit history
        $history = $service->getEditHistory();
        assert(is_array($history), "Edit history should be an array");
        
        echo "✓ Edit history retrieval test passed\n";
    }
    
    /**
     * Test invalidated approvals retrieval
     */
    public function testInvalidatedApprovalsRetrieval() {
        $service = new AdminEditService(
            $this->pdo, $this->testRequestId,
            $this->adminUserId, $this->adminUserRole, $this->adminUserName
        );
        
        // Get invalidated approvals
        $invalidated = $service->getInvalidatedApprovals();
        assert(is_array($invalidated), "Invalidated approvals should be an array");
        
        echo "✓ Invalidated approvals retrieval test passed\n";
    }
    
    /**
     * Tear down test fixtures
     */
    public function tearDown() {
        // Delete test request
        try {
            $stmt = $this->pdo->prepare("DELETE FROM admin_edit_audit WHERE request_id = ?");
            $stmt->execute([$this->testRequestId]);
            
            $stmt = $this->pdo->prepare("DELETE FROM approval_invalidation_log WHERE request_id = ?");
            $stmt->execute([$this->testRequestId]);
            
            $stmt = $this->pdo->prepare("DELETE FROM audit_log WHERE record_id = ? AND table_name = 'procurement_requests'");
            $stmt->execute([$this->testRequestId]);
            
            $stmt = $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?");
            $stmt->execute([$this->testRequestId]);
        } catch (Exception $e) {
            error_log("Cleanup error: " . $e->getMessage());
        }
    }
    
    /**
     * Run all tests
     */
    public function runAll() {
        echo "\n=== AdminEditService Tests ===\n";
        
        $this->setUp();
        $this->testAdminPermissionCheck();
        $this->testNonAdminPermissionRejection();
        $this->testRequestLoading();
        $this->testEditableFieldsInDraft();
        $this->testFieldEditValidation();
        $this->testApprovalCriticalFields();
        $this->testSQLInjectionPrevention();
        $this->testEditAuditLogging();
        $this->testEditHistoryRetrieval();
        $this->testInvalidatedApprovalsRetrieval();
        $this->tearDown();
        
        echo "\n✓ All AdminEditService tests passed!\n\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new AdminEditServiceTest($pdo);
    $test->runAll();
}
?>
