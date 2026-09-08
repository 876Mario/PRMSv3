<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/SignedRequestService.php';

class SignedRequestServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', function () {
                return date('Y-m-d H:i:s');
            });
        }
        $this->pdo->exec("CREATE TABLE procurement_requests (
            request_id INTEGER PRIMARY KEY,
            request_type TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            status TEXT,
            branch_id INTEGER,
            estimated_value REAL DEFAULT 0,
            signed_request_document_path TEXT,
            signed_request_received_date TEXT,
            signed_by_user_id INTEGER,
            signed_request_version_count INTEGER,
            signed_request_active_since TEXT
        )");
        $this->pdo->exec("CREATE TABLE signed_request_documents (
            signed_request_document_id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL,
            request_type TEXT NOT NULL,
            document_path TEXT NOT NULL,
            file_name TEXT NOT NULL,
            original_file_name TEXT NOT NULL,
            file_type TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            version_number INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            uploaded_by_user_id INTEGER NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE request_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT,
            entity_id INTEGER,
            request_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            stage_order INTEGER NOT NULL,
            status TEXT NOT NULL,
            approved_by INTEGER,
            approved_at TEXT
        )");
        $this->pdo->exec("INSERT INTO procurement_requests
            (request_id, request_type, created_by, status, branch_id, estimated_value)
            VALUES (1, 'REGULAR', 10, 'SUBMITTED', 1, 100000)");

        $_SESSION = ['user_id' => 10, 'full_name' => 'Rachel Requestor', '_granted_permissions' => []];
    }

    public function testRegisterStoredDocumentReturnsGenericMessageOnDatabaseFailure(): void
    {
        $service = new SignedRequestService($this->pdo);
        $this->pdo->exec('DROP TABLE signed_request_documents');

        $result = $service->registerStoredDocument(
            1,
            'REGULAR',
            '/uploads/request_documents/signed-request.pdf',
            'signed-request.pdf',
            'application/pdf',
            12345,
            10
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Unable to register the signed request right now. Please try again later.',
            $result['message']
        );
    }

    public function testUploadDocumentFailsWhenSignedRequestRegistrationFails(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/procurement/upload_document.php');

        $this->assertStringContainsString('$pdo->beginTransaction();', $source);
        $this->assertStringContainsString('throw new Exception($registration[\'message\'] ?? \'The signed request could not be registered.\');', $source);
        $this->assertStringNotContainsString('$successType = "warning";', $source);
    }

    public function testUploadSignedRequestReturnsGenericMessageOnFailure(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/services/SignedRequestService.php');

        $this->assertStringContainsString(
            "'Unable to save the signed request right now. Please try again later.'",
            $source
        );
        $this->assertStringNotContainsString("htmlspecialchars(\$e->getMessage())", $source);
    }

    public function testRegisterStoredDocumentRepairsMissingPendingApprovalChainForSubmittedRequest(): void
    {
        $service = new SignedRequestService($this->pdo);

        $result = $service->registerStoredDocument(
            1,
            'REGULAR',
            '/uploads/request_documents/signed-request.pdf',
            'signed-request.pdf',
            'application/pdf',
            12345,
            10
        );

        $this->assertTrue($result['success']);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM request_approvals WHERE request_id = 1 AND status = 'pending'");
        $this->assertSame(2, (int)$stmt->fetchColumn());
    }
}
