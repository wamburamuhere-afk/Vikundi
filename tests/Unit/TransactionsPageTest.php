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
        $this->assertStringContainsString('$allowed_types    = fb_types();', $h);
        $this->assertStringContainsString('in_array($_POST[\'contribution_type\'] ?? \'\', $allowed_types, true)', $h);
        $this->assertStringContainsString("['M-Koba', 'Bank', 'Cash', 'Mobile Money']", $h);
        // New columns are written.
        $this->assertStringContainsString('receipt_number', $h);
        $this->assertStringContainsString('account', $h);
        // Date is validated to Y-m-d rather than blindly trusted.
        $this->assertStringContainsString("createFromFormat('Y-m-d'", $h);
    }

    public function testAnImportEndsOnTheResultNotAnErrorPage(): void
    {
        // Found in the browser on a live server: the handler closed the uploaded
        // file twice. The second fclose() throws a TypeError in PHP 8, after the
        // rows are committed, so the import worked and the user saw HTTP 500.
        $h = $this->src('actions/import_contributions.php');
        $this->assertSame(1, substr_count($h, 'fclose($handle);'), 'the file is closed once, where it is read');

        // The one close sits inside the CSV read, before any row is processed.
        $this->assertLessThan(strpos($h, '$pdo->beginTransaction()'), strpos($h, 'fclose($handle);'));

        // Whatever happens, the handler ends by redirecting with the outcome.
        $tail = substr($h, strrpos($h, "\$_SESSION['import_response'] = \$response;"));
        $this->assertStringContainsString("header('Location: ' . getUrl('transactions'));", $tail);
    }
}
