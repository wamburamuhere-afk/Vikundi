-- Email Center (comms > Email) — outbound email log
-- Created/used by includes/email_helper.php (email_ensure_logs_table) and
-- api/email_center.php. Idempotent: the app self-heals this table on load,
-- this file is the canonical reference for the schema.

CREATE TABLE IF NOT EXISTS email_logs (
    email_id        INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(150) NOT NULL,
    recipient_name  VARCHAR(150) DEFAULT NULL,
    subject         VARCHAR(255) NOT NULL,
    body            MEDIUMTEXT,
    status          ENUM('sent','failed','queued') NOT NULL DEFAULT 'queued',
    error_message   TEXT DEFAULT NULL,
    sent_at         DATETIME DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_logs_status (status),
    INDEX idx_email_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional email configuration (read by email_get_settings()). Defaults are
-- applied in code, so these rows are not required for the feature to work.
INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description)
SELECT 'enable_email_notifications', '1', 'communication', '0', 'Master switch for outbound email'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'enable_email_notifications');

INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description)
SELECT 'mail_from_name', 'Vikundi', 'communication', '0', 'Display name on outbound email'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'mail_from_name');

INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public, description)
SELECT 'mail_from_email', '', 'communication', '0', 'From address on outbound email (falls back to company_email)'
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'mail_from_email');
