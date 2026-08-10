<?php
/**
 * Shared helpers for inventory transaction list searches.
 */

function inventoryTransactionSearchPattern(string $search): string
{
    return '%' . trim($search) . '%';
}

function validateInventorySearchIdentifier(string $identifier): void
{
    // Intentionally accepts only unqualified internal table, alias, and column identifiers.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid inventory search SQL identifier.');
    }
}

function buildInventoryItemSearchExistsClause(
    string $parentAlias,
    string $parentKey,
    string $lineTable,
    string $lineParentKey
): string {
    validateInventorySearchIdentifier($parentAlias);
    validateInventorySearchIdentifier($parentKey);
    validateInventorySearchIdentifier($lineTable);
    validateInventorySearchIdentifier($lineParentKey);

    return "EXISTS (
        SELECT 1
        FROM {$lineTable} item_line
        JOIN inv_items search_item ON search_item.item_id = item_line.item_id
        WHERE item_line.{$lineParentKey} = {$parentAlias}.{$parentKey}
          AND (
              LOWER(TRIM(search_item.item_code)) LIKE LOWER(?)
              OR LOWER(TRIM(search_item.item_name)) LIKE LOWER(?)
              OR LOWER(TRIM(COALESCE(search_item.description, ''))) LIKE LOWER(?)
          )
    )";
}
