-- database/control/add_clear_demo_action.sql — lets the panel ask for "Clear demo data"
-- (bin/provision_tenant.php --clear-demo). Run once as MySQL root on a registry made
-- before this change (a new one already has it from admin_schema.sql):
--
--   mysql -u root -p vikundi_control < database/control/add_clear_demo_action.sql
--
-- Safe to run more than once. Changes no existing request.

ALTER TABLE provision_requests
    MODIFY action ENUM('create','suspend','resume','archive','cert','features','clear_demo') NOT NULL;
