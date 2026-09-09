<?php
/**
 * includes/api_profile.php — shared rules for Module 18 (Profile).
 *
 * SCOPE, corrected against the actual code before anything was built (see
 * todo.md Module 18):
 *
 *   Two web pages both claim to be "profile": app/constant/profile/profile.php
 *   and app/constant/profile/my_settings.php. They are not the same thing.
 *
 *   - profile.php lets Admin/Chairperson/Secretary view (and, if they also
 *     hold `canEdit('customers')`, edit) ANY user's full member record —
 *     spouse, parents, guarantor, NIDA, the works. Its own comment is explicit:
 *     "Ordinary view-only Members cannot edit any profile — including their
 *     own." It is not self-service, and its field set already belongs to
 *     Module 3 (Members) — that module's own api/v1/members_update.php says
 *     so directly: "Self profile editing is a separate module (18, Profile)
 *     with its own narrower field set, and folding it in here would hand a
 *     member write access to the whole customers row."
 *
 *   - my_settings.php is the genuine self-service page: no leadership gate at
 *     all beyond being logged in, every query scoped to $_SESSION['user_id'],
 *     three tabs — Profile (name/email/phone/avatar), Security (password),
 *     Preferences (theme/language/timezone/date format/notifications).
 *
 *   This module mirrors my_settings.php, not profile.php's rich edit form.
 *   "Own profile" in the plan means exactly that — every endpoint here is
 *   self-only, no ?id override anywhere, matching my_settings.php exactly and
 *   avoiding rebuilding Module 3's member-editing surface a second time.
 *
 *   Password change gets its OWN endpoint (POST /profile/password) rather
 *   than being folded into PUT /profile/settings — current_password/
 *   new_password is a different shape of request than a settings object, and
 *   forcing a client to resend the whole settings block just to change a
 *   password invites mistakes. Not in the original plan; added because the
 *   underlying feature (my_settings.php's Security tab) needs it, same
 *   reasoning every prior module has used for a necessary action the plan
 *   text didn't spell out.
 *
 *   Avatar upload is its own endpoint (POST /profile/avatar) for the same
 *   reason group-settings/logo is its own endpoint, not a field on PUT
 *   /group-settings: PUT /profile is JSON and carries no file. Built on
 *   includes/api_upload.php's vk_api_store_upload() (extension whitelist +
 *   byte-sniffing + random filename) rather than my_settings.php's own
 *   extension-only check — the established, more secure convention already
 *   used by every other upload endpoint in this API.
 *
 *   Password policy is includes/registration_validator.php's
 *   reg_password_errors() (8+ chars, a letter, a number) — the same rule
 *   add_user.php/edit_user.php/api/v1/users.php already enforce — not
 *   my_settings.php's own weaker "6 characters" check. No reason for a
 *   self-service password change to be held to a lower bar than an
 *   admin-created account.
 *
 *   A CSRF gap was found and fixed in my_settings.php itself while tracing
 *   this module (none of its three POST handlers checked a token, unlike
 *   profile.php's own save, one directory over) — see that file's own
 *   comment. Irrelevant to this API, which is token- not cookie-authenticated,
 *   but fixed in the same change since it was found while building this exact
 *   module.
 *
 *   avatar_url does NOT use helpers.php's vk_avatar_url() — that points at
 *   api/get_upload.php, gated by includes/require_auth.php, a SESSION check.
 *   A token-authenticated mobile client has no session and would get a 401
 *   fetching its own avatar — confirmed live before api/v1/avatar.php (this
 *   module's own token-authed equivalent) existed. See that file's own header.
 */

require_once __DIR__ . '/api_auth.php';         // vk_api_can(), vk_api_is_admin()
require_once __DIR__ . '/registration_validator.php'; // reg_password_errors()

if (!function_exists('vk_api_avatar_url')) {
    /**
     * An absolute URL for an avatar filename, pointing at api/v1/avatar.php
     * (token-authed), never api/get_upload.php (session-authed). Mirrors
     * includes/api_group_settings.php's vk_group_settings_logo_url() for how
     * to build an absolute URL that resolves under both a subdirectory
     * install and a document-root install.
     */
    function vk_api_avatar_url(?string $stored, ?array $server = null): string
    {
        $name = trim((string) $stored);
        if ($name === '') {
            return '';
        }
        $name = basename($name);

        $server = $server ?? $_SERVER;
        $host = trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        if ($host === '') {
            return '/api/v1/avatar?name=' . rawurlencode($name);
        }

        require_once __DIR__ . '/env.php';
        $scheme = vikundi_is_https($server) ? 'https' : 'http';

        $docRoot  = rtrim(str_replace('\\', '/', (string) ($server['DOCUMENT_ROOT'] ?? '')), '/');
        $projRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

        $base = '';
        if ($docRoot !== '' && $projRoot !== $docRoot && str_starts_with($projRoot, $docRoot . '/')) {
            $base = '/' . trim(substr($projRoot, strlen($docRoot)), '/');
        }

        return $scheme . '://' . $host . $base . '/api/v1/avatar?name=' . rawurlencode($name);
    }
}

if (!function_exists('vk_api_profile_row')) {
    function vk_api_profile_row(array $r): array
    {
        return [
            'user_id'     => (int) $r['user_id'],
            'username'    => (string) $r['username'],
            'first_name'  => (string) ($r['first_name'] ?? ''),
            'middle_name' => (string) ($r['middle_name'] ?? ''),
            'last_name'   => (string) ($r['last_name'] ?? ''),
            'email'       => (string) ($r['email'] ?? ''),
            'phone'       => (string) ($r['phone'] ?? ''),
            'avatar_url'  => vk_api_avatar_url($r['avatar'] ?? null) ?: null,
            'role_id'     => $r['role_id'] !== null ? (int) $r['role_id'] : null,
            'role_name'   => $r['role_name'] ?? null,
            'member_id'   => isset($r['customer_id']) && $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'status'      => (string) ($r['status'] ?? 'pending'),
            'created_at'  => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'last_login'  => !empty($r['last_login']) ? date(DATE_ATOM, strtotime((string) $r['last_login'])) : null,
            // Never: password, password hash, or anything derived from it.
        ];
    }
}

if (!function_exists('vk_api_profile_settings_languages')) {
    function vk_api_profile_settings_languages(): array
    {
        return ['en', 'sw'];
    }
}

if (!function_exists('vk_api_profile_settings_themes')) {
    function vk_api_profile_settings_themes(): array
    {
        return ['light', 'dark'];
    }
}

if (!function_exists('vk_api_profile_settings_timezones')) {
    /** Mirrors my_settings.php's own <select> options exactly. */
    function vk_api_profile_settings_timezones(): array
    {
        return ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'UTC'];
    }
}

if (!function_exists('vk_api_profile_settings_date_formats')) {
    /** Mirrors my_settings.php's own <select> options exactly. */
    function vk_api_profile_settings_date_formats(): array
    {
        return ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY/MM/DD', 'YYYY-MM-DD', 'DD Mon YYYY'];
    }
}

if (!function_exists('vk_api_profile_validate_password')) {
    /**
     * The same policy add_user.php/edit_user.php/api/v1/users.php already
     * enforce (8+ chars, a letter, a number) — not my_settings.php's own
     * weaker "6 characters" rule. A self-service password change should not
     * be held to a lower bar than an admin-created account.
     */
    function vk_api_profile_validate_password(string $password, string $lang = 'en'): void
    {
        $errors = reg_password_errors($password, $lang);
        if ($errors) {
            vk_api_error(422, 'weak_password', implode(' ', $errors));
        }
    }
}

if (!function_exists('vk_api_profile_settings_row')) {
    function vk_api_profile_settings_row(array $userRow): array
    {
        $prefs = json_decode((string) ($userRow['preferences'] ?? '{}'), true) ?: [];
        $notif = json_decode((string) ($userRow['notification_preferences'] ?? '{}'), true) ?: [];

        return [
            'language'             => (string) ($userRow['preferred_language'] ?? 'en'),
            'theme'                => (string) ($prefs['theme'] ?? 'light'),
            'timezone'             => (string) ($prefs['timezone'] ?? 'Africa/Dar_es_Salaam'),
            'date_format'          => (string) ($prefs['date_format'] ?? 'DD/MM/YYYY'),
            'email_notifications'  => (bool) ($notif['email'] ?? true),
            'sms_notifications'    => (bool) ($notif['sms'] ?? true),
        ];
    }
}
