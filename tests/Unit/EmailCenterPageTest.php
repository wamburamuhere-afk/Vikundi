<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static-source tests for the Email Center feature (comms > Email):
 *  - The UI page follows .claude/ui-constants.md (§UI-1…§UI-8)
 *  - The API enforces session + RBAC + audit logging
 *  - Routes and the navigation menu are wired up
 */
class EmailCenterPageTest extends TestCase
{
    private string $page;
    private string $api;
    private string $roots;
    private string $header;

    protected function setUp(): void
    {
        $this->page   = file_get_contents(__DIR__ . '/../../app/constant/communication/email_center.php');
        $this->api    = file_get_contents(__DIR__ . '/../../api/email_center.php');
        $this->roots  = file_get_contents(__DIR__ . '/../../roots.php');
        $this->header = file_get_contents(__DIR__ . '/../../header.php');
    }

    // ----- UI page compliance ----------------------------------------------

    public function test_page_references_ui_constants_standard(): void
    {
        $this->assertStringContainsString('.claude/ui-constants.md', $this->page);
    }

    public function test_page_gates_view_permission(): void
    {
        $this->assertStringContainsString("requireViewPermission('message_center')", $this->page);
    }

    public function test_page_uses_blue_primary_compose_button_not_success(): void
    {
        $this->assertStringContainsString('btn btn-primary', $this->page);
        $this->assertStringNotContainsString('btn-success', $this->page);
        $this->assertStringNotContainsString('btn-warning', $this->page);
    }

    public function test_modal_header_is_blue(): void
    {
        $this->assertStringContainsString('modal-header bg-primary text-white', $this->page);
    }

    public function test_page_uses_datatable(): void
    {
        $this->assertStringContainsString("$('#emailTable').DataTable(", $this->page);
        $this->assertStringContainsString("dom: 'rtipB'", $this->page);
    }

    public function test_page_uses_gear_dropdown_actions(): void
    {
        $this->assertStringContainsString('bi-gear-fill', $this->page);
        $this->assertStringContainsString('dropdown-menu dropdown-menu-end', $this->page);
    }

    public function test_page_has_mobile_card_view(): void
    {
        $this->assertStringContainsString("id=\"cardView\"", $this->page);
        $this->assertStringContainsString('function renderCards', $this->page);
    }

    public function test_page_uses_sweetalert_not_native_dialogs(): void
    {
        $this->assertStringContainsString('Swal.fire', $this->page);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$])confirm\s*\(/', $this->page);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$.])alert\s*\(/', $this->page);
    }

    public function test_page_uses_select2_for_recipients(): void
    {
        $this->assertStringContainsString(".select2(", $this->page);
        $this->assertStringContainsString("theme: 'bootstrap-5'", $this->page);
    }

    public function test_page_is_bilingual(): void
    {
        $this->assertStringContainsString("preferred_language", $this->page);
        $this->assertStringContainsString('Barua Pepe', $this->page);
    }

    public function test_page_uses_bootstrap_icons_only(): void
    {
        $this->assertStringContainsString('bi bi-envelope', $this->page);
        $this->assertStringNotContainsString('fa-', $this->page);
    }

    // ----- API compliance ---------------------------------------------------

    public function test_api_checks_authentication(): void
    {
        $this->assertStringContainsString("isset(\$_SESSION['user_id'])", $this->api);
    }

    public function test_api_enforces_view_create_delete_permissions(): void
    {
        $this->assertStringContainsString('canView(', $this->api);
        $this->assertStringContainsString('canCreate(', $this->api);
        $this->assertStringContainsString('canDelete(', $this->api);
    }

    public function test_api_writes_audit_logs(): void
    {
        $this->assertStringContainsString('logCreate(', $this->api);
        $this->assertStringContainsString('logDelete(', $this->api);
    }

    public function test_api_uses_prepared_statements(): void
    {
        $this->assertStringContainsString('$pdo->prepare(', $this->api);
    }

    public function test_api_returns_json(): void
    {
        $this->assertStringContainsString("header('Content-Type: application/json')", $this->api);
    }

    public function test_api_supports_core_actions(): void
    {
        foreach (["case 'list'", "case 'get'", "case 'send'", "case 'resend'", "case 'delete'", "case 'recipients'"] as $action) {
            $this->assertStringContainsString($action, $this->api);
        }
    }

    // ----- Routing & menu ---------------------------------------------------

    public function test_page_route_registered(): void
    {
        $this->assertStringContainsString("'email_center' => COMMUNICATION_DIR . '/email_center.php'", $this->roots);
        $this->assertStringContainsString("'communication/email_center' => COMMUNICATION_DIR . '/email_center.php'", $this->roots);
    }

    public function test_api_route_registered(): void
    {
        $this->assertStringContainsString("'api/email_center' => API_DIR . '/email_center.php'", $this->roots);
    }

    public function test_menu_links_email_to_email_center(): void
    {
        $this->assertStringContainsString("getUrl('email_center')", $this->header);
        // The old dead placeholder for Email must be gone.
        $this->assertDoesNotMatchRegularExpression(
            '/href="#"><i class="bi bi-envelope"><\/i>/',
            $this->header
        );
    }
}
