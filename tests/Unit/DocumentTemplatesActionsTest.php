<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for app/constant/document/document_templates.php
 *
 * Bugs fixed:
 * 1. Action dropdown used bi-three-dots-vertical instead of bi-gear (BMS standard).
 * 2. Only 2 actions existed (Preview, Edit) instead of BMS's 4
 *    (Generate Doc, Preview/View, Edit Details, Delete).
 * 3. onclick handlers passed template name as a JS string argument using
 *    single-quote wrapping: generateDoc(1,'Manager's Letter') — any name
 *    with a single quote caused a JS syntax error that silently broke the click.
 *    Fix: store row data in tplRows map keyed by integer ID; onclick passes
 *    only the integer ID; functions look up the name from tplRows[id].
 */
class DocumentTemplatesActionsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(
            __DIR__ . '/../../app/constant/document/document_templates.php'
        );
    }

    // ── Dropdown trigger icon ─────────────────────────────────────────────────

    public function test_action_button_uses_gear_icon(): void
    {
        $this->assertStringContainsString(
            'bi bi-gear',
            $this->src,
            'Action dropdown must use bi-gear icon to match BMS standard'
        );
    }

    public function test_three_dots_icon_not_used(): void
    {
        $this->assertStringNotContainsString(
            'bi-three-dots-vertical',
            $this->src,
            'bi-three-dots-vertical must be replaced with bi-gear'
        );
    }

    // ── All 4 BMS action items present ────────────────────────────────────────

    public function test_generate_doc_action_exists_english(): void
    {
        $this->assertStringContainsString(
            'Generate Doc',
            $this->src,
            'Generate Doc action item must be present (first item, matching BMS)'
        );
    }

    public function test_generate_doc_action_exists_swahili(): void
    {
        $this->assertStringContainsString(
            'Tengeneza Hati',
            $this->src,
            'Swahili label for Generate Doc must be present'
        );
    }

    public function test_preview_view_action_exists_english(): void
    {
        $this->assertStringContainsString(
            'Preview / View',
            $this->src,
            'Preview / View must be a single action item (not split into Preview + Download)'
        );
    }

    public function test_preview_view_action_exists_swahili(): void
    {
        $this->assertStringContainsString(
            'Angalia / Tazama',
            $this->src,
            'Swahili label for Preview / View must be present'
        );
    }

    public function test_edit_details_action_exists_english(): void
    {
        $this->assertStringContainsString(
            'Edit Details',
            $this->src,
            'Edit Details action item must be present'
        );
    }

    public function test_edit_details_action_exists_swahili(): void
    {
        $this->assertStringContainsString(
            'Hariri Maelezo',
            $this->src,
            'Swahili label for Edit Details must be present'
        );
    }

    public function test_delete_action_exists_english(): void
    {
        $this->assertStringContainsString('Delete', $this->src);
    }

    public function test_delete_action_exists_swahili(): void
    {
        $this->assertStringContainsString('Futa', $this->src);
    }

    // ── Safe onclick pattern — ID only, no string name arg ───────────────────

    public function test_tpl_rows_map_is_declared(): void
    {
        $this->assertStringContainsString(
            'const tplRows = {}',
            $this->src,
            'tplRows map must exist to store row data by ID so onclick needs no string args'
        );
    }

    public function test_generate_doc_onclick_passes_id_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/onclick="generateDoc\(\$\{r\.id\}\)/',
            $this->src,
            'generateDoc onclick must pass only integer ID — no string name argument'
        );
    }

    public function test_confirm_delete_onclick_passes_id_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/onclick="confirmDelete\(\$\{r\.id\}\)/',
            $this->src,
            'confirmDelete onclick must pass only integer ID — no string name argument'
        );
    }

    public function test_no_single_quoted_name_in_generate_doc_onclick(): void
    {
        $this->assertStringNotContainsString(
            "generateDoc(\${r.id},'",
            $this->src,
            "Single-quote-wrapped name in generateDoc onclick breaks on names like Manager's Letter"
        );
    }

    public function test_no_single_quoted_name_in_confirm_delete_onclick(): void
    {
        $this->assertStringNotContainsString(
            "confirmDelete(\${r.id},'",
            $this->src,
            "Single-quote-wrapped name in confirmDelete onclick breaks on names with apostrophes"
        );
    }

    public function test_generate_doc_function_reads_name_from_map(): void
    {
        $this->assertStringContainsString(
            'tplRows[id]',
            $this->src,
            'generateDoc and confirmDelete must read the template name from tplRows map'
        );
    }

    // ── play-circle icon for Generate Doc ────────────────────────────────────

    public function test_generate_doc_uses_play_circle_icon(): void
    {
        $this->assertStringContainsString(
            'bi-play-circle',
            $this->src,
            'Generate Doc must use bi-play-circle icon (matching BMS)'
        );
    }

    // ── Mobile cards have the same 4 actions ─────────────────────────────────

    public function test_mobile_cards_have_generate_doc(): void
    {
        $this->assertStringContainsString(
            'generateDoc(${r.id})',
            $this->src,
            'Mobile card view must also have Generate Doc action'
        );
    }

    public function test_mobile_cards_have_confirm_delete(): void
    {
        $this->assertStringContainsString(
            'confirmDelete(${r.id})',
            $this->src,
            'Mobile card view must also have Delete action with id-only onclick'
        );
    }
}
