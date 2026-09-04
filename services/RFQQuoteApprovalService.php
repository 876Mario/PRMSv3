<?php

require_once __DIR__ . '/RequestorSpecificationReviewService.php';

class RFQQuoteApprovalService
{
    private PDO $pdo;
    private int $userId;
    private string $userRole;
    private RequestorSpecificationReviewService $requestorReviewService;

    public function __construct($pdo, $user_id, $user_role = null)
    {
        $this->pdo = $pdo;
        $this->userId = (int) $user_id;
        $this->userRole = (string) ($user_role ?? '');
        $this->requestorReviewService = new RequestorSpecificationReviewService($this->pdo, $this->userId, $this->userRole);
    }

    public function getPendingRequestorReviews(): array
    {
        return $this->requestorReviewService->getRequestorPendingReviews();
    }

    public function approveRequestorReview($rfq_id, $outcome, $comments = '', $quote_id = null, $requestor_notes = '', string $overrideReason = ''): bool
    {
        $commentPayload = trim((string) ($requestor_notes !== '' ? $requestor_notes : $comments));
        return $this->requestorReviewService->submitRequestorReview($rfq_id, $outcome, $commentPayload, $quote_id, $overrideReason);
    }

    public function rejectRequestorReview($rfq_id, $comments = '', $quote_id = null, string $overrideReason = ''): bool
    {
        return $this->requestorReviewService->rejectRequestorReview($rfq_id, $comments, $quote_id, $overrideReason);
    }

    public function getPendingBranchHeadApprovals(): array
    {
        $canOverride = $this->hasBranchHeadOverridePermission();
        $stmt = $this->pdo->prepare(
            "SELECT
                r.rfq_id,
                r.rfq_number,
                r.submission_deadline,
                r.requestor_spec_review_status,
                r.requestor_reviewed_at,
                r.requestor_review_comments,
                pr.request_id,
                pr.request_number,
                pr.description,
                pr.estimated_value,
                pr.branch_id,
                pr.created_by AS requestor_user_id,
                pr.status AS request_status,
                req.full_name AS requestor_name,
                rr.full_name AS requestor_reviewer_name,
                b.branch_name,
                sq.quote_id AS selected_quote_id,
                sq.quote_amount AS selected_quote_amount,
                sq.vendor_name AS selected_vendor_name
             FROM rfqs r
             JOIN procurement_requests pr ON pr.request_id = r.request_id
             LEFT JOIN users req ON req.user_id = pr.created_by
             LEFT JOIN users rr ON rr.user_id = r.requestor_reviewer_id
             LEFT JOIN branches b ON b.branch_id = pr.branch_id
             LEFT JOIN (
                 SELECT rv.rfq_id, q.quote_id, q.quote_amount, v.vendor_name
                   FROM rfq_quotes q
                   JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
                   JOIN vendors v ON v.vendor_id = rv.vendor_id
                  WHERE q.is_selected = 1
                    AND COALESCE(q.is_deleted, 0) = 0
             ) sq ON sq.rfq_id = r.rfq_id
             WHERE pr.status = 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'
               AND r.requestor_spec_review_status = 'APPROVED'
               AND r.branch_head_approval_status = 'PENDING'
              AND (
                  :can_override = 1
                  OR EXISTS (
                      SELECT 1
                        FROM users cu
                        JOIN roles cr ON cr.id = cu.role_id
                       WHERE cu.user_id = :current_user_id
                         AND cu.is_active = 1
                         AND (
                             (cr.name = 'Director HRM&A' AND (pr.branch_id = 5 OR UPPER(COALESCE(b.branch_name, '')) LIKE '%HRM%'))
                             OR (cr.name IN ('HOD', 'Branch Head') AND cu.branch_id = pr.branch_id)
                         )
                  )
              )
             ORDER BY r.submission_deadline ASC, r.created_at ASC"
        );
        $stmt->execute([
            ':can_override' => $canOverride ? 1 : 0,
            ':current_user_id' => $this->userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approveBranchHeadApproval($rfq_id, $comments = '', $quote_id = null, bool $confirmationChecked = true, string $overrideReason = ''): bool
    {
        return $this->decideBranchHeadApproval($rfq_id, 'APPROVE', $comments, $quote_id, $confirmationChecked, $overrideReason);
    }

    public function rejectBranchHeadApproval($rfq_id, $reason = '', $quote_id = null, string $overrideReason = ''): bool
    {
        return $this->decideBranchHeadApproval($rfq_id, 'REJECT', $reason, $quote_id, true, $overrideReason);
    }

    public function decideBranchHeadApproval($rfq_id, $decision, $comments = '', $quote_id = null, bool $confirmationChecked = false, string $overrideReason = ''): bool
    {
        $rfqId = (int) $rfq_id;
        $decision = strtoupper(trim((string) $decision));
        $comments = trim((string) $comments);

        if (!in_array($decision, ['APPROVE', 'REJECT', 'RETURN_FOR_CLARIFICATION'], true)) {
            throw new InvalidArgumentException('Invalid Branch Head decision.');
        }

        if (in_array($decision, ['REJECT', 'RETURN_FOR_CLARIFICATION'], true) && mb_strlen($comments) < 5) {
            throw new InvalidArgumentException('Comments must be at least 5 characters for rejection or return for clarification.');
        }

        if ($decision === 'APPROVE' && !$confirmationChecked) {
            throw new InvalidArgumentException('Confirmation is required before approving the vendor award.');
        }

        try {
            $this->pdo->beginTransaction();
            $context = $this->getRfqContext($rfqId, true);
            $this->assertBranchHeadActionAllowed($context, $overrideReason);
            $selectedQuote = $this->getSelectedQuote($rfqId);
            $this->assertBranchHeadStageState($context, $selectedQuote, $quote_id);
            error_log(sprintf(
                'RFQ workflow branch head decision: rfq_id=%d request_id=%d current_state=%s selected_quote_id=%s assigned_approver_id=%d actor_id=%d decision=%s',
                $rfqId,
                (int)($context['request_id'] ?? 0),
                (string)($context['request_status'] ?? ''),
                (string)($selectedQuote['quote_id'] ?? 'none'),
                $this->getAssignedBranchHeadId($context),
                $this->userId,
                $decision
            ));

            $requestStatus = 'QUOTE_APPROVED';
            $branchHeadStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            $requestorStatus = $context['requestor_spec_review_status'];
            $requestorReviewerId = $context['requestor_reviewer_id'];
            $requestorReviewedAt = $context['requestor_reviewed_at'];
            $requestorReviewComments = $context['requestor_review_comments'];
            $action = $decision === 'RETURN_FOR_CLARIFICATION' ? 'RETURNED_FOR_CLARIFICATION' : ($decision === 'APPROVE' ? 'APPROVED' : 'REJECTED');

            if ($decision === 'REJECT') {
                $requestStatus = 'QUOTE_REVIEW_PENDING';
                $requestorStatus = 'PENDING';
                $requestorReviewerId = null;
                $requestorReviewedAt = null;
                $requestorReviewComments = null;
            } elseif ($decision === 'RETURN_FOR_CLARIFICATION') {
                $requestStatus = 'QUOTE_REQUESTOR_REVIEW_PENDING';
                $requestorStatus = 'PENDING';
                $requestorReviewerId = null;
                $requestorReviewedAt = null;
                $requestorReviewComments = null;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE rfqs
                    SET requestor_spec_review_status = :requestor_status,
                        requestor_reviewer_id = :requestor_reviewer_id,
                        requestor_reviewed_at = :requestor_reviewed_at,
                        requestor_review_comments = :requestor_review_comments,
                        branch_head_approval_status = :branch_head_status,
                        branch_head_approver_id = :branch_head_approver_id,
                        branch_head_approved_at = CURRENT_TIMESTAMP,
                        branch_head_comments = :branch_head_comments
                  WHERE rfq_id = :rfq_id"
            );
            $stmt->execute([
                ':requestor_status' => $requestorStatus,
                ':requestor_reviewer_id' => $requestorReviewerId,
                ':requestor_reviewed_at' => $requestorReviewedAt,
                ':requestor_review_comments' => $requestorReviewComments,
                ':branch_head_status' => $branchHeadStatus,
                ':branch_head_approver_id' => $this->userId,
                ':branch_head_comments' => $comments !== '' ? $comments : null,
                ':rfq_id' => $rfqId,
            ]);

            $requestStmt = $this->pdo->prepare("UPDATE procurement_requests SET status = :status WHERE request_id = :request_id AND status = 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'");
            $requestStmt->execute([
                ':status' => $requestStatus,
                ':request_id' => (int) $context['request_id'],
            ]);
            if ($requestStmt->rowCount() !== 1) {
                throw new RuntimeException('The RFQ status changed while this approval was being submitted. Please reload and try again.');
            }

            $this->logApproval($rfqId, 'BRANCH_HEAD_APPROVAL', $action, $comments, $selectedQuote);

            if (function_exists('logRequestTimeline')) {
                $timelineAction = $decision === 'APPROVE'
                    ? 'QUOTE_APPROVED'
                    : ($decision === 'RETURN_FOR_CLARIFICATION' ? 'QUOTE_REQUESTOR_REVIEW_PENDING' : 'QUOTE_REVIEW_PENDING');
                $timelineNotes = match ($decision) {
                    'APPROVE' => 'Branch Head approved the selected quotation for RFQ ' . ($context['rfq_number'] ?? $rfqId),
                    'RETURN_FOR_CLARIFICATION' => 'Branch Head returned the selected quotation to the requestor for clarification: ' . $comments,
                    default => 'Branch Head rejected the selected quotation and returned the RFQ to procurement review: ' . $comments,
                };
                logRequestTimeline($this->pdo, (int) $context['request_id'], $timelineAction, $timelineNotes);
            }

            if ($this->isOverrideBranchHeadUser($context)) {
                $this->logBranchHeadOverrideAudit($rfqId, (int) $context['request_id'], $decision, $comments, $overrideReason);
            }

            $this->pdo->commit();

            if (isset($_SERVER['DOCUMENT_ROOT'])) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
                if ($decision === 'APPROVE') {
                    sendVendorAwardNotification($rfqId);
                } elseif ($decision === 'RETURN_FOR_CLARIFICATION') {
                    sendReturnForClarificationNotification($rfqId, 'BRANCH_HEAD_APPROVAL', $comments);
                } else {
                    sendRejectionNotification($rfqId, $comments);
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

    public function returnForClarification($rfq_id, $stage, $clarification_needed = '', $quote_id = null, string $overrideReason = ''): bool
    {
        $stage = strtoupper(trim((string) $stage));
        if (in_array($stage, ['BRANCH_HEAD_APPROVAL'], true)) {
            return $this->decideBranchHeadApproval($rfq_id, 'RETURN_FOR_CLARIFICATION', $clarification_needed, $quote_id, true, $overrideReason);
        }

        if (in_array($stage, ['SPEC_REVIEW', 'REQUESTOR_REVIEW'], true)) {
            return $this->rejectRequestorReview($rfq_id, $clarification_needed, $quote_id, $overrideReason);
        }

        throw new InvalidArgumentException('Invalid approval stage: ' . $stage);
    }

    public function getApprovalHistory($rfq_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, u.full_name AS approver_name, q.quote_amount, v.vendor_name
             FROM rfq_quote_approvals a
             LEFT JOIN users u ON u.user_id = a.approver_id
             LEFT JOIN rfq_quotes q ON q.quote_id = a.quote_id
             LEFT JOIN rfq_vendors rv ON rv.rfq_vendor_id = q.rfq_vendor_id
             LEFT JOIN vendors v ON v.vendor_id = rv.vendor_id
             WHERE a.rfq_id = ?
             ORDER BY a.created_at DESC, a.approval_id DESC"
        );
        $stmt->execute([(int) $rfq_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getApprovalStatus($rfq_id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rfq_id,
                    requestor_spec_review_status,
                    requestor_reviewer_id,
                    requestor_reviewed_at,
                    requestor_review_comments,
                    branch_head_approval_status,
                    branch_head_approver_id,
                    branch_head_approved_at,
                    branch_head_comments
             FROM rfqs
             WHERE rfq_id = ?"
        );
        $stmt->execute([(int) $rfq_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function isFullyApproved($rfq_id): bool
    {
        $status = $this->getApprovalStatus($rfq_id);
        return !empty($status)
            && ($status['requestor_spec_review_status'] ?? '') === 'APPROVED'
            && ($status['branch_head_approval_status'] ?? '') === 'APPROVED';
    }

    public function lockSelectedQuote($rfq_id, $quote_id): array
    {
        $selectedQuote = $this->getSelectedQuote((int) $rfq_id);
        if (!$selectedQuote) {
            throw new RuntimeException('No selected quote is currently locked for this RFQ.');
        }
        if ((int) $selectedQuote['quote_id'] !== (int) $quote_id) {
            throw new RuntimeException('The selected quote changed before this approval could be submitted.');
        }

        return $selectedQuote;
    }

    public function resetApprovalsOnQuoteChange($rfq_id): void
    {
        $rfqId = (int) $rfq_id;
        $stmt = $this->pdo->prepare(
            "UPDATE rfqs
                SET requestor_spec_review_status = 'PENDING',
                    requestor_reviewer_id = NULL,
                    requestor_reviewed_at = NULL,
                    requestor_review_comments = NULL,
                    branch_head_approval_status = 'PENDING',
                    branch_head_approver_id = NULL,
                    branch_head_approved_at = NULL,
                    branch_head_comments = NULL
              WHERE rfq_id = ?"
        );
        $stmt->execute([$rfqId]);
    }

    public function getRequestorReviewHistory($rfq_id): array
    {
        return $this->requestorReviewService->getRequestorReviewHistory($rfq_id);
    }

    private function getRfqContext(int $rfqId, bool $lock = false): array
    {
        $sql = "SELECT r.rfq_id,
                    r.rfq_number,
                    r.requestor_spec_review_status,
                    r.requestor_reviewer_id,
                    r.requestor_reviewed_at,
                    r.requestor_review_comments,
                    r.branch_head_approval_status,
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
             LIMIT 1";
        if ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$rfqId]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context) {
            throw new RuntimeException('RFQ not found.');
        }
        return $context;
    }

    private function getAssignedBranchHeadId(array $context): int
    {
        $ids = $this->getBranchHeadCandidateIds((int)($context['branch_id'] ?? 0), (string)($context['branch_name'] ?? ''));
        return (int)($ids[0] ?? 0);
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
                    q.is_selected,
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

    private function assertBranchHeadActionAllowed(array $context, string $overrideReason): void
    {
        if ($this->isActualBranchHeadForContext($context)) {
            return;
        }

        if (!$this->hasBranchHeadOverridePermission()) {
            throw new RuntimeException('Only the auto-routed Branch Head may decide this approval.');
        }

        if (mb_strlen(trim($overrideReason)) < 5) {
            throw new InvalidArgumentException('An override reason of at least 5 characters is required.');
        }
    }

    private function assertBranchHeadStageState(array $context, ?array $selectedQuote, $quoteId): void
    {
        $status = strtoupper((string) ($context['request_status'] ?? ''));
        if (in_array($status, ['CANCELLED', 'DECLINED'], true)) {
            throw new RuntimeException('This RFQ can no longer be approved in its current status.');
        }

        if ($status !== 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING') {
            throw new RuntimeException('This RFQ is not awaiting Branch Head approval.');
        }

        if (($context['requestor_spec_review_status'] ?? '') !== 'APPROVED') {
            throw new RuntimeException('The requestor specification confirmation must be approved first.');
        }

        if (!$selectedQuote) {
            throw new RuntimeException('A selected quote is required before Branch Head approval can proceed.');
        }

        if ($quoteId !== null && (int) $quoteId > 0 && (int) $selectedQuote['quote_id'] !== (int) $quoteId) {
            throw new RuntimeException('The selected quote changed before this approval could be submitted.');
        }
    }

    private function isActualBranchHeadForContext(array $context): bool
    {
        $candidateIds = $this->getBranchHeadCandidateIds((int) ($context['branch_id'] ?? 0), (string) ($context['branch_name'] ?? ''));
        return in_array($this->userId, $candidateIds, true);
    }

    private function getBranchHeadCandidateIds(int $branchId, string $branchName = ''): array
    {
        $candidates = [];
        $normalizedBranch = strtoupper(trim($branchName));
        $isHrmaBranch = $branchId === 5 || str_contains($normalizedBranch, 'HRM');

        if ($isHrmaBranch) {
            $stmt = $this->pdo->prepare(
                "SELECT u.user_id
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name = 'Director HRM&A'
                    AND u.is_active = 1"
            );
            $stmt->execute();
            $candidates = array_merge($candidates, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        if ($branchId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT u.user_id
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name IN ('HOD', 'Branch Head')
                    AND u.is_active = 1
                    AND u.branch_id = ?"
            );
            $stmt->execute([$branchId]);
            $candidates = array_merge($candidates, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function logApproval(int $rfqId, string $stage, string $action, string $comments = '', ?array $selectedQuote = null): void
    {
        $quote = $selectedQuote ?: $this->getSelectedQuote($rfqId);
        $snapshot = null;
        $quoteId = null;
        if ($quote) {
            $quoteId = (int) ($quote['quote_id'] ?? 0);
            $snapshot = json_encode([
                'quote_id' => $quoteId,
                'vendor_name' => $quote['vendor_name'] ?? null,
                'quote_amount' => isset($quote['quote_amount']) ? (float) $quote['quote_amount'] : null,
                'gct_amount' => isset($quote['gct_amount']) ? (float) $quote['gct_amount'] : null,
                'currency' => $quote['currency'] ?? null,
                'submitted_at' => $quote['submitted_at'] ?? null,
                'review_status' => $quote['review_status'] ?? null,
                'review_comments' => $quote['review_comments'] ?? null,
                'quote_file' => $quote['quote_file'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO rfq_quote_approvals
                (rfq_id, quote_id, approval_stage, approver_id, approver_role, action, comments, rejection_reason, requestor_notes, vendor_submission_details, created_at)
             VALUES
                (:rfq_id, :quote_id, :approval_stage, :approver_id, :approver_role, :action, :comments, :rejection_reason, :requestor_notes, :vendor_submission_details, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            ':rfq_id' => $rfqId,
            ':quote_id' => $quoteId ?: null,
            ':approval_stage' => $stage,
            ':approver_id' => $this->userId,
            ':approver_role' => $this->userRole !== '' ? $this->userRole : null,
            ':action' => $action,
            ':comments' => $comments !== '' ? $comments : null,
            ':rejection_reason' => in_array($action, ['REJECTED', 'RETURNED_FOR_CLARIFICATION'], true) ? $comments : null,
            ':requestor_notes' => $comments !== '' ? $comments : null,
            ':vendor_submission_details' => $snapshot,
        ]);
    }

    private function hasBranchHeadOverridePermission(): bool
    {
        return function_exists('hasPermission')
            && (hasPermission('override_branch_head_approval') || hasPermission('admin_override_approvals'));
    }

    private function isOverrideBranchHeadUser(array $context): bool
    {
        return !$this->isActualBranchHeadForContext($context) && $this->hasBranchHeadOverridePermission();
    }

    private function logBranchHeadOverrideAudit(int $rfqId, int $requestId, string $decision, string $comments, string $overrideReason): void
    {
        if (!function_exists('logAudit')) {
            return;
        }

        $notes = sprintf(
            'Branch Head approval override for RFQ %d. Decision: %s. Override reason: %s. Comments: %s',
            $rfqId,
            $decision,
            $overrideReason,
            $comments !== '' ? $comments : 'N/A'
        );
        logAudit($this->pdo, 'procurement_requests', $requestId, 'RFQ_BRANCH_HEAD_OVERRIDE', $notes);
        $auditId = (int) $this->pdo->lastInsertId();
        if ($auditId > 0) {
            $stmt = $this->pdo->prepare(
                "UPDATE audit_log
                    SET approval_stage = 'BRANCH_HEAD_APPROVAL',
                        approval_action = 'OVERRIDE',
                        approval_comments = :approval_comments,
                        specification_comparison = :comparison
                  WHERE audit_id = :audit_id"
            );
            $stmt->execute([
                ':approval_comments' => $overrideReason,
                ':comparison' => $comments,
                ':audit_id' => $auditId,
            ]);
        }
    }
}
