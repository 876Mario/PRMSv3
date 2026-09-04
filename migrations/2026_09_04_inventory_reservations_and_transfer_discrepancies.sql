-- Inventory reservation provenance + transfer discrepancy workflow

CREATE TABLE IF NOT EXISTS `inv_stock_reservations` (
  `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `reference_type` varchar(50) NOT NULL COMMENT 'inv_issues, inv_transfers, etc.',
  `reference_id` int(11) NOT NULL,
  `reference_line_id` int(11) DEFAULT NULL,
  `quantity_reserved` decimal(14,4) NOT NULL,
  `quantity_consumed` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `quantity_released` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(30) NOT NULL DEFAULT 'RESERVED',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `consumed_by` int(11) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`reservation_id`),
  KEY `idx_inv_stock_reservations_reference` (`reference_type`, `reference_id`, `reference_line_id`),
  KEY `idx_inv_stock_reservations_stock` (`stock_id`),
  KEY `idx_inv_stock_reservations_item_location` (`item_id`, `location_id`),
  CONSTRAINT `fk_inv_stock_reservation_stock` FOREIGN KEY (`stock_id`) REFERENCES `inv_stock` (`stock_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_stock_reservation_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`item_id`),
  CONSTRAINT `fk_inv_stock_reservation_location` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `inv_transfers`
  MODIFY COLUMN `status` varchar(50) NOT NULL DEFAULT 'DRAFT',
  ADD COLUMN IF NOT EXISTS `discrepancy_status` varchar(30) DEFAULT NULL AFTER `received_at`,
  ADD COLUMN IF NOT EXISTS `discrepancy_notes` text DEFAULT NULL AFTER `discrepancy_status`,
  ADD COLUMN IF NOT EXISTS `discrepancy_reported_by` int(11) DEFAULT NULL AFTER `discrepancy_notes`,
  ADD COLUMN IF NOT EXISTS `discrepancy_reported_at` datetime DEFAULT NULL AFTER `discrepancy_reported_by`,
  ADD COLUMN IF NOT EXISTS `discrepancy_incident_id` int(11) DEFAULT NULL AFTER `discrepancy_reported_at`,
  ADD COLUMN IF NOT EXISTS `discrepancy_adjustment_id` int(11) DEFAULT NULL AFTER `discrepancy_incident_id`;

CREATE TABLE IF NOT EXISTS `inv_transfer_discrepancies` (
  `transfer_discrepancy_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `transfer_item_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `expected_quantity` decimal(14,4) NOT NULL,
  `received_quantity` decimal(14,4) NOT NULL,
  `variance_quantity` decimal(14,4) NOT NULL,
  `discrepancy_type` varchar(20) NOT NULL COMMENT 'SHORTAGE, OVERAGE, DAMAGE',
  `incident_id` int(11) DEFAULT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `reported_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transfer_discrepancy_id`),
  KEY `idx_inv_transfer_discrepancies_transfer` (`transfer_id`),
  KEY `idx_inv_transfer_discrepancies_item` (`item_id`),
  CONSTRAINT `fk_inv_transfer_discrepancy_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `inv_transfers` (`transfer_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_transfer_discrepancy_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`item_id`),
  CONSTRAINT `fk_inv_transfer_discrepancy_transfer_item` FOREIGN KEY (`transfer_item_id`) REFERENCES `inv_transfer_items` (`transfer_item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
