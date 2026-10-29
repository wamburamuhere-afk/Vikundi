-- database/control/add_tenant_features.sql — per-group features (Shares, Dividends),
-- switched by the super-admin. Run once as MySQL root on a registry made before
-- this change (a new one already has both from schema.sql / admin_schema.sql):
--
--   mysql -u root -p vikundi_control < database/control/add_tenant_features.sql
--
-- 1. tenants.features — JSON, NULL = every feature on (includes/features.php).
-- 2. provision_requests.action may be 'features' (the panel's Features form).
--
-- Safe to run more than once. Changes no existing value: every group stays as it is.

SET @vk_has := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'features');
SET @vk_sql := IF(@vk_has = 0, 'ALTER TABLE tenants ADD COLUMN features JSON NULL AFTER is_demo', 'DO 0');
PREPARE vk_stmt FROM @vk_sql;
EXECUTE vk_stmt;
DEALLOCATE PREPARE vk_stmt;

ALTER TABLE provision_requests
    MODIFY action ENUM('create','suspend','resume','archive','cert','features') NOT NULL;
