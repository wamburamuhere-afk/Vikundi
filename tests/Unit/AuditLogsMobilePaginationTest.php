<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for mobile pagination changes in audit_logs.php:
 *  - Simple Prev/Next strip added at end of mobile card view
 *  - Existing desktop pagination ("Showing X to Y of Z" + numbered buttons)
 *    hidden on mobile via d-none d-md-block
 *  - JS helpers: auditTablePage(), updateAuditMobilePageInfo()
 *  - loadPage() updated to sync auditTotalPages from AJAX response
 */
class AuditLogsMobilePaginationTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/audit_logs.php');
    }

    // ── Mobile Prev/Next strip ────────────────────────────────────────────────

    public function test_prev_button_exists(): void
    {
        $this->assertStringContainsString('id="auditPrevBtn"', $this->src);
        $this->assertStringContainsString("auditTablePage('previous')", $this->src);
    }

    public function test_next_button_exists(): void
    {
        $this->assertStringContainsString('id="auditNextBtn"', $this->src);
        $this->assertStringContainsString("auditTablePage('next')", $this->src);
    }

    public function test_page_info_span_exists(): void
    {
        $this->assertStringContainsString('id="auditPageInfo"', $this->src);
    }

    public function test_prev_next_strip_is_mobile_only(): void
    {
        $this->assertStringContainsString('d-flex d-md-none', $this->src);
    }

    // ── Desktop pagination hidden on mobile ───────────────────────────────────

    public function test_desktop_pagination_has_d_none_d_md_block(): void
    {
        $this->assertStringContainsString('d-none d-md-block', $this->src);
    }

    public function test_desktop_pagination_wrapper_not_always_visible(): void
    {
        // The outer pagination div must NOT use plain "d-flex" without d-md-*
        // (it must be restricted to desktop)
        $this->assertStringNotContainsString('Modern Pagination -->
    <div class="mt-4 no-print">
        <div class="d-flex flex-wrap', $this->src);
    }

    // ── JS helpers ────────────────────────────────────────────────────────────

    public function test_audit_table_page_function_exists(): void
    {
        $this->assertStringContainsString('function auditTablePage(', $this->src);
    }

    public function test_update_audit_mobile_page_info_function_exists(): void
    {
        $this->assertStringContainsString('function updateAuditMobilePageInfo(', $this->src);
    }

    public function test_audit_total_pages_js_var_exists(): void
    {
        $this->assertStringContainsString('var auditTotalPages =', $this->src);
    }

    public function test_audit_table_page_respects_boundaries(): void
    {
        $this->assertStringContainsString('newPage < 1 || newPage > auditTotalPages', $this->src);
    }

    // ── loadPage() syncs total_pages from AJAX ────────────────────────────────

    public function test_load_page_updates_audit_total_pages_from_ajax(): void
    {
        $this->assertStringContainsString('auditTotalPages = res.total_pages', $this->src);
    }

    public function test_load_page_calls_update_mobile_page_info(): void
    {
        $this->assertStringContainsString('updateAuditMobilePageInfo()', $this->src);
    }

    // ── Page functions still wire to existing loadPage ────────────────────────

    public function test_audit_table_page_calls_load_page(): void
    {
        $this->assertStringContainsString('loadPage(newPage)', $this->src);
    }
}
