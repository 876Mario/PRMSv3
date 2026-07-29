<?php
/**
 * Cascading location dropdown AJAX endpoint.
 *
 * Returns JSON arrays for each level of the location hierarchy stored in
 * inv_locations.  Accepted parameters:
 *
 *   ?type=sites                             → distinct site_campus values
 *   ?type=buildings&site=...               → distinct buildings for a site
 *   ?type=floors&site=...&building=...     → distinct floors
 *   ?type=rooms&site=...&building=...&floor=... → distinct room_storage_area values
 *
 * Only ACTIVE locations are considered.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

$type     = $_GET['type']     ?? '';
$site     = trim($_GET['site']     ?? '');
$building = trim($_GET['building'] ?? '');
$floor    = trim($_GET['floor']    ?? '');

try {
    switch ($type) {
        case 'sites':
            $stmt = $pdo->query(
                "SELECT DISTINCT site_campus
                 FROM inv_locations
                 WHERE is_active = 1 AND site_campus IS NOT NULL AND site_campus <> ''
                 ORDER BY site_campus"
            );
            echo json_encode(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'site_campus'));
            break;

        case 'buildings':
            $stmt = $pdo->prepare(
                "SELECT DISTINCT building
                 FROM inv_locations
                 WHERE is_active = 1
                   AND building IS NOT NULL AND building <> ''
                   AND (:site = '' OR site_campus = :site)
                 ORDER BY building"
            );
            $stmt->execute([':site' => $site]);
            echo json_encode(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'building'));
            break;

        case 'floors':
            $stmt = $pdo->prepare(
                "SELECT DISTINCT floor
                 FROM inv_locations
                 WHERE is_active = 1
                   AND floor IS NOT NULL AND floor <> ''
                   AND (:site = '' OR site_campus = :site)
                   AND (:building = '' OR building = :building)
                 ORDER BY floor"
            );
            $stmt->execute([':site' => $site, ':building' => $building]);
            echo json_encode(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'floor'));
            break;

        case 'rooms':
            $stmt = $pdo->prepare(
                "SELECT DISTINCT room_storage_area, location_id
                 FROM inv_locations
                 WHERE is_active = 1
                   AND room_storage_area IS NOT NULL AND room_storage_area <> ''
                   AND (:site = '' OR site_campus = :site)
                   AND (:building = '' OR building = :building)
                   AND (:floor = '' OR floor = :floor)
                 ORDER BY room_storage_area"
            );
            $stmt->execute([':site' => $site, ':building' => $building, ':floor' => $floor]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid type parameter.']);
    }
} catch (Throwable $e) {
    error_log('get_locations.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error.']);
}
