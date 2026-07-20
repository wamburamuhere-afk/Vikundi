<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Follow-up to CurrencySymbolConsistencyTest: the member-facing money pages
 * (Contribution Ledger, Fines) still showed the old "TZS" label — and mixed its
 * position (suffix on the summary tiles, prefix in the fines list) — while the
 * rest of the app uses the canonical "TSh". These pages now render "TSh", and
 * the fines table amount column carries its unit instead of a bare number.
 *
 * The currency CODE default ($currency ?? 'TZS') is left intact — only the
 * user-visible symbol is normalised.
 */
class MemberMoneyCurrencyTest extends TestCase
{
    private const FILES = [
        'app/bms/customer/manage_contributions.php',
        'app/bms/customer/manage_fines.php',
        'app/bms/customer/my_fines.php',
    ];

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    /** @return array<string, array{0:string}> */
    public static function fileProvider(): array
    {
        $out = [];
        foreach (self::FILES as $f) {
            $out[$f] = [$f];
        }
        return $out;
    }

    #[DataProvider('fileProvider')]
    public function testNoVisibleTzsLabelRemains(string $file): void
    {
        $src = $this->src($file);
        // Every user-visible shape the old code used: prefix "TZS 1,000",
        // suffix "1,000 TZS", and the "<small>TZS</small>" / "<span>TZS</span>"
        // labels. None should survive. (The code default `?? 'TZS'` is `'TZS'`
        // with no surrounding space/angle bracket, so it is not matched here.)
        $this->assertStringNotContainsString('TZS ', $src, "$file still shows a 'TZS ' prefix");
        $this->assertStringNotContainsString(' TZS', $src, "$file still shows a ' TZS' suffix");
        $this->assertStringNotContainsString('TZS<', $src, "$file still shows a '>TZS<' label");
    }

    #[DataProvider('fileProvider')]
    public function testUsesCanonicalTshSymbol(string $file): void
    {
        $this->assertStringContainsString('TSh', $this->src($file), "$file should display the canonical 'TSh' symbol");
    }

    public function testFinesTableAmountColumnHasCurrencyUnit(): void
    {
        // The DataTables amount renderer previously output a bare number; it now
        // prefixes the canonical symbol so the column is not unit-less.
        $this->assertStringContainsString('TSh ${money(d)}', $this->src('app/bms/customer/manage_fines.php'));
    }

    public function testCurrencyCodeDefaultIsPreserved(): void
    {
        $this->assertStringContainsString("?? 'TZS'", $this->src('app/bms/customer/manage_contributions.php'));
    }
}
