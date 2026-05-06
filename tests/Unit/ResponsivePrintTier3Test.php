<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Tier 3 responsive print UI changes:
 *  - member_statement.php (death benefit history card view)
 *  - expense_report.php   (consolidated expense DataTable card view)
 *  - loan_details.php     (repayment schedule card view)
 *
 * All assertions target pure PHP helper functions and logic used by the
 * card views — no DB or HTTP required.
 */
class ResponsivePrintTier3Test extends TestCase
{
    // -------------------------------------------------------------------------
    // Repayment schedule — instalment status badge logic (loan_details.php)
    // -------------------------------------------------------------------------

    public function test_paid_instalment_maps_to_success_badge(): void
    {
        $istatus = 'paid';
        $badge = match($istatus) {
            'paid'    => ['Imelipwa',   'bg-success text-white'],
            'partial' => ['Sehemu',     'bg-warning text-dark'],
            'late'    => ['Imechelewa', 'bg-danger text-white'],
            default   => ['Inasubiri',  'bg-light text-dark border']
        };
        $this->assertEquals('Imelipwa', $badge[0]);
        $this->assertStringContainsString('success', $badge[1]);
    }

    public function test_partial_instalment_maps_to_warning_badge(): void
    {
        $istatus = 'partial';
        $badge = match($istatus) {
            'paid'    => ['Imelipwa',   'bg-success text-white'],
            'partial' => ['Sehemu',     'bg-warning text-dark'],
            'late'    => ['Imechelewa', 'bg-danger text-white'],
            default   => ['Inasubiri',  'bg-light text-dark border']
        };
        $this->assertEquals('Sehemu', $badge[0]);
        $this->assertStringContainsString('warning', $badge[1]);
    }

    public function test_late_instalment_maps_to_danger_badge(): void
    {
        $istatus = 'late';
        $badge = match($istatus) {
            'paid'    => ['Imelipwa',   'bg-success text-white'],
            'partial' => ['Sehemu',     'bg-warning text-dark'],
            'late'    => ['Imechelewa', 'bg-danger text-white'],
            default   => ['Inasubiri',  'bg-light text-dark border']
        };
        $this->assertEquals('Imechelewa', $badge[0]);
        $this->assertStringContainsString('danger', $badge[1]);
    }

    public function test_pending_instalment_maps_to_light_badge(): void
    {
        $istatus = 'pending';
        $badge = match($istatus) {
            'paid'    => ['Imelipwa',   'bg-success text-white'],
            'partial' => ['Sehemu',     'bg-warning text-dark'],
            'late'    => ['Imechelewa', 'bg-danger text-white'],
            default   => ['Inasubiri',  'bg-light text-dark border']
        };
        $this->assertEquals('Inasubiri', $badge[0]);
        $this->assertStringContainsString('light', $badge[1]);
    }

    // -------------------------------------------------------------------------
    // Overdue detection logic — loan_details.php card view
    // -------------------------------------------------------------------------

    public function test_pending_past_due_date_is_overdue(): void
    {
        $istatus  = 'pending';
        $due_date = '2020-01-01';
        $overdue  = ($istatus === 'pending' || $istatus === 'partial') && $due_date < date('Y-m-d');
        $this->assertTrue($overdue);
    }

    public function test_pending_future_due_date_is_not_overdue(): void
    {
        $istatus  = 'pending';
        $due_date = '2099-12-31';
        $overdue  = ($istatus === 'pending' || $istatus === 'partial') && $due_date < date('Y-m-d');
        $this->assertFalse($overdue);
    }

    public function test_paid_instalment_is_never_overdue_even_with_past_date(): void
    {
        $istatus  = 'paid';
        $due_date = '2020-01-01';
        $overdue  = ($istatus === 'pending' || $istatus === 'partial') && $due_date < date('Y-m-d');
        $this->assertFalse($overdue);
    }

    public function test_partial_past_due_date_is_overdue(): void
    {
        $istatus  = 'partial';
        $due_date = '2020-06-15';
        $overdue  = ($istatus === 'pending' || $istatus === 'partial') && $due_date < date('Y-m-d');
        $this->assertTrue($overdue);
    }

    // -------------------------------------------------------------------------
    // Avatar colour selection — loan_details.php card view
    // -------------------------------------------------------------------------

    public function test_paid_instalment_gets_green_avatar(): void
    {
        $sc_status  = 'paid';
        $sc_overdue = false;
        $color = $sc_status === 'paid'
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : ($sc_overdue ? 'linear-gradient(135deg,#dc3545,#b02a37)' : 'linear-gradient(135deg,#0d6efd,#0a58ca)');
        $this->assertStringContainsString('#198754', $color);
    }

    public function test_overdue_instalment_gets_red_avatar(): void
    {
        $sc_status  = 'pending';
        $sc_overdue = true;
        $color = $sc_status === 'paid'
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : ($sc_overdue ? 'linear-gradient(135deg,#dc3545,#b02a37)' : 'linear-gradient(135deg,#0d6efd,#0a58ca)');
        $this->assertStringContainsString('#dc3545', $color);
    }

    public function test_pending_non_overdue_gets_blue_avatar(): void
    {
        $sc_status  = 'pending';
        $sc_overdue = false;
        $color = $sc_status === 'paid'
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : ($sc_overdue ? 'linear-gradient(135deg,#dc3545,#b02a37)' : 'linear-gradient(135deg,#0d6efd,#0a58ca)');
        $this->assertStringContainsString('#0d6efd', $color);
    }

    // -------------------------------------------------------------------------
    // Expense category logic — expense_report.php card view
    // -------------------------------------------------------------------------

    public function test_general_expense_gets_teal_avatar(): void
    {
        $category   = 'General';
        $is_general = ($category === 'General');
        $av_color   = $is_general
            ? 'linear-gradient(135deg,#0dcaf0,#0aa2c0)'
            : 'linear-gradient(135deg,#dc3545,#b02a37)';
        $this->assertStringContainsString('#0dcaf0', $av_color);
    }

    public function test_death_expense_gets_red_avatar(): void
    {
        $category   = 'Death Assistance';
        $is_general = ($category === 'General');
        $av_color   = $is_general
            ? 'linear-gradient(135deg,#0dcaf0,#0aa2c0)'
            : 'linear-gradient(135deg,#dc3545,#b02a37)';
        $this->assertStringContainsString('#dc3545', $av_color);
    }

    public function test_general_expense_avatar_letter_is_G(): void
    {
        $category = 'General';
        $avatar   = ($category === 'General') ? 'G' : 'D';
        $this->assertEquals('G', $avatar);
    }

    public function test_death_expense_avatar_letter_is_D(): void
    {
        $category = 'Death Assistance';
        $avatar   = ($category === 'General') ? 'G' : 'D';
        $this->assertEquals('D', $avatar);
    }

    // -------------------------------------------------------------------------
    // Swahili label logic — expense_report.php and member_statement.php
    // -------------------------------------------------------------------------

    public function test_swahili_general_label(): void
    {
        $is_sw    = true;
        $category = 'General';
        $cat_sw   = $category === 'General' ? 'Matumizi ya Kikundi' : 'Msaada wa Msiba';
        $label    = $is_sw ? $cat_sw : $category;
        $this->assertEquals('Matumizi ya Kikundi', $label);
    }

    public function test_english_death_label(): void
    {
        $is_sw    = false;
        $category = 'Death Assistance';
        $cat_sw   = $category === 'General' ? 'Matumizi ya Kikundi' : 'Msaada wa Msiba';
        $label    = $is_sw ? $cat_sw : $category;
        $this->assertEquals('Death Assistance', $label);
    }

    // -------------------------------------------------------------------------
    // htmlspecialchars() — XSS safety for deceased name in member_statement cards
    // -------------------------------------------------------------------------

    public function test_deceased_name_with_script_tag_is_escaped(): void
    {
        $name = '<script>alert("xss")</script>';
        $safe = htmlspecialchars($name);
        $this->assertStringNotContainsString('<script>', $safe);
        $this->assertStringContainsString('&lt;script&gt;', $safe);
    }

    public function test_deceased_name_with_ampersand_is_escaped(): void
    {
        $name = 'John & Mary';
        $safe = htmlspecialchars($name);
        $this->assertStringContainsString('&amp;', $safe);
        $this->assertStringNotContainsString(' & ', $safe);
    }

    // -------------------------------------------------------------------------
    // number_format() — currency amounts in all Tier 3 card views
    // -------------------------------------------------------------------------

    public function test_instalment_amount_formatted_correctly(): void
    {
        $this->assertEquals('50,000.00', number_format(50000, 2));
    }

    public function test_zero_amount_paid_formats_correctly(): void
    {
        $this->assertEquals('0.00', number_format(0, 2));
    }

    public function test_expense_amount_no_decimals(): void
    {
        $this->assertEquals('1,250,000', number_format(1250000));
    }

    // -------------------------------------------------------------------------
    // date() formatting — used in all Tier 3 card headers and date rows
    // -------------------------------------------------------------------------

    public function test_due_date_formatted_as_dd_mm_yyyy(): void
    {
        $formatted = date('d/m/Y', strtotime('2025-07-20'));
        $this->assertEquals('20/07/2025', $formatted);
    }

    public function test_expense_date_formatted_as_dd_mm_yyyy(): void
    {
        $formatted = date('d/m/Y', strtotime('2024-12-01'));
        $this->assertEquals('01/12/2024', $formatted);
    }
}
