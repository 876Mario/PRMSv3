<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/services/SignedRequestNoticeService.php';

final class SignedRequestNoticeServiceTestPdo extends PDO
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
            'ON DUPLICATE KEY UPDATE config_value = config_value',
            'ON CONFLICT(config_key) DO NOTHING',
            $query
        );

        return parent::prepare($query, $options);
    }
}

class SignedRequestNoticeServiceTest extends PHPUnit\Framework\TestCase
{
    public function testSeedDefaultSettingsCopiesLegacyUploadSettingValue(): void
    {
        $pdo = new SignedRequestNoticeServiceTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO system_config (config_key, config_value, description, created_at)
             VALUES (?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([
            SignedRequestNoticeService::UPLOAD_NOTICE_KEY,
            '0',
            'Legacy upload notice setting',
        ]);

        SignedRequestNoticeService::seedDefaultSettings($pdo);

        $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
        $stmt->execute([SignedRequestNoticeService::SUBMIT_TO_PROCUREMENT_CONFIRMATION_KEY]);

        $this->assertSame('0', $stmt->fetchColumn());
    }

    public function testConfirmationSettingMigrationUpdatesExistingValueFromInsertedValue(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/migrations/2026_09_08_confirmation_submit_to_procurement_setting.sql'
        );

        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);',
            $source
        );
    }
}
