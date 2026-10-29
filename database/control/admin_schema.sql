-- database/control/admin_schema.sql — the super-admin panel's tables in the
-- vikundi_control registry. Run once as MySQL root, after schema.sql:
--
--   mysql -u root -p vikundi_control < database/control/admin_schema.sql
--
-- superadmins          BJP staff who may log in to the panel (created only with
--                      bin/superadmin.php on the server; no sign-up page).
-- provision_requests   what the panel asked for. bin/run_requests.php, run every
--                      minute as ubuntu, carries each one out with
--                      bin/provision_tenant.php and saves the result here.
-- admin_audit_log      every change to a group, from the panel or the server.
--                      Only ever added to.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS superadmins (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username             VARCHAR(50)   NOT NULL,
    full_name            VARCHAR(120)  NOT NULL,
    password_hash        VARCHAR(255)  NOT NULL,
    is_active            TINYINT(1)    NOT NULL DEFAULT 1,
    failed_attempts      INT UNSIGNED  NOT NULL DEFAULT 0,
    locked_until         DATETIME      NULL,
    last_login_at        DATETIME      NULL,
    last_login_ip        VARCHAR(45)   NULL,
    password_changed_at  DATETIME      NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_superadmins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provision_requests (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    action            ENUM('create','suspend','resume','archive','cert','features') NOT NULL,
    subdomain         VARCHAR(30)   NULL,              -- NULL only for 'cert'
    params            JSON          NULL,              -- the create form's fields, or the features to set
    requested_by      INT UNSIGNED  NULL,
    status            ENUM('waiting','running','done','failed') NOT NULL DEFAULT 'waiting',
    exit_code         INT           NULL,
    warning           VARCHAR(255)  NULL,              -- done, but something needs a look
    output            MEDIUMTEXT    NULL,              -- the command's output, password removed
    one_time_secret   VARCHAR(255)  NULL,              -- first Chairperson password, erased once seen
    secret_viewed_at  DATETIME      NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at        DATETIME      NULL,
    finished_at       DATETIME      NULL,
    PRIMARY KEY (id),
    KEY ix_requests_status (status, id),
    KEY ix_requests_subdomain (subdomain),
    CONSTRAINT fk_requests_superadmin FOREIGN KEY (requested_by) REFERENCES superadmins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_type  ENUM('superadmin','server') NOT NULL,
    actor_id    INT UNSIGNED    NULL,
    actor_name  VARCHAR(120)    NOT NULL,
    action      VARCHAR(40)     NOT NULL,
    subdomain   VARCHAR(30)     NULL,
    request_id  INT UNSIGNED    NULL,
    result      VARCHAR(20)     NOT NULL,
    detail      VARCHAR(500)    NULL,
    ip          VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    KEY ix_audit_subdomain (subdomain),
    KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
