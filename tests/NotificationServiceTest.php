<?php
/**
 * NotificationServiceTest
 * ========================
 * Tests for NotificationService using a PDO SQLite :memory: stub.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../services/NotificationService.php';

$passed = 0;
$failed = 0;

function nsAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

/* ─── Set up in-memory SQLite stub ─────────────────────────────────── */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Minimal schema matching migration 024
$pdo->exec("
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT,
        email TEXT,
        is_active INTEGER DEFAULT 1
    );
    INSERT INTO users VALUES (1,'Alice','alice@test.com',1);

    CREATE TABLE user_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        request_id INTEGER,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT,
        request_ref TEXT,
        action_url TEXT,
        stage TEXT,
        requestor_name TEXT,
        priority TEXT DEFAULT 'normal',
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME
    );
");

// Inject the stub $pdo into the global scope (NotificationService uses global $pdo)
$GLOBALS['pdo'] = $pdo;

echo "\n=== NotificationServiceTest ===\n";

/* 1. createNotification with valid data */
$ok = NotificationService::createNotification(1, NotificationService::TYPE_APPROVAL_NEEDED, [
    'title'       => 'Approve PR-001',
    'body'        => 'Please review',
    'request_id'  => 42,
    'request_ref' => 'PR-001',
    'action_url'  => '/procurement/approve.php?id=42',
    'stage'       => 'HOD_APPROVED',
    'priority'    => 'high',
]);
nsAssert('createNotification returns true for valid data', $ok);

/* 2. countUnread returns 1 */
nsAssert('countUnread returns 1 after one notification', NotificationService::countUnread(1) === 1);

/* 3. getUnread returns the notification */
$unread = NotificationService::getUnread(1);
nsAssert('getUnread returns array with 1 item', count($unread) === 1);
nsAssert('getUnread item has correct title', ($unread[0]['title'] ?? '') === 'Approve PR-001');
nsAssert('getUnread item has correct request_ref', ($unread[0]['request_ref'] ?? '') === 'PR-001');
nsAssert('getUnread item has priority=high', ($unread[0]['priority'] ?? '') === 'high');

/* 4. markRead removes from unread */
$notifId = (int)$unread[0]['id'];
NotificationService::markRead($notifId, 1);
nsAssert('countUnread returns 0 after markRead', NotificationService::countUnread(1) === 0);
nsAssert('getUnread returns empty after markRead', count(NotificationService::getUnread(1)) === 0);

/* 5. getAll still shows the notification as read */
$all = NotificationService::getAll(1, 10);
nsAssert('getAll returns 1 item (read)', count($all) === 1);
nsAssert('getAll item has is_read=1', (int)($all[0]['is_read'] ?? 0) === 1);

/* 6. markAllRead with multiple notifications */
NotificationService::createNotification(1, NotificationService::TYPE_REJECTION, [
    'title' => 'Request Declined',
]);
NotificationService::createNotification(1, NotificationService::TYPE_SUBMISSION, [
    'title' => 'Request Submitted',
]);
nsAssert('countUnread is 2 before markAllRead', NotificationService::countUnread(1) === 2);
NotificationService::markAllRead(1);
nsAssert('countUnread is 0 after markAllRead', NotificationService::countUnread(1) === 0);

/* 7. Invalid type is rejected */
$badOk = NotificationService::createNotification(1, 'unknown_type', ['title' => 'Bad']);
nsAssert('createNotification returns false for unknown type', $badOk === false);

/* 8. Missing title is rejected */
$noTitle = NotificationService::createNotification(1, NotificationService::TYPE_CANCELLATION, ['body' => 'oops']);
nsAssert('createNotification returns false when title is empty', $noTitle === false);

/* 9. Invalid userId is rejected */
$badUser = NotificationService::createNotification(0, NotificationService::TYPE_APPROVAL_NEEDED, ['title' => 'test']);
nsAssert('createNotification returns false for userId=0', $badUser === false);

/* 10. markRead for wrong user_id does nothing */
NotificationService::createNotification(1, NotificationService::TYPE_DRAFT_READY, ['title' => 'Draft done']);
nsAssert('countUnread is 1 for user 1', NotificationService::countUnread(1) === 1);
$unread2 = NotificationService::getUnread(1);
$id2 = (int)($unread2[0]['id'] ?? 0);
NotificationService::markRead($id2, 99); // wrong user
nsAssert('markRead for wrong user does not mark as read', NotificationService::countUnread(1) === 1);

/* 11. getAll limit is respected */
for ($i = 0; $i < 5; $i++) {
    NotificationService::createNotification(1, NotificationService::TYPE_APPROVAL_NEEDED, ['title' => "N{$i}"]);
}
$limited = NotificationService::getAll(1, 3);
nsAssert('getAll respects limit=3', count($limited) === 3);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
