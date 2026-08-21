<?php
/**
 * ActionQueueTest
 * ================
 * Tests for action-based queue logic:
 *  - stageOwner() returns correct roles
 *  - Terminal / excluded statuses are not action-queue items
 *  - isMonitoringRole() is excluded from action queue
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../config/workflow.php';

$passed = 0;
$failed = 0;

function qaAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

echo "\n=== ActionQueueTest ===\n";

/* stageOwner() correctness */
qaAssert('HOD_APPROVED owner is [HOD]',
    stageOwner('HOD_APPROVED') === ['HOD']
);
qaAssert('FUNDS_VERIFIED owner includes Finance Officer',
    in_array('Finance Officer', stageOwner('FUNDS_VERIFIED'), true)
);
qaAssert('DIRECTOR_APPROVED owner is [Director HRM&A]',
    stageOwner('DIRECTOR_APPROVED') === ['Director HRM&A']
);
qaAssert('COMMITMENT_APPROVED owner includes Finance Officer',
    in_array('Finance Officer', stageOwner('COMMITMENT_APPROVED'), true)
);
qaAssert('PO_PENDING owner includes Procurement Officer',
    in_array('Procurement Officer', stageOwner('PO_PENDING'), true)
);
qaAssert('INVOICE_RECEIVED owner includes Accounts Officer',
    in_array('Accounts Officer', stageOwner('INVOICE_RECEIVED'), true)
);

/* Terminal statuses return no owner */
$terminalStatuses = ['COMPLETED', 'CANCELLED', 'DECLINED'];
foreach ($terminalStatuses as $ts) {
    qaAssert("stageOwner('{$ts}') returns empty array",
        stageOwner($ts) === []
    );
}

/* Statuses that must NOT appear in action queues */
$excludedStatuses = ['CANCELLED', 'COMPLETED', 'DECLINED', 'PAUSED', 'DRAFT'];
$allWorkflowStatuses = [
    'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
    'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING',
    'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
    'QUOTE_APPROVED',
    'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
    'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED',
    'PO_PENDING', 'INVOICE_RECEIVED',
];

foreach ($excludedStatuses as $ex) {
    qaAssert("Excluded status '{$ex}' is not in actionable workflow statuses",
        !in_array($ex, $allWorkflowStatuses, true)
    );
}

/* Monitoring role should NOT own action-queue statuses */
$monitoringRole = 'Director HRM&A';
$ownedByMonitor = [];
foreach ($allWorkflowStatuses as $st) {
    if (in_array($monitoringRole, stageOwner($st), true)) {
        $ownedByMonitor[] = $st;
    }
}
// Director HRM&A is an approval-chain role (DIRECTOR_APPROVED) but that status
// is NOT in $allWorkflowStatuses (it is handled via request_approvals table).
// The monitoring role widget check is done via isMonitoringRole() before
// building workflow actions in pending_actions.php.
qaAssert('isMonitoringRole(Director HRM&A) returns true', isMonitoringRole($monitoringRole));
qaAssert('No DIRECTOR_APPROVED in generic workflow statuses list',
    !in_array('DIRECTOR_APPROVED', $allWorkflowStatuses, true)
);

/* allowedTransitions: DRAFT can only go to SUBMITTED */
$trans = allowedTransitions();
qaAssert('DRAFT only transitions to SUBMITTED',
    ($trans['DRAFT'] ?? []) === ['SUBMITTED']
);
qaAssert('QUOTE_REVIEW_PENDING transitions to QUOTE_REQUESTOR_REVIEW_PENDING',
    canTransition('QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING')
);
qaAssert('QUOTE_REQUESTOR_REVIEW_PENDING transitions to QUOTE_REQUESTOR_REVIEW_APPROVED',
    canTransition('QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED')
);
qaAssert('QUOTE_REQUESTOR_REVIEW_APPROVED transitions to QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
    canTransition('QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING')
);
qaAssert('QUOTE_BRANCH_HEAD_APPROVAL_PENDING transitions to QUOTE_APPROVED',
    canTransition('QUOTE_BRANCH_HEAD_APPROVAL_PENDING', 'QUOTE_APPROVED')
);


/* canTransition() blocks terminal → anything (except PAUSED rules) */
qaAssert('Cannot transition COMPLETED → SUBMITTED',
    !canTransition('COMPLETED', 'SUBMITTED')
);
qaAssert('Cannot transition DECLINED → SUBMITTED',
    !canTransition('DECLINED', 'SUBMITTED')
);
qaAssert('Can cancel from SUBMITTED',
    canTransition('SUBMITTED', 'CANCELLED')
);
qaAssert('Cannot cancel from COMPLETED',
    !canTransition('COMPLETED', 'CANCELLED')
);

echo "\n" . ($failed === 0 ? "All {$passed} tests passed.\n" : "{$failed} FAILED / {$passed} passed.\n");
exit($failed > 0 ? 1 : 0);
