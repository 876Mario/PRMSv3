-- Ensure signed procurement request upload permission is assigned to operational roles
-- so signed uploads from Request Documents can be registered and workflow can progress.

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT
    r.id,
    p.id
FROM roles r
CROSS JOIN permissions p
WHERE p.name = 'upload_procurement_signed_request'
  AND r.name IN (
      'Procurement Officer',
      'Finance Officer',
      'HOD',
      'Deputy Government Chemist',
      'Director HRM&A',
      'Admin',
      'SuperAdmin'
  );

INSERT INTO audit_log (table_name, record_id, action, notes)
VALUES (
    'DATABASE',
    0,
    'SCHEMA_CHANGE',
    'Assigned upload_procurement_signed_request permission to workflow approver and admin roles to prevent signed request registration failures.'
);
