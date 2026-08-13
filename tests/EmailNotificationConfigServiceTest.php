<?php
/**
 * EmailNotificationConfigServiceTest
 * ===================================
 * Tests for EmailNotificationConfigService using a PDO SQLite :memory: stub,
 * following the same pattern as tests/NotificationServiceTest.php.
 *
 * Covers:
 *  - enable/disable gating
 *  - dynamic recipient resolution (active users/roles only; inactive excluded)
 *  - template rendering with placeholder substitution + HTML escaping
 *  - duplicate/excessive notification prevention (dedup window)
 *  - successful and failed delivery logging
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

/* Stub sendMail() so the service can be tested without a real SMTP server. */
$GLOBALS['__stub_mail_should_fail'] = false;
$GLOBALS['__stub_mail_sent_log'] = [];
function sendMail(string $to, string $subject, string $html): bool
{
    $GLOBALS['__stub_mail_sent_log'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];
    return !$GLOBALS['__stub_mail_should_fail'];
}

require_once __DIR__ . '/../services/EmailNotificationConfigService.php';

$passed = 0;
$failed = 0;

function encAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

/* ─── Set up in-memory SQLite stub matching the migration schema ──────── */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO roles VALUES (1, 'Admin'), (2, 'HOD'), (3, 'Branch Head');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT,
        email TEXT,
        role_id INTEGER,
        is_active INTEGER DEFAULT 1
    );
    INSERT INTO users VALUES (1, 'Active HOD', 'hod@test.com', 2, 1);
    INSERT INTO users VALUES (2, 'Inactive HOD', 'inactive-hod@test.com', 2, 0);
    INSERT INTO users VALUES (3, 'Extra User', 'extra@test.com', 1, 1);

    CREATE TABLE email_notification_events (
        event_key TEXT PRIMARY KEY,
        event_label TEXT,
        description TEXT,
        default_subject TEXT,
        default_body TEXT,
        sort_order INTEGER DEFAULT 0
    );
    INSERT INTO email_notification_events VALUES (
        'REQUEST_SUBMITTED', 'New request submitted', 'desc',
        'New Request - {{request_number}}',
        '<p>Hi {{requester_name}}, request {{request_number}} needs {{required_action}}. <a href=\"{{action_link}}\">Open</a></p>',
        10
    );
    INSERT INTO email_notification_events VALUES (
        'DISABLED_EVENT', 'Disabled event', 'desc', 'Subj', 'Body', 20
    );

    CREATE TABLE email_notification_settings (
        event_key TEXT PRIMARY KEY,
        is_enabled INTEGER DEFAULT 1,
        subject_template TEXT,
        body_template TEXT,
        updated_by INTEGER,
        updated_by_name TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO email_notification_settings (event_key, is_enabled) VALUES ('REQUEST_SUBMITTED', 1);
    INSERT INTO email_notification_settings (event_key, is_enabled) VALUES ('DISABLED_EVENT', 0);

    CREATE TABLE email_notification_recipient_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_key TEXT,
        role_id INTEGER
    );
    INSERT INTO email_notification_recipient_roles (event_key, role_id) VALUES ('REQUEST_SUBMITTED', 2);

    CREATE TABLE email_notification_recipient_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_key TEXT,
        user_id INTEGER
    );

    CREATE TABLE email_notification_config_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_key TEXT,
        field_changed TEXT,
        old_value TEXT,
        new_value TEXT,
        changed_by INTEGER,
        changed_by_name TEXT,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE email_notification_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_key TEXT,
        request_id INTEGER,
        recipient_user_id INTEGER,
        recipient_email TEXT,
        subject TEXT,
        status TEXT,
        failure_reason TEXT,
        dedup_key TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

$GLOBALS['pdo'] = $pdo;

echo "\n=== EmailNotificationConfigServiceTest ===\n";

/* 1. isEventEnabled() */
encAssert('REQUEST_SUBMITTED is enabled', EmailNotificationConfigService::isEventEnabled('REQUEST_SUBMITTED') === true);
encAssert('DISABLED_EVENT is disabled', EmailNotificationConfigService::isEventEnabled('DISABLED_EVENT') === false);
encAssert('Unknown event defaults to enabled', EmailNotificationConfigService::isEventEnabled('UNKNOWN_EVENT') === true);

/* 2. resolveRecipients() - only active users in configured roles */
$recipients = EmailNotificationConfigService::resolveRecipients('REQUEST_SUBMITTED');
$recipientEmails = array_column($recipients, 'email');
encAssert('resolveRecipients includes active HOD', in_array('hod@test.com', $recipientEmails, true));
encAssert('resolveRecipients excludes inactive HOD', !in_array('inactive-hod@test.com', $recipientEmails, true));
encAssert('resolveRecipients excludes users outside configured roles', !in_array('extra@test.com', $recipientEmails, true));

/* 3. renderTemplate() - placeholder substitution + escaping */
$rendered = EmailNotificationConfigService::renderTemplate(
    'Hello {{requester_name}}, please <script>alert(1)</script> visit {{action_link}}',
    ['requester_name' => 'Jane <b>Doe</b>', 'action_link' => 'http://example.com/?a=1&b=2']
);
encAssert('renderTemplate substitutes placeholders', strpos($rendered, 'Jane') !== false);
encAssert('renderTemplate escapes HTML in placeholder values', strpos($rendered, '&lt;b&gt;Doe&lt;/b&gt;') !== false);
encAssert('renderTemplate does not leave raw <b> tag from substitution', strpos($rendered, '<b>Doe</b>') === false);

/* 3b. renderTemplate() with isHtml=false (subject line) does not HTML-encode */
$subjectRendered = EmailNotificationConfigService::renderTemplate(
    'Update for {{requester_name}}',
    ['requester_name' => 'Jane & Doe'],
    false
);
encAssert('renderTemplate(isHtml=false) leaves plain text unescaped for subjects', $subjectRendered === 'Update for Jane & Doe');

/* 4. dispatch() - disabled event is skipped, no mail sent */
$GLOBALS['__stub_mail_sent_log'] = [];
$result = EmailNotificationConfigService::dispatch('DISABLED_EVENT', [], 100);
encAssert('dispatch on disabled event reports skipped_disabled', $result['skipped_disabled'] === true);
encAssert('dispatch on disabled event sends no mail', count($GLOBALS['__stub_mail_sent_log']) === 0);

/* 5. dispatch() - enabled event sends to resolved recipients */
$GLOBALS['__stub_mail_should_fail'] = false;
$GLOBALS['__stub_mail_sent_log'] = [];
$result = EmailNotificationConfigService::dispatch('REQUEST_SUBMITTED', [
    'request_number'  => 'PR-100',
    'requester_name'  => 'Jane Requester',
    'required_action' => 'Approve',
    'action_link'     => 'http://example.com/view?id=100',
], 100);
encAssert('dispatch sends 1 email to the active HOD', $result['sent'] === 1);
encAssert('dispatch records 0 failures', $result['failed'] === 0);
encAssert('sendMail was actually invoked once', count($GLOBALS['__stub_mail_sent_log']) === 1);
encAssert('sent subject contains request number', strpos($GLOBALS['__stub_mail_sent_log'][0]['subject'], 'PR-100') !== false);

$logCount = (int)$pdo->query("SELECT COUNT(*) FROM email_notification_log WHERE status='SENT'")->fetchColumn();
encAssert('successful delivery logged in email_notification_log', $logCount === 1);

/* 6. dispatch() - duplicate suppression within dedup window */
$GLOBALS['__stub_mail_sent_log'] = [];
$result2 = EmailNotificationConfigService::dispatch('REQUEST_SUBMITTED', [
    'request_number' => 'PR-100',
], 100);
encAssert('duplicate dispatch for same event/request/recipient sends nothing new', $result2['sent'] === 0 && count($GLOBALS['__stub_mail_sent_log']) === 0);

/* 7. dispatch() - failed delivery is logged with failure reason */
$GLOBALS['__stub_mail_should_fail'] = true;
$GLOBALS['__stub_mail_sent_log'] = [];
$result3 = EmailNotificationConfigService::dispatch('REQUEST_SUBMITTED', [
    'request_number' => 'PR-200',
], 200);
encAssert('failed send is reported', $result3['failed'] === 1 && $result3['sent'] === 0);
$failedLogCount = (int)$pdo->query("SELECT COUNT(*) FROM email_notification_log WHERE status='FAILED'")->fetchColumn();
encAssert('failed delivery logged with failure_reason', $failedLogCount === 1);

/* 8. dispatch() - no recipients configured */
$GLOBALS['__stub_mail_should_fail'] = false;
$pdo->exec("DELETE FROM email_notification_recipient_roles");
$result4 = EmailNotificationConfigService::dispatch('REQUEST_SUBMITTED', ['request_number' => 'PR-300'], 300);
encAssert('dispatch with no configured recipients reports skipped_no_recipients', $result4['skipped_no_recipients'] === true);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    exit(1);
}
