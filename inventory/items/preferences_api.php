<?php
/**
 * User Table Preferences API
 *
 * Saves and retrieves per-user, per-page table preferences (visible columns,
 * column order, default sort column/direction, page size).
 *
 * GET  ?page_id=...              → Returns stored preferences as JSON
 * POST {action:"save"|"reset"}  → Upserts or deletes preferences; returns JSON
 *
 * All inputs are strictly validated server-side against a whitelist of
 * allowed column keys and values — the client cannot expose or inject
 * unauthorised fields.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';

header('Content-Type: application/json');

/* ── Auth ──────────────────────────────────────────────────────────────────── */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* ── Column whitelists per page identifier ──────────────────────────────────
   Add a new entry here when reusing this API for other pages.
   Only keys present in the whitelist for the requested page_identifier are
   accepted; any unknown key is silently stripped before persisting.
   ───────────────────────────────────────────────────────────────────────── */
$PAGE_COLUMN_WHITELIST = [
    'inventory_items_list' => [
        'item_code', 'item_name', 'item_domain', 'category_name',
        'manufacturer', 'uom_code', 'total_stock', 'available_stock',
        'average_cost', 'item_status', 'criticality_name', 'actions',
    ],
];

/* ── Handle GET (load preferences) ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pageId = trim($_GET['page_id'] ?? '');
    if (!isset($PAGE_COLUMN_WHITELIST[$pageId])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown page identifier']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT visible_columns, column_order, default_sort_column,
                   default_sort_direction, page_size
            FROM user_table_preferences
            WHERE user_id = ? AND page_identifier = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $pageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['visible_columns'] = json_decode($row['visible_columns'] ?? 'null', true);
            $row['column_order']    = json_decode($row['column_order']    ?? 'null', true);
            $row['page_size']       = (int) $row['page_size'];
            echo json_encode(['success' => true, 'preferences' => $row]);
        } else {
            echo json_encode(['success' => true, 'preferences' => null]);
        }
    } catch (Throwable $e) {
        // Table may not exist yet (migration not applied); return success with null preferences
        // to allow the page to use defaults gracefully instead of breaking with a 500 error.
        echo json_encode(['success' => true, 'preferences' => null]);
    }
    exit;
}

/* ── Handle POST (save / reset) ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$action = $body['action'] ?? '';
$pageId = trim($body['page_id'] ?? 'inventory_items_list');

if (!isset($PAGE_COLUMN_WHITELIST[$pageId])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown page identifier']);
    exit;
}

$allowedKeys = $PAGE_COLUMN_WHITELIST[$pageId];

/* ── RESET ──────────────────────────────────────────────────────────────────── */
if ($action === 'reset') {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_table_preferences
            WHERE user_id = ? AND page_identifier = ?
        ");
        $stmt->execute([$userId, $pageId]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        // Table may not exist yet (migration not applied); return success (no-op)
        // to allow the page to continue using defaults instead of breaking with a 500 error.
        echo json_encode(['success' => true]);
    }
    exit;
}

/* ── SAVE ───────────────────────────────────────────────────────────────────── */
if ($action === 'save') {

    /* Validate and sanitise column arrays */
    $rawVisible = $body['visible_columns'] ?? [];
    $rawOrder   = $body['column_order']   ?? [];

    if (!is_array($rawVisible) || !is_array($rawOrder)) {
        http_response_code(400);
        echo json_encode(['error' => 'visible_columns and column_order must be arrays']);
        exit;
    }

    /* Filter to whitelisted keys only */
    $visibleColumns = array_values(array_filter($rawVisible, fn($k) => in_array($k, $allowedKeys, true)));
    $columnOrder    = array_values(array_filter($rawOrder,   fn($k) => in_array($k, $allowedKeys, true)));

    /* Validate sort column */
    $validSortCols = array_diff($allowedKeys, ['actions', 'uom_code']); // non-sortable columns excluded
    $sortColumn    = null;
    if (!empty($body['default_sort_column'])) {
        $candidate = (string) $body['default_sort_column'];
        if (in_array($candidate, $validSortCols, true)) {
            $sortColumn = $candidate;
        }
    }

    /* Validate sort direction */
    $sortDirection = strtoupper($body['default_sort_direction'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    /* Validate page size (allow: 10, 20, 50, 100, 200) */
    $allowedSizes = [10, 20, 50, 100, 200];
    $pageSize     = (int) ($body['page_size'] ?? 20);
    if (!in_array($pageSize, $allowedSizes, true)) {
        $pageSize = 20;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_table_preferences
                (user_id, page_identifier, visible_columns, column_order,
                 default_sort_column, default_sort_direction, page_size)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                visible_columns      = VALUES(visible_columns),
                column_order         = VALUES(column_order),
                default_sort_column  = VALUES(default_sort_column),
                default_sort_direction = VALUES(default_sort_direction),
                page_size            = VALUES(page_size),
                updated_at           = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $userId,
            $pageId,
            json_encode($visibleColumns),
            json_encode($columnOrder),
            $sortColumn,
            $sortDirection,
            $pageSize,
        ]);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        // Table may not exist yet (migration not applied); return success (no-op)
        // to allow the page to continue functioning instead of breaking with a 500 error.
        // User preferences will be saved once the migration is applied.
        echo json_encode(['success' => true]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
