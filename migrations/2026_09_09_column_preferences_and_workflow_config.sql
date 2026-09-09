-- Centralized column preferences and workflow configuration framework

CREATE TABLE IF NOT EXISTS `user_column_preferences` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `module_name` VARCHAR(100) NOT NULL,
  `column_key` VARCHAR(100) NOT NULL,
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_column_preference` (`user_id`, `module_name`, `column_key`),
  KEY `idx_user_column_module` (`user_id`, `module_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user reusable column visibility and ordering preferences by module';

ALTER TABLE `reminder_log`
  MODIFY COLUMN `reminder_type` ENUM('reminder','escalation','critical') NOT NULL;

INSERT IGNORE INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
  ('high_value_petty_cash_threshold', '500000', 'Petty cash requests at or above this amount trigger finance high-value oversight.'),
  ('high_value_request_threshold', '500000', 'General request value threshold for high-value dashboards and alerts.'),
  ('high_value_procurement_threshold', '3000000', 'Procurement requests at or above this amount trigger director procurement oversight.'),
  ('high_value_purchase_order_threshold', '3000000', 'Purchase orders at or above this amount trigger high-value PO oversight.'),
  ('finance_escalation_threshold', '3000000', 'Transactions at or above this amount escalate to Director Accounts & Finance.'),
  ('director_notification_threshold', '3000000', 'Transactions at or above this amount notify the supervising director.'),
  ('executive_oversight_threshold', '10000000', 'Transactions at or above this amount trigger executive oversight.'),
  ('rfq_vendor_assignment_sla_days', '2', 'Allowed turnaround for assigning vendors or issuing RFQ letters.'),
  ('rfq_quotation_collection_sla_days', '5', 'Allowed turnaround for collecting quotations or additional quotations.'),
  ('quote_review_sla_days', '3', 'Allowed turnaround for quote evaluation and review stages.'),
  ('purchase_order_creation_sla_days', '3', 'Allowed turnaround for creating purchase orders after commitment approval.'),
  ('fund_verification_sla_days', '3', 'Allowed turnaround for finance fund verification stages.'),
  ('petty_cash_processing_sla_days', '2', 'Allowed turnaround for petty cash finance authorization and processing.'),
  ('disbursement_sla_days', '2', 'Allowed turnaround for invoice payment or petty cash disbursement actions.'),
  ('director_approval_sla_days', '2', 'Allowed turnaround for director- or branch-head approval stages.'),
  ('procurement_approval_sla_days', '3', 'Allowed turnaround for procurement processing stages.'),
  ('finance_approval_sla_days', '3', 'Allowed turnaround for finance approval and commitment stages.'),
  ('request_review_sla_days', '2', 'Allowed turnaround for submitted or requestor review stages.'),
  ('returned_request_correction_sla_days', '2', 'Allowed turnaround for returned request corrections.'),
  ('resubmitted_request_sla_days', '2', 'Allowed turnaround for resubmitted requests re-entering review.'),
  ('invoice_overdue_days', '30', 'Age after which unpaid invoices are considered overdue.'),
  ('escalation_warning_pct', '75', 'Percentage of SLA duration when warning alerts begin.'),
  ('escalation_overdue_pct', '100', 'Percentage of SLA duration when an item becomes overdue.'),
  ('escalation_critical_pct', '150', 'Percentage of SLA duration when critical escalation begins.');
