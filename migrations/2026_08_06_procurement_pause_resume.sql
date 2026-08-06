ALTER TABLE `procurement_requests`
  ADD COLUMN IF NOT EXISTS `paused_previous_status` varchar(30) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `paused_reason` text DEFAULT NULL AFTER `paused_previous_status`,
  ADD COLUMN IF NOT EXISTS `paused_by` int(11) DEFAULT NULL AFTER `paused_reason`,
  ADD COLUMN IF NOT EXISTS `paused_at` datetime DEFAULT NULL AFTER `paused_by`,
  ADD COLUMN IF NOT EXISTS `resume_reason` text DEFAULT NULL AFTER `paused_at`,
  ADD COLUMN IF NOT EXISTS `resumed_by` int(11) DEFAULT NULL AFTER `resume_reason`,
  ADD COLUMN IF NOT EXISTS `resumed_at` datetime DEFAULT NULL AFTER `resumed_by`;
