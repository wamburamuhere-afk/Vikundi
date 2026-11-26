<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The newer M-Koba export, the "Digital Group Saving Reports" (.xlsx): a title line,
 * then Date | Receipt | Name | Member Number | Amount | TransType | Blc.Before |
 * Blc.After | Status. Kuchangia is a member's payment; Hamisho is money going out
 * of the group account. The values below are invented, in the export's real shape.
 */
final class MkobaDgsStatementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/transaction_import.php';
    }

    private const HEAD = ['Date', 'Receipt', 'Name', 'Member Number', 'Amount', 'TransType', 'Blc.Before', 'Blc.After', 'Status'];

    /** A row as the importer builds it: lower-cased header => cell. */
    private function assoc(array $cells): array
    {
        return array_combine(array_map('strtolower', self::HEAD), $cells);
    }

    private function pay(string $type = 'Kuchangia', string $amount = '15000.00', string $status = 'Success'): array
    {
        return $this->assoc(['2025-12-02 11:23:22.0', 'CL29TEST001', 'ASHA MUSA', '255700000123', $amount, $type, '27328000.00', '27343000.00', $status]);
    }

    public function testTheHeaderIsFoundBelowTheTitleLine(): void
    {
        $rows = [['Digital Group Saving Reports'], self::HEAD, ['2025-12-01 14:36:43.0', 'X', 'Y', '255700000001', '5000.00', 'Kuchangia', '0', '5000', 'Success']];
        $this->assertSame(1, mkoba_header_index($rows));
        // The older statement starts with its header.
        $this->assertSame(0, mkoba_header_index([['NO', 'TRANS_ID', 'RECEIPT', 'DATE', 'MEMBER NAME', 'MEMBER ID', 'SOURCE', 'DESTINATION', 'AMOUNT', 'TRANS TYPE']]));
        $this->assertNull(mkoba_header_index([['just a title'], ['a', 'b']]));
    }

    public function testBothDateFormatsAreRead(): void
    {
        $this->assertSame('2025-12-01', mkoba_parse_date('2025-12-01 14:36:43.0'));
        $this->assertSame('2026-02-28', mkoba_parse_date('28/02/2026 23:50'));
        $this->assertNull(mkoba_parse_date('2025-13-01 10:00:00.0'));
        $this->assertNull(mkoba_parse_date(''));
    }

    public function testAPaymentBecomesAContribution(): void
    {
        $p = mkoba_parse_row(mkoba_normalize_assoc($this->pay()));
        $this->assertSame('700000123', $p['phone']);
        $this->assertSame(15000.0, $p['amount']);
        $this->assertSame('2025-12-02', $p['date']);
        $this->assertSame('CL29TEST001', $p['receipt']);
        $this->assertSame('CL29TEST001', $p['trans_id'], 'the receipt is the transaction id');
        $this->assertSame('ASHA MUSA', $p['name']);
        $this->assertSame('monthly', $p['type']);
        $this->assertSame('M-Koba', $p['account']);
    }

    public function testHamishoIsMoneyOutAndNeverAContribution(): void
    {
        $out = mkoba_normalize_assoc($this->pay('Hamisho', '-2000000.00', 'Process service request successfully.'));
        $this->assertNull(mkoba_parse_row($out), 'the minus would otherwise be stripped and count as a payment');
        $m = mkoba_mirror_row($out);
        $this->assertFalse($m['is_contribution']);
        $this->assertStringContainsString('Hamisho', $m['reason']);
    }

    public function testAFailedRowIsNotImported(): void
    {
        $failed = mkoba_normalize_assoc($this->pay('Kuchangia', '15000.00', 'Failed'));
        $this->assertNull(mkoba_parse_row($failed));
        $this->assertStringContainsString('Failed', mkoba_mirror_row($failed)['reason']);
        // Both success wordings the export uses count as success.
        $this->assertNotNull(mkoba_parse_row(mkoba_normalize_assoc($this->pay('Kuchangia', '15000.00', 'Process service request successfully.'))));
    }

    public function testTheMirrorRowMatchesTheContribution(): void
    {
        $a = mkoba_normalize_assoc($this->pay());
        $m = mkoba_mirror_row($a);
        $this->assertTrue($m['is_contribution']);
        $this->assertSame('700000123', $m['match_phone']);
        $this->assertSame('255700000123', $m['member_id']);
        $this->assertSame('2025-12-02', $m['trans_date']);
        $this->assertSame(15000.0, $m['amount']);
    }

    public function testTheOlderStatementIsUnchanged(): void
    {
        $old = ['no' => '1', 'trans_id' => 'ABC', 'receipt' => 'ABC', 'date' => '28/02/2026 23:50', 'member name' => 'X',
                'member id' => '255767276015.00', 'source' => '', 'destination' => '', 'amount' => '5,000.00', 'trans type' => 'Member Contribution'];
        $this->assertSame($old, mkoba_normalize_assoc($old));
        $this->assertSame(5000.0, mkoba_parse_row($old)['amount']);
    }

    public function testThePhoneIndexFindsEveryMemberOnAPhoneOnce(): void
    {
        $index = txn_phone_index([
            ['customer_id' => 1, 'phone' => '255712345678'],
            ['customer_id' => 2, 'phone' => '0712345678'],      // same phone, other format: shared
            ['customer_id' => 3, 'phone' => '+255 754 000 111'],
            ['customer_id' => 4, 'phone' => '12345'],           // too short to be a phone
            ['customer_id' => 5, 'phone' => ''],
        ]);
        $this->assertSame(['712345678' => [1, 2], '754000111' => [3]], $index);
        // A shared phone is still refused, a single one is credited.
        $this->assertNull(txn_pick_member($index['712345678'])[0]);
        $this->assertSame(3, txn_pick_member($index['754000111'])[0]);
        $this->assertSame([null, 'No matching member'], txn_pick_member($index['700000000'] ?? []));
    }

    public function testBothImportersUseTheHeaderAndTheNormaliser(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['actions/import_contributions.php', 'database/import_mkoba_oneoff.php'] as $f) {
            $code = (string) file_get_contents("$root/$f");
            $this->assertStringContainsString('mkoba_header_index($allRows)', $code, $f);
            $this->assertStringContainsString('mkoba_normalize_assoc(', $code, $f);
        }
    }
}
