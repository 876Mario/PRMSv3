-- Director Accounts & Finance role, hierarchy, and director notification config

START TRANSACTION;

INSERT IGNORE INTO roles (name, description)
VALUES ('Director Accounts & Finance', 'Supervises Finance Officer workflows and escalations');

INSERT IGNORE INTO permissions (name, description) VALUES
('view_director_finance_dashboard', 'Access Director Accounts & Finance dashboard');

SET @director_finance_role_id = (
    SELECT id FROM roles WHERE name = 'Director Accounts & Finance' LIMIT 1
);

SET @director_procurement_role_id = (
    SELECT id FROM roles WHERE name = 'Director Procurement' LIMIT 1
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT @director_finance_role_id, p.id
FROM permissions p
WHERE @director_finance_role_id IS NOT NULL
  AND p.name IN (
      'view_director_dashboard',
      'view_director_finance_dashboard',
      'view_finance_dashboard',
      'view_requests',
      'view_reimbursement_requests',
      'view_petty_cash_requests',
      'view_commitments',
      'view_purchase_orders',
      'view_invoices',
      'view_payments',
      'view_audit_logs',
      'view_financial_reports',
      'view_approval_analytics',
      'approve_reimbursement_request',
      'approve_petty_cash_request',
      'verify_reimbursement_goods',
      'verify_petty_cash_reconciliation',
      'record_invoice',
      'record_payment',
      'reconcile_petty_cash',
      'print_request',
      'print_invoice',
      'print_purchase_order',
      'export_requests'
  );

-- Enforce supervisory hierarchy defaults.
-- Finance Officer -> Director Accounts & Finance
UPDATE users u
JOIN roles r ON r.id = u.role_id
SET u.supervisor_id = (
    SELECT d.user_id
    FROM users d
    JOIN roles rd ON rd.id = d.role_id
    WHERE rd.name = 'Director Accounts & Finance'
      AND d.is_active = 1
    ORDER BY d.user_id ASC
    LIMIT 1
)
WHERE r.name = 'Finance Officer'
  AND u.is_active = 1
  AND EXISTS (
      SELECT 1
      FROM users d
      JOIN roles rd ON rd.id = d.role_id
      WHERE rd.name = 'Director Accounts & Finance'
        AND d.is_active = 1
  );

-- Procurement Officer -> Director Procurement
UPDATE users u
JOIN roles r ON r.id = u.role_id
SET u.supervisor_id = (
    SELECT d.user_id
    FROM users d
    JOIN roles rd ON rd.id = d.role_id
    WHERE rd.name = 'Director Procurement'
      AND d.is_active = 1
    ORDER BY d.user_id ASC
    LIMIT 1
)
WHERE r.name = 'Procurement Officer'
  AND u.is_active = 1
  AND EXISTS (
      SELECT 1
      FROM users d
      JOIN roles rd ON rd.id = d.role_id
      WHERE rd.name = 'Director Procurement'
        AND d.is_active = 1
  );

INSERT INTO system_config (config_key, config_value)
SELECT 'high_value_petty_cash_threshold', '500000'
WHERE NOT EXISTS (
    SELECT 1 FROM system_config WHERE config_key = 'high_value_petty_cash_threshold'
);

INSERT INTO system_config (config_key, config_value)
SELECT 'director_notification_sla_days', '7'
WHERE NOT EXISTS (
    SELECT 1 FROM system_config WHERE config_key = 'director_notification_sla_days'
);

COMMIT;
