<?php
/**
 * includes/roles.php — one definition of "is this person leadership?".
 *
 * WHY THIS FILE EXISTS. app/dashboard.php carried its own hard-coded list:
 *
 *     $viongozi_roles = ['admin','secretary','katibu','chairman','mwenyekiti',
 *                        'mhazini','treasurer'];
 *
 * That list has 'chairman' and 'mwenyekiti' but NOT 'chairperson' — and
 * 'Chairperson' is exactly the role name database/seed_vicoba_roles.php creates.
 * So the head of the group was served the ordinary member dashboard: no pending
 * approvals strip, no group-wide contribution counts, no expense or budget
 * chips. Confirmed against the live site before this was changed.
 *
 * It is an oversight rather than policy: core/permissions.php::isAdmin() already
 * treats 'chairperson' as an admin, so every other screen in the app disagreed
 * with the dashboard.
 *
 * Kept deliberately free of $_SESSION and $pdo so the mobile API can share it.
 * The API authenticates with a token and has no session, and a definition that
 * only works in one of the two transports is how web and mobile drift apart.
 */

if (!function_exists('vk_role_is_admin_name')) {
    /**
     * Role names with full administrative access. Mirrors the list in
     * core/permissions.php::isAdmin() — the Chairperson (Mwenyekiti) leads the
     * group and is an admin; Secretary and Treasurer are not.
     *
     * @return string[]
     */
    function vk_role_admin_names(): array
    {
        return ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman'];
    }

    /**
     * Role names that are leadership but not full admins: the officers who run
     * the group's day-to-day operations.
     *
     * @return string[]
     */
    function vk_role_officer_names(): array
    {
        return [
            'secretary', 'katibu',
            'treasurer', 'mweka hazina', 'mweka-hazina', 'mhazini', 'mhasibu',
        ];
    }

    /** Role ids with full administrative access. 1 Admin, 2 Chairperson, 12 legacy. */
    function vk_role_admin_ids(): array
    {
        return [1, 2, 12];
    }

    function vk_role_is_admin_name(?string $roleName): bool
    {
        if ($roleName === null) {
            return false;
        }
        return in_array(strtolower(trim($roleName)), vk_role_admin_names(), true);
    }
}

if (!function_exists('vk_role_is_admin')) {
    /**
     * Full administrative access, by id or by name.
     *
     * Both are checked because neither is reliable alone: role ids differ
     * between installs (the live system's Member role is 15, a fresh one gets
     * 13), while role names are free text an admin can edit in Settings.
     */
    function vk_role_is_admin(?int $roleId, ?string $roleName = null): bool
    {
        if ($roleId !== null && in_array($roleId, vk_role_admin_ids(), true)) {
            return true;
        }
        return vk_role_is_admin_name($roleName);
    }
}

if (!function_exists('vk_role_is_leadership')) {
    /**
     * Leadership = full admins (Admin, Chairperson) plus the officers
     * (Secretary, Treasurer). These are the people the dashboard shows
     * group-wide figures and pending-approval counts to.
     *
     * A plain Member is not leadership and sees only their own position.
     */
    function vk_role_is_leadership(?int $roleId, ?string $roleName = null): bool
    {
        if (vk_role_is_admin($roleId, $roleName)) {
            return true;
        }
        // Secretary (3) and Treasurer (4) by id, as created by seed_vicoba_roles.php.
        if ($roleId !== null && in_array($roleId, [3, 4], true)) {
            return true;
        }
        if ($roleName === null) {
            return false;
        }
        return in_array(strtolower(trim($roleName)), vk_role_officer_names(), true);
    }
}
