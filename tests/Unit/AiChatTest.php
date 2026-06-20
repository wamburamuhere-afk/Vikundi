<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural/security tests for the general AI chat feature:
 *   - api/ai/chat.php endpoint
 *   - app/constant/communication/ai_chat.php page
 *   - routes + nav link wiring
 *
 * The chat is a GENERAL assistant: it must be permission-gated and must NOT
 * claim access to business data.
 */
class AiChatTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── chat endpoint ─────────────────────────────────────────────────────────

    public function test_chat_requires_authentication(): void
    {
        $this->assertStringContainsString('isAuthenticated()', $this->read('api/ai/chat.php'));
    }

    public function test_chat_checks_view_permission(): void
    {
        $this->assertStringContainsString("canView('ai_assistant')", $this->read('api/ai/chat.php'));
    }

    public function test_chat_checks_configured_and_rate_limit(): void
    {
        $src = $this->read('api/ai/chat.php');
        $this->assertStringContainsString('aiConfigured()', $src);
        $this->assertStringContainsString('aiRateLimited()', $src);
    }

    public function test_chat_system_prompt_denies_data_access(): void
    {
        $src = $this->read('api/ai/chat.php');
        // Must instruct the model that it cannot see live data or perform actions.
        $this->assertStringContainsStringIgnoringCase('do not have access', $src);
        $this->assertStringContainsStringIgnoringCase('cannot', $src);
    }

    public function test_chat_trims_history_to_control_cost(): void
    {
        $this->assertStringContainsString('array_slice($history, -10)', $this->read('api/ai/chat.php'));
    }

    public function test_chat_returns_json(): void
    {
        $this->assertStringContainsString("header('Content-Type: application/json')", $this->read('api/ai/chat.php'));
    }

    // ── chat page ─────────────────────────────────────────────────────────────

    public function test_page_requires_view_permission(): void
    {
        $this->assertStringContainsString("requireViewPermission('ai_assistant')", $this->read('app/constant/communication/ai_chat.php'));
    }

    public function test_page_has_chat_window_and_input(): void
    {
        $src = $this->read('app/constant/communication/ai_chat.php');
        $this->assertStringContainsString('aiChatWindow', $src);
        $this->assertStringContainsString('aiChatInput', $src);
        $this->assertStringContainsString('aiChatSend', $src);
    }

    public function test_page_handles_unconfigured_state(): void
    {
        $src = $this->read('app/constant/communication/ai_chat.php');
        $this->assertStringContainsString('aiConfigured()', $src);
    }

    public function test_page_is_bilingual(): void
    {
        $src = $this->read('app/constant/communication/ai_chat.php');
        $this->assertStringContainsString('Chat with AI', $src);
        $this->assertStringContainsString('Ongea na AI', $src);
    }

    // ── wiring ────────────────────────────────────────────────────────────────

    public function test_routes_registered(): void
    {
        $src = $this->read('roots.php');
        $this->assertStringContainsString("'api/ai/chat'", $src);
        $this->assertStringContainsString("'ai-chat'", $src);
    }

    public function test_nav_link_gated_by_permission(): void
    {
        $src = $this->read('header.php');
        $this->assertStringContainsString("getUrl('ai-chat')", $src);
        $this->assertStringContainsString("canView('ai_assistant')", $src);
    }
}
