<?php
/**
 * "Requests Requiring Action" Widget
 * ====================================
 * Shows only requests at a workflow stage requiring THIS user's action.
 * - CANCELLED / COMPLETED / DECLINED / PAUSED requests are excluded.
 * - Monitoring roles (Director HRM&A) are not shown action items here.
 * - Draft requests are never shown in the action queue.
 * - Adds Age (days), SLA due-date, Category, and Submitting Unit columns.
 * - Shows an explicit empty-state when nothing is pending.
 */

$userRole = $_SESSION['role_name'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

if (!isset($pdo)) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
}
$userBranchId = 0;
try {
    $userBranchStmt = $pdo->prepare("SELECT branch_id FROM users WHERE user_id = ?");
    $userBranchStmt->execute([$userId]);
    $userBranchId = (int)($userBranchStmt->fetchColumn() ?: 0);
} catch (Throwable $_e) { /* leave as 0 */ }
if (!function_exists('stageOwner')) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/config/workflow.php';
}

// Read SLA default from system_config (fallback: 14 days)
$slaDefaultDays = 14;
try {
    $slaCfgStmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'default_sla_days'");
    $slaCfgStmt->execute();
    $slaCfgVal = $slaCfgStmt->fetchColumn();
    if ($slaCfgVal !== false && (int)$slaCfgVal > 0) {
        $slaDefaultDays = (int)$slaCfgVal;
    }
} catch (Throwable $_e) { /* use default */ }

// Allowed sort columns
$dashboardSortFields = [
    'ref'          => 'pr.request_number',
    'description'  => 'pr.description',
    'requested_by' => 'u.full_name',
    'amount'       => 'pr.estimated_value',
    'status'       => 'pr.status',
    'date'         => 'pr.created_at',
];
$dashboardSort    = $_GET['dashboard_sort'] ?? 'date';
$dashboardDir     = strtoupper($_GET['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$dashboardOrderBy = $dashboardSortFields[$dashboardSort] ?? $dashboardSortFields['date'];

if (!function_exists('dashboardSortLink')) {
    function dashboardSortLink(string $field, string $label): string
    {
        $params = $_GET;
        $currentSort = $params['dashboard_sort'] ?? 'date';
        $currentDir  = strtoupper($params['dashboard_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $params['dashboard_sort'] = $field;
        $params['dashboard_dir']  = ($currentSort === $field && $currentDir === 'ASC') ? 'DESC' : 'ASC';
        $arrow = $currentSort === $field ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
        return '<a href="?' . htmlspecialchars(http_build_query($params)) . '" style="color:inherit;text-decoration:none;">'
            . htmlspecialchars($label) . '<span style="font-size:.7rem;">' . $arrow . '</span></a>';
    }
}
if (!function_exists('dashboardShortText')) {
    function dashboardShortText(?string $text, int $limit = 80): string
    {
        $text = trim((string)$text);
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}

/* ═══════════════════════════════════════════════════════════════
   1. Approval-chain actions (request_approvals table)
      Exclude: DRAFT, CANCELLED, PAUSED, COMPLETED, DECLINED
═══════════════════════════════════════════════════════════════ */
$requestApprovalsStmt = $pdo->prepare("
    SELECT
        pr.request_id,
        pr.request_number,
        pr.request_type,
        pr.description,
        pr.estimated_value,
        pr.currency,
        pr.status        AS request_status,
        pr.created_at,
        b.branch_name,
        u.full_name      AS requestor_name
    FROM request_approvals ra
    JOIN procurement_requests pr ON ra.request_id = pr.request_id
    LEFT JOIN branches b ON pr.branch_id = b.branch_id
    LEFT JOIN users u    ON pr.created_by = u.user_id
    WHERE ra.entity_type = 'REQUEST'
      AND ra.role        = ?
      AND ra.status      = 'pending'
      AND UPPER(pr.status) NOT IN ('DRAFT','DECLINED','COMPLETED','AWARDED','PAUSED','CANCELLED')
    ORDER BY {$dashboardOrderBy} {$dashboardDir}
");
$requestApprovalsStmt->execute([$userRole]);
$pendingApprovals = $requestApprovalsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════
   2. Workflow-stage actions (stageOwner mapping)
      Only statuses where THIS role is the owner; exclude terminal states.
      Monitoring roles are intentionally excluded (no active queue items).
═══════════════════════════════════════════════════════════════ */
$workflowActions = [];

if (!function_exists('isMonitoringRole') ||
    (function_exists('isMonitoringRole') && !isMonitoringRole($userRole))
) {
    $allWorkflowStatuses = [
        'PROCUREMENT_STAGE', 'EVALUATION_STAGE',
        'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING',
        'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED',
        'QUOTE_BRANCH_HEAD_APPROVAL_PENDING',
        'QUOTE_APPROVED',
        'COMMITTEE_RECOMMENDED', 'GC_APPROVED',
        'FUNDS_VERIFIED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED',
        'PO_PENDING', 'INVOICE_RECEIVED',
    ];
    $myStatuses = [];
    foreach ($allWorkflowStatuses as $st) {
        if (in_array($userRole, stageOwner($st), true)) {
            $myStatuses[] = $st;
        }
    }

    if (!empty($myStatuses)) {
        $placeholders = implode(',', array_fill(0, count($myStatuses), '?'));

        // Deputy Government Chemist: restrict to their branch
        $branchFilter = '';
        $branchParams = [];
        if ($userRole === 'Deputy Government Chemist') {
            $branchFilter = 'AND pr.branch_id = ?';
            $branchParams = [6];
        }

        $workflowStmt = $pdo->prepare("
            SELECT
                pr.request_id,
                r.rfq_id AS rfq_id,
                pr.request_number,
                pr.request_type,
                pr.description,
                pr.estimated_value,
                pr.currency,
                pr.status  AS request_status,
                pr.created_at,
                pr.created_by,
                pr.branch_id,
                b.branch_name,
                u.full_name AS requestor_name
            FROM procurement_requests pr
            LEFT JOIN rfqs r     ON r.request_id = pr.request_id
            LEFT JOIN branches b ON pr.branch_id = b.branch_id
            LEFT JOIN users u    ON pr.created_by = u.user_id
            WHERE UPPER(pr.status) IN ({$placeholders})
              AND UPPER(pr.status) NOT IN ('CANCELLED','PAUSED')
              {$branchFilter}
            ORDER BY {$dashboardOrderBy} {$dashboardDir}
        ");
        $params = array_merge($myStatuses, $branchParams);
        $workflowStmt->execute($params);
        $workflowActions = $workflowStmt->fetchAll(PDO::FETCH_ASSOC);
        $canOverrideRequestor = function_exists('hasPermission') && (hasPermission('override_requestor_review') || hasPermission('admin_override_approvals'));
        $canOverrideBranchHead = function_exists('hasPermission') && (hasPermission('override_branch_head_approval') || hasPermission('admin_override_approvals'));
        $workflowActions = array_values(array_filter($workflowActions, static function (array $row) use ($userId, $userRole, $userBranchId, $canOverrideRequestor, $canOverrideBranchHead): bool {
            $status = strtoupper((string)($row['request_status'] ?? ''));
            if ($status === 'QUOTE_REQUESTOR_REVIEW_PENDING') {
                return $canOverrideRequestor || (int)($row['created_by'] ?? 0) === $userId;
            }
            if ($status === 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' || $status === 'QUOTE_REQUESTOR_REVIEW_APPROVED') {
                if ($canOverrideBranchHead) {
                    return true;
                }
                $branchId = (int)($row['branch_id'] ?? 0);
                $isHrmaBranch = $branchId === 5;
                if ($isHrmaBranch && $userRole === 'Director HRM&A') {
                    return true;
                }
                return in_array($userRole, ['HOD', 'Branch Head'], true) && $userBranchId > 0 && $userBranchId === $branchId;
            }
            return true;
        }));
    }
}

$totalPendingActions = count($pendingApprovals) + count($workflowActions);

// Status → action label / colour / icon / target URL
$statusActionMap = [
    'PROCUREMENT_STAGE'                  => ['label' => 'Create RFQ',              'color' => '#6c757d', 'icon' => 'bi-cart-plus',          'href_tpl' => '/rfq/create.php?request_id={id}'],
    'EVALUATION_STAGE'                   => ['label' => 'Evaluate RFQ',            'color' => '#fd7e14', 'icon' => 'bi-clipboard-check',     'href_tpl' => '/rfq/list.php?request_id={id}'],
    'RFQ_LETTER_AVAILABLE'               => ['label' => 'Generate RFQ Letters',    'color' => '#4facfe', 'icon' => 'bi-envelope-open',       'href_tpl' => '/rfq/view.php?request_id={id}'],
    'QUOTE_REVIEW_PENDING'               => ['label' => 'Review Quotes',           'color' => '#fa709a', 'icon' => 'bi-search',              'href_tpl' => '/rfq/view.php?id={id}'],
    'QUOTE_REQUESTOR_REVIEW_PENDING'    => ['label' => 'Pending Requestor Review','color' => '#e67e22', 'icon' => 'bi-file-earmark-check',  'href_tpl' => '/rfq/requestor_spec_review.php?id={id}'],
    'QUOTE_REQUESTOR_REVIEW_APPROVED'    => ['label' => 'Branch Head Review',      'color' => '#9b59b6', 'icon' => 'bi-person-check',        'href_tpl' => '/rfq/branch_head_approve.php?id={id}'],
    'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => ['label' => 'Pending Branch Head Approval', 'color' => '#8e44ad', 'icon' => 'bi-shield-check',   'href_tpl' => '/rfq/branch_head_approve.php?id={id}'],
    'QUOTE_APPROVED'                     => ['label' => 'Create Commitment',       'color' => '#43e97b', 'icon' => 'bi-plus-circle',         'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITTEE_RECOMMENDED'              => ['label' => 'GC Approval Required',    'color' => '#f093fb', 'icon' => 'bi-shield-check',        'href_tpl' => '/rfq/gc_approve.php?request_id={id}'],
    'GC_APPROVED'                        => ['label' => 'Ready for Award',         'color' => '#20c997', 'icon' => 'bi-trophy',              'href_tpl' => '/rfq/award.php?request_id={id}'],
    'FUNDS_VERIFIED'                     => ['label' => 'Proceed with Commitment', 'color' => '#17a2b8', 'icon' => 'bi-check-circle',       'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITMENTS_PENDING'                => ['label' => 'Create Commitment',       'color' => '#3498db', 'icon' => 'bi-upload',              'href_tpl' => '/commitments/add.php?request_id={id}'],
    'COMMITMENT_APPROVED'                => ['label' => 'Create Purchase Order',   'color' => '#667eea', 'icon' => 'bi-file-earmark-plus',   'href_tpl' => '/po/add.php?request_id={id}'],
    'COMMITMENT_DECLINED'                => ['label' => 'Revise & Resubmit',       'color' => '#f5576c', 'icon' => 'bi-arrow-repeat',        'href_tpl' => '/procurement/view.php?id={id}'],
    'PO_PENDING'                         => ['label' => 'Create PO',               'color' => '#2980b9', 'icon' => 'bi-file-earmark-text',   'href_tpl' => '/po/add.php?request_id={id}'],
    'INVOICE_RECEIVED'                   => ['label' => 'Record Payment',          'color' => '#27ae60', 'icon' => 'bi-cash-stack',          'href_tpl' => '/invoice/view.php?request_id={id}'],
];

/**
 * Build the direct-action URL for a given status and request_id.
 */
function actionHref(string $status, int $requestId, array $actionMap, ?int $rfqId = null): string
{
    $tpl = $actionMap[$status]['href_tpl'] ?? '/procurement/view.php?id={id}';
    $targetId = $requestId;
    if (in_array($status, ['QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'], true) && $rfqId) {
        $targetId = $rfqId;
    }
    return str_replace('{id}', (string)$targetId, $tpl);
}

/**
 * Compute age in days from created_at.
 */
function ageDays(string $createdAt): int
{
    $created = new DateTime($createdAt);
    $now     = new DateTime();
    return max(0, (int)$now->diff($created)->days);
}
?>

<div style="background: white; border-radius: 12px; border: 1px solid #e0e0e0; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 1.5rem;">
    <div style="margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 2px solid #e0e0e0; display:flex; align-items:center; justify-content:space-between;">
        <h6 style="margin: 0; font-size: 1rem; font-weight: 700; color: #333;">
            <i class="bi bi-hourglass-split"></i> Requests Requiring Action
            <?php if ($totalPendingActions > 0): ?>
            <span style="background: linear-gradient(135deg, #f5576c 0%, #ff6f91 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem;"><?= $totalPendingActions ?></span>
            <?php endif; ?>
        </h6>
        <?php if ($totalPendingActions > 0): ?>
        <small style="color:#999; font-size:0.75rem;">SLA default: <?= $slaDefaultDays ?> days</small>
        <?php endif; ?>
    </div>

    <?php if ($totalPendingActions === 0): ?>
    <!-- Empty state -->
    <div style="text-align:center; padding: 2.5rem 1rem; color: #aaa;">
        <i class="bi bi-check2-circle" style="font-size:2.5rem; color:#43e97b; display:block; margin-bottom:0.75rem;"></i>
        <div style="font-weight:600; color:#555; margin-bottom:0.25rem;">You're all caught up!</div>
        <small>No requests currently require your action.</small>
    </div>
    <?php endif; ?>

    <?php if (!empty($pendingApprovals)): ?>
    <!-- ── Section 1: Approval-chain approvals ── -->
    <div style="margin-bottom: 1.5rem;">
        <h6 style="margin: 0 0 1rem 0; font-size: 0.875rem; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="bi bi-check-circle"></i> Approvals Awaiting Your Review (<?= count($pendingApprovals) ?>)
        </h6>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead style="background: #f5f5f5;">
                    <tr>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('ref', 'Ref #') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('description', 'Description') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Category</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('requested_by', 'Requestor') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Unit</th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('amount', 'Amount') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('status', 'Stage') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('date', 'Age') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Due (SLA)</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingApprovals as $approval):
                    $age = ageDays($approval['created_at']);
                    $slaDate = date('d M Y', strtotime($approval['created_at'] . ' +' . $slaDefaultDays . ' days'));
                    $ageStyle = $age > $slaDefaultDays ? 'color:#e74c3c;font-weight:700;' : 'color:#555;';
                ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($approval['request_number']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666; max-width: 220px;"><?= htmlspecialchars(dashboardShortText($approval['description'] ?? '')) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['request_type']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['requestor_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($approval['branch_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($approval['currency'] ?? 'JMD')) ?> <?= number_format((float)$approval['estimated_value'], 2) ?></td>
                        <td style="padding: 0.75rem 1rem;"><?= statusBadge($approval['request_status']) ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center; font-size:0.8rem; <?= $ageStyle ?>"><?= $age ?>d</td>
                        <td style="padding: 0.75rem 1rem; text-align: center; color: #888; font-size:0.8rem;"><?= $slaDate ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <a href="/procurement/approve.php?id=<?= $approval['request_id'] ?>"
                               style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">
                               Review
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($workflowActions)): ?>
    <!-- ── Section 2: Workflow-stage actions ── -->
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
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Category</th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('requested_by', 'Requestor') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Unit</th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('amount', 'Amount') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('status', 'Stage') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;"><?= dashboardSortLink('date', 'Age') ?></th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Due (SLA)</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Next Action</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: #333; border-bottom: 2px solid #e0e0e0;">Go</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($workflowActions as $action):
                    $status     = strtoupper($action['request_status']);
                    $actionInfo = $statusActionMap[$status] ?? ['label' => 'View', 'color' => '#6c757d', 'icon' => 'bi-eye', 'href_tpl' => '/procurement/view.php?id={id}'];
                    $href       = actionHref($status, (int)$action['request_id'], $statusActionMap, isset($action['rfq_id']) ? (int)$action['rfq_id'] : null);
                    $age        = ageDays($action['created_at']);
                    $slaDate    = date('d M Y', strtotime($action['created_at'] . ' +' . $slaDefaultDays . ' days'));
                    $ageStyle   = $age > $slaDefaultDays ? 'color:#e74c3c;font-weight:700;' : 'color:#555;';
                ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #333;"><?= htmlspecialchars($action['request_number']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666; max-width: 220px;"><?= htmlspecialchars(dashboardShortText($action['description'] ?? '')) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($action['request_type']) ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($action['requestor_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; color: #666;"><?= htmlspecialchars($action['branch_name'] ?? 'N/A') ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #333;"><?= htmlspecialchars(normalizeCurrency($action['currency'] ?? 'JMD')) ?> <?= number_format((float)$action['estimated_value'], 2) ?></td>
                        <td style="padding: 0.75rem 1rem;">
                            <span style="background:#fff3cd; color:#856404; padding:0.25rem 0.5rem; border-radius:6px; font-size:0.75rem; font-weight:600;"><?= htmlspecialchars($status) ?></span>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: center; font-size:0.8rem; <?= $ageStyle ?>"><?= $age ?>d</td>
                        <td style="padding: 0.75rem 1rem; text-align: center; color: #888; font-size:0.8rem;"><?= $slaDate ?></td>
                        <td style="padding: 0.75rem 1rem; text-align: center; white-space:nowrap;">
                            <i class="bi <?= $actionInfo['icon'] ?>" style="color: <?= $actionInfo['color'] ?>; margin-right:0.25rem;"></i><?= htmlspecialchars($actionInfo['label']) ?>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <a href="<?= htmlspecialchars($href) ?>"
                               style="background: <?= $actionInfo['color'] ?>; color: white; padding: 0.35rem 0.75rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 600;">
                               Go
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
