<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards audit H3: the previously authenticate-only endpoints now also
 * authorize (permission check, not just login) via requirePermissionJson().
 */
class EndpointAuthorizationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testHelperExistsAndEmits403(): void
    {
        $src = file_get_contents($this->root . '/core/permissions.php');
        $this->assertStringContainsString('function requirePermissionJson', $src);
        $this->assertStringContainsString('http_response_code(403)', $src);
    }

    #[DataProvider('authorizedEndpoints')]
    public function testEndpointAuthorizesOnExpectedKey(string $rel, string $key): void
    {
        $full = $this->root . '/' . $rel;
        $this->assertFileExists($full);
        $this->assertMatchesRegularExpression(
            "/requirePermissionJson\([^)]*'" . preg_quote($key, '/') . "'/",
            file_get_contents($full),
            "$rel must authorize on '$key' (audit H3)"
        );
    }

    public static function authorizedEndpoints(): array
    {
        return [
            ['actions/delete_death_expense.php', 'death_expenses'],
            ['actions/process_death_expense.php', 'death_expenses'],
            ['actions/update_contribution.php', 'manage_contributions'],
            ['actions/delete_petty_cash.php', 'petty_cash'],
            ['api/account/save_account.php', 'chart_of_accounts'],
            ['api/account/delete_account.php', 'chart_of_accounts'],
            ['api/account/save_category.php', 'chart_of_accounts'],
            ['api/account/delete_account_category.php', 'chart_of_accounts'],
            ['api/account/create_reconciliation.php', 'bank_reconciliation'],
            ['api/account/delete_reconciliation.php', 'bank_reconciliation'],
        ];
    }
}
