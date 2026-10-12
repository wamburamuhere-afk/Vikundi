<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The loan arithmetic (includes/loan_rules.php): what a member may borrow, the
 * repayment schedule and the late penalty — all from the group's own loan-type
 * rules, none of it hardcoded. Also pins the access control on the endpoint
 * that changes those rules.
 */
class LoanRulesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/loan_rules.php';
    }

    private function valid(array $over = []): array
    {
        return array_merge([
            'name' => 'Development Loan', 'description' => '', 'interest_rate' => '2', 'interest_type' => 'flat',
            'max_term_months' => '12', 'max_share_multiple' => '3', 'max_amount' => '',
            'guarantors_required' => '2', 'penalty_type' => 'percent', 'penalty_value' => '5', 'grace_days' => '3',
        ], $over);
    }

    // ── lr_validate_type ─────────────────────────────────────────────────────
    public function testAValidTypeIsCleanedAndTyped(): void
    {
        [$t, $errors] = lr_validate_type($this->valid());
        $this->assertSame([], $errors);
        $this->assertSame('Development Loan', $t['name']);
        $this->assertSame(2.0, $t['interest_rate']);
        $this->assertSame('flat', $t['interest_type']);
        $this->assertSame(12, $t['max_term_months']);
        $this->assertSame(3.0, $t['max_share_multiple']);
        $this->assertNull($t['max_amount']);
        $this->assertSame(2, $t['guarantors_required']);
        $this->assertSame(5.0, $t['penalty_value']);
        $this->assertSame(3, $t['grace_days']);
    }

    public function testBlankLimitsMeanNoLimitNotZeroShillings(): void
    {
        [$t, $errors] = lr_validate_type($this->valid(['max_share_multiple' => '', 'max_amount' => '0']));
        $this->assertSame([], $errors);
        $this->assertSame(0.0, $t['max_share_multiple']);
        $this->assertNull($t['max_amount']);
        $this->assertNull(lr_max_loan(500000, $t));
    }

    public function testAmountsMayCarryThousandsSeparators(): void
    {
        [$t, $errors] = lr_validate_type($this->valid(['max_amount' => '1,500,000']));
        $this->assertSame([], $errors);
        $this->assertSame(1500000.0, $t['max_amount']);
    }

    #[DataProvider('badTypes')]
    public function testInvalidTypesAreRefused(array $over): void
    {
        [, $errors] = lr_validate_type($this->valid($over));
        $this->assertNotEmpty($errors);
    }

    public static function badTypes(): array
    {
        return [
            'no name'              => [['name' => '  ']],
            'negative rate'        => [['interest_rate' => '-1']],
            'rate over 100'        => [['interest_rate' => '101']],
            'rate not a number'    => [['interest_rate' => 'abc']],
            'unknown interest'     => [['interest_type' => 'compound']],
            'zero months'          => [['max_term_months' => '0']],
            'fractional months'    => [['max_term_months' => '2.5']],
            'negative multiple'    => [['max_share_multiple' => '-2']],
            'negative cap'         => [['max_amount' => '-5']],
            'fractional guarantor' => [['guarantors_required' => '1.5']],
            'unknown penalty'      => [['penalty_type' => 'daily']],
            'penalty without value' => [['penalty_type' => 'fixed', 'penalty_value' => '']],
            'percent over 100'     => [['penalty_type' => 'percent', 'penalty_value' => '150']],
            'negative grace'       => [['grace_days' => '-1']],
        ];
    }

    public function testNoPenaltyDropsAnyLeftoverValue(): void
    {
        [$t, $errors] = lr_validate_type($this->valid(['penalty_type' => 'none', 'penalty_value' => '9']));
        $this->assertSame([], $errors);
        $this->assertSame(0.0, $t['penalty_value']);
    }

    // ── lr_max_loan ──────────────────────────────────────────────────────────
    public function testLimitIsSharesTimesTheMultiple(): void
    {
        $this->assertSame(900000.0, lr_max_loan(300000, ['max_share_multiple' => 3, 'max_amount' => null]));
    }

    public function testWhatIsAlreadyOwedComesOffTheShareLimit(): void
    {
        $type = ['max_share_multiple' => 3, 'max_amount' => null];
        $this->assertSame(500000.0, lr_max_loan(300000, $type, 400000));
        $this->assertSame(0.0, lr_max_loan(300000, $type, 1200000)); // never negative
    }

    public function testTheLowerOfTheShareLimitAndTheFixedCapApplies(): void
    {
        $this->assertSame(200000.0, lr_max_loan(300000, ['max_share_multiple' => 3, 'max_amount' => 200000]));
        $this->assertSame(150000.0, lr_max_loan(50000, ['max_share_multiple' => 3, 'max_amount' => 200000]));
        $this->assertSame(200000.0, lr_max_loan(0, ['max_share_multiple' => 0, 'max_amount' => 200000]));
    }

    public function testAMemberWithNoSharesCannotBorrowOnAShareLimit(): void
    {
        $this->assertSame(0.0, lr_max_loan(0, ['max_share_multiple' => 3, 'max_amount' => null]));
    }

    // ── lr_add_months ────────────────────────────────────────────────────────
    public function testMonthEndsDoNotSpillIntoTheFollowingMonth(): void
    {
        $this->assertSame('2026-02-28', lr_add_months('2026-01-31', 1));
        $this->assertSame('2028-02-29', lr_add_months('2028-01-31', 1)); // leap year
        $this->assertSame('2026-03-31', lr_add_months('2026-01-31', 2)); // day restored
        $this->assertSame('2027-01-15', lr_add_months('2026-10-15', 3)); // across a year
    }

    // ── lr_schedule ──────────────────────────────────────────────────────────
    public function testFlatInterestIsChargedOnTheAmountBorrowedEveryMonth(): void
    {
        $s = lr_schedule(600000, 2, 'flat', 6, '2026-10-12');
        $this->assertCount(6, $s);
        $this->assertSame('2026-11-12', $s[0]['due_date']);
        $this->assertSame('2027-04-12', $s[5]['due_date']);
        foreach ($s as $line) {
            $this->assertSame(100000.0, $line['principal']);
            $this->assertSame(12000.0, $line['interest']);
            $this->assertSame(112000.0, $line['total']);
        }
        $this->assertSame(['principal' => 600000.0, 'interest' => 72000.0, 'total' => 672000.0], lr_schedule_totals($s));
    }

    public function testReducingInterestFallsAsTheBalanceFalls(): void
    {
        $s = lr_schedule(600000, 2, 'reducing', 6, '2026-10-12');
        $this->assertSame([12000.0, 10000.0, 8000.0, 6000.0, 4000.0, 2000.0], array_column($s, 'interest'));
        $this->assertSame(0.0, $s[5]['balance_after']);
        $this->assertSame(42000.0, lr_schedule_totals($s)['interest']);
    }

    public function testTheLastLineAbsorbsRoundingSoTheScheduleAddsUp(): void
    {
        $s = lr_schedule(100000, 1.5, 'flat', 3, '2026-10-12');
        $this->assertSame([33333.33, 33333.33, 33333.34], array_column($s, 'principal'));
        $this->assertSame(100000.0, lr_schedule_totals($s)['principal']);
        $this->assertSame(0.0, $s[2]['balance_after']);
    }

    public function testAZeroRateLoanCarriesNoInterest(): void
    {
        $this->assertSame(0.0, lr_schedule_totals(lr_schedule(90000, 0, 'reducing', 3, '2026-10-12'))['interest']);
    }

    public function testNoScheduleForNothingBorrowed(): void
    {
        $this->assertSame([], lr_schedule(0, 2, 'flat', 6, '2026-10-12'));
        $this->assertSame([], lr_schedule(1000, 2, 'flat', 0, '2026-10-12'));
    }

    // ── lr_months_overdue / lr_penalty ───────────────────────────────────────
    public function testNothingIsOverdueUntilTheGraceDaysPass(): void
    {
        $this->assertSame(0, lr_months_overdue('2026-11-12', '2026-11-12', 0)); // due today
        $this->assertSame(0, lr_months_overdue('2026-11-12', '2026-11-15', 3)); // last grace day
        $this->assertSame(1, lr_months_overdue('2026-11-12', '2026-11-16', 3)); // grace over
        $this->assertSame(1, lr_months_overdue('2026-11-12', '2026-11-13', 0));
    }

    public function testEveryStartedMonthLateCountsAsAWholeOne(): void
    {
        $this->assertSame(1, lr_months_overdue('2026-11-12', '2026-12-12', 0)); // exactly one month
        $this->assertSame(2, lr_months_overdue('2026-11-12', '2026-12-13', 0));
        $this->assertSame(3, lr_months_overdue('2026-11-12', '2027-02-01', 0));
    }

    public function testPenaltyFollowsTheTypesRule(): void
    {
        $this->assertSame(5600.0, lr_penalty(112000, 'percent', 5, 1));
        $this->assertSame(11200.0, lr_penalty(112000, 'percent', 5, 2));
        $this->assertSame(6000.0, lr_penalty(112000, 'fixed', 2000, 3));
        $this->assertSame(0.0, lr_penalty(112000, 'none', 5, 2));
        $this->assertSame(0.0, lr_penalty(112000, 'percent', 5, 0)); // not late
        $this->assertSame(0.0, lr_penalty(0, 'fixed', 2000, 3));      // nothing overdue
    }

    // ── lr_snapshot ──────────────────────────────────────────────────────────
    public function testASnapshotCarriesEveryRuleThatAffectsMoney(): void
    {
        [$t] = lr_validate_type($this->valid());
        $this->assertSame(
            ['type_name', 'interest_rate', 'interest_type', 'guarantors_required', 'penalty_type', 'penalty_value', 'grace_days'],
            array_keys(lr_snapshot($t))
        );
    }

    // ── the endpoint that changes the rules ──────────────────────────────────
    public function testSavingALoanTypeIsAuthenticatedCsrfProtectedAndRoleGated(): void
    {
        $src = file_get_contents(__DIR__ . '/../../actions/save_loan_type.php');
        $auth = strpos($src, "includes/require_auth.php");
        $csrf = strpos($src, "includes/require_csrf.php");
        $gate = strpos($src, 'vk_role_is_admin(');
        $write = strpos($src, 'UPDATE vicoba_loan_types');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($gate);
        // The gates come before anything is written.
        $this->assertLessThan($write, $auth);
        $this->assertLessThan($write, $csrf);
        $this->assertLessThan($write, $gate);
        $this->assertStringContainsString('lr_validate_type($_POST)', $src);
        $this->assertStringNotContainsString('DELETE FROM', $src, 'a loan type is switched off, never deleted');
    }

    public function testTheLoansModuleUsesItsOwnTablesNotTheBmsOnes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/create_vicoba_loan_tables.php');
        foreach (['vicoba_loan_types', 'vicoba_loans', 'vicoba_loan_guarantors', 'vicoba_loan_installments', 'vicoba_loan_payments'] as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS `$table`", $src);
        }
        $this->assertSame(5, substr_count($src, 'ENGINE=InnoDB'));
        $this->assertStringNotContainsString('INSERT INTO', $src, 'no loan types are seeded');
    }
}
