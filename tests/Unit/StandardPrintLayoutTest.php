<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StandardPrintLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock session data for tests
        $_SESSION['username'] = 'TestUser';
        $_SESSION['role_name'] = 'Admin';
        $_SESSION['preferred_language'] = 'en';
        
        $GLOBALS['group_name'] = 'TEST GROUP';
        $GLOBALS['group_logo'] = 'test_logo.png';
    }

    public function test_print_footer_contains_branding(): void
    {
        $footer = getPrintFooter();
        $this->assertStringContainsString('Powered By BJP Technologies', $footer);
    }

    public function test_print_footer_has_blue_branding_color(): void
    {
        $footer = getPrintFooter();
        // Check for blue color on the Powered By line
        $this->assertStringContainsString('color: #0d6efd !important;', $footer);
    }

    public function test_print_footer_font_size_is_10px(): void
    {
        $footer = getPrintFooter();
        // Check if 10px is applied to the main footer div and lines
        $this->assertStringContainsString('font-size: 10px;', $footer);
    }

    public function test_print_footer_has_correct_date_format(): void
    {
        $footer = getPrintFooter();
        $expected_date = date('d m, Y');
        $this->assertStringContainsString($expected_date, $footer);
        
        // Specifically check for space after day and comma after month
        // e.g., 06 05, 2026
        $this->assertMatchesRegularExpression('/\d{2} \d{2}, \d{4}/', $footer);
    }

    public function test_print_header_has_blue_group_name(): void
    {
        $header = getPrintHeader('REPORT TITLE');
        $this->assertStringContainsString('color: #0d6efd !important;', $header);
        $this->assertStringContainsString('TEST GROUP', $header);
    }

    public function test_print_header_has_black_heading(): void
    {
        $header = getPrintHeader('REPORT TITLE');
        // Heading should have text-dark class (standard black in bootstrap)
        $this->assertStringContainsString('text-dark', $header);
        $this->assertStringContainsString('REPORT TITLE', $header);
    }
}
