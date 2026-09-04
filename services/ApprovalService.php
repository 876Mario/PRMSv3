<?php

class ApprovalService {

    private $pdo;
    private $user_id;
    private $role;

    public function __construct($pdo, $user_id, $role) {
        $this->pdo = $pdo;
        $this->user_id = $user_id;
        $this->role = $role;
    }

    public function approve($entity_type, $entity_id) {
        $approval = $this->getNextPendingStage($entity_type, $entity_id);
        if (!$approval) {
            throw new Exception("No pending approval stages.");
        }

        if ($approval['role'] !== $this->role) {
            throw new Exception("You cannot approve out of sequence.");
        }

        $this->pdo->prepare("
            UPDATE request_approvals
            SET status='approved',
                approved_by=?,
                approved_at=CURRENT_TIMESTAMP
            WHERE id=?
        ")->execute([$this->user_id, $approval['id']]);

        return $this->isFullyApproved($entity_type, $entity_id);
    }

    public function isFullyApproved($entity_type, $entity_id) {

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM request_approvals
            WHERE entity_type = ?
              AND entity_id = ?
              AND status = 'pending'
        ");
        $stmt->execute([$entity_type, $entity_id]);

        return $stmt->fetchColumn() == 0;
    }

    public function reject($entity_type, $entity_id, $reason) {
        $approval = $this->getNextPendingStage($entity_type, $entity_id);
        if (!$approval || $approval['role'] !== $this->role) {
            throw new Exception("Not authorized to reject this stage.");
        }

        $this->pdo->prepare("
            UPDATE request_approvals
            SET status='rejected',
                rejection_reason=?,
                approved_by=?,
                approved_at=CURRENT_TIMESTAMP
            WHERE id=?
        ")->execute([
            $reason,
            $this->user_id,
            $approval['id']
        ]);
    }

    private function getNextPendingStage($entity_type, $entity_id) {
        $stmt = $this->pdo->prepare("
            SELECT id, role, stage_order
            FROM request_approvals
            WHERE entity_type = ?
              AND entity_id = ?
              AND status = 'pending'
            ORDER BY stage_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$entity_type, $entity_id]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        return $approval ?: null;
    }
}
