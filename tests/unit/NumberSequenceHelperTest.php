<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/helper.php';

final class NumberSequenceHelperTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE number_sequences (
                sequence_key TEXT PRIMARY KEY,
                next_value INTEGER NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        ");
    }

    public function testPreviewRequestNumberDoesNotAdvanceSequence(): void
    {
        $this->pdo->exec("
            CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_number TEXT
            )
        ");

        $this->assertSame('PR001', previewRequestNumber($this->pdo));
        $this->assertSame('PR001', previewRequestNumber($this->pdo));
        $this->assertSame('PR001', generateRequestNumber($this->pdo));
        $this->assertSame('PR002', previewRequestNumber($this->pdo));
    }

    public function testRequestNumberSequenceSkipsPastLegacyRequestsWhenSequenceRowIsMissing(): void
    {
        $this->pdo->exec("
            CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_number TEXT
            )
        ");
        $this->pdo->exec("
            INSERT INTO procurement_requests (request_number)
            VALUES ('PR001'), ('PR007')
        ");

        $this->assertSame('PR008', previewRequestNumber($this->pdo));
        $this->assertSame('PR008', generateRequestNumber($this->pdo));
        $this->assertSame('PR009', previewRequestNumber($this->pdo));
    }

    public function testRequestNumberSequenceRecoversFromStaleStoredSequence(): void
    {
        $this->pdo->exec("
            CREATE TABLE procurement_requests (
                request_id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_number TEXT
            )
        ");
        $this->pdo->exec("
            INSERT INTO procurement_requests (request_number)
            VALUES ('PR001'), ('PR007')
        ");
        $this->pdo->exec("
            INSERT INTO number_sequences (sequence_key, next_value, created_at, updated_at)
            VALUES ('procurement_request_number', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $this->assertSame('PR008', previewRequestNumber($this->pdo));
        $this->assertSame('PR008', generateRequestNumber($this->pdo));
        $this->assertSame('PR009', generateRequestNumber($this->pdo));
    }

    public function testServiceContractSequenceAdvancesOnlyOnGeneration(): void
    {
        $this->assertSame('SC0001', previewServiceContractNumber($this->pdo));
        $this->assertSame('SC0001', generateServiceContractNumber($this->pdo));
        $this->assertSame('SC0002', generateServiceContractNumber($this->pdo));
    }

    public function testYearlyPurchaseOrderSequenceIsScopedByYear(): void
    {
        $currentYear = date('Y');
        $this->assertSame(sprintf('PO-%s-0001', $currentYear), previewYearlyPONumber($this->pdo));
        $this->assertSame(sprintf('PO-%s-0001', $currentYear), generateYearlyPONumber($this->pdo));
        $this->assertSame(sprintf('PO-%s-0002', $currentYear), previewYearlyPONumber($this->pdo));
    }

    public function testNextSortOrderValueReturnsNextPosition(): void
    {
        $this->pdo->exec("
            CREATE TABLE job_titles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title_name TEXT,
                sort_order INTEGER
            )
        ");

        $this->assertSame(1, nextSortOrderValue($this->pdo, 'job_titles'));

        $this->pdo->exec("INSERT INTO job_titles (title_name, sort_order) VALUES ('Analyst', 2), ('Manager', 5)");

        $this->assertSame(6, nextSortOrderValue($this->pdo, 'job_titles'));
    }
}
