<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The app's canonical currency symbol is "TSh" (see format_currency() in
 * helpers.php, which maps the ISO code TZS -> symbol "TSh "). Several
 * leadership-facing pages hardcoded the literal "TZS" as the money prefix,
 * bypassing the helper, so the dashboard cards read "TSh" while the chart and
 * every Report read "TZS" — the same currency shown two ways.
 *
 * These tests lock the visible label to "TSh" on the pages that were fixed,
 * while making sure the currency CODE passed to format_currency() is untouched.
 */
class CurrencySymbolConsistencyTest extends TestCase
{
    private const FILES = [
        'app/dashboard.php',
        'app/constant/reports/vicoba_reports.php',
        'app/constant/reports/expense_report.php',
        'app/constant/reports/member_statement.php',
        'app/constant/reports/death_analysis.php',
        'app/constant/accounts/print_budget.php',
        'app/constant/accounts/budget.php',
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
    public function testNoVisibleTzsMoneyLabelRemains(string $file): void
    {
        $src = $this->src($file);
        // Money prefix "TZS 1,000", unit labels "(TZS)", and the "1,000 TZS<" suffix
        // are the three shapes that were user-visible. None should survive.
        $this->assertStringNotContainsString('TZS ', $src, "$file still shows a 'TZS ' money prefix");
        $this->assertStringNotContainsString('(TZS)', $src, "$file still shows a '(TZS)' unit label");
        $this->assertStringNotContainsString(' TZS<', $src, "$file still shows a ' TZS' suffix label");
    }

    #[DataProvider('fileProvider')]
    public function testUsesCanonicalTshSymbol(string $file): void
    {
        $this->assertStringContainsString('TSh ', $this->src($file), "$file should display the canonical 'TSh' symbol");
    }

    public function testFormatCurrencyCodeIsPreserved(): void
    {
        // The ISO currency CODE 'TZS' fed to the helper must stay — only the
        // rendered symbol changes, and the helper is what maps it to "TSh ".
        $this->assertStringContainsString("format_currency(\$n, 'TZS', 0)", $this->src('app/dashboard.php'));
    }

    public function testHelperStillMapsTzsCodeToTshSymbol(): void
    {
        $helpers = $this->src('helpers.php');
        $this->assertStringContainsString("'TZS' => 'TSh '", $helpers);
    }
}
