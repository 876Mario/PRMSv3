<?php

require_once __DIR__ . '/SystemConfigService.php';

final class WorkflowConfigurationService
{
    private static array $seededConnections = [];
    private static array $settingsCache = [];

    public static function definitions(): array
    {
        return [
            'high_value_thresholds' => [
                ['key' => 'high_value_petty_cash_threshold', 'label' => 'High Value Petty Cash Threshold', 'default' => '500000', 'type' => 'money', 'min' => '0', 'description' => 'Petty cash requests at or above this amount trigger finance high-value oversight.'],
                ['key' => 'high_value_request_threshold', 'label' => 'High Value Request Threshold', 'default' => '500000', 'type' => 'money', 'min' => '0', 'description' => 'General request value threshold for high-value dashboards and alerts.'],
                ['key' => 'high_value_procurement_threshold', 'label' => 'High Value Procurement Threshold', 'default' => '3000000', 'type' => 'money', 'min' => '0', 'description' => 'Procurement requests at or above this amount trigger director procurement oversight.'],
                ['key' => 'high_value_purchase_order_threshold', 'label' => 'High Value Purchase Order Threshold', 'default' => '3000000', 'type' => 'money', 'min' => '0', 'description' => 'Purchase orders at or above this amount trigger high-value PO oversight.'],
                ['key' => 'finance_escalation_threshold', 'label' => 'Finance Escalation Threshold', 'default' => '3000000', 'type' => 'money', 'min' => '0', 'description' => 'Transactions at or above this amount escalate to Director Accounts & Finance.'],
                ['key' => 'director_notification_threshold', 'label' => 'Director Notification Threshold', 'default' => '3000000', 'type' => 'money', 'min' => '0', 'description' => 'Transactions at or above this amount notify the supervising director.'],
                ['key' => 'executive_oversight_threshold', 'label' => 'Executive Oversight Threshold', 'default' => '10000000', 'type' => 'money', 'min' => '0', 'description' => 'Transactions at or above this amount trigger executive oversight.'],
            ],
            'workflow_slas' => [
                ['key' => 'rfq_vendor_assignment_sla_days', 'label' => 'RFQ Vendor Assignment SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for assigning vendors or issuing RFQ letters.'],
                ['key' => 'rfq_quotation_collection_sla_days', 'label' => 'RFQ Quotation Collection SLA (days)', 'default' => '5', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for collecting quotations or additional quotations.'],
                ['key' => 'quote_review_sla_days', 'label' => 'Quote Review SLA (days)', 'default' => '3', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for quote evaluation and review stages.'],
                ['key' => 'purchase_order_creation_sla_days', 'label' => 'Purchase Order Creation SLA (days)', 'default' => '3', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for creating purchase orders after commitment approval.'],
                ['key' => 'fund_verification_sla_days', 'label' => 'Fund Verification SLA (days)', 'default' => '3', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for finance fund verification stages.'],
                ['key' => 'petty_cash_processing_sla_days', 'label' => 'Petty Cash Processing SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for petty cash finance authorization and processing.'],
                ['key' => 'disbursement_sla_days', 'label' => 'Disbursement SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for invoice payment or petty cash disbursement actions.'],
                ['key' => 'director_approval_sla_days', 'label' => 'Director Approval SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for director- or branch-head approval stages.'],
                ['key' => 'procurement_approval_sla_days', 'label' => 'Procurement Approval SLA (days)', 'default' => '3', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for procurement processing stages.'],
                ['key' => 'finance_approval_sla_days', 'label' => 'Finance Approval SLA (days)', 'default' => '3', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for finance approval and commitment stages.'],
                ['key' => 'request_review_sla_days', 'label' => 'Request Review SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for submitted or requestor review stages.'],
                ['key' => 'returned_request_correction_sla_days', 'label' => 'Returned Request Correction SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for returned request corrections.'],
                ['key' => 'resubmitted_request_sla_days', 'label' => 'Resubmitted Request SLA (days)', 'default' => '2', 'type' => 'int', 'min' => '1', 'description' => 'Allowed turnaround for resubmitted requests re-entering review.'],
                ['key' => 'invoice_overdue_days', 'label' => 'Invoice Overdue Threshold (days)', 'default' => '30', 'type' => 'int', 'min' => '1', 'description' => 'Age after which unpaid invoices are considered overdue.'],
            ],
            'escalation_rules' => [
                ['key' => 'escalation_warning_pct', 'label' => 'Warning Threshold (%)', 'default' => '75', 'type' => 'int', 'min' => '1', 'description' => 'Percentage of SLA duration when warning alerts begin.'],
                ['key' => 'escalation_overdue_pct', 'label' => 'Overdue Threshold (%)', 'default' => '100', 'type' => 'int', 'min' => '1', 'description' => 'Percentage of SLA duration when an item becomes overdue.'],
                ['key' => 'escalation_critical_pct', 'label' => 'Critical Threshold (%)', 'default' => '150', 'type' => 'int', 'min' => '1', 'description' => 'Percentage of SLA duration when critical escalation begins.'],
            ],
        ];
    }

    public static function flatDefinitions(): array
    {
        return array_merge(...array_values(self::definitions()));
    }

    public static function seedDefaults(PDO $pdo): void
    {
        $cacheKey = spl_object_id($pdo);
        if (isset(self::$seededConnections[$cacheKey])) {
            return;
        }
        SystemConfigService::seedDefaults($pdo, self::flatDefinitions());
        self::$seededConnections[$cacheKey] = true;
    }

    public static function readSettings(PDO $pdo): array
    {
        $cacheKey = spl_object_id($pdo);
        if (isset(self::$settingsCache[$cacheKey])) {
            return self::$settingsCache[$cacheKey];
        }

        self::seedDefaults($pdo);
        $settings = [];
        foreach (self::flatDefinitions() as $definition) {
            $settings[$definition['key']] = SystemConfigService::getString($pdo, $definition['key'], (string)$definition['default']);
        }
        self::$settingsCache[$cacheKey] = $settings;
        return $settings;
    }

    public static function savePostedSettings(PDO $pdo, array $post): array
    {
        $saved = [];
        foreach (self::flatDefinitions() as $definition) {
            $key = $definition['key'];
            if (!array_key_exists($key, $post)) {
                continue;
            }

            $value = self::sanitizePostedValue($post[$key], $definition);
            SystemConfigService::upsert($pdo, $key, $value, (string)$definition['description']);
            $saved[$key] = $value;
        }
        if ($saved !== []) {
            $cacheKey = spl_object_id($pdo);
            self::$settingsCache[$cacheKey] = array_merge(self::readSettings($pdo), $saved);
        }
        return $saved;
    }

    public static function getHighValueThreshold(PDO $pdo, string $type): float
    {
        $map = [
            'petty_cash' => 'high_value_petty_cash_threshold',
            'request' => 'high_value_request_threshold',
            'procurement' => 'high_value_procurement_threshold',
            'purchase_order' => 'high_value_purchase_order_threshold',
            'finance' => 'finance_escalation_threshold',
            'director' => 'director_notification_threshold',
            'executive' => 'executive_oversight_threshold',
        ];
        $key = $map[$type] ?? $map['request'];
        $settings = self::readSettings($pdo);
        return isset($settings[$key]) && is_numeric($settings[$key]) ? (float)$settings[$key] : 0.0;
    }

    public static function getInvoiceOverdueDays(PDO $pdo): int
    {
        $settings = self::readSettings($pdo);
        return max(1, (int)($settings['invoice_overdue_days'] ?? 30));
    }

    public static function getEscalationPercentages(PDO $pdo): array
    {
        $settings = self::readSettings($pdo);
        $warning = max(1, (int)($settings['escalation_warning_pct'] ?? 75));
        $overdue = max($warning, (int)($settings['escalation_overdue_pct'] ?? 100));
        $critical = max($overdue, (int)($settings['escalation_critical_pct'] ?? 150));
        return compact('warning', 'overdue', 'critical');
    }

    public static function getStageSlaDays(PDO $pdo, string $requestType, string $status): int
    {
        $key = self::resolveSlaKey($requestType, $status);
        $settings = self::readSettings($pdo);
        $definitions = [];
        foreach (self::flatDefinitions() as $definition) {
            $definitions[$definition['key']] = $definition;
        }
        $default = isset($definitions[$key]) ? (int)$definitions[$key]['default'] : 3;
        return max(1, isset($settings[$key]) ? (int)$settings[$key] : $default);
    }

    public static function getEscalationSnapshot(PDO $pdo, string $requestType, string $status, int $ageDays, float $transactionValue = 0.0): array
    {
        $slaDays = self::getStageSlaDays($pdo, $requestType, $status);
        $percentages = self::getEscalationPercentages($pdo);
        $warningDays = max(1, (int)ceil($slaDays * ($percentages['warning'] / 100)));
        $overdueDays = max($warningDays, (int)ceil($slaDays * ($percentages['overdue'] / 100)));
        $criticalDays = max($overdueDays, (int)ceil($slaDays * ($percentages['critical'] / 100)));

        $riskLevel = 'normal';
        $priority = 'normal';
        if ($ageDays >= $criticalDays) {
            $riskLevel = 'critical';
            $priority = 'urgent';
        } elseif ($ageDays >= $overdueDays) {
            $riskLevel = 'overdue';
            $priority = 'high';
        } elseif ($ageDays >= $warningDays) {
            $riskLevel = 'warning';
            $priority = 'high';
        }

        if ($transactionValue >= self::getHighValueThreshold($pdo, 'executive')) {
            $priority = 'urgent';
        } elseif ($priority === 'normal' && $transactionValue >= self::getHighValueThreshold($pdo, 'director')) {
            $priority = 'high';
        }

        return [
            'sla_days' => $slaDays,
            'warning_days' => $warningDays,
            'overdue_days' => $overdueDays,
            'critical_days' => $criticalDays,
            'risk_level' => $riskLevel,
            'priority' => $priority,
        ];
    }

    public static function getPurchaseOrderOversightLevel(PDO $pdo, float $poTotal): string
    {
        if ($poTotal >= self::getHighValueThreshold($pdo, 'executive')) {
            return 'executive';
        }
        if ($poTotal >= self::getHighValueThreshold($pdo, 'finance')) {
            return 'director_finance';
        }
        if ($poTotal >= self::getHighValueThreshold($pdo, 'purchase_order')) {
            return 'director_procurement';
        }
        return 'none';
    }

    public static function isHighValueRequest(PDO $pdo, string $requestType, float $amount): bool
    {
        $type = strtoupper($requestType);
        if ($type === 'PETTY_CASH') {
            return $amount >= self::getHighValueThreshold($pdo, 'petty_cash');
        }
        return $amount >= self::getHighValueThreshold($pdo, 'request');
    }

    private static function resolveSlaKey(string $requestType, string $status): string
    {
        $requestType = strtoupper(trim($requestType));
        $status = strtoupper(trim($status));

        if ($status === 'RETURNED_FOR_CORRECTION') {
            return 'returned_request_correction_sla_days';
        }
        if ($status === 'RESUBMITTED') {
            return 'resubmitted_request_sla_days';
        }

        if (in_array($status, ['PROCUREMENT_STAGE', 'RFQ_LETTER_AVAILABLE'], true)) {
            return 'rfq_vendor_assignment_sla_days';
        }
        if (in_array($status, ['ADDITIONAL_QUOTATIONS_REQUIRED'], true)) {
            return 'rfq_quotation_collection_sla_days';
        }
        if (in_array($status, ['EVALUATION_STAGE', 'QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED'], true)) {
            return 'quote_review_sla_days';
        }
        if (in_array($status, ['QUOTE_REQUESTOR_REVIEW_PENDING'], true)) {
            return 'request_review_sla_days';
        }
        if (in_array($status, ['HOD_APPROVED', 'DIRECTOR_APPROVED', 'GC_APPROVED', 'QUOTE_REQUESTOR_REVIEW_APPROVED', 'QUOTE_BRANCH_HEAD_APPROVAL_PENDING'], true)) {
            return 'director_approval_sla_days';
        }
        if (in_array($status, ['COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'COMMITMENT_DECLINED'], true)) {
            return 'finance_approval_sla_days';
        }
        if ($status === 'PO_PENDING') {
            return 'purchase_order_creation_sla_days';
        }
        if (in_array($status, ['INVOICE_RECEIVED', 'DISBURSED'], true)) {
            return 'disbursement_sla_days';
        }
        if (in_array($status, ['FUNDS_VERIFIED', 'FINANCE_AUTHORIZED'], true)) {
            return $requestType === 'PETTY_CASH' ? 'petty_cash_processing_sla_days' : 'finance_approval_sla_days';
        }
        if ($status === 'SUBMITTED') {
            return in_array($requestType, ['PETTY_CASH', 'REIMBURSEMENT'], true)
                ? 'fund_verification_sla_days'
                : 'request_review_sla_days';
        }

        return $requestType === 'PETTY_CASH' ? 'petty_cash_processing_sla_days' : 'procurement_approval_sla_days';
    }

    private static function sanitizePostedValue(mixed $value, array $definition): string
    {
        $type = $definition['type'] ?? 'string';
        $min = isset($definition['min']) && is_numeric($definition['min']) ? (float)$definition['min'] : null;

        if ($type === 'int') {
            $normalized = (string)max((int)($min ?? 0), (int)$value);
            return $normalized;
        }

        if ($type === 'money') {
            $float = max((float)($min ?? 0), (float)$value);
            return number_format($float, 2, '.', '');
        }

        return trim((string)$value);
    }
}
