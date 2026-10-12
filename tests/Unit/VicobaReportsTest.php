<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The loan, share and Social Fund reports (includes/vicoba_reports.php and the
 * four pages under app/constant/reports/). The queries are verified against a
 * seeded database; this pins that the reports reuse the system's own figures,
 * are leadership-only, and are reachable.
 */
class VicobaReportsTest extends TestCase
{
    private static string $src;

    private const PAGES = [
        'reports/loans'           => 'loan_portfolio_report.php',
        'reports/loan_repayments' => 'loan_repayments_report.php',
        'reports/shares'          => 'shares_register.php',
        'reports/social_fund'     => 'social_fund_statement.php',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/vicoba_reports.php';
        self::$src = file_get_contents(__DIR__ . '/../../includes/vicoba_reports.php');
    }

    private function body(string $function): string
    {
        $start = strpos(self::$src, "function $function(");
        $this->assertNotFalse($start, "$function() must exist");
        $next = strpos(self::$src, "if (!function_exists(", $start);
        return substr(self::$src, $start, ($next === false ? strlen(self::$src) : $next) - $start);
    }

    public function testDaysLateCountsFromTheDueDate(): void
    {
        $this->assertSame(0, vr_days_late('2026-10-12', '2026-10-12'));
        $this->assertSame(0, vr_days_late('2026-10-20', '2026-10-12')); // not due yet
        $this->assertSame(17, vr_days_late('2026-09-25', '2026-10-12'));
        $this->assertSame(31, vr_days_late('2026-09-11', '2026-10-12'));
    }

    public function testEveryReportIsListedRoutedAndHasBothLanguages(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../roots.php');
        $menu   = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertSame(array_keys(self::PAGES), array_keys(vr_reports()));

        foreach (self::PAGES as $route => $file) {
            [$en, $sw, $icon] = vr_reports()[$route];
            $this->assertNotSame('', $en);
            $this->assertNotSame('', $sw);
            $this->assertStringStartsWith('bi-', $icon);
            $this->assertStringContainsString("'$route'", $routes);
            $this->assertStringContainsString("REPORTS_DIR . '/$file'", $routes);
            $this->assertFileExists(__DIR__ . '/../../app/constant/reports/' . $file);
            $this->assertStringContainsString("getUrl('$route')", $menu);
        }
    }

    public function testEveryReportIsLeadershipOnly(): void
    {
        foreach (self::PAGES as $file) {
            $page = file_get_contents(__DIR__ . '/../../app/constant/reports/' . $file);
            $gate = strpos($page, "if (!vr_can_view()) {\n    requireViewPermission(\"approve_loan\");");
            $this->assertNotFalse($gate, "$file must be gated");
            // The gate comes before any figure is read.
            foreach (['vr_loan_portfolio(', 'vr_loan_repayments(', 'vr_shares_register(', 'vr_social_fund(', 'vr_period('] as $read) {
                $at = strpos($page, $read);
                if ($at !== false) {
                    $this->assertLessThan($at, $gate, "$file reads data before checking permission");
                }
            }
        }
        $menu = file_get_contents(__DIR__ . '/../../header.php');
        $this->assertLessThan(
            strpos($menu, "getUrl('reports/loans')"),
            strpos($menu, "<?php if (canView('approve_loan') || canView('disburse_loan') || canView('dividends')): \$vk_rsw")
        );
    }

    public function testTheReportsAreNotOpenedByTheMemberVisibleReportsKey(): void
    {
        // Found by logging in as a Member: 'vicoba_reports' is granted to every
        // member (the older group reports are meant to be), so gating on it showed
        // each member's loans and arrears to everyone.
        $body = $this->body('vr_can_view');
        $this->assertStringContainsString("canView('approve_loan') || canView('disburse_loan') || canView('dividends')", $body);
        foreach (self::PAGES as $file) {
            $page = file_get_contents(__DIR__ . '/../../app/constant/reports/' . $file);
            $this->assertStringNotContainsString('requireViewPermission("vicoba_reports")', $page);
        }
        // None of the three keys is granted to a Member.
        require_once __DIR__ . '/../../includes/role_grants.php';
        foreach (['approve_loan', 'disburse_loan', 'dividends'] as $key) {
            $this->assertNull(vk_role_grants('view', $key));
        }
    }

    public function testEveryReportEscapesNamesAndPrintsItsFooterOnPaperOnly(): void
    {
        foreach (self::PAGES as $file) {
            $page = file_get_contents(__DIR__ . '/../../app/constant/reports/' . $file);
            $this->assertStringContainsString('htmlspecialchars(', $page);
            $this->assertStringContainsString('vr_close();', $page);
            // No name is echoed raw.
            $this->assertDoesNotMatchRegularExpression('/<\?= \$\w+\[\'(name|member_name|what|reference)\'\] \?>/', $page, $file);
        }
        $this->assertStringContainsString('<div class="d-none d-print-block"><?php include PRINT_FOOTER_FILE; ?></div>', self::$src);
    }

    // ── the reports reuse the system's own figures ───────────────────────────
    public function testLoanBalancesAndPenaltiesComeFromTheLoanRules(): void
    {
        $body = $this->body('vr_loan_portfolio');
        $this->assertStringContainsString('lr_installment_unpaid($i)', $body);
        $this->assertStringContainsString('lr_installment_penalty_due($i, $asOf, $rules)', $body);
        $this->assertStringContainsString('vl_loans($pdo, null, $status)', $body);
        // Only loans that were paid out count toward the money totals.
        $this->assertStringContainsString("in_array(\$l['status'], ['disbursed', 'repaid'], true)", $body);
    }

    public function testLendingIncomeIsInterestPlusPenaltiesNeverPrincipal(): void
    {
        $body = $this->body('vr_loan_repayments');
        $this->assertStringContainsString("\$t['income'] = lr_money(\$t['interest'] + \$t['penalty']);", $body);
        $this->assertStringNotContainsString("\$t['income'] = lr_money(\$t['principal']", $body);
        $this->assertStringContainsString('p.payment_date BETWEEN ? AND ?', $body);
    }

    public function testTheSharesRegisterUsesTheSameShareCapitalAsTheDividend(): void
    {
        $body = $this->body('vr_shares_register');
        $this->assertStringContainsString('dv_share_capital_as_at($pdo, $asAt, $rules)', $body);
        $this->assertStringContainsString('fb_share_count(', $body);
    }

    public function testTheSocialFundStatementBalances(): void
    {
        $body = $this->body('vr_social_fund');
        $this->assertStringContainsString("co.contribution_type = 'social_fund'", $body);
        $this->assertStringContainsString("FROM death_expenses WHERE status = 'paid'", $body);
        $this->assertStringContainsString("FROM member_payouts WHERE status = 'paid'", $body);
        $this->assertStringContainsString("'closing' => lr_money(\$opening + \$paidIn - \$paidOut)", $body);
        // Welfare is the only thing that leaves the fund; general expenses do not.
        $this->assertStringNotContainsString('general_expenses', $body);
    }
}
