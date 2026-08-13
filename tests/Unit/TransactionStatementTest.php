<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The Member Statement of Transactions — the same money as the Contributions
 * statement, laid out by WHEN IT ARRIVED rather than which months it covers.
 *
 * One 100,000 payment in January is a single January event here and five covered
 * months there. That is the whole point of having two documents, and it is also
 * the whole risk: two pages describing the same member's money that disagree on
 * the total are worse than one page, because now nobody knows which to believe.
 * The tie-out tests below are the ones that matter.
 */
class TransactionStatementTest extends TestCase
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

    private function gridTotal(array $grid): float
    {
        $t = 0.0;
        foreach ($grid['years'] as $cells) {
            foreach ($cells as $c) {
                $t += $c['allocated'];
            }
        }
        return $t;
    }

    // -------------------------------------------------------------------------
    // The invariant the whole feature rests on
    // -------------------------------------------------------------------------

    public function testBothStatementsReportTheSameTotalForTheSameMoney(): void
    {
        // 100,000 paid in one lump in January, 20,000 monthly target, viewed in May.
        // Transactions: one January event. Contributions: five covered months.
        // Different shapes, identical totals — the first thing anyone checks.
        $asOf   = new DateTime('2026-05-15');
        $events = [['date' => '2026-01-12', 'amount' => 100000.0]];

        $sched      = cs_build_schedule(0, 100000, 20000, 0, '2026-01-01', null, $asOf);
        $contrib    = cs_calendar_grid($sched, $asOf);
        $trans      = cs_transaction_grid($events, 20000, '2026-01-01', $asOf);

        $this->assertSame(100000.0, $this->gridTotal($contrib));
        $this->assertSame(100000.0, $this->gridTotal($trans));
        $this->assertSame(
            cs_year_summary($contrib)['total']['actual'],
            cs_year_summary($trans)['total']['actual'],
            'the two statements must agree on what the member has paid'
        );
    }

    public function testTotalsStillAgreeWhenTheMoneyCoversAFollowingYear(): void
    {
        // Paid entirely in 2026, covering months into 2027. The YEARLY figures
        // legitimately differ between the two documents — that is the point — but
        // the grand totals may not.
        $asOf   = new DateTime('2026-08-13');
        $events = [['date' => '2026-07-21', 'amount' => 285000.0]];

        $sched   = cs_build_schedule(0, 285000, 20000, 0, '2026-07-01', null, $asOf);
        $contrib = cs_calendar_grid($sched, $asOf);
        $trans   = cs_transaction_grid($events, 20000, '2026-07-01', $asOf);

        // Different years, same money.
        $this->assertSame(285000.0, cs_year_summary($trans)['years'][2026]['actual']);
        $this->assertNotSame(285000.0, cs_year_summary($contrib)['years'][2026]['actual']);
        $this->assertSame(285000.0, $this->gridTotal($contrib));
        $this->assertSame(285000.0, $this->gridTotal($trans));
    }

    public function testBothStatementsAgreeOnTargetAndVarianceNotJustTotal(): void
    {
        // Production member #1, and the case that caught a real contradiction:
        // receipts dated Jan and Feb 2026 against a join date of 21 Jul 2026.
        //
        // The transaction grid must stretch BACK to show that early money, but it
        // must not bill the months it stretched over. Moving the billing anchor with
        // the display anchor charged eight months instead of two, and the two
        // deployed statements printed variances of 125,000 and 245,000 for the same
        // member. Comparing only the Actual column missed it entirely.
        $asOf   = new DateTime('2026-08-13');
        $events = [
            ['date' => '2026-01-15', 'amount' => 200000.0],
            ['date' => '2026-02-28', 'amount' => 85000.0],
        ];

        $sched   = cs_build_schedule(285000, 0, 20000, 0, '2026-01-01', '2026-07-21', $asOf);
        $contrib = cs_year_summary(cs_calendar_grid($sched, $asOf));
        $trans   = cs_year_summary(cs_transaction_grid($events, 20000, $sched['anchor_ym'], $asOf));

        $this->assertSame(40000.0, $trans['total']['target'], 'two elapsed months since joining, not eight');
        $this->assertSame($contrib['total']['target'], $trans['total']['target']);
        $this->assertSame($contrib['total']['actual'], $trans['total']['actual']);
        $this->assertSame($contrib['total']['variance'], $trans['total']['variance']);
        $this->assertSame(245000.0, $trans['total']['variance']);
    }

    public function testEarlyMoneyIsStillShownEvenThoughItIsNotBilled(): void
    {
        // The other half of the same rule: not billing those months must not hide them.
        $grid = cs_transaction_grid(
            [['date' => '2026-01-15', 'amount' => 200000.0]],
            20000, '2026-07-01', new DateTime('2026-08-13')
        );

        $jan = $grid['years'][2026][1];
        $this->assertSame(200000.0, $jan['allocated'], 'the money must appear');
        $this->assertSame('received', $jan['status'], 'and be marked as received, not as pre-join');
        $this->assertSame(0.0, $jan['target'], 'but that month is not billed');
    }

    // -------------------------------------------------------------------------
    // Receipts land on the month they arrived
    // -------------------------------------------------------------------------

    public function testEachPaymentLandsInTheMonthItWasReceived(): void
    {
        $grid = cs_transaction_grid([
            ['date' => '2026-01-12', 'amount' => 50000.0],
            ['date' => '2026-01-28', 'amount' => 10000.0],   // same month, must add
            ['date' => '2026-04-03', 'amount' => 20000.0],
        ], 20000, '2026-01-01', new DateTime('2026-06-15'));

        $this->assertSame(60000.0, $grid['years'][2026][1]['allocated']);
        $this->assertSame('received', $grid['years'][2026][1]['status']);
        $this->assertSame(0.0, $grid['years'][2026][2]['allocated']);
        $this->assertSame('none', $grid['years'][2026][2]['status']);
        $this->assertSame(20000.0, $grid['years'][2026][4]['allocated']);
    }

    public function testMonthsWithNoPaymentAreBlankNotAccusatory(): void
    {
        // A transactions statement records what happened. An empty month means "no
        // payment arrived", not "you defaulted" — the arrears judgement belongs on
        // the contributions statement, where the target actually lives.
        $grid = cs_transaction_grid(
            [['date' => '2026-01-12', 'amount' => 20000.0]],
            20000, '2026-01-01', new DateTime('2026-03-15')
        );
        $html = $this->render(fn() => stmt_calendar($grid, false));

        $this->assertStringNotContainsString('vk-c-unpaid', $html, 'nothing on this page may be marked unpaid');
        $this->assertStringContainsString('vk-c-received', $html);
        $this->assertStringContainsString('vk-c-none', $html);
    }

    public function testPaymentsAreNeverHiddenByTheAnchor(): void
    {
        // If a receipt predates the anchor the grid must stretch back to include it.
        // Money that exists must never be dropped by a boundary the member did not set.
        $grid = cs_transaction_grid(
            [['date' => '2025-09-04', 'amount' => 30000.0]],
            20000, '2026-01-01', new DateTime('2026-03-15')
        );

        $this->assertSame(2025, $grid['first_year']);
        $this->assertSame(30000.0, $grid['years'][2025][9]['allocated']);
        $this->assertSame(30000.0, $this->gridTotal($grid));
    }

    public function testFutureMonthsAreEmptyAndUnbilled(): void
    {
        $grid = cs_transaction_grid(
            [['date' => '2026-01-12', 'amount' => 20000.0]],
            20000, '2026-01-01', new DateTime('2026-03-15')
        );

        $this->assertSame('future', $grid['years'][2026][4]['status']);
        $this->assertSame(0.0, $grid['years'][2026][4]['target'], 'a future month cannot be billed');
    }

    public function testGridToleratesUnparseableDatesRatherThanFataling(): void
    {
        $grid = cs_transaction_grid([
            ['date' => '2026-01-12', 'amount' => 20000.0],
            ['date' => '', 'amount' => 5000.0],
        ], 20000, '2026-01-01', new DateTime('2026-03-15'));

        $this->assertSame(20000.0, $this->gridTotal($grid));
    }

    // -------------------------------------------------------------------------
    // Legend and page wiring
    // -------------------------------------------------------------------------

    public function testTransactionLegendOmitsTheContributionStates(): void
    {
        $html = $this->render(fn() => stmt_legend(false, 'transactions'));

        $this->assertStringContainsString('Money received', $html);
        $this->assertStringContainsString('No transaction', $html);
        $this->assertStringNotContainsString('Partial', $html);
        $this->assertStringNotContainsString('Not paid', $html);
    }

    public function testEveryStatementQueryUsesOneSharedFilter(): void
    {
        // Four queries decide which contribution rows count on a statement: a
        // member's schedule, a member's receipts, every member's schedule, every
        // member's receipts. The moment one accepts a row another rejects, a
        // member's own statement and the group statement disagree about that member.
        //
        // This originally asserted the filter appeared exactly twice. Adding the
        // group statements made it four, and the test failing was the correct
        // outcome — the fix was to extract cs_statement_filter_sql(), not to raise
        // the count. So it now asserts there is exactly ONE definition.
        $src = file_get_contents(__DIR__ . '/../../includes/contribution_standing.php');

        preg_match_all("/contribution_type IN \('monthly','entrance','other'\)/", $src, $m);
        $this->assertCount(1, $m[0], 'the filter must be defined once, in cs_statement_filter_sql()');

        // ...and all four queries must go through it (occurrences, less the definition).
        $this->assertSame(4, substr_count($src, 'cs_statement_filter_sql(') - 1,
            'every statement query must call the shared filter');
    }

    public function testTheSharedFilterAppliesCleanlyWithAndWithoutAnAlias(): void
    {
        $bare    = cs_statement_filter_sql();
        $aliased = cs_statement_filter_sql('co');

        $this->assertStringContainsString("status IN ('confirmed','approved','')", $bare);
        $this->assertStringNotContainsString('co.', $bare);
        $this->assertStringContainsString("co.status IN ('confirmed','approved','')", $aliased);
        $this->assertStringContainsString('co.contribution_type', $aliased);
        // A trailing dot from the caller must not produce "co..status".
        $this->assertSame($aliased, cs_statement_filter_sql('co.'));
    }

    public function testPageIsRoutedAndGatedLikeTheContributionsStatement(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');
        $this->assertStringContainsString("'member_transactions'", $roots);

        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_transactions.php');
        $this->assertStringContainsString("\$is_leader = isAdmin() || canCreate('manage_contributions');", $src);
        $this->assertStringContainsString('if (!$is_leader || !$member_id) {', $src);
        $this->assertStringContainsString('$member_id = $own_cid;', $src);
    }

    public function testPageUsesTheHeadingTheGroupSpecified(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_transactions.php');
        $this->assertStringContainsString('Member Statement of Transactions as of', $src);
    }

    public function testUndatedOpeningIsCarriedForwardRatherThanLost(): void
    {
        // customers.initial_savings has no date, so it cannot sit in a month. It must
        // still be counted, or this statement falls short of the contributions one.
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_transactions.php');
        $this->assertStringContainsString("\$opening_bf = (float) (\$member['initial_savings'] ?? 0);", $src);
        $this->assertStringContainsString('Opening Brought Forward', $src);
        $this->assertStringContainsString('$money($opening_bf + $received_total)', $src);
    }

    public function testFinesAreListedButKeptOutOfTheContributionTotal(): void
    {
        // The group counts a fine as a real transaction, so it appears in the ledger.
        // It is not savings, so it must never be folded into the figure the two
        // statements have to agree on.
        $src = file_get_contents(__DIR__ . '/../../app/constant/reports/member_transactions.php');
        $this->assertStringContainsString("status = 'paid'", $src);
        $this->assertStringContainsString('Fines Paid', $src);
        $this->assertStringNotContainsString('$received_total + $fines_total', $src);
    }
}
