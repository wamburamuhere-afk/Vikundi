<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Default role-permission policy (includes/role_grants.php) used by the VICOBA
 * role seeder. Grants are keyed by PURPOSE ('admin' | 'operational' | 'view').
 * Proves the Member ('view') role is view-only on operational pages and hidden
 * from admin/write-action pages — the regression for "members could only see the
 * dashboard" and "members must not create/edit".
 */
class RoleGrantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/role_grants.php';
    }

    public function test_member_can_view_operational_pages_but_not_mutate(): void
    {
        foreach (['dashboard', 'customers', 'customer_details', 'expenses', 'transactions',
                  'loans', 'loan_details', 'repayment_report', 'balance_sheet'] as $key) {
            $g = vk_role_grants('view', $key);
            $this->assertSame([1, 0, 0, 0, 0, 0], $g, "Member should view-only '$key'");
        }
    }

    public function test_member_is_hidden_from_admin_and_action_pages(): void
    {
        foreach (['users', 'add_user', 'edit_user', 'user_roles', 'system_settings',
                  'policy_management', 'customer_import', 'campaign_management',
                  'approve_loan', 'disburse_loan', 'reject_loan', 'payment_processing',
                  'edit_customer', 'edit_loan', 'loan_application'] as $key) {
            $this->assertNull(vk_role_grants('view', $key), "Member must NOT see '$key'");
        }
    }

    public function test_member_never_gets_create_edit_or_delete_on_any_page(): void
    {
        // Whatever page is visible to a Member, the write flags must all be 0.
        foreach (['dashboard', 'loans', 'payments', 'income_statement', 'guarantors'] as $key) {
            $g = vk_role_grants('view', $key);
            if ($g === null) { continue; }
            $this->assertSame(1, $g[0], "view flag for '$key'");
            $this->assertSame([0, 0, 0], [$g[1], $g[2], $g[3]], "no create/edit/delete on '$key'");
        }
    }

    public function test_admin_purpose_gets_everything(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('admin', 'system_settings'));
        $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('admin', 'loans'));
    }

    public function test_operational_purpose_has_crud_but_not_admin_pages(): void
    {
        $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('operational', 'loans'));
        $this->assertNull(vk_role_grants('operational', 'users'));
        $this->assertNull(vk_role_grants('operational', 'system_settings'));
    }

    public function test_unknown_purpose_gets_nothing(): void
    {
        $this->assertNull(vk_role_grants('nonsense', 'dashboard'));
    }
}
