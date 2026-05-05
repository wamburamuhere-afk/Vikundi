<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset session state before every test
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // -------------------------------------------------------------------------
    // isAdmin()
    // -------------------------------------------------------------------------

    public function test_role_id_1_is_admin(): void
    {
        $_SESSION['role_id'] = 1;
        $this->assertTrue(isAdmin());
    }

    public function test_role_id_12_is_admin(): void
    {
        $_SESSION['role_id'] = 12;
        $this->assertTrue(isAdmin());
    }

    public function test_role_name_admin_is_admin(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(isAdmin());
    }

    public function test_role_name_mwenyekiti_is_admin(): void
    {
        $_SESSION['role'] = 'mwenyekiti';
        $this->assertTrue(isAdmin());
    }

    public function test_role_name_chairman_is_admin(): void
    {
        $_SESSION['role'] = 'chairman';
        $this->assertTrue(isAdmin());
    }

    public function test_role_name_secretary_is_admin(): void
    {
        $_SESSION['role'] = 'secretary';
        $this->assertTrue(isAdmin());
    }

    public function test_role_name_treasurer_is_admin(): void
    {
        $_SESSION['role'] = 'treasurer';
        $this->assertTrue(isAdmin());
    }

    public function test_user_role_field_also_checked(): void
    {
        $_SESSION['user_role'] = 'admin';
        $this->assertTrue(isAdmin());
    }

    public function test_regular_member_is_not_admin(): void
    {
        $_SESSION['role_id'] = 5;
        $_SESSION['role']    = 'member';
        $this->assertFalse(isAdmin());
    }

    public function test_role_id_0_is_not_admin(): void
    {
        $_SESSION['role_id'] = 0;
        $this->assertFalse(isAdmin());
    }

    public function test_empty_session_is_not_admin(): void
    {
        $this->assertFalse(isAdmin());
    }

    public function test_admin_check_is_case_insensitive(): void
    {
        $_SESSION['role'] = 'ADMIN';
        $this->assertTrue(isAdmin());
    }

    // -------------------------------------------------------------------------
    // canView()
    // -------------------------------------------------------------------------

    public function test_admin_can_view_any_page(): void
    {
        $_SESSION['role_id'] = 1;
        $this->assertTrue(canView('customers'));
        $this->assertTrue(canView('loans'));
        $this->assertTrue(canView('nonexistent_page_key'));
    }

    public function test_user_with_view_true_can_view(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        ];
        $this->assertTrue(canView('customers'));
    }

    public function test_user_with_view_false_cannot_view(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ];
        $this->assertFalse(canView('customers'));
    }

    public function test_missing_permission_key_returns_false(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [];
        $this->assertFalse(canView('nonexistent_page'));
    }

    // -------------------------------------------------------------------------
    // canCreate() — blocked when view is denied
    // -------------------------------------------------------------------------

    public function test_cannot_create_if_view_is_false(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => true, 'edit' => false, 'delete' => false],
        ];
        $this->assertFalse(canCreate('customers'));
    }

    public function test_can_create_when_view_and_create_are_true(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
        ];
        $this->assertTrue(canCreate('customers'));
    }

    public function test_cannot_create_when_create_is_false_but_view_is_true(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        ];
        $this->assertFalse(canCreate('customers'));
    }

    public function test_admin_can_always_create(): void
    {
        $_SESSION['role_id'] = 1;
        $this->assertTrue(canCreate('customers'));
        $this->assertTrue(canCreate('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // canEdit() — blocked when view is denied
    // -------------------------------------------------------------------------

    public function test_cannot_edit_if_view_is_false(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => false, 'edit' => true, 'delete' => false],
        ];
        $this->assertFalse(canEdit('customers'));
    }

    public function test_can_edit_when_view_and_edit_are_true(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        ];
        $this->assertTrue(canEdit('customers'));
    }

    // -------------------------------------------------------------------------
    // canDelete() — blocked when view is denied
    // -------------------------------------------------------------------------

    public function test_cannot_delete_if_view_is_false(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => true],
        ];
        $this->assertFalse(canDelete('customers'));
    }

    public function test_can_delete_when_view_and_delete_are_true(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => true],
        ];
        $this->assertTrue(canDelete('customers'));
    }

    // -------------------------------------------------------------------------
    // getPermissionSummary()
    // -------------------------------------------------------------------------

    public function test_permission_summary_no_access(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ];
        $this->assertEquals('No Access', getPermissionSummary('customers'));
    }

    public function test_permission_summary_view_only(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        ];
        $this->assertEquals('View', getPermissionSummary('customers'));
    }

    public function test_permission_summary_view_and_edit(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        ];
        $this->assertEquals('View, Edit', getPermissionSummary('customers'));
    }

    public function test_permission_summary_full_access(): void
    {
        $_SESSION['role_id']    = 5;
        $_SESSION['role']       = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => true],
        ];
        $this->assertEquals('View, Edit, Delete', getPermissionSummary('customers'));
    }

    // -------------------------------------------------------------------------
    // arePermissionsLoaded()
    // -------------------------------------------------------------------------

    public function test_permissions_not_loaded_returns_false(): void
    {
        $this->assertFalse(arePermissionsLoaded());
    }

    public function test_permissions_loaded_returns_true(): void
    {
        $_SESSION['permissions'] = ['customers' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false]];
        $this->assertTrue(arePermissionsLoaded());
    }

    public function test_empty_permissions_array_is_considered_loaded(): void
    {
        $_SESSION['permissions'] = [];
        $this->assertTrue(arePermissionsLoaded());
    }
}
