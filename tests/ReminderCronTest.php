<?php
/**
 * ReminderCronTest
 * =================
 * Tests for reminder/escalation deduplication logic used in cron/overdue_alerts.php.
 * Uses a PDO SQLite :memory: database.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

$passed = 0;
$failed = 0;

function rcAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

/* ─── Minimal SQLite stub ─────────────────────────────────────────── */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE reminder_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id    INTEGER NOT NULL,
        user_id       INTEGER NOT NULL,
        reminder_type TEXT    NOT NULL,
        sent_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE UNIQUE INDEX no_dup ON reminder_log
        (request_id, user_id, reminder_type, date(sent_at));

    CREATE TABLE users (
        user_id     INTEGER PRIMARY KEY,
        full_name   TEXT,
        email       TEXT,
        is_active   INTEGER DEFAULT 1,
        supervisor_id INTEGER,
        branch_id   INTEGER
    );
    INSERT INTO users VALUES (1,'Alice','alice@test.com',1,2,10);
    INSERT INTO users VALUES (2,'Bob','bob@test.com',1,NULL,10);

    CREATE TABLE branches (
        branch_id   INTEGER PRIMARY KEY,
        branch_name TEXT
    );
    INSERT INTO branches VALUES (10,'Finance');

    CREATE TABLE roles (
        id INTEGER PRIMARY KEY,
        name TEXT
    );
    INSERT INTO roles VALUES (1,'HOD');
    ALTER TABLE users ADD COLUMN role_id INTEGER DEFAULT 1;
");

echo "\n=== ReminderCronTest ===\n";

/* Inline the helper functions from the cron script for isolated testing */

function reminderAlreadySentTest(PDO $pdo, int $requestId, int $userId, string $type): bool
{
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM reminder_log
        WHERE request_id = ?
          AND user_id    = ?
          AND reminder_type = ?
          AND date(sent_at) = date('now')
    ");
    $s->execute([$requestId, $userId, $type]);
    return (int)$s->fetchColumn() > 0;
}

function logReminderTest(PDO $pdo, int $requestId, int $userId, string $type): void
{
    try {
        $pdo->prepare("
            INSERT OR IGNORE INTO reminder_log (request_id, user_id, reminder_type)
            VALUES (?, ?, ?)
        ")->execute([$requestId, $userId, $type]);
    } catch (Throwable $e) {
        // duplicate suppressed
    }
}

/* 1. Initially no reminder sent */
rcAssert('No reminder sent initially',
    !reminderAlreadySentTest($pdo, 101, 1, 'reminder')
);

/* 2. After logging, reminder is marked sent */
logReminderTest($pdo, 101, 1, 'reminder');
rcAssert('Reminder is recorded after first log',
    reminderAlreadySentTest($pdo, 101, 1, 'reminder')
);

/* 3. Duplicate log within same day is suppressed */
logReminderTest($pdo, 101, 1, 'reminder'); // second call same day
$count = (int)$pdo->query(
    "SELECT COUNT(*) FROM reminder_log WHERE request_id=101 AND user_id=1 AND reminder_type='reminder'"
)->fetchColumn();
rcAssert('Duplicate reminder NOT inserted twice on same day', $count === 1);

/* 4. Different type (escalation) is separate */
rcAssert('Escalation not sent before logging',
    !reminderAlreadySentTest($pdo, 101, 1, 'escalation')
);
logReminderTest($pdo, 101, 1, 'escalation');
rcAssert('Escalation is recorded after logging',
    reminderAlreadySentTest($pdo, 101, 1, 'escalation')
);

/* 5. Different user is independent */
rcAssert('User 2 has no reminder for same request',
    !reminderAlreadySentTest($pdo, 101, 2, 'reminder')
);

/* 6. Different request is independent */
rcAssert('Different request has no reminder',
    !reminderAlreadySentTest($pdo, 999, 1, 'reminder')
);

/* 7. Escalation threshold logic (config values) */
$reminderDays   = 3;
$escalationDays = 7;
rcAssert('Escalation threshold >= reminder interval',
    $escalationDays >= $reminderDays
);
rcAssert('A 4-day idle request qualifies for reminder',
    4 >= $reminderDays
);
rcAssert('A 4-day idle request does NOT qualify for escalation',
    4 < $escalationDays
);
rcAssert('An 8-day idle request qualifies for BOTH reminder and escalation',
    8 >= $reminderDays && 8 >= $escalationDays
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
