<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the standard print layout introduced in feat/print-layout-phase1-reports:
 *  - includes/print_footer.php   — shared footer included on all printable report pages
 *  - app/constant/reports/*.php  — all five core report files
 *
 * No database or HTTP required. Output-capture tests buffer the footer include
 * directly; file-content tests read PHP source as a string.
 */
class StandardPrintLayoutTest extends TestCase
{
    private string $footerPath;
    private string $headerPath;
    private string $reportsDir;

    protected function setUp(): void
    {
        $this->footerPath  = __DIR__ . '/../../includes/print_footer.php';
        $this->headerPath  = __DIR__ . '/../../header.php';
        $this->reportsDir  = __DIR__ . '/../../app/constant/reports';
        unset($_SESSION['preferred_language']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['preferred_language']);
    }

    // -------------------------------------------------------------------------
    // File existence
    // -------------------------------------------------------------------------

    public function test_print_footer_file_exists(): void
    {
        $this->assertFileExists($this->footerPath);
    }

    // -------------------------------------------------------------------------
    // CSS structure — @page margin, body padding, fixed positioning
    // -------------------------------------------------------------------------

    public function test_print_footer_css_sets_1cm_page_margin(): void
    {
        $css = file_get_contents($this->footerPath);
        $this->assertStringContainsString('@page { margin: 1cm; }', $css);
    }

    public function test_print_footer_css_sets_55px_body_padding(): void
    {
        $css = file_get_contents($this->footerPath);
        $this->assertStringContainsString('padding-bottom: 55px', $css);
    }

    public function test_print_footer_uses_position_fixed_to_repeat_on_every_page(): void
    {
        $css = file_get_contents($this->footerPath);
        $this->assertStringContainsString('position: fixed', $css);
    }

    public function test_print_footer_anchored_to_bottom_zero(): void
    {
        $css = file_get_contents($this->footerPath);
        $this->assertStringContainsString('bottom: 0', $css);
    }

    public function test_print_footer_spans_full_page_width(): void
    {
        $css = file_get_contents($this->footerPath);
        $this->assertStringContainsString('left: 0', $css);
        $this->assertStringContainsString('right: 0', $css);
    }

    // -------------------------------------------------------------------------
    // HTML output — English
    // -------------------------------------------------------------------------

    public function test_footer_output_english_printed_by_phrase(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Jane Doe';
        $user_role = 'Treasurer';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('This document was printed by', $html);
        $this->assertStringContainsString('on', $html);
        $this->assertStringContainsString('at', $html);
    }

    public function test_footer_output_english_shows_username_and_role(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Jane Doe';
        $user_role = 'Treasurer';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Treasurer', $html);
    }

    // -------------------------------------------------------------------------
    // HTML output — Swahili
    // -------------------------------------------------------------------------

    public function test_footer_output_swahili_printed_by_phrase(): void
    {
        $_SESSION['preferred_language'] = 'sw';
        $username  = 'Amina Ngowi';
        $user_role = 'Mweka Hazina';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('Nyaraka hii imechapishwa na', $html);
        $this->assertStringContainsString('mnamo', $html);
        $this->assertStringContainsString('saa', $html);
    }

    public function test_footer_output_swahili_shows_username_and_role(): void
    {
        $_SESSION['preferred_language'] = 'sw';
        $username  = 'Amina Ngowi';
        $user_role = 'Mweka Hazina';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('Amina Ngowi', $html);
        $this->assertStringContainsString('Mweka Hazina', $html);
    }

    // -------------------------------------------------------------------------
    // HTML output — null/missing variable fallbacks
    // -------------------------------------------------------------------------

    public function test_footer_falls_back_to_user_when_username_is_null(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = null;
        $user_role = null;

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('>User<', $html);
        $this->assertStringContainsString('>Member<', $html);
    }

    // -------------------------------------------------------------------------
    // XSS safety — htmlspecialchars() on username and role
    // -------------------------------------------------------------------------

    public function test_footer_escapes_script_tag_in_username(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = '<script>alert(1)</script>';
        $user_role = 'Member';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_footer_escapes_img_onerror_in_role(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Admin';
        $user_role = '"><img src=x onerror=alert(1)>';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_footer_escapes_ampersand_in_username(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'John & Jane';
        $user_role = 'Admin';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('John &amp; Jane', $html);
        $this->assertStringNotContainsString('John & Jane', $html);
    }

    // -------------------------------------------------------------------------
    // Branding and date
    // -------------------------------------------------------------------------

    public function test_footer_contains_bjp_branding(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Test';
        $user_role = 'Admin';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString('BJP Technologies', $html);
        $this->assertStringContainsString('All Rights Reserved', $html);
    }

    public function test_footer_contains_current_date(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Test';
        $user_role = 'Admin';
        $today     = date('d m, Y');

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString($today, $html);
    }

    public function test_footer_contains_current_year_in_copyright(): void
    {
        $_SESSION['preferred_language'] = 'en';
        $username  = 'Test';
        $user_role = 'Admin';

        ob_start();
        include $this->footerPath;
        $html = ob_get_clean();

        $this->assertStringContainsString(date('Y'), $html);
    }

    // -------------------------------------------------------------------------
    // Report files — PRINT_FOOTER_FILE included in all five reports
    // -------------------------------------------------------------------------

    public function test_vicoba_reports_includes_print_footer_file(): void
    {
        $src = file_get_contents($this->reportsDir . '/vicoba_reports.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    public function test_customer_analysis_includes_print_footer_file(): void
    {
        $src = file_get_contents($this->reportsDir . '/customer_analysis.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    public function test_death_analysis_includes_print_footer_file(): void
    {
        $src = file_get_contents($this->reportsDir . '/death_analysis.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    public function test_expense_report_includes_print_footer_file(): void
    {
        $src = file_get_contents($this->reportsDir . '/expense_report.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    public function test_member_statement_includes_print_footer_file(): void
    {
        $src = file_get_contents($this->reportsDir . '/member_statement.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    public function test_financial_ledger_includes_print_footer_file(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/bms/customer/financial_ledger.php');
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $src);
    }

    // -------------------------------------------------------------------------
    // Report files — @page margin standardised at 1cm
    // -------------------------------------------------------------------------

    public function test_vicoba_reports_has_1cm_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/vicoba_reports.php');
        $this->assertStringContainsString('@page { margin: 1cm; }', $src);
    }

    public function test_death_analysis_has_1cm_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/death_analysis.php');
        $this->assertStringContainsString('@page { margin: 1cm; }', $src);
    }

    public function test_death_analysis_cards_visible_in_print(): void
    {
        $src = file_get_contents($this->reportsDir . '/death_analysis.php');
        // Ensure d-print-none is NOT on the row containing the summary cards
        $this->assertStringContainsString('<div class="row g-2 g-md-4 mb-4">', $src);
        $this->assertStringNotContainsString('<div class="row g-4 mb-5 d-print-none">', $src);
    }

    public function test_expense_report_has_1cm_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/expense_report.php');
        $this->assertStringContainsString('@page { margin: 1cm; }', $src);
    }

    public function test_expense_report_cards_visible_in_print(): void
    {
        $src = file_get_contents($this->reportsDir . '/expense_report.php');
        $this->assertStringContainsString('<div class="row g-2 g-md-4 mb-4">', $src);
        $this->assertStringNotContainsString('<div class="row g-4 mb-4 d-print-none">', $src);
    }

    public function test_customer_analysis_has_1cm_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/customer_analysis.php');
        $this->assertStringContainsString('@page { margin: 1cm; }', $src);
    }

    public function test_member_statement_has_uniform_1cm_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/member_statement.php');
        $this->assertStringContainsString('@page { margin: 1cm; }', $src);
    }

    // -------------------------------------------------------------------------
    // member_statement.php — old custom inline footer must be gone
    // -------------------------------------------------------------------------

    public function test_member_statement_has_no_old_custom_footer_div(): void
    {
        $src = file_get_contents($this->reportsDir . '/member_statement.php');
        $this->assertStringNotContainsString('class="d-none d-print-block print-footer"', $src);
    }

    public function test_member_statement_has_no_non_uniform_page_margin(): void
    {
        $src = file_get_contents($this->reportsDir . '/member_statement.php');
        $this->assertStringNotContainsString('margin: 1cm 1cm 2cm 1cm', $src);
    }

    // -------------------------------------------------------------------------
    // customer_analysis.php — body padding added
    // -------------------------------------------------------------------------

    public function test_customer_analysis_body_has_print_padding_bottom(): void
    {
        $src = file_get_contents($this->reportsDir . '/customer_analysis.php');
        $this->assertStringContainsString('padding-bottom: 55px', $src);
    }

    // -------------------------------------------------------------------------
    // Global Header — Hide wrapper in print
    // -------------------------------------------------------------------------

    public function test_header_php_hides_wrapper_in_print(): void
    {
        $src = file_get_contents($this->headerPath);
        $this->assertStringContainsString('.header-wrapper, .navbar {', $src);
        $this->assertStringContainsString('display: none !important;', $src);
    }
}
