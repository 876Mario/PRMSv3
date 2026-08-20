<?php
/**
 * RFQ Approval Reminders & Escalation Cron Job
 * ==============================================
 * Sends reminders for pending RFQ approvals (spec review & branch head)
 * and escalates overdue actions according to configurable rules.
 *
 * Schedule: Run every hour via cron
 * Example: 0 * * * * php /path/to/cron/rfq_approval_reminders.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/../config/notifications.php';

$now = new DateTime();

// Load reminder configuration
$stmtConfig = $pdo->query("SELECT * FROM approval_reminder_config WHERE is_active = 1");
$configs = $stmtConfig->fetchAll(PDO::FETCH_ASSOC);

if (empty($configs)) {
    echo "No active reminder configurations found.\n";
    exit(0);
}

foreach ($configs as $config) {
    $stage = $config['approval_stage'];
    $reminderAfterHours = (int)$config['reminder_after_hours'];
    $escalationAfterHours = (int)$config['escalation_after_hours'];
    $maxReminders = (int)$config['max_reminders'];
    $escalateToRole = $config['escalate_to_role'];

    // Find RFQs with pending approvals at this stage
    if ($stage === 'SPEC_REVIEW') {
        $stmtPending = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, r.spec_review_status, r.created_at,
                   sr.reviewer_id as assigned_user_id, u.email, u.display_name
            FROM rfqs r
            JOIN rfq_spec_reviewers sr ON r.rfq_id = sr.rfq_id AND sr.is_active = 1
            JOIN users u ON sr.reviewer_id = u.user_id AND u.is_active = 1
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            WHERE r.spec_review_status = 'PENDING'
              AND pr.status NOT IN ('CANCELLED', 'EXPIRED', 'COMPLETED')
              AND r.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmtPending->execute([$reminderAfterHours]);
    } else {
        $stmtPending = $pdo->prepare("
            SELECT r.rfq_id, r.rfq_number, r.branch_head_approval_status, r.spec_reviewed_at as stage_start,
                   bh.approver_id as assigned_user_id, u.email, u.display_name
            FROM rfqs r
            JOIN rfq_branch_head_approvers bh ON r.rfq_id = bh.rfq_id AND bh.is_active = 1
            JOIN users u ON bh.approver_id = u.user_id AND u.is_active = 1
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            WHERE r.spec_review_status = 'APPROVED'
              AND r.branch_head_approval_status = 'PENDING'
              AND pr.status NOT IN ('CANCELLED', 'EXPIRED', 'COMPLETED')
              AND r.spec_reviewed_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmtPending->execute([$reminderAfterHours]);
    }

    $pendingRfqs = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingRfqs as $rfq) {
        $rfqId = $rfq['rfq_id'];
        $userId = $rfq['assigned_user_id'];

        // Check how many reminders have been sent
        $stmtCount = $pdo->prepare("
            SELECT COUNT(*) FROM approval_reminders_sent
            WHERE rfq_id = ? AND approval_stage = ? AND sent_to_user_id = ? AND is_escalation = 0
        ");
        $stmtCount->execute([$rfqId, $stage, $userId]);
        $remindersSent = (int)$stmtCount->fetchColumn();

        if ($remindersSent >= $maxReminders) {
            // Check if escalation already sent
            $stmtEsc = $pdo->prepare("
                SELECT COUNT(*) FROM approval_reminders_sent
                WHERE rfq_id = ? AND approval_stage = ? AND is_escalation = 1
            ");
            $stmtEsc->execute([$rfqId, $stage]);
            if ((int)$stmtEsc->fetchColumn() === 0) {
                // Escalate to designated role
                $stmtEscUsers = $pdo->prepare("
                    SELECT u.user_id, u.email, u.display_name FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE r.name = ? AND u.is_active = 1
                ");
                $stmtEscUsers->execute([$escalateToRole]);
                $escalateUsers = $stmtEscUsers->fetchAll(PDO::FETCH_ASSOC);

                foreach ($escalateUsers as $escUser) {
                    $subject = "[ESCALATION] RFQ {$rfq['rfq_number']} - Overdue {$stage} Approval";
                    $html = "<p>The following RFQ approval has been overdue for more than {$escalationAfterHours} hours:</p>
                             <p><strong>RFQ:</strong> {$rfq['rfq_number']}<br>
                             <strong>Stage:</strong> {$stage}<br>
                             <strong>Assigned to:</strong> {$rfq['display_name']}</p>
                             <p>Please escalate or reassign this approval.</p>";

                    if (notificationsEnabled() && !empty($escUser['email'])) {
                        sendMail($escUser['email'], $subject, $html);
                    }

                    $pdo->prepare("
                        INSERT INTO approval_reminders_sent (rfq_id, approval_stage, reminder_number, sent_to_user_id, is_escalation)
                        VALUES (?, ?, ?, ?, 1)
                    ")->execute([$rfqId, $stage, $remindersSent + 1, $escUser['user_id']]);
                }

                echo "Escalated RFQ {$rfq['rfq_number']} stage {$stage}\n";
            }
            continue;
        }

        // Check cooldown - don't send more than one reminder per 24 hours
        $stmtLast = $pdo->prepare("
            SELECT MAX(sent_at) FROM approval_reminders_sent
            WHERE rfq_id = ? AND approval_stage = ? AND sent_to_user_id = ?
        ");
        $stmtLast->execute([$rfqId, $stage, $userId]);
        $lastSent = $stmtLast->fetchColumn();
        if ($lastSent) {
            $lastSentTime = new DateTime($lastSent);
            $hoursSinceLast = ($now->getTimestamp() - $lastSentTime->getTimestamp()) / 3600;
            if ($hoursSinceLast < 24) {
                continue;
            }
        }

        // Send reminder
        $appUrl = getAppUrl();
        $actionUrl = $stage === 'SPEC_REVIEW'
            ? "{$appUrl}/rfq/spec_review_approve.php?id={$rfqId}"
            : "{$appUrl}/rfq/branch_head_approve.php?id={$rfqId}";

        $subject = "[Reminder] RFQ {$rfq['rfq_number']} - Pending {$stage} Approval";
        $html = "<p>This is a reminder that your approval is required for RFQ <strong>{$rfq['rfq_number']}</strong>.</p>
                 <p><strong>Stage:</strong> " . str_replace('_', ' ', $stage) . "</p>
                 <p><a href='" . htmlspecialchars($actionUrl) . "'>Click here to review and approve</a></p>";

        if (notificationsEnabled() && !empty($rfq['email'])) {
            sendMail($rfq['email'], $subject, $html);
        }

        $pdo->prepare("
            INSERT INTO approval_reminders_sent (rfq_id, approval_stage, reminder_number, sent_to_user_id)
            VALUES (?, ?, ?, ?)
        ")->execute([$rfqId, $stage, $remindersSent + 1, $userId]);

        echo "Reminder #" . ($remindersSent + 1) . " sent for RFQ {$rfq['rfq_number']} stage {$stage} to {$rfq['display_name']}\n";
    }
}

echo "RFQ approval reminder job completed at " . $now->format('Y-m-d H:i:s') . "\n";
