<?php
/**
 * Unified Pending Approvals Widget
 * ═══════════════════════════════════════════════════════════════
 * Shows pending approvals for REQUESTS, COMMITMENTS, and POs
 * with dynamic filtering via tabs/buttons
 */

if (!isset($pdo)) {
    require_once __DIR__.'/_init.php';
}

$userRole = $_SESSION['role'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

// Get selected entity type from GET/SESSION, default to REQUEST
$selectedType = $_GET['approval_type'] ?? $_SESSION['approval_type'] ?? 'REQUEST';
$_SESSION['approval_type'] = $selectedType;

// ═══════════════════════════════════════════════════════════════
// Fetch pending approvals for current user's role
// ═══════════════════════════════════════════════════════════════

$approvals = [];
$counts = ['REQUEST' => 0, 'COMMITMENT' => 0, 'PO' => 0, 'WORKFLOW' => 0];

// REQUEST: Procurement requests pending approval
$requestStmt = $pdo->prepare("
    SELECT 
        'REQUEST' as entity_type,
        ra.id,
        ra.entity_id,
        ra.request_id,
        pr.request_number,
        pr.request_type,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        ra.role as required_role,
        ra.stage_order,
        ra.status,
        ra.created_at,
        b.branch_name,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = ?
      AND ra.status = 'pending'
      AND UPPER(pr.status) NOT IN ('DRAFT','CANCELLED','PAUSED','DECLINED','COMPLETED','AWARDED')
    ORDER BY ra.created_at ASC
");
$requestStmt->execute([$userRole]);
$requests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);
$counts['REQUEST'] = count($requests);
if ($selectedType === 'REQUEST') {
    $approvals = $requests;
}

// COMMITMENT: No longer requires approval (auto-approved)
$counts['COMMITMENT'] = 0;

// PO: No longer requires approval (auto-approved)
$counts['PO'] = 0;

// If user selected a removed tab, redirect to REQUEST
if (in_array($selectedType, ['COMMITMENT', 'PO'])) {
    $selectedType = 'REQUEST';
    $_SESSION['approval_type'] = $selectedType;
    $approvals = $requests;
}

// WORKFLOW: Requests at workflow stages needing action by THIS user's role
// Uses stageOwner() from config/workflow.php to map statuses to responsible roles
$allWorkflowStatuses = [
    'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
    'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED',
    'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
    'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'
];

// Filter to only statuses this role is responsible for
$myStatuses = [];
foreach ($allWorkflowStatuses as $st) {
    $owners = stageOwner($st);
    if (in_array($userRole, $owners)) {
        $myStatuses[] = $st;
    }
}

$workflowItems = [];
if (!empty($myStatuses)) {
    $placeholders = implode(',', array_fill(0, count($myStatuses), '?'));
    // Branch filtering: Director HRM&A sees only branch 5, Deputy GC sees only branch 6
    $branchFilter = '';
    $branchParams = [];
    if ($userRole === 'Director HRM&A') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [5];
    } elseif ($userRole === 'Deputy Government Chemist') {
        $branchFilter = 'AND pr.branch_id = ?';
        $branchParams = [6];
    }
    $workflowStmt = $pdo->prepare("
        SELECT 
            'WORKFLOW' as entity_type,
            pr.request_id as entity_id,
            pr.request_id,
            pr.request_number,
            pr.request_type,
            pr.estimated_value,
            pr.currency,
            pr.status as request_status,
            pr.created_at,
            b.branch_name,
            u.full_name as requestor_name
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE UPPER(pr.status) IN ($placeholders)
        $branchFilter
        ORDER BY pr.created_at ASC
    ");
    $workflowStmt->execute(array_merge($myStatuses, $branchParams));
    $workflowItems = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
}
$counts['WORKFLOW'] = count($workflowItems);
if ($selectedType === 'WORKFLOW') {
    $approvals = $workflowItems;
}

// Calculate totals
$totalPending = $counts['REQUEST'] + $counts['COMMITMENT'] + $counts['PO'] + $counts['WORKFLOW'];
?>

<style>
    .unified-approvals-widget {
        background: white;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        margin-bottom: 1rem;
    }

    .approval-type-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 1rem;
        flex-wrap: wrap;
    }

    .approval-type-btn {
        background: white;
        border: 2px solid #e0e0e0;
        padding: 0.5rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        color: #666;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .approval-type-btn:hover {
        border-color: #667eea;
        color: #667eea;
    }

    .approval-type-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-color: #667eea;
    }

    .approval-badge {
        display: inline-block;
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .approval-type-btn.active .approval-badge {
        background: rgba(255, 255, 255, 0.3);
        color: white;
    }

    .approval-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.3s ease;
    }

    .approval-row:hover {
        background: #f9f9f9;
    }

    .approval-row:last-child {
        border-bottom: none;
    }

    .approval-info {
        flex: 1;
    }

    .approval-number {
        font-weight: 600;
        color: #333;
        display: block;
        margin-bottom: 0.25rem;
    }

    .approval-meta {
        font-size: 0.8rem;
        color: #999;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }

    .approval-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .approval-amount {
        font-weight: 600;
        color: #333;
        text-align: right;
        min-width: 120px;
        margin: 0 1rem;
    }

    .empty-state {
        text-align: center;
        color: #999;
        padding: 2rem;
    }

    .empty-state-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }
</style>

<div class="unified-approvals-widget">
    <!-- Header -->
    <div style="margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h5 style="margin: 0; font-weight: 600; color: #1a1a1a; font-size: 1.1rem;">
                <span style="font-size: 1.3rem;">⏳</span> Pending Approvals
            </h5>
            <span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 5px; font-size: 0.85rem; font-weight: 600;">
                <?= $totalPending ?> total
            </span>
        </div>
    </div>

    <!-- Dynamic Type Tabs -->
    <div class="approval-type-tabs">
        <a href="?approval_type=REQUEST" class="approval-type-btn <?= $selectedType === 'REQUEST' ? 'active' : '' ?>">
            📋 Requests
            <span class="approval-badge"><?= $counts['REQUEST'] ?></span>
        </a>
        <a href="?approval_type=WORKFLOW" class="approval-type-btn <?= $selectedType === 'WORKFLOW' ? 'active' : '' ?>">
            🔄 Workflow Actions
            <span class="approval-badge"><?= $counts['WORKFLOW'] ?></span>
        </a>
    </div>

    <!-- Content Area -->
    <?php if (empty($approvals)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">✅</div>
            <div>No pending <?= strtolower($selectedType) ?>s to approve</div>
        </div>
    <?php else: ?>
        <div>
            <?php foreach ($approvals as $item): 
                $link = '';
                $amount = 0;
                $typeLabel = '';
                $icon = '';

                if ($selectedType === 'REQUEST') {
                    $link = "/procurement/view.php?id=" . $item['entity_id'];
                    $amount = $item['estimated_value'];
                    $typeLabel = $item['request_type'] ?? 'REGULAR';
                    $icon = '📋';
                } elseif ($selectedType === 'COMMITMENT') {
                    $link = "/commitments/view.php?commitment_id=" . $item['commitment_id'];
                    $amount = $item['commitment_total'];
                    $typeLabel = 'Commitment';
                    $icon = '📌';
                } elseif ($selectedType === 'PO') {
                    $link = "/po/view.php?po_id=" . $item['po_id'];
                    $amount = $item['po_total'];
                    $typeLabel = 'Purchase Order';
                    $icon = '📑';
                } elseif ($selectedType === 'WORKFLOW') {
                    $link = "/procurement/view.php?id=" . $item['request_id'];
                    $amount = $item['estimated_value'];
                    $statusMap = [
                        'PROCUREMENT_STAGE' => 'Create RFQ',
                        'EVALUATION_STAGE' => 'Evaluate RFQ',
                        'RFQ_LETTER_AVAILABLE' => 'Move to Quote Review',
                        'QUOTE_REVIEW_PENDING' => 'Review Quotes',
                        'QUOTE_APPROVED' => 'Create Commitment',
                        'COMMITTEE_RECOMMENDED' => 'GC Approval Required',
                        'GC_APPROVED' => 'Ready for Award',
                        'COMMITMENT_APPROVED' => 'Create Purchase Order',
                        'COMMITMENT_DECLINED' => 'Revise & Resubmit',
                    ];
                    $typeLabel = $statusMap[strtoupper($item['request_status'])] ?? $item['request_status'];
                    $icon = '🔄';
                }
            ?>
                <div class="approval-row">
                    <div class="approval-info">
                        <span class="approval-number">
                            <?= $icon ?> 
                            <?php if ($selectedType === 'WORKFLOW'): ?>
                                <?= htmlspecialchars($item['request_number']) ?>
                                <span style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.7rem; margin-left: 0.5rem;">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($item[$selectedType === 'REQUEST' ? 'request_number' : ($selectedType === 'COMMITMENT' ? 'commitment_number' : 'po_number')]) ?>
                            <?php endif; ?>
                        </span>
                        <div class="approval-meta">
                            <span style="color: #666;">
                                <strong>Branch:</strong> <?= htmlspecialchars($item['branch_name'] ?? 'N/A') ?>
                            </span>
                            <?php if ($selectedType === 'WORKFLOW'): ?>
                            <span style="color: #666;">
                                <strong>Status:</strong> <span style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem;">
                                    <?= htmlspecialchars(str_replace('_', ' ', $item['request_status'])) ?>
                                </span>
                            </span>
                            <?php if (!empty($item['requestor_name'])): ?>
                            <span style="color: #666;">
                                <strong>By:</strong> <?= htmlspecialchars($item['requestor_name']) ?>
                            </span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color: #666;">
                                <strong>Stage:</strong> <span style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem;">
                                    <?= htmlspecialchars($item['required_role'] ?? 'N/A') ?>
                                </span>
                            </span>
                            <?php endif; ?>
                            <span style="color: #999; font-size: 0.75rem;">
                                <?= date('d M Y', strtotime($item['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="approval-amount">
                        <?= htmlspecialchars($item['currency'] ?? 'JMD') ?> <?= number_format($amount, 0) ?>
                    </div>

                    <div class="approval-actions">
                        <a href="<?= $link ?>"
                           style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: transform 0.3s ease; display: inline-block;">
                            <?= $selectedType === 'WORKFLOW' ? 'Take Action' : 'Review' ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Footer Info -->
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f0f0f0; font-size: 0.8rem; color: #999;">
        <span>👉 Click "Review" to process the approval</span>
    </div>
</div>
