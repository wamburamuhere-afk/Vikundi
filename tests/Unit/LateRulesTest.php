<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The late-payment rule (includes/late_rules.php), with UKUU's numbers:
 * 10,000 a month, pay month M by the 9th of M+1 or get one 5,000 fine, and
 * Dormant if the contribution or the fine is still unpaid after the 15th.
 * The rule here starts in November 2026.
 */
final class LateRulesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/late_rules.php';
    }

    private function cfg(array $over = []): array
    {
        return late_settings($over + [
            'late_rules_enabled'  => '1',
            'late_rules_start_ym' => '2026-11',
            'late_fine_amount'    => '5000',
            'late_fine_day'       => '9',
            'late_dormant_day'    => '15',
        ]);
    }

    private function judge(array $payments, string $today, array $fines = [], $rule = 10000.0, array $cfg = null, string $from = '2026-11'): array
    {
        $p = array_map(fn($x) => ['date' => $x[0], 'amount' => $x[1]], $payments);
        return late_judge_member($p, $rule, $from, $fines, $cfg ?? $this->cfg(), new DateTime($today));
    }

    // ── settings and dates ────────────────────────────────────────────────────

    public function testSettingsDefaultToUkuusNumbersAndStayOffUntilSwitchedOn(): void
    {
        $s = late_settings([]);
        $this->assertFalse($s['enabled']);
        $this->assertNull($s['start_ym']);
        $this->assertSame(5000.0, $s['fine']);
        $this->assertSame(9, $s['fine_day']);
        $this->assertSame(15, $s['dormant_day']);
        $this->assertNull(late_settings(['late_rules_start_ym' => '2026-13'])['start_ym']);
    }

    public function testTheDeadlineIsInTheFollowingMonthAndClamped(): void
    {
        $this->assertSame('2026-12-09', late_deadline('2026-11', 9));
        $this->assertSame('2027-01-15', late_deadline('2026-12', 15));
        $this->assertSame('2027-02-28', late_deadline('2027-01', 31));
        $this->assertSame('2026-10', late_add_months('2026-11', -1));
        $this->assertSame('2027-01', late_add_months('2026-12', 1));
    }

    // ── fines ─────────────────────────────────────────────────────────────────

    public function testPaidOnTimeMeansNoFine(): void
    {
        $j = $this->judge([['2026-11-20', 10000]], '2026-12-20');
        $this->assertSame([], $j['fine_months']);
        $this->assertFalse($j['dormant']);
    }

    public function testTheNinthItselfIsStillOnTime(): void
    {
        $this->assertSame([], $this->judge([['2026-12-09', 10000]], '2026-12-10')['fine_months']);
    }

    public function testNothingIsJudgedUntilTheDayAfterTheDeadline(): void
    {
        $this->assertSame([], $this->judge([], '2026-12-09')['fine_months']);
        $this->assertSame(['2026-11'], $this->judge([], '2026-12-10')['fine_months']);
    }

    public function testPayingAfterTheNinthStillEarnsTheFine(): void
    {
        $j = $this->judge([['2026-12-12', 10000]], '2026-12-12');
        $this->assertSame(['2026-11'], $j['fine_months']);
    }

    public function testAPartPaymentIsLate(): void
    {
        $this->assertSame(['2026-11'], $this->judge([['2026-11-20', 6000]], '2026-12-10')['fine_months']);
    }

    public function testOneFinePerMonthNeverTwice(): void
    {
        $j = $this->judge([], '2027-01-20', ['2026-11' => 'pending']);
        $this->assertSame(['2026-12'], $j['fine_months'], 'November is already fined; only December is new');
    }

    public function testAZeroFineMeansNoFinesButDormancyStillApplies(): void
    {
        $j = $this->judge([], '2026-12-16', [], 10000.0, $this->cfg(['late_fine_amount' => '0']));
        $this->assertSame([], $j['fine_months']);
        $this->assertTrue($j['dormant']);
    }

    // ── dormancy ──────────────────────────────────────────────────────────────

    public function testUnpaidAfterTheFifteenthMeansDormant(): void
    {
        $this->assertFalse($this->judge([], '2026-12-15')['dormant'], 'the 15th is still allowed');
        $j = $this->judge([], '2026-12-16');
        $this->assertTrue($j['dormant']);
        $this->assertSame(['2026-11'], $j['reasons']);
    }

    public function testContributionPaidButFinePendingIsStillDormant(): void
    {
        $j = $this->judge([['2026-12-12', 10000]], '2026-12-16', ['2026-11' => 'pending']);
        $this->assertTrue($j['dormant']);
    }

    public function testContributionAndFinePaidByTheFifteenthStaysActive(): void
    {
        $j = $this->judge([['2026-12-12', 10000]], '2026-12-16', ['2026-11' => 'paid']);
        $this->assertFalse($j['dormant']);
        $this->assertFalse($this->judge([['2026-12-12', 10000]], '2026-12-16', ['2026-11' => 'waived'])['dormant']);
    }

    public function testPayingEverythingLaterMakesTheMemberActiveAgain(): void
    {
        // Dormant on 16 Dec; on 3 Jan November's 10,000 arrives and the fine is marked paid.
        $j = $this->judge([['2027-01-03', 10000]], '2027-01-05', ['2026-11' => 'paid']);
        $this->assertFalse($j['dormant']);
    }

    // ── what counts ───────────────────────────────────────────────────────────

    public function testMoneyPaidAheadCarriesForward(): void
    {
        $j = $this->judge([['2026-11-05', 30000]], '2027-02-20');
        $this->assertSame([], $j['fine_months'], 'Nov, Dec and Jan are covered by one payment');
        $this->assertFalse($j['dormant']);
    }

    public function testHistoryBeforeTheRuleStartsIsNeverJudged(): void
    {
        // Nothing paid for months before November: no fine for them, only for November.
        $j = $this->judge([], '2026-12-10');
        $this->assertSame(['2026-11'], $j['fine_months']);
        $this->assertArrayNotHasKey('2026-10', $j['months']);
    }

    public function testAPunctualMemberPayingEarlyInTheStartMonthIsNotFined(): void
    {
        // October paid on 3 Oct, November paid early on 5 Nov (before the 9 Nov
        // deadline for October): the 5 Nov money is ahead of October and carries in.
        $j = $this->judge([['2026-10-03', 10000], ['2026-11-05', 10000]], '2026-12-10');
        $this->assertSame([], $j['fine_months']);
        $this->assertSame(10000.0, $j['carried_in']);
    }

    public function testALateOctoberPaymentDoesNotPayForNovember(): void
    {
        // September paid; October paid late on 5 Nov; nothing for November.
        $j = $this->judge([['2026-09-03', 10000], ['2026-11-05', 10000]], '2026-12-10');
        $this->assertSame(['2026-11'], $j['fine_months']);
        $this->assertSame(0.0, $j['carried_in']);
    }

    public function testArrearsFromBeforeTheStartAreForgiven(): void
    {
        // Paid only once in 2026, long before the rule: no carry, no debt. November is judged alone.
        $j = $this->judge([['2026-02-03', 10000], ['2026-11-20', 10000]], '2026-12-16');
        $this->assertSame([], $j['fine_months']);
        $this->assertFalse($j['dormant']);
    }

    public function testAMemberWhoJoinedLaterIsJudgedFromTheirJoinMonth(): void
    {
        $j = $this->judge([], '2027-01-10', [], 10000.0, null, '2026-12');
        $this->assertSame(['2026-12'], $j['fine_months']);
    }

    public function testEachMonthIsMeasuredAtItsOwnRate(): void
    {
        // Rule started May 2026: 5,000 for May, 10,000 from June. Paid 5,000 each month.
        $rule = cr_monthly_rule(['contribution_rates' => '[{"from":null,"amount":5000},{"from":"2026-06","amount":10000}]']);
        $cfg = $this->cfg(['late_rules_start_ym' => '2026-05']);
        $j = late_judge_member(
            [['date' => '2026-05-10', 'amount' => 5000], ['date' => '2026-06-10', 'amount' => 5000]],
            $rule, '2026-05', [], $cfg, new DateTime('2026-07-10')
        );
        $this->assertSame(['2026-06'], $j['fine_months'], 'May is paid at 5,000; June needed 10,000');
    }

    // ── wiring ────────────────────────────────────────────────────────────────

    public function testTheRunCountsPendingPaymentsAndOnlyMonthlyContributions(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../includes/late_rules.php');
        $this->assertStringContainsString("status IN ('pending','reviewed','approved','confirmed','')", $src);
        $this->assertStringContainsString("(contribution_type = 'monthly' OR contribution_type = '' OR contribution_type IS NULL)", $src);
        // Leader-made dormancy is never touched; only the rule's own is reversed.
        $this->assertStringContainsString("(status = 'active' OR (status = 'dormant' AND late_rule_dormant = 1))", $src);
        $this->assertStringContainsString('INSERT IGNORE INTO fines', $src);
    }

    public function testTheDailyRunAppliesTheRuleAndTheOldSweepStepsAside(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../actions/auto_terminate_members.php');
        $this->assertStringContainsString('$r = late_run($pdo);', $src);
        $this->assertStringContainsString("if ((\$settings['late_rules_enabled'] ?? '0') === '1') {", $src);
        $this->assertStringContainsString('vk_run_daily_contribution_rules($pdo);', $src);
    }

    public function testTheMigrationIsRegisteredAndOneFinePerMonthIsEnforcedByTheDatabase(): void
    {
        $this->assertStringContainsString("'add_late_payment_rules.php'", (string) file_get_contents(__DIR__ . '/../../database/migrate.php'));
        $mig = (string) file_get_contents(__DIR__ . '/../../database/add_late_payment_rules.php');
        $this->assertStringContainsString('ADD UNIQUE KEY `uniq_late_fine` (`customer_id`, `late_period`)', $mig);
        $schema = (string) file_get_contents(__DIR__ . '/../../database/schema_sync.sql');
        $this->assertStringContainsString('UNIQUE KEY `uniq_late_fine` (`customer_id`,`late_period`)', $schema);
        $this->assertStringContainsString('`late_rule_dormant` tinyint(1) NOT NULL DEFAULT', $schema);
    }

    public function testNoFunctionNameIsSharedWithAnotherFile(): void
    {
        // The first version used the lr_ prefix, which the loan rules already own
        // (lr_add_months among them). Behind function_exists() guards, whichever file
        // loaded first silently won, and loan schedules came out wrong.
        $root = dirname(__DIR__, 2);
        $mine = ['includes/late_rules.php', 'includes/contribution_rates.php'];
        $defs = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (!str_ends_with($rel, '.php') || preg_match('#^(vendor|tests|TCPDF|docs|node_modules)/#', $rel)) {
                continue;
            }
            preg_match_all('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/mi', (string) file_get_contents($f->getPathname()), $m);
            foreach ($m[1] as $fn) {
                $defs[strtolower($fn)][] = $rel;
            }
        }
        foreach ($mine as $file) {
            preg_match_all('/^\s*function\s+([a-z_][a-z0-9_]*)\s*\(/mi', (string) file_get_contents("$root/$file"), $m);
            foreach ($m[1] as $fn) {
                $this->assertSame([$file], array_values(array_unique($defs[strtolower($fn)])), "$fn() is also defined elsewhere");
            }
        }
    }

    public function testTheSaveRefusesADormantDayBeforeTheFineDay(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../actions/save_group_settings.php');
        $this->assertStringContainsString('$vk_dd <= $vk_fd', $src);
        $this->assertStringContainsString("\$_POST['late_rules_start_ym'] = date('Y-m');", $src);
    }
}
