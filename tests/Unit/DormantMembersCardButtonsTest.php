<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for mobile card button style change in dormant_members.php:
 *  - Statement button changed from btn-primary to btn-outline-primary
 *  - Delete button changed from btn-danger to btn-outline-danger
 *  - Solid filled variants must not appear on vk-btn-action inside card actions
 */
class DormantMembersCardButtonsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/bms/customer/dormant_members.php');
    }

    public function test_statement_button_uses_outline_primary(): void
    {
        $this->assertStringContainsString('vk-btn-action btn-outline-primary', $this->src);
    }

    public function test_delete_button_uses_outline_danger(): void
    {
        $this->assertStringContainsString('vk-btn-action btn-outline-danger', $this->src);
    }

    public function test_no_solid_primary_on_vk_btn_action(): void
    {
        $this->assertStringNotContainsString('vk-btn-action btn-primary', $this->src);
    }

    public function test_no_solid_danger_on_vk_btn_action(): void
    {
        $this->assertStringNotContainsString('vk-btn-action btn-danger', $this->src);
    }

    public function test_card_actions_section_exists(): void
    {
        $this->assertStringContainsString('class="vk-card-actions"', $this->src);
    }

    public function test_statement_action_still_links_to_member_statement(): void
    {
        $this->assertStringContainsString("getUrl('member_statement')", $this->src);
    }

    public function test_delete_action_still_calls_delete_dormant(): void
    {
        $this->assertStringContainsString('deleteDormant(', $this->src);
    }
}
