CREATE TABLE IF NOT EXISTS `number_sequences` (
  `sequence_key` varchar(100) NOT NULL,
  `next_value` bigint(20) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, description)
SELECT 'approve_reimbursement_without_invoice_verification', 'Approve reimbursement without invoice verification in exceptional cases'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE name = 'approve_reimbursement_without_invoice_verification'
);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'approve_reimbursement_without_invoice_verification'
WHERE r.name IN ('Admin', 'SuperAdmin')
  AND NOT EXISTS (
      SELECT 1
      FROM role_permissions rp
      WHERE rp.role_id = r.id
        AND rp.permission_id = p.id
  );

SET @drop_duplicate_request_number_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'procurement_requests'
              AND index_name = 'uq_request_number'
        ),
        'ALTER TABLE `procurement_requests` DROP INDEX `uq_request_number`',
        'SELECT 1'
    )
);
PREPARE stmt FROM @drop_duplicate_request_number_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_rfq_quote_vendor_selected_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'rfq_quotes'
              AND index_name = 'idx_rfq_quote_vendor_selected'
        ),
        'SELECT 1',
        'ALTER TABLE `rfq_quotes` ADD INDEX `idx_rfq_quote_vendor_selected` (`rfq_vendor_id`, `is_selected`)'
    )
);
PREPARE stmt FROM @add_rfq_quote_vendor_selected_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_rfq_scores_rfq_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'rfq_scores'
              AND index_name = 'idx_rfq_scores_rfq'
        ),
        'SELECT 1',
        'ALTER TABLE `rfq_scores` ADD INDEX `idx_rfq_scores_rfq` (`rfq_id`)'
    )
);
PREPARE stmt FROM @add_rfq_scores_rfq_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_user_notifications_unread_created_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'user_notifications'
              AND index_name = 'idx_notif_user_unread_created'
        ),
        'SELECT 1',
        'ALTER TABLE `user_notifications` ADD INDEX `idx_notif_user_unread_created` (`user_id`, `is_read`, `created_at`)'
    )
);
PREPARE stmt FROM @add_user_notifications_unread_created_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
