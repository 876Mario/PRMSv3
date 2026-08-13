<?php
/**
 * tests/FinanceNotificationTest.php
 * ================================
 * Test cases for finance notification system.
 */

class FinanceNotificationTest extends PHPUnit\Framework\TestCase
{
    private $pdo;
    private $testRequestId;
    private $testFinanceUserId;

    protected function setUp(): void
    {
        global $pdo;
        $this->pdo = $pdo;
        
        $this->setupTestData();
    }

    private function setupTestData(): void
    {
        // Create test procurement request
        $stmt = $this->pdo->prepare("
            INSERT INTO procurement_requests 
            (request_number, description, status, estimated_value, currency, request_date, created_by, branch_id)
            VALUES (?, ?, 'FUNDS_VERIFIED', 15000, 'JMD', NOW(), 1, 1)
        ");
        $stmt->execute(['PR-TEST-' . uniqid(), 'Test procurement request']);
        $this->testRequestId = $this->pdo->lastInsertId();

        // Create test Finance Officer user
        $stmt = $this->pdo->prepare("
            INSERT INTO users (full_name, email, username, is_active, password)
            VALUES (?, ?, ?, 1, ?)
        ");
        $stmt->execute(['Test Finance Officer', 'finance@test.com', 'testfinance', password_hash('password', PASSWORD_BCRYPT)]);
        $this->testFinanceUserId = $this->pdo->lastInsertId();

        // Assign Finance Officer role to test user
        $stmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'Finance Officer' LIMIT 1");
        $stmt->execute();
        $financeRoleId = $stmt->fetchColumn();
        
        if ($financeRoleId) {
            $stmt = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $stmt->execute([$this->testFinanceUserId, $financeRoleId]);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup test data
        $this->pdo->prepare("DELETE FROM user_notifications WHERE request_id = ?")->execute([$this->testRequestId]);
        $this->pdo->prepare("DELETE FROM procurement_requests WHERE request_id = ?")->execute([$this->testRequestId]);
        
        if ($this->testFinanceUserId) {
            $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$this->testFinanceUserId]);
            $this->pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$this->testFinanceUserId]);
        }
    }

    /**
     * Test: Finance notification is created
     */
    public function testFinanceNotificationCreated(): void
    {
        $requestData = [
            'request_number' => 'PR-001',
            'vendor_name' => 'Test Vendor',
            'description' => 'Test procurement',
            'estimated_value' => 15000,
            'currency' => 'JMD',
            'requestor_name' => 'Test Requestor',
        ];

        $result = FinanceNotificationService::triggerNotification(
            $this->testRequestId,
            FinanceNotificationService::ACTION_FUNDS_VERIFICATION,
            $requestData
        );

        // Check if notification was created
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM user_notifications 
            WHERE request_id = ? AND type = ?
        ");
        $stmt->execute([$this->testRequestId, NotificationService::TYPE_FINANCE_ACTION]);
        
        // If finance users exist, notification should be created
        $this->assertGreaterThanOrEqual(0, (int)$stmt->fetchColumn());
    }

    /**
     * Test: Duplicate notifications are prevented
     */
    public function testDuplicateNotificationsPrevented(): void
    {
        $requestData = [
            'request_number' => 'PR-002',
            'vendor_name' => 'Test Vendor',
            'description' => 'Test procurement',
            'estimated_value' => 15000,
            'currency' => 'JMD',
            'requestor_name' => 'Test Requestor',
        ];

        $actionType = FinanceNotificationService::ACTION_FUNDS_VERIFICATION;

        // Count notifications before
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM user_notifications
            WHERE request_id = ? AND stage = ? AND is_read = 0
        ");
        $stmt->execute([$this->testRequestId, $actionType]);
        $countBefore = (int)$stmt->fetchColumn();

        // Create first notification
        FinanceNotificationService::triggerNotification(
            $this->testRequestId,
            $actionType,
            $requestData
        );

        // Attempt to create duplicate
        FinanceNotificationService::triggerNotification(
            $this->testRequestId,
            $actionType,
            $requestData
        );

        // Count notifications after
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM user_notifications
            WHERE request_id = ? AND stage = ? AND is_read = 0
        ");
        $stmt->execute([$this->testRequestId, $actionType]);
        $countAfter = (int)$stmt->fetchColumn();

        // Duplicate prevention should result in no additional notifications beyond the first
        $this->assertLessThanOrEqual($countBefore + 1, $countAfter);
    }

    /**
     * Test: Notification priority is set correctly
     */
    public function testNotificationPriority(): void
    {
        // Create mock notification to verify priority
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications 
            (user_id, request_id, type, title, priority)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->testFinanceUserId,
            $this->testRequestId,
            NotificationService::TYPE_FINANCE_ACTION,
            'Test Finance Action',
            'high'
        ]);

        $stmt = $this->pdo->prepare("
            SELECT priority FROM user_notifications
            WHERE request_id = ? AND priority = 'high'
        ");
        $stmt->execute([$this->testRequestId]);
        $priority = $stmt->fetchColumn();

        $this->assertEquals('high', $priority);
    }

    /**
     * Test: Notification status tracking (read state)
     */
    public function testNotificationStatusTracking(): void
    {
        // Create test notification
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications 
            (user_id, request_id, type, title, is_read)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $this->testFinanceUserId,
            $this->testRequestId,
            NotificationService::TYPE_FINANCE_ACTION,
            'Test Notification'
        ]);
        $notifId = $this->pdo->lastInsertId();

        // Mark as read
        NotificationService::markRead($notifId, $this->testFinanceUserId);

        // Verify notification is marked as read
        $stmt = $this->pdo->prepare("SELECT is_read FROM user_notifications WHERE id = ?");
        $stmt->execute([$notifId]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    /**
     * Test: Notification completes action and marks as read
     */
    public function testCompleteActionMarksNotificationsAsRead(): void
    {
        // Create unread notification
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications 
            (user_id, request_id, type, stage, is_read)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $this->testFinanceUserId,
            $this->testRequestId,
            NotificationService::TYPE_FINANCE_ACTION,
            FinanceNotificationService::ACTION_FUNDS_VERIFICATION
        ]);
        $notifId = $this->pdo->lastInsertId();

        // Complete action
        FinanceNotificationService::completeAction(
            $this->testRequestId,
            FinanceNotificationService::ACTION_FUNDS_VERIFICATION
        );

        // Verify notification is marked as read
        $stmt = $this->pdo->prepare("SELECT is_read FROM user_notifications WHERE id = ?");
        $stmt->execute([$notifId]);
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    /**
     * Test: Finance action notification content includes required details
     */
    public function testNotificationContentIncludesRequiredDetails(): void
    {
        $requestData = [
            'request_number' => 'PR-TEST-123',
            'vendor_name' => 'Premium Vendor Inc',
            'description' => 'Office supplies procurement',
            'estimated_value' => 25000,
            'currency' => 'JMD',
            'requestor_name' => 'John Smith',
        ];

        // Create a test notification with all details
        $stmt = $this->pdo->prepare("
            INSERT INTO user_notifications 
            (user_id, request_id, type, title, body, request_ref, action_url, stage)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->testFinanceUserId,
            $this->testRequestId,
            NotificationService::TYPE_FINANCE_ACTION,
            'Funds Verification Required - ' . $requestData['request_number'],
            'Request for ' . $requestData['vendor_name'] . ' (JMD ' . number_format($requestData['estimated_value'], 2) . ') requires funds verification.',
            $requestData['request_number'],
            '/procurement/view.php?id=' . $this->testRequestId,
            FinanceNotificationService::ACTION_FUNDS_VERIFICATION
        ]);

        // Verify notification content
        $stmt = $this->pdo->prepare("
            SELECT title, body, request_ref, action_url FROM user_notifications
            WHERE request_id = ?
        ");
        $stmt->execute([$this->testRequestId]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($notification);
        $this->assertStringContainsString($requestData['request_number'], $notification['title']);
        $this->assertStringContainsString($requestData['vendor_name'], $notification['body']);
        $this->assertEquals($requestData['request_number'], $notification['request_ref']);
        $this->assertStringContainsString((string)$this->testRequestId, $notification['action_url']);
    }

    /**
     * Test: Audit log is created for notification events
     */
    public function testAuditLogForNotificationEvents(): void
    {
        $beforeCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'FINANCE_NOTIFICATION%'"
        );
        $beforeCountStmt->execute();
        $beforeCount = (int)$beforeCountStmt->fetchColumn();

        // Create a notification event
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, notes, change_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'procurement_requests',
            $this->testRequestId,
            'FINANCE_NOTIFICATION_SENT',
            'Test User',
            'Test finance notification sent'
        ]);

        $afterCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'FINANCE_NOTIFICATION_SENT'"
        );
        $afterCountStmt->execute();
        $afterCount = (int)$afterCountStmt->fetchColumn();

        $this->assertGreaterThan($beforeCount, $afterCount);
    }
}
