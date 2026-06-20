<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests that the rebuilt email_templates.php is professional, bilingual and
 * compliant with .claude/ui-constants.md — the page previously used native
 * alert()/confirm(), green stat cards and had no Swahili at all.
 */
class EmailTemplatesPageTest extends TestCase
{
    private string $page;
    private string $header;

    protected function setUp(): void
    {
        $this->page   = file_get_contents(__DIR__ . '/../../app/constant/communication/email_templates.php');
        $this->header = file_get_contents(__DIR__ . '/../../header.php');
    }

    public function test_references_ui_constants_standard(): void
    {
        $this->assertStringContainsString('.claude/ui-constants.md', $this->page);
    }

    public function test_gates_view_permission(): void
    {
        $this->assertStringContainsString("requireViewPermission('message_center')", $this->page);
    }

    public function test_is_bilingual_language_picked_server_side(): void
    {
        // Language resolved once on the server, not toggled client-side.
        $this->assertStringContainsString("preferred_language", $this->page);
        $this->assertStringContainsString('$is_sw', $this->page);
        $this->assertStringContainsString('Violezo vya Barua Pepe', $this->page); // Swahili title
    }

    public function test_no_native_dialogs(): void
    {
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$.])alert\s*\(/', $this->page);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$])confirm\s*\(/', $this->page);
        $this->assertStringContainsString('Swal.fire', $this->page);
    }

    public function test_uses_blue_stat_cards_not_green(): void
    {
        $this->assertStringContainsString('#e7f0ff', $this->page);
        $this->assertStringNotContainsString('custom-stat-card', $this->page);
        $this->assertStringNotContainsString('#d1e7dd', $this->page); // old green
        $this->assertStringNotContainsString('btn-info', $this->page);
    }

    public function test_modal_header_is_blue(): void
    {
        $this->assertStringContainsString('modal-header bg-primary text-white', $this->page);
    }

    public function test_uses_datatable_standard_init(): void
    {
        $this->assertStringContainsString("$('#tplTable').DataTable(", $this->page);
        $this->assertStringContainsString("dom: 'rtipB'", $this->page);
    }

    public function test_uses_gear_dropdown_and_mobile_cards(): void
    {
        $this->assertStringContainsString('bi-gear-fill', $this->page);
        $this->assertStringContainsString('function renderCards', $this->page);
        $this->assertStringContainsString('id="cardView"', $this->page);
    }

    public function test_preview_renders_in_sandboxed_iframe(): void
    {
        // Template HTML is shown via a sandboxed iframe + srcdoc, never injected raw.
        $this->assertStringContainsString('iframe', $this->page);
        $this->assertStringContainsString('sandbox', $this->page);
        $this->assertStringContainsString('.srcdoc', $this->page);
    }

    public function test_uses_select2_for_type(): void
    {
        $this->assertStringContainsString('select2-static', $this->page);
        $this->assertStringContainsString("theme:'bootstrap-5'", $this->page);
    }

    public function test_links_to_email_center(): void
    {
        $this->assertStringContainsString("getUrl('email_center')", $this->page);
    }

    public function test_menu_lists_email_templates(): void
    {
        $this->assertStringContainsString("getUrl('email_templates')", $this->header);
        $this->assertStringContainsString('Violezo vya Barua Pepe', $this->header);
    }
}
