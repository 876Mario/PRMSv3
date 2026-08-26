<?php
/**
 * Inventory Alerts Cron Job (FIXED - 2026-08-19)
 * ===============================================
 * Recommended schedule: 0 6 * * * php /path/to/cron/inventory_alerts.php
 *
 * Generates alerts for:
 * 1. Items below reorder level
 * 2. Expiring stock (within 30 / 7 days)
 * 3. Expired stock
 * 4. Pending approvals older than 48 hours
 * 5. Open incidents without investigation
 *
 * FIXES (2026-08-19):
 *   ✓ Sends to configured Property Management Officers (role query), not hardcoded admin email
 *   ✓ Uses CronAuditService for execution locking and audit trail
 *   ✓ Location-aware recipient filtering from inventory_alert_recipients table
 *   ✓ Deduplication at the recipient-selection level
 *   ✓ Complete audit trail for compliance
 *   ✓ Prevents duplicate cron execution via lock mechanism
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/app.php';

if (!class_exists('CronAuditService')) {
    $cronAuditPath = __DIR__ . '/../services/CronAuditService.php';
    if (file_exists($cronAuditPath)) {
        require_once $cronAuditPath;
    } else {
        die("FATAL: CronAuditService.php not found at {$cronAuditPath}\n");
    }
}

if (!class_exists('CronNotificationRoutingService')) {
    $routingServicePath = __DIR__ . '/../services/CronNotificationRoutingService.php';
    if (file_exists($routingServicePath)) {
        require_once $routingServicePath;
    } else {
        die("FATAL: CronNotificationRoutingService.php not found at {$routingServicePath}\n");
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Inventory alerts cron started.\n";

/* ─── Acquire execution lock (prevent concurrent runs) ──────────────────── */
$cronName = 'inventory_alerts';
$lockId = CronAuditService::acquireLock($cronName, 600);
if ($lockId === null) {
    echo "[" . date('H:i:s') . "] ERROR: Could not acquire lock for {$cronName}. "
       . "Another instance may be running. Exiting to prevent duplicate notifications.\n";
    exit(1);
}

/* ─── Start execution audit log ───────────────────────────────────────── */
$executionId = CronAuditService::startExecution($cronName);
if ($executionId === null) {
    echo "[" . date('H:i:s') . "] ERROR: Could not start execution audit log. Exiting.\n";
    CronAuditService::releaseLock($lockId);
    exit(1);
}

$itemsFound = 0;
$recipientsFound = 0;
$notificationsCreated = 0;
$notificationsFailed = 0;
$errorMessages = [];

try {
    $alerts = [];
    $alertDetails = []; // For audit trail

    // 1. Reorder alerts — items where total stock <= reorder_level
    $reorder = $pdo->query("
        SELECT i.item_id, i.item_code, i.item_name, i.reorder_level, 
               COALESCE(SUM(s.quantity_on_hand), 0) AS total_stock,
               MIN(s.location_id) as sample_location_id
        FROM inv_items i
        LEFT JOIN inv_stock s ON i.item_id = s.item_id
        WHERE i.item_status = 'ACTIVE' AND i.reorder_level > 0
        GROUP BY i.item_id
        HAVING total_stock <= i.reorder_level
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reorder as $r) {
        $alertDetails[] = [
            'item_id' => (int)$r['item_id'],
            'location_id' => $r['sample_location_id'] ? (int)$r['sample_location_id'] : null,
            'type' => 'REORDER',
            'message' => "[REORDER] {$r['item_code']} {$r['item_name']} — Stock: {$r['total_stock']}, Reorder Level: {$r['reorder_level']}",
        ];
        $alerts[] = $alertDetails[count($alertDetails)-1]['message'];
        $itemsFound++;
    }

    // 2. Expiring within 30 days
    $expiring30 = $pdo->query("
        SELECT i.item_id, i.item_code, i.item_name, s.expiry_date, s.quantity_on_hand, 
               l.location_id, l.location_code
        FROM inv_stock s
        JOIN inv_items i ON s.item_id = i.item_id
        LEFT JOIN inv_locations l ON s.location_id = l.location_id
        WHERE s.expiry_date IS NOT NULL
          AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND s.quantity_on_hand > 0
        ORDER BY s.expiry_date
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiring30 as $e) {
        $daysLeft = (int) ((strtotime($e['expiry_date']) - time()) / 86400);
        $urgency = $daysLeft <= 7 ? 'URGENT' : 'WARNING';
        $alertType = $daysLeft <= 7 ? 'EXPIRING_7' : 'EXPIRING_30';
        $msg = "[EXPIRY-$urgency] {$e['item_code']} {$e['item_name']} at {$e['location_code']} — "
             . "Expires {$e['expiry_date']} ({$daysLeft}d), Qty: {$e['quantity_on_hand']}";
        $alertDetails[] = [
            'item_id' => (int)$e['item_id'],
            'location_id' => !empty($e['location_id']) ? (int)$e['location_id'] : null,
            'type' => $alertType,
            'message' => $msg,
        ];
        $alerts[] = $msg;
        $itemsFound++;
    }

    // 3. Already expired
    $expired = $pdo->query("
        SELECT i.item_id, i.item_code, i.item_name, s.expiry_date, s.quantity_on_hand, 
               l.location_id, l.location_code
        FROM inv_stock s
        JOIN inv_items i ON s.item_id = i.item_id
        LEFT JOIN inv_locations l ON s.location_id = l.location_id
        WHERE s.expiry_date IS NOT NULL AND s.expiry_date < CURDATE() AND s.quantity_on_hand > 0
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expired as $e) {
        $msg = "[EXPIRED] {$e['item_code']} {$e['item_name']} at {$e['location_code']} — "
             . "Expired {$e['expiry_date']}, Qty: {$e['quantity_on_hand']}";
        $alertDetails[] = [
            'item_id' => (int)$e['item_id'],
            'location_id' => !empty($e['location_id']) ? (int)$e['location_id'] : null,
            'type' => 'EXPIRED',
            'message' => $msg,
        ];
        $alerts[] = $msg;
        $itemsFound++;
    }

    // 4. Pending approvals > 48 hours
    $pendingIssues = $pdo->query("
        SELECT issue_number FROM inv_issues WHERE status = 'PENDING_APPROVAL' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingIssues as $p) {
        $msg = "[PENDING] Issue {$p['issue_number']} — awaiting approval for over 48 hours";
        $alertDetails[] = ['item_id' => null, 'location_id' => null, 'type' => 'PENDING_APPROVAL', 'message' => $msg];
        $alerts[] = $msg;
    }

    $pendingReturns = $pdo->query("
        SELECT return_number FROM inv_returns WHERE status = 'PENDING_APPROVAL' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingReturns as $p) {
        $msg = "[PENDING] Return {$p['return_number']} — awaiting approval for over 48 hours";
        $alertDetails[] = ['item_id' => null, 'location_id' => null, 'type' => 'PENDING_APPROVAL', 'message' => $msg];
        $alerts[] = $msg;
    }

    // 5. Open incidents without investigation assigned
    $openIncidents = $pdo->query("
        SELECT incident_number, incident_type FROM inv_incidents
        WHERE status = 'REPORTED' AND investigator_id IS NULL AND reported_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($openIncidents as $inc) {
        $msg = "[INCIDENT] {$inc['incident_number']} ({$inc['incident_type']}) — no investigator assigned after 24 hours";
        $alertDetails[] = ['item_id' => null, 'location_id' => null, 'type' => 'OPEN_INCIDENT', 'message' => $msg];
        $alerts[] = $msg;
    }

    // If no alerts, exit early
    if (empty($alerts)) {
        echo "[" . date('H:i:s') . "] No inventory alerts generated.\n";
        CronAuditService::completeExecution(
            $executionId, 'SUCCESS', 0, 0, 0, 0, null,
            "No inventory alerts found; no notifications sent"
        );
        CronAuditService::releaseLock($lockId);
        exit(0);
    }

    // Build alert report
    $subject = "Inventory Alerts — " . date('Y-m-d') . " (" . count($alerts) . " alerts)";
    $body = "Inventory Management System — Daily Alert Report\n";
    $body .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $body .= str_repeat('=', 60) . "\n\n";
    $body .= implode("\n", $alerts) . "\n";

    echo $subject . "\n" . $body . "\n";

    // Get configured recipients for inventory alerts
    // (no location filter = all Property Management Officers)
    $recipients = [];
    foreach ($alertDetails as $detail) {
        $locationId = $detail['location_id'];
        $alertType = $detail['type'];
        $locs = CronAuditService::getInventoryAlertRecipients($locationId, $alertType);
        foreach ($locs as $userId => $info) {
            if (!isset($recipients[$userId])) {
                $recipients[$userId] = $info;
            }
        }
    }

    if (empty($recipients)) {
        echo "[" . date('H:i:s') . "] SKIP: No configured inventory alert recipients found.\n";
        $errorMessages[] = "No configured inventory alert recipients (Property Management Officers)";
        CronAuditService::completeExecution(
            $executionId, 'PARTIAL_FAILURE', count($alertDetails), 0, 0, 0, null,
            implode("; ", $errorMessages)
        );
        CronAuditService::releaseLock($lockId);
        exit(0);
    }

    $recipientsFound = count($recipients);
    echo "[" . date('H:i:s') . "] Found {$recipientsFound} configured recipient(s) for inventory alerts.\n";

    // Send to each recipient
    foreach ($recipients as $userId => $recipientInfo) {
        $userEmail = $recipientInfo['email'] ?? '';
        $userName  = $recipientInfo['full_name'] ?? 'User';
        $reason    = $recipientInfo['reason'] ?? 'Unknown';

        if (!$userEmail) continue;

        // Format email body (HTML)
        $htmlBody = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>"
                  . "<body style='font-family:Arial,sans-serif;color:#333;line-height:1.6;'>"
                  . "<div style='max-width:600px;margin:0 auto;'>"
                  . "<div style='background:linear-gradient(90deg,#0b5e2b,#c9a227);color:#fff;padding:16px 20px;border-radius:4px 4px 0 0;'>"
                  . "<strong>DGC PIAMS – Inventory Alert</strong></div>"
                  . "<div style='padding:20px;border:1px solid #ddd;border-radius:0 0 4px 4px;'>"
                  . "<p>Dear " . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . ",</p>"
                  . "<p>The following inventory alerts require your attention:</p>"
                  . "<pre style='background:#f5f5f5;padding:10px;overflow-x:auto;'>"
                  . htmlspecialchars(implode("\n", $alerts), ENT_QUOTES, 'UTF-8')
                  . "</pre>"
                  . "<p><a href='" . htmlspecialchars(defined('APP_URL') ? APP_URL : 'http://localhost') . "/inventory/' "
                  . "style='background:#0b5e2b;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;'>"
                  . "View Inventory Management</a></p>"
                  . "<p style='font-size:0.9em;color:#666;'>"
                  . "Recipient: " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "<br/>"
                  . "Generated: " . date('Y-m-d H:i:s') . "</p>"
                  . "</div></div></body></html>";

        try {
            if (sendMail($userEmail, $subject, $htmlBody)) {
                $notificationsCreated++;
                // Audit each generated inventory alert against the PMO recipient.
                foreach ($alertDetails as $detail) {
                    CronAuditService::logRecipient(
                        $executionId,
                        isset($detail['item_id']) ? (int)$detail['item_id'] : null,
                        'INVENTORY_ALERT',
                        $detail['type'] ?? null,
                        null,
                        !empty($detail['location_id']) ? (int)$detail['location_id'] : null,
                        (int)$userId,
                        $reason,
                        false,
                        null
                    );
                }
                echo "[" . date('H:i:s') . "] Alert sent → user {$userId} ({$userName})\n";
            } else {
                $notificationsFailed++;
                echo "[" . date('H:i:s') . "] Alert FAILED for user {$userId} ({$userName})\n";
            }
        } catch (Throwable $e) {
            $notificationsFailed++;
            echo "[" . date('H:i:s') . "] Alert exception for user {$userId}: " . $e->getMessage() . "\n";
        }
    }

    // Complete execution
    $notes = "Found {$itemsFound} inventory issues, sent to {$notificationsCreated}/{$recipientsFound} recipients";
    if (!empty($errorMessages)) {
        $notes .= "; Issues: " . implode("; ", $errorMessages);
    }

    CronAuditService::completeExecution(
        $executionId,
        $notificationsFailed > 0 ? 'PARTIAL_FAILURE' : 'SUCCESS',
        $itemsFound,
        $recipientsFound,
        $notificationsCreated,
        $notificationsFailed,
        null,
        $notes
    );

} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    echo "[" . date('H:i:s') . "] FATAL ERROR: {$errorMsg}\n";
    CronAuditService::completeExecution(
        $executionId,
        'FAILED',
        $itemsFound,
        0,
        0,
        0,
        $errorMsg,
        "Exception during execution"
    );
} finally {
    // Release lock
    CronAuditService::releaseLock($lockId);
}

echo "[" . date('Y-m-d H:i:s') . "] Inventory alerts cron completed.\n";
