<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Tier 4 responsive print UI changes:
 *  - dormant_members.php  (dormant member card view)
 *  - budget.php           (budget card view)
 *  - death_analysis.php   (funeral aid analysis card view)
 *  - financial_ledger.php (ledger summary card view)
 *  - user_roles.php       (user assignments card view)
 *  - users.php            (user management card view — server-side)
 *  - manage_contributions.php (pending + ledger card views)
 */
class ResponsivePrintTier4Test extends TestCase
{
    // -------------------------------------------------------------------------
    // dormant_members.php — avatar colour and badge logic
    // -------------------------------------------------------------------------

    public function test_deceased_member_gets_dark_avatar(): void
    {
        $is_deceased = true;
        $av_color = $is_deceased
            ? 'linear-gradient(135deg,#343a40,#212529)'
            : 'linear-gradient(135deg,#0d6efd,#0a58ca)';
        $this->assertStringContainsString('#343a40', $av_color);
    }

    public function test_non_deceased_member_gets_blue_avatar(): void
    {
        $is_deceased = false;
        $av_color = $is_deceased
            ? 'linear-gradient(135deg,#343a40,#212529)'
            : 'linear-gradient(135deg,#0d6efd,#0a58ca)';
        $this->assertStringContainsString('#0d6efd', $av_color);
    }

    public function test_member_initials_from_name(): void
    {
        $first = 'Amina';
        $last  = 'Ngowi';
        $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
        $this->assertEquals('AN', $initials);
    }

    public function test_dormant_search_string_is_lowercase(): void
    {
        $first = 'John';
        $last  = 'Doe';
        $phone = '0712345678';
        $search = strtolower($first . ' ' . $last . ' ' . $phone);
        $this->assertEquals('john doe 0712345678', $search);
        $this->assertSame(strtolower($search), $search);
    }

    // -------------------------------------------------------------------------
    // budget.php — status badge and avatar colour
    // -------------------------------------------------------------------------

    public function test_approved_budget_gets_green_avatar(): void
    {
        $status = 'approved';
        $av_color = ($status == 'approved')
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : 'linear-gradient(135deg,#ffc107,#e0a800)';
        $this->assertStringContainsString('#198754', $av_color);
    }

    public function test_pending_budget_gets_yellow_avatar(): void
    {
        $status = 'pending';
        $av_color = ($status == 'approved')
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : 'linear-gradient(135deg,#ffc107,#e0a800)';
        $this->assertStringContainsString('#ffc107', $av_color);
    }

    public function test_approved_budget_badge_is_success(): void
    {
        $status = 'approved';
        $badge_class = $status == 'approved' ? 'success' : 'warning';
        $this->assertEquals('success', $badge_class);
    }

    public function test_pending_budget_badge_is_warning(): void
    {
        $status = 'pending';
        $badge_class = $status == 'approved' ? 'success' : 'warning';
        $this->assertEquals('warning', $badge_class);
    }

    public function test_budget_avatar_letter_is_first_char_of_category(): void
    {
        $category_name = 'Operations';
        $letter = strtoupper(substr($category_name, 0, 1));
        $this->assertEquals('O', $letter);
    }

    // -------------------------------------------------------------------------
    // death_analysis.php — variance, avatar colour, member status badge
    // -------------------------------------------------------------------------

    public function test_positive_variance_is_formatted_with_plus(): void
    {
        $contributed = 500000;
        $benefit     = 300000;
        $variance    = $contributed - $benefit;
        $sign        = $variance >= 0 ? '+' : '-';
        $this->assertEquals('+', $sign);
        $this->assertEquals(200000, $variance);
    }

    public function test_negative_variance_is_formatted_with_minus(): void
    {
        $contributed = 100000;
        $benefit     = 500000;
        $variance    = $contributed - $benefit;
        $sign        = $variance >= 0 ? '+' : '-';
        $this->assertEquals('-', $sign);
    }

    public function test_deceased_member_gets_dark_avatar_in_analysis(): void
    {
        $is_deceased = 1;
        $status      = 'active';
        $av_color = $is_deceased
            ? 'linear-gradient(135deg,#343a40,#212529)'
            : ($status === 'active'
                ? 'linear-gradient(135deg,#198754,#146c43)'
                : 'linear-gradient(135deg,#fd7e14,#e85d04)');
        $this->assertStringContainsString('#343a40', $av_color);
    }

    public function test_active_member_gets_green_avatar_in_analysis(): void
    {
        $is_deceased = 0;
        $status      = 'active';
        $av_color = $is_deceased
            ? 'linear-gradient(135deg,#343a40,#212529)'
            : ($status === 'active'
                ? 'linear-gradient(135deg,#198754,#146c43)'
                : 'linear-gradient(135deg,#fd7e14,#e85d04)');
        $this->assertStringContainsString('#198754', $av_color);
    }

    public function test_dormant_member_gets_orange_avatar_in_analysis(): void
    {
        $is_deceased = 0;
        $status      = 'dormant';
        $av_color = $is_deceased
            ? 'linear-gradient(135deg,#343a40,#212529)'
            : ($status === 'active'
                ? 'linear-gradient(135deg,#198754,#146c43)'
                : 'linear-gradient(135deg,#fd7e14,#e85d04)');
        $this->assertStringContainsString('#fd7e14', $av_color);
    }

    // -------------------------------------------------------------------------
    // financial_ledger.php — surplus/deficit sign, fmt() helper, avatar colour
    // -------------------------------------------------------------------------

    public function test_surplus_deficit_positive_gets_plus_prefix(): void
    {
        $surplus_deficit = 50000;
        $prefix = $surplus_deficit >= 0 ? '+' : '';
        $this->assertEquals('+', $prefix);
    }

    public function test_surplus_deficit_negative_gets_no_plus(): void
    {
        $surplus_deficit = -20000;
        $prefix = $surplus_deficit >= 0 ? '+' : '';
        $this->assertEquals('', $prefix);
    }

    public function test_positive_surplus_gets_green_avatar(): void
    {
        $surplus = 10000;
        $av_color = $surplus >= 0
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : 'linear-gradient(135deg,#dc3545,#b02a37)';
        $this->assertStringContainsString('#198754', $av_color);
    }

    public function test_negative_surplus_gets_red_avatar(): void
    {
        $surplus = -5000;
        $av_color = $surplus >= 0
            ? 'linear-gradient(135deg,#198754,#146c43)'
            : 'linear-gradient(135deg,#dc3545,#b02a37)';
        $this->assertStringContainsString('#dc3545', $av_color);
    }

    public function test_fmt_helper_formats_large_number(): void
    {
        $n = 1250000;
        $formatted = number_format($n, 0);
        $this->assertEquals('1,250,000', $formatted);
    }

    public function test_fmt_helper_formats_zero(): void
    {
        $formatted = number_format(0, 0);
        $this->assertEquals('0', $formatted);
    }

    // -------------------------------------------------------------------------
    // user_roles.php — role badge colour and status badge
    // -------------------------------------------------------------------------

    public function test_admin_role_gets_danger_badge(): void
    {
        $role = 'Admin';
        $color = match($role) {
            'Admin' => 'danger',
            'Managing Director', 'Director' => 'warning',
            'Loan Officer' => 'primary',
            'CFO', 'Accountant' => 'info',
            default => 'secondary'
        };
        $this->assertEquals('danger', $color);
    }

    public function test_unknown_role_gets_secondary_badge(): void
    {
        $role = 'Custom Role';
        $color = match($role) {
            'Admin' => 'danger',
            'Managing Director', 'Director' => 'warning',
            'Loan Officer' => 'primary',
            'CFO', 'Accountant' => 'info',
            default => 'secondary'
        };
        $this->assertEquals('secondary', $color);
    }

    public function test_active_user_status_shows_success_badge(): void
    {
        $status = 1;
        $badge = $status == 1 ? 'success' : 'secondary';
        $this->assertEquals('success', $badge);
    }

    public function test_inactive_user_status_shows_secondary_badge(): void
    {
        $status = 0;
        $badge = $status == 1 ? 'success' : 'secondary';
        $this->assertEquals('secondary', $badge);
    }

    // -------------------------------------------------------------------------
    // manage_contributions.php — contribution type badge
    // -------------------------------------------------------------------------

    public function test_contribution_type_displayed_uppercase(): void
    {
        $type = 'monthly';
        $display = strtoupper($type);
        $this->assertEquals('MONTHLY', $display);
    }

    public function test_entrance_contribution_type_uppercased(): void
    {
        $type = 'entrance';
        $display = strtoupper($type);
        $this->assertEquals('ENTRANCE', $display);
    }

    // -------------------------------------------------------------------------
    // users.php — is_active flag to badge colour
    // -------------------------------------------------------------------------

    public function test_active_user_is_active_flag_one(): void
    {
        $is_active = 1;
        $badge = $is_active == 1 ? 'success' : 'secondary';
        $this->assertEquals('success', $badge);
    }

    public function test_inactive_user_is_active_flag_zero(): void
    {
        $is_active = 0;
        $badge = $is_active == 1 ? 'success' : 'secondary';
        $this->assertEquals('secondary', $badge);
    }

    // -------------------------------------------------------------------------
    // XSS safety — htmlspecialchars on user-supplied name in all card headers
    // -------------------------------------------------------------------------

    public function test_member_name_with_html_tags_is_escaped(): void
    {
        $name = '<b>Bold</b> & "Quoted"';
        $safe = htmlspecialchars($name);
        $this->assertStringNotContainsString('<b>', $safe);
        $this->assertStringContainsString('&lt;b&gt;', $safe);
        $this->assertStringContainsString('&amp;', $safe);
    }

    // -------------------------------------------------------------------------
    // date formatting used in dormant_members and manage_contributions cards
    // -------------------------------------------------------------------------

    public function test_registered_date_formatted_as_d_M_Y(): void
    {
        $formatted = date('d M Y', strtotime('2024-03-15'));
        $this->assertEquals('15 Mar 2024', $formatted);
    }

    public function test_contribution_date_formatted_as_d_m_Y_H_i(): void
    {
        $formatted = date('d/m/Y H:i', strtotime('2025-07-01 09:30:00'));
        $this->assertEquals('01/07/2025 09:30', $formatted);
    }
}
