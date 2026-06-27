<?php
/**
 * includes/role_grants.php
 * ------------------------
 * Pure, testable default-permission rules for the four VICOBA system roles.
 * No DB / no globals — the seeder (database/seed_vicoba_roles.php) walks every
 * page_key and asks vk_role_grants() what to grant. Keeping this here (instead
 * of inline in the seeder) lets the policy be unit-tested.
 *
 * Roles:
 *   2  Chairperson — everything (admin).
 *   3  Secretary   — full CRUD on operational data, NOT user/role/settings.
 *   4  Treasurer   — full CRUD on operational data, NOT user/role/settings.
 *   13 Member      — VIEW-ONLY on most pages; admin/management & write-action
 *                    pages are hidden entirely (no row -> default-deny).
 */

if (!function_exists('vk_admin_only_keys')) {
    /** Pages only the Chairperson/Admin may manage (user/role mgmt + settings). */
    function vk_admin_only_keys(): array
    {
        return ['users', 'user_roles', 'add_user', 'edit_user', 'system_settings', 'policy_management'];
    }
}

if (!function_exists('vk_member_hidden_keys')) {
    /**
     * Pages an ordinary Member may NOT even view. Everything NOT in this list is
     * granted view-only. Covers: user/role/settings management, comms & AI admin,
     * bulk import, create/registration flows, loan/payment write-actions, and the
     * dedicated edit pages.
     */
    function vk_member_hidden_keys(): array
    {
        return [
            // user / role / settings management
            'users', 'add_user', 'edit_user', 'user_roles',
            'system_settings', 'policy_management', 'ai_settings', 'notification_settings',
            // AI tooling
            'ai_assistant', 'ai_ask_data',
            // communications config / sending
            'campaign_management', 'email_templates', 'sms_templates', 'sms_alerts',
            'document_templates', 'document_workflow',
            // bulk data import
            'customer_import',
            // create / registration / lead flows
            'customer_registration', 'guarantor_registration', 'loan_application', 'lead_generation',
            // loan & payment write-actions
            'approve_loan', 'reject_loan', 'disburse_loan', 'loan_processes',
            'payment_processing', 'forfeit_collateral', 'release_collateral',
            // dedicated edit pages
            'edit_customer', 'edit_guarantor', 'edit_loan',
        ];
    }
}

if (!function_exists('vk_role_grants')) {
    /**
     * Default grants for a role on a single page, or null to grant nothing.
     * Returns [can_view, can_create, can_edit, can_delete, can_review, can_approve].
     */
    function vk_role_grants(int $roleId, string $key): ?array
    {
        if ($roleId === 2) {
            return [1, 1, 1, 1, 1, 1]; // Chairperson: everything
        }
        if ($roleId === 3 || $roleId === 4) {
            // Secretary / Treasurer: full operational CRUD, but not admin/settings.
            return in_array($key, vk_admin_only_keys(), true) ? null : [1, 1, 1, 1, 1, 1];
        }
        if ($roleId === 13) {
            // Member: view-only on everything except the hidden admin/action pages.
            return in_array($key, vk_member_hidden_keys(), true) ? null : [1, 0, 0, 0, 0, 0];
        }
        return null;
    }
}
