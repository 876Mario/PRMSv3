-- ============================================================================
-- Migration 2026_08_03: User Table Preferences
-- ============================================================================
-- Purpose:
--   Store per-user, per-page preferences for table column visibility,
--   column ordering, default sort column, default sort direction, and page size.
--   Applied to the Inventory Items list page initially; designed to be reusable
--   for any page_identifier across the application.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_table_preferences` (
  `id`                   int(11)          NOT NULL AUTO_INCREMENT,
  `user_id`              int(11)          NOT NULL,
  `page_identifier`      varchar(100)     NOT NULL COMMENT 'Unique page/table identifier, e.g. inventory_items_list',
  `visible_columns`      text             DEFAULT NULL COMMENT 'JSON array of visible column keys',
  `column_order`         text             DEFAULT NULL COMMENT 'JSON array of column keys in display order',
  `default_sort_column`  varchar(50)      DEFAULT NULL COMMENT 'Column key used as the default sort',
  `default_sort_direction` enum('ASC','DESC') NOT NULL DEFAULT 'ASC',
  `page_size`            smallint(5)      UNSIGNED NOT NULL DEFAULT 20 COMMENT 'Rows per page (10-200)',
  `created_at`           timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_page` (`user_id`, `page_identifier`),
  KEY `idx_user_id`       (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user display preferences for paginated list/table pages';
