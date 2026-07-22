<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two follow-ups the boss asked for:
 *  1. Excel mangles long M-Koba TRANS_IDs into scientific notation ("3.83E+15");
 *     recover a real reference from the receipt on import and repair stored rows.
 *  2. The Transactions "Statement" (print) and "Excel" outputs must match the new
 *     M-Koba columns exactly — not the old contributions layout.
 */
class MkobaTransIdAndStatementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/transaction_import.php';
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testScientificNotationTransIdFallsBackToReceipt(): void
    {
        $this->assertSame('DBS6N7JEJ36', mkoba_repair_trans_id('3.83E+15', 'DBS6N7JEJ36'));
        $this->assertSame('DBS9N7LOXOR', mkoba_repair_trans_id('3.7986E+15', 'DBS9N7LOXOR'));
    }

    public function testCleanAndCompositeTransIdsAreKept(): void
    {
        $this->assertSame('DBS3N7LN11R', mkoba_repair_trans_id('DBS3N7LN11R', 'DBS3N7LN11R'));
        // a composite id (number_phone) is not scientific notation — keep it
        $this->assertSame('3820502806778077_0783459353',
            mkoba_repair_trans_id('3820502806778077_0783459353', 'DBS8N6Q4WWA'));
    }

    public function testCorruptedButNoReceiptIsLeftAsIs(): void
    {
        // nothing to fall back to → don't destroy the (already broken) value
        $this->assertSame('3.83E+15', mkoba_repair_trans_id('3.83E+15', ''));
    }

    public function testParsersApplyTheRepair(): void
    {
        $src = $this->src('includes/transaction_import.php');
        $this->assertSame(2, substr_count($src, 'mkoba_repair_trans_id((string) ($assoc[\'trans_id\']'));
    }

    public function testRepairMigrationRegistered(): void
    {
        $mig = $this->src('database/repair_mkoba_scientific_trans_ids.php');
        $this->assertStringContainsString('mkoba_trans_id', $mig);
        $this->assertStringContainsString('REGEXP', $mig);
        $this->assertStringContainsString('create_mkoba_statement_rows_table.php', $this->src('database/migrate.php'));
        $this->assertStringContainsString('repair_mkoba_scientific_trans_ids.php', $this->src('database/migrate.php'));
    }

    public function testTransactionsButtonsUseTheMkobaOutputs(): void
    {
        $page = $this->src('app/bms/customer/transactions.php');
        $this->assertStringContainsString('api/export_contributions_statement_mkoba', $page); // Excel
        $this->assertStringContainsString("+ '&layout=mkoba'", $page);                          // print
        $this->assertStringNotContainsString('getUrl("api/export_contributions_statement")', $page); // not the old one
    }

    public function testPrintPageHasMkobaLayout(): void
    {
        $stmt = $this->src('app/bms/customer/contribution_statement.php');
        $this->assertStringContainsString("=== 'mkoba')", $stmt);
        $this->assertStringContainsString('vk_mkoba_statement_columns()', $stmt);
        $this->assertStringContainsString('vk_mkoba_statement_row($r', $stmt);
    }

    public function testExportRoutesRegistered(): void
    {
        $roots = $this->src('roots.php');
        $this->assertStringContainsString("'api/export_contributions_statement_mkoba' => API_DIR", $roots);
    }
}
