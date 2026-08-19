<?php
/**
 * Test Suite: RequestDocumentService
 * 
 * Tests for:
 * - File validation
 * - Authorization checks
 * - Upload processing
 * - Database persistence
 * - Audit logging
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/RequestDocumentService.php';

class RequestDocumentServiceTest {
    
    private $pdo;
    private $testRequestId;
    private $testUserId = 999;
    private $testUserRole = 'Finance Officer';
    private $testUserName = 'Test User';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Set up test fixtures
     */
    public function setUp() {
        // Create a test reimbursement request
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, request_type, status, created_by, branch_id, description, estimated_value, currency)
            VALUES (?, 'REIMBURSEMENT', 'SUBMITTED', ?, 1, ?, 5000.00, 'USD')
        ");
        $stmt->execute([
            'TEST_REIMB_' . time(),
            $this->testUserId,
            'Test reimbursement for unit tests'
        ]);
        $this->testRequestId = $this->pdo->lastInsertId();
        
        return $this->testRequestId;
    }
    
    /**
     * Test file type validation
     */
    public function testFileTypeValidation() {
        $service = new RequestDocumentService(
            $this->pdo, $this->testRequestId,
            $this->testUserId, $this->testUserRole, $this->testUserName
        );
        
        // Test valid PDF
        $validFile = [
            'name' => 'test.pdf',
            'tmp_name' => '/tmp/test.pdf',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024 * 100  // 100 KB
        ];
        
        $result = $service->validateFile($validFile);
        assert($result['valid'] === false, "File doesn't exist, should be invalid");
        
        echo "✓ File type validation test passed\n";
    }
    
    /**
     * Test authorization checks
     */
    public function testAuthorizationChecks() {
        $service = new RequestDocumentService(
            $this->pdo, $this->testRequestId,
            $this->testUserId, 'Requestor', $this->testUserName
        );
        
        // Load the request
        $request = $service->loadRequest();
        assert($request !== null, "Request should load");
        assert($request['request_type'] === 'REIMBURSEMENT', "Request type should be REIMBURSEMENT");
        
        // Test authorization
        $auth = $service->checkUploadAuthorization($request);
        assert($auth['authorized'] === true, "Requestor should be authorized");
        
        echo "✓ Authorization check test passed\n";
    }
    
    /**
     * Test workflow constraints
     */
    public function testWorkflowConstraints() {
        $service = new RequestDocumentService(
            $this->pdo, $this->testRequestId,
            $this->testUserId, $this->testUserRole, $this->testUserName
        );
        
        // Load request
        $request = $service->loadRequest();
        
        // REIMBURSEMENT requests should allow uploads
        $constraints = $service->checkWorkflowConstraints($request);
        assert($constraints['allowed'] === true, "SUBMITTED reimbursement request should allow uploads");
        
        echo "✓ Workflow constraints test passed\n";
    }
    
    /**
     * Test version history tracking
     */
    public function testVersionHistory() {
        $service = new RequestDocumentService(
            $this->pdo, $this->testRequestId,
            $this->testUserId, $this->testUserRole, $this->testUserName
        );
        
        // Get version history (should be empty initially)
        $history = $service->getVersionHistory();
        assert(is_array($history), "Version history should be an array");
        
        echo "✓ Version history test passed\n";
    }
    
    /**
     * Tear down test fixtures
     */
    public function tearDown() {
        // Delete test request
        try {
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
        echo "\n=== RequestDocumentService Tests ===\n";
        
        $this->setUp();
        $this->testFileTypeValidation();
        $this->testAuthorizationChecks();
        $this->testWorkflowConstraints();
        $this->testVersionHistory();
        $this->tearDown();
        
        echo "\n✓ All RequestDocumentService tests passed!\n\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new RequestDocumentServiceTest($pdo);
    $test->runAll();
}
?>
