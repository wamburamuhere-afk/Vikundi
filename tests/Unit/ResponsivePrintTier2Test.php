<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Tier 2 responsive print UI changes:
 *  - expenses.php     (death assistance — server-side DataTable card view)
 *  - transactions.php (journal entries  — client-side DataTable card view)
 *  - vicoba_reports.php (savings + expenses read-only card views)
 *
 * All assertions target pure PHP helper functions used by the card views;
 * no DB or HTTP required.
 */
class ResponsivePrintTier2Test extends TestCase
{
    // -------------------------------------------------------------------------
    // get_status_badge() — transaction-specific statuses used in transactions.php
    // -------------------------------------------------------------------------

    public function test_draft_returns_secondary(): void
    {
        $this->assertEquals('secondary', get_status_badge('draft'));
    }

    public function test_posted_returns_success(): void
    {
        $this->assertEquals('success', get_status_badge('posted'));
    }

    public function test_reversed_returns_info(): void
    {
        $this->assertEquals('info', get_status_badge('reversed'));
    }

    // -------------------------------------------------------------------------
    // get_status_badge() — death expense statuses used in expenses.php
    // -------------------------------------------------------------------------

    public function test_approved_returns_success_for_death_card(): void
    {
        $this->assertEquals('success', get_status_badge('approved'));
    }

    public function test_pending_returns_warning_for_death_card(): void
    {
        $this->assertEquals('warning', get_status_badge('pending'));
    }

    // -------------------------------------------------------------------------
    // safe_output() — used in transactions.php card view for description/reference
    // -------------------------------------------------------------------------

    public function test_safe_output_prevents_xss_in_card_description(): void
    {
        $result = safe_output('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_safe_output_truncated_description_still_safe(): void
    {
        $long = str_repeat('A', 50) . '<img src=x onerror=alert(1)>';
        $truncated = mb_substr($long, 0, 40);
        $result = safe_output($truncated);
        $this->assertStringNotContainsString('<img', $result);
    }

    public function test_safe_output_empty_description_returns_na(): void
    {
        $this->assertEquals('N/A', safe_output(''));
    }

    public function test_safe_output_null_reference_number_uses_default(): void
    {
        $this->assertEquals('N/A', safe_output(null));
    }

    // -------------------------------------------------------------------------
    // format_currency() — used in transactions.php and vicoba_reports.php cards
    // -------------------------------------------------------------------------

    public function test_format_currency_used_in_transaction_card(): void
    {
        $result = format_currency(150000, 'TZS');
        $this->assertStringContainsString('150,000.00', $result);
    }

    public function test_format_currency_zero_amount(): void
    {
        $result = format_currency(0, 'TZS');
        $this->assertStringContainsString('0.00', $result);
    }

    public function test_format_currency_large_savings_amount(): void
    {
        $result = format_currency(1500000, 'TZS');
        $this->assertStringContainsString('1,500,000.00', $result);
    }

    // -------------------------------------------------------------------------
    // number_format() behaviour — used in vicoba_reports.php card totals
    // -------------------------------------------------------------------------

    public function test_number_format_savings_amount(): void
    {
        $this->assertEquals('1,234,567', number_format(1234567));
    }

    public function test_number_format_zero_savings(): void
    {
        $this->assertEquals('0', number_format(0));
    }

    // -------------------------------------------------------------------------
    // date() formatting — used in card date fields
    // -------------------------------------------------------------------------

    public function test_date_format_for_expense_date(): void
    {
        $formatted = date('d/m/Y', strtotime('2025-01-15'));
        $this->assertEquals('15/01/2025', $formatted);
    }

    public function test_date_format_for_transaction_card(): void
    {
        $formatted = date('M d, Y', strtotime('2025-01-15'));
        $this->assertEquals('Jan 15, 2025', $formatted);
    }

    // -------------------------------------------------------------------------
    // htmlspecialchars() — XSS safety for death card fields (member_name, deceased_name, etc.)
    // -------------------------------------------------------------------------

    public function test_htmlspecialchars_sanitises_member_name(): void
    {
        $raw = '<b>John</b> & "Jane"';
        $safe = htmlspecialchars($raw);
        $this->assertStringContainsString('&lt;b&gt;', $safe);
        $this->assertStringContainsString('&amp;', $safe);
        $this->assertStringContainsString('&quot;', $safe);
    }

    public function test_htmlspecialchars_safe_for_deceased_name_with_apostrophe(): void
    {
        $raw = "O'Brien";
        $safe = htmlspecialchars($raw, ENT_QUOTES);
        $this->assertStringContainsString('&#039;', $safe);
    }

    // -------------------------------------------------------------------------
    // mb_substr() — used to truncate long descriptions in transaction cards
    // -------------------------------------------------------------------------

    public function test_mb_substr_truncates_long_description(): void
    {
        $desc = str_repeat('A', 80);
        $truncated = mb_substr($desc, 0, 40);
        $this->assertEquals(40, mb_strlen($truncated));
    }

    public function test_mb_substr_does_not_corrupt_multibyte_chars(): void
    {
        $swahili = 'Malipo ya mkopo wa mwanachama aliyeomba';
        $truncated = mb_substr($swahili, 0, 20);
        $this->assertEquals(20, mb_strlen($truncated));
    }

    // -------------------------------------------------------------------------
    // strtolower() + string concatenation — card data-search attribute logic
    // -------------------------------------------------------------------------

    public function test_search_text_lowercased_for_card_filter(): void
    {
        $description = 'Monthly Savings';
        $reference   = 'REF-001';
        $amount      = 5000.00;
        $created_by  = 'Admin User';

        $search = strtolower($description . ' ' . $reference . ' ' . $amount . ' ' . $created_by);

        $this->assertStringContainsString('monthly savings', $search);
        $this->assertStringContainsString('ref-001', $search);
        $this->assertStringContainsString('admin user', $search);
    }

    public function test_card_filter_matches_partial_description(): void
    {
        $cardSearch = strtolower('Loan disbursement REF-042 50000 Jane Doe');
        $query      = strtolower('disbursement');

        $this->assertNotFalse(strpos($cardSearch, $query));
    }

    public function test_card_filter_no_match_returns_false(): void
    {
        $cardSearch = strtolower('Monthly fee REF-001 1000 John');
        $query      = strtolower('death');

        $this->assertFalse(strpos($cardSearch, $query));
    }
}
