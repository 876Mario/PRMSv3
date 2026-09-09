<?php

require_once dirname(__DIR__) . '/config/helper.php';
require_once dirname(__DIR__) . '/config/workflow.php';
require_once __DIR__ . '/WorkflowConfigurationService.php';
require_once __DIR__ . '/NotificationService.php';

class DashboardActionService
{
    private const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
    private const TERMINAL_STATUSES = ['CANCELLED', 'COMPLETED', 'DECLINED', 'PAUSED'];

    public static function buildDashboard(PDO $pdo, array $session, string $dashboardKey): array
    {
        $context = self::viewerContext($pdo, $session, $dashboardKey);
        $config = self::dashboardConfig($dashboardKey);

        $actionQueue = self::buildActionQueue($pdo, $context, $config);
        $notifications = self::buildNotifications($session);

        return [
            'config' => $config,
            'context' => $context,
            'summary_cards' => self::buildSummaryCards($actionQueue['summary'], $notifications['unread_count'], $actionQueue['workflow_summary']),
            'action_queue' => $actionQueue,
            'notifications' => $notifications,
            'monitoring_queue' => !empty($config['monitoring']) ? self::buildMonitoringQueue($pdo, $context, $config) : null,
            'active_requests' => $dashboardKey === 'requestor' ? self::buildActiveRequestsQueue($pdo, $context) : null,
        ];
    }

    private static function viewerContext(PDO $pdo, array $session, string $dashboardKey): array
    {
        $userId = (int)($session['user_id'] ?? 0);
        $roleName = (string)($session['role_name'] ?? '');
        $branchId = 0;

        if ($userId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT branch_id FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $branchId = (int)($stmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $branchId = 0;
            }
        }

        return [
            'dashboard_key' => $dashboardKey,
            'user_id' => $userId,
            'full_name' => (string)($session['full_name'] ?? 'User'),
            'role_name' => $roleName,
            'branch_id' => $branchId,
            'today' => date('l, j F Y'),
            'is_procurement_visibility_restricted' => function_exists('isProcurementVisibilityRestrictedRole') && isProcurementVisibilityRestrictedRole($roleName),
        ];
    }

    private static function dashboardConfig(string $dashboardKey): array
    {
        $configs = [
            'procurement' => [
                'title' => 'Procurement Dashboard',
                'icon' => '📋',
                'description' => 'Action-first procurement workflow management.',
                'primary_widget_title' => 'Workflow Actions Required',
                'notification_title' => 'Procurement Notifications',
                'summary_title' => 'Workflow Summary',
                'quick_links_title' => 'Quick Action Links',
                'empty_text' => 'No procurement actions require attention right now.',
                'queue_param_prefix' => 'wa_',
                'quick_links' => [
                    ['label' => 'New Procurement', 'href' => '/procurement/add.php', 'variant' => 'primary'],
                    ['label' => 'All Requests', 'href' => '/procurement/list.php', 'variant' => 'secondary'],
                    ['label' => 'RFQs', 'href' => '/rfq/list.php', 'variant' => 'secondary'],
                    ['label' => 'Purchase Orders', 'href' => '/po/list.php', 'variant' => 'secondary'],
                    ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'variant' => 'danger'],
                ],
                'definitions' => self::procurementDefinitions(),
            ],
            'director_procurement' => [
                'title' => 'Director Procurement Dashboard',
                'icon' => '🏢',
                'description' => 'Oversight for procurement work requiring intervention.',
                'primary_widget_title' => 'Procurement Items Requiring Attention',
                'notification_title' => 'Procurement Notifications',
                'summary_title' => 'Workflow Summary',
                'quick_links_title' => 'Quick Action Links',
                'empty_text' => 'No procurement bottlenecks currently need escalation.',
                'queue_param_prefix' => 'wa_',
                'quick_links' => [
                    ['label' => 'Procurement List', 'href' => '/procurement/list.php', 'variant' => 'secondary'],
                    ['label' => 'RFQs', 'href' => '/rfq/list.php', 'variant' => 'secondary'],
                    ['label' => 'Purchase Orders', 'href' => '/po/list.php', 'variant' => 'secondary'],
                    ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'variant' => 'danger'],
                ],
                'definitions' => self::directorProcurementDefinitions(),
                'monitoring' => [
                    'title' => 'Procurement Officer Performance Queue',
                    'role' => 'Procurement Officer',
                    'statuses' => ['PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'ADDITIONAL_QUOTATIONS_REQUIRED', 'COMMITMENT_APPROVED', 'PO_PENDING'],
                    'param_prefix' => 'monitor_',
                ],
            ],
            'finance' => [
                'title' => 'Finance Dashboard',
                'icon' => '💰',
                'description' => 'Action-first finance verification and disbursement management.',
                'primary_widget_title' => 'Workflow Actions Required',
                'notification_title' => 'Finance Notifications',
                'summary_title' => 'Workflow Summary',
                'quick_links_title' => 'Quick Action Links',
                'empty_text' => 'No finance actions require immediate attention.',
                'queue_param_prefix' => 'wa_',
                'quick_links' => [
                    ['label' => 'All Requests', 'href' => '/procurement/list.php', 'variant' => 'secondary'],
                    ['label' => 'Petty Cash', 'href' => '/petty_cash/list.php', 'variant' => 'secondary'],
                    ['label' => 'Reimbursements', 'href' => '/reimbursement/list.php', 'variant' => 'secondary'],
                    ['label' => 'Commitments', 'href' => '/commitments/list.php', 'variant' => 'secondary'],
                    ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'variant' => 'danger'],
                ],
                'definitions' => self::financeDefinitions(),
            ],
            'director_accounts_finance' => [
                'title' => 'Director Accounts & Finance Dashboard',
                'icon' => '📊',
                'description' => 'Supervisory visibility for finance workloads and escalations.',
                'primary_widget_title' => 'Finance Items Requiring Attention',
                'notification_title' => 'Finance Notifications',
                'summary_title' => 'Workflow Summary',
                'quick_links_title' => 'Quick Action Links',
                'empty_text' => 'No finance interventions are currently outstanding.',
                'queue_param_prefix' => 'wa_',
                'quick_links' => [
                    ['label' => 'Petty Cash', 'href' => '/petty_cash/list.php', 'variant' => 'secondary'],
                    ['label' => 'Reimbursements', 'href' => '/reimbursement/list.php', 'variant' => 'secondary'],
                    ['label' => 'Payments', 'href' => '/payment/list.php', 'variant' => 'secondary'],
                    ['label' => 'Approval Queue', 'href' => '/dashboard/approval_queue.php', 'variant' => 'danger'],
                ],
                'definitions' => self::directorFinanceDefinitions(),
                'monitoring' => [
                    'title' => 'Finance Officer Monitoring',
                    'role' => 'Finance Officer',
                    'statuses' => ['HOD_APPROVED', 'SUBMITTED', 'FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'COMMITMENTS_PENDING', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'INVOICE_RECEIVED', 'RECONCILIATION_DISCREPANCY'],
                    'param_prefix' => 'monitor_',
                ],
            ],
            'requestor' => [
                'title' => 'My Dashboard',
                'icon' => '📝',
                'description' => 'Everything you need to do on your requests, in priority order.',
                'primary_widget_title' => 'My Pending Actions',
                'notification_title' => 'Recent Notifications',
                'summary_title' => 'Workflow Summary',
                'quick_links_title' => 'Quick Action Links',
                'empty_text' => 'You have no actions pending right now.',
                'queue_param_prefix' => 'wa_',
                'quick_links' => [
                    ['label' => 'New Request', 'href' => '/procurement/add.php', 'variant' => 'primary'],
                    ['label' => 'All Requests', 'href' => '/procurement/list.php', 'variant' => 'secondary'],
                    ['label' => 'My Requests', 'href' => '/procurement/my_requests.php', 'variant' => 'secondary'],
                    ['label' => 'RFQs', 'href' => '/rfq/list.php', 'variant' => 'secondary'],
                ],
                'definitions' => self::requestorDefinitions(),
            ],
        ];

        return $configs[$dashboardKey] ?? $configs['procurement'];
    }

    private static function procurementDefinitions(): array
    {
        return [
            self::requestDefinition('create_rfq', ['REGULAR', 'SERVICE_CONTRACT'], ['PROCUREMENT_STAGE'], 'Create RFQ', '/rfq/create.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Workflow Actions Required'),
            self::requestDefinition('generate_rfq_letters', ['REGULAR', 'SERVICE_CONTRACT'], ['RFQ_LETTER_AVAILABLE'], 'Generate RFQ Letters', '/rfq/view.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Pending RFQs'),
            self::requestDefinition('move_to_quote_review', ['REGULAR', 'SERVICE_CONTRACT'], ['QUOTE_REVIEW_PENDING'], 'Move to Quote Review', '/rfq/view.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'Pending Quotes'),
            self::requestDefinition('request_additional_quotes', ['REGULAR', 'SERVICE_CONTRACT'], ['ADDITIONAL_QUOTATIONS_REQUIRED'], 'Request Additional Quotations', '/rfq/view.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'Additional Quotations Required'),
            self::requestDefinition('create_purchase_order', ['REGULAR', 'SERVICE_CONTRACT'], ['COMMITMENT_APPROVED'], 'Create Purchase Order', '/po/add.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Purchase Orders'),
            self::requestDefinition('generate_purchase_order', ['REGULAR', 'SERVICE_CONTRACT'], ['PO_PENDING'], 'Generate Purchase Order', '/po/view.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Purchase Orders'),
        ];
    }

    private static function directorProcurementDefinitions(): array
    {
        return [
            self::requestDefinition('rfq_proc_action', ['REGULAR', 'SERVICE_CONTRACT'], ['PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE'], 'RFQs Awaiting Procurement Action', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'RFQs Awaiting Procurement Action'),
            self::requestDefinition('rfq_quote_review', ['REGULAR', 'SERVICE_CONTRACT'], ['QUOTE_REVIEW_PENDING'], 'RFQs Awaiting Quote Review', '/rfq/view.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'RFQs Awaiting Quote Review'),
            self::requestDefinition('returned_quotes', ['REGULAR', 'SERVICE_CONTRACT'], ['ADDITIONAL_QUOTATIONS_REQUIRED'], 'Returned Quotations', '/rfq/view.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'Returned Quotations'),
            self::requestDefinition('overdue_procurement', ['REGULAR', 'SERVICE_CONTRACT'], ['PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'ADDITIONAL_QUOTATIONS_REQUIRED', 'COMMITMENT_APPROVED', 'PO_PENDING'], 'Overdue Procurement Workflow', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Overdue Procurement Workflows'),
            self::requestDefinition('high_value_procurement', ['REGULAR', 'SERVICE_CONTRACT'], ['PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE', 'QUOTE_REVIEW_PENDING', 'ADDITIONAL_QUOTATIONS_REQUIRED', 'COMMITMENT_APPROVED', 'PO_PENDING'], 'High Value Procurement Activity', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'High Value Procurement Activities', 'pr.estimated_value >= {director_threshold}'),
            self::requestDefinition('high_value_po', ['REGULAR', 'SERVICE_CONTRACT'], ['COMMITMENT_APPROVED', 'PO_PENDING'], 'High Value Purchase Order', '/po/view.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'High Value Purchase Orders', 'pr.estimated_value >= {purchase_order_threshold}'),
        ];
    }

    private static function financeDefinitions(): array
    {
        return [
            self::approvalDefinition('verify_funds_regular', ['REGULAR', 'SERVICE_CONTRACT'], ['HOD_APPROVED'], 'Verify Funds', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Fund Verification', 'Finance Officer'),
            self::approvalDefinition('review_petty_cash', ['PETTY_CASH'], ['SUBMITTED'], 'Review Petty Cash Request', '/petty_cash/view.php?id={request_id}', '/petty_cash/view.php?id={request_id}', 'Petty Cash Requests', 'Finance Officer'),
            self::approvalDefinition('review_reimbursement', ['REIMBURSEMENT'], ['SUBMITTED'], 'Process Finance Verification', '/reimbursement/view.php?id={request_id}', '/reimbursement/view.php?id={request_id}', 'Actions Required', 'Finance Officer'),
            self::requestDefinition('approve_disbursement', ['PETTY_CASH'], ['FUNDS_VERIFIED'], 'Approve Disbursement', '/petty_cash/view.php?id={request_id}', '/petty_cash/view.php?id={request_id}', 'Pending Disbursements'),
            self::requestDefinition('record_disbursement', ['PETTY_CASH'], ['FINANCE_AUTHORIZED'], 'Record Disbursement', '/petty_cash/view.php?id={request_id}', '/petty_cash/view.php?id={request_id}', 'Pending Disbursements'),
            self::requestDefinition('create_commitment', ['REGULAR', 'SERVICE_CONTRACT'], ['COMMITMENTS_PENDING'], 'Process Finance Verification', '/commitments/add.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Actions Required'),
            self::requestDefinition('record_payment', ['REGULAR', 'SERVICE_CONTRACT'], ['INVOICE_RECEIVED'], 'Record Disbursement', '/invoice/view.php?request_id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Disbursements'),
            self::requestDefinition('review_returned_finance', ['PETTY_CASH'], ['RECONCILIATION_DISCREPANCY'], 'Review Returned Finance Request', '/petty_cash/view.php?id={request_id}', '/petty_cash/view.php?id={request_id}', 'Returned Requests'),
            self::requestDefinition('review_reimbursement_invoice', ['REIMBURSEMENT'], ['INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED'], 'Approve Disbursement', '/reimbursement/view.php?id={request_id}', '/reimbursement/view.php?id={request_id}', 'Pending Disbursements'),
            self::requestDefinition('resolve_finance_escalation', ['REIMBURSEMENT'], ['RETURNED_FOR_CORRECTION'], 'Resolve Finance Escalation', '/reimbursement/view.php?id={request_id}', '/reimbursement/view.php?id={request_id}', 'Returned Requests'),
        ];
    }

    private static function directorFinanceDefinitions(): array
    {
        return [
            self::requestDefinition('high_value_requests', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['HOD_APPROVED', 'SUBMITTED', 'FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'COMMITMENTS_PENDING', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'INVOICE_RECEIVED'], 'High Value Request', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'High Value Requests', 'pr.estimated_value >= {director_threshold}'),
            self::requestDefinition('pending_verifications', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['HOD_APPROVED', 'SUBMITTED', 'FUNDS_VERIFIED', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED'], 'Pending Verification', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Verifications'),
            self::requestDefinition('pending_disbursements', ['PETTY_CASH', 'REIMBURSEMENT', 'REGULAR', 'SERVICE_CONTRACT'], ['FINANCE_AUTHORIZED', 'APPROVED', 'INVOICE_RECEIVED'], 'Pending Disbursement', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Pending Disbursements'),
            self::requestDefinition('overdue_finance', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['HOD_APPROVED', 'SUBMITTED', 'FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'COMMITMENTS_PENDING', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'INVOICE_RECEIVED', 'RECONCILIATION_DISCREPANCY'], 'Overdue Finance Review', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Overdue Finance Reviews'),
            self::requestDefinition('sla_breach', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['HOD_APPROVED', 'SUBMITTED', 'FUNDS_VERIFIED', 'FINANCE_AUTHORIZED', 'COMMITMENTS_PENDING', 'INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'APPROVED', 'INVOICE_RECEIVED', 'RECONCILIATION_DISCREPANCY'], 'Approaching SLA Breach', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Approaching SLA Breaches'),
            self::requestDefinition('finance_escalations', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['RECONCILIATION_DISCREPANCY', 'RETURNED_FOR_CORRECTION', 'COMMITMENTS_PENDING', 'INVOICE_RECEIVED'], 'Escalated Finance Activity', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'Escalated Finance Activities'),
        ];
    }

    private static function requestorDefinitions(): array
    {
        return [
            self::requestDefinition('provide_info', ['REGULAR', 'SERVICE_CONTRACT', 'PETTY_CASH', 'REIMBURSEMENT'], ['DRAFT', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED'], 'Provide Additional Information', '/procurement/view.php?id={request_id}', '/procurement/view.php?id={request_id}', 'My Pending Actions', null, true),
            self::requestDefinition('review_quotes', ['REGULAR', 'SERVICE_CONTRACT'], ['QUOTE_REVIEW_PENDING'], 'Review Quotes', '/rfq/view.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'My Pending Actions', null, true),
            self::requestDefinition('confirm_quote_specs', ['REGULAR', 'SERVICE_CONTRACT'], ['QUOTE_REQUESTOR_REVIEW_PENDING'], 'Review Quotes', '/rfq/requestor_spec_review.php?id={rfq_id}', '/procurement/view.php?id={request_id}', 'My Pending Actions', null, true),
            self::requestDefinition('collect_funds', ['PETTY_CASH'], ['DISBURSED'], 'Collect Funds', '/petty_cash/view.php?id={request_id}', '/petty_cash/view.php?id={request_id}', 'My Pending Actions', null, true),
            self::requestDefinition('ack_receipt', ['REIMBURSEMENT'], ['REIMBURSED'], 'Acknowledgement Required', '/reimbursement/confirm_receipt.php?id={request_id}', '/reimbursement/view.php?id={request_id}', 'My Pending Actions', null, true),
        ];
    }

    private static function requestDefinition(
        string $key,
        array $requestTypes,
        array $statuses,
        string $actionLabel,
        string $actionUrlTemplate,
        string $viewUrlTemplate,
        string $category,
        ?string $extraWhere = null,
        bool $ownOnly = false
    ): array {
        return [
            'key' => $key,
            'source' => 'request',
            'request_types' => $requestTypes,
            'statuses' => $statuses,
            'action_label' => $actionLabel,
            'action_url_template' => $actionUrlTemplate,
            'view_url_template' => $viewUrlTemplate,
            'category' => $category,
            'extra_where' => $extraWhere,
            'own_only' => $ownOnly,
        ];
    }

    private static function approvalDefinition(
        string $key,
        array $requestTypes,
        array $statuses,
        string $actionLabel,
        string $actionUrlTemplate,
        string $viewUrlTemplate,
        string $category,
        string $approvalRole
    ): array {
        return [
            'key' => $key,
            'source' => 'approval',
            'request_types' => $requestTypes,
            'statuses' => $statuses,
            'action_label' => $actionLabel,
            'action_url_template' => $actionUrlTemplate,
            'view_url_template' => $viewUrlTemplate,
            'category' => $category,
            'approval_role' => $approvalRole,
        ];
    }

    private static function buildActionQueue(PDO $pdo, array $context, array $config): array
    {
        $prefix = (string)($config['queue_param_prefix'] ?? 'wa_');
        $state = self::tableState($prefix, [
            'priority_rank' => 'priority_rank',
            'age_days' => 'age_days',
            'request_number' => 'request_number',
            'requestor_name' => 'requestor_name',
            'department' => 'department',
            'current_stage' => 'current_stage',
            'action_required' => 'action_required',
        ]);

        $baseSql = self::buildActionUnionSql($pdo, $context, $config['definitions']);
        $filters = self::buildActionFilters($state);

        $countSql = "SELECT COUNT(*) FROM ({$baseSql}) action_rows WHERE 1=1 {$filters['sql']}";
        $countStmt = $pdo->prepare($countSql);
        foreach ($filters['params'] as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $summarySql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN priority_rank >= 3 THEN 1 ELSE 0 END) AS urgent_actions,
                SUM(CASE WHEN risk_rank >= 3 THEN 1 ELSE 0 END) AS overdue_actions,
                SUM(CASE WHEN risk_rank >= 4 THEN 1 ELSE 0 END) AS escalations,
                SUM(CASE WHEN risk_rank = 2 THEN 1 ELSE 0 END) AS sla_risks
            FROM ({$baseSql}) action_rows
            WHERE 1=1 {$filters['sql']}
        ";
        $summaryStmt = $pdo->prepare($summarySql);
        foreach ($filters['params'] as $key => $value) {
            $summaryStmt->bindValue($key, $value);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $workflowSummarySql = "
            SELECT summary_category, COUNT(*) AS item_count
            FROM ({$baseSql}) action_rows
            WHERE 1=1 {$filters['sql']}
            GROUP BY summary_category
            ORDER BY item_count DESC, summary_category ASC
        ";
        $workflowSummaryStmt = $pdo->prepare($workflowSummarySql);
        foreach ($filters['params'] as $key => $value) {
            $workflowSummaryStmt->bindValue($key, $value);
        }
        $workflowSummaryStmt->execute();
        $workflowSummary = $workflowSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

        $dataSql = "
            SELECT *
            FROM ({$baseSql}) action_rows
            WHERE 1=1 {$filters['sql']}
            ORDER BY {$state['sort_column']} {$state['sort_dir']}, age_days DESC, estimated_value DESC, request_number ASC
            LIMIT :limit OFFSET :offset
        ";
        $dataStmt = $pdo->prepare($dataSql);
        foreach ($filters['params'] as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $state['per_page'], PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $state['offset'], PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'state' => $state,
            'total' => $total,
            'rows' => array_map(static fn(array $row): array => self::decorateActionRow($row), $rows),
            'summary' => [
                'total' => (int)($summary['total'] ?? 0),
                'urgent_actions' => (int)($summary['urgent_actions'] ?? 0),
                'overdue_actions' => (int)($summary['overdue_actions'] ?? 0),
                'escalations' => (int)($summary['escalations'] ?? 0),
                'sla_risks' => (int)($summary['sla_risks'] ?? 0),
            ],
            'workflow_summary' => array_map(static function (array $row) use ($prefix): array {
                $params = $_GET;
                $params[$prefix . 'category'] = $row['summary_category'];
                $params[$prefix . 'page'] = 1;
                return [
                    'label' => $row['summary_category'],
                    'count' => (int)$row['item_count'],
                    'href' => '?' . http_build_query($params),
                ];
            }, $workflowSummary),
            'pagination_params' => self::tableQueryParams($prefix, $state),
        ];
    }

    private static function buildActionUnionSql(PDO $pdo, array $context, array $definitions): string
    {
        $parts = [];
        $percentages = WorkflowConfigurationService::getEscalationPercentages($pdo);
        $directorThreshold = (float)WorkflowConfigurationService::getHighValueThreshold($pdo, 'director');
        $executiveThreshold = (float)WorkflowConfigurationService::getHighValueThreshold($pdo, 'executive');
        $poThreshold = (float)WorkflowConfigurationService::getHighValueThreshold($pdo, 'purchase_order');

        foreach ($definitions as $definition) {
            $typesSql = implode(', ', array_map([$pdo, 'quote'], array_map('strtoupper', $definition['request_types'])));
            $statusesSql = implode(', ', array_map([$pdo, 'quote'], array_map('strtoupper', $definition['statuses'])));
            $typeForSla = strtoupper((string)($definition['request_types'][0] ?? 'REGULAR'));
            $statusForSla = strtoupper((string)($definition['statuses'][0] ?? 'SUBMITTED'));
            $slaDays = WorkflowConfigurationService::getStageSlaDays($pdo, $typeForSla, $statusForSla);
            $warningDays = max(1, (int)ceil($slaDays * ((int)$percentages['warning'] / 100)));
            $overdueDays = max($warningDays, (int)ceil($slaDays * ((int)$percentages['overdue'] / 100)));
            $criticalDays = max($overdueDays, (int)ceil($slaDays * ((int)$percentages['critical'] / 100)));

            $ageExpr = 'DATEDIFF(CURDATE(), DATE(pr.created_at))';
            $riskRankExpr = "CASE
                WHEN {$ageExpr} >= {$criticalDays} THEN 4
                WHEN {$ageExpr} >= {$overdueDays} THEN 3
                WHEN {$ageExpr} >= {$warningDays} THEN 2
                ELSE 1
            END";
            $priorityRankExpr = "CASE
                WHEN pr.estimated_value >= {$executiveThreshold} OR {$ageExpr} >= {$criticalDays} THEN 3
                WHEN pr.estimated_value >= {$directorThreshold} OR {$ageExpr} >= {$warningDays} THEN 2
                ELSE 1
            END";
            $riskLevelExpr = "CASE
                WHEN {$ageExpr} >= {$criticalDays} THEN 'critical'
                WHEN {$ageExpr} >= {$overdueDays} THEN 'overdue'
                WHEN {$ageExpr} >= {$warningDays} THEN 'warning'
                ELSE 'normal'
            END";
            $priorityExpr = "CASE
                WHEN pr.estimated_value >= {$executiveThreshold} OR {$ageExpr} >= {$criticalDays} THEN 'urgent'
                WHEN pr.estimated_value >= {$directorThreshold} OR {$ageExpr} >= {$warningDays} THEN 'high'
                ELSE 'normal'
            END";

            $filters = [
                "UPPER(pr.request_type) IN ({$typesSql})",
                "UPPER(pr.status) IN ({$statusesSql})",
                "UPPER(pr.status) NOT IN ('" . implode("','", self::TERMINAL_STATUSES) . "')",
            ];

            if (!empty($definition['own_only'])) {
                $filters[] = 'pr.created_by = ' . (int)$context['user_id'];
            }

            if ($context['dashboard_key'] === 'procurement' && $context['is_procurement_visibility_restricted'] && function_exists('getProcurementVisibilitySqlCondition')) {
                $filters[] = getProcurementVisibilitySqlCondition('pr');
            }

            if (($definition['source'] ?? 'request') === 'approval') {
                $filters[] = "ra.entity_type = 'REQUEST'";
                $filters[] = "ra.status = 'pending'";
                $filters[] = "ra.role = " . $pdo->quote((string)($definition['approval_role'] ?? $context['role_name']));
            }

            if (!empty($definition['extra_where'])) {
                $extraWhere = strtr($definition['extra_where'], [
                    '{director_threshold}' => (string)$directorThreshold,
                    '{purchase_order_threshold}' => (string)$poThreshold,
                ]);
                $filters[] = $extraWhere;
            }

            $from = (($definition['source'] ?? 'request') === 'approval')
                ? 'request_approvals ra JOIN procurement_requests pr ON pr.request_id = ra.request_id'
                : 'procurement_requests pr';

            $parts[] = "
                    SELECT
                        pr.request_id,
                        r.rfq_id,
                        pr.request_number,
                        pr.request_type,
                        pr.status AS status_code,
                        pr.status AS current_stage,
                        COALESCE(u.full_name, 'Unknown Requestor') AS requestor_name,
                    COALESCE(b.branch_name, 'Unassigned') AS department,
                    pr.estimated_value,
                    pr.currency,
                    pr.created_at,
                    COALESCE(pr.updated_at, pr.created_at) AS updated_at,
                    " . $pdo->quote($definition['action_label']) . " AS action_required,
                    " . $pdo->quote($definition['category']) . " AS summary_category,
                    " . $pdo->quote($definition['action_url_template']) . " AS action_url_template,
                    " . $pdo->quote($definition['view_url_template']) . " AS view_url_template,
                    " . $pdo->quote((string)($definition['source'] ?? 'request')) . " AS action_source,
                    {$ageExpr} AS age_days,
                    {$riskRankExpr} AS risk_rank,
                    {$priorityRankExpr} AS priority_rank,
                    {$riskLevelExpr} AS risk_level,
                    {$priorityExpr} AS priority_label
                FROM {$from}
                LEFT JOIN branches b ON b.branch_id = pr.branch_id
                LEFT JOIN users u ON u.user_id = pr.created_by
                LEFT JOIN rfqs r ON r.request_id = pr.request_id
                WHERE " . implode(' AND ', $filters) . "
            ";
        }

        return implode("\nUNION ALL\n", $parts);
    }

    private static function buildActionFilters(array $state): array
    {
        $sql = '';
        $params = [];

        if ($state['search'] !== '') {
            $sql .= " AND (
                request_number LIKE :search
                OR requestor_name LIKE :search
                OR department LIKE :search
                OR current_stage LIKE :search
                OR action_required LIKE :search
                OR summary_category LIKE :search
            )";
            $params[':search'] = '%' . $state['search'] . '%';
        }

        if ($state['risk'] !== '') {
            $sql .= ' AND risk_level = :risk_level';
            $params[':risk_level'] = $state['risk'];
        }

        if ($state['priority'] !== '') {
            $sql .= ' AND priority_label = :priority_label';
            $params[':priority_label'] = $state['priority'];
        }

        if ($state['category'] !== '') {
            $sql .= ' AND summary_category = :summary_category';
            $params[':summary_category'] = $state['category'];
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private static function decorateActionRow(array $row): array
    {
        $statusMeta = function_exists('getStatusLabel') ? getStatusLabel((string)$row['status_code']) : ['label' => (string)$row['status_code']];
        $row['current_stage'] = (string)($statusMeta['label'] ?? $row['status_code']);
        $row['priority'] = (string)$row['priority_label'];
        $row['action_url'] = self::fillUrlTemplate((string)$row['action_url_template'], $row);
        $row['view_url'] = self::fillUrlTemplate((string)$row['view_url_template'], $row);
        $row['badges'] = self::badgesForRow($row);
        return $row;
    }

    private static function fillUrlTemplate(string $template, array $row): string
    {
        $requestId = (int)($row['request_id'] ?? 0);
        $rfqId = (int)($row['rfq_id'] ?? 0);
        $url = strtr($template, [
            '{request_id}' => (string)$requestId,
            '{rfq_id}' => (string)($rfqId > 0 ? $rfqId : $requestId),
        ]);
        if ($template === '/procurement/view.php?id={request_id}') {
            $moduleBase = self::requestModuleBase((string)($row['request_type'] ?? 'REGULAR'));
            $url = $moduleBase . '/view.php?id=' . $requestId;
        }
        return $url;
    }

    private static function badgesForRow(array $row): array
    {
        $badges = [];
        $risk = (string)($row['risk_level'] ?? 'normal');
        $priority = (string)($row['priority'] ?? 'normal');
        $status = strtoupper((string)($row['status_code'] ?? ''));
        $amount = (float)($row['estimated_value'] ?? 0);

        if ($priority === 'urgent') {
            $badges[] = ['label' => 'URGENT', 'variant' => 'red'];
        }
        if (in_array($risk, ['overdue', 'critical'], true)) {
            $badges[] = ['label' => 'OVERDUE', 'variant' => 'red'];
        }
        if ($risk === 'critical') {
            $badges[] = ['label' => 'ESCALATED', 'variant' => 'red'];
        } elseif ($risk === 'warning') {
            $badges[] = ['label' => 'NEAR SLA', 'variant' => 'amber'];
        }
        if ($amount >= 3000000) {
            $badges[] = ['label' => 'HIGH VALUE', 'variant' => 'amber'];
        }
        if (in_array($status, ['ADDITIONAL_QUOTATIONS_REQUIRED', 'RETURNED_FOR_CORRECTION', 'RECONCILIATION_DISCREPANCY', 'COMMITMENT_DECLINED'], true)) {
            $badges[] = ['label' => 'RETURNED', 'variant' => 'amber'];
        }
        if (str_contains($status, 'REVIEW') || str_contains($status, 'PENDING')) {
            $badges[] = ['label' => 'PENDING REVIEW', 'variant' => 'blue'];
        } else {
            $badges[] = ['label' => 'ACTION REQUIRED', 'variant' => 'blue'];
        }

        return $badges;
    }

    private static function buildSummaryCards(array $summary, int $unreadNotifications, array $workflowSummary): array
    {
        return [
            ['label' => 'My Pending Actions', 'value' => (int)($summary['total'] ?? 0), 'variant' => 'blue'],
            ['label' => 'Urgent Actions', 'value' => (int)($summary['urgent_actions'] ?? 0), 'variant' => 'red'],
            ['label' => 'Overdue Actions', 'value' => (int)($summary['overdue_actions'] ?? 0), 'variant' => 'red'],
            ['label' => 'Escalations', 'value' => (int)($summary['escalations'] ?? 0), 'variant' => 'red'],
            ['label' => 'Recent Notifications', 'value' => $unreadNotifications, 'variant' => 'amber'],
            ['label' => 'Workflow Summary', 'value' => count($workflowSummary), 'variant' => 'green'],
        ];
    }

    private static function buildNotifications(array $session): array
    {
        $userId = (int)($session['user_id'] ?? 0);
        $notifications = $userId > 0 && class_exists('NotificationService')
            ? NotificationService::getAll($userId, 10)
            : [];
        $unreadCount = $userId > 0 && class_exists('NotificationService')
            ? NotificationService::countUnread($userId)
            : 0;

        $rows = array_map(static function (array $row): array {
            $row['view_url'] = !empty($row['request_id']) ? '/procurement/view.php?id=' . (int)$row['request_id'] : null;
            $row['action_url'] = $row['action_url'] ?? $row['view_url'];
            $row['action_label'] = 'Take Action';
            $row['view_label'] = 'View Record';
            return $row;
        }, $notifications);

        return ['rows' => $rows, 'unread_count' => $unreadCount];
    }

    private static function buildMonitoringQueue(PDO $pdo, array $context, array $config): array
    {
        $monitoring = $config['monitoring'];
        $prefix = (string)$monitoring['param_prefix'];
        $state = self::tableState($prefix, [
            'pending_items' => 'pending_items',
            'overdue_count' => 'overdue_count',
            'escalations' => 'escalations',
            'officer' => 'officer',
            'near_sla' => 'near_sla',
            'requires_intervention' => 'requires_intervention',
        ]);

        $statusesSql = implode(', ', array_map([$pdo, 'quote'], array_map('strtoupper', $monitoring['statuses'])));
        $searchSql = '';
        $params = [];
        if ($state['search'] !== '') {
            $searchSql = ' AND u.full_name LIKE :monitor_search';
            $params[':monitor_search'] = '%' . $state['search'] . '%';
        }

        $ageExpr = 'DATEDIFF(CURDATE(), DATE(pr.created_at))';
        $warningDays = 2;
        $overdueDays = 3;
        $criticalDays = 5;

        $baseSql = "
            SELECT
                u.full_name AS officer,
                COUNT(pr.request_id) AS pending_items,
                SUM(CASE WHEN {$ageExpr} >= {$overdueDays} THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN {$ageExpr} >= {$warningDays} AND {$ageExpr} < {$overdueDays} THEN 1 ELSE 0 END) AS near_sla,
                SUM(CASE WHEN {$ageExpr} >= {$criticalDays} THEN 1 ELSE 0 END) AS escalations,
                CASE
                    WHEN SUM(CASE WHEN {$ageExpr} >= {$criticalDays} THEN 1 ELSE 0 END) > 0
                        OR SUM(CASE WHEN {$ageExpr} >= {$overdueDays} THEN 1 ELSE 0 END) > 0
                    THEN 'Yes'
                    ELSE 'No'
                END AS requires_intervention
            FROM users u
            JOIN roles ro ON ro.id = u.role_id
            LEFT JOIN procurement_requests pr
                ON pr.branch_id = u.branch_id
               AND UPPER(pr.status) IN ({$statusesSql})
               AND UPPER(pr.status) NOT IN ('" . implode("','", self::TERMINAL_STATUSES) . "')
            WHERE ro.name = " . $pdo->quote((string)$monitoring['role']) . "
              AND u.is_active = 1
              {$searchSql}
            GROUP BY u.user_id, u.full_name
        ";

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$baseSql}) monitoring_rows");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $dataStmt = $pdo->prepare("
            SELECT *
            FROM ({$baseSql}) monitoring_rows
            ORDER BY {$state['sort_column']} {$state['sort_dir']}, officer ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $state['per_page'], PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $state['offset'], PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'title' => $monitoring['title'],
            'state' => $state,
            'rows' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pagination_params' => self::tableQueryParams($prefix, $state),
        ];
    }

    private static function buildActiveRequestsQueue(PDO $pdo, array $context): array
    {
        $prefix = 'requests_';
        $state = self::tableState($prefix, [
            'days_open' => 'days_open',
            'request_number' => 'request_number',
            'status' => 'status',
            'current_approver' => 'current_approver',
            'action_needed' => 'action_needed',
        ]);

        $where = ['pr.created_by = :requestor_id'];
        $params = [':requestor_id' => (int)$context['user_id']];

        if ($state['search'] !== '') {
            $where[] = '(pr.request_number LIKE :request_search OR pr.description LIKE :request_search OR pr.status LIKE :request_search)';
            $params[':request_search'] = '%' . $state['search'] . '%';
        }
        if ($state['risk'] !== '') {
            $riskMap = [
                'critical' => 'DATEDIFF(CURDATE(), DATE(pr.created_at)) >= 5',
                'overdue' => 'DATEDIFF(CURDATE(), DATE(pr.created_at)) >= 3',
                'warning' => 'DATEDIFF(CURDATE(), DATE(pr.created_at)) >= 2 AND DATEDIFF(CURDATE(), DATE(pr.created_at)) < 3',
            ];
            if (isset($riskMap[$state['risk']])) {
                $where[] = $riskMap[$state['risk']];
            }
        }
        if ($state['category'] !== '') {
            $where[] = 'pr.status = :request_status_filter';
            $params[':request_status_filter'] = $state['category'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM procurement_requests pr {$whereSql}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $dataStmt = $pdo->prepare("
            SELECT
                pr.request_id,
                pr.request_number,
                pr.status,
                pr.created_at,
                pr.request_type,
                r.rfq_id,
                DATEDIFF(CURDATE(), DATE(pr.created_at)) AS days_open,
                CASE
                    WHEN UPPER(pr.status) IN ('DRAFT', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED', 'DISBURSED', 'REIMBURSED') THEN 'You'
                    ELSE " . self::stageOwnerCaseSql('pr.status') . "
                END AS current_approver,
                CASE
                    WHEN UPPER(pr.status) IN ('DRAFT', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED') THEN 'Provide Additional Information'
                    WHEN UPPER(pr.status) IN ('QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING') THEN 'Review Quotes'
                    WHEN UPPER(pr.status) = 'DISBURSED' THEN 'Collect Funds'
                    WHEN UPPER(pr.status) = 'REIMBURSED' THEN 'Acknowledgement Required'
                    ELSE 'View Request'
                END AS action_needed
            FROM procurement_requests pr
            LEFT JOIN rfqs r ON r.request_id = pr.request_id
            {$whereSql}
            ORDER BY {$state['sort_column']} {$state['sort_dir']}, pr.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $state['per_page'], PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $state['offset'], PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $statusCountsStmt = $pdo->prepare("
            SELECT pr.status, COUNT(*) AS item_count
            FROM procurement_requests pr
            WHERE pr.created_by = :requestor_id
            GROUP BY pr.status
            ORDER BY item_count DESC, pr.status ASC
        ");
        $statusCountsStmt->bindValue(':requestor_id', (int)$context['user_id'], PDO::PARAM_INT);
        $statusCountsStmt->execute();
        $statusCounts = $statusCountsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'title' => 'My Active Requests',
            'state' => $state,
            'rows' => array_map(static fn(array $row): array => self::decorateRequestorRow($row), $rows),
            'total' => $total,
            'workflow_summary' => array_map(static function (array $row): array {
                $params = $_GET;
                $params['requests_category'] = $row['status'];
                $params['requests_page'] = 1;
                return [
                    'label' => (function_exists('getStatusLabel') ? (getStatusLabel($row['status'])['label'] ?? $row['status']) : $row['status']),
                    'filter_value' => $row['status'],
                    'count' => (int)$row['item_count'],
                    'href' => '?' . http_build_query($params),
                ];
            }, $statusCounts),
            'pagination_params' => self::tableQueryParams($prefix, $state),
        ];
    }

    private static function decorateRequestorRow(array $row): array
    {
        $status = strtoupper((string)$row['status']);
        $statusMeta = function_exists('getStatusLabel') ? getStatusLabel($status) : ['label' => $status];
        $moduleBase = self::requestModuleBase((string)$row['request_type']);

        $actionNeeded = match ($status) {
            'DRAFT', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED' => 'Provide Additional Information',
            'QUOTE_REVIEW_PENDING', 'QUOTE_REQUESTOR_REVIEW_PENDING' => 'Review Quotes',
            'DISBURSED' => 'Collect Funds',
            'REIMBURSED' => 'Acknowledgement Required',
            default => 'View Request',
        };
        $currentApprover = match ($status) {
            'DRAFT', 'RETURNED_FOR_CORRECTION', 'RESUBMITTED', 'DISBURSED', 'REIMBURSED' => 'You',
            default => implode(', ', stageOwner($status)),
        };
        $actionUrl = match ($status) {
            'QUOTE_REVIEW_PENDING' => '/rfq/view.php?id=' . (int)($row['rfq_id'] ?? $row['request_id']),
            'QUOTE_REQUESTOR_REVIEW_PENDING' => '/rfq/requestor_spec_review.php?id=' . (int)($row['rfq_id'] ?? $row['request_id']),
            'REIMBURSED' => '/reimbursement/confirm_receipt.php?id=' . (int)$row['request_id'],
            default => $moduleBase . '/view.php?id=' . (int)$row['request_id'],
        };

        return [
            'request_id' => (int)$row['request_id'],
            'request_number' => $row['request_number'],
            'status' => (string)($statusMeta['label'] ?? $status),
            'current_approver' => $currentApprover !== '' ? $currentApprover : 'Pending Assignment',
            'days_open' => (int)$row['days_open'],
            'action_needed' => $actionNeeded,
            'view_url' => $moduleBase . '/view.php?id=' . (int)$row['request_id'],
            'action_url' => $actionUrl,
            'badges' => self::badgesForRow([
                'risk_level' => ((int)$row['days_open'] >= 5 ? 'critical' : ((int)$row['days_open'] >= 3 ? 'overdue' : ((int)$row['days_open'] >= 2 ? 'warning' : 'normal'))),
                'priority' => ((int)$row['days_open'] >= 5 ? 'urgent' : (((int)$row['days_open'] >= 2) ? 'high' : 'normal')),
                'status_code' => $status,
                'estimated_value' => 0,
            ]),
        ];
    }

    private static function tableState(string $prefix, array $allowedSorts): array
    {
        $perPage = isset($_GET[$prefix . 'per_page']) && in_array((int)$_GET[$prefix . 'per_page'], self::PAGE_SIZE_OPTIONS, true)
            ? (int)$_GET[$prefix . 'per_page']
            : 10;
        $page = isset($_GET[$prefix . 'page']) && (int)$_GET[$prefix . 'page'] > 0 ? (int)$_GET[$prefix . 'page'] : 1;
        $sortKey = (string)($_GET[$prefix . 'sort'] ?? array_key_first($allowedSorts));
        $sortDir = strtoupper((string)($_GET[$prefix . 'dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $search = trim((string)($_GET[$prefix . 'q'] ?? ''));
        $risk = trim((string)($_GET[$prefix . 'risk'] ?? ''));
        $priority = trim((string)($_GET[$prefix . 'priority'] ?? ''));
        $category = trim((string)($_GET[$prefix . 'category'] ?? ''));

        return [
            'prefix' => $prefix,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'sort_key' => $sortKey,
            'sort_column' => $allowedSorts[$sortKey] ?? reset($allowedSorts),
            'sort_dir' => $sortDir,
            'search' => $search,
            'risk' => in_array($risk, ['critical', 'overdue', 'warning', 'normal'], true) ? $risk : '',
            'priority' => in_array($priority, ['urgent', 'high', 'normal'], true) ? $priority : '',
            'category' => $category,
            'page_size_options' => self::PAGE_SIZE_OPTIONS,
        ];
    }

    private static function tableQueryParams(string $prefix, array $state): array
    {
        $params = $_GET;
        $params[$prefix . 'per_page'] = $state['per_page'];
        $params[$prefix . 'sort'] = $state['sort_key'];
        $params[$prefix . 'dir'] = $state['sort_dir'];
        if ($state['search'] !== '') {
            $params[$prefix . 'q'] = $state['search'];
        }
        if ($state['risk'] !== '') {
            $params[$prefix . 'risk'] = $state['risk'];
        }
        if ($state['priority'] !== '') {
            $params[$prefix . 'priority'] = $state['priority'];
        }
        if ($state['category'] !== '') {
            $params[$prefix . 'category'] = $state['category'];
        }
        return $params;
    }

    private static function requestModuleBase(string $requestType): string
    {
        return match (strtoupper($requestType)) {
            'PETTY_CASH' => '/petty_cash',
            'REIMBURSEMENT' => '/reimbursement',
            default => '/procurement',
        };
    }

    private static function stageOwnerCaseSql(string $statusColumn): string
    {
        $cases = [
            'SUBMITTED' => 'HOD',
            'HOD_APPROVED' => 'Finance Officer',
            'DIRECTOR_APPROVED' => 'Deputy Government Chemist',
            'FUNDS_VERIFIED' => 'Finance Officer',
            'GC_APPROVED' => 'Procurement Officer',
            'PROCUREMENT_STAGE' => 'Procurement Officer',
            'RFQ_LETTER_AVAILABLE' => 'Procurement Officer',
            'QUOTE_REVIEW_PENDING' => 'Requestor / Branch Head',
            'QUOTE_REQUESTOR_REVIEW_PENDING' => 'Requestor',
            'QUOTE_REQUESTOR_REVIEW_APPROVED' => 'Branch Head',
            'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => 'Branch Head',
            'COMMITMENTS_PENDING' => 'Finance Officer',
            'COMMITMENT_APPROVED' => 'Procurement Officer',
            'PO_PENDING' => 'Accounts Officer',
            'INVOICE_RECEIVED' => 'Finance Officer',
            'FINANCE_AUTHORIZED' => 'Finance Officer',
            'INVOICE_SUBMITTED' => 'Finance Officer',
            'INVOICE_VERIFIED' => 'Finance Officer',
            'APPROVED' => 'Finance Officer',
        ];

        $sql = "CASE UPPER({$statusColumn})";
        foreach ($cases as $status => $owner) {
            $sql .= " WHEN " . self::quoteSqlLiteral($status) . " THEN " . self::quoteSqlLiteral($owner);
        }
        $sql .= " ELSE " . self::quoteSqlLiteral('Pending Assignment') . " END";
        return $sql;
    }

    private static function quoteSqlLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
