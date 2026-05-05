-- Add Bank Reconciliation Permission
INSERT INTO `permissions` (`permission_name`, `page_key`, `page_name`, `description`, `module_id`, `module_name`) 
VALUES ('', 'bank_reconciliation', 'Bank Reconciliation', 'View and manage bank reconciliations', NULL, 'Accounts');

-- Assign View/Create/Edit/Delete permissions to Admin (Role ID 1)
-- Assuming Role ID 1 is Admin. Adjust if necessary.
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `can_view`, `can_create`, `can_edit`, `can_delete`) 
SELECT 1, permission_id, 1, 1, 1, 1 
FROM `permissions` 
WHERE `page_key` = 'bank_reconciliation';

-- You may want to assign permissions to other roles (e.g., Accountant) as needed.
-- Example for Role ID 2 (Accountant):
-- INSERT INTO `role_permissions` (`role_id`, `permission_id`, `can_view`, `can_create`, `can_edit`, `can_delete`) 
-- SELECT 2, permission_id, 1, 1, 1, 0 
-- FROM `permissions` 
-- WHERE `page_key` = 'bank_reconciliation';
