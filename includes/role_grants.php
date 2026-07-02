<?php
/**
 * includes/role_grants.php
 * ------------------------
 * Pure, testable default-permission rules for the VICOBA role seeder
 * (database/seed_vicoba_roles.php). The seeder resolves each role by name, maps
 * it to a PURPOSE, then asks vk_role_grants() what to grant for every page_key.
 * Keeping this here (not inline in the seeder) lets the policy be unit-tested.
 *
 * Purposes:
 *   'admin'       — Chairperson: everything.
 *   'operational' — Secretary/Treasurer: full CRUD except user/role/settings.
 *   'view'        — Member: VIEW-ONLY on most pages; admin/write-action pages hidden.
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
            // fines management (members see only their own via my_fines)
            'manage_fines',
            // voting management (members vote via the 'voting' page, not this one)
            'manage_voting',
        ];
    }
}

if (!function_exists('vk_role_grants')) {
    /**
     * Default grants for a role PURPOSE on a single page, or null to grant nothing.
     * Returns [can_view, can_create, can_edit, can_delete, can_review, can_approve].
     */
    function vk_role_grants(string $purpose, string $key): ?array
    {
        if ($purpose === 'admin') {
            return [1, 1, 1, 1, 1, 1]; // Chairperson: everything
        }
        if ($purpose === 'operational') {
            // Secretary / Treasurer: full operational CRUD, but not admin/settings.
            return in_array($key, vk_admin_only_keys(), true) ? null : [1, 1, 1, 1, 1, 1];
        }
        if ($purpose === 'view') {
            // Member: view-only on everything except the hidden admin/action pages.
            return in_array($key, vk_member_hidden_keys(), true) ? null : [1, 0, 0, 0, 0, 0];
        }
        return null;
    }
}
