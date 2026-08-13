<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The two group statements — Contributions and Transactions — in their two views:
 * combined (the group as a single member) and per-member (one row each).
 *
 * The invariant the whole page rests on: the per-member rows must sum to the
 * combined totals. Those are two different code paths over the same money, shown
 * on two tabs of one document, and the first thing a treasurer does is add up the
 * column and check it against the other view.
 */
class GroupStatementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
        require_once __DIR__ . '/../../includes/statement_layout.php';
    }

    /** Three members with different histories, as grids. */
    private function members(string $asOf = '2026-04-15'): array
    {
        $d = new DateTime($asOf);
        return [
            // paid up
            cs_calendar_grid(cs_build_schedule(0, 80000, 20000, 0, '2026-01-01', null, $d), $d),
            // half short
            cs_calendar_grid(cs_build_schedule(0, 50000, 20000, 0, '2026-01-01', null, $d), $d),
            // joined late, paid nothing
            cs_calendar_grid(cs_build_schedule(0, 0, 20000, 0, '2026-01-01', '2026-03-10', $d), $d),
        ];
    }

    private function total(array $grid): float
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
    // The invariant
    // -------------------------------------------------------------------------

    public function testPerMemberRowsSumToTheCombinedTotals(): void
    {
        $grids  = $this->members();
        $merged = cs_merge_grids($grids);
        $group  = cs_year_summary($merged);

        $target = $actual = 0.0;
        foreach ($grids as $g) {
            $s = cs_year_summary($g);
            $target += $s['total']['target'];
            $actual += $s['total']['actual'];
        }

        $this->assertSame($target, $group['total']['target'], 'targets must reconcile across the two views');
        $this->assertSame($actual, $group['total']['actual'], 'actuals must reconcile across the two views');
        $this->assertSame($actual - $target, $group['total']['variance']);
    }

    public function testTheCombinedGridHoldsEveryShillingTheMembersPaid(): void
    {
        $grids  = $this->members();
        $paid   = array_sum(array_map(fn($g) => $this->total($g), $grids));

        $this->assertSame(130000.0, $paid, '80,000 + 50,000 + 0');
        $this->assertSame($paid, $this->total(cs_merge_grids($grids)));
    }

    // -------------------------------------------------------------------------
    // A group is not a person — the merged states mean different things
    // -------------------------------------------------------------------------

    public function testAGroupMonthIsPartialWhenSomeMembersPaidAndSomeDidNot(): void
    {
        // April: the first member covered it, the second ran out, the third has
        // joined but paid nothing. The group met part of April, so April is partial —
        // not red because someone missed, and not green because someone paid.
        $merged = cs_merge_grids($this->members());
        $apr    = $merged['years'][2026][4];

        $this->assertSame('partial', $apr['status']);
        $this->assertSame(60000.0, $apr['target'], 'three members owed April');
        $this->assertSame(20000.0, $apr['allocated']);
    }

    public function testAMonthIsOnlyPreJoinWhenItIsPreJoinForEveryMember(): void
    {
        // The third member joined in March, but the other two owed January. If any
        // member owed something that month, the group owed something that month.
        $merged = cs_merge_grids($this->members());

        $this->assertSame(40000.0, $merged['years'][2026][1]['target'], 'two members owed January');
        $this->assertNotSame('before_join', $merged['years'][2026][1]['status']);
    }

    public function testAMonthBeforeEverybodyJoinedStaysPreJoin(): void
    {
        $d = new DateTime('2026-06-15');
        $merged = cs_merge_grids([
            cs_calendar_grid(cs_build_schedule(0, 40000, 20000, 0, '2026-01-01', '2026-05-01', $d), $d),
            cs_calendar_grid(cs_build_schedule(0, 20000, 20000, 0, '2026-01-01', '2026-05-01', $d), $d),
        ]);

        $this->assertSame('before_join', $merged['years'][2026][1]['status']);
        $this->assertSame(0.0, $merged['years'][2026][1]['target'], 'a month nobody had joined cannot be billed');
    }

    public function testFutureMonthsStayFutureAndUnbilled(): void
    {
        $merged = cs_merge_grids($this->members());

        $this->assertSame('future', $merged['years'][2026][6]['status']);
        $this->assertSame(0.0, $merged['years'][2026][6]['target']);
    }

    public function testMergingNothingDoesNotFatal(): void
    {
        $merged = cs_merge_grids([]);
        $this->assertSame([], $merged['years']);
        $this->assertSame(0.0, cs_year_summary($merged)['total']['actual']);
    }

    // -------------------------------------------------------------------------
    // Both documents, same money
    // -------------------------------------------------------------------------

    public function testGroupContributionsAndTransactionsAgreeOnTheGrandTotal(): void
    {
        $d = new DateTime('2026-05-15');

        $contrib = cs_merge_grids([
            cs_calendar_grid(cs_build_schedule(0, 100000, 20000, 0, '2026-01-01', null, $d), $d),
            cs_calendar_grid(cs_build_schedule(0, 60000, 20000, 0, '2026-01-01', null, $d), $d),
        ]);
        $trans = cs_merge_grids([
            cs_transaction_grid([['date' => '2026-01-12', 'amount' => 100000.0]], 20000, '2026-01-01', $d),
            cs_transaction_grid([['date' => '2026-02-05', 'amount' => 60000.0]], 20000, '2026-01-01', $d),
        ]);

        $this->assertSame(160000.0, $this->total($contrib));
        $this->assertSame(160000.0, $this->total($trans));
        $this->assertSame(
            cs_year_summary($contrib)['total']['target'],
            cs_year_summary($trans)['total']['target'],
            'both group documents bill the same months'
        );
    }

    public function testTheGrandTotalTiesEvenWhenTheGroupHasNoMonthlyRule(): void
    {
        // The general form of the invariant, and the group's actual state on the
        // local database today. With no monthly rule the contributions document can
        // allocate nothing — every cell reads 0 and `actual` is 0 — while the
        // transactions document still records every receipt by date.
        //
        // So `actual` legitimately differs between the two documents here, and
        // `paid` (allocated + unallocated) is the figure that must always tie. A
        // tie-out written against `actual` alone would pass on a configured group
        // and silently mean nothing on an unconfigured one.
        $d = new DateTime('2026-05-15');

        $contrib = cs_merge_grids([
            cs_calendar_grid(cs_build_schedule(0, 100000, 0, 0, '2026-01-01', null, $d), $d),
            cs_calendar_grid(cs_build_schedule(0, 60000, 0, 0, '2026-01-01', null, $d), $d),
        ]);
        $trans = cs_merge_grids([
            cs_transaction_grid([['date' => '2026-01-12', 'amount' => 100000.0]], 0, '2026-01-01', $d),
            cs_transaction_grid([['date' => '2026-02-05', 'amount' => 60000.0]], 0, '2026-01-01', $d),
        ]);

        $c = cs_year_summary($contrib)['total'];
        $t = cs_year_summary($trans)['total'];

        $this->assertSame(0.0, $c['actual'], 'nothing can be laid on a month without a target');
        $this->assertSame(160000.0, $t['actual']);
        $this->assertSame(160000.0, $c['paid'], 'but the money is still counted');
        $this->assertSame($c['paid'], $t['paid'], 'the grand total must tie in every configuration');
    }

    public function testMergingPreservesUnallocatedMoneyAcrossMembers(): void
    {
        // cs_merge_grids() must carry each member's unallocated pot, or the group's
        // grand total quietly loses every save-what-you-can member's savings.
        $d = new DateTime('2026-05-15');
        $merged = cs_merge_grids([
            cs_calendar_grid(cs_build_schedule(0, 100000, 0, 0, '2026-01-01', null, $d), $d),
            cs_calendar_grid(cs_build_schedule(0, 60000, 0, 0, '2026-01-01', null, $d), $d),
        ]);

        $this->assertSame(160000.0, $merged['unallocated']);
    }

    public function testTheGroupTransactionsGridNeverMarksAMonthUnpaid(): void
    {
        // Found on the deployed page: the merged grid recomputed states using the
        // CONTRIBUTIONS vocabulary, so July and August rendered red with "0" on the
        // Group Statement of Transactions — a document whose own legend has no red
        // in it. A transactions grid records what arrived; the arrears judgement
        // lives on the other statement.
        $d = new DateTime('2026-08-13');
        $merged = cs_merge_grids([
            cs_transaction_grid([['date' => '2026-01-15', 'amount' => 200000.0]], 20000, '2026-01-01', $d),
            cs_transaction_grid([['date' => '2026-02-05', 'amount' => 85000.0]], 20000, '2026-01-01', $d),
        ], 'transactions');

        $seen = [];
        foreach ($merged['years'] as $cells) {
            foreach ($cells as $cell) {
                $seen[$cell['status']] = true;
            }
        }

        $this->assertArrayNotHasKey('unpaid', $seen);
        $this->assertArrayNotHasKey('partial', $seen);
        $this->assertArrayNotHasKey('paid', $seen);
        $this->assertArrayHasKey('received', $seen);
        $this->assertArrayHasKey('none', $seen);

        // July had no receipts but is elapsed: empty, not a debt.
        $this->assertSame('none', $merged['years'][2026][7]['status']);
        $this->assertSame(0.0, $merged['years'][2026][7]['allocated']);
    }

    public function testAnEmptyTransactionMonthRendersBlankNotZero(): void
    {
        $d = new DateTime('2026-08-13');
        $merged = cs_merge_grids([
            cs_transaction_grid([['date' => '2026-01-15', 'amount' => 200000.0]], 20000, '2026-01-01', $d),
        ], 'transactions');

        $html = $this->render(fn() => stmt_calendar($merged, false));
        $this->assertStringNotContainsString('vk-c-unpaid', $html);
        $this->assertStringContainsString('vk-c-none', $html);
    }

    public function testTheContributionsMergeStillUsesItsOwnStates(): void
    {
        // The default must not have shifted: a contributions grid still says unpaid.
        $d = new DateTime('2026-04-15');
        $merged = cs_merge_grids([
            cs_calendar_grid(cs_build_schedule(0, 0, 20000, 0, '2026-01-01', null, $d), $d),
        ]);

        $this->assertSame('unpaid', $merged['years'][2026][1]['status']);
    }

    public function testTheGroupPagePassesItsTypeToTheMerge(): void
    {
        $src = file_get_contents(__DIR__ . '/../../includes/group_statement.php');
        $this->assertStringContainsString('cs_merge_grids($grids, $vk_statement_type)', $src);
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Page wiring
    // -------------------------------------------------------------------------

    public function testBothStatementsAreRoutedToTheirOwnPage(): void
    {
        $roots = file_get_contents(__DIR__ . '/../../roots.php');
        $this->assertStringContainsString("'group_statement_contributions'", $roots);
        $this->assertStringContainsString("'group_statement_transactions'", $roots);
    }

    public function testTheTwoPagesShareOneRendererRatherThanACopy(): void
    {
        $dir = __DIR__ . '/../../app/constant/reports/';
        foreach (['group_statement_contributions.php', 'group_statement_transactions.php'] as $file) {
            $src = file_get_contents($dir . $file);
            $this->assertStringContainsString("require ROOT_DIR . '/includes/group_statement.php';", $src);
            $this->assertLessThan(20, substr_count($src, "\n"), "$file should stay thin");
        }
        $a = file_get_contents($dir . 'group_statement_contributions.php');
        $b = file_get_contents($dir . 'group_statement_transactions.php');
        $this->assertStringContainsString("\$vk_statement_type = 'contributions';", $a);
        $this->assertStringContainsString("\$vk_statement_type = 'transactions';", $b);
    }

    public function testHeadingsAreTheWordingTheGroupSpecified(): void
    {
        $src = file_get_contents(__DIR__ . '/../../includes/group_statement.php');
        $this->assertStringContainsString('Group Statement of Contributions as of', $src);
        $this->assertStringContainsString('Group Statement of Transactions as of', $src);
    }

    public function testBothViewsAreReachable(): void
    {
        $src = file_get_contents(__DIR__ . '/../../includes/group_statement.php');
        $this->assertStringContainsString("view=combined", $src);
        $this->assertStringContainsString("view=members", $src);
    }

    public function testThePageGateMatchesTheExistingGroupReports(): void
    {
        // Group-wide figures are already visible to members via the Group Financial
        // Ledger and Group Reports. This page must not widen that policy, and must
        // not narrow it either — it uses the same check.
        $src = file_get_contents(__DIR__ . '/../../includes/group_statement.php');
        $this->assertStringContainsString("if (!canView('vicoba_reports')) {", $src);
    }

    public function testMemberSchedulesAreBuiltInOneQueryNotOnePerMember(): void
    {
        // 327 members x cs_member_schedule() would be ~650 round trips for one page.
        $src = file_get_contents(__DIR__ . '/../../includes/group_statement.php');
        $this->assertStringContainsString('cs_group_schedules($pdo, $as_of)', $src);
        $this->assertStringNotContainsString('cs_member_schedule(', $src);
    }
}
