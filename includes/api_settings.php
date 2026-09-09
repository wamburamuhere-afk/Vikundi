<?php
/**
 * includes/api_settings.php — shared rules for Module 17 (Settings & Roles).
 *
 * Covers Users, Roles & Permissions, System Settings, and Backups.
 *
 * GATING, deliberately uniform and NOT going through vk_api_can()/page_keys.
 * Every page this module mirrors (users.php, add_user.php, edit_user.php,
 * user_roles.php, manage_permissions.php, system_settings.php,
 * backup_restore.php) is admin-only by design — 'users', 'user_roles' and
 * 'system_settings' are literally in includes/role_grants.php's
 * vk_admin_only_keys(), and backup_restore.php's own web gate has always been
 * a hard isAdmin(), never a page_key. Every endpoint here checks
 * vk_api_is_admin((int) $auth['role_id']) directly — the same pure, role_id-only
 * bypass vk_api_can() already uses internally, without adding a page_key
 * indirection this module never needed. See this session's own note
 * (memory: sec-015-isadmin-name-bypass) on why role_id-only matters here
 * specifically: the WEB's isAdmin() ALSO matches on session role-name strings
 * that can drift from role_id — confirmed live, deliberately NOT carried into
 * this API, exactly like every other module's vk_api_is_admin().
 *
 * SCOPE, confirmed with the group before writing any code (see todo.md §17):
 *
 *   - Role changes via the API are Admin/Chairperson only. The web's own
 *     actions/update_user_role.php let Secretary/Treasurer change ANY user's
 *     role, including granting Admin — inconsistent with edit_user.php's own
 *     stricter canEdit('users') gate (which, since 'users' is admin-only,
 *     already structurally resolves to Admin/Chairperson alone). Fixed on
 *     the web in the same change; the API was never built the wider way.
 *
 *   - No user deletion, anywhere in this API. actions/update_user_status.php's
 *     'deleted' status doesn't soft-delete — it runs an actual SQL DELETE —
 *     and isn't even a real value in users.status's enum
 *     ('pending','active','rejected','dormant'); it's a magic trigger
 *     intercepted before the real UPDATE. todo.md's plan never asked for a
 *     delete endpoint. PUT /users/{id} only ever accepts the real enum.
 */

require_once __DIR__ . '/api_auth.php'; // vk_api_is_admin()

if (!function_exists('vk_api_settings_require_admin')) {
    function vk_api_settings_require_admin(array $auth): void
    {
        if (!vk_api_is_admin((int) $auth['role_id'])) {
            vk_api_error(403, 'forbidden', 'This is available to Admin/Chairperson only.');
        }
    }
}

// --- Users --------------------------------------------------------------

if (!function_exists('vk_api_user_statuses')) {
    /** The real enum, as the column declares it — 'deleted' is not a real value (see file header). */
    function vk_api_user_statuses(): array
    {
        return ['pending', 'active', 'rejected', 'dormant'];
    }
}

if (!function_exists('vk_api_user_row')) {
    function vk_api_user_row(array $r): array
    {
        return [
            'user_id'    => (int) $r['user_id'],
            'username'   => (string) $r['username'],
            'email'      => (string) ($r['email'] ?? ''),
            'first_name' => (string) ($r['first_name'] ?? ''),
            'last_name'  => (string) ($r['last_name'] ?? ''),
            'role_id'    => $r['role_id'] !== null ? (int) $r['role_id'] : null,
            'role_name'  => $r['role_name'] ?? null,
            'status'     => (string) ($r['status'] ?? 'pending'),
            'created_at' => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'last_login' => !empty($r['last_login']) ? date(DATE_ATOM, strtotime((string) $r['last_login'])) : null,
            // Never: password, password hash, or anything derived from it.
        ];
    }
}

if (!function_exists('vk_api_users_filters')) {
    /** @return array{0:string[],1:array} */
    function vk_api_users_filters(PDO $pdo, array $q): array
    {
        $where = [];
        $params = [];

        $roleId = (int) ($q['role_id'] ?? 0);
        if ($roleId > 0) {
            $where[] = 'u.role_id = ?';
            $params[] = $roleId;
        }

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_user_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: ' . implode(', ', vk_api_user_statuses()) . '.');
            }
            $where[] = 'u.status = ?';
            $params[] = $status;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_users_validate_role')) {
    function vk_api_users_validate_role(PDO $pdo, $roleId): int
    {
        $roleId = (int) $roleId;
        if ($roleId <= 0) {
            vk_api_error(422, 'role_required', 'role_id is required.');
        }
        $st = $pdo->prepare('SELECT 1 FROM roles WHERE role_id = ?');
        $st->execute([$roleId]);
        if (!$st->fetchColumn()) {
            vk_api_error(404, 'role_not_found', 'No role was found with that id.');
        }
        return $roleId;
    }
}

if (!function_exists('vk_api_users_validate_unique')) {
    /** Username/email must be unique, excluding the row being edited (0 = a new user). */
    function vk_api_users_validate_unique(PDO $pdo, string $username, string $email, int $excludeUserId = 0): void
    {
        $st = $pdo->prepare('SELECT username, email FROM users WHERE (username = ? OR email = ?) AND user_id != ?');
        $st->execute([$username, $email, $excludeUserId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) $row['username'], $username) === 0) {
                vk_api_error(409, 'username_taken', 'That username is already in use.');
            }
            if (strcasecmp((string) $row['email'], $email) === 0) {
                vk_api_error(409, 'email_taken', 'That email is already in use.');
            }
        }
    }
}

if (!function_exists('vk_api_users_validate_password')) {
    function vk_api_users_validate_password(string $password, string $lang = 'en'): void
    {
        $errors = reg_password_errors($password, $lang);
        if ($errors) {
            vk_api_error(422, 'weak_password', implode(' ', $errors));
        }
    }
}

// --- Roles & permissions --------------------------------------------------

if (!function_exists('VK_API_PROTECTED_ROLE_ID')) {
    // Mirrors manage_permissions.php's own hardcoded rule: "The Admin role is
    // protected and its permissions cannot be modified."
    define('VK_API_PROTECTED_ROLE_ID', 1);
}

if (!function_exists('vk_api_role_row')) {
    function vk_api_role_row(array $r): array
    {
        return [
            'role_id'     => (int) $r['role_id'],
            'role_name'   => (string) $r['role_name'],
            'description' => $r['description'] ?? null,
            'user_count'  => array_key_exists('user_count', $r) ? (int) $r['user_count'] : null,
        ];
    }
}

if (!function_exists('vk_api_role_permission_row')) {
    function vk_api_role_permission_row(array $perm, array $grant): array
    {
        return [
            'permission_id' => (int) $perm['permission_id'],
            'page_key'      => (string) $perm['page_key'],
            'page_name'     => (string) ($perm['page_name'] ?? $perm['page_key']),
            'module_name'   => $perm['module_name'] ?? null,
            'description'   => $perm['description'] ?? null,
            'can_view'      => (bool) ($grant['can_view'] ?? false),
            'can_create'    => (bool) ($grant['can_create'] ?? false),
            'can_edit'      => (bool) ($grant['can_edit'] ?? false),
            'can_delete'    => (bool) ($grant['can_delete'] ?? false),
        ];
    }
}

if (!function_exists('vk_api_role_permissions_validate')) {
    /**
     * Validate the submitted {permission_id: {view,create,edit,delete}} map into
     * a clean [[permission_id, view, create, edit, delete], ...] list, stricter
     * than manage_permissions.php's own handler: create/edit/delete require view
     * (the web only enforces that client-side, via disabled checkboxes — a
     * scripted request could otherwise submit create=1,view=0. canCreate()
     * already refuses that combination at read time since it checks canView()
     * first, so the web's gap was never exploitable, but there is no reason to
     * carry an inconsistent row into the database when this is free to validate).
     *
     * @return array<int, array{permission_id:int, view:bool, create:bool, edit:bool, delete:bool}>
     */
    function vk_api_role_permissions_validate(PDO $pdo, $raw): array
    {
        if (!is_array($raw)) {
            vk_api_error(422, 'permissions_required', 'permissions must be an object of {permission_id: {view,create,edit,delete}}.');
        }

        $ids = array_map('intval', array_keys($raw));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT permission_id FROM permissions WHERE permission_id IN ($in)");
            $st->execute($ids);
            $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $unknown = array_diff($ids, $valid);
            if ($unknown) {
                vk_api_error(404, 'permission_not_found', 'No permission was found with id(s): ' . implode(', ', $unknown) . '.');
            }
        }

        $out = [];
        foreach ($raw as $permId => $flags) {
            if (!is_array($flags)) {
                vk_api_error(422, 'invalid_permission_flags', "permissions[$permId] must be an object of booleans.");
            }
            $view   = !empty($flags['view']);
            $create = !empty($flags['create']);
            $edit   = !empty($flags['edit']);
            $delete = !empty($flags['delete']);
            if (($create || $edit || $delete) && !$view) {
                vk_api_error(422, 'view_required', "permissions[$permId]: create/edit/delete require view.");
            }
            if ($view || $create || $edit || $delete) {
                $out[] = ['permission_id' => (int) $permId, 'view' => $view, 'create' => $create, 'edit' => $edit, 'delete' => $delete];
            }
        }
        return $out;
    }
}

// --- System settings --------------------------------------------------------

if (!function_exists('vk_api_settings_get')) {
    /** All system_settings rows as a flat [key => value] map. */
    function vk_api_settings_get(PDO $pdo): array
    {
        $out = [];
        $st = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }
}

if (!function_exists('vk_api_settings_save')) {
    function vk_api_settings_save(PDO $pdo, string $key, $value): void
    {
        $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }
}

if (!function_exists('VK_API_SETTINGS_SECTIONS')) {
    // Mirrors system_settings.php's five save_* branches exactly (minus
    // save_group, which is a passthrough JSON blob handled separately below).
    define('VK_API_SETTINGS_SECTIONS', [
        'general' => ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website', 'currency', 'timezone', 'date_format', 'items_per_page'],
        'email'   => ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'from_email', 'from_name', 'enable_email_notifications'],
        'sms'     => ['sms_gateway_type', 'sms_api_key', 'sms_api_secret', 'sms_sender_id', 'enable_sms_notifications'],
        'security' => ['session_timeout', 'max_login_attempts', 'password_expiry_days', 'require_strong_password', 'enable_2fa', 'enable_audit_log'],
    ]);
}

if (!function_exists('vk_api_settings_shape')) {
    /** The flat key=>value map, grouped into the same sections the web page edits. */
    function vk_api_settings_shape(array $flat): array
    {
        $out = [];
        foreach (VK_API_SETTINGS_SECTIONS as $section => $keys) {
            $out[$section] = [];
            foreach ($keys as $key) {
                $out[$section][$key] = $flat[$key] ?? null;
            }
        }
        $out['group'] = json_decode($flat['group_settings'] ?? '{}', true) ?: [];
        return $out;
    }
}
