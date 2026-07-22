<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * M-Koba reconciliation (Level 2): the imported statement is mirrored row-for-row
 * in `mkoba_statement_rows` and tied out against the ledger — savings + excluded
 * (transfers/openings) + any missing add up to the statement total. These tests
 * lock the pure classification, the mirror wiring, and the view/route.
 */
class MkobaReconciliationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/transaction_import.php';
    }

    public function testContributionTypesAreNotExcluded(): void
    {
        $this->assertSame('', mkoba_exclusion_reason('Member Contribution', '767276015', 5000.0));
        $this->assertSame('', mkoba_exclusion_reason('contribute for other member', '767276015', 5000.0));
        $this->assertSame('', mkoba_exclusion_reason('App contribution on behalf', '767276015', 5000.0));
    }

    public function testTransfersOpeningsAndBlanksAreExcludedWithReason(): void
    {
        $this->assertNotSame('', mkoba_exclusion_reason('Group Transfer', '767276015', 8000000.0));
        $this->assertStringContainsStringIgnoringCase('transfer', mkoba_exclusion_reason('Group Transfer', '1', 1.0));
        $this->assertStringContainsStringIgnoringCase('opening', mkoba_exclusion_reason('Opening an account on cbs', '1', 0.0));
        $this->assertNotSame('', mkoba_exclusion_reason('', '767276015', 5000.0)); // blank type
        $this->assertNotSame('', mkoba_exclusion_reason('Member Contribution', '', 5000.0)); // no phone
        $this->assertNotSame('', mkoba_exclusion_reason('Member Contribution', '767276015', 0.0)); // zero amount
    }

    public function testMirrorRowFlattensAContribution(): void
    {
        $row = mkoba_mirror_row([
            'no' => '1', 'trans_id' => 'ABC', 'receipt' => 'DBS9N7LOXOR',
            'date' => '28/02/2026 23:50', 'member name' => 'CONSESA MUNISHI',
            'member id' => '255767276015.00', 'source' => '255767276015.00',
            'destination' => '60243499376.00', 'amount' => '"5,000.00"', 'trans type' => 'Member Contribution',
        ]);
        $this->assertTrue($row['is_contribution']);
        $this->assertSame('', $row['reason']);
        $this->assertSame('DBS9N7LOXOR', $row['receipt']);
        $this->assertSame('2026-02-28', $row['trans_date']);
        $this->assertSame('255767276015', $row['member_id']); // Excel ".00" dropped, digits kept
        $this->assertSame(5000.0, $row['amount']);
    }

    public function testMirrorRowFlagsAGroupTransferAsExcluded(): void
    {
        $row = mkoba_mirror_row([
            'no' => '9', 'receipt' => 'X', 'date' => '06/02/2026 15:52',
            'member id' => '255653240446.00', 'amount' => '"1,000,000.00"', 'trans type' => 'Group Transfer',
        ]);
        $this->assertFalse($row['is_contribution']);
        $this->assertStringContainsStringIgnoringCase('transfer', $row['reason']);
    }

    public function testMigrationCreatesTableAndPermission(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/create_mkoba_statement_rows_table.php');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `mkoba_statement_rows`', $src);
        $this->assertStringContainsString("'mkoba_reconciliation'", $src);
        $migrate = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString('create_mkoba_statement_rows_table.php', $migrate);
    }

    public function testImportScriptHasMirrorOnlyAndPopulatesMirror(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/import_mkoba_oneoff.php');
        $this->assertStringContainsString('--mirror-only', $src);
        // The mirror routine now lives in the shared include; the CLI calls it.
        $this->assertStringContainsString('includes/mkoba_mirror.php', $src);
        $this->assertStringContainsString('mkoba_populate_mirror(', $src);
        // and the actual INSERT lives in that shared helper
        $this->assertStringContainsString('INSERT INTO mkoba_statement_rows',
            file_get_contents(__DIR__ . '/../../includes/mkoba_mirror.php'));
    }

    public function testViewIsGatedMirrorsColumnsAndTiesOut(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/constant/accounts/mkoba_reconciliation.php');
        $this->assertStringContainsString("requireViewPermission('mkoba_reconciliation')", $src);
        $this->assertStringContainsString('mkoba_statement_rows', $src);
        // mirrors the statement columns + shows the reconciled/tie-out state
        $this->assertStringContainsString('Receipt', $src);
        $this->assertStringContainsString('Member ID', $src);
        $this->assertStringContainsString('reconciled', $src);
    }

    public function testRouteRegistered(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');
        $this->assertStringContainsString("'mkoba_reconciliation' => ACCOUNTS_DIR", $roots);
    }
}
