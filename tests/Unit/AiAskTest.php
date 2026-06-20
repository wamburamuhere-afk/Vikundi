<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural + safety tests for "Ask Vikundi" (Phase 2 — data-aware Q&A).
 *   - api/ai/ask.php (function-calling loop)
 *   - core/ai_insights.php (read-only registry)
 *   - app/constant/communication/ai_ask.php (page)
 *   - routes + nav wiring
 */
class AiAskTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── endpoint security ─────────────────────────────────────────────────────

    public function test_ask_requires_authentication(): void
    {
        $this->assertStringContainsString('isAuthenticated()', $this->read('api/ai/ask.php'));
    }

    public function test_ask_checks_data_permission(): void
    {
        $this->assertStringContainsString("canView('ai_ask_data')", $this->read('api/ai/ask.php'));
    }

    public function test_ask_checks_configured_and_rate_limit(): void
    {
        $src = $this->read('api/ai/ask.php');
        $this->assertStringContainsString('aiConfigured()', $src);
        $this->assertStringContainsString('aiRateLimited()', $src);
    }

    public function test_ask_uses_function_calling_loop(): void
    {
        $src = $this->read('api/ai/ask.php');
        $this->assertStringContainsString('aiInsightCatalog()', $src);
        $this->assertStringContainsString('aiRunInsight', $src);
        $this->assertStringContainsString('_ai_extract_call', $src);
    }

    public function test_ask_forbids_sql_and_invention_in_prompt(): void
    {
        $src = $this->read('api/ai/ask.php');
        $this->assertStringContainsStringIgnoringCase('NEVER invent', $src);
        $this->assertStringContainsStringIgnoringCase('Do not output SQL', $src);
    }

    // ── insights registry is READ-ONLY (critical safety guarantee) ────────────

    public function test_insights_contain_no_write_sql(): void
    {
        $src = $this->read('core/ai_insights.php');
        $this->assertStringNotContainsStringIgnoringCase('INSERT INTO', $src);
        $this->assertStringNotContainsStringIgnoringCase('DELETE FROM', $src);
        $this->assertStringNotContainsStringIgnoringCase('DROP TABLE', $src);
        $this->assertStringNotContainsStringIgnoringCase('TRUNCATE', $src);
        $this->assertDoesNotMatchRegularExpression('/\bUPDATE\s+\w+\s+SET\b/i', $src);
    }

    public function test_insights_expose_catalog_and_runner(): void
    {
        $src = $this->read('core/ai_insights.php');
        $this->assertStringContainsString('function aiInsightCatalog', $src);
        $this->assertStringContainsString('function aiRunInsight', $src);
    }

    public function test_insights_use_confirmed_status_filter(): void
    {
        // Contribution sums must use the app-wide confirmed filter, not a single status.
        $this->assertStringContainsString("status IN ('confirmed','approved','')", $this->read('core/ai_insights.php'));
    }

    // ── page ──────────────────────────────────────────────────────────────────

    public function test_page_requires_data_permission(): void
    {
        $this->assertStringContainsString("requireViewPermission('ai_ask_data')", $this->read('app/constant/communication/ai_ask.php'));
    }

    public function test_page_shows_data_sources_used(): void
    {
        // Transparency: the page renders the "used" functions returned by the API.
        $this->assertStringContainsString('used', $this->read('app/constant/communication/ai_ask.php'));
        $this->assertStringContainsString('ai-source', $this->read('app/constant/communication/ai_ask.php'));
    }

    public function test_page_is_bilingual(): void
    {
        $src = $this->read('app/constant/communication/ai_ask.php');
        $this->assertStringContainsString('Ask Vikundi', $src);
        $this->assertStringContainsString('Uliza Vikundi', $src);
    }

    // ── wiring ────────────────────────────────────────────────────────────────

    public function test_routes_registered(): void
    {
        $src = $this->read('roots.php');
        $this->assertStringContainsString("'api/ai/ask'", $src);
        $this->assertStringContainsString("'ask-vikundi'", $src);
    }

    public function test_nav_link_gated_by_data_permission(): void
    {
        $src = $this->read('header.php');
        $this->assertStringContainsString("getUrl('ask-vikundi')", $src);
        $this->assertStringContainsString("canView('ai_ask_data')", $src);
    }
}
