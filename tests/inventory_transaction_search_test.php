<?php

require_once __DIR__ . '/../services/InventoryTransactionSearch.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function searchTransfers(PDO $pdo, string $search, string $status = ''): array
{
    $where = '1=1';
    $params = [];

    if ($search !== '') {
        $itemSearch = buildInventoryItemSearchExistsClause('t', 'transfer_id', 'inv_transfer_items', 'transfer_id');
        $where .= " AND (t.transfer_number LIKE ? OR u.full_name LIKE ? OR $itemSearch)";
        $s = inventoryTransactionSearchPattern($search);
        $params = array_merge($params, [$s, $s, $s, $s, $s]);
    }
    if ($status) {
        $where .= ' AND t.status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT t.transfer_number
        FROM inv_transfers t
        LEFT JOIN users u ON t.requested_by = u.user_id
        WHERE $where
        ORDER BY t.transfer_number
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL
    );
    CREATE TABLE inv_transfers (
        transfer_id INTEGER PRIMARY KEY,
        transfer_number TEXT NOT NULL,
        requested_by INTEGER NOT NULL,
        status TEXT NOT NULL
    );
    CREATE TABLE inv_items (
        item_id INTEGER PRIMARY KEY,
        item_code TEXT NOT NULL,
        item_name TEXT NOT NULL,
        description TEXT
    );
    CREATE TABLE inv_transfer_items (
        transfer_item_id INTEGER PRIMARY KEY,
        transfer_id INTEGER NOT NULL,
        item_id INTEGER NOT NULL
    );
");

$pdo->exec("
    INSERT INTO users (user_id, full_name) VALUES (1, 'Alice Smith'), (2, 'Brian Brown');
    INSERT INTO inv_transfers (transfer_id, transfer_number, requested_by, status) VALUES
        (1, 'TR-001', 1, 'COMPLETED'),
        (2, 'TR-002', 2, 'DRAFT'),
        (3, 'TR-003', 1, 'COMPLETED');
    INSERT INTO inv_items (item_id, item_code, item_name, description) VALUES
        (10, 'ABC-123', 'Reagent Kit', 'Sterile Transfer Bottle'),
        (11, 'xyz-789', 'Safety Gloves', 'Powder Free Nitrile Gloves'),
        (12, 'MIX-456', 'Control Sample', '  Mixed Case Description  '),
        (13, '0', 'Zero Code Item', 'Zero edge case');
    INSERT INTO inv_transfer_items (transfer_item_id, transfer_id, item_id) VALUES
        (100, 1, 10),
        (101, 2, 11),
        (102, 3, 12),
        (103, 2, 13);
");

assertSameValue(['TR-001'], searchTransfers($pdo, 'ABC-123'), 'Exact item code search should match.');
assertSameValue(['TR-001'], searchTransfers($pdo, 'BC-1'), 'Partial item code search should match.');
assertSameValue(['TR-001'], searchTransfers($pdo, 'Sterile Transfer Bottle'), 'Exact item description search should match.');
assertSameValue(['TR-002'], searchTransfers($pdo, 'nitrile'), 'Partial item description search should match.');
assertSameValue(['TR-002'], searchTransfers($pdo, '0', 'DRAFT'), 'Zero-like item code search should not be treated as empty.');
assertSameValue(['TR-003'], searchTransfers($pdo, 'mixed case'), 'Mixed-case and padded description search should match.');
assertSameValue(['TR-001'], searchTransfers($pdo, 'ABC', 'COMPLETED'), 'Combined search and status filters should match.');
assertSameValue([], searchTransfers($pdo, 'ABC', 'DRAFT'), 'Combined filters should exclude non-matching statuses.');
assertSameValue(['TR-001', 'TR-002', 'TR-003'], searchTransfers($pdo, ''), 'Empty search should not restrict transfers.');
assertSameValue(['TR-001', 'TR-003'], searchTransfers($pdo, '', 'COMPLETED'), 'Empty search should still allow other filters.');
assertSameValue(['TR-001', 'TR-003'], searchTransfers($pdo, 'Alice'), 'Existing requester search should still work.');

$invalidIdentifierThrown = false;
try {
    buildInventoryItemSearchExistsClause('t;DROP TABLE inv_items', 'transfer_id', 'inv_transfer_items', 'transfer_id');
} catch (InvalidArgumentException $e) {
    $invalidIdentifierThrown = true;
}
assertSameValue(true, $invalidIdentifierThrown, 'Invalid SQL identifiers should be rejected.');

echo "Inventory transaction search tests passed.\n";
