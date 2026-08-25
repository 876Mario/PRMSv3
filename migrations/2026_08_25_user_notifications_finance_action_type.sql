-- =====================================================
-- PRMS DATABASE MIGRATION: user_notifications finance type
-- Purpose: Align the user_notifications.type ENUM with
--          NotificationService, which already emits the
--          'finance_action_required' type (see
--          services/NotificationService.php TYPE_FINANCE_ACTION and
--          services/FinanceNotificationService.php). Without this value
--          in the ENUM, finance action notifications fail to insert on
--          strict-mode MySQL/MariaDB servers.
--
-- Reference schema: prmsv2.sql (user_notifications.type currently only
-- allows the original 7 values from migration 024).
-- =====================================================

ALTER TABLE `user_notifications`
  MODIFY `type` ENUM(
    'approval_needed',
    'return_correction',
    'clarification',
    'rejection',
    'cancellation',
    'draft_ready',
    'submission',
    'finance_action_required'
  ) NOT NULL;

-- =====================================================
-- ROLLBACK
-- =====================================================
-- Remove rows using the new type first, then restore the original ENUM:
--
-- DELETE FROM `user_notifications` WHERE `type` = 'finance_action_required';
--
-- ALTER TABLE `user_notifications`
--   MODIFY `type` ENUM(
--     'approval_needed',
--     'return_correction',
--     'clarification',
--     'rejection',
--     'cancellation',
--     'draft_ready',
--     'submission'
--   ) NOT NULL;
