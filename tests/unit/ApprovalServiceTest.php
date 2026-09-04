<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/ApprovalService.php';

final class ApprovalServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE request_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT,
                entity_id INTEGER,
                role TEXT,
                stage_order INTEGER,
                status TEXT,
                approved_by INTEGER NULL,
                approved_at TEXT NULL,
                rejection_reason TEXT NULL
            )
        ");
    }

    public function testApproveUsesEarliestPendingStageOnly(): void
    {
        $this->pdo->exec("
            INSERT INTO request_approvals (entity_type, entity_id, role, stage_order, status)
            VALUES
                ('PO', 99, 'Finance Officer', 1, 'pending'),
                ('PO', 99, 'Procurement Officer', 2, 'pending')
        ");

        $service = new ApprovalService($this->pdo, 10, 'Procurement Officer');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('out of sequence');
        $service->approve('PO', 99);
    }

    public function testRejectUsesEarliestPendingStageOnly(): void
    {
        $this->pdo->exec("
            INSERT INTO request_approvals (entity_type, entity_id, role, stage_order, status)
            VALUES
                ('COMMITMENT', 55, 'Finance Officer', 1, 'pending'),
                ('COMMITMENT', 55, 'Finance Officer', 2, 'pending')
        ");

        $service = new ApprovalService($this->pdo, 11, 'Finance Officer');
        $service->reject('COMMITMENT', 55, 'Needs correction');

        $rows = $this->pdo->query("
            SELECT stage_order, status, rejection_reason, approved_by
            FROM request_approvals
            WHERE entity_type = 'COMMITMENT' AND entity_id = 55
            ORDER BY stage_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('rejected', $rows[0]['status']);
        $this->assertSame('Needs correction', $rows[0]['rejection_reason']);
        $this->assertSame('11', (string) $rows[0]['approved_by']);
        $this->assertSame('pending', $rows[1]['status']);
    }
}
