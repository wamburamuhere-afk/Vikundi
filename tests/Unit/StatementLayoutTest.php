<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * includes/statement_layout.php — the shared NSSF-style skeleton behind all four
 * statements (member/group x contributions/transactions).
 *
 * These tests RENDER the functions and read the output, rather than grepping the
 * source for expected strings. That distinction is deliberate: this codebase already
 * has tests asserting on their own inline copies of production logic, which pass
 * happily while the product changes underneath them. A test that captures real
 * output cannot do that.
 */
class StatementLayoutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
        require_once __DIR__ . '/../../includes/statement_layout.php';
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    /** A member on 20k/month who has paid 100,000, viewed in May. */
    private function grid(
        float $newMoney = 100000,
        float $monthly = 20000,
        string $start = '2026-01-01',
        ?string $join = null,
        string $asOf = '2026-05-15'
    ): array {
        $sched = cs_build_schedule(0, $newMoney, $monthly, 0, $start, $join, new DateTime($asOf));
        return cs_calendar_grid($sched, new DateTime($asOf));
    }

    // -------------------------------------------------------------------------
    // The grid — what a member actually sees
    // -------------------------------------------------------------------------

    public function testPreJoinMonthsRenderBlankRatherThanZero(): void
    {
        // Joined in May. January to April must be visibly empty, not "0" — a zero in a
        // money column reads as "paid nothing", which is an accusation, not a fact.
        $html = $this->render(fn() => stmt_calendar($this->grid(60000, 20000, '2026-01-01', '2026-05-10', '2026-08-15'), false));

        $this->assertStringContainsString('vk-c-before', $html);
        $cells = $this->cellsFor($html, 2026);
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertStringContainsString('vk-c-before', $cells[$i], "month " . ($i + 1) . " should be marked pre-join");
            $this->assertStringNotContainsString('0', strip_tags($cells[$i]), 'a pre-join month must not print a figure');
        }
    }

    public function testPaidPartialAndUnpaidMonthsAreVisuallyDistinct(): void
    {
        // 50,000 against a 20,000 target from January, viewed in April:
        // Jan and Feb full, Mar half, Apr nothing.
        $html  = $this->render(fn() => stmt_calendar($this->grid(50000, 20000, '2026-01-01', null, '2026-04-15'), false));
        $cells = $this->cellsFor($html, 2026);

        $this->assertStringContainsString('vk-c-paid', $cells[0]);
        $this->assertStringContainsString('vk-c-paid', $cells[1]);
        $this->assertStringContainsString('vk-c-partial', $cells[2]);
        $this->assertStringContainsString('10,000', $cells[2], 'the part that was paid must still show');
        $this->assertStringContainsString('vk-c-unpaid', $cells[3]);
    }

    public function testMoneyPaidAheadIsMarkedAdvanceNotOverpayment(): void
    {
        $html  = $this->render(fn() => stmt_calendar($this->grid(240000, 20000, '2026-01-01', null, '2026-03-15'), false));
        $cells = $this->cellsFor($html, 2026);

        $this->assertStringContainsString('vk-c-paid', $cells[2]);      // March — due and met
        $this->assertStringContainsString('vk-c-advance', $cells[3]);   // April — paid ahead
        $this->assertStringContainsString('20,000', $cells[3]);
    }

    public function testEveryYearRowPrintsTwelveMonthsPlusATotal(): void
    {
        $html = $this->render(fn() => stmt_calendar($this->grid(100000, 20000, '2025-11-01', null, '2026-03-15'), false));

        foreach ([2025, 2026] as $year) {
            $this->assertCount(13, $this->cellsFor($html, $year), "year $year needs 12 months and a total");
        }
        $this->assertStringContainsString('vk-stmt-rowtotal', $html);
    }

    public function testMonthHeadingsAreTranslated(): void
    {
        $en = $this->render(fn() => stmt_calendar($this->grid(), false));
        $sw = $this->render(fn() => stmt_calendar($this->grid(), true));

        $this->assertStringContainsString('>MAY<', $en);
        $this->assertStringContainsString('>YEAR<', $en);
        $this->assertStringContainsString('>MEI<', $sw);
        $this->assertStringContainsString('>MWAKA<', $sw);
    }

    // -------------------------------------------------------------------------
    // The summary — Target vs Actual
    // -------------------------------------------------------------------------

    public function testSummaryShowsADeficitInBrackets(): void
    {
        // Four months elapsed at 20k = 80,000 due; 50,000 paid; 30,000 short.
        $summary = cs_year_summary($this->grid(50000, 20000, '2026-01-01', null, '2026-04-15'));
        $html    = $this->render(fn() => stmt_summary($summary, false));

        $this->assertStringContainsString('80,000', $html);
        $this->assertStringContainsString('50,000', $html);
        $this->assertStringContainsString('(30,000)', $html, 'a shortfall reads as a bracketed figure');
        $this->assertStringContainsString('vk-neg', $html);
    }

    public function testTotalSurvivesWhenTheGroupHasNoMonthlyRule(): void
    {
        // The trap: with no monthly rule nothing lands on a month, every cell reads 0,
        // and a summed total would tell a member who has paid 500,000 that they have
        // paid nothing. The note row must state what they actually contributed.
        $summary = cs_year_summary($this->grid(500000, 0, '2026-01-01', null, '2026-06-15'));
        $html    = $this->render(fn() => stmt_summary($summary, false));

        $this->assertStringContainsString('500,000', $html);
        $this->assertStringContainsString('Total contributed:', $html);

        $sw = $this->render(fn() => stmt_summary($summary, true));
        $this->assertStringContainsString('Jumla aliyotoa:', $sw);
    }

    public function testTheNoteIsAbsentWhenEveryShillingIsAllocated(): void
    {
        $summary = cs_year_summary($this->grid(100000, 20000, '2026-01-01', null, '2026-05-15'));
        $html    = $this->render(fn() => stmt_summary($summary, false));

        $this->assertStringNotContainsString('Total contributed:', $html);
    }

    // -------------------------------------------------------------------------
    // The header — honesty about missing data
    // -------------------------------------------------------------------------

    public function testMissingRegistrationDetailsAreOmittedNotInvented(): void
    {
        // Production has no registration number and no logo. A blank line is honest;
        // a plausible-looking "Reg/2026/001" on a real member's statement is not.
        $html = $this->render(fn() => stmt_head(
            ['name' => 'Umoja Test', 'logo' => '', 'registration' => '', 'org_type' => '', 'phone' => '', 'address' => ''],
            'Member Statement of Contributions as of',
            'AUG 2026'
        ));

        $this->assertStringContainsString('UMOJA TEST', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('Reg/', $html);
        $this->assertStringContainsString('MEMBER STATEMENT OF CONTRIBUTIONS AS OF AUG 2026', $html);
    }

    public function testGroupNameIsEscaped(): void
    {
        $html = $this->render(fn() => stmt_head(
            ['name' => '<script>x</script>', 'logo' => '', 'registration' => '', 'org_type' => '', 'phone' => '', 'address' => ''],
            'T', 'AUG 2026'
        ));
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testAsOfLabelMatchesTheWordingTheGroupAskedFor(): void
    {
        $this->assertSame('AUG 2026', stmt_as_of_label(new DateTime('2026-08-13'), false));
        $this->assertSame('AGO 2026', stmt_as_of_label(new DateTime('2026-08-13'), true));
    }

    // -------------------------------------------------------------------------
    // The Condolences band
    // -------------------------------------------------------------------------

    public function testCondolencesBandStatesWhenNothingWasPaid(): void
    {
        $html = $this->render(fn() => stmt_bar_table(
            'Condolences',
            ['date' => 'Date', 'amount' => 'Amount Paid'],
            [],
            'No condolences have been paid to this member.'
        ));

        $this->assertStringContainsString('CONDOLENCES', $html);
        $this->assertStringContainsString('No condolences have been paid to this member.', $html);
    }

    public function testCondolencesBandListsWhatWasPaid(): void
    {
        $html = $this->render(fn() => stmt_bar_table(
            'Condolences',
            ['date' => 'Date', 'deceased' => 'Name of Deceased', 'amount' => 'Amount Paid'],
            [['date' => '04 Mar 2026', 'deceased' => 'Asha Juma', 'amount' => '150,000']],
            'none'
        ));

        $this->assertStringContainsString('Asha Juma', $html);
        $this->assertStringContainsString('150,000', $html);
        $this->assertStringNotContainsString('none', $html);
    }

    // -------------------------------------------------------------------------
    // The page — the gate must not drift
    // -------------------------------------------------------------------------

    public function testMembersStillCannotReadAnotherMembersStatement(): void
    {
        // This gate was a real fix: canView('vicoba_reports') alone let any member
        // open any other member's statement via ?id. Restyling the page must never
        // quietly relax it.
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');

        $this->assertStringContainsString("\$is_leader = isAdmin() || canCreate('manage_contributions');", $src);
        $this->assertStringContainsString('if (!$is_leader || !$member_id) {', $src);
        $this->assertStringContainsString('$member_id = $own_cid;', $src);
    }

    public function testStatementUsesTheHeadingTheGroupSpecified(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
        $this->assertStringContainsString('Member Statement of Contributions as of', $src);
    }

    public function testAsOfCannotBeSetToTheFutureFromTheControl(): void
    {
        // Future months carry no target, so a future "as of" would print a page of
        // empty columns and invite the question of why the member owes nothing.
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_statement.php');
        $this->assertStringContainsString('max="<?= date(\'Y-m\') ?>"', $src);
    }

    /** The `<td>` cells of one year row, in order. */
    private function cellsFor(string $html, int $year): array
    {
        if (!preg_match('#<td class="vk-stmt-year">' . $year . '</td>(.*?)</tr>#s', $html, $m)) {
            $this->fail("no row rendered for year $year");
        }
        preg_match_all('#<td[^>]*>.*?</td>#s', $m[1], $cells);
        return $cells[0];
    }
}
