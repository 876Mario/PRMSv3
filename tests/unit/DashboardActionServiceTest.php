<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/DashboardActionService.php';

final class DashboardActionServiceTestPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec("CREATE TABLE system_config (
            config_key TEXT PRIMARY KEY,
            config_value TEXT,
            description TEXT,
            created_at TEXT
        )");
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('NOW()', "datetime('now')", $query);
        $query = str_replace(
            "ON DUPLICATE KEY UPDATE\n                config_value = VALUES(config_value),\n                description = CASE\n                    WHEN VALUES(description) <> '' THEN VALUES(description)\n                    ELSE description\n                END",
            "ON CONFLICT(config_key) DO UPDATE SET
                config_value = excluded.config_value,
                description = CASE
                    WHEN excluded.description <> '' THEN excluded.description
                    ELSE description
                END",
            $query
        );
        $query = str_replace(
            'ON DUPLICATE KEY UPDATE config_value = config_value',
            'ON CONFLICT(config_key) DO NOTHING',
            $query
        );

        return parent::prepare($query, $options);
    }
}

final class DashboardActionServiceTest extends PHPUnit\Framework\TestCase
{
    private static function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(DashboardActionService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    public function testNormalizeNotificationLinksUsesActionUrlAsViewUrl(): void
    {
        $row = self::invokePrivate('normalizeNotificationLinks', [[
            'request_id' => 42,
            'action_url' => ' /reimbursement/view.php?id=42 ',
        ]]);

        $this->assertSame('/reimbursement/view.php?id=42', $row['view_url']);
        $this->assertSame('/reimbursement/view.php?id=42', $row['action_url']);
        $this->assertSame('Take Action', $row['action_label']);
        $this->assertSame('View Record', $row['view_label']);
    }

    public function testNormalizeNotificationLinksFallsBackToProcurementViewWhenActionUrlMissing(): void
    {
        $row = self::invokePrivate('normalizeNotificationLinks', [[
            'request_id' => 12,
            'action_url' => '',
        ]]);

        $this->assertSame('/procurement/view.php?id=12', $row['view_url']);
        $this->assertSame('/procurement/view.php?id=12', $row['action_url']);
    }

    public function testBadgesForRowUsesConfiguredHighValueFlag(): void
    {
        $highValue = self::invokePrivate('badgesForRow', [[
            'risk_level' => 'normal',
            'priority' => 'normal',
            'status_code' => 'SUBMITTED',
            'estimated_value' => 10000000,
            'is_high_value' => 1,
        ]]);
        $notHighValue = self::invokePrivate('badgesForRow', [[
            'risk_level' => 'normal',
            'priority' => 'normal',
            'status_code' => 'SUBMITTED',
            'estimated_value' => 10000000,
            'is_high_value' => 0,
        ]]);

        $highLabels = array_column($highValue, 'label');
        $normalLabels = array_column($notHighValue, 'label');

        $this->assertContains('HIGH VALUE', $highLabels);
        $this->assertNotContains('HIGH VALUE', $normalLabels);
    }

    public function testEscalationThresholdExpressionsIncludeTypeAndStatusSpecificThresholds(): void
    {
        $pdo = new DashboardActionServiceTestPdo();

        $thresholds = self::invokePrivate('escalationThresholdExpressions', [
            $pdo,
            ['REGULAR', 'PETTY_CASH'],
            ['SUBMITTED'],
        ]);

        $this->assertStringContainsString("UPPER(pr.request_type) = 'REGULAR' AND UPPER(pr.status) = 'SUBMITTED' THEN 2", $thresholds['warning']);
        $this->assertStringContainsString("UPPER(pr.request_type) = 'PETTY_CASH' AND UPPER(pr.status) = 'SUBMITTED' THEN 3", $thresholds['warning']);
        $this->assertStringContainsString("UPPER(pr.request_type) = 'PETTY_CASH' AND UPPER(pr.status) = 'SUBMITTED' THEN 5", $thresholds['critical']);
    }

    public function testBuildActionUnionSqlIncludesHighValueFlagAndThresholdCases(): void
    {
        $pdo = new DashboardActionServiceTestPdo();

        $sql = self::invokePrivate('buildActionUnionSql', [
            $pdo,
            ['dashboard_key' => 'finance', 'user_id' => 9, 'is_procurement_visibility_restricted' => false, 'role_name' => 'Finance Officer'],
            [[
                'key' => 'mixed_finance_stage',
                'source' => 'request',
                'request_types' => ['REGULAR', 'PETTY_CASH'],
                'statuses' => ['SUBMITTED', 'FUNDS_VERIFIED'],
                'action_label' => 'Action',
                'action_url_template' => '/procurement/view.php?id={request_id}',
                'view_url_template' => '/procurement/view.php?id={request_id}',
                'category' => 'Category',
                'extra_where' => null,
                'own_only' => false,
            ]],
        ]);

        $this->assertStringContainsString('AS is_high_value', $sql);
        $this->assertStringContainsString("UPPER(pr.request_type) = 'REGULAR' AND UPPER(pr.status) = 'FUNDS_VERIFIED'", $sql);
        $this->assertStringContainsString("UPPER(pr.request_type) = 'PETTY_CASH' AND UPPER(pr.status) = 'SUBMITTED'", $sql);
    }
}
