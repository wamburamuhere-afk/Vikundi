<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for mobile card button style change in expenses.php (Death Assistance):
 *  - Approve button: btn-success  → btn-outline-success
 *  - View button:    btn-primary  → btn-outline-primary
 *  - Delete button:  btn-danger   → btn-outline-danger
 *  - Solid filled variants must not appear on vk-btn-action inside card actions
 */
class ExpensesCardButtonsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/accounts/expenses.php');
    }

    public function test_approve_button_uses_outline_success(): void
    {
        $this->assertStringContainsString('btn-outline-success vk-btn-action', $this->src);
    }

    public function test_view_button_uses_outline_primary(): void
    {
        $this->assertStringContainsString('btn-outline-primary vk-btn-action', $this->src);
    }

    public function test_delete_button_uses_outline_danger(): void
    {
        $this->assertStringContainsString('btn-outline-danger vk-btn-action', $this->src);
    }

    public function test_no_solid_success_on_vk_btn_action(): void
    {
        $this->assertStringNotContainsString('btn-success vk-btn-action', $this->src);
    }

    public function test_no_solid_primary_on_vk_btn_action(): void
    {
        $this->assertStringNotContainsString('btn-primary vk-btn-action', $this->src);
    }

    public function test_no_solid_danger_on_vk_btn_action(): void
    {
        $this->assertStringNotContainsString('btn-danger vk-btn-action', $this->src);
    }

    public function test_card_actions_section_exists(): void
    {
        $this->assertStringContainsString('vk-card-actions', $this->src);
    }

    public function test_approve_action_still_calls_approve_function(): void
    {
        $this->assertStringContainsString('approveDeathExpense(', $this->src);
    }

    public function test_view_action_still_calls_view_function(): void
    {
        $this->assertStringContainsString('viewDeathDetails(', $this->src);
    }

    public function test_delete_action_still_calls_delete_function(): void
    {
        $this->assertStringContainsString('deleteDeathExpense(', $this->src);
    }
}
