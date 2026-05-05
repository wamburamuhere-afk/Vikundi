<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Feature tests for authentication and session behaviour.
 *
 * These tests do not require a live database — they test session logic
 * and the stub behaviour of redirectTo() defined in tests/bootstrap.php.
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_is_not_authenticated_without_user_id_in_session(): void
    {
        unset($_SESSION['user_id']);
        $this->assertFalse(isAuthenticated());
    }

    public function test_is_authenticated_when_user_id_is_set(): void
    {
        $_SESSION['user_id'] = 42;
        $this->assertTrue(isAuthenticated());
    }

    public function test_redirect_throws_runtime_exception_in_test_context(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/login/');
        redirectTo('login');
    }

    public function test_redirect_message_contains_target_page(): void
    {
        try {
            redirectTo('dashboard');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dashboard', $e->getMessage());
            return;
        }
        $this->fail('Expected RuntimeException was not thrown');
    }

    public function test_require_view_permission_redirects_unauthenticated_user(): void
    {
        // No user_id in session = unauthenticated
        $_SESSION = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/login/');

        requireViewPermission('customers');
    }

    public function test_require_view_permission_redirects_user_without_permission(): void
    {
        $_SESSION['user_id']     = 1;
        $_SESSION['role_id']     = 5;
        $_SESSION['role']        = 'member';
        $_SESSION['permissions'] = [
            'customers' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unauthorized/');

        requireViewPermission('customers');
    }

    public function test_require_view_permission_passes_for_admin(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role_id'] = 1; // Admin role

        // Admin should NOT trigger a redirect — no exception should be thrown
        $this->expectNotToPerformAssertions();
        requireViewPermission('customers');
    }
}
