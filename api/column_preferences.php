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

$userId = (int)$_SESSION['user_id'];
$service = new ColumnPreferenceService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $module = trim((string)($_GET['module'] ?? ''));
    $config = getColumnPreferenceModuleConfig($module, $pdo);
    if ($config === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown column preference module']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'preferences' => $service->resolveState($userId, $config),
    ]);
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

$module = trim((string)($body['module'] ?? ''));
$config = getColumnPreferenceModuleConfig($module, $pdo);
if ($config === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown column preference module']);
    exit;
}

$action = trim((string)($body['action'] ?? ''));

try {
    if ($action === 'reset') {
        $service->resetPreferences($userId, $config);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save') {
        $state = $service->savePreferences($userId, $config, $body);
        echo json_encode(['success' => true, 'preferences' => $state]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to save column preferences right now.']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
