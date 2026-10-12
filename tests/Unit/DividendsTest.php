<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Year-end dividends (includes/dividends.php): what counts as surplus, how it is
 * shared out by share capital, and the guarantees on the run itself.
 */
class DividendsTest extends TestCase
{
    private static string $svc;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/dividends.php';
        require_once __DIR__ . '/../../includes/finance.php';
        self::$svc = file_get_contents(__DIR__ . '/../../includes/dividends.php');
    }

    private function body(string $function): string
    {
        $start = strpos(self::$svc, "function $function(");
        $this->assertNotFalse($start, "$function() must exist");
        $next = strpos(self::$svc, "if (!function_exists(", $start);
        return substr(self::$svc, $start, ($next === false ? strlen(self::$svc) : $next) - $start);
    }

    // ── dv_settings / dv_current_year ────────────────────────────────────────
    public function testUnsetSettingsShareEverythingOverACalendarYear(): void
    {
        $this->assertSame(['distribute_pct' => 100.0, 'year_start_month' => 1], dv_settings([]));
    }

    public function testSettingsAreClamped(): void
    {
        $this->assertSame(100.0, dv_settings(['dividend_distribute_pct' => '250'])['distribute_pct']);
        $this->assertSame(0.0, dv_settings(['dividend_distribute_pct' => '-5'])['distribute_pct']);
        $this->assertSame(1, dv_settings(['financial_year_start_month' => '13'])['year_start_month']);
        $this->assertSame(7, dv_settings(['financial_year_start_month' => '7'])['year_start_month']);
    }

    public function testTheFinancialYearFollowsItsStartMonth(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31'], dv_current_year(1, '2026-10-12'));
        $this->assertSame(['2026-07-01', '2027-06-30'], dv_current_year(7, '2026-10-12'));
        $this->assertSame(['2025-11-01', '2026-10-31'], dv_current_year(11, '2026-10-12')); // year began last November
    }

    public function testPeriodValidation(): void
    {
        $this->assertTrue(dv_valid_period('2026-01-01', '2026-12-31'));
        $this->assertTrue(dv_valid_period('2026-05-05', '2026-05-05'));
        $this->assertFalse(dv_valid_period('2026-12-31', '2026-01-01'));
        $this->assertFalse(dv_valid_period('2026-02-30', '2026-12-31'));
        $this->assertFalse(dv_valid_period('', '2026-12-31'));
    }

    // ── dv_surplus ───────────────────────────────────────────────────────────
    public function testSurplusIsIncomeLessRunningCosts(): void
    {
        $f = dv_surplus(['interest' => 72000, 'penalties' => 5600, 'fines' => 10000, 'expenses' => 17600], 100);
        $this->assertSame(87600.0, $f['income']);
        $this->assertSame(70000.0, $f['surplus']);
        $this->assertSame(70000.0, $f['distributable']);
        $this->assertSame(0.0, $f['reserve']);
    }

    public function testAReserveIsHeldBack(): void
    {
        $f = dv_surplus(['interest' => 100000, 'penalties' => 0, 'fines' => 0, 'expenses' => 0], 80);
        $this->assertSame(80000.0, $f['distributable']);
        $this->assertSame(20000.0, $f['reserve']);
    }

    public function testALossIsNotSharedOut(): void
    {
        $f = dv_surplus(['interest' => 5000, 'penalties' => 0, 'fines' => 0, 'expenses' => 20000], 100);
        $this->assertSame(-15000.0, $f['surplus']);
        $this->assertSame(0.0, $f['distributable']);
        $this->assertSame(0.0, $f['reserve']);
    }

    // ── dv_split ─────────────────────────────────────────────────────────────
    public function testTheSplitFollowsShareCapital(): void
    {
        $this->assertSame([1 => 50000.0, 2 => 30000.0, 3 => 20000.0], dv_split([1 => 500000, 2 => 300000, 3 => 200000], 100000));
    }

    public function testThePartsAlwaysAddUpToTheAmountExactly(): void
    {
        foreach ([[100000, [1 => 1, 2 => 1, 3 => 1]], [70000.01, [5 => 300000, 9 => 301000, 2 => 300000]], [0.05, [1 => 10, 2 => 10, 3 => 10]]] as [$amount, $shares]) {
            $split = dv_split($shares, $amount);
            $this->assertSame((int) round($amount * 100), (int) round(array_sum($split) * 100), "TZS $amount");
        }
        // 100,000 over three equal holders: 33,333.34 + 33,333.33 + 33,333.33.
        $this->assertSame([1 => 33333.34, 2 => 33333.33, 3 => 33333.33], dv_split([1 => 1, 2 => 1, 3 => 1], 100000));
    }

    public function testTheSplitIsTheSameEveryTimeItIsWorkedOut(): void
    {
        $shares = [9 => 100, 3 => 100, 7 => 100];
        $forward  = dv_split($shares, 100);
        $backward = dv_split(array_reverse($shares, true), 100);
        ksort($forward);
        ksort($backward);
        $this->assertSame($forward, $backward, 'the order members are listed in must not change who gets the spare cent');
        // The spare cent goes to the lowest member id among equals.
        $this->assertSame(33.34, dv_split($shares, 100)[3]);
    }

    public function testMembersWithNoSharesGetNoLine(): void
    {
        $this->assertSame([1 => 1000.0], dv_split([1 => 50000, 2 => 0, 3 => -10], 1000));
        $this->assertSame([], dv_split([1 => 0, 2 => 0], 1000));
        $this->assertSame([], dv_split([1 => 50000], 0));
    }

    // ── what is, and is not, in a dividend ───────────────────────────────────
    public function testSurplusCountsOnlyWhatTheGroupEarnedAndSpentRunningItself(): void
    {
        $totals = $this->body('dv_totals');
        $this->assertStringContainsString('SUM(interest_part)', $totals);
        $this->assertStringContainsString('SUM(penalty_part)', $totals);
        $this->assertStringContainsString("FROM fines WHERE status = 'paid'", $totals);
        $this->assertStringContainsString('FROM general_expenses', $totals);
        $this->assertStringContainsString('FROM petty_cash_vouchers', $totals);
        // Welfare is the Social Fund's cost, and capital is not income.
        foreach (['death_expenses', 'member_payouts', 'principal_part', 'contributions'] as $never) {
            $this->assertStringNotContainsString($never, $totals, "$never must not touch the surplus");
        }
    }

    public function testWelfareIsChargedToTheSocialFund(): void
    {
        $src = file_get_contents(__DIR__ . '/../../includes/fund_buckets.php');
        $start = strpos($src, 'function fb_social_fund_position(');
        $body = substr($src, $start, strpos($src, 'function fb_type_labels(') - $start);
        $this->assertStringContainsString("FROM death_expenses WHERE status = 'paid'", $body);
        $this->assertStringContainsString("FROM member_payouts WHERE status = 'paid'", $body);
    }

    public function testSharesAreCountedOnThePeriodEndDateFromShareTypesOnly(): void
    {
        $body = $this->body('dv_share_capital_as_at');
        $this->assertStringContainsString('fb_share_types(', $body);
        $this->assertStringContainsString('co.contribution_date <= ?', $body);
    }

    // ── the run ──────────────────────────────────────────────────────────────
    public function testTheSameIncomeCannotBeSharedOutTwice(): void
    {
        $body = $this->body('dv_confirm');
        $this->assertStringContainsString("status <> 'cancelled' AND period_from <= ? AND period_to >= ?", $body);
        $this->assertStringContainsString("\$to > date('Y-m-d')", $body);
        // Figures come from the books, not from the caller.
        $this->assertStringContainsString('dv_preview($pdo, $from, $to)', $body);
    }

    public function testARunIsDistributedOnceAndADistributedRunIsFinal(): void
    {
        $this->assertStringContainsString("status = 'confirmed' FOR UPDATE", $this->body('dv_distribute'));
        $this->assertStringContainsString("WHERE id = ? AND status = 'confirmed'", $this->body('dv_cancel'));
    }

    public function testADividendTurnedIntoSharesIsNotCountedTwiceInTheFund(): void
    {
        // contributions 1,000,000 + 70,000 dividend-as-shares; 70,000 distributed.
        $this->assertSame(1000000.0, fundBalanceFromTotals(1070000, 0, 0, 0, 0, 0, 0, 0, 70000));
        $src = file_get_contents(__DIR__ . '/../../includes/finance.php');
        $this->assertStringContainsString("FROM vicoba_dividend_lines l JOIN vicoba_dividend_runs r ON r.id = l.run_id WHERE r.status = 'distributed'", $src);
    }

    // ── access ───────────────────────────────────────────────────────────────
    public function testEachStepNeedsItsOwnPermissionAndMembersAreNotGrantedThePage(): void
    {
        $action = file_get_contents(__DIR__ . '/../../actions/dividend_action.php');
        $this->assertLessThan(strpos($action, 'switch ($action)'), strpos($action, 'includes/require_csrf.php'));
        $this->assertLessThan(strpos($action, 'switch ($action)'), strpos($action, 'includes/require_auth.php'));
        $this->assertMatchesRegularExpression("/case 'confirm':\s*if \(!canCreate\('dividends'\)\)/", $action);
        $this->assertMatchesRegularExpression("/case 'distribute':\s*if \(!canApprove\('dividends'\)\)/", $action);
        $this->assertMatchesRegularExpression("/case 'cancel':\s*if \(!canDelete\('dividends'\)\)/", $action);
        // No figure is accepted from the request.
        $this->assertStringNotContainsString("\$_POST['amount']", $action);
        $this->assertStringNotContainsString("\$_POST['distributable']", $action);

        $page = file_get_contents(__DIR__ . '/../../app/constant/accounts/dividends.php');
        $this->assertStringContainsString("requireViewPermission('dividends')", $page);

        require_once __DIR__ . '/../../includes/role_grants.php';
        $this->assertNull(vk_role_grants('view', 'dividends'));
        $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('operational', 'dividends'));
    }
}
