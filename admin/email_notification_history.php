<?php
/**
 * /admin/email_notification_history.php
 * ======================================
 * Admin-only view of:
 *   1. Notification delivery history (event, recipient, status, failure reason, timestamp)
 *   2. Configuration change history (who changed what, old vs new value, when)
 */
$REQUIRE_PERMISSION = 'manage_email_notifications';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/pagination.php';

$canManage = in_array($_SESSION['role_name'] ?? '', ['Admin', 'SuperAdmin'], true);
if (!$canManage) {
    pop('Access denied.', '/dashboard/index.php', 1500, 'error');
    exit;
}

$tab = ($_GET['tab'] ?? 'log') === 'history' ? 'history' : 'log';

extract(getPaginationParams(25));

if ($tab === 'log') {
    $where = [];
    $params = [];
    if (!empty($_GET['event_key'])) {
        $where[] = 'l.event_key = :event_key';
        $params[':event_key'] = $_GET['event_key'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'l.status = :status';
        $params[':status'] = $_GET['status'];
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT l.id, l.event_key, e.event_label, l.request_id, l.recipient_email, l.subject,
               l.status, l.failure_reason, l.sent_at
        FROM email_notification_log l
        LEFT JOIN email_notification_events e ON e.event_key = l.event_key
        $whereSQL
        ORDER BY l.sent_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_notification_log l $whereSQL");
    foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
    $countStmt->execute();
    $totalRows = (int)$countStmt->fetchColumn();
} else {
    $sql = "
        SELECT h.id, h.event_key, e.event_label, h.field_changed, h.old_value, h.new_value,
               h.changed_by_name, h.changed_at
        FROM email_notification_config_history h
        LEFT JOIN email_notification_events e ON e.event_key = h.event_key
        ORDER BY h.changed_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM email_notification_config_history")->fetchColumn();
}

$events = $pdo->query("SELECT event_key, event_label FROM email_notification_events ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>Email Notification History</h4>
    <a href="/admin/email_notifications.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-gear me-1"></i>Back to Configuration
    </a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'log' ? 'active' : '' ?>" href="?tab=log">Delivery Log</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="?tab=history">Configuration Changes</a></li>
</ul>

<?php if ($tab === 'log'): ?>
<form method="get" class="row g-2 mb-3">
    <input type="hidden" name="tab" value="log">
    <div class="col-md-3">
        <select name="event_key" class="form-select form-select-sm">
            <option value="">All Events</option>
            <?php foreach ($events as $e): ?>
            <option value="<?= htmlspecialchars($e['event_key']) ?>" <?= ($_GET['event_key'] ?? '') === $e['event_key'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['event_label']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="SENT" <?= ($_GET['status'] ?? '') === 'SENT' ? 'selected' : '' ?>>Sent</option>
            <option value="FAILED" <?= ($_GET['status'] ?? '') === 'FAILED' ? 'selected' : '' ?>>Failed</option>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <p class="text-muted mb-0">No notification history found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Event</th>
                        <th>Request</th>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Failure Reason</th>
                        <th>Sent At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($r['event_label'] ?? $r['event_key']) ?></td>
                        <td class="small"><?= $r['request_id'] ? '#'.(int)$r['request_id'] : '—' ?></td>
                        <td class="small"><?= htmlspecialchars($r['recipient_email']) ?></td>
                        <td class="small"><?= htmlspecialchars($r['subject'] ?? '') ?></td>
                        <td><span class="badge <?= $r['status'] === 'SENT' ? 'bg-success' : 'bg-danger' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        <td class="small text-muted"><?= htmlspecialchars($r['failure_reason'] ?? '') ?></td>
                        <td class="small"><?= date('d M Y H:i', strtotime($r['sent_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div class="card shadow-sm border-0">
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <p class="text-muted mb-0">No configuration changes recorded.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Event</th>
                        <th>Field</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>Changed By</th>
                        <th>Changed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($r['event_label'] ?? $r['event_key']) ?></td>
                        <td class="small"><?= htmlspecialchars($r['field_changed']) ?></td>
                        <td class="small text-muted" style="max-width:250px; word-break:break-word;"><?= htmlspecialchars(mb_strimwidth((string)$r['old_value'], 0, 200, '…')) ?></td>
                        <td class="small" style="max-width:250px; word-break:break-word;"><?= htmlspecialchars(mb_strimwidth((string)$r['new_value'], 0, 200, '…')) ?></td>
                        <td class="small"><?= htmlspecialchars($r['changed_by_name'] ?? 'N/A') ?></td>
                        <td class="small"><?= date('d M Y H:i', strtotime($r['changed_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPagination($totalRows, $perPage, $page, $_GET); ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
