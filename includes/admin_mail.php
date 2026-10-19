<?php
/**
 * includes/admin_mail.php — the email the worker sends when a panel request
 * fails, or finishes with a warning (e.g. the https certificate failed).
 *
 * Settings come from /etc/vikundi/admin.php:
 *   'alert_to' => 'someone@example.com',
 *   'smtp'     => ['host' => 'mail.example.com', 'port' => 465, 'secure' => 'ssl',
 *                  'user' => 'noreply@example.com', 'password' => '...',
 *                  'from' => 'noreply@example.com', 'from_name' => 'Vikundi Super-Admin'],
 * Without them nothing is sent and the worker carries on.
 */

if (!function_exists('vk_admin_alert_needed')) {
    function vk_admin_alert_needed(string $status, ?string $warning): bool
    {
        return $status === 'failed' || ($warning !== null && $warning !== '');
    }
}

if (!function_exists('vk_admin_alert_message')) {
    /**
     * Subject and plain-text body. The output is the stored, password-free
     * copy, cut to its last 40 lines.
     *
     * @return array{0:string,1:string}
     */
    function vk_admin_alert_message(int $id, string $action, ?string $sub, string $status, ?string $warning, string $requestedBy, string $output, string $baseDomain): array
    {
        $what = ucfirst(vk_req_audit_action($action)) . ($sub ? " $sub" : '');
        $subject = $status === 'failed'
            ? "[Vikundi] Request #$id failed: $what"
            : "[Vikundi] Request #$id needs a look: $what";
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $tail = implode("\n", array_slice($lines, -40));
        $body = "Request #$id: $what\n"
              . "Asked by: $requestedBy\n"
              . 'Result: ' . ($status === 'failed' ? 'FAILED' : 'done, with a warning') . "\n"
              . ($warning ? "Warning: $warning\n" : '')
              . "\nOpen it: https://admin.$baseDomain/requests/$id\n"
              . "\n--- last lines of the output ---\n$tail\n";
        return [$subject, $body];
    }
}

if (!function_exists('vk_admin_send_alert')) {
    /** Send it. Returns null when sent, or the reason it was not. */
    function vk_admin_send_alert(?array $adminConf, string $subject, string $body): ?string
    {
        $to = (string) ($adminConf['alert_to'] ?? '');
        $smtp = $adminConf['smtp'] ?? null;
        if ($to === '' || !is_array($smtp) || empty($smtp['host'])) {
            return 'no alert_to / smtp settings in admin.php';
        }
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            return 'vendor/autoload.php missing (run composer install)';
        }
        require_once $autoload;
        try {
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string) $smtp['host'];
            $m->Port = (int) ($smtp['port'] ?? 465);
            $m->SMTPAuth = true;
            $m->Username = (string) ($smtp['user'] ?? '');
            $m->Password = (string) ($smtp['password'] ?? '');
            $m->SMTPSecure = ($smtp['secure'] ?? 'ssl') === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $m->Timeout = 20;
            $m->CharSet = 'UTF-8';
            $m->setFrom((string) ($smtp['from'] ?? $smtp['user']), (string) ($smtp['from_name'] ?? 'Vikundi Super-Admin'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->Body = $body;
            $m->isHTML(false);
            $m->send();
            return null;
        } catch (Throwable $e) {
            return 'mail failed: ' . $e->getMessage();
        }
    }
}
