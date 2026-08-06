<?php
/**
 * Pending Actions Widget
 * Shows all pending approvals and workflow actions for the current user's role
 * Can be included in any dashboard
 */

$userRole = $_SESSION['role_name'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;

$dashboardSortFields = [
    'ref'          => 'pr.request_number',
    'description'  => 'pr.description',
    'requested_by' => 'u.full_name',
    'amount'       => 'pr.estimated_value',
    'status'       => 'pr.status',
    'date'         => 'pr.created_at',
];
$dashboardSort = $_GET['dashboard_sort'] ?? 'date';
$dashboardDir = strtoupper($_GET['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$dashboardOrderBy = $dashboardSortFields[$dashboardSort] ?? $dashboardSortFields['date'];

function dashboardSortLink(string $field, string $label): string
{
    $params = $_GET;
    $currentSort = $params['dashboard_sort'] ?? 'date';
    $currentDir = strtoupper($params['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $params['dashboard_sort'] = $field;
    $params['dashboard_dir'] = ($currentSort === $field && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $arrow = $currentSort === $field ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars(http_build_query($params)) . '" style="color:inherit;text-decoration:none;">'
        . htmlspecialchars($label) . '<span style="font-size:.7rem;">' . $arrow . '</span></a>';
}

function dashboardShortText(?string $text, int $limit = 80): string
{
    $text = trim((string)$text);
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

// Database and workflow config should already be loaded by dashboard
// but we'll ensure they're available
if (!isset($pdo)) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
}
if (!function_exists('stageOwner')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
}

/* ═══════════════════════════════════════════════════════════════
   1. Pending Request Approvals (awaiting this role's approval)
═══════════════════════════════════════════════════════════════ */
$requestApprovalsStmt = $pdo->prepare("
    SELECT 
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.description,
        pr.estimated_value,
        pr.currency,
        pr.status as request_status,
        pr.created_at,
        b.branch_name,
        u.full_name as requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role = ?
      AND ra.status = 'pending'
      AND UPPER(pr.status) NOT IN ('DECLINED', 'COMPLETED', 'AWARDED', 'PAUSED')
    ORDER BY {$dashboardOrderBy} {$dashboardDir}
");
$requestApprovalsStmt->execute([$userRole]);
$pendingApprovals = $requestApprovalsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════
   2. Workflow Actions Required (filtered by role using stageOwner)
═══════════════════════════════════════════════════════════════ */
$allWorkflowStatuses = [
    'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
    'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED',
    'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
    'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'
];
$myStatuses = [];
foreach ($allWorkflowStatuses as $st) {
    if (in_array($userRole, stageOwner($st))) {
        $myStatuses[] = $st;
    }
}

$workflowActions = [];
if (!empty($myStatuses)) {
    $placeholders = implode(',', array_fill(0, count($myStatuses), '?'));
    
    // Branch filtering for specific roles
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
            pr.request_id,
            pr.request_number,
            pr.request_type,
            pr.description,
            pr.estimated_value,
            pr.currency,
            pr.status as request_status,
            pr.created_at,
            b.branch_name,
            u.full_name as requestor_name
        FROM procurement_requests pr
        LEFT JOIN branches b ON pr.branch_id = b.branch_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE UPPER(pr.status) IN ({$placeholders})
          {$branchFilter}
        ORDER BY {$dashboardOrderBy} {$dashboardDir}
    ");
    
    $params = array_merge($myStatuses, $branchParams);
    $workflowStmt->execute($params);
    $workflowActions = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPendingActions = count($pendingApprovals) + count($workflowActions);

// Status action mapping
$statusActionMap = [
    'PROCUREMENT_STAGE'    => ['label' => 'Create RFQ', 'color' => '#6c757d', 'icon' => 'bi-cart-plus'],
    'EVALUATION_STAGE'     => ['label' => 'Evaluate RFQ', 'color' => '#fd7e14', 'icon' => 'bi-clipboard-check'],
    'RFQ_LETTER_AVAILABLE' => ['label' => 'Generate RFQ Letters', 'color' => '#4facfe', 'icon' => 'bi-envelope-open'],
    'QUOTE_REVIEW_PENDING' => ['label' => 'Review Quotes', 'color' => '#fa709a', 'icon' => 'bi-search'],
    'QUOTE_APPROVED'       => ['label' => 'Create Commitment', 'color' => '#43e97b', 'icon' => 'bi-plus-circle'],
    'COMMITTEE_RECOMMENDED'=> ['label' => 'GC Approval Required', 'color' => '#f093fb', 'icon' => 'bi-shield-check'],
    'GC_APPROVED'          => ['label' => 'Ready for Award', 'color' => '#20c997', 'icon' => 'bi-trophy'],
    'COMMITMENT_APPROVED'  => ['label' => 'Create Purchase Order', 'color' => '#667eea', 'icon' => 'bi-file-earmark-plus'],
    'COMMITMENT_DECLINED'  => ['label' => 'Revise & Resubmit', 'color' => '#f5576c', 'icon' => 'bi-arrow-repeat'],
];
?>

<?php if ($totalPendingActions > 0): ?>
<div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); margin-bottom: 1.5rem;">
    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">
            <i class="bi bi-hourglass-split"></i> Pending Actions
            <span style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= $totalPendingActions ?></span>
        </h6>
    </div>

    <!-- Pending Approvals -->
    <?php if (!empty($pendingApprovals)): ?>
    <div style="margin-bottom: 1.5rem;">
        <h6 style="margin: 0 0 1rem 0; font-size: 0.875rem; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="bi bi-check-circle"></i> Approvals Awaiting Your Action (<?= count($pendingApprovals) ?>)
        </h6>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead style="background: #f5f5f5;">
                    <tr>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('ref', 'Ref #') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('description', 'Description') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Type</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('requested_by', 'Requested By') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('amount', 'Amount') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('status', 'Status') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Branch</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('date', 'Date') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingApprovals as $approval): ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($approval['request_number']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666; max-width: 260px;"><?= htmlspecialchars(dashboardShortText($approval['description'] ?? '')) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['request_type']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['requestor_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($approval['currency'] ?? 'JMD')) ?> <?= number_format((float)$approval['estimated_value'], 2) ?></td>
                        <td style="padding: 0.75rem 1rem;"><?= statusBadge($approval['request_status']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['branch_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($approval['created_at'])) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <a href="/procurement/approve.php?id=<?= $approval['request_id'] ?>" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">Review</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Workflow Actions -->
    <?php if (!empty($workflowActions)): ?>
    <div>
        <h6 style="margin: 0 0 1rem 0; font-size: 0.875rem; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="bi bi-arrow-repeat"></i> Workflow Actions Required (<?= count($workflowActions) ?>)
        </h6>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead style="background: #f5f5f5;">
                    <tr>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('ref', 'Ref #') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('description', 'Description') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Type</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('requested_by', 'Requested By') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('amount', 'Amount') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('status', 'Status') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('date', 'Date') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Next Action</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($workflowActions as $action): 
                    $status = strtoupper($action['request_status']);
                    $actionInfo = $statusActionMap[$status] ?? ['label' => 'View', 'color' => '#e0e0e0', 'icon' => 'bi-eye'];
                ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($action['request_number']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666; max-width: 260px;"><?= htmlspecialchars(dashboardShortText($action['description'] ?? '')) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($action['request_type']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($action['requestor_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($action['currency'] ?? 'JMD')) ?> <?= number_format((float)$action['estimated_value'], 2) ?></td>
                        <td style="padding: 0.75rem 1rem;">
                            <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($status) ?></span>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: center; color: #999; font-size: 0.8rem;"><?= date('d M Y', strtotime($action['created_at'])) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <i class="bi <?= $actionInfo['icon'] ?>" style="color: <?= $actionInfo['color'] ?>; margin-right: 0.25rem;"></i><?= htmlspecialchars($actionInfo['label']) ?>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <a href="/procurement/view.php?id=<?= $action['request_id'] ?>" style="background: <?= $actionInfo['color'] ?>; color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">View Request</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
