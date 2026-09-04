<?php
/**
 * Inventory Service — core helpers for the inventory module.
 * Provides number generation, stock queries, segregation checks, and document control.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

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
    $sql = "
        SELECT $column FROM $table
        WHERE $column LIKE :prefix
        ORDER BY LENGTH($column) DESC, $column DESC
        LIMIT 1
    ";
    if ($pdo->inTransaction()) {
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
        VALUES (?, ?, ?, ?, ?, ?, ?, 'USABLE', CURDATE())
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
    return $pdo->query("SELECT * FROM inv_categories $where ORDER BY sort_order, category_name")->fetchAll(PDO::FETCH_ASSOC);
}

function getCriticalityClasses(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM inv_criticality_classes ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
}

function getRiskClasses(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM inv_risk_classes ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
}

function getAccountingClasses(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM inv_accounting_classes ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
}

function getUnitsOfMeasure(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM inv_units_of_measure WHERE is_active = 1 ORDER BY uom_name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all active asset types (from migration 024).
 * Returns an empty array if the asset_types table does not yet exist.
 */
function getAssetTypes(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        return $pdo->query("SELECT * FROM asset_types $where ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC);
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
        return $pdo->query("SELECT * FROM inventory_types $where ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC);
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
            "SELECT * FROM inv_asset_item_type_groups $where ORDER BY sort_order, group_name"
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
            "SELECT t.*, g.group_code, g.group_name
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
        SELECT l.*, u.full_name AS custodian_name
        FROM inv_locations l
        LEFT JOIN users u ON l.custodian_user_id = u.user_id
        WHERE l.is_active = 1
        ORDER BY l.site_campus, l.building, l.floor, l.room_storage_area
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function getInvRoles(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM inv_roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
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
        SELECT * FROM inv_approval_log
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
        SELECT * FROM inv_fiscal_periods
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
    $q = $pdo->prepare("SELECT * FROM inv_quarantine_log WHERE quarantine_id = ? AND status IN ('QUARANTINED','UNDER_INSPECTION')");
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
        SET status = ?, release_decision = ?, decision_notes = ?, released_by = ?, released_at = NOW()
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
