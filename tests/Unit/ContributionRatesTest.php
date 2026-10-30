<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The monthly contribution as it changed over time (includes/contribution_rates.php).
 *
 * UKUU's rule: 5,000 a month from the start of the group to May 2026, 10,000 a
 * month from June 2026. Every statement, ledger and grid must measure each month
 * against the amount in force that month, and a group without a history must
 * behave exactly as before.
 */
final class ContributionRatesTest extends TestCase
{
    private const UKUU = '[{"from":null,"amount":5000},{"from":"2026-06","amount":10000}]';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/contribution_standing.php';
        require_once __DIR__ . '/../../includes/api_financial_ledger.php';
    }

    private function ukuu(): array
    {
        return cr_monthly_rule(['contribution_rates' => self::UKUU, 'monthly_contribution' => '10000']);
    }

    // ── reading the rule ───────────────────────────────────────────────────────

    public function testWithoutAHistoryTheRuleIsTheFlatMonthlyAmount(): void
    {
        $this->assertSame(20000.0, cr_monthly_rule(['monthly_contribution' => '20000']));
        $this->assertSame(0.0, cr_monthly_rule([]));
        $this->assertSame(20000.0, cr_monthly_rule(['monthly_contribution' => '20000', 'contribution_rates' => '']));
        $this->assertSame(20000.0, cr_monthly_rule(['monthly_contribution' => '20000', 'contribution_rates' => 'not json']));
    }

    public function testAOneRowHistoryIsJustAFlatAmount(): void
    {
        $this->assertSame(7000.0, cr_monthly_rule(['contribution_rates' => '[{"from":null,"amount":7000}]', 'monthly_contribution' => '1']));
    }

    public function testUkuusHistoryGivesEachMonthItsOwnAmount(): void
    {
        $rule = $this->ukuu();
        $this->assertSame(5000.0, cr_rate_for($rule, 2024, 1));
        $this->assertSame(5000.0, cr_rate_for($rule, 2026, 5));
        $this->assertSame(10000.0, cr_rate_for($rule, 2026, 6));
        $this->assertSame(10000.0, cr_rate_for($rule, 2027, 3));
        $this->assertSame(10000.0, cr_rate_at($rule, new DateTime('2026-10-28')));
    }

    public function testRowsAreSortedAndTheFirstChangeCoversEarlierMonthsWhenThereIsNoStartRow(): void
    {
        $plan = cr_normalise([['from' => '2026-06', 'amount' => 10000], ['from' => '2025-01', 'amount' => 7000]]);
        $this->assertSame([null, '2025-01', '2026-06'], array_column($plan, 'from'));
        $this->assertSame(7000.0, cr_rate_for($plan, 2020, 1));
    }

    public function testSumAddsEachMonthAtItsOwnRate(): void
    {
        // Apr + May at 5,000, Jun + Jul at 10,000.
        $this->assertSame(30000.0, cr_sum($this->ukuu(), '2026-04', '2026-07'));
        $this->assertSame(0.0, cr_sum($this->ukuu(), '2026-07', '2026-04'));
        $this->assertSame(40000.0, cr_sum(20000.0, '2026-01-15', '2026-02-01'));
    }

    public function testValidationCatchesBadRows(): void
    {
        $this->assertSame([], cr_validate([['from' => '', 'amount' => '5000'], ['from' => '2026-06', 'amount' => '10000']]));
        $this->assertCount(1, cr_validate([['from' => '2026-13', 'amount' => '1']]));
        $this->assertCount(1, cr_validate([['from' => '2026-06', 'amount' => '-5']]));
        $this->assertCount(1, cr_validate([['from' => '2026-06', 'amount' => '1'], ['from' => '2026-06', 'amount' => '2']]));
        $this->assertCount(1, cr_validate([['from' => '', 'amount' => '1'], ['from' => '', 'amount' => '2']]));
        $this->assertStringContainsString('Mstari', cr_validate([['from' => 'x', 'amount' => '1']], true)[0]);
    }

    // ── the standing module ────────────────────────────────────────────────────

    public function testExpectedToDateSumsEachMonthsRate(): void
    {
        // Member anchored Apr 2026, as of Aug 2026: Apr, May at 5,000 + Jun, Jul, Aug at 10,000.
        $this->assertSame(40000.0, cs_expected_to_date($this->ukuu(), '2026-04-01', new DateTime('2026-08-20')));
        $this->assertSame(0.0, cs_expected_to_date($this->ukuu(), null, new DateTime('2026-08-20')));
        // Flat behaviour unchanged.
        $this->assertSame(100000.0, cs_expected_to_date(20000.0, '2026-04-01', new DateTime('2026-08-20')));
    }

    public function testTheScheduleLaysMoneyAtEachMonthsRate(): void
    {
        // 25,000 from Apr 2026: Apr 5,000, May 5,000, Jun 10,000, then 5,000 toward Jul.
        $s = cs_build_schedule(0, 25000, $this->ukuu(), 0, '2026-04-01', null, new DateTime('2026-07-20'));
        $this->assertSame([5000.0, 5000.0, 10000.0, 5000.0], array_column($s['distribution'], 'amount'));
        $this->assertSame([5000.0, 5000.0, 10000.0, 10000.0], array_column($s['distribution'], 'target'));
        $this->assertSame(['paid', 'paid', 'paid', 'partial'], array_column($s['distribution'], 'status'));
        $this->assertSame(3, $s['total_months_covered']);
        $this->assertSame(10000.0, $s['monthly_amt'], 'monthly_amt is the rate in force as of the statement date');
        $this->assertSame($this->ukuu(), $s['rule']);
    }

    public function testMoneyPaidAheadIsLaidAtTheFutureRate(): void
    {
        // 40,000 from May 2026, as of May: May 5,000, Jun/Jul/Aug... 10,000 each -> covers May-Aug with 5,000 over.
        $s = cs_build_schedule(0, 40000, $this->ukuu(), 0, '2026-05-01', null, new DateTime('2026-05-20'));
        $this->assertSame(4, $s['total_months_covered']);
        $this->assertSame(5000.0, $s['advance']);
        $this->assertSame(10000.0, $s['next_target']);

        // The calendar lays the 5,000 remainder on September, paid ahead (not owed yet).
        $g = cs_calendar_grid($s, new DateTime('2026-05-20'));
        $this->assertSame(5000.0, $g['years'][2026][9]['allocated']);
        $this->assertSame('advance', $g['years'][2026][9]['status']);
        $this->assertSame(0.0, $g['unallocated']);
    }

    public function testAFlatScheduleIsUnchanged(): void
    {
        $s = cs_build_schedule(0, 50000, 20000.0, 0, '2026-01-01', null, new DateTime('2026-03-10'));
        $this->assertSame([20000.0, 20000.0, 10000.0], array_column($s['distribution'], 'amount'));
        $this->assertSame(2, $s['total_months_covered']);
        $this->assertSame(20000.0, $s['monthly_amt']);
        $this->assertSame(20000.0, $s['next_target']);
    }

    public function testTheTransactionGridTargetsFollowTheRate(): void
    {
        $g = cs_transaction_grid([['date' => '2026-05-03', 'amount' => 5000]], $this->ukuu(), '2026-05-01', new DateTime('2026-07-10'));
        $this->assertSame(5000.0, $g['years'][2026][5]['target']);
        $this->assertSame(10000.0, $g['years'][2026][6]['target']);
        $this->assertSame(10000.0, $g['years'][2026][7]['target']);
        $this->assertSame(0.0, $g['years'][2026][8]['target'], 'future months are never owed');
    }

    public function testTheLedgerTargetsEachColumnAtItsRate(): void
    {
        // Columns May, Jun, Jul all owed; 20,000 paid.
        $calc = vk_api_ledger_member_calc(0, 20000, 0, 0, 10000, 0, [true, true, true], 3, [5000.0, 10000.0, 10000.0]);
        $this->assertSame(25000.0, $calc['target_amt']);
        $this->assertSame([5000.0, 10000.0, 5000.0], $calc['monthly_by_month']);
        $this->assertSame(-5000.0, $calc['surplus_deficit']);

        // Without per-column rates the old flat arithmetic is unchanged.
        $flat = vk_api_ledger_member_calc(0, 20000, 0, 0, 10000, 0, [true, true, true], 3);
        $this->assertSame(30000.0, $flat['target_amt']);
    }

    // ── wiring ─────────────────────────────────────────────────────────────────

    public function testEveryScreenPassesTheRuleNotTheFlatAmount(): void
    {
        $root = __DIR__ . '/../../';
        $expect = [
            'api/v1/my_transactions.php'                 => "cs_transaction_grid(\$events, \$schedule['rule']",
            // cs_schedule_expected() reads the schedule's own 'rule' (see contribution_standing.php).
            'api/v1/contributions_standing.php'          => 'cs_schedule_expected($schedule, $settings)',
            'api/v1/member-statement_detail.php'         => 'cs_schedule_expected($sched, $settings, $asOf)',
            'app/constant/reports/member_statement.php'  => 'cs_schedule_expected($sched, $settings_raw, $as_of)',
            'includes/contribution_standing.php'         => "cs_expected_to_date(\$schedule['rule'] ?? 0.0",
            'api/v1/member-transactions_detail.php'      => 'cr_monthly_rule($settings),',
            'app/constant/reports/member_transactions.php' => 'cr_monthly_rule($settings_raw),',
            'includes/group_statement.php'               => 'cr_monthly_rule($settings_raw)',
            'includes/api_reports.php'                   => 'cr_monthly_rule($settings)',
            'app/bms/customer/manage_contributions.php'  => "vk_contribution_cell_status(\$paid, \$col['target'])",
            'app/bms/customer/financial_ledger.php'      => '$monthly_rule = cr_monthly_rule($settings);',
            'includes/api_financial_ledger.php'          => '$monthlyRule            = cr_monthly_rule($settings);',
        ];
        foreach ($expect as $file => $needle) {
            $this->assertStringContainsString($needle, (string) file_get_contents($root . $file), $file);
        }
    }

    public function testSavingTheFormStoresTheHistoryAndSyncsTheMonthlyAmount(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../actions/save_group_settings.php');
        $this->assertStringContainsString("cr_validate(\$vk_rows, \$lang === 'sw')", $src);
        $this->assertStringContainsString("\$stmt->execute(['contribution_rates', \$vk_rates_json]);", $src);
        $this->assertStringContainsString('cr_sync_current($pdo);', $src);
        // Errors stop the save before anything is written.
        $this->assertLessThan(strpos($src, 'REPLACE INTO group_settings'), strpos($src, 'if ($vk_errors) {'));
    }
}
