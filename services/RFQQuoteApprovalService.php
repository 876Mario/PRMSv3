<?php

/**
 * RFQ Quote Approval Service
 * Handles the two-stage approval workflow for RFQ quotes:
 * 1. Specification Review
 * 2. Branch Head Final Approval
 */

class RFQQuoteApprovalService {
    
    private $pdo;
    private $user_id;
    private $user_role;

    public function __construct($pdo, $user_id, $user_role = null) {
        $this->pdo = $pdo;
        $this->user_id = $user_id;
        $this->user_role = $user_role;
    }

    /**
     * Get pending spec reviews for a user
     * @return array List of RFQs awaiting specification review
     */
    public function getPendingSpecReviews() {
        $stmt = $this->pdo->prepare("
            SELECT 
                r.rfq_id,
                r.rfq_number,
                r.submission_deadline,
                pr.request_number,
                pr.description,
                pr.estimated_value,
                COUNT(q.quote_id) as quote_count,
                r.created_at,
                u.display_name as created_by_name
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            LEFT JOIN rfq_quotes q ON (
                SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = r.rfq_id LIMIT 1
            ) = q.rfq_vendor_id
            LEFT JOIN users u ON r.created_by = u.user_id
            WHERE r.spec_review_status = 'PENDING'
            GROUP BY r.rfq_id, r.rfq_number, r.submission_deadline, pr.request_number, 
                     pr.description, pr.estimated_value, r.created_at, u.display_name
            ORDER BY r.submission_deadline ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending branch head approvals for a user
     * @return array List of RFQs awaiting branch head approval
     */
    public function getPendingBranchHeadApprovals() {
        $stmt = $this->pdo->prepare("
            SELECT 
                r.rfq_id,
                r.rfq_number,
                r.submission_deadline,
                pr.request_number,
                pr.description,
                pr.estimated_value,
                COUNT(q.quote_id) as quote_count,
                r.created_at,
                u.display_name as created_by_name,
                sr.display_name as spec_reviewer_name,
                r.spec_reviewed_at
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            LEFT JOIN rfq_quotes q ON (
                SELECT rfq_vendor_id FROM rfq_vendors WHERE rfq_id = r.rfq_id LIMIT 1
            ) = q.rfq_vendor_id
            LEFT JOIN users u ON r.created_by = u.user_id
            LEFT JOIN users sr ON r.spec_reviewer_id = sr.user_id
            WHERE r.spec_review_status = 'APPROVED'
              AND r.branch_head_approval_status = 'PENDING'
            GROUP BY r.rfq_id, r.rfq_number, r.submission_deadline, pr.request_number, 
                     pr.description, pr.estimated_value, r.created_at, u.display_name,
                     sr.display_name, r.spec_reviewed_at
            ORDER BY r.submission_deadline ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve specification review for an RFQ
     * @param int $rfq_id RFQ ID
     * @param string $comments Optional approval comments
     * @return bool Success status
     */
    public function approveSpecReview($rfq_id, $comments = '') {
        try {
            $this->pdo->beginTransaction();

            // Update RFQ spec review status and lock quotes
            $stmt = $this->pdo->prepare("
                UPDATE rfqs
                SET spec_review_status = 'APPROVED',
                    spec_reviewer_id = ?,
                    spec_reviewed_at = NOW(),
                    spec_review_comments = ?,
                    quotes_locked = 1,
                    quotes_locked_at = NOW(),
                    quotes_locked_by = ?
                WHERE rfq_id = ?
            ");
            $stmt->execute([$this->user_id, $comments, $this->user_id, $rfq_id]);

            // Log in approval audit trail
            $this->logApproval($rfq_id, 'SPEC_REVIEW', 'APPROVED', $comments);

            // Send notification to branch head
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
            notifyBranchHeadSpecReviewApproved($rfq_id);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error approving spec review for RFQ {$rfq_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reject specification review for an RFQ
     * @param int $rfq_id RFQ ID
     * @param string $reason Reason for rejection
     * @return bool Success status
     */
    public function rejectSpecReview($rfq_id, $reason = '') {
        try {
            $this->pdo->beginTransaction();

            // Update RFQ spec review status
            $stmt = $this->pdo->prepare("
                UPDATE rfqs
                SET spec_review_status = 'REJECTED',
                    spec_reviewer_id = ?,
                    spec_reviewed_at = NOW(),
                    spec_review_comments = ?,
                    quotes_locked = 0,
                    quotes_locked_at = NULL,
                    quotes_locked_by = NULL
                WHERE rfq_id = ?
            ");
            $stmt->execute([$this->user_id, $reason, $rfq_id]);

            // Return the procurement request to RFQ_LETTER_AVAILABLE for resolution
            $stmt = $this->pdo->prepare("SELECT request_id FROM rfqs WHERE rfq_id = ?");
            $stmt->execute([$rfq_id]);
            $requestId = $stmt->fetchColumn();
            if ($requestId) {
                $this->pdo->prepare("
                    UPDATE procurement_requests
                    SET status = 'RFQ_LETTER_AVAILABLE'
                    WHERE request_id = ?
                ")->execute([$requestId]);
            }

            // Log in approval audit trail
            $this->logApproval($rfq_id, 'SPEC_REVIEW', 'REJECTED', $reason);

            // Send notification to requestor (quotes need revision)
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
            notifyRequestorSpecReviewRejected($rfq_id, $reason);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error rejecting spec review for RFQ {$rfq_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Approve branch head final approval for an RFQ
     * @param int $rfq_id RFQ ID
     * @param string $comments Optional approval comments
     * @return bool Success status
     */
    public function approveBranchHeadApproval($rfq_id, $comments = '') {
        try {
            $this->pdo->beginTransaction();

            // Verify spec review is approved
            $stmt = $this->pdo->prepare("SELECT spec_review_status, request_id FROM rfqs WHERE rfq_id = ?");
            $stmt->execute([$rfq_id]);
            $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rfq || $rfq['spec_review_status'] !== 'APPROVED') {
                throw new Exception('Specification review must be approved before branch head approval');
            }

            // Update RFQ branch head approval status
            $stmt = $this->pdo->prepare("
                UPDATE rfqs
                SET branch_head_approval_status = 'APPROVED',
                    branch_head_approver_id = ?,
                    branch_head_approved_at = NOW(),
                    branch_head_comments = ?
                WHERE rfq_id = ?
            ");
            $stmt->execute([$this->user_id, $comments, $rfq_id]);

            // Transition procurement request status to QUOTE_APPROVED (ready for PO/commitment)
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                SET status = 'QUOTE_APPROVED'
                WHERE request_id = ? AND status IN ('QUOTE_REVIEW_PENDING', 'QUOTE_SPEC_REVIEW_PENDING', 'QUOTE_SPEC_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING')
            ");
            $stmt->execute([$rfq['request_id']]);

            // Mark the selected quotation as approved
            $stmt = $this->pdo->prepare("
                UPDATE rfq_quotes q
                JOIN rfq_vendors rv ON q.rfq_vendor_id = rv.rfq_vendor_id
                SET q.review_status = 'MEETS_REQUIREMENTS'
                WHERE rv.rfq_id = ? AND q.is_selected = 1
            ");
            $stmt->execute([$rfq_id]);

            // Log in approval audit trail
            $this->logApproval($rfq_id, 'BRANCH_HEAD_APPROVAL', 'APPROVED', $comments);

            // Send notification to finance/procurement for supplier selection
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
            notifyProcurementAllApprovalsComplete($rfq_id);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error approving branch head approval for RFQ {$rfq_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reject branch head final approval for an RFQ
     * @param int $rfq_id RFQ ID
     * @param string $reason Reason for rejection
     * @return bool Success status
     */
    public function rejectBranchHeadApproval($rfq_id, $reason = '') {
        try {
            $this->pdo->beginTransaction();

            // Update RFQ branch head approval status
            $stmt = $this->pdo->prepare("
                UPDATE rfqs
                SET branch_head_approval_status = 'REJECTED',
                    branch_head_approver_id = ?,
                    branch_head_approved_at = NOW(),
                    branch_head_comments = ?
                WHERE rfq_id = ?
            ");
            $stmt->execute([$this->user_id, $reason, $rfq_id]);

            // Log in approval audit trail
            $this->logApproval($rfq_id, 'BRANCH_HEAD_APPROVAL', 'REJECTED', $reason);

            // Send notification to requestor and spec reviewer
            $this->sendRequestorNotification($rfq_id, 'branch_head_approval_rejected', $reason);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error rejecting branch head approval for RFQ {$rfq_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Return RFQ for clarification (from any approval stage)
     * @param int $rfq_id RFQ ID
     * @param string $stage SPEC_REVIEW or BRANCH_HEAD_APPROVAL
     * @param string $clarification_needed Clarification details
     * @return bool Success status
     */
    public function returnForClarification($rfq_id, $stage, $clarification_needed = '') {
        try {
            $this->pdo->beginTransaction();

            if ($stage === 'SPEC_REVIEW') {
                // Reset spec review and unlock quotes
                $stmt = $this->pdo->prepare("
                    UPDATE rfqs
                    SET spec_review_status = 'REJECTED',
                        spec_reviewer_id = ?,
                        spec_reviewed_at = NOW(),
                        spec_review_comments = ?,
                        quotes_locked = 0,
                        quotes_locked_at = NULL,
                        quotes_locked_by = NULL
                    WHERE rfq_id = ?
                ");
                $stmt->execute([$this->user_id, $clarification_needed, $rfq_id]);
                
                $action = 'RETURNED_FOR_CLARIFICATION';
                $this->sendRequestorNotification($rfq_id, 'spec_review_returned', $clarification_needed);
            } elseif ($stage === 'BRANCH_HEAD_APPROVAL') {
                // Reset branch head approval and unlock quotes
                $stmt = $this->pdo->prepare("
                    UPDATE rfqs
                    SET branch_head_approval_status = 'REJECTED',
                        branch_head_approver_id = ?,
                        branch_head_approved_at = NOW(),
                        branch_head_comments = ?,
                        quotes_locked = 0,
                        quotes_locked_at = NULL,
                        quotes_locked_by = NULL
                    WHERE rfq_id = ?
                ");
                $stmt->execute([$this->user_id, $clarification_needed, $rfq_id]);
                
                $action = 'RETURNED_FOR_CLARIFICATION';
                $this->sendRequestorNotification($rfq_id, 'branch_head_approval_returned', $clarification_needed);
            } else {
                throw new Exception('Invalid approval stage: ' . $stage);
            }

            // Log in approval audit trail
            $this->logApproval($rfq_id, $stage, $action, $clarification_needed);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error returning RFQ {$rfq_id} for clarification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get approval history for an RFQ
     * @param int $rfq_id RFQ ID
     * @return array Approval history records
     */
    public function getApprovalHistory($rfq_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                a.*,
                u.display_name as approver_name,
                u.email as approver_email
            FROM rfq_quote_approvals a
            LEFT JOIN users u ON a.approver_id = u.user_id
            WHERE a.rfq_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$rfq_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get current approval status for an RFQ
     * @param int $rfq_id RFQ ID
     * @return array Approval status details
     */
    public function getApprovalStatus($rfq_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                rfq_id,
                spec_review_status,
                spec_reviewer_id,
                spec_reviewed_at,
                branch_head_approval_status,
                branch_head_approver_id,
                branch_head_approved_at
            FROM rfqs
            WHERE rfq_id = ?
        ");
        $stmt->execute([$rfq_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Check if RFQ is fully approved (both stages)
     * @param int $rfq_id RFQ ID
     * @return bool True if both approvals are complete
     */
    public function isFullyApproved($rfq_id) {
        $status = $this->getApprovalStatus($rfq_id);
        return $status && 
               $status['spec_review_status'] === 'APPROVED' && 
               $status['branch_head_approval_status'] === 'APPROVED';
    }

    /**
     * Reset approval stages when the selected vendor/quotation changes.
     * Per business rules: any change to the selected vendor or quotation after
     * requestor approval must restart the requestor-review and Branch Head-approval stages.
     * @param int $rfq_id RFQ ID
     * @return bool Success
     */
    public function resetApprovalStages($rfq_id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE rfqs
                SET spec_review_status = 'PENDING',
                    spec_reviewer_id = NULL,
                    spec_reviewed_at = NULL,
                    spec_review_comments = NULL,
                    branch_head_approval_status = 'PENDING',
                    branch_head_approver_id = NULL,
                    branch_head_approved_at = NULL,
                    branch_head_comments = NULL,
                    quotes_locked = 0,
                    quotes_locked_at = NULL,
                    quotes_locked_by = NULL
                WHERE rfq_id = ?
            ");
            $stmt->execute([$rfq_id]);

            // Log the reset in audit trail
            $this->logApproval($rfq_id, 'SPEC_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'Approval stages reset due to quotation change');
            return true;
        } catch (Exception $e) {
            error_log("Error resetting approval stages for RFQ {$rfq_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log approval action to audit trail
     */
    private function logApproval($rfq_id, $stage, $action, $comments = '') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO rfq_quote_approvals 
                (rfq_id, approval_stage, approver_id, approver_role, action, comments, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$rfq_id, $stage, $this->user_id, $this->user_role, $action, $comments]);
        } catch (Exception $e) {
            error_log("Error logging approval: " . $e->getMessage());
        }
    }

    /**
     * Send notification to requestor for approval status changes
     */
    private function sendRequestorNotification($rfq_id, $event_type, $details = '') {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';

        try {
            if ((int)$rfq_id <= 0) {
                error_log("Notification[RequestorNotification]: invalid RFQ ID provided");
                return;
            }

            // Get RFQ and requestor details
            $stmt = $this->pdo->prepare("
                SELECT r.*, pr.request_number, pr.created_by, u.email, u.display_name
                FROM rfqs r
                JOIN procurement_requests pr ON r.request_id = pr.request_id
                JOIN users u ON pr.created_by = u.user_id
                WHERE r.rfq_id = ?
            ");
            $stmt->execute([$rfq_id]);
            $rfq = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rfq) {
                error_log("Notification[RequestorNotification]: RFQ {$rfq_id} not found");
                return;
            }

            $appUrl = getAppUrl();
            if ($appUrl === '') {
                error_log("Notification[RequestorNotification]: application URL could not be resolved for RFQ {$rfq_id}");
                return;
            }

            $subjectPrefix = "RFQ " . he($rfq['rfq_number']) . " (Request: " . he($rfq['request_number']) . ")";

            if ($event_type === 'spec_review_rejected') {
                $subject = "{$subjectPrefix} - Specification Review Returned for Corrections";
                $action = "returned by the Specification Reviewer";
            } elseif ($event_type === 'spec_review_returned') {
                $subject = "{$subjectPrefix} - Specification Review Returned for Clarification";
                $action = "returned for clarification";
            } elseif ($event_type === 'branch_head_approval_rejected') {
                $subject = "{$subjectPrefix} - Branch Head Approval Rejected";
                $action = "rejected by the Branch Head";
            } elseif ($event_type === 'branch_head_approval_returned') {
                $subject = "{$subjectPrefix} - Branch Head Approval Returned for Clarification";
                $action = "returned for clarification by the Branch Head";
            } else {
                return;
            }

            $rfqUrl = "{$appUrl}/rfq/view.php?id={$rfq_id}";
            $html = "
                <p>Your RFQ <strong>" . he($rfq['rfq_number']) . "</strong> has been {$action}.</p>
                <p><strong>Details:</strong> " . nl2br(he($details)) . "</p>
                <a href='" . he($rfqUrl) . "' class='btn btn-info'>View RFQ Details</a>
            ";

            if (notificationsEnabled()) {
                $recipients = [];
                if (!empty($rfq['email']) && filter_var($rfq['email'], FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $rfq['email'];
                } else {
                    error_log("Notification[RequestorNotification]: RFQ {$rfq_id} skipped invalid requestor email");
                }

                // Branch head rejections also need to reach the procurement team
                if ($event_type === 'branch_head_approval_rejected') {
                    $stmt = $this->pdo->prepare("
                        SELECT u.email FROM users u
                        INNER JOIN roles r ON u.role_id = r.id
                        WHERE r.name = 'Procurement Officer' AND u.is_active = 1
                    ");
                    $stmt->execute();
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $officer) {
                        if (!empty($officer['email']) && filter_var($officer['email'], FILTER_VALIDATE_EMAIL)) {
                            $recipients[] = $officer['email'];
                        }
                    }
                }

                foreach (array_unique($recipients) as $email) {
                    $sent = sendMail($email, $subject, $html);
                    error_log("Notification[RequestorNotification]: RFQ {$rfq_id} event={$event_type} recipient={$email} url={$rfqUrl} status=" . ($sent ? 'Sent' : 'Failed'));
                }
            }
        } catch (Exception $e) {
            error_log("Error sending requestor notification for RFQ {$rfq_id}: " . $e->getMessage());
        }
    }
}
