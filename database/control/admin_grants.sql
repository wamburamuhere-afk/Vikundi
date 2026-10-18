-- database/control/admin_grants.sql — the panel's MySQL login, vikundi_admin,
-- gets only what the panel needs. Run once as MySQL root AFTER the user exists
-- (CREATE USER 'vikundi_admin'@'localhost' IDENTIFIED BY '...') and after
-- admin_schema.sql:
--
--   mysql -u root -p vikundi_control < database/control/admin_grants.sql
--
-- It may read the group list, save requests, hand over one-time passwords,
-- keep its own login records and add to the history. It cannot create,
-- change or drop groups or databases, and cannot edit or delete history.

GRANT SELECT ON vikundi_control.tenants TO 'vikundi_admin'@'localhost';
GRANT SELECT ON vikundi_control.tenant_hosts TO 'vikundi_admin'@'localhost';
GRANT SELECT, INSERT ON vikundi_control.provision_requests TO 'vikundi_admin'@'localhost';
GRANT UPDATE (one_time_secret, secret_viewed_at) ON vikundi_control.provision_requests TO 'vikundi_admin'@'localhost';
GRANT SELECT ON vikundi_control.superadmins TO 'vikundi_admin'@'localhost';
GRANT UPDATE (failed_attempts, locked_until, last_login_at, last_login_ip, password_hash, password_changed_at)
    ON vikundi_control.superadmins TO 'vikundi_admin'@'localhost';
GRANT SELECT, INSERT ON vikundi_control.admin_audit_log TO 'vikundi_admin'@'localhost';
