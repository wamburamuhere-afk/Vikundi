<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Approved" is not "paid". PR 1 adds a `paid` state to the three expense
 * workflows (death / general / petty cash), a treasurer-or-admin "Mark as paid"
 * action, and keeps the balance neutral (an expense is money-out whether approved
 * OR paid) — the cash-basis switch comes in PR 2.
 */
class ExpensesPaidStatusTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../core/permissions.php';
    }

    protected function tearDown(): void
    {
        // canMarkPaid()/isAdmin() read from the session — reset between cases.
        foreach (['role_id', 'role', 'user_role'] as $k) unset($_SESSION[$k]);
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testTreasurerAndAdminMayMarkPaid(): void
    {
        $_SESSION['role_id'] = 1;  $this->assertTrue(canMarkPaid(), 'Admin');
        $_SESSION['role_id'] = 2;  $this->assertTrue(canMarkPaid(), 'Chairperson');
        $_SESSION['role_id'] = 4;  $this->assertTrue(canMarkPaid(), 'Treasurer');
        unset($_SESSION['role_id']);
        $_SESSION['user_role'] = 'Treasurer'; $this->assertTrue(canMarkPaid(), 'Treasurer by name');
    }

    public function testSecretaryAndMemberMayNotMarkPaid(): void
    {
        $_SESSION['role_id'] = 3;  $this->assertFalse(canMarkPaid(), 'Secretary');
        $_SESSION['role_id'] = 13; $this->assertFalse(canMarkPaid(), 'Member');
        unset($_SESSION['role_id']);
        $_SESSION['user_role'] = 'Member'; $this->assertFalse(canMarkPaid());
    }

    public function testMigrationAddsPaidStateAndRegistered(): void
    {
        $mig = $this->src('database/add_paid_status_to_expenses.php');
        $this->assertStringContainsString("'paid'", $mig);
        $this->assertStringContainsString('paid_at', $mig);
        $this->assertStringContainsString('paid_by', $mig);
        $this->assertStringContainsString('add_paid_status_to_expenses.php', $this->src('database/migrate.php'));
    }

    public function testBalanceCountsApprovedOrPaidForExpenses(): void
    {
        // PR 1 is balance-neutral: each expense type counts whether approved or paid.
        $fin = $this->src('includes/finance.php');
        $this->assertStringContainsString("FROM death_expenses WHERE status IN ('approved','paid')", $fin);
        $this->assertStringContainsString("FROM general_expenses WHERE status IN ('approved','paid')", $fin);
        $this->assertStringContainsString("FROM petty_cash_vouchers WHERE status IN ('approved','paid')", $fin);
    }

    public function testMarkPaidEndpointIsGatedAndWhitelisted(): void
    {
        $ep = $this->src('actions/mark_expense_paid.php');
        $this->assertStringContainsString('canMarkPaid()', $ep);
        $this->assertStringContainsString("require_csrf.php", $ep);
        $this->assertStringContainsString("status = 'paid'", $ep);
        $this->assertStringContainsString("paid_at = NOW()", $ep);
        // type -> table is whitelisted (no raw table name from the request)
        $this->assertStringContainsString("'death'   => 'death_expenses'", $ep);
        $this->assertStringContainsString("'general' => 'general_expenses'", $ep);
        $this->assertStringContainsString("'petty'   => 'petty_cash_vouchers'", $ep);
        // only an approved row can move to paid
        $this->assertStringContainsString("!== 'approved'", $ep);
    }

    public function testEachExpensePageHasMarkPaidAndPaidBadge(): void
    {
        foreach ([
            'app/constant/accounts/expenses.php'         => "markExpensePaid('death'",
            'app/constant/accounts/general_expenses.php' => "markExpensePaid('general'",
            'app/constant/accounts/petty_cash.php'       => "markExpensePaid('petty'",
        ] as $page => $call) {
            $src = $this->src($page);
            $this->assertStringContainsString('canMarkPaid()', $src, "$page missing the permission flag");
            $this->assertStringContainsString($call, $src, "$page missing the Mark-as-paid action");
            $this->assertMatchesRegularExpression('/paid.*primary|primary.*paid/i', $src, "$page missing a distinct 'paid' badge");
        }
    }
}
