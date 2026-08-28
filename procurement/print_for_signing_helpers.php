<?php

function requestTypeToModule(string $requestType): string
{
    $normalizedType = strtoupper(trim($requestType));
    if ($normalizedType === 'SERVICE_CONTRACT') {
        return 'procurement';
    }

    $normalized = strtolower($normalizedType);
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function loadDocControlSettings(PDO $pdo, string $requestType): array
{
    try {
        $typeStmt = $pdo->prepare("SELECT * FROM doc_ctrl_settings WHERE request_type = ? LIMIT 1");
        $typeStmt->execute([$requestType]);
        $settings = $typeStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($settings) && !empty($settings)) {
            return $settings;
        }
    } catch (Throwable $e) {
        // Backward compatibility: older schemas may not have request_type column.
    }

    try {
        $legacyStmt = $pdo->query("SELECT * FROM doc_ctrl_settings WHERE id = 1 LIMIT 1");
        $settings = $legacyStmt->fetch(PDO::FETCH_ASSOC);
        return is_array($settings) ? $settings : [];
    } catch (Throwable $e) {
        return [];
    }
}
