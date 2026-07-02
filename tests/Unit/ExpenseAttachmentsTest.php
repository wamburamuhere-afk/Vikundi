<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Attachments on expense detail views. Pure tests cover the legacy id parsing
 * and the render on/off + empty behaviour; source-guards pin the wiring
 * (migration registered, both upload flows set the structured link, both detail
 * views include the reusable component). DB fetching + access filtering are
 * verified live (they reuse the PR #147 document access control).
 */
class ExpenseAttachmentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/expense_attachments.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure: legacy description parsing ------------------------------------

    public function testParsesExpenseIdFromDescription(): void
    {
        $this->assertSame(42, vk_parse_expense_id_from_description('Receipt for expense: rent (Expense ID: 42)'));
        $this->assertSame(7, vk_parse_expense_id_from_description('x (Expense ID: 7)'));
    }

    public function testParseReturnsNullWhenAbsent(): void
    {
        $this->assertNull(vk_parse_expense_id_from_description('No id here'));
        $this->assertNull(vk_parse_expense_id_from_description(null));
        $this->assertNull(vk_parse_expense_id_from_description(''));
    }

    // --- pure: render on/off + empty ----------------------------------------

    public function testRenderReturnsEmptyWhenHidden(): void
    {
        $docs = [['id' => 1, 'document_name' => 'r', 'file_type' => 'pdf', 'file_size' => 10, 'original_filename' => 'r.pdf']];
        $this->assertSame('', vk_render_attachments_section($docs, false, false));
    }

    public function testRenderReturnsEmptyWhenNoDocs(): void
    {
        $this->assertSame('', vk_render_attachments_section([], true, false));
    }

    public function testRenderShowsDocsThroughGatedRouteOnly(): void
    {
        $docs = [[
            'id' => 5, 'document_name' => 'Receipt', 'file_type' => 'jpg',
            'file_size' => 2048, 'original_filename' => 'receipt.jpg',
        ]];
        $html = vk_render_attachments_section($docs, true, false);
        // URL is HTML-escaped in the attribute (& -> &amp;), which is correct.
        $this->assertStringContainsString('action=download&amp;document_id=5', $html);
        // must never link the raw uploads path
        $this->assertStringNotContainsString('uploads/document_library', $html);
    }

    // --- wiring (source guards) ---------------------------------------------

    public function testMigrationRegisteredAndAddsColumns(): void
    {
        $this->assertStringContainsString('add_document_relation_columns.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/add_document_relation_columns.php');
        $this->assertStringContainsString('related_type', $mig);
        $this->assertStringContainsString('related_id', $mig);
    }

    public function testUploadFlowsSetStructuredLink(): void
    {
        $gen = $this->src('api/add_general_expense.php');
        $this->assertStringContainsString("'general_expense'", $gen);
        $this->assertStringContainsString('related_type', $gen);

        $death = $this->src('actions/process_death_expense.php');
        $this->assertStringContainsString("'death_expense'", $death);
        $this->assertStringContainsString('related_type', $death);
    }

    public function testBothDetailViewsIncludeComponent(): void
    {
        foreach (['app/constant/accounts/general_expense_view.php', 'app/constant/accounts/death_expense_view.php'] as $view) {
            $s = $this->src($view);
            $this->assertStringContainsString('expense_attachments.php', $s, "$view must include the component");
            $this->assertStringContainsString('vk_render_attachments_section', $s, "$view must render the section");
        }
    }
}
