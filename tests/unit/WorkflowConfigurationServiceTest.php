<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/WorkflowConfigurationService.php';

final class WorkflowConfigurationServiceTestPdo extends PDO
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

final class WorkflowConfigurationServiceTest extends PHPUnit\Framework\TestCase
{
    public function testSeedDefaultsAndEscalationSnapshot(): void
    {
        $pdo = new WorkflowConfigurationServiceTestPdo();
        WorkflowConfigurationService::seedDefaults($pdo);

        $settings = WorkflowConfigurationService::readSettings($pdo);
        $this->assertArrayHasKey('high_value_petty_cash_threshold', $settings);
        $this->assertArrayHasKey('rfq_vendor_assignment_sla_days', $settings);

        $snapshot = WorkflowConfigurationService::getEscalationSnapshot($pdo, 'REGULAR', 'PROCUREMENT_STAGE', 3, 4500000);
        $this->assertSame('critical', $snapshot['risk_level']);
        $this->assertSame('urgent', $snapshot['priority']);
        $this->assertSame(2, $snapshot['sla_days']);
    }

    public function testPurchaseOrderOversightLevelUsesConfigurableThresholds(): void
    {
        $pdo = new WorkflowConfigurationServiceTestPdo();
        WorkflowConfigurationService::seedDefaults($pdo);
        $pdo->prepare('UPDATE system_config SET config_value = ? WHERE config_key = ?')->execute(['2500000', 'high_value_purchase_order_threshold']);
        $pdo->prepare('UPDATE system_config SET config_value = ? WHERE config_key = ?')->execute(['5000000', 'finance_escalation_threshold']);
        $pdo->prepare('UPDATE system_config SET config_value = ? WHERE config_key = ?')->execute(['9000000', 'executive_oversight_threshold']);

        $this->assertSame('director_procurement', WorkflowConfigurationService::getPurchaseOrderOversightLevel($pdo, 3000000));
        $this->assertSame('director_finance', WorkflowConfigurationService::getPurchaseOrderOversightLevel($pdo, 6000000));
        $this->assertSame('executive', WorkflowConfigurationService::getPurchaseOrderOversightLevel($pdo, 10000000));
    }
}
