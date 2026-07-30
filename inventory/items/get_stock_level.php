<?php
/**
 * AJAX endpoint: return available stock for an item at a location.
 * GET params: item_id, location_id
 * Returns JSON: { available: float, on_hand: float, reserved: float }
 */
$REQUIRE_PERMISSION = 'transfer_stock';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once __DIR__ . '/../check_setup.php';

header('Content-Type: application/json');

$itemId     = (int) ($_GET['item_id']     ?? 0);
$locationId = (int) ($_GET['location_id'] ?? 0);

if ($itemId <= 0 || $locationId <= 0) {
    echo json_encode(['available' => 0, 'on_hand' => 0, 'reserved' => 0]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(quantity_on_hand), 0)  AS on_hand,
        COALESCE(SUM(quantity_reserved), 0) AS reserved,
        COALESCE(SUM(quantity_available), 0) AS available
    FROM inv_stock
    WHERE item_id = ? AND location_id = ? AND stock_status = 'USABLE'
");
$stmt->execute([$itemId, $locationId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'available' => (float) ($row['available'] ?? 0),
    'on_hand'   => (float) ($row['on_hand']   ?? 0),
    'reserved'  => (float) ($row['reserved']  ?? 0),
]);
