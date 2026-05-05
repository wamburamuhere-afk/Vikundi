<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DateTime;

class HelpersTest extends TestCase
{
    // -------------------------------------------------------------------------
    // calculateTotalInterest()
    // -------------------------------------------------------------------------

    public function test_flat_rate_interest_basic(): void
    {
        // 10,000 at 12% annual for 12 months = 10000 * 0.12 * 1 = 1200
        $result = calculateTotalInterest(10000, 12, 12, 'Flat Rate');
        $this->assertEqualsWithDelta(1200.0, $result, 0.01);
    }

    public function test_flat_rate_interest_short_term(): void
    {
        // 10,000 at 12% annual for 6 months = 10000 * 0.12 * 0.5 = 600
        $result = calculateTotalInterest(10000, 12, 6, 'Flat Rate');
        $this->assertEqualsWithDelta(600.0, $result, 0.01);
    }

    public function test_emi_interest_is_positive_for_non_zero_rate(): void
    {
        $result = calculateTotalInterest(10000, 12, 12, 'EMI');
        $this->assertGreaterThan(0, $result);
    }

    public function test_reducing_balance_uses_same_formula_as_emi(): void
    {
        $emi = calculateTotalInterest(10000, 12, 12, 'EMI');
        $rb  = calculateTotalInterest(10000, 12, 12, 'Reducing Balance');
        $this->assertEqualsWithDelta($emi, $rb, 0.01);
    }

    public function test_zero_interest_rate_returns_zero(): void
    {
        $result = calculateTotalInterest(10000, 0, 12, 'EMI');
        $this->assertEquals(0, $result);
    }

    public function test_unknown_formula_falls_back_to_emi(): void
    {
        $emi     = calculateTotalInterest(10000, 12, 12, 'EMI');
        $unknown = calculateTotalInterest(10000, 12, 12, 'Unknown Formula');
        $this->assertEqualsWithDelta($emi, $unknown, 0.01);
    }

    // -------------------------------------------------------------------------
    // addMonthsWithAnchor()
    // -------------------------------------------------------------------------

    public function test_add_one_month_preserves_anchor_day(): void
    {
        $date   = new DateTime('2024-01-15');
        $result = addMonthsWithAnchor($date, 1, 15);
        $this->assertEquals('2024-02-15', $result->format('Y-m-d'));
    }

    public function test_add_months_clamps_anchor_to_last_day_of_month(): void
    {
        // Jan 31 + 1 month: February has no 31st — clamp to 29 (2024 is leap year)
        $date   = new DateTime('2024-01-31');
        $result = addMonthsWithAnchor($date, 1, 31);
        $this->assertEquals('2024-02-29', $result->format('Y-m-d'));
    }

    public function test_add_months_handles_year_rollover(): void
    {
        $date   = new DateTime('2024-11-15');
        $result = addMonthsWithAnchor($date, 2, 15);
        $this->assertEquals('2025-01-15', $result->format('Y-m-d'));
    }

    public function test_add_zero_months_returns_first_of_same_month_with_anchor(): void
    {
        $date   = new DateTime('2024-03-10');
        $result = addMonthsWithAnchor($date, 0, 10);
        $this->assertEquals('2024-03-10', $result->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // get_status_badge()
    // -------------------------------------------------------------------------

    public function test_active_returns_success(): void
    {
        $this->assertEquals('success', get_status_badge('active'));
    }

    public function test_approved_returns_success(): void
    {
        $this->assertEquals('success', get_status_badge('approved'));
    }

    public function test_pending_returns_warning(): void
    {
        $this->assertEquals('warning', get_status_badge('pending'));
    }

    public function test_rejected_returns_danger(): void
    {
        $this->assertEquals('danger', get_status_badge('rejected'));
    }

    public function test_cancelled_returns_danger(): void
    {
        $this->assertEquals('danger', get_status_badge('cancelled'));
    }

    public function test_paid_returns_info(): void
    {
        $this->assertEquals('info', get_status_badge('paid'));
    }

    public function test_unknown_status_returns_secondary(): void
    {
        $this->assertEquals('secondary', get_status_badge('not_a_real_status_xyz'));
    }

    public function test_status_badge_is_case_insensitive(): void
    {
        $this->assertEquals('success', get_status_badge('ACTIVE'));
        $this->assertEquals('danger',  get_status_badge('REJECTED'));
        $this->assertEquals('warning', get_status_badge('PENDING'));
    }

    public function test_void_returns_danger(): void
    {
        $this->assertEquals('danger', get_status_badge('void'));
    }

    public function test_reconciled_returns_success(): void
    {
        $this->assertEquals('success', get_status_badge('reconciled'));
    }

    // -------------------------------------------------------------------------
    // format_currency()
    // -------------------------------------------------------------------------

    public function test_format_currency_tzs_contains_symbol_and_amount(): void
    {
        $result = format_currency(1000, 'TZS');
        $this->assertStringContainsString('TSh', $result);
        $this->assertStringContainsString('1,000.00', $result);
    }

    public function test_format_currency_usd(): void
    {
        $result = format_currency(500.5, 'USD');
        $this->assertStringContainsString('$', $result);
        $this->assertStringContainsString('500.50', $result);
    }

    public function test_format_currency_kes(): void
    {
        $result = format_currency(2000, 'KES');
        $this->assertStringContainsString('KSh', $result);
    }

    public function test_format_currency_unknown_currency_defaults_to_tzs_symbol(): void
    {
        $result = format_currency(100, 'XYZ');
        $this->assertStringContainsString('TSh', $result);
    }

    // -------------------------------------------------------------------------
    // format_date()
    // -------------------------------------------------------------------------

    public function test_format_date_default_format(): void
    {
        $result = format_date('2024-01-15');
        $this->assertEquals('15 Jan 2024', $result);
    }

    public function test_format_date_custom_format(): void
    {
        $result = format_date('2024-01-15', 'Y/m/d');
        $this->assertEquals('2024/01/15', $result);
    }

    public function test_format_date_returns_na_for_null(): void
    {
        $this->assertEquals('N/A', format_date(null));
    }

    public function test_format_date_returns_na_for_empty_string(): void
    {
        $this->assertEquals('N/A', format_date(''));
    }

    // -------------------------------------------------------------------------
    // calculate_leave_days()
    // -------------------------------------------------------------------------

    public function test_leave_days_is_inclusive(): void
    {
        // Jan 1 to Jan 3 = 3 days (inclusive of both ends)
        $this->assertEquals(3, calculate_leave_days('2024-01-01', '2024-01-03'));
    }

    public function test_leave_days_same_day_is_one(): void
    {
        $this->assertEquals(1, calculate_leave_days('2024-06-10', '2024-06-10'));
    }

    public function test_leave_days_returns_zero_for_empty_dates(): void
    {
        $this->assertEquals(0, calculate_leave_days('', ''));
    }

    // -------------------------------------------------------------------------
    // format_phone()
    // -------------------------------------------------------------------------

    public function test_nine_digit_phone_gets_tanzania_country_code(): void
    {
        $this->assertEquals('255712345678', format_phone('712345678'));
    }

    public function test_format_phone_strips_non_numeric_characters(): void
    {
        // +255-712-345-678 → strips non-digits → 255712345678 (12 digits, not 9)
        $result = format_phone('+255712345678');
        $this->assertEquals('255712345678', $result);
    }

    public function test_format_phone_with_spaces(): void
    {
        $this->assertEquals('255712345678', format_phone('712 345 678'));
    }

    // -------------------------------------------------------------------------
    // safe_output()
    // -------------------------------------------------------------------------

    public function test_safe_output_escapes_html_tags(): void
    {
        $result = safe_output('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_safe_output_returns_default_for_empty_string(): void
    {
        $this->assertEquals('N/A', safe_output(''));
    }

    public function test_safe_output_returns_custom_default(): void
    {
        $this->assertEquals('None', safe_output('', 'None'));
    }

    public function test_safe_output_returns_value_when_present(): void
    {
        $this->assertEquals('Hello World', safe_output('Hello World'));
    }

    // -------------------------------------------------------------------------
    // get_variance_color()
    // -------------------------------------------------------------------------

    public function test_positive_variance_is_success(): void
    {
        $this->assertEquals('success', get_variance_color(100));
    }

    public function test_negative_variance_is_danger(): void
    {
        $this->assertEquals('danger', get_variance_color(-1));
    }

    public function test_zero_variance_is_info(): void
    {
        $this->assertEquals('info', get_variance_color(0));
    }

    // -------------------------------------------------------------------------
    // format_number()
    // -------------------------------------------------------------------------

    public function test_format_number_default_two_decimals(): void
    {
        $this->assertEquals('1,234.57', format_number(1234.567));
    }

    public function test_format_number_custom_decimal_places(): void
    {
        $this->assertEquals('1,234.6', format_number(1234.567, 1));
    }

    public function test_format_number_zero_decimals(): void
    {
        $this->assertEquals('1,235', format_number(1234.567, 0));
    }

    // -------------------------------------------------------------------------
    // get_variance_color() edge cases
    // -------------------------------------------------------------------------

    public function test_calculate_age_returns_na_for_empty_birth_date(): void
    {
        $this->assertEquals('N/A', calculate_age(''));
    }

    public function test_calculate_age_returns_integer_for_valid_date(): void
    {
        $age = calculate_age('1990-01-01');
        $this->assertIsInt($age);
        $this->assertGreaterThan(0, $age);
    }
}
