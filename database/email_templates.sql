-- Email Templates (comms > Email Templates) — reusable email templates
-- Created/used by includes/email_helper.php (email_ensure_templates_table) and
-- api/{get,save,delete}_email_template(s).php. Idempotent: the app self-heals
-- this table on load; this file is the canonical reference for the schema.

CREATE TABLE IF NOT EXISTS email_templates (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    template_type ENUM('general','loan','payment','security') NOT NULL DEFAULT 'general',
    subject       VARCHAR(255) NOT NULL,
    content       MEDIUMTEXT NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_templates_active (is_active),
    INDEX idx_email_templates_type (template_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
