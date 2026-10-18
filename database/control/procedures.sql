-- database/control/procedures.sql — run ONCE as MySQL root, after schema.sql:
--
--   mysql -u root -p vikundi_control < database/control/procedures.sql
--
-- Why: the provisioner (vikundi_prov) may create vk_* databases and users, but
-- MySQL refuses to let it GRANT on a database it reaches only through its
-- `vk\_%` wildcard grant (error 1044). This routine runs with root's rights
-- (SQL SECURITY DEFINER) and does one thing only: give the group's own MySQL
-- user, vk_<name>@localhost, full rights on its own database, vk_<name>.
-- vikundi_prov may EXECUTE it and nothing more of root's.
--
-- Safe to run again: it replaces the routine and re-grants EXECUTE.

DROP PROCEDURE IF EXISTS vk_grant_group;

DELIMITER //
CREATE PROCEDURE vk_grant_group(IN p_name VARCHAR(32))
    SQL SECURITY DEFINER
BEGIN
    -- Only vk_ + lowercase letters, digits and _ (the provisioner's own naming).
    -- 'c' makes the match case-sensitive; quotes and backticks can never pass.
    IF p_name IS NULL OR NOT REGEXP_LIKE(p_name, '^vk_[a-z0-9_]{1,29}$', 'c') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vk_grant_group: not a group name';
    END IF;
    SET @vk_grant_sql = CONCAT('GRANT ALL PRIVILEGES ON `', p_name, '`.* TO ''', p_name, '''@''localhost''');
    PREPARE vk_grant_stmt FROM @vk_grant_sql;
    EXECUTE vk_grant_stmt;
    DEALLOCATE PREPARE vk_grant_stmt;
    SET @vk_grant_sql = NULL;
END//
DELIMITER ;

GRANT EXECUTE ON PROCEDURE vikundi_control.vk_grant_group TO 'vikundi_prov'@'localhost';
