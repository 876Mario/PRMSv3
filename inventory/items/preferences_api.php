<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/column_modules.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/ColumnPreferenceService.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$config = getColumnPreferenceModuleConfig('inventory_items', $pdo);
$service = new ColumnPreferenceService($pdo);
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'preferences' => $service->resolveState($userId, $config)]);
    exit;
}

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

try {
    $action = $body['action'] ?? '';
    if ($action === 'reset') {
        $service->resetPreferences($userId, $config);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action !== 'save') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }

    $body['module'] = 'inventory_items';
    $state = $service->savePreferences($userId, $config, $body);
    echo json_encode(['success' => true, 'preferences' => $state]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to save preferences right now.']);
}
