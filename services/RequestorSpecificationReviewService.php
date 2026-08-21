<?php

if (!function_exists('logAudit') && isset($_SERVER['DOCUMENT_ROOT'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
}

class RequestorSpecificationReviewService
{
    private PDO $pdo;
    private int $userId;
    private string $userRole;

    public function __construct(PDO $pdo, int $userId, string $userRole = '')
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->userRole = $userRole;
    }

    public function getRequestorPendingReviews(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                r.rfq_id,
                r.rfq_number,
                r.submission_deadline,
                r.requestor_spec_review_status,
                pr.request_id,
                pr.request_number,
                pr.description,
                pr.estimated_value,
                pr.created_by AS requestor_user_id,
                u.full_name AS requestor_name,
                COUNT(q.quote_id) AS quote_count,
                SUM(CASE WHEN q.is_selected = 1 THEN 1 ELSE 0 END) AS selected_quote_count,
                MAX(CASE WHEN q.is_selected = 1 THEN v.vendor_name ELSE NULL END) AS selected_vendor_name,
                MAX(CASE WHEN q.is_selected = 1 THEN q.quote_amount ELSE NULL END) AS selected_quote_amount,
                r.created_at
             FROM rfqs r
             JOIN procurement_requests pr ON pr.request_id = r.request_id
             LEFT JOIN users u ON u.user_id = pr.created_by
             LEFT JOIN rfq_vendors rv ON rv.rfq_id = r.rfq_id
             LEFT JOIN rfq_quotes q ON q.rfq_vendor_id = rv.rfq_vendor_id AND COALESCE(q.is_deleted, 0) = 0
             LEFT JOIN vendors v ON v.vendor_id = rv.vendor_id
             WHERE pr.status = 'QUOTE_REQUESTOR_REVIEW_PENDING'
               AND r.requestor_spec_review_status = 'PENDING'
               AND (pr.created_by = :user_id OR :can_override = 1)
             GROUP BY r.rfq_id, r.rfq_number, r.submission_deadline, r.requestor_spec_review_status,
                      pr.request_id, pr.request_number, pr.description, pr.estimated_value,
                      pr.created_by, u.full_name, r.created_at
             ORDER BY r.submission_deadline ASC, r.created_at ASC"
        );
        $stmt->execute([
            ':user_id' => $this->userId,
            ':can_override' => $this->hasOverridePermission() ? 1 : 0,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submitRequestorReview($rfq_id, $outcome, $comments, $quote_id = null, string $overrideReason = ''): bool
    {
        $rfqId = (int) $rfq_id;
        $outcome = strtoupper(trim((string) $outcome));
        $comments = trim((string) $comments);

        if (!in_array($outcome, ['MEETS_SPECIFICATIONS', 'DOES_NOT_MEET_SPECIFICATIONS'], true)) {
            throw new InvalidArgumentException('Invalid requestor review outcome.');
        }

        if ($outcome === 'DOES_NOT_MEET_SPECIFICATIONS' && mb_strlen($comments) < 5) {
            throw new InvalidArgumentException('Comments must be at least 5 characters when the quotation does not meet specifications.');
        }

        try {
            $this->pdo->beginTransaction();

            $context = $this->getRfqContext($rfqId);
            $this->assertRequestorActionAllowed($context, $overrideReason);
            $selectedQuote = $this->getSelectedQuote($rfqId);
            $this->assertStageState($context, $selectedQuote, $quote_id);

            $requestorStatus = $outcome === 'MEETS_SPECIFICATIONS' ? 'APPROVED' : 'REJECTED';
            $nextStatus = $outcome === 'MEETS_SPECIFICATIONS'
                ? 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
                : 'QUOTE_REVIEW_PENDING';

            $rfqUpdate = $this->pdo->prepare(
                "UPDATE rfqs
                    SET requestor_spec_review_status = :status,
                        requestor_reviewer_id = :reviewer_id,
                        requestor_reviewed_at = CURRENT_TIMESTAMP,
                        requestor_review_comments = :comments,
                        branch_head_approval_status = 'PENDING',
                        branch_head_approver_id = NULL,
                        branch_head_approved_at = NULL,
                        branch_head_comments = NULL
                  WHERE rfq_id = :rfq_id"
            );
            $rfqUpdate->execute([
                ':status' => $requestorStatus,
                ':reviewer_id' => $this->userId,
                ':comments' => $comments !== '' ? $comments : null,
                ':rfq_id' => $rfqId,
            ]);

            $historyStmt = $this->pdo->prepare(
                "INSERT INTO rfq_requestor_reviews
                    (rfq_id, requestor_id, review_outcome, comments, review_date, created_at, updated_at)
                 VALUES
                    (:rfq_id, :requestor_id, :outcome, :comments, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $historyStmt->execute([
                ':rfq_id' => $rfqId,
                ':requestor_id' => $this->userId,
                ':outcome' => $outcome,
                ':comments' => $comments !== '' ? $comments : null,
            ]);

            $this->logApprovalAudit($rfqId, $outcome, $comments, $selectedQuote);

            $requestUpdate = $this->pdo->prepare("UPDATE procurement_requests SET status = :status WHERE request_id = :request_id");
            $requestUpdate->execute([
                ':status' => $nextStatus,
                ':request_id' => (int) $context['request_id'],
            ]);

            if (function_exists('logRequestTimeline')) {
                logRequestTimeline(
                    $this->pdo,
                    (int) $context['request_id'],
                    $outcome === 'MEETS_SPECIFICATIONS' ? 'QUOTE_REQUESTOR_REVIEW_APPROVED' : 'QUOTE_REVIEW_PENDING',
                    $outcome === 'MEETS_SPECIFICATIONS'
                        ? 'Requestor specification confirmation approved for RFQ ' . ($context['rfq_number'] ?? $rfqId)
                        : 'Requestor returned selected quotation to procurement review: ' . $comments
                );
            }

            if ($this->isOverrideUser((int) $context['requestor_user_id'])) {
                $this->logOverrideAudit(
                    $rfqId,
                    (int) $context['request_id'],
                    $outcome,
                    $comments,
                    $overrideReason,
                    (string) ($selectedQuote['vendor_name'] ?? '')
                );
            }

            $this->pdo->commit();

            if (isset($_SERVER['DOCUMENT_ROOT'])) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
                if ($outcome === 'MEETS_SPECIFICATIONS') {
                    sendBranchHeadApprovalNotification($rfqId);
                } else {
                    sendRequestorRejectionNotification($rfqId, $comments);
                }
            }

            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function rejectRequestorReview($rfq_id, $comments, $quote_id = null, string $overrideReason = ''): bool
    {
        return $this->submitRequestorReview($rfq_id, 'DOES_NOT_MEET_SPECIFICATIONS', $comments, $quote_id, $overrideReason);
    }

    public function getRequestorReviewHistory($rfq_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*, u.full_name AS reviewer_name
             FROM rfq_requestor_reviews rr
             LEFT JOIN users u ON u.user_id = rr.requestor_id
             WHERE rr.rfq_id = ?
             ORDER BY rr.review_date DESC, rr.rfq_requestor_review_id DESC"
        );
        $stmt->execute([(int) $rfq_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private function logApprovalAudit(int $rfqId, string $outcome, string $comments, array $selectedQuote): void
    {
        $snapshot = json_encode([
            'quote_id' => (int)($selectedQuote['quote_id'] ?? 0),
            'vendor_name' => $selectedQuote['vendor_name'] ?? null,
            'quote_amount' => isset($selectedQuote['quote_amount']) ? (float)$selectedQuote['quote_amount'] : null,
            'gct_amount' => isset($selectedQuote['gct_amount']) ? (float)$selectedQuote['gct_amount'] : null,
            'currency' => $selectedQuote['currency'] ?? null,
            'submitted_at' => $selectedQuote['submitted_at'] ?? null,
            'review_status' => $selectedQuote['review_status'] ?? null,
            'review_comments' => $selectedQuote['review_comments'] ?? null,
            'quote_file' => $selectedQuote['quote_file'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->pdo->prepare(
            "INSERT INTO rfq_quote_approvals
                (rfq_id, quote_id, approval_stage, approver_id, approver_role, action, comments, rejection_reason, requestor_notes, vendor_submission_details, created_at)
             VALUES
                (:rfq_id, :quote_id, 'REQUESTOR_REVIEW', :approver_id, :approver_role, :action, :comments, :rejection_reason, :requestor_notes, :vendor_submission_details, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            ':rfq_id' => $rfqId,
            ':quote_id' => (int)($selectedQuote['quote_id'] ?? 0) ?: null,
            ':approver_id' => $this->userId,
            ':approver_role' => $this->userRole !== '' ? $this->userRole : null,
            ':action' => $outcome === 'MEETS_SPECIFICATIONS' ? 'APPROVED' : 'REJECTED',
            ':comments' => $comments !== '' ? $comments : null,
            ':rejection_reason' => $outcome === 'DOES_NOT_MEET_SPECIFICATIONS' ? $comments : null,
            ':requestor_notes' => $comments !== '' ? $comments : null,
            ':vendor_submission_details' => $snapshot,
        ]);
    }

    private function getRfqContext(int $rfqId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.rfq_id,
                    r.rfq_number,
                    r.requestor_spec_review_status,
                    r.branch_head_approval_status,
                    r.requestor_reviewer_id,
                    r.requestor_reviewed_at,
                    r.requestor_review_comments,
                    pr.request_id,
                    pr.request_number,
                    pr.status AS request_status,
                    pr.created_by AS requestor_user_id,
                    pr.branch_id,
                    pr.description,
                    pr.estimated_value,
                    req.full_name AS requestor_name,
                    b.branch_name
             FROM rfqs r
             JOIN procurement_requests pr ON pr.request_id = r.request_id
             LEFT JOIN users req ON req.user_id = pr.created_by
             LEFT JOIN branches b ON b.branch_id = pr.branch_id
             WHERE r.rfq_id = ?
             LIMIT 1"
        );
        $stmt->execute([$rfqId]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$context) {
            throw new RuntimeException('RFQ not found.');
        }

        return $context;
    }

    private function getSelectedQuote(int $rfqId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT q.quote_id,
                    q.quote_amount,
                    q.gct_amount,
                    q.review_status,
                    q.review_comments,
                    q.quote_file,
                    q.submitted_at,
                    q.currency,
                    q.usd_rate,
                    v.vendor_name,
                    v.contact_person,
                    v.email AS vendor_email
             FROM rfq_quotes q
             JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
             JOIN vendors v ON v.vendor_id = rv.vendor_id
             WHERE rv.rfq_id = ?
               AND q.is_selected = 1
               AND COALESCE(q.is_deleted, 0) = 0
             ORDER BY q.quote_id DESC
             LIMIT 1"
        );
        $stmt->execute([$rfqId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);

        return $quote ?: null;
    }

    private function assertRequestorActionAllowed(array $context, string $overrideReason): void
    {
        $requestorUserId = (int) ($context['requestor_user_id'] ?? 0);
        if ($requestorUserId === $this->userId) {
            return;
        }

        if (!$this->hasOverridePermission()) {
            throw new RuntimeException('Only the original requestor may submit this specification confirmation.');
        }

        if (mb_strlen(trim($overrideReason)) < 5) {
            throw new InvalidArgumentException('An override reason of at least 5 characters is required.');
        }
    }

    private function assertStageState(array $context, ?array $selectedQuote, $quoteId): void
    {
        $status = strtoupper((string) ($context['request_status'] ?? ''));
        if (in_array($status, ['CANCELLED', 'DECLINED'], true)) {
            throw new RuntimeException('This RFQ can no longer be reviewed in its current status.');
        }

        if ($status !== 'QUOTE_REQUESTOR_REVIEW_PENDING') {
            throw new RuntimeException('This RFQ is not awaiting requestor specification confirmation.');
        }

        if (!$selectedQuote) {
            throw new RuntimeException('A selected quote is required before the requestor can confirm specifications.');
        }

        if ($quoteId !== null && (int) $quoteId > 0 && (int) $selectedQuote['quote_id'] !== (int) $quoteId) {
            throw new RuntimeException('The selected quote changed before this review was submitted. Please reopen the RFQ and try again.');
        }
    }

    private function logOverrideAudit(
        int $rfqId,
        int $requestId,
        string $outcome,
        string $comments,
        string $overrideReason,
        string $vendorName
    ): void {
        if (!function_exists('logAudit')) {
            return;
        }

        $notes = sprintf(
            'Requestor review override for RFQ %d. Outcome: %s. Override reason: %s. Vendor: %s. Comments: %s',
            $rfqId,
            $outcome,
            $overrideReason,
            $vendorName !== '' ? $vendorName : 'N/A',
            $comments !== '' ? $comments : 'N/A'
        );
        logAudit($this->pdo, 'procurement_requests', $requestId, 'RFQ_REQUESTOR_OVERRIDE', $notes);

        $auditId = (int) $this->pdo->lastInsertId();
        if ($auditId > 0) {
            $stmt = $this->pdo->prepare(
                "UPDATE audit_log
                    SET approval_stage = 'REQUESTOR_REVIEW',
                        approval_action = 'OVERRIDE',
                        approval_comments = :approval_comments,
                        requestor_review_outcome = :review_outcome,
                        specification_comparison = :comparison
                  WHERE audit_id = :audit_id"
            );
            $stmt->execute([
                ':approval_comments' => $overrideReason,
                ':review_outcome' => $outcome,
                ':comparison' => $comments,
                ':audit_id' => $auditId,
            ]);
        }
    }

    private function hasOverridePermission(): bool
    {
        return function_exists('hasPermission') && (hasPermission('override_requestor_review') || hasPermission('admin_override_approvals'));
    }

    private function isOverrideUser(int $requestorUserId): bool
    {
        return $requestorUserId > 0 && $requestorUserId !== $this->userId;
    }
}
