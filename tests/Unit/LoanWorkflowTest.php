<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The loan workflow (includes/loans.php) and the one endpoint that drives it
 * (actions/loan_action.php). The DB steps are verified live; this pins the
 * guarantees that must not regress: who may do what, that every step is taken
 * once, and that the old unguarded handlers are gone.
 */
class LoanWorkflowTest extends TestCase
{
    private static string $svc;
    private static string $action;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/loans.php';
        self::$svc    = file_get_contents(__DIR__ . '/../../includes/loans.php');
        self::$action = file_get_contents(__DIR__ . '/../../actions/loan_action.php');
    }

    private function body(string $function): string
    {
        $start = strpos(self::$svc, "function $function(");
        $this->assertNotFalse($start, "$function() must exist");
        $next = strpos(self::$svc, "if (!function_exists(", $start);
        return substr(self::$svc, $start, ($next === false ? strlen(self::$svc) : $next) - $start);
    }

    // ── statuses ─────────────────────────────────────────────────────────────
    public function testEveryStatusHasALabelInBothLanguages(): void
    {
        $expected = ['pending_guarantors', 'pending', 'approved', 'disbursed', 'repaid', 'rejected', 'cancelled'];
        $this->assertSame($expected, array_keys(vl_status_labels(false)));
        $this->assertSame($expected, array_keys(vl_status_labels(true)));
    }

    public function testClosedLoansDoNotCountAgainstTheBorrowingLimit(): void
    {
        $this->assertSame(['pending_guarantors', 'pending', 'approved', 'disbursed'], vl_open_statuses());
    }

    // ── the endpoint's gates ─────────────────────────────────────────────────
    public function testTheEndpointIsAuthenticatedAndCsrfProtectedBeforeAnything(): void
    {
        $auth   = strpos(self::$action, 'includes/require_auth.php');
        $csrf   = strpos(self::$action, 'includes/require_csrf.php');
        $switch = strpos(self::$action, 'switch ($action)');
        $this->assertNotFalse($auth);
        $this->assertNotFalse($csrf);
        $this->assertLessThan($switch, $auth);
        $this->assertLessThan($switch, $csrf);
    }

    public function testApprovingAndDisbursingEachNeedTheirOwnPermission(): void
    {
        $this->assertMatchesRegularExpression(
            "/case 'approve':\s*case 'reject':\s*if \(!canApprove\('approve_loan'\)\)/", self::$action
        );
        $this->assertMatchesRegularExpression("/case 'disburse':\s*if \(!canCreate\('disburse_loan'\)\)/", self::$action);
    }

    public function testAMemberCanOnlyApplyAsThemselves(): void
    {
        // customer_id from the request is honoured for leadership only.
        $this->assertStringContainsString(
            "(vl_can_manage() && \$int('customer_id') > 0) ? \$int('customer_id') : (int) (\$me ?? 0)", self::$action
        );
    }

    public function testAGuarantorAnswersOnlyForThemselves(): void
    {
        // The guarantor id is the caller's own member id, never a request field.
        $this->assertStringContainsString('vl_respond_guarantee($pdo, $loan_id, $me,', self::$action);
        $this->assertStringContainsString('vl_cancel($pdo, $loan_id, $me,', self::$action);
    }

    public function testEveryStateChangeIsAuditLogged(): void
    {
        // apply -> logCreate; respond, cancel, approve/reject (one shared call)
        // and disburse -> logUpdate.
        $this->assertSame(1, substr_count(self::$action, 'logCreate('));
        $this->assertSame(4, substr_count(self::$action, 'logUpdate('));
    }

    // ── the service's guarantees ─────────────────────────────────────────────
    public function testTheRulesComeFromTheLoanTypeNotTheCaller(): void
    {
        $apply = $this->body('vl_apply');
        $this->assertStringContainsString('lr_snapshot($type)', $apply);
        $this->assertStringContainsString('vl_member_limit($pdo, $customerId, $type)', $apply);
        // No rate, limit or penalty parameter exists to be tampered with.
        $this->assertStringNotContainsString('$interestRate', $apply);
        $this->assertStringNotContainsString('interest_rate', self::$action);
    }

    public function testTheBorrowingLimitUsesShareCapitalOnly(): void
    {
        $this->assertStringContainsString("fb_member_balances(\$pdo, \$customerId)['shares']", $this->body('vl_member_limit'));
    }

    public function testNobodyDecidesOrPaysOutTheirOwnLoan(): void
    {
        $this->assertStringContainsString('vl_is_own_loan($pdo, $loanId, $userId)', $this->body('vl_decide'));
        $this->assertStringContainsString('vl_is_own_loan($pdo, $loanId, $userId)', $this->body('vl_disburse'));
    }

    public function testEachStepCanOnlyBeTakenFromTheRightStatus(): void
    {
        $this->assertStringContainsString("WHERE loan_id = ? AND status = 'pending'", $this->body('vl_decide'));
        $this->assertStringContainsString("status = 'approved' FOR UPDATE", $this->body('vl_disburse'));
        $this->assertStringContainsString("g.status = 'pending'", $this->body('vl_respond_guarantee'));
        $this->assertStringContainsString("l.status = 'pending_guarantors'", $this->body('vl_respond_guarantee'));
        $this->assertStringContainsString("status IN ('pending_guarantors', 'pending')", $this->body('vl_cancel'));
    }

    public function testTheGroupCannotLendMoneyItDoesNotHold(): void
    {
        $disburse = $this->body('vl_disburse');
        $this->assertStringContainsString('getGroupFundBalance($pdo)', $disburse);
        $this->assertLessThan(strpos($disburse, 'INSERT INTO vicoba_loan_installments'), strpos($disburse, 'getGroupFundBalance($pdo)'));
    }

    public function testARejectionNeedsAReason(): void
    {
        $this->assertStringContainsString("!\$approve && \$reason === ''", $this->body('vl_decide'));
    }

    // ── the fund balance ─────────────────────────────────────────────────────
    public function testLoansPaidOutLeaveTheFundAndRepaymentsReturnToIt(): void
    {
        require_once __DIR__ . '/../../includes/finance.php';
        // contributions 1,000,000; nothing else; 600,000 lent; 112,000 repaid.
        $this->assertSame(512000.0, fundBalanceFromTotals(1000000, 0, 0, 0, 0, 0, 600000, 112000));
        // The two loan arguments are optional, so existing callers are unchanged.
        $this->assertSame(1000000.0, fundBalanceFromTotals(1000000, 0, 0, 0, 0, 0));

        $src = file_get_contents(__DIR__ . '/../../includes/finance.php');
        $this->assertStringContainsString("FROM vicoba_loans WHERE status IN ('disbursed','repaid')", $src);
        $this->assertStringContainsString('FROM vicoba_loan_payments', $src);
    }

    // ── pages and routes ─────────────────────────────────────────────────────
    public function testTheUnguardedHandlersAreGoneAndTheNewRoutesAreLive(): void
    {
        $root = __DIR__ . '/../../';
        $this->assertFileDoesNotExist($root . 'actions/apply_for_loan.php');
        $this->assertFileDoesNotExist($root . 'actions/approve_loan_vicoba.php');

        $routes = file_get_contents($root . 'roots.php');
        foreach (["'actions/loan_action'", "'api/loan_preview'", "'loans' ", "'loans/details'", "'my_loans'"] as $route) {
            $this->assertMatchesRegularExpression('/^    ' . preg_quote($route, '/') . '/m', $routes, "$route must be routed");
        }
    }

    public function testTheLoansListIsLeadershipOnlyAndALoanIsHiddenFromOutsiders(): void
    {
        $list = file_get_contents(__DIR__ . '/../../app/bms/loans/loans_list.php');
        $this->assertStringContainsString("if (!vl_can_manage()) {\n    requireViewPermission('approve_loan');", $list);

        $detail = file_get_contents(__DIR__ . '/../../app/bms/loans/loan_details.php');
        $this->assertStringContainsString('!$loan || !vl_may_view($pdo, $loan, $uid, $customer_id)', $detail);

        $mayView = $this->body('vl_may_view');
        $this->assertStringContainsString('vl_can_manage()', $mayView);
        $this->assertStringContainsString('vicoba_loan_guarantors', $mayView);
    }

    public function testThePreviewUsesTheSameArithmeticAsTheRealLoan(): void
    {
        $preview = file_get_contents(__DIR__ . '/../../api/loan_preview.php');
        $this->assertStringContainsString('includes/require_auth.php', $preview);
        $this->assertStringContainsString('vl_member_limit(', $preview);
        $this->assertStringContainsString('lr_schedule(', $preview);
        // ...and the browser does none of its own.
        $modal = file_get_contents(__DIR__ . '/../../app/bms/loans/_apply_modal.php');
        $this->assertStringNotContainsString('interest_rate /', $modal);
        $this->assertStringContainsString('api/loan_preview', $modal);
    }
}
