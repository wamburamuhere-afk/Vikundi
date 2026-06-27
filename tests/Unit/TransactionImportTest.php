<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Bulk transaction import parsing (PR-2): the pure helpers in
 * includes/transaction_import.php that normalise M-Koba and our-template rows.
 * Uses real M-Koba values (Excel ".00" phones, "5,000.00" amounts, dd/mm/yyyy).
 */
class TransactionImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/transaction_import.php';
    }

    public function testNormalizePhoneHandlesExcelAndPrefix(): void
    {
        $this->assertSame('767276015', mkoba_normalize_phone('255767276015.00')); // M-Koba Excel form
        $this->assertSame('712345678', mkoba_normalize_phone('0712345678'));
        $this->assertSame('712345678', mkoba_normalize_phone('255712345678'));
        $this->assertSame('712345678', mkoba_normalize_phone('+255 712 345 678'));
        $this->assertSame('', mkoba_normalize_phone(''));
    }

    public function testParseAmountStripsCommas(): void
    {
        $this->assertSame(5000.0, mkoba_parse_amount('"5,000.00"'));
        $this->assertSame(5000.0, mkoba_parse_amount('5,000.00'));
        $this->assertSame(285000.0, mkoba_parse_amount('285,000.00'));
        $this->assertSame(0.0, mkoba_parse_amount('0'));
    }

    public function testParseDate(): void
    {
        $this->assertSame('2026-02-28', mkoba_parse_date('28/02/2026 23:50'));
        $this->assertSame('2026-02-28', mkoba_parse_date('28/02/2026'));
        $this->assertNull(mkoba_parse_date(''));
        $this->assertNull(mkoba_parse_date('not a date'));
    }

    public function testIsContributionSkipsNonContributions(): void
    {
        $this->assertTrue(mkoba_is_contribution('Member Contribution'));
        $this->assertTrue(mkoba_is_contribution('App contribution'));
        $this->assertTrue(mkoba_is_contribution('contribute for other member'));
        $this->assertTrue(mkoba_is_contribution('App contribution on behalf'));
        $this->assertFalse(mkoba_is_contribution('Group Transfer'));
        $this->assertFalse(mkoba_is_contribution('Opening an account on cbs'));
        $this->assertFalse(mkoba_is_contribution(''));
    }

    public function testParseMkobaRowFromRealValues(): void
    {
        $row = mkoba_parse_row([
            'no' => '1', 'trans_id' => 'DBS9N7LOXOR', 'receipt' => 'DBS9N7LOXOR',
            'date' => '28/02/2026 23:50', 'member name' => 'CONSESA MUNISHI',
            'member id' => '255767276015.00', 'source' => '255767276015.00',
            'destination' => '60243499376.00', 'amount' => '5,000.00',
            'trans type' => 'Member Contribution',
        ]);
        $this->assertSame('767276015', $row['phone']);
        $this->assertSame(5000.0, $row['amount']);
        $this->assertSame('2026-02-28', $row['date']);
        $this->assertSame('DBS9N7LOXOR', $row['receipt']);
        $this->assertSame('CONSESA MUNISHI', $row['name']);
        $this->assertSame('monthly', $row['type']);
        $this->assertSame('M-Koba', $row['account']);
    }

    public function testParseMkobaRowSkipsNonContributionsAndZero(): void
    {
        $this->assertNull(mkoba_parse_row(['member id' => '255767276015.00', 'amount' => '5,000', 'trans type' => 'Group Transfer']));
        $this->assertNull(mkoba_parse_row(['member id' => '255767276015.00', 'amount' => '0', 'trans type' => 'Member Contribution']));
        $this->assertNull(mkoba_parse_row(['member id' => '', 'amount' => '5,000', 'trans type' => 'Member Contribution']));
    }

    public function testTemplateRowValidatesTypeAndAccount(): void
    {
        $ok = txn_template_parse_row([
            'receipt_number' => 'RCP-1', 'date' => '2026-06-27', 'member_phone' => '0712345678',
            'member_name' => 'Juma', 'amount' => '5000', 'type' => 'entrance', 'account' => 'Cash',
            'description' => 'fee',
        ]);
        $this->assertSame('712345678', $ok['phone']);
        $this->assertSame('2026-06-27', $ok['date']);
        $this->assertSame('entrance', $ok['type']);
        $this->assertSame('Cash', $ok['account']);
        $this->assertSame('fee', $ok['description']);

        // Bad type/account fall back safely.
        $bad = txn_template_parse_row(['member_phone' => '0712345678', 'amount' => '5000', 'type' => 'hacker', 'account' => 'Swiss Bank']);
        $this->assertSame('monthly', $bad['type']);
        $this->assertNull($bad['account']);
    }

    public function testUnmatchedRowsToCsvHasHeaderAndRows(): void
    {
        $csv = unmatched_rows_to_csv([
            [
                'name' => 'Consesa Munishi', 'phone' => '767276015', 'amount' => 5000.0,
                'date' => '2026-02-28', 'receipt' => 'DBS9N7LOXOR',
                'trans_type' => 'Member Contribution', 'reason' => 'No matching member',
            ],
        ]);
        $lines = array_values(array_filter(explode("\n", trim($csv)), fn($l) => $l !== ''));
        $this->assertSame('member_name,phone,amount,date,receipt,trans_type,reason', trim($lines[0]));
        $this->assertStringContainsString('Consesa Munishi', $lines[1]);
        $this->assertStringContainsString('767276015', $lines[1]);
        $this->assertStringContainsString('No matching member', $lines[1]);
    }

    public function testUnmatchedRowsToCsvHandlesMissingKeysAndEmptyList(): void
    {
        // Header-only when there are no rejects.
        $this->assertSame('member_name,phone,amount,date,receipt,trans_type,reason', trim(unmatched_rows_to_csv([])));

        // Missing keys don't fatal; reason defaults.
        $csv = unmatched_rows_to_csv([['phone' => '712345678']]);
        $this->assertStringContainsString('712345678', $csv);
        $this->assertStringContainsString('No matching member', $csv);
    }
}
