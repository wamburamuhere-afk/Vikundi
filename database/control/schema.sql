-- database/control/schema.sql — the vikundi_control registry: which groups exist
-- on the shared server, where their databases are, and whether they are online.
--
-- One registry per server. Group data never lives here; each group has its own
-- database (tenants.db_name). Passwords and token secrets are not stored here
-- either: they are in /etc/vikundi/tenants/<subdomain>.php.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS tenants (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subdomain     VARCHAR(30)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    db_name       VARCHAR(64)  NOT NULL,
    db_user       VARCHAR(32)  NOT NULL,
    status        ENUM('provisioning','active','suspended','archived') NOT NULL DEFAULT 'provisioning',
    is_demo       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    suspended_at  DATETIME     NULL,
    archived_at   DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_subdomain (subdomain),
    UNIQUE KEY uq_tenants_db_name (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full host names that reach a group. Every group has its subdomain's host here
-- too; production also has vikundi.bjptechnologies.co.tz, which the wildcard
-- does not cover.
CREATE TABLE IF NOT EXISTS tenant_hosts (
    host       VARCHAR(253) NOT NULL,
    tenant_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (host),
    KEY ix_tenant_hosts_tenant (tenant_id),
    CONSTRAINT fk_tenant_hosts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
