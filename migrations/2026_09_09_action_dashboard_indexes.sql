-- Action dashboard performance indexes
-- Supports role dashboard queues, workflow summaries, and monitoring queries.

SET @add_pr_status_request_type_created_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'procurement_requests'
              AND index_name = 'idx_pr_status_type_created'
        ),
        'SELECT 1',
        'ALTER TABLE `procurement_requests` ADD INDEX `idx_pr_status_type_created` (`status`, `request_type`, `created_at`)'
    )
);
PREPARE stmt FROM @add_pr_status_request_type_created_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_pr_created_by_status_created_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'procurement_requests'
              AND index_name = 'idx_pr_created_by_status_created'
        ),
        'SELECT 1',
        'ALTER TABLE `procurement_requests` ADD INDEX `idx_pr_created_by_status_created` (`created_by`, `status`, `created_at`)'
    )
);
PREPARE stmt FROM @add_pr_created_by_status_created_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_pr_branch_status_created_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'procurement_requests'
              AND index_name = 'idx_pr_branch_status_created'
        ),
        'SELECT 1',
        'ALTER TABLE `procurement_requests` ADD INDEX `idx_pr_branch_status_created` (`branch_id`, `status`, `created_at`)'
    )
);
PREPARE stmt FROM @add_pr_branch_status_created_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_request_approvals_role_status_lookup_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'request_approvals'
              AND index_name = 'idx_request_approvals_role_status_lookup'
        ),
        'SELECT 1',
        'ALTER TABLE `request_approvals` ADD INDEX `idx_request_approvals_role_status_lookup` (`role`, `status`, `entity_type`, `request_id`)'
    )
);
PREPARE stmt FROM @add_request_approvals_role_status_lookup_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
