<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for mobile card view changes in budget.php:
 *  - Card action buttons use outline (white-background) variants
 *  - Mobile action row: Print icon, Export icon, Show dropdown
 *  - Prev/Next pagination strip
 *  - Page-aware drawCallback (index-based show/hide)
 *  - JS helpers: budgetTablePage, updateBudgetPageInfo, changeBudgetLen
 */
class MobileCardViewsBudgetTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/budget.php');
    }

    // ── Card action buttons — outline (white background) ─────────────────────

    public function test_view_button_uses_outline_primary(): void
    {
        $this->assertStringContainsString('btn-outline-primary', $this->src);
        $this->assertStringNotContainsString('vk-btn-action btn-primary"', $this->src);
    }

    public function test_edit_button_uses_outline_warning(): void
    {
        $this->assertStringContainsString('btn-outline-warning', $this->src);
    }

    public function test_status_button_uses_outline_secondary(): void
    {
        $this->assertStringContainsString('btn-outline-secondary', $this->src);
    }

    public function test_delete_button_uses_outline_danger(): void
    {
        $this->assertStringContainsString('btn-outline-danger', $this->src);
    }

    public function test_no_solid_colored_vk_btn_action_in_cards(): void
    {
        // Solid btn-danger must not appear on a vk-btn-action inside card actions
        $this->assertStringNotContainsString('vk-btn-action btn-danger"', $this->src);
        $this->assertStringNotContainsString('vk-btn-action btn-warning"', $this->src);
    }

    // ── Mobile action row ─────────────────────────────────────────────────────

    public function test_mobile_action_row_exists(): void
    {
        $this->assertStringContainsString('d-flex d-md-none flex-nowrap gap-1', $this->src);
    }

    public function test_mobile_print_icon_button_exists(): void
    {
        $this->assertStringContainsString("button('.buttons-print').trigger()", $this->src);
        $this->assertStringContainsString('bi-printer', $this->src);
    }

    public function test_mobile_export_icon_button_exists(): void
    {
        $this->assertStringContainsString("button('.buttons-excel').trigger()", $this->src);
        $this->assertStringContainsString('bi-file-excel', $this->src);
    }

    public function test_mobile_show_dropdown_exists(): void
    {
        $this->assertStringContainsString('changeBudgetLen(10)', $this->src);
        $this->assertStringContainsString('changeBudgetLen(25)', $this->src);
        $this->assertStringContainsString('changeBudgetLen(50)', $this->src);
        $this->assertStringContainsString('changeBudgetLen(-1)', $this->src);
    }

    // ── Prev/Next pagination ──────────────────────────────────────────────────

    public function test_prev_button_exists(): void
    {
        $this->assertStringContainsString('id="budgetPrevBtn"', $this->src);
        $this->assertStringContainsString("budgetTablePage('previous')", $this->src);
    }

    public function test_next_button_exists(): void
    {
        $this->assertStringContainsString('id="budgetNextBtn"', $this->src);
        $this->assertStringContainsString("budgetTablePage('next')", $this->src);
    }

    public function test_page_info_span_exists(): void
    {
        $this->assertStringContainsString('id="budgetPageInfo"', $this->src);
    }

    // ── JS helper functions ───────────────────────────────────────────────────

    public function test_budget_table_page_function_exists(): void
    {
        $this->assertStringContainsString('function budgetTablePage(', $this->src);
    }

    public function test_update_budget_page_info_function_exists(): void
    {
        $this->assertStringContainsString('function updateBudgetPageInfo(', $this->src);
    }

    public function test_change_budget_len_function_exists(): void
    {
        $this->assertStringContainsString('function changeBudgetLen(', $this->src);
    }

    // ── drawCallback — page-aware filtering ───────────────────────────────────

    public function test_draw_callback_uses_page_info(): void
    {
        $this->assertStringContainsString('info.start', $this->src);
        $this->assertStringContainsString('info.end', $this->src);
    }

    public function test_draw_callback_calls_update_page_info(): void
    {
        $this->assertStringContainsString('updateBudgetPageInfo()', $this->src);
    }

    public function test_cards_are_hidden_before_page_slice_applied(): void
    {
        $this->assertStringContainsString('$(this).hide()', $this->src);
    }
}
