<?php
/**
 * Shared helpers for inventory transaction list searches.
 */

function inventoryTransactionSearchPattern(string $search): string
{
    return '%' . trim($search) . '%';
}

function buildInventoryItemSearchExistsClause(
    string $parentAlias,
    string $parentKey,
    string $lineTable,
    string $lineParentKey
): string {
    return "EXISTS (
        SELECT 1
        FROM {$lineTable} item_line
        JOIN inv_items search_item ON search_item.item_id = item_line.item_id
        WHERE item_line.{$lineParentKey} = {$parentAlias}.{$parentKey}
          AND (
              LOWER(TRIM(search_item.item_code)) LIKE LOWER(TRIM(?))
              OR LOWER(TRIM(search_item.item_name)) LIKE LOWER(TRIM(?))
              OR LOWER(TRIM(COALESCE(search_item.description, ''))) LIKE LOWER(TRIM(?))
          )
    )";
}
