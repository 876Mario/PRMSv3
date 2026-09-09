<?php

require_once __DIR__ . '/TableConfigurationEngine.php';

final class ColumnPreferenceService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function resolveState(int $userId, array $moduleConfig): array
    {
        $config = TableConfigurationEngine::build($moduleConfig);
        $meta = $this->loadTablePreferences($userId, $config['table_preference_key'], $config['legacy_page_identifier']);
        $columnRows = $this->loadColumnPreferenceRows($userId, $config['module']);

        if ($columnRows !== []) {
            $columnOrder = array_column($columnRows, 'column_key');
            $visibleKeys = array_values(array_map(
                static fn(array $row): string => $row['column_key'],
                array_filter($columnRows, static fn(array $row): bool => (int)$row['is_visible'] === 1)
            ));
        } else {
            $columnOrder = $this->decodeJsonArray($meta['column_order'] ?? null);
            $visibleKeys = $this->decodeJsonArray($meta['visible_columns'] ?? null);
        }

        $columnOrder = TableConfigurationEngine::normalizeOrder(
            $columnOrder,
            $config['allowed_keys'],
            $config['default_order']
        );
        $visibleKeys = TableConfigurationEngine::normalizeVisibleKeys(
            $visibleKeys,
            $config['allowed_keys'],
            $config['default_visible_keys'],
            $config['locked_keys']
        );

        [$sortColumn, $sortDirection] = TableConfigurationEngine::normalizeSort(
            $meta['default_sort_column'] ?? null,
            $meta['default_sort_direction'] ?? null,
            $config['sort_map'],
            $config['default_sort_column'],
            $config['default_sort_direction']
        );

        $pageSize = TableConfigurationEngine::normalizePageSize(
            (int)($meta['page_size'] ?? $config['default_page_size']),
            $config['page_size_options'],
            $config['default_page_size']
        );

        $visibleColumns = [];
        foreach ($columnOrder as $key) {
            if (in_array($key, $visibleKeys, true) && isset($config['columns_by_key'][$key])) {
                $visibleColumns[] = $config['columns_by_key'][$key];
            }
        }

        if ($visibleColumns === []) {
            $visibleColumns = array_values(array_map(
                fn(string $key): array => $config['columns_by_key'][$key],
                $config['default_visible_keys']
            ));
        }

        return $config + [
            'column_order' => $columnOrder,
            'visible_keys' => $visibleKeys,
            'visible_columns' => $visibleColumns,
            'sort_column' => $sortColumn,
            'sort_direction' => $sortDirection,
            'page_size' => $pageSize,
        ];
    }

    public function savePreferences(int $userId, array $moduleConfig, array $payload): array
    {
        $config = TableConfigurationEngine::build($moduleConfig);

        $columnOrder = TableConfigurationEngine::normalizeOrder(
            is_array($payload['column_order'] ?? null) ? $payload['column_order'] : [],
            $config['allowed_keys'],
            $config['default_order']
        );
        $visibleKeys = TableConfigurationEngine::normalizeVisibleKeys(
            is_array($payload['visible_columns'] ?? null) ? $payload['visible_columns'] : [],
            $config['allowed_keys'],
            $config['default_visible_keys'],
            $config['locked_keys']
        );
        [$sortColumn, $sortDirection] = TableConfigurationEngine::normalizeSort(
            $payload['default_sort_column'] ?? null,
            $payload['default_sort_direction'] ?? null,
            $config['sort_map'],
            $config['default_sort_column'],
            $config['default_sort_direction']
        );
        $pageSize = TableConfigurationEngine::normalizePageSize(
            (int)($payload['page_size'] ?? $config['default_page_size']),
            $config['page_size_options'],
            $config['default_page_size']
        );

        $startedTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $this->replaceColumnPreferences($userId, $config['module'], $columnOrder, $visibleKeys);
            $this->upsertTablePreferences(
                $userId,
                $config['table_preference_key'],
                $columnOrder,
                $visibleKeys,
                $sortColumn !== '' ? $sortColumn : null,
                $sortDirection,
                $pageSize
            );

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->resolveState($userId, $config);
    }

    public function resetPreferences(int $userId, array $moduleConfig): void
    {
        $config = TableConfigurationEngine::build($moduleConfig);

        try {
            $stmt = $this->pdo->prepare('DELETE FROM user_column_preferences WHERE user_id = ? AND module_name = ?');
            $stmt->execute([$userId, $config['module']]);
        } catch (Throwable $e) {
            // fall back to table preferences only
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM user_table_preferences WHERE user_id = ? AND page_identifier IN (?, ?)');
            $stmt->execute([$userId, $config['table_preference_key'], $config['legacy_page_identifier']]);
        } catch (Throwable $e) {
            // preferences table may not exist before migration
        }
    }

    private function loadColumnPreferenceRows(int $userId, string $module): array
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT column_key, is_visible, display_order
                FROM user_column_preferences
                WHERE user_id = ? AND module_name = ?
                ORDER BY display_order ASC, id ASC
            ');
            $stmt->execute([$userId, $module]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loadTablePreferences(int $userId, string $primaryKey, string $legacyKey): array
    {
        $keys = array_values(array_unique(array_filter([$primaryKey, $legacyKey], static fn(string $key): bool => $key !== '')));
        if ($keys === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->pdo->prepare("
                SELECT page_identifier, visible_columns, column_order, default_sort_column, default_sort_direction, page_size
                FROM user_table_preferences
                WHERE user_id = ? AND page_identifier IN ({$placeholders})
                ORDER BY CASE WHEN page_identifier = ? THEN 0 ELSE 1 END
                LIMIT 1
            ");
            $params = array_merge([$userId], $keys, [$primaryKey]);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function replaceColumnPreferences(int $userId, string $module, array $columnOrder, array $visibleKeys): void
    {
        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM user_column_preferences WHERE user_id = ? AND module_name = ?');
            $deleteStmt->execute([$userId, $module]);

            $visibleLookup = array_fill_keys($visibleKeys, true);
            $insertStmt = $this->pdo->prepare('
                INSERT INTO user_column_preferences
                    (user_id, module_name, column_key, is_visible, display_order, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ');

            foreach ($columnOrder as $index => $key) {
                $insertStmt->execute([
                    $userId,
                    $module,
                    $key,
                    isset($visibleLookup[$key]) ? 1 : 0,
                    $index + 1,
                ]);
            }
        } catch (Throwable $e) {
            if (!$this->isMissingPreferencesTableError($e)) {
                throw $e;
            }
        }
    }

    private function upsertTablePreferences(
        int $userId,
        string $pageIdentifier,
        array $columnOrder,
        array $visibleKeys,
        ?string $sortColumn,
        string $sortDirection,
        int $pageSize
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_table_preferences
                    (user_id, page_identifier, visible_columns, column_order, default_sort_column, default_sort_direction, page_size)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    visible_columns = VALUES(visible_columns),
                    column_order = VALUES(column_order),
                    default_sort_column = VALUES(default_sort_column),
                    default_sort_direction = VALUES(default_sort_direction),
                    page_size = VALUES(page_size),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $userId,
                $pageIdentifier,
                json_encode(array_values($visibleKeys)),
                json_encode(array_values($columnOrder)),
                $sortColumn,
                $sortDirection,
                $pageSize,
            ]);
        } catch (Throwable $e) {
            if (!$this->isMissingPreferencesTableError($e)) {
                throw $e;
            }
        }
    }

    private function isMissingPreferencesTableError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $code = (string)$e->getCode();

        return strpos($code, '42S02') !== false
            || strpos($code, '1146') !== false
            || str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist');
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
