<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Loan repayment arithmetic (includes/loan_rules.php): how a payment is spread
 * over the schedule, what a late instalment costs, and what clears a loan early.
 */
class LoanRepaymentTest extends TestCase
{
    private const FLAT     = ['interest_type' => 'flat', 'penalty_type' => 'percent', 'penalty_value' => 5, 'grace_days' => 3];
    private const REDUCING = ['interest_type' => 'reducing', 'penalty_type' => 'none', 'penalty_value' => 0, 'grace_days' => 0];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/loan_rules.php';
    }

    /** A 600,000 loan over 6 months at 2% a month, paid out 12 Oct 2026, as installment rows. */
    private function loan(string $interestType): array
    {
        $rows = [];
        foreach (lr_schedule(600000, 2, $interestType, 6, '2026-10-12') as $l) {
            $rows[] = ['seq' => $l['seq'], 'due_date' => $l['due_date'], 'principal_due' => $l['principal'], 'interest_due' => $l['interest'],
                       'principal_paid' => 0.0, 'interest_paid' => 0.0, 'penalty_paid' => 0.0];
        }
        return $rows;
    }

    /** Apply an allocation to the rows, the way vl_record_payment() writes it. */
    private function apply(array $rows, array $alloc): array
    {
        foreach ($alloc['lines'] as $k => $l) {
            $rows[$k]['principal_paid'] += $l['principal'];
            $rows[$k]['interest_paid']  += $l['interest'];
            $rows[$k]['penalty_paid']   += $l['penalty'];
            $rows[$k]['interest_due']   -= $l['waive_interest'];
        }
        return $rows;
    }

    // ── an ordinary instalment ───────────────────────────────────────────────
    public function testAnOnTimeInstalmentClearsExactlyOneLine(): void
    {
        $a = lr_allocate_payment($this->loan('flat'), 112000, '2026-11-12', self::FLAT);
        $this->assertTrue($a['ok']);
        $this->assertSame(100000.0, $a['principal']);
        $this->assertSame(12000.0, $a['interest']);
        $this->assertSame(0.0, $a['penalty']);
        $this->assertSame('paid', $a['lines'][0]['status']);
        $this->assertSame('pending', $a['lines'][1]['status']);
        $this->assertFalse($a['settled']);
    }

    public function testAPartPaymentCoversInterestBeforePrincipal(): void
    {
        $a = lr_allocate_payment($this->loan('flat'), 50000, '2026-11-12', self::FLAT);
        $this->assertSame(12000.0, $a['interest']);
        $this->assertSame(38000.0, $a['principal']);
        $this->assertSame('partial', $a['lines'][0]['status']);
    }

    public function testEveryShillingOfAPaymentIsAccountedFor(): void
    {
        foreach ([1, 50000, 112000, 200000, 672000] as $amount) {
            $a = lr_allocate_payment($this->loan('flat'), $amount, '2026-11-12', self::FLAT);
            $this->assertSame((float) $amount, round($a['principal'] + $a['interest'] + $a['penalty'], 2), "TZS $amount");
        }
    }

    // ── late payment ─────────────────────────────────────────────────────────
    public function testNoPenaltyInsideTheGraceDays(): void
    {
        $this->assertSame(0.0, lr_installment_penalty_due($this->loan('flat')[0], '2026-11-15', self::FLAT));
    }

    public function testALateInstalmentCarriesItsPenaltyAndThePenaltyIsPaidFirst(): void
    {
        $rows = $this->loan('flat');
        // 5% of 112,000 for one month late.
        $this->assertSame(5600.0, lr_installment_penalty_due($rows[0], '2026-11-20', self::FLAT));

        $a = lr_allocate_payment($rows, 112000, '2026-11-20', self::FLAT);
        $this->assertSame(5600.0, $a['penalty']);
        $this->assertSame(12000.0, $a['interest']);
        $this->assertSame(94400.0, $a['principal']);
        // The instalment is NOT cleared: the penalty came off the top.
        $this->assertSame('partial', $a['lines'][0]['status']);
    }

    public function testAPenaltyAlreadyPaidIsNotChargedAgain(): void
    {
        $rows = $this->loan('flat');
        $rows = $this->apply($rows, lr_allocate_payment($rows, 60000, '2026-11-20', self::FLAT));
        // 57,600 is still unpaid on the instalment; 5% of that, less the 5,600 paid.
        $this->assertSame(0.0, lr_installment_penalty_due($rows[0], '2026-11-20', self::FLAT));
    }

    public function testAClearedInstalmentOwesNoPenalty(): void
    {
        $rows = $this->loan('flat');
        $rows = $this->apply($rows, lr_allocate_payment($rows, 117600, '2026-11-20', self::FLAT));
        $this->assertSame(0.0, lr_installment_unpaid($rows[0]));
        $this->assertSame(0.0, lr_installment_penalty_due($rows[0], '2027-06-01', self::FLAT));
    }

    public function testAFixedPenaltyIsChargedPerMonthLate(): void
    {
        $rules = ['interest_type' => 'flat', 'penalty_type' => 'fixed', 'penalty_value' => 2000, 'grace_days' => 0];
        $this->assertSame(4000.0, lr_installment_penalty_due($this->loan('flat')[0], '2026-12-20', $rules));
    }

    // ── paying a loan off ────────────────────────────────────────────────────
    public function testAFlatLoanPaidOffEarlyStillOwesItsFullInterest(): void
    {
        $p = lr_payoff($this->loan('flat'), '2026-10-20', self::FLAT);
        $this->assertSame(600000.0, $p['principal']);
        $this->assertSame(72000.0, $p['interest']);
        $this->assertSame(0.0, $p['waived_interest']);
        $this->assertSame(672000.0, $p['total']);

        $a = lr_allocate_payment($this->loan('flat'), 672000, '2026-10-20', self::FLAT);
        $this->assertTrue($a['settled']);
        $this->assertSame(72000.0, $a['interest']);
    }

    public function testAReducingLoanPaidOffEarlyOwesInterestOnlyForMonthsThatHaveStarted(): void
    {
        // Eight days in: only the first month has started (12,000). The other
        // five months' interest (10,000 + 8,000 + 6,000 + 4,000 + 2,000) is waived.
        $p = lr_payoff($this->loan('reducing'), '2026-10-20', self::REDUCING);
        $this->assertSame(12000.0, $p['interest']);
        $this->assertSame(30000.0, $p['waived_interest']);
        $this->assertSame(612000.0, $p['total']);

        $a = lr_allocate_payment($this->loan('reducing'), 612000, '2026-10-20', self::REDUCING);
        $this->assertTrue($a['ok']);
        $this->assertTrue($a['settled']);
        $this->assertSame(600000.0, $a['principal']);
        $this->assertSame(12000.0, $a['interest']);
        $this->assertSame(30000.0, $a['waived_interest']);
    }

    public function testAReducingLoanPaidLessThanThePayoffWaivesNothing(): void
    {
        $a = lr_allocate_payment($this->loan('reducing'), 300000, '2026-10-20', self::REDUCING);
        $this->assertSame(0.0, $a['waived_interest']);
        $this->assertFalse($a['settled']);
    }

    public function testAPaymentCannotExceedWhatClearsTheLoan(): void
    {
        $a = lr_allocate_payment($this->loan('flat'), 700000, '2026-10-20', self::FLAT);
        $this->assertFalse($a['ok']);
        $this->assertSame(672000.0, $a['max']);

        $this->assertFalse(lr_allocate_payment($this->loan('flat'), 0, '2026-10-20', self::FLAT)['ok']);
        $this->assertFalse(lr_allocate_payment($this->loan('flat'), -5, '2026-10-20', self::FLAT)['ok']);
    }

    public function testSixOnTimeInstalmentsClearAFlatLoanExactly(): void
    {
        $rows = $this->loan('flat');
        $paid = ['principal' => 0.0, 'interest' => 0.0];
        foreach (['2026-11-12', '2026-12-12', '2027-01-12', '2027-02-12', '2027-03-12', '2027-04-12'] as $n => $date) {
            $a = lr_allocate_payment($rows, 112000, $date, self::FLAT);
            $this->assertTrue($a['ok']);
            $this->assertSame($n === 5, $a['settled'], "settled only on the last instalment ($date)");
            $rows = $this->apply($rows, $a);
            $paid['principal'] += $a['principal'];
            $paid['interest']  += $a['interest'];
        }
        $this->assertSame(600000.0, $paid['principal']);
        $this->assertSame(72000.0, $paid['interest']);
        $this->assertSame(0.0, lr_payoff($rows, '2027-04-12', self::FLAT)['total']);
    }

    // ── the endpoint ─────────────────────────────────────────────────────────
    public function testRecordingARepaymentIsGatedAndTheOldHandlerIsGone(): void
    {
        $root = __DIR__ . '/../../';
        $this->assertFileDoesNotExist($root . 'actions/record_loan_payment.php');

        $action = file_get_contents($root . 'actions/loan_action.php');
        $this->assertMatchesRegularExpression("/case 'repay':[^}]*?if \(!canCreate\('disburse_loan'\)\)/s", $action);

        $svc = file_get_contents($root . 'includes/loans.php');
        $start = strpos($svc, 'function vl_record_payment(');
        $body  = substr($svc, $start, strpos($svc, 'function vl_member_loan_summary(') - $start);
        $this->assertStringContainsString('vl_is_own_loan($pdo, $loanId, $userId)', $body);
        $this->assertStringContainsString("status = 'disbursed' FOR UPDATE", $body);
        $this->assertStringContainsString('lr_allocate_payment(', $body);
    }
}
