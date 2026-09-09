<?php

final class TableConfigurationEngine
{
    public static function build(array $moduleConfig): array
    {
        $module = trim((string)($moduleConfig['module'] ?? ''));
        if ($module === '') {
            throw new InvalidArgumentException('Column preference module is required.');
        }

        $rawColumns = $moduleConfig['columns'] ?? [];
        if (!is_array($rawColumns) || $rawColumns === []) {
            throw new InvalidArgumentException("Column preference module '{$module}' has no columns.");
        }

        $columns = [];
        $seenKeys = [];
        foreach ($rawColumns as $index => $column) {
            if (!is_array($column)) {
                continue;
            }

            $key = trim((string)($column['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (isset($seenKeys[$key])) {
                throw new InvalidArgumentException("Duplicate column key '{$key}' in module '{$module}'.");
            }
            $seenKeys[$key] = true;

            $columns[] = [
                'key' => $key,
                'label' => (string)($column['label'] ?? $key),
                'locked' => !empty($column['locked']),
                'sortable' => !empty($column['sortable']),
                'sort_col' => isset($column['sort_col']) && $column['sort_col'] !== '' ? (string)$column['sort_col'] : null,
                'default_visible' => array_key_exists('default_visible', $column) ? (bool)$column['default_visible'] : true,
                'default_order' => (int)($column['default_order'] ?? ($index + 1)),
                'align' => (string)($column['align'] ?? ''),
            ];
        }

        usort($columns, static fn(array $a, array $b): int => $a['default_order'] <=> $b['default_order']);

        $columnsByKey = [];
        $defaultOrder = [];
        $lockedKeys = [];
        $defaultVisibleKeys = [];
        $sortMap = [];

        foreach ($columns as $column) {
            $key = $column['key'];
            $columnsByKey[$key] = $column;
            $defaultOrder[] = $key;

            if ($column['locked']) {
                $lockedKeys[] = $key;
            }
            if ($column['default_visible'] || $column['locked']) {
                $defaultVisibleKeys[] = $key;
            }
            if ($column['sortable'] && $column['sort_col']) {
                $sortMap[$key] = $column['sort_col'];
            }
        }

        $defaultSortColumn = (string)($moduleConfig['default_sort_column'] ?? '');
        if ($defaultSortColumn === '' || !isset($sortMap[$defaultSortColumn])) {
            $defaultSortColumn = array_key_first($sortMap) ?? '';
        }

        $defaultSortDirection = strtoupper((string)($moduleConfig['default_sort_direction'] ?? 'ASC'));
        if (!in_array($defaultSortDirection, ['ASC', 'DESC'], true)) {
            $defaultSortDirection = 'ASC';
        }

        $pageSizeOptions = array_values(array_unique(array_map('intval', $moduleConfig['page_size_options'] ?? [10, 20, 50, 100, 200])));
        $pageSizeOptions = array_values(array_filter($pageSizeOptions, static fn(int $size): bool => $size > 0));
        if ($pageSizeOptions === []) {
            $pageSizeOptions = [10, 20, 50, 100, 200];
        }
        sort($pageSizeOptions);

        $defaultPageSize = (int)($moduleConfig['default_page_size'] ?? $pageSizeOptions[0]);
        if (!in_array($defaultPageSize, $pageSizeOptions, true)) {
            $defaultPageSize = $pageSizeOptions[0];
        }

        return [
            'module' => $module,
            'title' => (string)($moduleConfig['title'] ?? 'Manage Columns'),
            'description' => (string)($moduleConfig['description'] ?? ''),
            'button_label' => (string)($moduleConfig['button_label'] ?? 'Manage Columns'),
            'table_preference_key' => (string)($moduleConfig['table_preference_key'] ?? $module),
            'legacy_page_identifier' => (string)($moduleConfig['legacy_page_identifier'] ?? ($moduleConfig['table_preference_key'] ?? $module)),
            'save_endpoint' => (string)($moduleConfig['save_endpoint'] ?? '/api/column_preferences.php'),
            'columns' => $columns,
            'columns_by_key' => $columnsByKey,
            'allowed_keys' => array_keys($columnsByKey),
            'default_order' => $defaultOrder,
            'locked_keys' => $lockedKeys,
            'default_visible_keys' => $defaultVisibleKeys,
            'sort_map' => $sortMap,
            'default_sort_column' => $defaultSortColumn,
            'default_sort_direction' => $defaultSortDirection,
            'page_size_options' => $pageSizeOptions,
            'default_page_size' => $defaultPageSize,
        ];
    }

    public static function normalizeOrder(array $candidate, array $allowedKeys, array $defaultOrder): array
    {
        $allowedLookup = array_fill_keys($allowedKeys, true);
        $normalized = [];
        foreach ($candidate as $key) {
            $key = (string)$key;
            if (isset($allowedLookup[$key]) && !in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        foreach ($defaultOrder as $key) {
            if (!in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }

    public static function normalizeVisibleKeys(array $candidate, array $allowedKeys, array $defaultVisibleKeys, array $lockedKeys): array
    {
        $allowedLookup = array_fill_keys($allowedKeys, true);
        $normalized = [];
        foreach ($candidate as $key) {
            $key = (string)$key;
            if (isset($allowedLookup[$key]) && !in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        if ($normalized === []) {
            $normalized = $defaultVisibleKeys;
        }

        foreach ($lockedKeys as $key) {
            if (!in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized;
    }

    public static function normalizeSort(?string $sortColumn, ?string $sortDirection, array $sortMap, string $defaultSortColumn, string $defaultSortDirection): array
    {
        $column = (string)$sortColumn;
        if ($column === '' || !isset($sortMap[$column])) {
            $column = $defaultSortColumn;
        }

        $direction = strtoupper((string)$sortDirection);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = $defaultSortDirection;
        }

        return [$column, $direction];
    }

    public static function normalizePageSize(int $pageSize, array $allowedSizes, int $defaultSize): int
    {
        return in_array($pageSize, $allowedSizes, true) ? $pageSize : $defaultSize;
    }
}
