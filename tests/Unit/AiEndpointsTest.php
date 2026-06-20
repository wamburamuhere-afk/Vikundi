<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural/wiring tests for the AI API endpoints, the settings page and the
 * widget JS. These guard the security gates and integration points without
 * making real network calls.
 */
class AiEndpointsTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── api/ai/generate.php ───────────────────────────────────────────────────

    public function test_generate_requires_authentication(): void
    {
        $this->assertStringContainsString('isAuthenticated()', $this->read('api/ai/generate.php'));
    }

    public function test_generate_checks_create_permission(): void
    {
        $this->assertStringContainsString("canCreate('ai_assistant')", $this->read('api/ai/generate.php'));
    }

    public function test_generate_checks_configured_and_rate_limit(): void
    {
        $src = $this->read('api/ai/generate.php');
        $this->assertStringContainsString('aiConfigured()', $src);
        $this->assertStringContainsString('aiRateLimited()', $src);
    }

    public function test_generate_returns_json(): void
    {
        $this->assertStringContainsString("header('Content-Type: application/json')", $this->read('api/ai/generate.php'));
    }

    // ── api/ai/save_settings.php ──────────────────────────────────────────────

    public function test_save_settings_is_admin_gated(): void
    {
        $src = $this->read('api/ai/save_settings.php');
        $this->assertStringContainsString("canEdit('ai_settings')", $src);
        $this->assertStringContainsString('isAdmin()', $src);
    }

    public function test_save_settings_encrypts_key(): void
    {
        $src = $this->read('api/ai/save_settings.php');
        $this->assertStringContainsString('aiEncryptSecret', $src);
        $this->assertStringContainsString('ai_api_key_enc', $src);
    }

    public function test_save_settings_keeps_existing_key_when_blank(): void
    {
        // Must only overwrite the stored key when a new non-empty one is supplied.
        $src = $this->read('api/ai/save_settings.php');
        $this->assertStringContainsString("\$newKey !== ''", $src);
    }

    // ── api/ai/test_connection.php ────────────────────────────────────────────

    public function test_test_connection_is_admin_gated(): void
    {
        $src = $this->read('api/ai/test_connection.php');
        $this->assertStringContainsString("canEdit('ai_settings')", $src);
    }

    // ── settings page ─────────────────────────────────────────────────────────

    public function test_settings_page_requires_view_permission(): void
    {
        $this->assertStringContainsString("requireViewPermission('ai_settings')", $this->read('app/constant/settings/ai_settings.php'));
    }

    public function test_settings_page_is_bilingual(): void
    {
        $src = $this->read('app/constant/settings/ai_settings.php');
        $this->assertStringContainsString('preferred_language', $src);
        $this->assertStringContainsString('Test Connection', $src);
    }

    // ── widget JS ─────────────────────────────────────────────────────────────

    public function test_widget_injects_modal_and_handles_insert(): void
    {
        $js = $this->read('assets/js/ai-assistant.js');
        $this->assertStringContainsString('aiAssistModal', $js);
        $this->assertStringContainsString('ai-insert', $js);
        $this->assertStringContainsString('ai-assist-btn', $js);
    }

    public function test_widget_supports_bilingual(): void
    {
        $js = $this->read('assets/js/ai-assistant.js');
        $this->assertStringContainsString('IS_SW', $js);
    }

    // ── message_center integration ────────────────────────────────────────────

    public function test_message_center_has_ai_button_gated_by_permission(): void
    {
        $src = $this->read('app/constant/communication/message_center.php');
        $this->assertStringContainsString("canCreate('ai_assistant')", $src);
        $this->assertStringContainsString('ai-assist-btn', $src);
        $this->assertStringContainsString('ai-assistant.js', $src);
    }
}
