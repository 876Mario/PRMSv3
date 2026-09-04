INSERT INTO permissions (name, description)
SELECT 'approve_inter_mda_transfer', 'Approve Financial Secretary stage for inter-MDA stock transfers'
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE name = 'approve_inter_mda_transfer'
);
