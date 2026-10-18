<?php
/**
 * includes/admin_requests.php — the rules for super-admin panel requests.
 *
 * The panel never changes a group itself: it saves a row in
 * vikundi_control.provision_requests, and bin/run_requests.php (run every minute
 * as ubuntu) carries it out with bin/provision_tenant.php. These functions are
 * shared by both sides, so a request is checked with the same rules when it is
 * made and again when it is run. Pure: no database, no files.
 */

require_once __DIR__ . '/provisioning.php';

if (!function_exists('vk_req_actions')) {
    /** The actions the panel may ask for. Nothing else is ever run. */
    function vk_req_actions(): array
    {
        return ['create', 'suspend', 'resume', 'archive', 'cert'];
    }
}

if (!function_exists('vk_req_clean_params')) {
    /**
     * The create form's fields, trimmed and reduced to the ones we use.
     * Anything else in the array is dropped.
     */
    function vk_req_clean_params(array $p): array
    {
        $s = static fn($k) => trim((string) ($p[$k] ?? ''));
        return [
            'name'        => $s('name'),
            'chair_first' => $s('chair_first'),
            'chair_last'  => $s('chair_last'),
            'chair_phone' => preg_replace('/\D/', '', $s('chair_phone')),
            'chair_email' => $s('chair_email'),
            'demo'        => !empty($p['demo']) && $p['demo'] !== '0',
        ];
    }
}

if (!function_exists('vk_req_validate')) {
    /**
     * What is wrong with a request, as a list of messages; [] when it may run.
     * $taken / $hosts / $base are only used for 'create' (is the address free?).
     */
    function vk_req_validate(string $action, ?string $sub, array $params = [], array $taken = [], array $hosts = [], string $base = ''): array
    {
        if (!in_array($action, vk_req_actions(), true)) {
            return ["Unknown action '$action'."];
        }
        if ($action === 'cert') {
            return [];
        }
        $sub = (string) $sub;
        if ($action !== 'create') {
            return vk_tenant_valid_subdomain($sub) ? [] : ['Not a valid group address.'];
        }

        $errors = [];
        if ($why = vk_prov_validate_new($sub, $taken, $hosts, $base)) {
            $errors[] = $why;
        }
        $p = vk_req_clean_params($params);
        $plain = static fn(string $v) => !preg_match('/[\x00-\x1F\x7F]/', $v);
        if (mb_strlen($p['name']) < 2 || mb_strlen($p['name']) > 150 || !$plain($p['name'])) {
            $errors[] = 'Group name: 2 to 150 characters.';
        }
        foreach (['chair_first' => 'Chairperson first name', 'chair_last' => 'Chairperson last name'] as $k => $label) {
            if (!preg_match('/^[\p{L}][\p{L}\' .-]{0,59}$/u', $p[$k])) {
                $errors[] = "$label: letters only, up to 60.";
            }
        }
        if (!preg_match('/^\d{9,15}$/', $p['chair_phone'])) {
            $errors[] = 'Chairperson phone: 9 to 15 digits.';
        }
        if ($p['chair_email'] !== '' && filter_var($p['chair_email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Chairperson email is not a valid address.';
        }
        return $errors;
    }
}

if (!function_exists('vk_req_command_args')) {
    /**
     * The arguments for bin/provision_tenant.php. Each value is its own argv
     * element and the command is run without a shell, so no value can add
     * another option or command.
     */
    function vk_req_command_args(string $action, ?string $sub, array $params = []): array
    {
        if ($action === 'cert') {
            return ['--cert'];
        }
        if ($action !== 'create') {
            return ['--' . $action . '=' . $sub];
        }
        $p = vk_req_clean_params($params);
        $args = [
            '--create',
            '--subdomain=' . $sub,
            '--name=' . $p['name'],
            '--chair-first=' . $p['chair_first'],
            '--chair-last=' . $p['chair_last'],
            '--chair-phone=' . $p['chair_phone'],
        ];
        if ($p['chair_email'] !== '') {
            $args[] = '--chair-email=' . $p['chair_email'];
        }
        if ($p['demo']) {
            $args[] = '--demo';
        }
        return $args;
    }
}

if (!function_exists('vk_req_extract_secret')) {
    /**
     * Take the first Chairperson's password out of --create's output.
     * Returns [output with the password replaced, the password or null].
     */
    function vk_req_extract_secret(string $output): array
    {
        if (!preg_match('/First Chairperson: username \S+, password (\S+)/', $output, $m)) {
            return [$output, null];
        }
        $redacted = str_replace('password ' . $m[1], 'password (shown once in the panel)', $output);
        return [$redacted, $m[1]];
    }
}

if (!function_exists('vk_req_outcome')) {
    /**
     * The request's final status from the command's exit code.
     * provision_tenant.php exits 2 when the group was made but the https
     * certificate failed: the group is fine, the certificate needs a retry.
     *
     * @return array{0:string,1:?string}  [status, warning]
     */
    function vk_req_outcome(string $action, int $exitCode): array
    {
        if ($exitCode === 0) {
            return ['done', null];
        }
        if ($exitCode === 2 && $action === 'create') {
            return ['done', 'Group created, but the https certificate failed. Use Retry certificate.'];
        }
        return ['failed', null];
    }
}

if (!function_exists('vk_req_trim_output')) {
    /** Keep the end of a long output (where the result and any error are). */
    function vk_req_trim_output(string $output, int $max = 60000): string
    {
        if (strlen($output) <= $max) {
            return $output;
        }
        return "[... earlier output cut ...]\n" . substr($output, -$max);
    }
}

if (!function_exists('vk_req_audit_action')) {
    /** The history's name for an action. */
    function vk_req_audit_action(string $action): string
    {
        return match ($action) {
            'create'  => 'create group',
            'suspend' => 'suspend group',
            'resume'  => 'resume group',
            'archive' => 'archive group',
            'cert'    => 'update certificate',
            default   => $action,
        };
    }
}

if (!function_exists('vk_superadmin_valid_username')) {
    /** Super-admin usernames: 3 to 50 lowercase letters, digits, dot, dash or underscore. */
    function vk_superadmin_valid_username(string $u): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{3,50}$/', $u);
    }
}

if (!function_exists('vk_superadmin_password_problem')) {
    /** Why a new super-admin password is not acceptable, or null. */
    function vk_superadmin_password_problem(string $pw, string $username = ''): ?string
    {
        if (strlen($pw) < 12) {
            return 'Use at least 12 characters.';
        }
        if ($username !== '' && stripos($pw, $username) !== false) {
            return 'The password must not contain the username.';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Use letters and at least one digit.';
        }
        return null;
    }
}
