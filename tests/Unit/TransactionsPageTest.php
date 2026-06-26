<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Finance > Transactions recording hub (PR-1): the new page, its enriched record
 * form, the route + menu, the schema columns, and the handler that persists them.
 */
class TransactionsPageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function src(string $rel): string
    {
        return file_get_contents(self::ROOT . $rel);
    }

    public function testRouteAndMenuRegistered(): void
    {
        $this->assertStringContainsString("'transactions'", $this->src('roots.php'));
        $this->assertStringContainsString("getUrl('transactions')", $this->src('header.php'));
    }

    public function testMigrationRegisteredAndAddsColumns(): void
    {
        $this->assertStringContainsString('add_transaction_fields.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/add_transaction_fields.php');
        $this->assertStringContainsString("'receipt_number'", $mig);
        $this->assertStringContainsString("'account'", $mig);
    }

    public function testFormHasAllFieldsAndPostsToHandler(): void
    {
        $page = $this->src('app/bms/customer/transactions.php');
        foreach (['member_id', 'receipt_number', 'contribution_date', 'account',
                  'contribution_type', 'amount', 'description', 'evidence'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $page, "form must collect $field");
        }
        $this->assertStringContainsString('actions/process_contribution', $page, 'form posts to the handler');
        $this->assertStringContainsString('csrf_field()', $page, 'CSRF token present');
        // Both bulk paths surfaced on the recording hub.
        $this->assertStringContainsString('uploadReportModal', $page);
        $this->assertStringContainsString('uploadMKobaModal', $page);
    }

    public function testPageGatedByPermission(): void
    {
        $page = $this->src('app/bms/customer/transactions.php');
        $this->assertStringContainsString("requireViewPermission('manage_contributions')", $page);
        $this->assertStringContainsString("canCreate('manage_contributions')", $page);
    }

    public function testHandlerValidatesAndPersistsNewFields(): void
    {
        $h = $this->src('actions/process_contribution.php');
        // Allowed sets are enforced (no arbitrary type/account written).
        $this->assertStringContainsString("['entrance', 'monthly', 'agm', 'fine', 'other']", $h);
        $this->assertStringContainsString("['M-Koba', 'Bank', 'Cash', 'Mobile Money']", $h);
        // New columns are written.
        $this->assertStringContainsString('receipt_number', $h);
        $this->assertStringContainsString('account', $h);
        // Date is validated to Y-m-d rather than blindly trusted.
        $this->assertStringContainsString("createFromFormat('Y-m-d'", $h);
    }
}
