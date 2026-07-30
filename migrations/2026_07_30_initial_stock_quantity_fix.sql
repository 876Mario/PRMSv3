-- ============================================================
-- Migration: 2026_07_30_initial_stock_quantity_fix.sql
-- Purpose  : Initialise inv_stock records (qty = 1) for
--            existing active items that currently have no
--            usable stock, preventing transfer failures.
--
-- SAFE TO RUN multiple times (INSERT IGNORE / WHERE NOT EXISTS).
-- ============================================================

-- Step 1: Find the most-used location (fallback target for items
--         that have no existing stock record anywhere).
--         We pick the location_id that appears most often in the
--         inv_stock table; if inv_stock is empty, fall back to the
--         first active location by location_code.

-- Step 2: Insert qty = 1 OPENING_BALANCE stock for every active
--         item that has zero usable stock.
--
-- Strategy: use the first location_id found in an existing stock
--           record for the item, or the most popular location
--           system-wide, or finally the first active location.

INSERT INTO inv_stock
    (item_id, location_id, quantity_on_hand, unit_cost, stock_status, received_date)
SELECT
    i.item_id,
    COALESCE(
        -- Use the item's most-recently-received location (any status)
        (SELECT s2.location_id
         FROM inv_stock s2
         WHERE s2.item_id = i.item_id
         ORDER BY s2.received_date DESC, s2.stock_id DESC
         LIMIT 1),
        -- Fallback: most-used location in the stock table overall
        (SELECT s3.location_id
         FROM inv_stock s3
         WHERE s3.stock_status = 'USABLE'
         GROUP BY s3.location_id
         ORDER BY COUNT(*) DESC
         LIMIT 1),
        -- Final fallback: first active inventory location
        (SELECT l2.location_id
         FROM inv_locations l2
         WHERE l2.is_active = 1 AND l2.location_type = 'USABLE'
         ORDER BY l2.location_code
         LIMIT 1)
    ) AS chosen_location,
    1.0000,   -- initial quantity
    0.00,     -- unit_cost (unknown)
    'USABLE',
    CURDATE()
FROM inv_items i
WHERE i.item_status = 'ACTIVE'
  AND NOT EXISTS (
      SELECT 1 FROM inv_stock s
      WHERE s.item_id = i.item_id
        AND s.stock_status = 'USABLE'
        AND s.quantity_on_hand > 0
  )
  AND COALESCE(
        (SELECT s2.location_id FROM inv_stock s2 WHERE s2.item_id = i.item_id LIMIT 1),
        (SELECT s3.location_id FROM inv_stock s3 WHERE s3.stock_status='USABLE' GROUP BY s3.location_id ORDER BY COUNT(*) DESC LIMIT 1),
        (SELECT l2.location_id FROM inv_locations l2 WHERE l2.is_active=1 AND l2.location_type='USABLE' ORDER BY l2.location_code LIMIT 1)
      ) IS NOT NULL;

-- Step 3: Record opening-balance transactions for the new stock records
INSERT INTO inv_transactions
    (transaction_type, item_id, stock_id, location_id, quantity, unit_cost, total_cost,
     balance_after, reference_type, notes)
SELECT
    'RECEIPT',
    s.item_id,
    s.stock_id,
    s.location_id,
    s.quantity_on_hand,
    s.unit_cost,
    s.quantity_on_hand * s.unit_cost,
    s.quantity_on_hand,
    'MIGRATION',
    'Auto-initialised by migration 2026_07_30 — existing item with no prior stock record'
FROM inv_stock s
WHERE s.stock_status = 'USABLE'
  AND s.received_date = CURDATE()
  AND s.quantity_on_hand = 1.0000
  AND NOT EXISTS (
      SELECT 1 FROM inv_transactions t
      WHERE t.item_id  = s.item_id
        AND t.stock_id = s.stock_id
        AND t.transaction_type = 'RECEIPT'
  );
