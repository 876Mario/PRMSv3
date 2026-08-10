<?php
/**
 * Inventory Item Search API
 * Returns searchable inventory items with asset and item details
 * Supports searching by item_code, item_description, item_name, and asset_code
 * Excludes items with BOS status
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');
$limit = (int) ($_GET['limit'] ?? 50);

if (strlen($query) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

if ($limit < 1 || $limit > 500) {
    $limit = 50;
}

// Search in both inv_items and inv_asset_details tables
// Prioritize exact matches and prefix matches
$searchPattern = $query . '%';
$wildPattern = '%' . $query . '%';

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            i.item_id,
            i.item_code,
            i.item_name,
            i.description,
            COALESCE(NULLIF(TRIM(i.description), ''), i.item_name) AS item_description,
            ad.asset_code,
            ad.asset_status,
            COALESCE(ad.asset_code, '') AS display_code,
            CASE 
                WHEN LOWER(TRIM(COALESCE(ad.asset_code, ''))) LIKE LOWER(?) THEN 1
                WHEN LOWER(TRIM(COALESCE(i.item_code, ''))) LIKE LOWER(?) THEN 2
                WHEN LOWER(TRIM(COALESCE(ad.asset_code, ''))) LIKE LOWER(?) THEN 3
                WHEN LOWER(TRIM(COALESCE(i.item_code, ''))) LIKE LOWER(?) THEN 4
                WHEN LOWER(COALESCE(NULLIF(TRIM(i.description), ''), i.item_name, '')) LIKE LOWER(?) THEN 5
                WHEN LOWER(TRIM(COALESCE(i.item_name, ''))) LIKE LOWER(?) THEN 6
                ELSE 7
            END AS match_priority
        FROM inv_items i
        LEFT JOIN inv_asset_details ad ON i.item_id = ad.item_id
        WHERE (
            LOWER(TRIM(COALESCE(i.item_code, ''))) LIKE LOWER(?) OR
            LOWER(COALESCE(NULLIF(TRIM(i.description), ''), i.item_name, '')) LIKE LOWER(?) OR
            LOWER(TRIM(COALESCE(i.item_name, ''))) LIKE LOWER(?) OR
            LOWER(TRIM(COALESCE(ad.asset_code, ''))) LIKE LOWER(?) OR
            LOWER(TRIM(COALESCE(ad.asset_status, ''))) LIKE LOWER(?)
        )
        AND i.item_status = 'ACTIVE'
        AND (ad.asset_status IS NULL OR ad.asset_status != 'BOS')
        ORDER BY match_priority ASC, i.item_code ASC, i.item_name ASC
        LIMIT ?
    ");

    $matchPriorityParams = [
        $searchPattern,      // asset_code exact prefix
        $searchPattern,      // item_code exact prefix
        $wildPattern,        // asset_code contains
        $wildPattern,        // item_code contains
        $wildPattern,        // item_description contains
        $wildPattern,        // item_name contains
    ];
    $filterParams = [
        $wildPattern,        // item_code search
        $wildPattern,        // item_description search
        $wildPattern,        // item_name search
        $wildPattern,        // asset_code search
        $wildPattern,        // asset_status search
    ];

    $params = array_merge($matchPriorityParams, $filterParams);
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
    }
    // LIMIT must be bound as an integer: with PDO emulated prepares (the
    // default), values passed via execute() are quoted as strings, producing
    // "LIMIT '50'" which is a MySQL syntax error and made every search fail.
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = [];
    $seenIds = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $itemId = $row['item_id'];
        
        // Avoid duplicate items
        if (isset($seenIds[$itemId])) {
            continue;
        }
        $seenIds[$itemId] = true;

        // Format display text
        $displayCode = !empty($row['asset_code']) ? $row['asset_code'] : $row['item_code'];
        $displayName = $row['item_name'];
        $displayText = $displayCode . ' | ' . $displayName;

        $results[] = [
            'id' => $itemId,
            'text' => $displayText,
            'item_id' => $itemId,
            'item_code' => $row['item_code'],
            'item_name' => $row['item_name'],
            'item_description' => $row['item_description'],
            'asset_code' => $row['asset_code'],
            'description' => $row['description'],
            'asset_status' => $row['asset_status']
        ];
    }

    echo json_encode([
        'results' => $results,
        'pagination' => ['more' => count($results) >= $limit]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
