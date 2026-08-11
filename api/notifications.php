<?php
/**
 * In-app Notifications API
 * ========================
 * GET  ?action=list            – return JSON array of current user's notifications
 * GET  ?action=count           – return JSON { "unread": N }
 * POST ?action=mark_read&id=X  – mark notification X as read
 * POST ?action=mark_all_read   – mark all as read
 *
 * All responses are JSON.  Authentication is enforced via config/auth.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/NotificationService.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    case 'list':
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
        $rows = $unreadOnly
            ? NotificationService::getUnread($userId)
            : NotificationService::getAll($userId, $limit);
        echo json_encode(['notifications' => $rows]);
        break;

    case 'count':
        echo json_encode(['unread' => NotificationService::countUnread($userId)]);
        break;

    case 'mark_read':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            break;
        }
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid id']);
            break;
        }
        NotificationService::markRead($id, $userId);
        echo json_encode(['ok' => true]);
        break;

    case 'mark_all_read':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            break;
        }
        NotificationService::markAllRead($userId);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
