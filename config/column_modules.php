<?php

function getColumnPreferenceModuleConfig(string $module, ?PDO $pdo = null): ?array
{
    $module = strtolower(trim($module));

    if ($module === 'inventory_items') {
        return [
            'module' => 'inventory_items',
            'title' => 'Manage Inventory Columns',
            'description' => 'Show, hide, search, and reorder inventory item columns.',
            'button_label' => 'Manage Columns',
            'table_preference_key' => 'inventory_items_list',
            'legacy_page_identifier' => 'inventory_items_list',
            'default_sort_column' => 'item_name',
            'default_sort_direction' => 'ASC',
            'default_page_size' => 20,
            'page_size_options' => [10, 20, 50, 100, 200],
            'columns' => [
                ['key' => 'item_code', 'label' => 'Code', 'sortable' => true, 'sort_col' => 'i.item_code', 'default_order' => 1],
                ['key' => 'item_name', 'label' => 'Item Name', 'sortable' => true, 'sort_col' => 'i.item_name', 'default_order' => 2],
                ['key' => 'item_domain', 'label' => 'Domain', 'sortable' => true, 'sort_col' => 'i.item_domain', 'default_order' => 3],
                ['key' => 'category_name', 'label' => 'Category', 'sortable' => true, 'sort_col' => 'c.category_name', 'default_order' => 4],
                ['key' => 'manufacturer', 'label' => 'Manufacturer / Model', 'sortable' => true, 'sort_col' => 'i.manufacturer', 'default_order' => 5],
                ['key' => 'uom_code', 'label' => 'UOM', 'default_order' => 6],
                ['key' => 'total_stock', 'label' => 'On Hand', 'sortable' => true, 'sort_col' => 'total_stock', 'align' => 'end', 'default_order' => 7],
                ['key' => 'available_stock', 'label' => 'Available', 'sortable' => true, 'sort_col' => 'available_stock', 'align' => 'end', 'default_order' => 8],
                ['key' => 'average_cost', 'label' => 'Avg Cost', 'sortable' => true, 'sort_col' => 'i.average_cost', 'align' => 'end', 'default_order' => 9],
                ['key' => 'item_status', 'label' => 'Status', 'sortable' => true, 'sort_col' => 'i.item_status', 'default_order' => 10],
                ['key' => 'criticality_name', 'label' => 'Criticality', 'sortable' => true, 'sort_col' => 'cr.criticality_name', 'default_order' => 11],
                ['key' => 'actions', 'label' => 'Actions', 'locked' => true, 'align' => 'center', 'default_order' => 12],
            ],
        ];
    }

    if ($module === 'admin_permissions') {
        $roles = [];
        if ($pdo) {
            try {
                $roles = $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $roles = [];
            }
        }

        $columns = [
            ['key' => 'permission', 'label' => 'Permission', 'locked' => true, 'default_order' => 1],
            ['key' => 'description', 'label' => 'Description', 'default_order' => 2],
        ];

        $order = 3;
        foreach ($roles as $role) {
            $columns[] = [
                'key' => 'role_' . (int)($role['id'] ?? 0),
                'label' => (string)($role['name'] ?? 'Role'),
                'align' => 'center',
                'default_order' => $order++,
            ];
        }

        $columns[] = ['key' => 'user_overrides', 'label' => 'Users', 'align' => 'center', 'default_order' => $order++];
        $columns[] = ['key' => 'actions', 'label' => 'Actions', 'align' => 'center', 'default_order' => $order];

        return [
            'module' => 'admin_permissions',
            'title' => 'Manage Permission Columns',
            'description' => 'Show, hide, search, and reorder permission matrix columns.',
            'button_label' => 'Manage Columns',
            'table_preference_key' => 'admin_permissions',
            'legacy_page_identifier' => 'admin_permissions',
            'default_page_size' => 25,
            'page_size_options' => [10, 25, 50, 100],
            'columns' => $columns,
        ];
    }

    return null;
}
