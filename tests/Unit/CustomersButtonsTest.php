<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Functional and Wiring tests for c:\wamp64\www\vikundi\app\bms\customer\customers.php
 * This test verifies the presence and wiring of all 46 buttons identified.
 */
class CustomersButtonsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $filePath = __DIR__ . '/../../app/bms/customer/customers.php';
        $this->src = file_get_contents($filePath);
    }

    // -------------------------------------------------------------------------
    // Global & Toolbar Actions
    // -------------------------------------------------------------------------

    public function test_import_members_button_exists(): void
    {
        $this->assertStringContainsString('data-bs-target="#importMemberModal"', $this->src);
        // Button text simplified to 'Import' on mobile (was 'Import Members')
        $this->assertStringContainsString("'Import'", $this->src);
    }

    public function test_register_member_button_exists(): void
    {
        $this->assertStringContainsString('data-bs-target="#addMemberModal"', $this->src);
        // Button text simplified to 'Register' on mobile (was 'Register Member')
        $this->assertStringContainsString("'Register'", $this->src);
    }

    public function test_print_list_button_exists(): void
    {
        $this->assertStringContainsString('onclick="window.print()"', $this->src);
        $this->assertStringContainsString('Print List', $this->src);
    }

    public function test_export_excel_button_exists(): void
    {
        $this->assertStringContainsString('onclick="exportMembers()"', $this->src);
        $this->assertStringContainsString('Export Excel', $this->src);
    }

    public function test_table_length_dropdown_exists(): void
    {
        $this->assertStringContainsString('id="lengthMenuBtn"', $this->src);
        $this->assertStringContainsString('onclick="changeTableLength(10)"', $this->src);
        $this->assertStringContainsString('onclick="changeTableLength(25)"', $this->src);
        $this->assertStringContainsString('onclick="changeTableLength(50)"', $this->src);
        $this->assertStringContainsString('onclick="changeTableLength(100)"', $this->src);
        $this->assertStringContainsString('onclick="changeTableLength(-1)"', $this->src);
    }

    // -------------------------------------------------------------------------
    // Table Actions (Per Member)
    // -------------------------------------------------------------------------

    public function test_table_row_actions_exist(): void
    {
        // Settings Gear
        $this->assertStringContainsString('bi-gear-fill', $this->src);
        // Financial Statement
        $this->assertStringContainsString('getUrl(\'member_statement\')', $this->src);
        // View Details
        $this->assertStringContainsString('getUrl(\'profile\')', $this->src);
        // Change Status
        $this->assertStringContainsString('onclick="openStatusModal(', $this->src);
        // Delete
        $this->assertStringContainsString('onclick="deleteMember(', $this->src);
    }

    // -------------------------------------------------------------------------
    // Mobile Card Actions
    // -------------------------------------------------------------------------

    public function test_mobile_card_actions_exist(): void
    {
        $this->assertStringContainsString('class="vk-card-actions"', $this->src);
        $this->assertStringContainsString('btn-outline-info vk-btn-action', $this->src);
        $this->assertStringContainsString('btn-outline-primary vk-btn-action', $this->src);
        $this->assertStringContainsString('btn-outline-warning vk-btn-action', $this->src);
        $this->assertStringContainsString('btn-outline-secondary vk-btn-action', $this->src);
        $this->assertStringContainsString('btn-outline-danger vk-btn-action', $this->src);
    }

    // -------------------------------------------------------------------------
    // Registration Modal (addMemberModal)
    // -------------------------------------------------------------------------

    public function test_registration_modal_buttons_exist(): void
    {
        // Tabs
        $this->assertStringContainsString('id="personal-tab"', $this->src);
        $this->assertStringContainsString('id="home-tab"', $this->src);
        $this->assertStringContainsString('id="account-tab"', $this->src);
        
        // Navigation
        $this->assertStringContainsString('onclick="switchTab(\'home\')"', $this->src);
        $this->assertStringContainsString('onclick="switchTab(\'personal\')"', $this->src);
        $this->assertStringContainsString('onclick="switchTab(\'account\')"', $this->src);

        // Dynamic Rows
        $this->assertStringContainsString('onclick="addChildRowAdmin()"', $this->src);
        $this->assertStringContainsString('onclick="removeRowAdmin(this)"', $this->src);

        // Password Toggles
        $this->assertStringContainsString('onclick="togglePasswordAdmin(', $this->src);

        // Language
        $this->assertStringContainsString('onclick="setRegLang(\'en\')"', $this->src);
        $this->assertStringContainsString('onclick="setRegLang(\'sw\')"', $this->src);

        // Reset Religion
        $this->assertStringContainsString('onclick="resetReligionSelectModal()"', $this->src);
        $this->assertStringContainsString('onclick="resetSpouseReligionAdmin()"', $this->src);

        // Submit
        $this->assertStringContainsString('type="submit"', $this->src);
        $this->assertStringContainsString('COMPLETE REGISTRATION', $this->src);
    }

    // -------------------------------------------------------------------------
    // Status & Role & Import Modals
    // -------------------------------------------------------------------------

    public function test_status_modal_buttons_exist(): void
    {
        $this->assertStringContainsString('id="statusModal"', $this->src);
        $this->assertStringContainsString('onclick="submitStatus(\'active\')"', $this->src);
        $this->assertStringContainsString('onclick="submitStatus(\'inactive\')"', $this->src);
        $this->assertStringContainsString('onclick="submitStatus(\'pending\')"', $this->src);
        $this->assertStringContainsString('onclick="submitStatus(\'suspended\')"', $this->src);
    }

    public function test_role_modal_buttons_exist(): void
    {
        $this->assertStringContainsString('id="roleModal"', $this->src);
        $this->assertStringContainsString('onclick="submitRole(\'Member\')"', $this->src);
        $this->assertStringContainsString('onclick="submitRole(\'Secretary\')"', $this->src);
        $this->assertStringContainsString('onclick="submitRole(\'Treasurer\')"', $this->src);
        $this->assertStringContainsString('onclick="submitRole(\'Katibu\')"', $this->src);
    }

    public function test_import_modal_buttons_exist(): void
    {
        $this->assertStringContainsString('id="importMemberModal"', $this->src);
        $this->assertStringContainsString('onclick="downloadTemplate()"', $this->src);
        $this->assertStringContainsString('id="btnImportSubmit"', $this->src);
        $this->assertStringContainsString('START IMPORT', $this->src);
    }
}
