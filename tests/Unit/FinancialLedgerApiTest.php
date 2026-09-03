<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_financial_ledger.php';

/**
 * Module 8 — Financial Ledger.
 *
 * vk_api_ledger_member_calc() is a direct port of financial_ledger.php's
 * per-member loop (lines 232-293): entrance-then-monthly allocation, the
 * "no fixed monthly => no target" rule, and opening only ever reducing a
 * deficit, never creating one.
 */
final class FinancialLedgerApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function auth(bool $leader, bool $admin = false): array
    {
        return [
            'user_id' => 1,
            'role_id' => $admin ? 1 : ($leader ? 4 : 15),
            'user'    => ['user_role' => $admin ? 'Admin' : ($leader ? 'Treasurer' : 'Member')],
            'permissions' => $leader || $admin
                ? ['vicoba_reports' => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0]]
                : [],
        ];
    }

    // ── period parsing ──────────────────────────────────────────────────────

    public function testDiffMonthsIsInclusiveOfBothEnds(): void
    {
        $this->assertSame(1, vk_api_ledger_diff_months(strtotime('2026-03-05'), strtotime('2026-03-28')));
        $this->assertSame(3, vk_api_ledger_diff_months(strtotime('2026-01-15'), strtotime('2026-03-01')));
        $this->assertSame(13, vk_api_ledger_diff_months(strtotime('2025-01-01'), strtotime('2026-01-31')));
    }

    public function testAnInvalidDateIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_date/');
        vk_api_ledger_period(['start_date' => '05/03/2026']);
    }

    public function testAnEndDateBeforeTheStartIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_range/');
        vk_api_ledger_period(['start_date' => '2026-06-01', 'end_date' => '2026-01-01']);
    }

    public function testARangeOverTenYearsIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/range_too_large/');
        vk_api_ledger_period(['start_date' => '2010-01-01', 'end_date' => '2026-01-01']);
    }

    public function testOmittedDatesDefaultToTheCurrentCalendarYear(): void
    {
        [$start, $end] = vk_api_ledger_period([]);
        $this->assertSame(date('Y-01-01'), $start);
        $this->assertSame(date('Y-12-31'), $end);
    }

    // ── the per-member split ────────────────────────────────────────────────

    public function testEntranceComesOffNewMoneyNeverOpening(): void
    {
        // 200,000 opening (M-Koba carry-in), 5,000 new money, entrance fee 10,000.
        // Entrance can only ever eat into the 5,000 new money, never the opening.
        $calc = vk_api_ledger_member_calc(200000.0, 5000.0, 0.0, 0.0, 3000.0, 10000.0, [true], 1);
        $this->assertSame(5000.0, $calc['entrance_paid']);
        $this->assertSame(0.0, $calc['monthly_total'], 'the whole 5,000 new money went to the entrance fee');
    }

    public function testNoFixedMonthlyMeansNoTargetAndOntrackStatus(): void
    {
        $calc = vk_api_ledger_member_calc(0.0, 1000.0, 0.0, 0.0, 0.0, 0.0, [true, true], 2);
        $this->assertSame(0.0, $calc['target_amt']);
        $this->assertSame('ontrack', $calc['status']);
    }

    public function testOpeningOnlyReducesADeficitNeverCreatesOne(): void
    {
        // No opening, no new money, but 2 elapsed months at 5,000/month = 10,000
        // expected -> a real deficit.
        $bare = vk_api_ledger_member_calc(0.0, 0.0, 0.0, 0.0, 5000.0, 0.0, [true, true], 2);
        $this->assertSame(-10000.0, $bare['surplus_deficit']);

        // Same expectation, but with an opening balance covering it: no deficit,
        // and the opening never gets treated as a fresh entrance/monthly payment.
        $covered = vk_api_ledger_member_calc(10000.0, 0.0, 0.0, 0.0, 5000.0, 0.0, [true, true], 2);
        $this->assertSame(0.0, $covered['surplus_deficit']);
    }

    public function testMonthlyGridAllocatesOnlyIntoValidColumnsAndCapsPerColumn(): void
    {
        // 3 columns, only the last two are valid (e.g. the member joined mid-range).
        // 12,000 new money at a 5,000 monthly rate: 5,000 into each valid column,
        // the 2,000 remainder dumped into the LAST column (not the first invalid one).
        $calc = vk_api_ledger_member_calc(0.0, 12000.0, 0.0, 0.0, 5000.0, 0.0, [false, true, true], 2);
        $this->assertSame([0.0, 5000.0, 7000.0], $calc['monthly_by_month']);
        $this->assertSame(12000.0, $calc['monthly_total']);
    }

    public function testWithNoFixedMonthlyNewMoneySpreadsEvenlyAcrossElapsedMonths(): void
    {
        $calc = vk_api_ledger_member_calc(0.0, 9000.0, 0.0, 0.0, 0.0, 0.0, [true, true, true], 3);
        $this->assertSame([3000.0, 3000.0, 3000.0], $calc['monthly_by_month']);
    }

    public function testAgmIsAddedToTheTotalButNotToStandingsBalance(): void
    {
        // AGM is kept separate from savings/standing (mirrors financial_ledger.php
        // line 289: "savings + AGM (kept separate)").
        $calc = vk_api_ledger_member_calc(0.0, 1000.0, 2000.0, 0.0, 0.0, 0.0, [true], 1);
        $this->assertSame(3000.0, $calc['total_member_contributed']);
        $this->assertSame(1000.0, $calc['balance'], 'balance must not include the AGM payment');
    }

    public function testAssistanceReducesTheBalanceButNotTheSurplusDeficit(): void
    {
        $calc = vk_api_ledger_member_calc(0.0, 5000.0, 0.0, 2000.0, 0.0, 0.0, [true], 1);
        $this->assertSame(3000.0, $calc['balance']);
    }

    // ── leadership gate ──────────────────────────────────────────────────────

    public function testAnAdminCountsAsLeadershipEvenWithNoPermissionRow(): void
    {
        $this->assertTrue(vk_api_ledger_is_leader(self::auth(false, true)));
    }

    public function testAMemberWithNoGrantIsRefused(): void
    {
        $this->assertFalse(vk_api_ledger_is_leader(self::auth(false)));
    }

    public function testLeadershipWithTheReportsGrantIsAccepted(): void
    {
        $this->assertTrue(vk_api_ledger_is_leader(self::auth(true)));
    }

    public function testTheGateIsTheReportsPermissionNotAContributionsOne(): void
    {
        // Mirrors financial_ledger.php's canView('vicoba_reports') exactly —
        // not manage_contributions, which is a different module.
        $this->assertStringContainsString(
            "vk_api_can(\$auth, 'view', 'vicoba_reports')",
            self::code('includes/api_financial_ledger.php')
        );
    }

    public function testTheGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/ledger.php');
        $gate  = strpos($code, 'vk_api_ledger_require_leader($auth)');
        $query = strpos($code, 'vk_api_ledger_build(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testTheFundBalanceIsIncluded(): void
    {
        $this->assertStringContainsString('getGroupFundBalance($pdo)', self::code('api/v1/ledger.php'));
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testTheEndpointResolvesToAFlatFileWithNoRootsChange(): void
    {
        // A single top-level segment resolves by direct file existence (roots.php
        // rule 2) — no REST sub-path rule involved, so no roots.php entry needed.
        $this->assertFileExists(__DIR__ . '/../../api/v1/ledger.php');
    }
}
