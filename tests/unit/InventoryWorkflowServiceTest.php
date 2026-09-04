<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/InventoryService.php';

final class InventoryWorkflowServiceTest extends PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE inv_items (
            item_id INTEGER PRIMARY KEY,
            item_code TEXT,
            item_name TEXT,
            reorder_level REAL DEFAULT 0,
            average_cost REAL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE inv_locations (
            location_id INTEGER PRIMARY KEY,
            location_code TEXT,
            site_name TEXT
        )");
        $this->pdo->exec("CREATE TABLE inv_stock (
            stock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            location_id INTEGER NOT NULL,
            quantity_on_hand REAL NOT NULL,
            quantity_reserved REAL NOT NULL DEFAULT 0,
            quantity_available REAL GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) VIRTUAL,
            unit_cost REAL DEFAULT 0,
            batch_lot_number TEXT,
            serial_number TEXT,
            expiry_date TEXT,
            received_date TEXT,
            stock_status TEXT NOT NULL DEFAULT 'USABLE'
        )");
        $this->pdo->exec("CREATE TABLE inv_transactions (
            transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_type TEXT,
            item_id INTEGER,
            stock_id INTEGER,
            location_id INTEGER,
            quantity REAL,
            unit_cost REAL,
            total_cost REAL,
            balance_after REAL,
            reference_type TEXT,
            reference_id INTEGER,
            reference_number TEXT,
            batch_lot_number TEXT,
            serial_number TEXT,
            expiry_date TEXT,
            performed_by INTEGER,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE inv_stock_reservations (
            reservation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            location_id INTEGER NOT NULL,
            reference_type TEXT NOT NULL,
            reference_id INTEGER NOT NULL,
            reference_line_id INTEGER,
            quantity_reserved REAL NOT NULL,
            quantity_consumed REAL NOT NULL DEFAULT 0,
            quantity_released REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'RESERVED',
            notes TEXT,
            created_by INTEGER,
            approved_by INTEGER,
            consumed_by INTEGER,
            released_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE inv_requisitions (
            requisition_id INTEGER PRIMARY KEY,
            status TEXT
        )");
        $this->pdo->exec("CREATE TABLE inv_requisition_items (
            req_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            requisition_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            quantity_approved REAL NOT NULL,
            quantity_issued REAL NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE inv_transfers (
            transfer_id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_number TEXT,
            transfer_type TEXT DEFAULT 'INTERNAL',
            source_location_id INTEGER,
            destination_location_id INTEGER,
            requested_by INTEGER,
            approved_by INTEGER,
            status TEXT,
            received_by INTEGER,
            received_at TEXT,
            notes TEXT,
            discrepancy_status TEXT,
            discrepancy_notes TEXT,
            discrepancy_reported_by INTEGER,
            discrepancy_reported_at TEXT,
            discrepancy_incident_id INTEGER,
            discrepancy_adjustment_id INTEGER
        )");
        $this->pdo->exec("CREATE TABLE inv_transfer_items (
            transfer_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            quantity REAL NOT NULL,
            quantity_received REAL,
            batch_lot_number TEXT,
            serial_number TEXT,
            unit_cost REAL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE inv_transfer_discrepancies (
            transfer_discrepancy_id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_id INTEGER NOT NULL,
            transfer_item_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            expected_quantity REAL NOT NULL,
            received_quantity REAL NOT NULL,
            variance_quantity REAL NOT NULL,
            discrepancy_type TEXT NOT NULL,
            incident_id INTEGER,
            adjustment_id INTEGER,
            notes TEXT,
            reported_by INTEGER,
            reported_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE inv_incidents (
            incident_id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_number TEXT,
            incident_type TEXT,
            incident_date TEXT,
            location_id INTEGER,
            description TEXT,
            status TEXT,
            reported_by INTEGER,
            total_estimated_loss REAL,
            notes TEXT
        )");
        $this->pdo->exec("CREATE TABLE inv_incident_items (
            incident_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            incident_id INTEGER,
            item_id INTEGER,
            quantity_lost REAL,
            unit_cost REAL,
            total_value REAL,
            batch_lot_number TEXT,
            serial_number TEXT,
            condition_notes TEXT
        )");
        $this->pdo->exec("CREATE TABLE inv_adjustments (
            adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            adjustment_number TEXT,
            location_id INTEGER,
            adjustment_type TEXT,
            reason_code TEXT,
            reason_detail TEXT,
            status TEXT,
            requested_by INTEGER,
            supervisor_approved_by INTEGER,
            supervisor_approved_at TEXT
        )");
        $this->pdo->exec("CREATE TABLE inv_adjustment_items (
            adjustment_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            adjustment_id INTEGER,
            item_id INTEGER,
            system_quantity REAL,
            physical_quantity REAL,
            variance_quantity REAL,
            unit_cost REAL
        )");
        $this->pdo->exec("CREATE TABLE inv_quarantine_log (
            quarantine_id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER,
            location_id INTEGER,
            quantity REAL,
            reason TEXT,
            status TEXT DEFAULT 'QUARANTINED',
            quarantined_by INTEGER,
            batch_lot_number TEXT,
            serial_number TEXT,
            release_decision TEXT,
            decision_notes TEXT,
            released_by INTEGER,
            released_at TEXT
        )");

        $this->pdo->exec("INSERT INTO inv_items (item_id, item_code, item_name, average_cost) VALUES
            (1, 'ITM-001', 'Widget', 10),
            (2, 'ITM-002', 'Cable', 5)");
        $this->pdo->exec("INSERT INTO inv_locations (location_id, location_code, site_name) VALUES
            (1, 'MAIN', 'Main Store'),
            (2, 'BR1', 'Branch Store')");

        $_SESSION = ['user_id' => 99, 'role_name' => 'Admin'];
    }

    public function testIssueReservationConsumptionAndRequisitionPartialFulfilment(): void
    {
        $this->pdo->exec("INSERT INTO inv_stock (item_id, location_id, quantity_on_hand, unit_cost, received_date) VALUES (1, 1, 10, 10, '2026-09-01')");
        $this->pdo->exec("INSERT INTO inv_requisitions (requisition_id, status) VALUES (10, 'APPROVED')");
        $this->pdo->exec("INSERT INTO inv_requisition_items (requisition_id, item_id, quantity_approved, quantity_issued) VALUES (10, 1, 5, 0)");

        $issue = ['issue_id' => 7, 'issue_number' => 'ISS00007', 'from_location_id' => 1, 'requisition_id' => 10, 'issued_to_department_id' => 2, 'issued_to_user_id' => null];
        $lineItems = [['issue_item_id' => 15, 'item_id' => 1, 'quantity_issued' => 2]];

        reserveIssueStock($this->pdo, $issue, $lineItems);
        $this->assertSame(2.0, (float) $this->pdo->query("SELECT quantity_reserved FROM inv_stock WHERE stock_id = 1")->fetchColumn());
        $this->assertSame('RESERVED', $this->pdo->query("SELECT status FROM inv_stock_reservations")->fetchColumn());

        dispatchIssueStock($this->pdo, $issue, $lineItems);
        $status = applyIssuedQuantitiesToRequisition($this->pdo, 10, $lineItems);

        $stock = $this->pdo->query("SELECT quantity_on_hand, quantity_reserved FROM inv_stock WHERE stock_id = 1")->fetch(PDO::FETCH_ASSOC);
        $ledger = $this->pdo->query("SELECT quantity_consumed, status FROM inv_stock_reservations")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('PARTIALLY_ISSUED', $status);
        $this->assertSame(8.0, (float) $stock['quantity_on_hand']);
        $this->assertSame(0.0, (float) $stock['quantity_reserved']);
        $this->assertSame(2.0, (float) $ledger['quantity_consumed']);
        $this->assertSame('CONSUMED', $ledger['status']);
    }

    public function testReservationReleaseUpdatesLedgerAndStock(): void
    {
        $this->pdo->exec("INSERT INTO inv_stock (item_id, location_id, quantity_on_hand, unit_cost, received_date) VALUES (1, 1, 6, 10, '2026-09-01')");

        reserveStockForReference($this->pdo, 1, 1, 3, 'inv_transfers', 21, 2101, 'Transfer reservation');
        releaseReservedStockForReference($this->pdo, 'inv_transfers', 21, 2101, 1, 1, 3);

        $stock = $this->pdo->query("SELECT quantity_reserved FROM inv_stock WHERE stock_id = 1")->fetchColumn();
        $ledger = $this->pdo->query("SELECT quantity_released, status FROM inv_stock_reservations")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(0.0, (float) $stock);
        $this->assertSame(3.0, (float) $ledger['quantity_released']);
        $this->assertSame('RELEASED', $ledger['status']);
    }

    public function testTransferReceiptCreatesShortageDiscrepancyAndIncident(): void
    {
        $this->pdo->exec("INSERT INTO inv_transfers (transfer_id, transfer_number, source_location_id, destination_location_id, requested_by, status) VALUES (5, 'TRF00005', 1, 2, 99, 'IN_TRANSIT')");
        $this->pdo->exec("INSERT INTO inv_transfer_items (transfer_item_id, transfer_id, item_id, quantity, unit_cost, batch_lot_number) VALUES (51, 5, 1, 5, 10, 'LOT-1')");

        $result = receiveTransferStock(
            $this->pdo,
            ['transfer_id' => 5, 'transfer_number' => 'TRF00005', 'source_location_id' => 1, 'destination_location_id' => 2, 'from_loc' => 'MAIN'],
            [['transfer_item_id' => 51, 'item_id' => 1, 'quantity' => 5, 'unit_cost' => 10, 'batch_lot_number' => 'LOT-1', 'serial_number' => null]],
            [51 => 3]
        );

        $transfer = $this->pdo->query("SELECT status, discrepancy_status, discrepancy_incident_id FROM inv_transfers WHERE transfer_id = 5")->fetch(PDO::FETCH_ASSOC);
        $destinationQty = $this->pdo->query("SELECT quantity_on_hand FROM inv_stock WHERE item_id = 1 AND location_id = 2")->fetchColumn();
        $discrepancy = $this->pdo->query("SELECT discrepancy_type, variance_quantity FROM inv_transfer_discrepancies WHERE transfer_id = 5")->fetch(PDO::FETCH_ASSOC);
        $incidentLoss = $this->pdo->query("SELECT total_estimated_loss FROM inv_incidents WHERE incident_id = 1")->fetchColumn();

        $this->assertTrue($result['has_discrepancy']);
        $this->assertSame('RECEIVED_WITH_DISCREPANCY', $transfer['status']);
        $this->assertSame('OPEN', $transfer['discrepancy_status']);
        $this->assertSame(3.0, (float) $destinationQty);
        $this->assertSame('SHORTAGE', $discrepancy['discrepancy_type']);
        $this->assertSame(-2.0, (float) $discrepancy['variance_quantity']);
        $this->assertSame(20.0, (float) $incidentLoss);
    }

    public function testTransferReceiptCreatesOverageAdjustment(): void
    {
        $this->pdo->exec("INSERT INTO inv_transfers (transfer_id, transfer_number, source_location_id, destination_location_id, requested_by, status) VALUES (6, 'TRF00006', 1, 2, 99, 'IN_TRANSIT')");
        $this->pdo->exec("INSERT INTO inv_transfer_items (transfer_item_id, transfer_id, item_id, quantity, unit_cost) VALUES (61, 6, 2, 4, 5)");

        $result = receiveTransferStock(
            $this->pdo,
            ['transfer_id' => 6, 'transfer_number' => 'TRF00006', 'source_location_id' => 1, 'destination_location_id' => 2, 'from_loc' => 'MAIN'],
            [['transfer_item_id' => 61, 'item_id' => 2, 'quantity' => 4, 'unit_cost' => 5, 'batch_lot_number' => null, 'serial_number' => null]],
            [61 => 6]
        );

        $qty = $this->pdo->query("SELECT quantity_on_hand FROM inv_stock WHERE item_id = 2 AND location_id = 2")->fetchColumn();
        $adjustment = $this->pdo->query("SELECT adjustment_type, status FROM inv_adjustments WHERE adjustment_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue($result['has_discrepancy']);
        $this->assertSame(6.0, (float) $qty);
        $this->assertSame('GAIN', $adjustment['adjustment_type']);
        $this->assertSame('APPROVED', $adjustment['status']);
    }

    public function testReturnDispatchAndDisposalCompletionSubtractStock(): void
    {
        $this->pdo->exec("INSERT INTO inv_stock (item_id, location_id, quantity_on_hand, unit_cost, received_date) VALUES (1, 1, 10, 10, '2026-09-01')");
        $this->pdo->exec("INSERT INTO inv_stock (item_id, location_id, quantity_on_hand, unit_cost, received_date) VALUES (2, 1, 8, 5, '2026-09-01')");

        dispatchReturnStock($this->pdo, ['return_id' => 3, 'return_number' => 'RET00003', 'return_type' => 'DEFECTIVE', 'from_location_id' => 1], [
            ['item_id' => 1, 'quantity' => 2, 'batch_lot_number' => null, 'serial_number' => null],
        ]);
        completeDisposalStock($this->pdo, ['disposal_id' => 4, 'disposal_method' => 'DESTRUCTION', 'location_id' => 1], [
            ['item_id' => 2, 'quantity' => 3],
        ]);

        $returnQty = $this->pdo->query("SELECT quantity_on_hand FROM inv_stock WHERE item_id = 1 AND location_id = 1")->fetchColumn();
        $disposalQty = $this->pdo->query("SELECT quantity_on_hand FROM inv_stock WHERE item_id = 2 AND location_id = 1")->fetchColumn();
        $txnTypes = $this->pdo->query("SELECT GROUP_CONCAT(transaction_type, ',') FROM inv_transactions")->fetchColumn();

        $this->assertSame(8.0, (float) $returnQty);
        $this->assertSame(5.0, (float) $disposalQty);
        $this->assertStringContainsString('RETURN_TO_SUPPLIER', (string) $txnTypes);
        $this->assertStringContainsString('DISPOSAL', (string) $txnTypes);
    }

    public function testQuarantineReleaseRestoresStockAndRecordsOutcome(): void
    {
        $this->pdo->exec("INSERT INTO inv_quarantine_log (quarantine_id, item_id, location_id, quantity, reason, status, quarantined_by, batch_lot_number)
            VALUES (9, 1, 1, 4, 'Damaged packaging', 'UNDER_INSPECTION', 99, 'LOT-Q')");

        resolveQuarantineRelease($this->pdo, 9, 'RETURN_TO_STOCK', 'Inspection cleared item for use');

        $qty = $this->pdo->query("SELECT quantity_on_hand FROM inv_stock WHERE item_id = 1 AND location_id = 1")->fetchColumn();
        $status = $this->pdo->query("SELECT status FROM inv_quarantine_log WHERE quarantine_id = 9")->fetchColumn();
        $decision = $this->pdo->query("SELECT release_decision FROM inv_quarantine_log WHERE quarantine_id = 9")->fetchColumn();
        $txnType = $this->pdo->query("SELECT transaction_type FROM inv_transactions WHERE reference_id = 9")->fetchColumn();

        $this->assertSame(4.0, (float) $qty);
        $this->assertSame('RELEASED', $status);
        $this->assertSame('RETURN_TO_STOCK', $decision);
        $this->assertSame('QUARANTINE_OUT', $txnType);
    }
}
