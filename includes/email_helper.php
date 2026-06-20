<?php
/**
 * Email Helper for the Vikundi VICOBA System
 * --------------------------------------------------------------
 * Handles outbound email sending and logging. Modelled on the
 * existing includes/sms_helper.php pattern: configuration is read
 * from the `system_settings` table and every send is recorded in
 * the `email_logs` table for the Email Center (comms > Email).
 *
 * The pure helper functions (validation, template rendering and
 * recipient parsing) are intentionally free of any DB dependency
 * so they can be unit-tested without a database connection.
 */

// ---------------------------------------------------------------------------
// Pure helpers (no DB) — safe to unit-test in isolation
// ---------------------------------------------------------------------------

if (!function_exists('email_is_valid')) {
    /**
     * Validate a single email address.
     *
     * @param string $email
     * @return bool
     */
    function email_is_valid($email): bool
    {
        $email = trim((string)$email);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('email_parse_recipients')) {
    /**
     * Parse a free-text list of email addresses (separated by comma,
     * semicolon, space or new line) into an array of unique, valid,
     * lower-cased addresses.
     *
     * @param string $raw
     * @return string[] Unique valid addresses (order preserved)
     */
    function email_parse_recipients($raw): array
    {
        $parts = preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = [];
        foreach ($parts as $part) {
            $candidate = strtolower(trim($part));
            if (email_is_valid($candidate) && !in_array($candidate, $valid, true)) {
                $valid[] = $candidate;
            }
        }
        return $valid;
    }
}

if (!function_exists('email_render_template')) {
    /**
     * Replace {{placeholder}} tokens in a template with provided values.
     * Tokens with no matching key are left untouched. Whitespace inside
     * the braces is tolerated, e.g. {{ member_name }}.
     *
     * @param string               $template
     * @param array<string,string> $vars
     * @return string
     */
    function email_render_template($template, array $vars): string
    {
        $template = (string)$template;
        if (empty($vars)) {
            return $template;
        }
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string)$vars[$key] : $m[0];
        }, $template);
    }
}

// ---------------------------------------------------------------------------
// DB-backed helpers
// ---------------------------------------------------------------------------

if (!function_exists('email_ensure_logs_table')) {
    /**
     * Create the email_logs table if it does not yet exist. Idempotent —
     * safe to call on every page load (mirrors the self-healing schema
     * approach used elsewhere in the project).
     *
     * @param PDO $pdo
     * @return void
     */
    function email_ensure_logs_table(PDO $pdo): void
    {
        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('email_ensure_templates_table')) {
    /**
     * Create the email_templates table if it does not yet exist. Idempotent.
     * Backs the Email Templates page and the Email Center compose picker.
     *
     * @param PDO $pdo
     * @return void
     */
    function email_ensure_templates_table(PDO $pdo): void
    {
        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('email_template_types')) {
    /**
     * Canonical template type vocabulary with bilingual labels. Keeping it
     * here means the Templates page and the Email Center share one source of
     * truth for the type list.
     *
     * @param bool $is_sw
     * @return array<string,string> type-key => label
     */
    function email_template_types(bool $is_sw = false): array
    {
        return [
            'general'  => $is_sw ? 'Kawaida' : 'General',
            'loan'     => $is_sw ? 'Mkopo' : 'Loan Related',
            'payment'  => $is_sw ? 'Malipo' : 'Payment/Collection',
            'security' => $is_sw ? 'Usalama' : 'Security/Auth',
        ];
    }
}

if (!function_exists('email_get_settings')) {
    /**
     * Read email configuration from system_settings, with sensible
     * defaults. Keys honoured (all optional):
     *   enable_email_notifications, mail_from_email, mail_from_name,
     *   company_email
     *
     * @param PDO $pdo
     * @return array{enabled:bool,from_email:string,from_name:string}
     */
    function email_get_settings(PDO $pdo): array
    {
        $settings = [];
        try {
            $stmt = $pdo->query("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key IN (
                    'enable_email_notifications', 'mail_from_email',
                    'mail_from_name', 'company_email'
                )
            ");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            // Table/columns missing — fall back to defaults below.
            error_log('email_get_settings: ' . $e->getMessage());
        }

        $from_email = $settings['mail_from_email'] ?? $settings['company_email'] ?? '';
        $from_name  = $settings['mail_from_name'] ?? 'Vikundi';

        return [
            // Default to enabled so the feature works out of the box; an
            // explicit '0' in settings disables it.
            'enabled'    => ($settings['enable_email_notifications'] ?? '1') != '0',
            'from_email' => trim((string)$from_email),
            'from_name'  => trim((string)$from_name) ?: 'Vikundi',
        ];
    }
}

if (!function_exists('email_send')) {
    /**
     * Send an HTML email to a single recipient and record the attempt
     * in email_logs. Never throws — always returns a status array.
     *
     * @param string $to      Recipient email address
     * @param string $subject Email subject
     * @param string $body    Email body (HTML allowed)
     * @param array  $opts     {
     *     @var string   recipient_name Friendly recipient name (logged)
     *     @var int|null created_by     User id performing the send
     *     @var bool     log            Whether to write to email_logs (default true)
     * }
     * @return array{success:bool,message:string,email_id:?int,status:string}
     */
    function email_send($to, $subject, $body, array $opts = []): array
    {
        global $pdo;

        $to      = trim((string)$to);
        $subject = trim((string)$subject);
        $name    = $opts['recipient_name'] ?? null;
        $created_by = $opts['created_by'] ?? ($_SESSION['user_id'] ?? null);
        $do_log  = $opts['log'] ?? true;

        if (!email_is_valid($to)) {
            return ['success' => false, 'message' => 'Invalid recipient email address.', 'email_id' => null, 'status' => 'failed'];
        }
        if ($subject === '') {
            return ['success' => false, 'message' => 'Email subject is required.', 'email_id' => null, 'status' => 'failed'];
        }

        $settings = ($pdo instanceof PDO) ? email_get_settings($pdo) : ['enabled' => true, 'from_email' => '', 'from_name' => 'Vikundi'];

        if (!$settings['enabled']) {
            return ['success' => false, 'message' => 'Email notifications are disabled in settings.', 'email_id' => null, 'status' => 'failed'];
        }

        $status = 'failed';
        $error  = null;

        try {
            $from_email = $settings['from_email'] !== '' ? $settings['from_email'] : ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'vikundi.local'));
            $from_name  = $settings['from_name'];

            $headers   = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
            $headers[] = 'Reply-To: ' . $from_email;

            // mail() returns false when no MTA is configured (typical on
            // local WAMP). We record the outcome honestly rather than
            // pretending the message was delivered.
            $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
            if ($sent) {
                $status = 'sent';
            } else {
                $error  = 'Mail transport unavailable or rejected the message.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $email_id = null;
        if ($do_log && $pdo instanceof PDO) {
            try {
                email_ensure_logs_table($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO email_logs
                        (recipient_email, recipient_name, subject, body, status, error_message, sent_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $to,
                    $name,
                    $subject,
                    $body,
                    $status,
                    $error,
                    $status === 'sent' ? date('Y-m-d H:i:s') : null,
                    $created_by,
                ]);
                $email_id = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                error_log('email_send log: ' . $e->getMessage());
            }
        }

        return [
            'success'  => $status === 'sent',
            'message'  => $status === 'sent' ? 'Email sent.' : ($error ?? 'Email could not be sent.'),
            'email_id' => $email_id,
            'status'   => $status,
        ];
    }
}
