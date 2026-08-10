-- Migration: Document Control Settings
-- Date: 2026-08-10
-- Description: Adds a dedicated table for document control settings (Form Revision, Effective Date,
--              DCR Number) and snapshot columns on procurement_requests so that historical
--              print requests retain the values that were active at the time they were generated.

-- ── Document Control Settings table ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `doc_ctrl_settings` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `form_revision`    VARCHAR(100) NOT NULL DEFAULT '',
    `effective_date`   DATE         DEFAULT NULL,
    `dcr_number`       VARCHAR(100) NOT NULL DEFAULT '',
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by_id`    INT(11)      DEFAULT NULL COMMENT 'User ID of last editor',
    `updated_by_name`  VARCHAR(255) DEFAULT NULL COMMENT 'Display name of last editor',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed one row so there is always a single record to UPDATE
INSERT INTO `doc_ctrl_settings` (`id`, `form_revision`, `effective_date`, `dcr_number`)
VALUES (1, '', NULL, '')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ── Snapshot columns on procurement_requests ─────────────────────────────────
ALTER TABLE `procurement_requests`
    ADD COLUMN IF NOT EXISTS `doc_ctrl_form_revision` VARCHAR(100) DEFAULT NULL
        COMMENT 'Form Revision active when this print request was generated' AFTER `signed_by_user_id`,
    ADD COLUMN IF NOT EXISTS `doc_ctrl_effective_date` DATE DEFAULT NULL
        COMMENT 'Effective Date active when this print request was generated' AFTER `doc_ctrl_form_revision`,
    ADD COLUMN IF NOT EXISTS `doc_ctrl_dcr_number`    VARCHAR(100) DEFAULT NULL
        COMMENT 'DCR Number active when this print request was generated'    AFTER `doc_ctrl_effective_date`;
