<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Matching a leader's entrance-fee list to the members (includes/entrance_fee_import.php).
 * Only sure matches are applied; anything a person should look at is 'check'.
 */
final class EntranceFeeImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/entrance_fee_import.php';
    }

    private function members(): array
    {
        return [
            ['ref' => '1', 'names' => ['Abdallah Ally'], 'phones' => ['255700000001']],
            ['ref' => '2', 'names' => ['Ally Mzungu/abdul Bakari Seif'], 'phones' => ['255700000002']],
            ['ref' => '3', 'names' => ['Miriam Nyoni'], 'phones' => ['255757435050']],
            ['ref' => '4', 'names' => ['Miriam Nyoni'], 'phones' => ['255747969616']],
            ['ref' => '5', 'names' => ['Frank Kakasa'], 'phones' => ['255755547028']],
            ['ref' => '6', 'names' => ['Frank Kakasa(m.koba)angela Msila'], 'phones' => ['255767410411']],
            ['ref' => '7', 'names' => ['Stratone Lyimo'], 'phones' => []],
            ['ref' => '8', 'names' => ['Rukondo M.manumbu'], 'phones' => []],
        ];
    }

    private function row(string $no, string $name, string $mkoba = '', string $note = ''): array
    {
        return ['no' => $no, 'name' => $name, 'mkoba_name' => $mkoba === '' ? $name : $mkoba, 'fee' => 45000.0, 'note' => $note];
    }

    private function one(array $row): array
    {
        return efi_match([$row], $this->members())[0];
    }

    public function testTheSameNameIsSure(): void
    {
        $r = $this->one($this->row('1', 'ABDALLAH ALLY'));
        $this->assertSame(['matched', '1', 'exact'], [$r['status'], $r['ref'], $r['how']]);
    }

    public function testTheSameWordsWithAnInitialIsSure(): void
    {
        $r = $this->one($this->row('1', 'RUKONDO MANUMBU'));
        $this->assertSame(['matched', '8'], [$r['status'], $r['ref']]);
    }

    public function testAPhoneNextToTheRealNameSettlesTwoMembersWithTheSameName(): void
    {
        $r = $this->one($this->row('1', 'MIRIAM NYONI (0747969616)', 'MIRIAM NYONI'));
        $this->assertSame(['matched', '4', 'phone'], [$r['status'], $r['ref'], $r['how']]);

        $r = $this->one($this->row('1', 'MIRIAM NYONI'));
        $this->assertSame('check', $r['status'], 'two Miriam Nyonis and no phone: a person decides');
        $this->assertNull($r['ref']);
    }

    public function testAnythingFoundOnlyThroughTheMkobaNameIsASuggestion(): void
    {
        // The M-Koba account is Frank Kakasa's, but the member is Angela Msila.
        $r = $this->one($this->row('1', 'ANGELA MSILA', 'FRANK KAKASA'));
        $this->assertSame('check', $r['status']);

        // A phone written in the M-Koba column is the account holder's.
        $r = $this->one($this->row('1', 'FRANK KAKASA', 'FRANK KAKASA (255767410411)'));
        $this->assertSame(['check', '6', 'phone (M-Koba name)'], [$r['status'], $r['ref'], $r['how']]);
    }

    public function testARecordHoldingTwoNamesIsSuggestedNotApplied(): void
    {
        $r = $this->one($this->row('1', 'ABDUL BAKARI SEIF', 'ALLY MZUNGU'));
        $this->assertSame(['check', '2'], [$r['status'], $r['ref']]);
        $this->assertStringContainsString('holds this name', $r['how']);
    }

    public function testACloseSpellingIsSuggestedNotApplied(): void
    {
        $r = $this->one($this->row('1', 'STRATTONE LYIMO'));
        $this->assertSame(['check', '7'], [$r['status'], $r['ref']]);
        $this->assertStringContainsString('close spelling', $r['how']);
    }

    public function testNobodyFoundIsNone(): void
    {
        $r = $this->one($this->row('1', 'LUCAS MJEMA'));
        $this->assertSame(['none', null], [$r['status'], $r['ref']]);
    }

    public function testTwoRowsForOneMemberAreBothChecked(): void
    {
        $out = efi_match([$this->row('1', 'ABDALLAH ALLY'), $this->row('2', 'ABDALLAH ALLY')], $this->members());
        $this->assertSame(['check', 'check'], array_column($out, 'status'));
    }

    public function testTheJoinDateComesFromTheNote(): void
    {
        $this->assertSame('2026-10-11', efi_join_date('New member as of  11/10/2026'));
        $this->assertNull(efi_join_date(''));
        $this->assertNull(efi_join_date('New member as of 31/02/2026'), 'not a real date');
        $r = $this->one($this->row('1', 'ABDALLAH ALLY', '', 'New member as of 28/10/2026'));
        $this->assertSame('2026-10-28', $r['join_date']);
    }

    public function testFeesAndPhonesAreRead(): void
    {
        $this->assertSame(45000.0, efi_fee('45,000'));
        $this->assertSame(500000.0, efi_fee(500000));
        $this->assertNull(efi_fee(''));
        $this->assertNull(efi_fee('-5'));
        $this->assertSame(['740121044'], efi_phones('ADERA MEDARD - 0740121044'));
        $this->assertSame(['745577161', '798746048'], efi_phones('EMMA JOACHIM-255745577161 / MODESTER MWITA - 255798746048'));
        $this->assertSame('GINES G KWEKA', efi_norm('Gines G.Kweka'));
        $this->assertSame('FEBRONIA MNYANI', efi_norm('FEBRONIA MNYANI (old No: 255788818715)'));
    }

    public function testTheScriptOnlyAppliesThePreparedFileAndKeepsAnUndoFile(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/import_entrance_fees.php');
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')", $code);
        $this->assertStringContainsString("if (\$r['status'] === 'matched')", $code, 'only sure rows go into the prepared file');
        $this->assertStringContainsString('WHERE l.source = ? AND l.source_ref = ?', $code, 'rows find members through the member import');
        $this->assertStringContainsString("if (!\$commit) {", $code, 'a dry run by default');
        $this->assertLessThan(strpos($code, '$pdo->beginTransaction();'), strpos($code, 'entrance_fee_undo_'), 'the undo file is written first');
    }
}
