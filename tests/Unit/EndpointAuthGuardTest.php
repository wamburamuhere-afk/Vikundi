<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards audit B3: the central authentication gate exists and is included by the
 * previously-unauthenticated mutating endpoints, so they can never run for an
 * anonymous caller.
 */
class EndpointAuthGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testGuardEmits401AndStops(): void
    {
        $src = file_get_contents($this->root . '/includes/require_auth.php');
        $this->assertStringContainsString('http_response_code(401)', $src);
        $this->assertStringContainsString("\$_SESSION['user_id']", $src);
        $this->assertStringContainsString('exit', $src);
    }

    #[DataProvider('guardedEndpoints')]
    public function testEndpointIncludesGuard(string $relPath): void
    {
        $full = $this->root . '/' . $relPath;
        $this->assertFileExists($full);
        $this->assertStringContainsString(
            'require_auth.php',
            file_get_contents($full),
            "$relPath must include the central auth guard (audit B3)"
        );
    }

    public static function guardedEndpoints(): array
    {
        $files = [
            'actions/delete_death_expense.php',
            'actions/update_contribution.php',
            'actions/process_death_expense.php',
            'actions/delete_petty_cash.php',
            'api/account/save_account.php',
            'api/account/delete_account.php',
            'api/account/save_category.php',
            'api/account/delete_account_category.php',
            'api/account/create_reconciliation.php',
            'api/account/delete_reconciliation.php',
        ];
        return array_map(static fn ($p) => [$p], $files);
    }
}
