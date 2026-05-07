<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for mobile card view changes in document_library.php:
 *  - Mobile header: compact h5 title + Upload/Export buttons in one row
 *  - Stats cards: 2-per-row on mobile (col-6), equal size via CSS grid
 *  - Icons hidden on mobile
 *  - Table hidden on mobile, card view shown instead
 *  - Card view renders via renderDocCards() in drawCallback
 *  - Prev/Next pagination strip
 *  - _sw and userPermissions moved to global scope
 */
class DocumentLibraryMobileTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/document/document_library.php');
    }

    // ── Mobile header ─────────────────────────────────────────────────────────

    public function test_mobile_h5_title_exists(): void
    {
        $this->assertStringContainsString('d-md-none', $this->src);
        $this->assertStringContainsString('Document Library', $this->src);
    }

    public function test_desktop_header_hidden_on_mobile(): void
    {
        $this->assertStringContainsString('d-none d-md-flex', $this->src);
    }

    public function test_upload_button_is_primary(): void
    {
        $this->assertStringContainsString('btn btn-primary btn-sm flex-fill', $this->src);
    }

    public function test_export_mobile_button_exists(): void
    {
        $this->assertStringContainsString('id="exportDocumentsMobile"', $this->src);
        $this->assertStringContainsString('btn-outline-primary btn-sm flex-fill', $this->src);
    }

    public function test_both_export_buttons_wired_to_excel(): void
    {
        $this->assertStringContainsString('#exportDocuments, #exportDocumentsMobile', $this->src);
        $this->assertStringContainsString("button('.buttons-excel').trigger()", $this->src);
    }

    // ── Stats cards — 2-per-row on mobile ────────────────────────────────────

    public function test_stats_cards_use_col6(): void
    {
        $this->assertStringContainsString('col-6 col-md-3', $this->src);
    }

    public function test_stats_row_has_id_for_css_grid(): void
    {
        $this->assertStringContainsString('id="docStatsRow"', $this->src);
    }

    public function test_stats_cards_have_h100(): void
    {
        $this->assertStringContainsString('card custom-stat-card h-100', $this->src);
    }

    public function test_stat_label_class_applied(): void
    {
        $this->assertStringContainsString('class="mb-0 stat-label"', $this->src);
    }

    public function test_icons_hidden_on_mobile(): void
    {
        $this->assertStringContainsString('d-none d-md-block', $this->src);
    }

    public function test_css_grid_applied_to_stats_row(): void
    {
        $this->assertStringContainsString('#docStatsRow', $this->src);
        $this->assertStringContainsString('grid-auto-rows: 1fr', $this->src);
        $this->assertStringContainsString('grid-template-columns: 1fr 1fr', $this->src);
    }

    // ── Table hidden on mobile, card view shown ───────────────────────────────

    public function test_table_hidden_on_mobile(): void
    {
        $this->assertStringContainsString('table-responsive d-none d-md-block d-print-block', $this->src);
    }

    public function test_card_wrapper_exists(): void
    {
        $this->assertStringContainsString('id="docCardsWrapper"', $this->src);
        $this->assertStringContainsString('d-md-none d-print-none', $this->src);
    }

    // ── Prev/Next pagination ──────────────────────────────────────────────────

    public function test_prev_button_exists(): void
    {
        $this->assertStringContainsString('id="docPrevBtn"', $this->src);
        $this->assertStringContainsString("docTablePage('previous')", $this->src);
    }

    public function test_next_button_exists(): void
    {
        $this->assertStringContainsString('id="docNextBtn"', $this->src);
        $this->assertStringContainsString("docTablePage('next')", $this->src);
    }

    public function test_page_info_span_exists(): void
    {
        $this->assertStringContainsString('id="docPageInfo"', $this->src);
    }

    // ── JS helper functions ───────────────────────────────────────────────────

    public function test_render_doc_cards_function_exists(): void
    {
        $this->assertStringContainsString('function renderDocCards(', $this->src);
    }

    public function test_doc_table_page_function_exists(): void
    {
        $this->assertStringContainsString('function docTablePage(', $this->src);
    }

    public function test_update_doc_page_info_function_exists(): void
    {
        $this->assertStringContainsString('function updateDocPageInfo(', $this->src);
    }

    public function test_draw_callback_calls_render_and_page_info(): void
    {
        $this->assertStringContainsString('renderDocCards(this.api())', $this->src);
        $this->assertStringContainsString('updateDocPageInfo()', $this->src);
    }

    // ── Global scope for _sw and userPermissions ──────────────────────────────

    public function test_sw_defined_globally(): void
    {
        // Must appear before $(document).ready — i.e., the const must not be
        // inside the ready block (checked by order in source)
        $readyPos    = strpos($this->src, '$(document).ready(');
        $swPos       = strpos($this->src, 'const _sw =');
        $this->assertNotFalse($swPos);
        $this->assertNotFalse($readyPos);
        $this->assertLessThan($readyPos, $swPos, '_sw must be defined before $(document).ready()');
    }

    public function test_user_permissions_defined_globally(): void
    {
        $readyPos  = strpos($this->src, '$(document).ready(');
        $permPos   = strpos($this->src, 'const userPermissions =');
        $this->assertNotFalse($permPos);
        $this->assertLessThan($readyPos, $permPos, 'userPermissions must be defined before $(document).ready()');
    }

    // ── Card view renders correct action buttons ──────────────────────────────

    public function test_card_download_button_is_outline_primary(): void
    {
        $this->assertStringContainsString('btn-outline-primary', $this->src);
    }

    public function test_card_view_button_is_outline_secondary(): void
    {
        $this->assertStringContainsString('btn-outline-secondary', $this->src);
    }

    public function test_card_delete_button_is_outline_danger(): void
    {
        $this->assertStringContainsString('btn-outline-danger', $this->src);
    }
}
