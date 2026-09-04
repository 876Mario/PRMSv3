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
        $this->assertSame('PR001', previewRequestNumber($this->pdo));
        $this->assertSame('PR001', previewRequestNumber($this->pdo));
        $this->assertSame('PR001', generateRequestNumber($this->pdo));
        $this->assertSame('PR002', previewRequestNumber($this->pdo));
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
}
