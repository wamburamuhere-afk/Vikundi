-- Add 'invoices' permission to permissions table
INSERT INTO permissions (permission_key, description, module, created_at)
SELECT 'invoices', 'Manage Invoices and Income Statements', 'accounts', NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'invoices');

-- Assign 'invoices' permission to 'Admin' role (role_id = 1 usually, or fetch by name)
-- Assuming Admin role exists
INSERT INTO role_permissions (role_id, permission_key, created_at)
SELECT r.role_id, 'invoices', NOW()
FROM roles r
WHERE r.role_name = 'Admin'
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_key = 'invoices');

-- Optionally assign to Manager or Accountant if they exist
INSERT INTO role_permissions (role_id, permission_key, created_at)
SELECT r.role_id, 'invoices', NOW()
FROM roles r
WHERE r.role_name IN ('Manager', 'Accountant')
AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.role_id AND rp.permission_key = 'invoices');
