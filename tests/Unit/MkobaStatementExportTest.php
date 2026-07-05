<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * M-Koba-format reconciliation export of the contribution statement. Pure tests
 * cover the exact column set and the row mapper (imported row matches fully;
 * hand-recorded row falls back and leaves source/destination/trans_id blank;
 * amount/date/name formatting). Source-guards pin the endpoint (same gate + BOM,
 * no total footer) and the second export button on the contributions page.
 */
class MkobaStatementExportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_statement.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure: column set ---------------------------------------------------

    public function testColumnsMatchMkobaExactly(): void
    {
        $this->assertSame(
            ['NO', 'TRANS_ID', 'RECEIPT', 'DATE', 'MEMBER NAME',
             'MEMBER ID', 'SOURCE', 'DESTINATION', 'AMOUNT', 'TRANS TYPE'],
            vk_mkoba_statement_columns()
        );
    }

    // --- pure: row mapper ---------------------------------------------------

    public function testImportedRowMatchesFully(): void
    {
        // A row that came from an M-Koba upload carries every original field.
        $row = [
            'contribution_date' => '2026-02-28',
            'amount'            => 5000,
            'receipt_number'    => 'INTERNAL-1',
            'contribution_type' => 'monthly',
            'member_name'       => 'Jane Internal',
            'phone'             => '0700000000',
            'mkoba_trans_id'    => 'DBS3N7LN11R',
            'mkoba_receipt'     => 'DBS9N7LOXOR',
            'mkoba_member_name' => 'CONSESA MUNISHI',
            'mkoba_member_id_str' => '255767276015.00',
            'mkoba_source'      => '255767276015.00',
            'mkoba_destination' => '60243499376.00',
            'mkoba_trans_type'  => 'Member Contribution',
        ];
        $out = vk_mkoba_statement_row($row, 1);

        $this->assertSame('1', $out['NO']);
        $this->assertSame('DBS3N7LN11R', $out['TRANS_ID']);
        $this->assertSame('DBS9N7LOXOR', $out['RECEIPT']);       // M-Koba receipt wins over ours
        $this->assertSame('28/02/2026', $out['DATE']);           // dd/mm/yyyy
        $this->assertSame('CONSESA MUNISHI', $out['MEMBER NAME']); // original M-Koba name, already caps
        $this->assertSame('255767276015.00', $out['MEMBER ID']);
        $this->assertSame('255767276015.00', $out['SOURCE']);
        $this->assertSame('60243499376.00', $out['DESTINATION']);
        $this->assertSame('5,000.00', $out['AMOUNT']);           // thousands + 2 decimals
        $this->assertSame('Member Contribution', $out['TRANS TYPE']);
    }

    public function testManualRowFallsBackAndLeavesMkobaOnlyColumnsBlank(): void
    {
        // A hand-recorded row has no mkoba_* values: fall back to our fields and
        // leave the columns M-Koba had but we never did.
        $row = [
            'contribution_date' => '2026-03-05',
            'amount'            => 20000,
            'receipt_number'    => 'RCP-99',
            'contribution_type' => 'entrance',
            'member_name'       => 'John doe',
            'phone'             => '0788112233',
        ];
        $out = vk_mkoba_statement_row($row, 7);

        $this->assertSame('7', $out['NO']);                 // running counter
        $this->assertSame('', $out['TRANS_ID']);            // never had it
        $this->assertSame('RCP-99', $out['RECEIPT']);       // our receipt
        $this->assertSame('05/03/2026', $out['DATE']);
        $this->assertSame('JOHN DOE', $out['MEMBER NAME']); // upper-cased
        $this->assertSame('0788112233', $out['MEMBER ID']); // our phone
        $this->assertSame('', $out['SOURCE']);
        $this->assertSame('', $out['DESTINATION']);
        $this->assertSame('20,000.00', $out['AMOUNT']);
        $this->assertSame('Entrance Fee', $out['TRANS TYPE']); // our type -> friendly label
    }

    public function testAmountAndMissingFieldsAreSafe(): void
    {
        $out = vk_mkoba_statement_row(['amount' => 0], 3);
        $this->assertSame('0.00', $out['AMOUNT']);
        $this->assertSame('', $out['MEMBER NAME']);
        $this->assertSame('', $out['DATE']);
        $this->assertSame('', $out['TRANS TYPE']);
    }

    // --- wiring (source guards) --------------------------------------------

    public function testEndpointGatedBomAndNoTotalFooter(): void
    {
        $exp = $this->src('api/export_contributions_statement_mkoba.php');
        $this->assertStringContainsString('vk_mkoba_statement_columns', $exp);
        $this->assertStringContainsString('vk_mkoba_statement_row', $exp);
        $this->assertStringContainsString("canView('manage_contributions')", $exp); // leadership-gated
        $this->assertStringContainsString('\xEF\xBB\xBF', $exp);                     // Excel BOM
        $this->assertStringContainsString('mkoba_trans_id', $exp);                   // reads the mirror columns
        $this->assertStringNotContainsString('TOTAL', $exp);                         // no footer -> diff-clean
    }

    public function testContributionsPageHasSecondExportButton(): void
    {
        $p = $this->src('app/bms/customer/manage_contributions.php');
        $this->assertStringContainsString('exportStatementMkoba', $p);
        $this->assertStringContainsString('export_contributions_statement_mkoba', $p);
    }
}
