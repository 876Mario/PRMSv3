<?php
/**
 * /admin/email_notifications.php
 * ===============================
 * Admin-only screen for configuring automated workflow email notifications:
 * enable/disable per event, choose recipient roles/users, customise
 * subject/body templates (with placeholder preview), and send a test email.
 *
 * Reuses the existing notification/email infrastructure via
 * services/EmailNotificationConfigService.php - no parallel framework.
 */
$REQUIRE_PERMISSION = 'manage_email_notifications';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/services/EmailNotificationConfigService.php';

$canManage = in_array($_SESSION['role_name'] ?? '', ['Admin', 'SuperAdmin'], true);
if (!$canManage) {
    pop('Access denied. Only Admin/SuperAdmin can manage email notifications.', '/dashboard/index.php', 1500, 'error');
    exit;
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';

/* Sample placeholder data used for template preview / test email */
$sampleData = [
    'request_number'      => 'PR-2026-0001',
    'request_description' => 'Sample procurement description',
    'requester_name'      => 'Jane Requester',
    'vendor_name'         => 'Acme Supplies Ltd.',
    'current_status'      => 'SUBMITTED',
    'required_action'     => 'Review and approve',
    'action_link'         => rtrim(getAppUrl(), '/') . '/procurement/view.php?id=1',
    'due_date'            => date('d M Y', strtotime('+3 days')),
];

/* ─── POST handlers ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $eventKey  = trim($_POST['event_key'] ?? '');
    $event     = $eventKey !== '' ? EmailNotificationConfigService::getEvent($eventKey) : null;

    if (!$event) {
        modalPop('Error', 'Unknown notification event.', '/admin/email_notifications.php', 'error');
        exit;
    }

    /* ── Toggle enabled / update roles / update template ── */
    if ($action === 'save_settings') {
        $newEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $newSubject = trim($_POST['subject_template'] ?? '');
        $newBody    = trim($_POST['body_template'] ?? '');
        $roleIds    = array_map('intval', $_POST['role_ids'] ?? []);
        $userIds    = array_map('intval', $_POST['user_ids'] ?? []);

        try {
            $pdo->beginTransaction();

            $oldEnabled = (int)$event['is_enabled'];
            $oldSubject = (string)($event['subject_template'] ?? '');
            $oldBody    = (string)($event['body_template'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO email_notification_settings (event_key, is_enabled, subject_template, body_template, updated_by, updated_by_name, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    subject_template = VALUES(subject_template),
                    body_template = VALUES(body_template),
                    updated_by = VALUES(updated_by),
                    updated_by_name = VALUES(updated_by_name),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $eventKey,
                $newEnabled,
                $newSubject !== '' ? $newSubject : null,
                $newBody !== '' ? $newBody : null,
                $userId ?: null,
                $userName,
            ]);

            EmailNotificationConfigService::recordHistory($eventKey, 'is_enabled', (string)$oldEnabled, (string)$newEnabled, $userId, $userName);
            EmailNotificationConfigService::recordHistory($eventKey, 'subject_template', $oldSubject, $newSubject, $userId, $userName);
            EmailNotificationConfigService::recordHistory($eventKey, 'body_template', $oldBody, $newBody, $userId, $userName);

            /* Recipient roles: only active roles may be assigned (all roles are
               selectable here since the roles table has no disable flag; an
               inactive/removed role simply won't be present in the list). */
            $oldRoleIds = EmailNotificationConfigService::getRecipientRoleIds($eventKey);
            $pdo->prepare("DELETE FROM email_notification_recipient_roles WHERE event_key = ?")->execute([$eventKey]);
            if (!empty($roleIds)) {
                $roleStmt = $pdo->prepare("INSERT IGNORE INTO email_notification_recipient_roles (event_key, role_id) VALUES (?, ?)");
                foreach ($roleIds as $rid) {
                    if ($rid > 0) $roleStmt->execute([$eventKey, $rid]);
                }
            }
            sort($oldRoleIds); sort($roleIds);
            EmailNotificationConfigService::recordHistory($eventKey, 'recipient_role_ids', implode(',', $oldRoleIds), implode(',', $roleIds), $userId, $userName);

            /* Recipient specific users: only active users may be assigned */
            $oldUserIds = EmailNotificationConfigService::getRecipientUserIds($eventKey);
            $pdo->prepare("DELETE FROM email_notification_recipient_users WHERE event_key = ?")->execute([$eventKey]);
            if (!empty($userIds)) {
                $userStmt = $pdo->prepare("
                    INSERT IGNORE INTO email_notification_recipient_users (event_key, user_id)
                    SELECT ?, user_id FROM users WHERE user_id = ? AND is_active = 1
                ");
                foreach ($userIds as $uid) {
                    if ($uid > 0) $userStmt->execute([$eventKey, $uid]);
                }
            }
            sort($oldUserIds); sort($userIds);
            EmailNotificationConfigService::recordHistory($eventKey, 'recipient_user_ids', implode(',', $oldUserIds), implode(',', $userIds), $userId, $userName);

            $pdo->commit();

            logAudit($pdo, 'email_notification_settings', null, 'UPDATE', "Email notification '{$event['event_label']}' configuration updated by {$userName}");

            pop("Settings saved for '{$event['event_label']}'.", '/admin/email_notifications.php', 1500, 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('email_notifications.php save_settings error: ' . $e->getMessage());
            modalPop('Error', 'Failed to save settings.', '/admin/email_notifications.php', 'error');
        }
        exit;
    }

    /* ── Send test email to current admin ── */
    if ($action === 'send_test') {
        $subjectTemplate = trim($_POST['subject_template'] ?? '') ?: $event['default_subject'];
        $bodyTemplate    = trim($_POST['body_template'] ?? '') ?: $event['default_body'];
        $adminEmail      = $_SESSION['email'] ?? getUserEmailForTest($pdo, $userId);

        if (!$adminEmail) {
            modalPop('Error', 'No email address found for your account.', '/admin/email_notifications.php', 'error');
            exit;
        }

        $subject = '[TEST] ' . EmailNotificationConfigService::renderTemplate($subjectTemplate, $sampleData);
        $body    = EmailNotificationConfigService::renderTemplate($bodyTemplate, $sampleData);

        try {
            $sent = sendMail($adminEmail, $subject, $body);
            $pdo->prepare("
                INSERT INTO email_notification_log (event_key, request_id, recipient_user_id, recipient_email, subject, status, failure_reason, dedup_key, sent_at)
                VALUES (?, NULL, ?, ?, ?, ?, ?, NULL, NOW())
            ")->execute([$eventKey, $userId ?: null, $adminEmail, $subject, $sent ? 'SENT' : 'FAILED', $sent ? null : 'sendMail() returned false']);

            if ($sent) {
                pop("Test email sent to {$adminEmail}.", '/admin/email_notifications.php', 1800, 'success');
            } else {
                modalPop('Delivery Failed', "Test email to {$adminEmail} could not be sent. Check mail server configuration.", '/admin/email_notifications.php', 'error');
            }
        } catch (Throwable $e) {
            error_log('email_notifications.php send_test error: ' . $e->getMessage());
            modalPop('Error', 'Failed to send test email.', '/admin/email_notifications.php', 'error');
        }
        exit;
    }
}

/**
 * Small local helper: fall back to DB lookup if session doesn't carry email.
 */
function getUserEmailForTest(PDO $pdo, int $userId): ?string
{
    if ($userId <= 0) return null;
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $email = $stmt->fetchColumn();
    return $email ?: null;
}

/* ─── Data for the page ─────────────────────────────────────────────── */
$events = EmailNotificationConfigService::getEvents();

$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$activeUsers = $pdo->query("SELECT user_id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$recipientRolesByEvent = [];
$recipientUsersByEvent = [];
foreach ($events as $e) {
    $recipientRolesByEvent[$e['event_key']] = EmailNotificationConfigService::getRecipientRoleIds($e['event_key']);
    $recipientUsersByEvent[$e['event_key']] = EmailNotificationConfigService::getRecipientUserIds($e['event_key']);
}

require_once $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-envelope-gear me-2"></i>Automatic Email Notification Configuration</h4>
    <a href="/admin/email_notification_history.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i>Notification History
    </a>
</div>

<p class="text-muted">
    Configure which roles/users receive automated emails for each workflow event, and customise the
    subject/body templates. Available placeholders:
    <code><?= implode('</code> <code>', EmailNotificationConfigService::availablePlaceholders()) ?></code>
</p>

<div class="accordion" id="eventsAccordion">
<?php foreach ($events as $event):
    $key = $event['event_key'];
    $selectedRoles = $recipientRolesByEvent[$key] ?? [];
    $selectedUsers = $recipientUsersByEvent[$key] ?? [];
    $effectiveSubject = $event['subject_template'] ?: $event['default_subject'];
    $effectiveBody    = $event['body_template'] ?: $event['default_body'];
?>
    <div class="accordion-item mb-2 border rounded">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ev_<?= htmlspecialchars($key) ?>">
                <span class="badge <?= $event['is_enabled'] ? 'bg-success' : 'bg-secondary' ?> me-2">
                    <?= $event['is_enabled'] ? 'Enabled' : 'Disabled' ?>
                </span>
                <?= htmlspecialchars($event['event_label']) ?>
            </button>
        </h2>
        <div id="ev_<?= htmlspecialchars($key) ?>" class="accordion-collapse collapse" data-bs-parent="#eventsAccordion">
            <div class="accordion-body">
                <p class="text-muted small"><?= htmlspecialchars($event['description'] ?? '') ?></p>
                <form method="POST">
                    <input type="hidden" name="event_key" value="<?= htmlspecialchars($key) ?>">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_enabled" id="enabled_<?= htmlspecialchars($key) ?>"
                               <?= $event['is_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled_<?= htmlspecialchars($key) ?>">Notification enabled</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Recipient Roles</label>
                        <div class="row g-1">
                            <?php foreach ($roles as $r): ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>"
                                           id="role_<?= htmlspecialchars($key) ?>_<?= $r['id'] ?>"
                                           <?= in_array((int)$r['id'], $selectedRoles, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="role_<?= htmlspecialchars($key) ?>_<?= $r['id'] ?>">
                                        <?= htmlspecialchars($r['name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Additional Specific Recipients (optional)</label>
                        <select class="form-select form-select-sm" name="user_ids[]" multiple size="4">
                            <?php foreach ($activeUsers as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= in_array((int)$u['user_id'], $selectedUsers, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Template</label>
                        <input type="text" class="form-control form-control-sm" name="subject_template"
                               value="<?= htmlspecialchars($effectiveSubject) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Body Template (HTML)</label>
                        <textarea class="form-control form-control-sm" name="body_template" rows="5"><?= htmlspecialchars($effectiveBody) ?></textarea>
                    </div>

                    <div class="d-flex justify-content-between">
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-info preview-btn"
                                    data-event="<?= htmlspecialchars($key) ?>">
                                <i class="bi bi-eye me-1"></i>Preview
                            </button>
                            <button type="submit" formaction="/admin/email_notifications.php" formmethod="POST"
                                    name="action" value="send_test"
                                    class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-send me-1"></i>Send Test Email to Me
                            </button>
                        </div>
                        <button type="submit" name="action" value="save_settings" class="btn btn-sm btn-primary">
                            <i class="bi bi-save me-1"></i>Save Settings
                        </button>
                    </div>

                    <div class="preview-box border rounded p-3 mt-3 d-none" id="preview_<?= htmlspecialchars($key) ?>">
                        <strong>Preview Subject:</strong>
                        <div class="preview-subject mb-2"></div>
                        <strong>Preview Body:</strong>
                        <iframe class="preview-body border rounded w-100 bg-light" sandbox="" style="min-height:150px;"></iframe>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
var SAMPLE_PLACEHOLDERS = <?= json_encode($sampleData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function renderPreview(template) {
    var out = template;
    Object.keys(SAMPLE_PLACEHOLDERS).forEach(function (key) {
        var token = '{{' + key + '}}';
        var value = String(SAMPLE_PLACEHOLDERS[key]);
        // Simple HTML-escape of substituted values (client-side preview only;
        // the authoritative escaping happens server-side when actually sent).
        var div = document.createElement('div');
        div.textContent = value;
        var escaped = div.innerHTML;
        out = out.split(token).join(escaped);
    });
    return out;
}

document.querySelectorAll('.preview-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var eventKey = btn.getAttribute('data-event');
        var form = btn.closest('form');
        var subjectTemplate = form.querySelector('[name="subject_template"]').value;
        var bodyTemplate = form.querySelector('[name="body_template"]').value;
        var box = document.getElementById('preview_' + eventKey);
        box.querySelector('.preview-subject').textContent = renderPreview(subjectTemplate);
        // Render the body preview inside a sandboxed iframe (no scripts,
        // no same-origin access) so a template containing markup/script
        // cannot execute in the context of the admin page.
        box.querySelector('.preview-body').srcdoc = renderPreview(bodyTemplate);
        box.classList.remove('d-none');
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
