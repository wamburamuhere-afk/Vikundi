<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Access control on the money-moving expense endpoints. These created/deleted
 * group expenses with NO permission check, so an ordinary member could fabricate
 * or erase expenses (confirmed live). Every one must now require a login, a valid
 * CSRF token, and the appropriate 'expenses' permission (which members lack).
 */
class ExpenseEndpointAccessTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testEveryExpenseEndpointRequiresPermissionAndCsrf(): void
    {
        $endpoints = [
            'api/add_general_expense.php'   => "requirePermissionJson('create', 'expenses')",
            'api/delete_general_expense.php'=> "requirePermissionJson('delete', 'expenses')",
            'api/account/add_expense.php'   => "requirePermissionJson('create', 'expenses')",
            'actions/save_petty_cash.php'   => "requirePermissionJson('create', 'expenses')",
        ];
        foreach ($endpoints as $file => $gate) {
            $src = $this->src($file);
            $this->assertStringContainsString($gate, $src, "$file must gate on the expenses permission");
            $this->assertStringContainsString('require_csrf.php', $src, "$file must require a CSRF token");
        }
    }

    public function testCreateEndpointsAlsoRequireLogin(): void
    {
        // The two that had no auth check at all must now require a login.
        $this->assertStringContainsString('require_auth.php', $this->src('api/add_general_expense.php'));
        $this->assertStringContainsString('require_auth.php', $this->src('api/delete_general_expense.php'));
        $this->assertStringContainsString('require_auth.php', $this->src('actions/save_petty_cash.php'));
    }
}
