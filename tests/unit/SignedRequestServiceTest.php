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
        $this->pdo->exec("CREATE TABLE procurement_requests (
            request_id INTEGER PRIMARY KEY,
            request_type TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            status TEXT,
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
        $this->pdo->exec("INSERT INTO procurement_requests
            (request_id, request_type, created_by, status)
            VALUES (1, 'REGULAR', 10, 'SUBMITTED')");

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

    public function testUploadDocumentUsesWarningToastForPartialSignedRequestRegistrationFailure(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/procurement/upload_document.php');

        $this->assertStringContainsString('$successType = "warning";', $source);
        $this->assertStringNotContainsString('$registration[\'message\']', $source);
        $this->assertMatchesRegularExpression(
            '/pop\\(\\s*\\$successMessage,\\s*"\\/procurement\\/view\\.php\\?id="\\s*\\.\\s*\\$request_id,\\s*2500,\\s*\\$successType\\s*\\);/m',
            $source
        );
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
}
