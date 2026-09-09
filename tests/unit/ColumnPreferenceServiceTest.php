<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/column_modules.php';
require_once dirname(__DIR__, 2) . '/services/ColumnPreferenceService.php';

final class ColumnPreferenceServiceTestPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->exec("CREATE TABLE user_column_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            module_name TEXT NOT NULL,
            column_key TEXT NOT NULL,
            is_visible INTEGER NOT NULL DEFAULT 1,
            display_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->exec("CREATE UNIQUE INDEX uk_user_column_preference ON user_column_preferences (user_id, module_name, column_key)");
        $this->exec("CREATE TABLE user_table_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            page_identifier TEXT NOT NULL,
            visible_columns TEXT,
            column_order TEXT,
            default_sort_column TEXT,
            default_sort_direction TEXT NOT NULL DEFAULT 'ASC',
            page_size INTEGER NOT NULL DEFAULT 20,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->exec("CREATE UNIQUE INDEX uk_user_page ON user_table_preferences (user_id, page_identifier)");
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('NOW()', "datetime('now')", $query);
        $query = str_replace(
            "ON DUPLICATE KEY UPDATE\n                    visible_columns = VALUES(visible_columns),\n                    column_order = VALUES(column_order),\n                    default_sort_column = VALUES(default_sort_column),\n                    default_sort_direction = VALUES(default_sort_direction),\n                    page_size = VALUES(page_size),\n                    updated_at = CURRENT_TIMESTAMP",
            "ON CONFLICT(user_id, page_identifier) DO UPDATE SET
                    visible_columns = excluded.visible_columns,
                    column_order = excluded.column_order,
                    default_sort_column = excluded.default_sort_column,
                    default_sort_direction = excluded.default_sort_direction,
                    page_size = excluded.page_size,
                    updated_at = CURRENT_TIMESTAMP",
            $query
        );

        return parent::prepare($query, $options);
    }
}

final class ColumnPreferenceServiceTest extends PHPUnit\Framework\TestCase
{
    public function testSaveResolveAndResetInventoryPreferences(): void
    {
        $pdo = new ColumnPreferenceServiceTestPdo();
        $service = new ColumnPreferenceService($pdo);
        $config = getColumnPreferenceModuleConfig('inventory_items', $pdo);

        $state = $service->savePreferences(12, $config, [
            'column_order' => ['item_name', 'item_code', 'actions'],
            'visible_columns' => ['item_name'],
            'default_sort_column' => 'item_code',
            'default_sort_direction' => 'DESC',
            'page_size' => 50,
        ]);

        $this->assertSame(['item_name', 'item_code', 'actions'], array_slice($state['column_order'], 0, 3));
        $this->assertContains('actions', $state['visible_keys']);
        $this->assertSame('item_code', $state['sort_column']);
        $this->assertSame('DESC', $state['sort_direction']);
        $this->assertSame(50, $state['page_size']);

        $resolved = $service->resolveState(12, $config);
        $this->assertSame($state['visible_keys'], $resolved['visible_keys']);

        $service->resetPreferences(12, $config);
        $reset = $service->resolveState(12, $config);
        $this->assertSame($reset['default_order'], $reset['column_order']);
        $this->assertSame($reset['default_visible_keys'], $reset['visible_keys']);
    }

    public function testAdminPermissionsModuleBuildsDynamicRoleColumns(): void
    {
        $pdo = new ColumnPreferenceServiceTestPdo();
        $pdo->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)");
        $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Finance Officer')");

        $config = getColumnPreferenceModuleConfig('admin_permissions', $pdo);
        $keys = array_column($config['columns'], 'key');

        $this->assertContains('role_1', $keys);
        $this->assertContains('role_2', $keys);
        $this->assertContains('permission', $keys);
    }
}
