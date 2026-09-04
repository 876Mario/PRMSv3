<?php
/**
 * Inventory Service — core helpers for the inventory module.
 * Provides number generation, stock queries, segregation checks, and document control.
 */

if (!defined('UNIT_TESTING')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

/* ================================================================
   MIGRATION CHECK
================================================================ */

/**
 * Check whether the inventory tables have been created.
 * Returns true if the core inv_items table exists.
 */
function inventoryTablesExist(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM inv_items LIMIT 1");
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return false;
        }
        throw $e;
    }
}

/**
 * Check whether the GoJ compliance tables (migration 019c) have been created.
 * Returns true if inv_recalls (and the related compliance tables) exist.
 */
function inventoryComplianceTablesExist(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM inv_recalls LIMIT 1");
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return false;
        }
        throw $e;
    }
}

/* ================================================================
   NUMBER GENERATORS
================================================================ */

function generateInventoryNumber(PDO $pdo, string $prefix, string $table, string $column): string
{
    if (numberSequencesTableExists($pdo)) {
        $sequenceKey = 'inventory:' . $table . ':' . $column . ':' . $prefix;
        return $prefix . str_pad((string) nextSequenceValue($pdo, $sequenceKey, 1), 5, '0', STR_PAD_LEFT);
    }

    $sql = "
        SELECT $column FROM $table
        WHERE $column LIKE :prefix
        ORDER BY LENGTH($column) DESC, $column DESC
        LIMIT 1
    ";
    if (dbSupportsSelectForUpdate($pdo)) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $num = (int) preg_replace('/[^0-9]/', '', substr($last, strlen($prefix)));
        return $prefix . str_pad($num + 1, 5, '0', STR_PAD_LEFT);
    }
    return $prefix . '00001';
}

function generateItemCode(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'ITM-', 'inv_items', 'item_code');
}

function generateRequisitionNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'SRQ-', 'inv_requisitions', 'requisition_number');
}

function generateGRNNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'GRN-', 'inv_goods_received', 'grn_number');
}

function generateIssueNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'ISS-', 'inv_issues', 'issue_number');
}

function generateTransferNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'TRF-', 'inv_transfers', 'transfer_number');
}

function generateAdjustmentNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'ADJ-', 'inv_adjustments', 'adjustment_number');
}

function generateDisposalNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'DSP-', 'inv_disposals', 'disposal_number');
}

function generateCountNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'CNT-', 'inv_stock_counts', 'count_number');
}

function generateDocumentNumber(PDO $pdo, string $docType): string {
    $prefixes = [
        'REQUISITION' => 'DOC-REQ-',
        'GOODS_RECEIVED_NOTE' => 'DOC-GRN-',
        'STOCK_ISSUE_VOUCHER' => 'DOC-ISS-',
        'TRANSFER_NOTE' => 'DOC-TRF-',
        'ADJUSTMENT_NOTE' => 'DOC-ADJ-',
        'DISPOSAL_FORM' => 'DOC-DSP-',
        'STOCK_COUNT_SHEET' => 'DOC-CNT-',
    ];
    $prefix = $prefixes[$docType] ?? 'DOC-';
    return generateInventoryNumber($pdo, $prefix, 'inv_documents', 'document_number');
}

function inventorySupportsForUpdate(PDO $pdo): bool
{
    return dbSupportsSelectForUpdate($pdo);
}

function inventoryOptionalTableExists(PDO $pdo, string $tableName): bool
{
    try {
        $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1");
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false || stripos($e->getMessage(), 'no such table') !== false) {
            return false;
        }
        throw $e;
    }
}

function inventoryOptionalColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $pdo->query("SELECT {$columnName} FROM {$tableName} LIMIT 1");
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1054') !== false || strpos($e->getMessage(), '42S22') !== false || stripos($e->getMessage(), 'no such column') !== false) {
            return false;
        }
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false || stripos($e->getMessage(), 'no such table') !== false) {
            return false;
        }
        throw $e;
    }
}

function inventoryReservationLedgerExists(PDO $pdo): bool
{
    return inventoryOptionalTableExists($pdo, 'inv_stock_reservations');
}

function inventoryTransferDiscrepancyTableExists(PDO $pdo): bool
{
    return inventoryOptionalTableExists($pdo, 'inv_transfer_discrepancies');
}

/* ================================================================
   STOCK QUERIES
================================================================ */

/**
 * Get total available quantity for an item across all active locations.
 */
function getItemAvailableStock(PDO $pdo, int $itemId): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_available), 0)
        FROM inv_stock
        WHERE item_id = ? AND stock_status = 'USABLE'
    ");
    $stmt->execute([$itemId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Get stock by item and location.
 */
function getStockAtLocation(PDO $pdo, int $itemId, int $locationId): array
{
    $stmt = $pdo->prepare("
        SELECT s.*, i.item_name, i.item_code, l.location_code
        FROM inv_stock s
        JOIN inv_items i ON s.item_id = i.item_id
        JOIN inv_locations l ON s.location_id = l.location_id
        WHERE s.item_id = ? AND s.location_id = ? AND s.stock_status = 'USABLE'
        ORDER BY s.expiry_date ASC, s.received_date ASC
    ");
    $stmt->execute([$itemId, $locationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get total available quantity for an item at a specific location.
 */
function getAvailableStockAtLocation(PDO $pdo, int $itemId, int $locationId): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_available), 0)
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
    ");
    $stmt->execute([$itemId, $locationId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Check if item is below reorder level.
 */
function isItemBelowReorderLevel(PDO $pdo, int $itemId): bool
{
    $item = getInventoryItem($pdo, $itemId);
    if (!$item) return false;
    $available = getItemAvailableStock($pdo, $itemId);
    return $available <= (float) $item['reorder_level'];
}

/**
 * Get a single inventory item, including domain type names when available.
 */
function getInventoryItem(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare("
        SELECT i.*, c.category_name, u.uom_code, u.uom_name,
               cr.criticality_name, ac.acct_class_name,
               atype.type_name  AS asset_type_name,
               itype.type_name  AS inventory_type_name,
               ait.type_code    AS asset_item_type_code,
               ait.type_name    AS asset_item_type_name,
               aitg.group_code  AS asset_item_group_code,
               aitg.group_name  AS asset_item_group_name
        FROM inv_items i
        LEFT JOIN inv_categories c       ON i.category_id        = c.category_id
        LEFT JOIN inv_units_of_measure u ON i.uom_id             = u.uom_id
        LEFT JOIN inv_criticality_classes cr ON i.criticality_id = cr.criticality_id
        LEFT JOIN inv_accounting_classes ac  ON i.acct_class_id  = ac.acct_class_id
        LEFT JOIN asset_types     atype  ON i.asset_type_id      = atype.asset_type_id
        LEFT JOIN inventory_types itype  ON i.inventory_type_id  = itype.inventory_type_id
        LEFT JOIN inv_asset_item_types  ait  ON i.asset_item_type_id = ait.item_type_id
        LEFT JOIN inv_asset_item_type_groups aitg ON ait.group_id   = aitg.group_id
        WHERE i.item_id = ?
    ");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Get risk classes for an item.
 */
function getItemRiskClasses(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare("
        SELECT r.* FROM inv_risk_classes r
        JOIN inv_item_risk_classes irc ON r.risk_class_id = irc.risk_class_id
        WHERE irc.item_id = ?
        ORDER BY r.sort_order
    ");
    $stmt->execute([$itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================================================================
   STOCK MOVEMENT RECORDING
================================================================ */

/**
 * Record an immutable stock transaction.
 */
function recordStockTransaction(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare("
        INSERT INTO inv_transactions
        (transaction_type, item_id, stock_id, location_id, quantity, unit_cost, total_cost,
         balance_after, reference_type, reference_id, reference_number,
         batch_lot_number, serial_number, expiry_date, performed_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['transaction_type'],
        $data['item_id'],
        $data['stock_id'] ?? null,
        $data['location_id'] ?? null,
        $data['quantity'],
        $data['unit_cost'] ?? 0,
        $data['total_cost'] ?? 0,
        $data['balance_after'] ?? null,
        $data['reference_type'] ?? null,
        $data['reference_id'] ?? null,
        $data['reference_number'] ?? null,
        $data['batch_lot_number'] ?? null,
        $data['serial_number'] ?? null,
        $data['expiry_date'] ?? null,
        $_SESSION['user_id'] ?? null,
        $data['notes'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Increase stock at a location (receiving, adjustment gain, transfer in).
 */
function increaseStock(PDO $pdo, int $itemId, int $locationId, float $qty, array $extra = []): int
{
    // Try to find existing stock record matching batch/serial
    $batchLot = $extra['batch_lot_number'] ?? null;
    $serial = $extra['serial_number'] ?? null;
    $expiry = $extra['expiry_date'] ?? null;
    $unitCost = $extra['unit_cost'] ?? 0;

    $where = "item_id = ? AND location_id = ? AND stock_status = 'USABLE'";
    $params = [$itemId, $locationId];

    if ($batchLot) {
        $where .= " AND batch_lot_number = ?";
        $params[] = $batchLot;
    } else {
        $where .= " AND (batch_lot_number IS NULL OR batch_lot_number = '')";
    }

    if ($serial) {
        $where .= " AND serial_number = ?";
        $params[] = $serial;
    } else {
        $where .= " AND (serial_number IS NULL OR serial_number = '')";
    }

    $stmt = $pdo->prepare("SELECT stock_id, quantity_on_hand FROM inv_stock WHERE $where LIMIT 1");
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $newQty = (float) $existing['quantity_on_hand'] + $qty;
        $pdo->prepare("UPDATE inv_stock SET quantity_on_hand = ?, unit_cost = ?, expiry_date = COALESCE(?, expiry_date) WHERE stock_id = ?")
            ->execute([$newQty, $unitCost, $expiry, $existing['stock_id']]);
        return (int) $existing['stock_id'];
    }

    // Create new stock record
    $stmt = $pdo->prepare("
        INSERT INTO inv_stock (item_id, location_id, batch_lot_number, serial_number, expiry_date,
                               quantity_on_hand, unit_cost, stock_status, received_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'USABLE', CURRENT_DATE)
    ");
    $stmt->execute([$itemId, $locationId, $batchLot, $serial, $expiry, $qty, $unitCost]);
    return (int) $pdo->lastInsertId();
}

/**
 * Decrease stock at a location (issuing, adjustment loss, transfer out).
 * Uses FEFO/FIFO ordering.
 */
function decreaseStock(PDO $pdo, int $itemId, int $locationId, float $qty): array
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return [];
    }

    $sql = "
        SELECT stock_id, quantity_on_hand, quantity_reserved, quantity_available, unit_cost, batch_lot_number, serial_number, expiry_date
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE' AND quantity_available > 0
        ORDER BY expiry_date ASC, received_date ASC
    ";
    if ($pdo->inTransaction()) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $locationId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $qty;
    $consumed = [];

    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        $available = max(0, (float) ($batch['quantity_available'] ?? ((float) $batch['quantity_on_hand'] - (float) ($batch['quantity_reserved'] ?? 0))));
        if ($available <= 0) {
            continue;
        }
        $take = min($remaining, $available);
        $newQty = (float) $batch['quantity_on_hand'] - $take;

        $pdo->prepare("UPDATE inv_stock SET quantity_on_hand = ? WHERE stock_id = ?")
            ->execute([$newQty, $batch['stock_id']]);

        $consumed[] = [
            'stock_id' => $batch['stock_id'],
            'quantity' => $take,
            'unit_cost' => $batch['unit_cost'],
            'batch_lot_number' => $batch['batch_lot_number'],
            'serial_number' => $batch['serial_number'],
        ];
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Insufficient available stock for this transaction.');
    }

    return $consumed;
}

/**
 * Reserve available stock at a location for a pending downstream action.
 */
function reserveStock(PDO $pdo, int $itemId, int $locationId, float $qty): array
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return [];
    }

    $sql = "
        SELECT stock_id, quantity_reserved, quantity_available, batch_lot_number, serial_number, expiry_date
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE' AND quantity_available > 0
        ORDER BY expiry_date ASC, received_date ASC
    ";
    if ($pdo->inTransaction()) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $locationId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $qty;
    $reserved = [];

    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $available = (float) $batch['quantity_available'];
        if ($available <= 0) {
            continue;
        }

        $take = min($remaining, $available);
        $newReserved = (float) $batch['quantity_reserved'] + $take;
        $pdo->prepare("UPDATE inv_stock SET quantity_reserved = ? WHERE stock_id = ?")
            ->execute([$newReserved, $batch['stock_id']]);

        $reserved[] = [
            'stock_id' => $batch['stock_id'],
            'quantity' => $take,
            'batch_lot_number' => $batch['batch_lot_number'],
            'serial_number' => $batch['serial_number'],
            'expiry_date' => $batch['expiry_date'],
        ];
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Insufficient available stock to reserve.');
    }

    return $reserved;
}

/**
 * Release previously reserved stock back to available stock.
 */
function releaseReservedStock(PDO $pdo, int $itemId, int $locationId, float $qty): void
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return;
    }

    $sql = "
        SELECT stock_id, quantity_reserved
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE' AND quantity_reserved > 0
        ORDER BY expiry_date DESC, received_date DESC, stock_id DESC
    ";
    if ($pdo->inTransaction()) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $locationId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $qty;
    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $reservedQty = (float) $batch['quantity_reserved'];
        if ($reservedQty <= 0) {
            continue;
        }

        $release = min($remaining, $reservedQty);
        $pdo->prepare("UPDATE inv_stock SET quantity_reserved = ? WHERE stock_id = ?")
            ->execute([$reservedQty - $release, $batch['stock_id']]);
        $remaining -= $release;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Reserved stock release exceeded the reserved balance.');
    }
}

/**
 * Consume reserved stock when a reserved action is finalized.
 */
function consumeReservedStock(PDO $pdo, int $itemId, int $locationId, float $qty): array
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return [];
    }

    $sql = "
        SELECT stock_id, quantity_on_hand, quantity_reserved, unit_cost, batch_lot_number, serial_number, expiry_date
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE' AND quantity_reserved > 0
        ORDER BY expiry_date ASC, received_date ASC
    ";
    if ($pdo->inTransaction()) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $locationId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $qty;
    $consumed = [];
    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $reservedQty = min((float) $batch['quantity_reserved'], (float) $batch['quantity_on_hand']);
        if ($reservedQty <= 0) {
            continue;
        }

        $take = min($remaining, $reservedQty);
        $pdo->prepare("UPDATE inv_stock SET quantity_on_hand = ?, quantity_reserved = ? WHERE stock_id = ?")
            ->execute([
                (float) $batch['quantity_on_hand'] - $take,
                (float) $batch['quantity_reserved'] - $take,
                $batch['stock_id']
            ]);

        $consumed[] = [
            'stock_id' => $batch['stock_id'],
            'quantity' => $take,
            'unit_cost' => $batch['unit_cost'],
            'batch_lot_number' => $batch['batch_lot_number'],
            'serial_number' => $batch['serial_number'],
            'expiry_date' => $batch['expiry_date'],
        ];
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Reserved stock is insufficient for dispatch.');
    }

    return $consumed;
}

function updateReservationLedgerStatus(PDO $pdo, int $reservationId): void
{
    $stmt = $pdo->prepare("
        SELECT quantity_reserved, quantity_consumed, quantity_released
        FROM inv_stock_reservations
        WHERE reservation_id = ?
    ");
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $reserved = (float) $row['quantity_reserved'];
    $consumed = (float) $row['quantity_consumed'];
    $released = (float) $row['quantity_released'];
    $remaining = max(0, $reserved - $consumed - $released);

    if ($consumed + 0.0001 >= $reserved) {
        $status = 'CONSUMED';
    } elseif ($released + 0.0001 >= $reserved) {
        $status = 'RELEASED';
    } elseif ($consumed > 0) {
        $status = 'PARTIALLY_CONSUMED';
    } elseif ($released > 0) {
        $status = 'PARTIALLY_RELEASED';
    } elseif ($remaining > 0) {
        $status = 'RESERVED';
    } else {
        $status = 'RESOLVED';
    }

    $pdo->prepare("UPDATE inv_stock_reservations SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE reservation_id = ?")
        ->execute([$status, $reservationId]);
}

function reserveStockForReference(PDO $pdo, int $itemId, int $locationId, float $qty, string $referenceType, int $referenceId, ?int $referenceLineId = null, ?string $notes = null): array
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return [];
    }

    if (!inventoryReservationLedgerExists($pdo)) {
        return reserveStock($pdo, $itemId, $locationId, $qty);
    }

    $existing = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_reserved - quantity_consumed - quantity_released), 0)
        FROM inv_stock_reservations
        WHERE reference_type = ? AND reference_id = ? AND ((reference_line_id IS NULL AND ? IS NULL) OR reference_line_id = ?)
          AND status NOT IN ('CONSUMED', 'RELEASED', 'RESOLVED')
    ");
    $existing->execute([$referenceType, $referenceId, $referenceLineId, $referenceLineId]);
    if ((float) $existing->fetchColumn() > 0.0001) {
        throw new RuntimeException('Stock is already reserved for this workflow line.');
    }

    $sql = "
        SELECT stock_id, quantity_reserved, quantity_available, batch_lot_number, serial_number, expiry_date
        FROM inv_stock
        WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE' AND quantity_available > 0
        ORDER BY expiry_date ASC, received_date ASC, stock_id ASC
    ";
    if (inventorySupportsForUpdate($pdo)) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId, $locationId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare("
        INSERT INTO inv_stock_reservations
        (stock_id, item_id, location_id, reference_type, reference_id, reference_line_id,
         quantity_reserved, quantity_consumed, quantity_released, status, notes,
         created_by, approved_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 'RESERVED', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");

    $remaining = $qty;
    $reserved = [];

    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $available = max(0, (float) $batch['quantity_available']);
        if ($available <= 0) {
            continue;
        }

        $take = min($remaining, $available);
        $newReserved = (float) $batch['quantity_reserved'] + $take;
        $pdo->prepare("UPDATE inv_stock SET quantity_reserved = ? WHERE stock_id = ?")
            ->execute([$newReserved, $batch['stock_id']]);

        $insert->execute([
            $batch['stock_id'],
            $itemId,
            $locationId,
            $referenceType,
            $referenceId,
            $referenceLineId,
            $take,
            $notes,
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_id'] ?? null,
        ]);

        $reserved[] = [
            'reservation_id' => (int) $pdo->lastInsertId(),
            'stock_id' => (int) $batch['stock_id'],
            'quantity' => $take,
            'batch_lot_number' => $batch['batch_lot_number'],
            'serial_number' => $batch['serial_number'],
            'expiry_date' => $batch['expiry_date'],
        ];
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Insufficient available stock to reserve.');
    }

    return $reserved;
}

function releaseReservedStockForReference(PDO $pdo, string $referenceType, int $referenceId, ?int $referenceLineId, int $itemId, int $locationId, float $qty): void
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return;
    }

    if (!inventoryReservationLedgerExists($pdo)) {
        $reservedQtyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity_reserved), 0)
            FROM inv_stock
            WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
        ");
        $reservedQtyStmt->execute([$itemId, $locationId]);
        if ((float) $reservedQtyStmt->fetchColumn() > 0.0001) {
            releaseReservedStock($pdo, $itemId, $locationId, $qty);
        }
        return;
    }

    $sql = "
        SELECT reservation_id, stock_id, quantity_reserved, quantity_consumed, quantity_released
        FROM inv_stock_reservations
        WHERE reference_type = ? AND reference_id = ? AND ((reference_line_id IS NULL AND ? IS NULL) OR reference_line_id = ?)
          AND item_id = ? AND location_id = ?
          AND (quantity_reserved - quantity_consumed - quantity_released) > 0
        ORDER BY reservation_id DESC
    ";
    if (inventorySupportsForUpdate($pdo)) {
        $sql .= " FOR UPDATE";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$referenceType, $referenceId, $referenceLineId, $referenceLineId, $itemId, $locationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $reservedQtyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity_reserved), 0)
            FROM inv_stock
            WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
        ");
        $reservedQtyStmt->execute([$itemId, $locationId]);
        if ((float) $reservedQtyStmt->fetchColumn() > 0.0001) {
            releaseReservedStock($pdo, $itemId, $locationId, $qty);
        }
        return;
    }

    $remaining = $qty;
    foreach ($rows as $row) {
        if ($remaining <= 0) {
            break;
        }

        $available = max(0, (float) $row['quantity_reserved'] - (float) $row['quantity_consumed'] - (float) $row['quantity_released']);
        if ($available <= 0) {
            continue;
        }

        $release = min($remaining, $available);

        $stock = $pdo->prepare("SELECT quantity_reserved FROM inv_stock WHERE stock_id = ?" . (inventorySupportsForUpdate($pdo) ? " FOR UPDATE" : ""));
        $stock->execute([$row['stock_id']]);
        $stockReserved = (float) $stock->fetchColumn();
        $pdo->prepare("UPDATE inv_stock SET quantity_reserved = ? WHERE stock_id = ?")
            ->execute([max(0, $stockReserved - $release), $row['stock_id']]);

        $pdo->prepare("
            UPDATE inv_stock_reservations
            SET quantity_released = quantity_released + ?, released_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE reservation_id = ?
        ")->execute([$release, $_SESSION['user_id'] ?? null, $row['reservation_id']]);
        updateReservationLedgerStatus($pdo, (int) $row['reservation_id']);
        $remaining -= $release;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Reserved stock release exceeded the reserved balance for this workflow line.');
    }
}

function consumeReservedStockForReference(PDO $pdo, string $referenceType, int $referenceId, ?int $referenceLineId, int $itemId, int $locationId, float $qty): array
{
    $qty = abs($qty);
    if ($qty <= 0) {
        return [];
    }

    if (!inventoryReservationLedgerExists($pdo)) {
        $reservedQtyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity_reserved), 0)
            FROM inv_stock
            WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
        ");
        $reservedQtyStmt->execute([$itemId, $locationId]);
        if ((float) $reservedQtyStmt->fetchColumn() + 0.0001 >= $qty) {
            return consumeReservedStock($pdo, $itemId, $locationId, $qty);
        }
        return decreaseStock($pdo, $itemId, $locationId, $qty);
    }

    $sql = "
        SELECT reservation_id, stock_id, quantity_reserved, quantity_consumed, quantity_released
        FROM inv_stock_reservations
        WHERE reference_type = ? AND reference_id = ? AND ((reference_line_id IS NULL AND ? IS NULL) OR reference_line_id = ?)
          AND item_id = ? AND location_id = ?
          AND (quantity_reserved - quantity_consumed - quantity_released) > 0
        ORDER BY reservation_id ASC
    ";
    if (inventorySupportsForUpdate($pdo)) {
        $sql .= " FOR UPDATE";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$referenceType, $referenceId, $referenceLineId, $referenceLineId, $itemId, $locationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $reservedQtyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity_reserved), 0)
            FROM inv_stock
            WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
        ");
        $reservedQtyStmt->execute([$itemId, $locationId]);
        if ((float) $reservedQtyStmt->fetchColumn() + 0.0001 >= $qty) {
            return consumeReservedStock($pdo, $itemId, $locationId, $qty);
        }
        return decreaseStock($pdo, $itemId, $locationId, $qty);
    }

    $remaining = $qty;
    $consumed = [];
    foreach ($rows as $row) {
        if ($remaining <= 0) {
            break;
        }

        $available = max(0, (float) $row['quantity_reserved'] - (float) $row['quantity_consumed'] - (float) $row['quantity_released']);
        if ($available <= 0) {
            continue;
        }

        $take = min($remaining, $available);

        $stockSql = "
            SELECT quantity_on_hand, quantity_reserved, unit_cost, batch_lot_number, serial_number, expiry_date
            FROM inv_stock
            WHERE stock_id = ?
        ";
        if (inventorySupportsForUpdate($pdo)) {
            $stockSql .= " FOR UPDATE";
        }
        $stockStmt = $pdo->prepare($stockSql);
        $stockStmt->execute([$row['stock_id']]);
        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stock) {
            throw new RuntimeException('Reserved stock batch no longer exists.');
        }

        $pdo->prepare("
            UPDATE inv_stock
            SET quantity_on_hand = ?, quantity_reserved = ?
            WHERE stock_id = ?
        ")->execute([
            (float) $stock['quantity_on_hand'] - $take,
            max(0, (float) $stock['quantity_reserved'] - $take),
            $row['stock_id']
        ]);

        $pdo->prepare("
            UPDATE inv_stock_reservations
            SET quantity_consumed = quantity_consumed + ?, consumed_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE reservation_id = ?
        ")->execute([$take, $_SESSION['user_id'] ?? null, $row['reservation_id']]);
        updateReservationLedgerStatus($pdo, (int) $row['reservation_id']);

        $consumed[] = [
            'stock_id' => (int) $row['stock_id'],
            'quantity' => $take,
            'unit_cost' => (float) ($stock['unit_cost'] ?? 0),
            'batch_lot_number' => $stock['batch_lot_number'] ?? null,
            'serial_number' => $stock['serial_number'] ?? null,
            'expiry_date' => $stock['expiry_date'] ?? null,
        ];
        $remaining -= $take;
    }

    if ($remaining > 0.0001) {
        throw new RuntimeException('Reserved stock is insufficient for this workflow line.');
    }

    return $consumed;
}

function reserveIssueStock(PDO $pdo, array $issue, array $lineItems): void
{
    foreach ($lineItems as $li) {
        reserveStockForReference(
            $pdo,
            (int) $li['item_id'],
            (int) $issue['from_location_id'],
            (float) $li['quantity_issued'],
            'inv_issues',
            (int) $issue['issue_id'],
            isset($li['issue_item_id']) ? (int) $li['issue_item_id'] : null,
            'Issue stock reservation'
        );
    }
}

function dispatchIssueStock(PDO $pdo, array $issue, array $lineItems): void
{
    foreach ($lineItems as $li) {
        requireLocationNotFrozen($pdo, (int) $issue['from_location_id']);

        consumeReservedStockForReference(
            $pdo,
            'inv_issues',
            (int) $issue['issue_id'],
            isset($li['issue_item_id']) ? (int) $li['issue_item_id'] : null,
            (int) $li['item_id'],
            (int) $issue['from_location_id'],
            (float) $li['quantity_issued']
        );

        InventoryService::recordTransaction(
            $pdo,
            (int) $li['item_id'],
            (int) $issue['from_location_id'],
            'ISSUE',
            (float) $li['quantity_issued'],
            (int) $issue['issue_id'],
            'inv_issues',
            "Issued to " . ($issue['issued_to_user_id'] ? "user " . $issue['issued_to_user_id'] : "dept " . $issue['issued_to_department_id']),
            $_SESSION['user_id'] ?? null,
            $li['lot_number'] ?? null,
            $li['batch_number'] ?? null,
            $li['serial_number'] ?? null,
            null
        );
    }
}

function applyIssuedQuantitiesToRequisition(PDO $pdo, int $requisitionId, array $lineItems): string
{
    $updateReqItems = $pdo->prepare("
        UPDATE inv_requisition_items
        SET quantity_issued = quantity_issued + ?
        WHERE req_item_id = ?
    ");

    foreach ($lineItems as $li) {
        $remaining = (float) $li['quantity_issued'];
        $reqLines = $pdo->prepare("
            SELECT req_item_id, quantity_approved, quantity_issued
            FROM inv_requisition_items
            WHERE requisition_id = ? AND item_id = ?
            ORDER BY req_item_id ASC
        ");
        $reqLines->execute([$requisitionId, (int) $li['item_id']]);

        foreach ($reqLines->fetchAll(PDO::FETCH_ASSOC) as $reqLine) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding = max(0, (float) $reqLine['quantity_approved'] - (float) $reqLine['quantity_issued']);
            if ($outstanding <= 0) {
                continue;
            }

            $applyQty = min($remaining, $outstanding);
            $updateReqItems->execute([$applyQty, $reqLine['req_item_id']]);
            $remaining -= $applyQty;
        }

        if ($remaining > 0.0001) {
            throw new RuntimeException('Issued quantity exceeds the remaining approved requisition balance.');
        }
    }

    $reqStatusStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(quantity_approved), 0) AS approved_total,
            COALESCE(SUM(quantity_issued), 0) AS issued_total
        FROM inv_requisition_items
        WHERE requisition_id = ?
    ");
    $reqStatusStmt->execute([$requisitionId]);
    $reqTotals = $reqStatusStmt->fetch(PDO::FETCH_ASSOC) ?: ['approved_total' => 0, 'issued_total' => 0];

    $newReqStatus = ((float) $reqTotals['issued_total'] + 0.0001) >= (float) $reqTotals['approved_total']
        ? 'ISSUED'
        : 'PARTIALLY_ISSUED';

    $pdo->prepare("UPDATE inv_requisitions SET status = ? WHERE requisition_id = ?")
        ->execute([$newReqStatus, $requisitionId]);

    return $newReqStatus;
}

function reserveTransferStock(PDO $pdo, array $transfer, array $lineItems): void
{
    foreach ($lineItems as $li) {
        reserveStockForReference(
            $pdo,
            (int) $li['item_id'],
            (int) $transfer['source_location_id'],
            (float) $li['quantity'],
            'inv_transfers',
            (int) $transfer['transfer_id'],
            isset($li['transfer_item_id']) ? (int) $li['transfer_item_id'] : null,
            'Transfer stock reservation'
        );
    }
}

function dispatchTransferStock(PDO $pdo, array $transfer, array $lineItems): void
{
    requireOpenPeriod($pdo);
    requireLocationNotFrozen($pdo, (int) $transfer['source_location_id']);

    foreach ($lineItems as $li) {
        consumeReservedStockForReference(
            $pdo,
            'inv_transfers',
            (int) $transfer['transfer_id'],
            isset($li['transfer_item_id']) ? (int) $li['transfer_item_id'] : null,
            (int) $li['item_id'],
            (int) $transfer['source_location_id'],
            (float) $li['quantity']
        );

        InventoryService::recordTransaction(
            $pdo,
            (int) $li['item_id'],
            (int) $transfer['source_location_id'],
            'TRANSFER_OUT',
            (float) $li['quantity'],
            (int) $transfer['transfer_id'],
            'inv_transfers',
            'Transfer to ' . ($transfer['to_loc'] ?? $transfer['destination_location_id']),
            $_SESSION['user_id'] ?? null,
            $li['batch_lot_number'] ?? null,
            null,
            $li['serial_number'] ?? null,
            null
        );
    }
}

function generateTransferDiscrepancyIncident(PDO $pdo, array $transfer, array $discrepancies): int
{
    $incidentNumber = generateInventoryNumber($pdo, 'INC-', 'inv_incidents', 'incident_number');
    $totalLoss = 0.0;
    foreach ($discrepancies as $row) {
        if ($row['variance_quantity'] < 0) {
            $totalLoss += abs((float) $row['variance_quantity']) * (float) $row['unit_cost'];
        }
    }

    $description = "Transfer discrepancy for {$transfer['transfer_number']} from location {$transfer['source_location_id']} to {$transfer['destination_location_id']}.";
    $pdo->prepare("
        INSERT INTO inv_incidents
        (incident_number, incident_type, incident_date, location_id, description, status, reported_by, total_estimated_loss, notes)
        VALUES (?, 'LOSS', ?, ?, ?, 'REPORTED', ?, ?, ?)
    ")->execute([
        $incidentNumber,
        date('Y-m-d'),
        $transfer['destination_location_id'] ?? null,
        $description,
        $_SESSION['user_id'] ?? null,
        $totalLoss,
        'Auto-generated from transfer discrepancy workflow.'
    ]);
    $incidentId = (int) $pdo->lastInsertId();

    $lineInsert = $pdo->prepare("
        INSERT INTO inv_incident_items
        (incident_id, item_id, quantity_lost, unit_cost, total_value, batch_lot_number, serial_number, condition_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($discrepancies as $row) {
        if ($row['variance_quantity'] >= 0) {
            continue;
        }
        $lossQty = abs((float) $row['variance_quantity']);
        $lineInsert->execute([
            $incidentId,
            $row['item_id'],
            $lossQty,
            $row['unit_cost'],
            $lossQty * (float) $row['unit_cost'],
            $row['batch_lot_number'] ?? null,
            $row['serial_number'] ?? null,
            'Auto-generated from transfer discrepancy.'
        ]);
    }

    return $incidentId;
}

function generateTransferOverageAdjustment(PDO $pdo, array $transfer, array $discrepancies): ?int
{
    $overages = array_values(array_filter($discrepancies, static fn(array $row): bool => (float) $row['variance_quantity'] > 0));
    if (empty($overages)) {
        return null;
    }

    $adjNumber = generateInventoryNumber($pdo, 'ADJ-', 'inv_adjustments', 'adjustment_number');
    $pdo->prepare("
        INSERT INTO inv_adjustments
        (adjustment_number, location_id, adjustment_type, reason_code, reason_detail, status, requested_by, supervisor_approved_by, supervisor_approved_at)
        VALUES (?, ?, 'GAIN', 'OTHER', ?, 'APPROVED', ?, ?, CURRENT_TIMESTAMP)
    ")->execute([
        $adjNumber,
        $transfer['destination_location_id'],
        "Auto-generated transfer overage for {$transfer['transfer_number']}",
        $_SESSION['user_id'] ?? null,
        $_SESSION['user_id'] ?? null,
    ]);
    $adjustmentId = (int) $pdo->lastInsertId();

    $adjLine = $pdo->prepare("
        INSERT INTO inv_adjustment_items
        (adjustment_id, item_id, system_quantity, physical_quantity, variance_quantity, unit_cost)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($overages as $row) {
        $systemQty = InventoryService::getStockLevel($pdo, (int) $row['item_id'], (int) $transfer['destination_location_id']);
        $varianceQty = (float) $row['variance_quantity'];
        $adjLine->execute([
            $adjustmentId,
            $row['item_id'],
            max(0, $systemQty - $varianceQty),
            $systemQty,
            $varianceQty,
            $row['unit_cost'],
        ]);
        InventoryService::recordTransaction(
            $pdo,
            (int) $row['item_id'],
            (int) $transfer['destination_location_id'],
            'ADJUSTMENT_IN',
            $varianceQty,
            $adjustmentId,
            'inv_adjustments',
            "Transfer overage for {$transfer['transfer_number']}",
            $_SESSION['user_id'] ?? null,
            $row['batch_lot_number'] ?? null,
            null,
            $row['serial_number'] ?? null,
            null
        );
    }

    return $adjustmentId;
}

function receiveTransferStock(PDO $pdo, array $transfer, array $lineItems, array $receivedQuantities): array
{
    requireOpenPeriod($pdo);
    requireLocationNotFrozen($pdo, (int) $transfer['destination_location_id']);

    $discrepancies = [];
    $updateLine = $pdo->prepare("UPDATE inv_transfer_items SET quantity_received = ? WHERE transfer_item_id = ?");

    foreach ($lineItems as $li) {
        $expectedQty = (float) $li['quantity'];
        $qtyReceived = (float) ($receivedQuantities[$li['transfer_item_id']] ?? $expectedQty);
        if ($qtyReceived < 0) {
            throw new RuntimeException('Received quantity cannot be negative.');
        }

        $updateLine->execute([$qtyReceived, $li['transfer_item_id']]);

        $transferQty = min($expectedQty, $qtyReceived);
        if ($transferQty > 0) {
            InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $transfer['destination_location_id'], $transferQty, 'add');
            InventoryService::recordTransaction(
                $pdo,
                (int) $li['item_id'],
                (int) $transfer['destination_location_id'],
                'TRANSFER_IN',
                $transferQty,
                (int) $transfer['transfer_id'],
                'inv_transfers',
                'Transfer from ' . ($transfer['from_loc'] ?? $transfer['source_location_id']),
                $_SESSION['user_id'] ?? null,
                $li['batch_lot_number'] ?? null,
                null,
                $li['serial_number'] ?? null,
                null
            );
        }

        $varianceQty = $qtyReceived - $expectedQty;
        if (abs($varianceQty) > 0.0001) {
            $discrepancies[] = [
                'transfer_item_id' => (int) $li['transfer_item_id'],
                'item_id' => (int) $li['item_id'],
                'expected_quantity' => $expectedQty,
                'received_quantity' => $qtyReceived,
                'variance_quantity' => $varianceQty,
                'discrepancy_type' => $varianceQty > 0 ? 'OVERAGE' : 'SHORTAGE',
                'unit_cost' => (float) ($li['unit_cost'] ?? 0),
                'batch_lot_number' => $li['batch_lot_number'] ?? null,
                'serial_number' => $li['serial_number'] ?? null,
            ];
        }
    }

    $incidentId = null;
    $adjustmentId = null;

    if (!empty($discrepancies)) {
        if (inventoryTransferDiscrepancyTableExists($pdo)) {
            $insert = $pdo->prepare("
                INSERT INTO inv_transfer_discrepancies
                (transfer_id, transfer_item_id, item_id, expected_quantity, received_quantity, variance_quantity,
                 discrepancy_type, incident_id, adjustment_id, notes, reported_by, reported_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
        } else {
            $insert = null;
        }

        $incidentRows = array_values(array_filter($discrepancies, static fn(array $row): bool => $row['variance_quantity'] < 0));
        if (!empty($incidentRows) && inventoryOptionalTableExists($pdo, 'inv_incidents') && inventoryOptionalTableExists($pdo, 'inv_incident_items')) {
            $incidentId = generateTransferDiscrepancyIncident($pdo, $transfer, $incidentRows);
        }

        $overageRows = array_values(array_filter($discrepancies, static fn(array $row): bool => $row['variance_quantity'] > 0));
        if (!empty($overageRows) && inventoryOptionalTableExists($pdo, 'inv_adjustments') && inventoryOptionalTableExists($pdo, 'inv_adjustment_items')) {
            foreach ($overageRows as $row) {
                InventoryService::updateStockLevel($pdo, (int) $row['item_id'], (int) $transfer['destination_location_id'], (float) $row['variance_quantity'], 'add');
            }
            $adjustmentId = generateTransferOverageAdjustment($pdo, $transfer, $overageRows);
        }

        if ($insert) {
            foreach ($discrepancies as $row) {
                $insert->execute([
                    $transfer['transfer_id'],
                    $row['transfer_item_id'],
                    $row['item_id'],
                    $row['expected_quantity'],
                    $row['received_quantity'],
                    $row['variance_quantity'],
                    $row['discrepancy_type'],
                    $row['discrepancy_type'] === 'SHORTAGE' ? $incidentId : null,
                    $row['discrepancy_type'] === 'OVERAGE' ? $adjustmentId : null,
                    'Auto-generated during transfer receipt.',
                    $_SESSION['user_id'] ?? null,
                ]);
            }
        }

        if (inventoryOptionalColumnExists($pdo, 'inv_transfers', 'discrepancy_status')) {
            $pdo->prepare("
                UPDATE inv_transfers
                SET status = 'RECEIVED_WITH_DISCREPANCY',
                    received_by = ?,
                    received_at = CURRENT_TIMESTAMP,
                    discrepancy_status = 'OPEN',
                    discrepancy_notes = ?,
                    discrepancy_reported_by = ?,
                    discrepancy_reported_at = CURRENT_TIMESTAMP,
                    discrepancy_incident_id = ?,
                    discrepancy_adjustment_id = ?
                WHERE transfer_id = ?
            ")->execute([
                $_SESSION['user_id'] ?? null,
                'Transfer receipt discrepancy recorded automatically.',
                $_SESSION['user_id'] ?? null,
                $incidentId,
                $adjustmentId,
                $transfer['transfer_id'],
            ]);
        } else {
            $pdo->prepare("UPDATE inv_transfers SET status = 'RECEIVED_WITH_DISCREPANCY', received_by = ?, received_at = CURRENT_TIMESTAMP WHERE transfer_id = ?")
                ->execute([$_SESSION['user_id'] ?? null, $transfer['transfer_id']]);
        }

        return [
            'has_discrepancy' => true,
            'incident_id' => $incidentId,
            'adjustment_id' => $adjustmentId,
            'discrepancies' => $discrepancies,
        ];
    }

    $pdo->prepare("UPDATE inv_transfers SET status = 'COMPLETED', received_by = ?, received_at = CURRENT_TIMESTAMP WHERE transfer_id = ?")
        ->execute([$_SESSION['user_id'] ?? null, $transfer['transfer_id']]);

    return [
        'has_discrepancy' => false,
        'incident_id' => null,
        'adjustment_id' => null,
        'discrepancies' => [],
    ];
}

function dispatchReturnStock(PDO $pdo, array $return, array $lineItems): void
{
    if (!empty($return['from_location_id'])) {
        requireLocationNotFrozen($pdo, (int) $return['from_location_id']);
    }

    foreach ($lineItems as $li) {
        if (empty($return['from_location_id'])) {
            continue;
        }

        InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $return['from_location_id'], (float) $li['quantity'], 'subtract');
        InventoryService::recordTransaction(
            $pdo,
            (int) $li['item_id'],
            (int) $return['from_location_id'],
            'RETURN_TO_SUPPLIER',
            (float) $li['quantity'],
            (int) $return['return_id'],
            'inv_returns',
            "Return {$return['return_number']}: {$return['return_type']}",
            $_SESSION['user_id'] ?? null,
            $li['batch_lot_number'] ?? null,
            null,
            $li['serial_number'] ?? null,
            null
        );
    }
}

function createIncidentAdjustmentAndApplyLoss(PDO $pdo, array $incident, array $lineItems): ?int
{
    if (empty($lineItems) || empty($incident['location_id'])) {
        return null;
    }

    $adjNumber = generateInventoryNumber($pdo, 'ADJ-', 'inv_adjustments', 'adjustment_number');
    $pdo->prepare("
        INSERT INTO inv_adjustments
        (adjustment_number, location_id, adjustment_type, reason_code, reason_detail, status, requested_by, supervisor_approved_by, supervisor_approved_at)
        VALUES (?, ?, 'LOSS', 'OTHER', ?, 'APPROVED', ?, ?, CURRENT_TIMESTAMP)
    ")->execute([
        $adjNumber,
        $incident['location_id'],
        "Incident {$incident['incident_number']}: {$incident['incident_type']}",
        $incident['reported_by'],
        $_SESSION['user_id'] ?? null,
    ]);
    $adjustmentId = (int) $pdo->lastInsertId();

    $adjLine = $pdo->prepare("
        INSERT INTO inv_adjustment_items
        (adjustment_id, item_id, system_quantity, physical_quantity, variance_quantity, unit_cost)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($lineItems as $li) {
        $systemQty = InventoryService::getStockLevel($pdo, (int) $li['item_id'], (int) $incident['location_id']);
        if ((float) $li['quantity_lost'] > $systemQty + 0.0001) {
            throw new RuntimeException("Incident loss quantity cannot exceed current stock on hand for item {$li['item_code']}.");
        }
        $physQty = $systemQty - (float) $li['quantity_lost'];
        $variance = -(float) $li['quantity_lost'];
        $adjLine->execute([$adjustmentId, $li['item_id'], $systemQty, max(0, $physQty), $variance, $li['unit_cost']]);

        InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $incident['location_id'], (float) $li['quantity_lost'], 'subtract');
        InventoryService::recordTransaction(
            $pdo,
            (int) $li['item_id'],
            (int) $incident['location_id'],
            'ADJUSTMENT_OUT',
            (float) $li['quantity_lost'],
            $adjustmentId,
            'inv_adjustments',
            "Incident loss: {$incident['incident_number']}",
            $_SESSION['user_id'] ?? null,
            $li['batch_lot_number'] ?? null,
            null,
            $li['serial_number'] ?? null,
            null
        );
    }

    return $adjustmentId;
}

function completeDisposalStock(PDO $pdo, array $disposal, array $lineItems): void
{
    requireLocationNotFrozen($pdo, (int) $disposal['location_id']);

    foreach ($lineItems as $li) {
        InventoryService::updateStockLevel($pdo, (int) $li['item_id'], (int) $disposal['location_id'], (float) $li['quantity'], 'subtract');
        InventoryService::recordTransaction(
            $pdo,
            (int) $li['item_id'],
            (int) $disposal['location_id'],
            'DISPOSAL',
            (float) $li['quantity'],
            (int) $disposal['disposal_id'],
            'inv_disposals',
            'Disposed: ' . $disposal['disposal_method'],
            $_SESSION['user_id'] ?? null
        );
    }
}

function resolveQuarantineRelease(PDO $pdo, int $quarantineId, string $decision, ?string $notes = null): void
{
    if (!in_array($decision, ['RETURN_TO_STOCK', 'DISPOSE', 'RETURN_TO_SUPPLIER'], true)) {
        throw new RuntimeException('Invalid quarantine release decision.');
    }

    $q = $pdo->prepare("
        SELECT quarantine_id, item_id, location_id, quantity, batch_lot_number, serial_number, status
        FROM inv_quarantine_log
        WHERE quarantine_id = ? AND status IN ('QUARANTINED','UNDER_INSPECTION')
    ");
    $q->execute([$quarantineId]);
    $qr = $q->fetch(PDO::FETCH_ASSOC);
    if (!$qr) {
        throw new RuntimeException('Quarantine record not found or already resolved.');
    }

    if ($decision === 'RETURN_TO_STOCK') {
        requireOpenPeriod($pdo);
        requireLocationNotFrozen($pdo, (int) $qr['location_id']);
    }

    releaseFromQuarantine($pdo, $quarantineId, $decision, $notes);
}

/**
 * Update the average cost of an item after receiving.
 */
function updateAverageCost(PDO $pdo, int $itemId): void
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_on_hand * unit_cost), 0) AS total_value,
               COALESCE(SUM(quantity_on_hand), 0) AS total_qty
        FROM inv_stock
        WHERE item_id = ? AND stock_status = 'USABLE'
    ");
    $stmt->execute([$itemId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    $avgCost = ((float) $r['total_qty'] > 0)
        ? (float) $r['total_value'] / (float) $r['total_qty']
        : 0;

    $pdo->prepare("UPDATE inv_items SET average_cost = ? WHERE item_id = ?")
        ->execute([round($avgCost, 2), $itemId]);
}

/* ================================================================
   SEGREGATION OF DUTIES CHECKS
================================================================ */

/**
 * Check if current user has a specific inventory role.
 */
function hasInvRole(PDO $pdo, string $roleCode, ?int $locationId = null): bool
{
    // Admin/SuperAdmin bypass
    if (in_array($_SESSION['role_name'] ?? '', ['Admin', 'SuperAdmin'])) return true;

    $sql = "
        SELECT COUNT(*) FROM inv_user_roles ur
        JOIN inv_roles r ON ur.inv_role_id = r.inv_role_id
        WHERE ur.user_id = ? AND r.role_code = ? AND ur.is_active = 1
          AND (ur.effective_from IS NULL OR ur.effective_from <= CURDATE())
          AND (ur.effective_to IS NULL OR ur.effective_to >= CURDATE())
    ";
    $params = [$_SESSION['user_id'], $roleCode];

    if ($locationId) {
        $sql .= " AND (ur.location_id IS NULL OR ur.location_id = ?)";
        $params[] = $locationId;
    }

    // Also check delegations
    $delSql = "
        SELECT COUNT(*) FROM inv_delegations d
        JOIN inv_roles r ON d.inv_role_id = r.inv_role_id
        WHERE d.delegate_user_id = ? AND r.role_code = ? AND d.is_active = 1
          AND d.effective_from <= NOW() AND d.effective_to >= NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $directCount = (int) $stmt->fetchColumn();

    $stmt2 = $pdo->prepare($delSql);
    $stmt2->execute([$_SESSION['user_id'], $roleCode]);
    $delCount = (int) $stmt2->fetchColumn();

    return ($directCount + $delCount) > 0;
}

/**
 * Enforce that the current user did NOT perform a conflicting action on the same transaction.
 * Implements segregation of duties: same user cannot request+approve+receive+issue for same stock.
 */
function checkSegregation(PDO $pdo, string $referenceType, int $referenceId, string $conflictAction): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM inv_transactions
        WHERE reference_type = ? AND reference_id = ? AND performed_by = ?
          AND transaction_type = ?
    ");
    $stmt->execute([$referenceType, $referenceId, $_SESSION['user_id'], $conflictAction]);
    return (int) $stmt->fetchColumn() === 0; // true = no conflict
}

/* ================================================================
   DOCUMENT CONTROL
================================================================ */

/**
 * Create an inventory document record.
 */
function createInvDocument(PDO $pdo, string $docType, string $refTable, int $refId, ?string $notes = null): int
{
    $docNumber = generateDocumentNumber($pdo, $docType);
    $stmt = $pdo->prepare("
        INSERT INTO inv_documents (document_number, document_type, reference_table, reference_id,
                                   status, created_by, notes)
        VALUES (?, ?, ?, ?, 'DRAFT', ?, ?)
    ");
    $stmt->execute([$docNumber, $docType, $refTable, $refId, $_SESSION['user_id'] ?? null, $notes]);
    return (int) $pdo->lastInsertId();
}

/**
 * Lock a document after approval (no further edits).
 */
function lockDocument(PDO $pdo, int $documentId): void
{
    $pdo->prepare("
        UPDATE inv_documents SET is_locked = 1, status = 'APPROVED',
               approved_by = ?, approved_at = NOW()
        WHERE document_id = ? AND is_locked = 0
    ")->execute([$_SESSION['user_id'] ?? null, $documentId]);
}

/* ================================================================
   LOOKUP HELPERS
================================================================ */

function getCategories(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? "WHERE is_active = 1" : "";
    return $pdo->query("
        SELECT category_id, category_name, category_code, description, parent_category_id, is_active, sort_order
        FROM inv_categories
        $where
        ORDER BY sort_order, category_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getCriticalityClasses(PDO $pdo): array
{
    return $pdo->query("
        SELECT criticality_id, criticality_code, criticality_name, description, sort_order
        FROM inv_criticality_classes
        ORDER BY sort_order
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getRiskClasses(PDO $pdo): array
{
    return $pdo->query("
        SELECT risk_class_id, risk_code, risk_name, description, sort_order
        FROM inv_risk_classes
        ORDER BY sort_order
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getAccountingClasses(PDO $pdo): array
{
    return $pdo->query("
        SELECT acct_class_id, acct_class_code, acct_class_name, description, sort_order
        FROM inv_accounting_classes
        ORDER BY sort_order
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getUnitsOfMeasure(PDO $pdo): array
{
    return $pdo->query("
        SELECT uom_id, uom_code, uom_name, is_active
        FROM inv_units_of_measure
        WHERE is_active = 1
        ORDER BY uom_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all active asset types (from migration 024).
 * Returns an empty array if the asset_types table does not yet exist.
 */
function getAssetTypes(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        return $pdo->query("
            SELECT asset_type_id, type_code, type_name, description, is_active, sort_order
            FROM asset_types
            $where
            ORDER BY sort_order, type_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return [];
        }
        throw $e;
    }
}

/**
 * Get all active inventory types (from migration 024).
 * Returns an empty array if the inventory_types table does not yet exist.
 */
function getInventoryTypes(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        return $pdo->query("
            SELECT inventory_type_id, type_code, type_name, description, is_active, sort_order
            FROM inventory_types
            $where
            ORDER BY sort_order, type_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return [];
        }
        throw $e;
    }
}

/**
 * Get all active asset item type groups (OFFICE FURNITURE, OFFICE MACHINE, etc.)
 * Returns an empty array if the table does not yet exist.
 */
function getAssetItemTypeGroups(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        return $pdo->query(
            "SELECT group_id, group_code, group_name, description, sort_order, is_active
             FROM inv_asset_item_type_groups
             $where
             ORDER BY sort_order, group_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return [];
        }
        throw $e;
    }
}

/**
 * Get active asset item types, optionally filtered by group.
 * Returns an empty array if the table does not yet exist.
 *
 * @param PDO      $pdo
 * @param int|null $groupId  Limit to a specific group; NULL returns all.
 * @param bool     $activeOnly
 * @return array  Each row: item_type_id, group_id, type_code, type_name, sort_order, is_active
 */
function getAssetItemTypes(PDO $pdo, ?int $groupId = null, bool $activeOnly = true): array
{
    try {
        $conditions = [];
        $params     = [];
        if ($activeOnly) {
            $conditions[] = 't.is_active = 1';
        }
        if ($groupId !== null) {
            $conditions[] = 't.group_id = ?';
            $params[]     = $groupId;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt  = $pdo->prepare(
            "SELECT t.item_type_id, t.group_id, t.type_code, t.type_name, t.description, t.sort_order, t.is_active,
                    g.group_code, g.group_name
             FROM inv_asset_item_types t
             JOIN inv_asset_item_type_groups g ON t.group_id = g.group_id
             $where
             ORDER BY g.sort_order, t.sort_order, t.type_code"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1146') !== false || strpos($e->getMessage(), '42S02') !== false) {
            return [];
        }
        throw $e;
    }
}

/** Approved top-level Primary Asset Types (Ministry of Finance alignment). */
const PRIMARY_ASSET_TYPE_PPE        = 'Property, Plant, and Equipment (Non-Financial Assets)';
const PRIMARY_ASSET_TYPE_CONSUMABLE = 'Consumable and Expendable Assets';

/**
 * Derive the Primary Asset Type label(s) for an item domain.
 * ASSET  -> Property, Plant, and Equipment (Non-Financial Assets)
 * INVENTORY -> Consumable and Expendable Assets
 * BOTH -> both types apply
 */
function getPrimaryAssetTypeLabel(?string $itemDomain): string
{
    switch ($itemDomain) {
        case 'ASSET':
            return PRIMARY_ASSET_TYPE_PPE;
        case 'BOTH':
            return PRIMARY_ASSET_TYPE_PPE . ' + ' . PRIMARY_ASSET_TYPE_CONSUMABLE;
        case 'INVENTORY':
        default:
            return PRIMARY_ASSET_TYPE_CONSUMABLE;
    }
}

/**
 * Validate the Primary Asset Type / classification selection for an item.
 * - item_domain (Primary Asset Type) is mandatory and must be a known value.
 * - Classifications may only belong to the selected Primary Asset Type.
 *
 * Returns [itemDomain, assetTypeId, inventoryTypeId] with the classification
 * that does not belong to the selected Primary Asset Type cleared.
 *
 * @throws Exception when the selection is invalid
 */
function validatePrimaryAssetTypeSelection(PDO $pdo, ?string $itemDomain, $assetTypeId, $inventoryTypeId): array
{
    $itemDomain = $itemDomain !== null ? strtoupper(trim($itemDomain)) : '';
    if (!in_array($itemDomain, ['INVENTORY', 'ASSET', 'BOTH'], true)) {
        throw new Exception("Primary Asset Type is mandatory. Select Property, Plant, and Equipment (Non-Financial Assets) or Consumable and Expendable Assets.");
    }

    $assetTypeId     = $assetTypeId ? (int) $assetTypeId : null;
    $inventoryTypeId = $inventoryTypeId ? (int) $inventoryTypeId : null;

    // Classifications may only belong to the selected Primary Asset Type
    if ($itemDomain === 'INVENTORY') $assetTypeId = null;
    if ($itemDomain === 'ASSET')     $inventoryTypeId = null;

    if ($assetTypeId !== null) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM asset_types WHERE asset_type_id = ? AND is_active = 1");
        $chk->execute([$assetTypeId]);
        if ((int) $chk->fetchColumn() === 0) {
            throw new Exception("Invalid Asset Classification: only approved Property, Plant, and Equipment classifications may be selected.");
        }
    }
    if ($inventoryTypeId !== null) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM inventory_types WHERE inventory_type_id = ? AND is_active = 1");
        $chk->execute([$inventoryTypeId]);
        if ((int) $chk->fetchColumn() === 0) {
            throw new Exception("Invalid Asset Classification: only approved Consumable and Expendable classifications may be selected.");
        }
    }

    return [$itemDomain, $assetTypeId, $inventoryTypeId];
}

function getActiveLocations(PDO $pdo): array
{
    return $pdo->query("
        SELECT l.location_id, l.location_code, l.site_campus, l.building, l.floor, l.room_storage_area,
               l.bin_shelf_rack, l.security_level, l.temp_humidity_req, l.custodian_user_id,
               l.capacity, l.is_active, l.location_type, u.full_name AS custodian_name
        FROM inv_locations l
        LEFT JOIN users u ON l.custodian_user_id = u.user_id
        WHERE l.is_active = 1
        ORDER BY l.site_campus, l.building, l.floor, l.room_storage_area
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getInvRoles(PDO $pdo): array
{
    return $pdo->query("
        SELECT inv_role_id, role_code, role_name, description
        FROM inv_roles
        ORDER BY role_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inventory audit log helper.
 */
function logInventoryAudit(PDO $pdo, string $table, ?int $recordId, string $action, ?string $notes = null): void
{
    logAudit($pdo, $table, $recordId, $action, $notes);
}

/* ================================================================
   APPROVAL MATRIX LOOKUP
================================================================ */

/**
 * Get the required approval levels for a transaction based on value and type.
 * Uses the inv_approval_matrix table.
 */
function getRequiredApprovals(PDO $pdo, string $transactionType, float $totalValue, bool $isEmergency = false, ?int $departmentId = null): array
{
    $sql = "
        SELECT am.*, r.role_name, r.role_code
        FROM inv_approval_matrix am
        JOIN inv_roles r ON am.required_role_code = r.role_code
        WHERE am.transaction_type = ?
          AND am.is_active = 1
          AND am.min_value <= ?
          AND am.max_value >= ?
          AND (am.is_emergency = 0 OR am.is_emergency = ?)
    ";
    $params = [$transactionType, $totalValue, $totalValue, $isEmergency ? 1 : 0];

    if ($departmentId) {
        $sql .= " AND (am.department_id IS NULL OR am.department_id = ?)";
        $params[] = $departmentId;
    } else {
        $sql .= " AND am.department_id IS NULL";
    }

    $sql .= " ORDER BY am.approval_level ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if user can approve at a given level based on their inventory role.
 */
function canApproveAtLevel(PDO $pdo, string $requiredRoleCode, ?int $locationId = null): bool
{
    return hasInvRole($pdo, $requiredRoleCode, $locationId);
}

/**
 * Create approval log entries for a transaction.
 */
function createApprovalLog(PDO $pdo, string $refType, int $refId, array $requiredApprovals): void
{
    $stmt = $pdo->prepare("
        INSERT INTO inv_approval_log (reference_type, reference_id, approval_level, required_role_code)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($requiredApprovals as $a) {
        $stmt->execute([$refType, $refId, $a['approval_level'], $a['required_role_code']]);
    }
}

/**
 * Record an approval action in the log.
 */
function recordApproval(PDO $pdo, string $refType, int $refId, int $level, string $status, ?string $notes = null): void
{
    $pdo->prepare("
        UPDATE inv_approval_log
        SET approved_by = ?, approved_at = NOW(), status = ?, notes = ?
        WHERE reference_type = ? AND reference_id = ? AND approval_level = ? AND status = 'PENDING'
        LIMIT 1
    ")->execute([$_SESSION['user_id'], $status, $notes, $refType, $refId, $level]);
}

/**
 * Get next pending approval level for a transaction.
 */
function getNextPendingApproval(PDO $pdo, string $refType, int $refId): ?array
{
    $stmt = $pdo->prepare("
        SELECT approval_log_id, reference_type, reference_id, approval_level, required_role_code,
               approved_by, approved_at, status, notes, created_at
        FROM inv_approval_log
        WHERE reference_type = ? AND reference_id = ? AND status = 'PENDING'
        ORDER BY approval_level ASC LIMIT 1
    ");
    $stmt->execute([$refType, $refId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Check if all approval levels are complete for a transaction.
 */
function allApprovalsComplete(PDO $pdo, string $refType, int $refId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM inv_approval_log
        WHERE reference_type = ? AND reference_id = ? AND status = 'PENDING'
    ");
    $stmt->execute([$refType, $refId]);
    return (int) $stmt->fetchColumn() === 0;
}

/* ================================================================
   PERIOD CONTROLS
================================================================ */

/**
 * Check if the current date falls within an open fiscal period.
 * Returns the open period or null if no period is open.
 */
function getCurrentOpenPeriod(PDO $pdo): ?array
{
    $stmt = $pdo->query("
        SELECT period_id, period_name, fiscal_year, period_start, period_end, status, closed_by, closed_at, notes, created_at
        FROM inv_fiscal_periods
        WHERE status = 'OPEN' AND period_start <= CURDATE() AND period_end >= CURDATE()
        ORDER BY period_start DESC LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Enforce that a transaction can only occur in an open period.
 * Throws an exception if no open period exists.
 */
function requireOpenPeriod(PDO $pdo): array
{
    try {
        $pdo->query("SELECT 1 FROM inv_fiscal_periods LIMIT 1");
    } catch (PDOException $e) {
        // Table doesn't exist yet — migration not run; allow transactions
        return ['period_id' => 0, 'status' => 'OPEN'];
    }

    $period = getCurrentOpenPeriod($pdo);
    if (!$period) {
        // Check if any period exists at all; if not, don't enforce
        $count = (int) $pdo->query("SELECT COUNT(*) FROM inv_fiscal_periods")->fetchColumn();
        if ($count === 0) {
            return ['period_id' => 0, 'status' => 'OPEN'];
        }
        throw new Exception("No open fiscal period. Contact Finance to open the current period before recording transactions.");
    }
    return $period;
}

/**
 * Create a period-end snapshot of all stock values.
 */
function createPeriodSnapshot(PDO $pdo, int $periodId): int
{
    $stmt = $pdo->prepare("
        INSERT INTO inv_period_snapshots (period_id, item_id, location_id, quantity_on_hand, unit_cost, total_value, nrv)
        SELECT ?, s.item_id, s.location_id, s.quantity_on_hand, s.unit_cost,
               (s.quantity_on_hand * s.unit_cost), s.nrv
        FROM inv_stock s
        WHERE s.quantity_on_hand > 0
    ");
    $stmt->execute([$periodId]);
    return $stmt->rowCount();
}

/* ================================================================
   QUARANTINE MANAGEMENT
================================================================ */

/**
 * Move stock into quarantine.
 */
function quarantineStock(PDO $pdo, int $itemId, int $fromLocationId, float $qty, string $reason, ?string $batchLot = null, ?string $serial = null): int
{
    // Decrease usable stock
    decreaseStock($pdo, $itemId, $fromLocationId, $qty);

    // Record quarantine log entry
    $stmt = $pdo->prepare("
        INSERT INTO inv_quarantine_log
        (item_id, location_id, quantity, reason, quarantined_by, batch_lot_number, serial_number)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$itemId, $fromLocationId, $qty, $reason, $_SESSION['user_id'], $batchLot, $serial]);
    $quarantineId = (int) $pdo->lastInsertId();

    recordStockTransaction($pdo, [
        'transaction_type' => 'QUARANTINE_IN',
        'item_id' => $itemId,
        'location_id' => $fromLocationId,
        'quantity' => $qty,
        'reference_type' => 'inv_quarantine_log',
        'reference_id' => $quarantineId,
        'batch_lot_number' => $batchLot,
        'serial_number' => $serial,
        'notes' => "Quarantined: $reason",
    ]);

    return $quarantineId;
}

/**
 * Release stock from quarantine back to usable.
 */
function releaseFromQuarantine(PDO $pdo, int $quarantineId, string $decision, ?string $notes = null): void
{
    $q = $pdo->prepare("
        SELECT quarantine_id, item_id, location_id, quantity, batch_lot_number, serial_number, status
        FROM inv_quarantine_log
        WHERE quarantine_id = ? AND status IN ('QUARANTINED','UNDER_INSPECTION')
    ");
    $q->execute([$quarantineId]);
    $qr = $q->fetch(PDO::FETCH_ASSOC);
    if (!$qr) throw new Exception("Quarantine record not found or already resolved.");

    if ($decision === 'RETURN_TO_STOCK') {
        increaseStock($pdo, $qr['item_id'], $qr['location_id'], (float) $qr['quantity'], [
            'batch_lot_number' => $qr['batch_lot_number'],
            'serial_number' => $qr['serial_number'],
        ]);
        recordStockTransaction($pdo, [
            'transaction_type' => 'QUARANTINE_OUT',
            'item_id' => $qr['item_id'],
            'location_id' => $qr['location_id'],
            'quantity' => $qr['quantity'],
            'reference_type' => 'inv_quarantine_log',
            'reference_id' => $quarantineId,
            'notes' => "Released from quarantine: $decision",
        ]);
    }

    $pdo->prepare("
        UPDATE inv_quarantine_log
        SET status = ?, release_decision = ?, decision_notes = ?, released_by = ?, released_at = CURRENT_TIMESTAMP
        WHERE quarantine_id = ?
    ")->execute([
        $decision === 'RETURN_TO_STOCK' ? 'RELEASED' : 'DISPOSED',
        $decision, $notes, $_SESSION['user_id'], $quarantineId
    ]);
}

/* ================================================================
   FREEZE CONTROLS FOR STOCK COUNTS
================================================================ */

/**
 * Check if a location is frozen for stock counting.
 */
function isLocationFrozen(PDO $pdo, int $locationId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM inv_stock_counts
            WHERE location_id = ? AND is_frozen = 1 AND status = 'IN_PROGRESS'
        ");
        $stmt->execute([$locationId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        // Column doesn't exist yet — migration not run
        return false;
    }
}

/**
 * Enforce that a location is not frozen before allowing stock movements.
 */
function requireLocationNotFrozen(PDO $pdo, int $locationId): void
{
    if (isLocationFrozen($pdo, $locationId)) {
        throw new Exception("This location is currently frozen for stock counting. No stock movements are allowed until the count is completed.");
    }
}

/* ================================================================
   DOCUMENT CONTROL HELPERS
================================================================ */

/**
 * Create and optionally lock a document for any workflow step.
 */
function createAndLockDocument(PDO $pdo, string $docType, string $refTable, int $refId, ?string $notes = null): int
{
    $docId = createInvDocument($pdo, $docType, $refTable, $refId, $notes);
    lockDocument($pdo, $docId);
    return $docId;
}

/**
 * Find document for a reference and lock it.
 */
function lockDocumentByReference(PDO $pdo, string $refTable, int $refId): void
{
    $stmt = $pdo->prepare("
        SELECT document_id FROM inv_documents
        WHERE reference_table = ? AND reference_id = ? AND is_locked = 0
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$refTable, $refId]);
    $docId = $stmt->fetchColumn();
    if ($docId) {
        lockDocument($pdo, (int) $docId);
    }
}

/* ================================================================
   NUMBER GENERATORS FOR NEW MODULES
================================================================ */

function generateRecallNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'RCL-', 'inv_recalls', 'recall_number');
}

function generateReturnNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'RTS-', 'inv_returns', 'return_number');
}

function generateIncidentNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'INC-', 'inv_incidents', 'incident_number');
}

function generateWriteDownNumber(PDO $pdo): string {
    return generateInventoryNumber($pdo, 'WDN-', 'inv_write_downs', 'write_down_number');
}

/* ================================================================
   BATCH TRACEABILITY
================================================================ */

/**
 * Trace all transactions for a given batch/lot number across the system.
 */
function traceBatch(PDO $pdo, string $batchLotNumber): array
{
    $stmt = $pdo->prepare("
        SELECT t.*, i.item_code, i.item_name, l.location_code, u.full_name AS performed_by_name
        FROM inv_transactions t
        JOIN inv_items i ON t.item_id = i.item_id
        LEFT JOIN inv_locations l ON t.location_id = l.location_id
        LEFT JOIN users u ON t.performed_by = u.user_id
        WHERE t.batch_lot_number = ?
        ORDER BY t.created_at ASC
    ");
    $stmt->execute([$batchLotNumber]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================================================================
   STATIC FACADE CLASS
   Provides InventoryService::method() wrappers used by module files.
================================================================ */

class InventoryService
{
    /** Generate a sequential document number. */
    public static function generateDocNumber(PDO $pdo, string $prefix, string $table, string $column): string
    {
        return generateInventoryNumber($pdo, $prefix, $table, $column);
    }

    /** Get total usable stock at a specific location. */
    public static function getStockLevel(PDO $pdo, int $itemId, int $locationId): float
    {
        $batches = getStockAtLocation($pdo, $itemId, $locationId);
        $total = 0;
        foreach ($batches as $b) {
            $total += (float) $b['quantity_on_hand'];
        }
        return $total;
    }

    /** Get currently available usable stock at a location. */
    public static function getAvailableStockLevel(PDO $pdo, int $itemId, int $locationId): float
    {
        return getAvailableStockAtLocation($pdo, $itemId, $locationId);
    }

    public static function reserveIssueStock(PDO $pdo, array $issue, array $lineItems): void
    {
        reserveIssueStock($pdo, $issue, $lineItems);
    }

    public static function dispatchIssueStock(PDO $pdo, array $issue, array $lineItems): void
    {
        dispatchIssueStock($pdo, $issue, $lineItems);
    }

    public static function applyIssuedQuantitiesToRequisition(PDO $pdo, int $requisitionId, array $lineItems): string
    {
        return applyIssuedQuantitiesToRequisition($pdo, $requisitionId, $lineItems);
    }

    public static function reserveTransferStock(PDO $pdo, array $transfer, array $lineItems): void
    {
        reserveTransferStock($pdo, $transfer, $lineItems);
    }

    public static function dispatchTransferStock(PDO $pdo, array $transfer, array $lineItems): void
    {
        dispatchTransferStock($pdo, $transfer, $lineItems);
    }

    public static function receiveTransferStock(PDO $pdo, array $transfer, array $lineItems, array $receivedQuantities): array
    {
        return receiveTransferStock($pdo, $transfer, $lineItems, $receivedQuantities);
    }

    public static function dispatchReturnStock(PDO $pdo, array $return, array $lineItems): void
    {
        dispatchReturnStock($pdo, $return, $lineItems);
    }

    public static function createIncidentAdjustmentAndApplyLoss(PDO $pdo, array $incident, array $lineItems): ?int
    {
        return createIncidentAdjustmentAndApplyLoss($pdo, $incident, $lineItems);
    }

    public static function completeDisposalStock(PDO $pdo, array $disposal, array $lineItems): void
    {
        completeDisposalStock($pdo, $disposal, $lineItems);
    }

    public static function resolveQuarantineRelease(PDO $pdo, int $quarantineId, string $decision, ?string $notes = null): void
    {
        resolveQuarantineRelease($pdo, $quarantineId, $decision, $notes);
    }

    /** Increase or decrease stock at a location. */
    public static function updateStockLevel(PDO $pdo, int $itemId, int $locationId, float $qty, string $direction = 'add'): void
    {
        if ($direction === 'add') {
            increaseStock($pdo, $itemId, $locationId, abs($qty));
        } else {
            decreaseStock($pdo, $itemId, $locationId, abs($qty));
        }
    }

    /** Record a stock transaction. */
    public static function recordTransaction(
        PDO $pdo, int $itemId, int $locationId, string $type, float $qty,
        ?int $refId = null, ?string $refType = null, ?string $notes = null, ?int $userId = null,
        ?string $lotNumber = null, ?string $batchNumber = null, ?string $serialNumber = null, ?string $expiryDate = null
    ): int {
        return recordStockTransaction($pdo, [
            'transaction_type' => $type,
            'item_id'         => $itemId,
            'location_id'     => $locationId,
            'quantity'         => $qty,
            'reference_type'  => $refType,
            'reference_id'    => $refId,
            'batch_lot_number'=> $lotNumber ?? $batchNumber,
            'serial_number'   => $serialNumber,
            'expiry_date'     => $expiryDate,
            'notes'           => $notes,
        ]);
    }
}
