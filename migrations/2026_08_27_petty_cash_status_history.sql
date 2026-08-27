-- Migration: Add petty_cash_status_history table
-- Date: 2026-08-27
-- Purpose: Track status changes for petty cash requests to enable workflow timeline display

-- Create petty_cash_status_history table (mirrors reimbursement_status_history structure)
CREATE TABLE IF NOT EXISTS `petty_cash_status_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `change_date` datetime DEFAULT CURRENT_TIMESTAMP(),
  `change_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`history_id`),
  KEY `idx_pc_hist_request` (`request_id`),
  KEY `idx_pc_hist_changed_by` (`changed_by`),
  KEY `idx_pc_hist_change_date` (`change_date`),
  CONSTRAINT `fk_pc_hist_request` FOREIGN KEY (`request_id`) REFERENCES `procurement_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_hist_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Historical record of petty cash request status changes';

-- Insert initial status history for existing petty cash requests
-- This will backfill the history table with current statuses
INSERT INTO `petty_cash_status_history` 
  (`request_id`, `old_status`, `new_status`, `changed_by`, `change_date`, `change_notes`)
SELECT 
  pr.request_id,
  NULL as old_status,
  pr.status as new_status,
  pr.created_by as changed_by,
  pr.created_at as change_date,
  'Initial status (backfilled from existing data)' as change_notes
FROM procurement_requests pr
WHERE pr.request_type = 'PETTY_CASH'
  AND NOT EXISTS (
    SELECT 1 FROM petty_cash_status_history pch 
    WHERE pch.request_id = pr.request_id
  );

-- Note: Future status changes will be logged by the application code in petty_cash/approve.php
