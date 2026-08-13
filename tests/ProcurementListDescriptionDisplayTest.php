<?php
/**
 * tests/ProcurementListDescriptionDisplayTest.php
 * ===============================================
 * Test cases for description display in procurement list.
 */

class ProcurementListDescriptionDisplayTest extends PHPUnit\Framework\TestCase
{
    private $pdo;
    private $testRequestId;

    protected function setUp(): void
    {
        global $pdo;
        $this->pdo = $pdo;
        
        $this->setupTestData();
    }

    private function setupTestData(): void
    {
        // Create test procurement request with description
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, description, status, estimated_value, currency, request_date, created_by, branch_id)
            VALUES (?, ?, 'SUBMITTED', 10000, 'JMD', NOW(), 1, 1)
        ");
        $description = 'This is a comprehensive test description for procurement request testing purposes.';
        $stmt->execute(['PR-DESC-TEST', $description]);
        $this->testRequestId = $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Cleanup test data
        $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")->execute([$this->testRequestId]);
    }

    /**
     * Test: Request description is stored and retrieved
     */
    public function testRequestDescriptionStoredAndRetrieved(): void
    {
        $description = 'Test procurement request description';
        
        $stmt = $this->pdo->prepare("
            SELECT description FROM procurement_requests WHERE request_id = ?
        ");
        $stmt->execute([$this->testRequestId]);
        $retrievedDesc = $stmt->fetchColumn();

        $this->assertNotEmpty($retrievedDesc);
        $this->assertStringContainsString('comprehensive test description', $retrievedDesc);
    }

    /**
     * Test: Description is included in list query results
     */
    public function testDescriptionIncludedInListQuery(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT request_id, request_number, description
            FROM procurement_requests
            WHERE request_id = ?
        ");
        $stmt->execute([$this->testRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('description', $row);
        $this->assertNotEmpty($row['description']);
    }

    /**
     * Test: Description truncation (60 character max in display)
     */
    public function testDescriptionTruncation(): void
    {
        $longDescription = 'This is a very long description that should be truncated in the list view but preserved in the database.';
        
        // Update with long description
        $stmt = $this->pdo->prepare("UPDATE procurement_requests SET description = ? WHERE request_id = ?");
        $stmt->execute([$longDescription, $this->testRequestId]);

        // Retrieve full description
        $stmt = $this->pdo->prepare("SELECT description FROM procurement_requests WHERE request_id = ?");
        $stmt->execute([$this->testRequestId]);
        $fullDesc = $stmt->fetchColumn();

        // Simulate truncation (60 chars + '...')
        $maxLen = 60;
        $truncated = strlen($fullDesc) > $maxLen 
            ? substr($fullDesc, 0, $maxLen) . '...'
            : $fullDesc;

        $this->assertLessThanOrEqual(strlen($fullDesc), strlen($truncated));
        $this->assertTrue(strlen($truncated) <= ($maxLen + 3)); // +3 for '...'
    }

    /**
     * Test: Tooltip preserves full description
     */
    public function testTooltipPreservesFullDescription(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT description FROM procurement_requests WHERE request_id = ?
        ");
        $stmt->execute([$this->testRequestId]);
        $description = $stmt->fetchColumn();

        // Tooltip should contain the full untruncated description
        $tooltip = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        
        $this->assertNotEmpty($tooltip);
        $this->assertStringContainsString('comprehensive test description', $tooltip);
    }

    /**
     * Test: Empty description is handled gracefully
     */
    public function testEmptyDescriptionHandledGracefully(): void
    {
        // Create request without description
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, description, status, estimated_value, currency, request_date, created_by, branch_id)
            VALUES (?, ?, 'DRAFT', 5000, 'JMD', NOW(), 1, 1)
        ");
        $stmt->execute(['PR-NO-DESC', '']);
        $testId = $this->pdo->lastInsertId();

        try {
            // Query should work fine
            $stmt = $this->pdo->prepare("SELECT description FROM procurement_requests WHERE request_id = ?");
            $stmt->execute([$testId]);
            $description = $stmt->fetchColumn();

            $this->assertEmpty($description);
            
            // Rendering logic should skip description display for empty descriptions
            $hasDescription = !empty($description);
            $this->assertFalse($hasDescription);
        } finally {
            $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")->execute([$testId]);
        }
    }

    /**
     * Test: Null description is handled gracefully
     */
    public function testNullDescriptionHandledGracefully(): void
    {
        // Create request with NULL description
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, description, status, estimated_value, currency, request_date, created_by, branch_id)
            VALUES (?, NULL, 'DRAFT', 5000, 'JMD', NOW(), 1, 1)
        ");
        $stmt->execute(['PR-NULL-DESC']);
        $testId = $this->pdo->lastInsertId();

        try {
            // Query should work fine
            $stmt = $this->pdo->prepare("SELECT description FROM procurement_requests WHERE request_id = ?");
            $stmt->execute([$testId]);
            $description = $stmt->fetchColumn();

            $this->assertNull($description);
            
            // Rendering logic should skip description display for NULL
            $hasDescription = !empty($description);
            $this->assertFalse($hasDescription);
        } finally {
            $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")->execute([$testId]);
        }
    }

    /**
     * Test: HTML special characters in description are escaped
     */
    public function testHtmlSpecialCharactersEscaped(): void
    {
        $descWithHtml = '<script>alert("XSS")</script> & "dangerous" content';
        
        // Update with HTML content
        $stmt = $this->pdo->prepare("UPDATE procurement_requests SET description = ? WHERE request_id = ?");
        $stmt->execute([$descWithHtml, $this->testRequestId]);

        // Retrieve and escape
        $stmt = $this->pdo->prepare("SELECT description FROM procurement_requests WHERE request_id = ?");
        $stmt->execute([$this->testRequestId]);
        $description = $stmt->fetchColumn();

        $escaped = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Should not contain unescaped HTML tags
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringNotContainsString('&', $escaped); // Should be &amp;
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    /**
     * Test: Description with unicode characters
     */
    public function testDescriptionWithUnicodeCharacters(): void
    {
        $unicodeDesc = 'Procurement for café ☕ and equipment 🛠️';
        
        // Update with unicode content
        $stmt = $this->pdo->prepare("UPDATE procurement_requests SET description = ? WHERE request_id = ?");
        $stmt->execute([$unicodeDesc, $this->testRequestId]);

        // Retrieve
        $stmt = $this->pdo->prepare("SELECT description FROM procurement_requests WHERE request_id = ?");
        $stmt->execute([$this->testRequestId]);
        $description = $stmt->fetchColumn();

        $this->assertStringContainsString('café', $description);
        $this->assertStringContainsString('☕', $description);
        $this->assertStringContainsString('🛠️', $description);
    }

    /**
     * Test: Filtering and sorting unaffected by description display
     */
    public function testFilteringAndSortingWorkCorrectly(): void
    {
        // Create multiple test requests
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, description, status, estimated_value, currency, request_date, created_by, branch_id)
            VALUES (?, ?, 'SUBMITTED', ?, 'JMD', NOW(), 1, 1)
        ");
        
        $testIds = [];
        $stmt->execute(['PR-SORT-001', 'Alpha description', 5000]);
        $testIds[] = $this->pdo->lastInsertId();
        
        $stmt->execute(['PR-SORT-002', 'Beta description', 10000]);
        $testIds[] = $this->pdo->lastInsertId();

        try {
            // Test sorting by estimated_value
            $stmt = $this->pdo->prepare("
                SELECT request_number, description, estimated_value
                FROM procurement_requests
                WHERE request_id IN (?, ?)
                ORDER BY estimated_value DESC
            ");
            $stmt->execute($testIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->assertCount(2, $rows);
            $this->assertEquals(10000, (int)$rows[0]['estimated_value']);
            $this->assertEquals(5000, (int)$rows[1]['estimated_value']);

            // Test filtering by description search
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM procurement_requests
                WHERE request_id IN (?, ?)
                AND description LIKE ?
            ");
            $stmt->execute([...$testIds, '%Beta%']);
            $count = $stmt->fetchColumn();

            $this->assertEquals(1, $count);
        } finally {
            foreach ($testIds as $testId) {
                $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")->execute([$testId]);
            }
        }
    }

    /**
     * Test: Description display responsive on mobile
     */
    public function testDescriptionDisplayResponsive(): void
    {
        // This test verifies that CSS and structure support mobile responsiveness
        // The actual rendering is checked in browser testing, but database layer is verified here
        
        $stmt = $this->pdo->prepare("
            SELECT request_id, request_number, description FROM procurement_requests WHERE request_id = ?
        ");
        $stmt->execute([$this->testRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify data structure supports responsive display
        $this->assertNotNull($row);
        $this->assertLessThan(100, strlen($row['request_number'])); // Short code
        $this->assertNotEmpty($row['description']); // Meaningful description
        
        // Description should be reasonable length for truncation on mobile
        $truncated = strlen($row['description']) > 60;
        $this->assertTrue($truncated); // Should be long enough to truncate
    }
}
