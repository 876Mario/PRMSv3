<?php

if (!function_exists('getAdminWorkflowStatusOptions') && isset($_SERVER['DOCUMENT_ROOT'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
}
if (!function_exists('logRequestTimeline') && isset($_SERVER['DOCUMENT_ROOT'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
}

class AdminWorkflowOverrideService
{
    private PDO $pdo;
    private int $adminUserId;
    private string $adminRole;
    private string $adminName;

    public function __construct(PDO $pdo, int $adminUserId, string $adminRole, string $adminName)
    {
        $this->pdo = $pdo;
        $this->adminUserId = $adminUserId;
        $this->adminRole = $adminRole;
        $this->adminName = $adminName;
    }

    public function overrideStatus(int $requestId, string $newStatus, string $reason): array
    {
        if (!$this->isAdmin()) {
            return ['success' => false, 'changed' => false, 'error' => 'Only Admin and SuperAdmin users can change workflow status.'];
        }

        $newStatus = strtoupper(trim($newStatus));
        $reason = trim($reason);
        $allowed = getAdminWorkflowStatusOptions();

        if (!isset($allowed[$newStatus])) {
            return ['success' => false, 'changed' => false, 'error' => 'Invalid workflow status selected.'];
        }
        if ($reason === '' || mb_strlen($reason) < 5) {
            return ['success' => false, 'changed' => false, 'error' => 'A reason of at least 5 characters is required for workflow status overrides.'];
        }

        $request = $this->loadRequest($requestId);
        if (!$request) {
            return ['success' => false, 'changed' => false, 'error' => 'Request not found.'];
        }

        $oldStatus = strtoupper((string)($request['status'] ?? ''));
        if ($oldStatus === $newStatus) {
            return ['success' => true, 'changed' => false, 'error' => 'No workflow status change was made.'];
        }

        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                   SET status = :status,
                       updated_at = NOW()
                 WHERE request_id = :request_id
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':request_id' => $requestId,
            ]);

            $this->logAdminEditAudit($request, $oldStatus, $newStatus, $reason);
            $this->logWorkflowAudit($requestId, $oldStatus, $newStatus, $reason);

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notifyResponsibleUsers($requestId, $newStatus);

        return ['success' => true, 'changed' => true, 'error' => ''];
    }

    private function isAdmin(): bool
    {
        return in_array($this->adminRole, ['Admin', 'SuperAdmin'], true);
    }

    private function loadRequest(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT pr.*, u.full_name AS requestor_name
              FROM procurement_requests pr
              LEFT JOIN users u ON u.user_id = pr.created_by
             WHERE pr.request_id = ?
             LIMIT 1
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        return $request ?: null;
    }

    private function logAdminEditAudit(array $request, string $oldStatus, string $newStatus, string $reason): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO admin_edit_audit
                (request_id, request_type, request_number, field_name, old_value, new_value,
                 change_reason, edited_by, editor_role, editor_ip_address, editor_user_agent,
                 edited_at, requires_re_approval, approval_stages_affected)
            VALUES
                (:request_id, :request_type, :request_number, 'status', :old_value, :new_value,
                 :change_reason, :edited_by, :editor_role, :ip_address, :user_agent,
                 NOW(), 0, :approval_stages_affected)
        ");
        $stmt->execute([
            ':request_id' => (int)$request['request_id'],
            ':request_type' => $request['request_type'] ?? 'REGULAR',
            ':request_number' => $request['request_number'] ?? '',
            ':old_value' => $oldStatus,
            ':new_value' => $newStatus,
            ':change_reason' => $reason,
            ':edited_by' => $this->adminUserId,
            ':editor_role' => $this->adminRole,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ':approval_stages_affected' => json_encode([$oldStatus, $newStatus]),
        ]);
    }

    private function logWorkflowAudit(int $requestId, string $oldStatus, string $newStatus, string $reason): void
    {
        $notes = sprintf(
            'Admin workflow override by %s: %s → %s. Reason: %s',
            $this->adminName,
            $oldStatus,
            $newStatus,
            $reason
        );

        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, change_date, notes,
                 approval_stage, approval_action, approval_comments)
            VALUES
                ('procurement_requests', :request_id, 'ADMIN_OVERRIDE', :changed_by, NOW(), :notes,
                 :approval_stage, 'OVERRIDE', :comments)
        ");
        $stmt->execute([
            ':request_id' => $requestId,
            ':changed_by' => $this->adminName,
            ':notes' => $notes,
            ':approval_stage' => $newStatus,
            ':comments' => $reason,
        ]);
    }

    private function notifyResponsibleUsers(int $requestId, string $newStatus): void
    {
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
        }

        $request = $this->loadRequest($requestId);
        if (!$request) {
            return;
        }

        if ($newStatus === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING') {
            $rfqId = $this->getRfqId($requestId);
            if ($rfqId && function_exists('sendBranchHeadApprovalNotification')) {
                sendBranchHeadApprovalNotification($rfqId);
            }
        }

        if (!class_exists('NotificationService') && isset($_SERVER['DOCUMENT_ROOT'])) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/services/NotificationService.php';
        }
        if (!class_exists('NotificationService')) {
            return;
        }

        foreach ($this->responsibleUserIds($request, $newStatus) as $userId) {
            NotificationService::createNotification((int)$userId, NotificationService::TYPE_APPROVAL_NEEDED, [
                'title' => 'Workflow status changed',
                'body' => 'Request ' . ($request['request_number'] ?? ('#' . $requestId)) . ' was manually moved to ' . (getAdminWorkflowStatusOptions()[$newStatus]['label'] ?? $newStatus) . '.',
                'request_id' => $requestId,
                'request_ref' => $request['request_number'] ?? null,
                'action_url' => '/procurement/view.php?id=' . $requestId,
                'stage' => $newStatus,
                'requestor_name' => $request['requestor_name'] ?? null,
                'priority' => $newStatus === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' ? 'high' : 'normal',
            ]);
        }
    }

    private function getRfqId(int $requestId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT rfq_id FROM rfqs WHERE request_id = ? ORDER BY rfq_id DESC LIMIT 1");
        $stmt->execute([$requestId]);
        $rfqId = $stmt->fetchColumn();
        return $rfqId ? (int)$rfqId : null;
    }

    private function responsibleUserIds(array $request, string $status): array
    {
        $roleNames = match ($status) {
            'SUBMITTED' => ['HOD', 'Branch Head'],
            'DIRECTOR_APPROVED' => ['Procurement Officer', 'Director Procurement'],
            'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED', 'COMMITMENTS_PENDING', 'PO_PENDING' => ['Procurement Officer', 'Director Procurement'],
            'QUOTE_REQUESTOR_REVIEW_PENDING' => ['Requestor'],
            'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['Branch Head'],
            'FUNDS_VERIFIED', 'COMMITMENT_APPROVED', 'INVOICE_RECEIVED' => ['Finance Officer'],
            default => [],
        };

        $userIds = [];
        if (in_array('Requestor', $roleNames, true) && !empty($request['created_by'])) {
            $userIds[] = (int)$request['created_by'];
            $roleNames = array_values(array_diff($roleNames, ['Requestor']));
        }

        if ($roleNames) {
            $placeholders = implode(',', array_fill(0, count($roleNames), '?'));
            $params = $roleNames;
            $branchScoped = ['HOD', 'Branch Head', 'Finance Officer'];
            $needsBranchScope = (bool)array_intersect($roleNames, $branchScoped);

            $sql = "
                SELECT DISTINCT u.user_id
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                 WHERE u.is_active = 1
                   AND r.name IN ($placeholders)
            ";
            if ($needsBranchScope) {
                $sql .= " AND (r.name NOT IN ('HOD', 'Branch Head', 'Finance Officer') OR u.branch_id = ?)";
                $params[] = (int)($request['branch_id'] ?? 0);
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $userIds = array_merge($userIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        return array_values(array_unique(array_filter($userIds)));
    }
}
