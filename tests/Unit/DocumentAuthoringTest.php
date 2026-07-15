<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Document authoring (PR 1 — authoring core): a rich-text writer for letters /
 * contracts / notices. Real behavioural tests for the HTML sanitiser, plus
 * source-guards pinning the gates, routes, migration and editor wiring.
 */
class DocumentAuthoringTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── Sanitiser (real, DB-free) ─────────────────────────────────────────────

    public function testSanitiserKeepsSafeFormatting(): void
    {
        require_once __DIR__ . '/../../includes/document_sanitizer.php';
        $clean = vk_sanitize_document_html('<h1>Title</h1><p>Hello <b>bold</b> <i>italic</i> <u>u</u></p><ul><li>one</li></ul>');
        $this->assertStringContainsString('<b>bold</b>', $clean);
        $this->assertStringContainsString('<i>italic</i>', $clean);
        $this->assertStringContainsString('<li>one</li>', $clean);
        $this->assertStringContainsString('<h1>Title</h1>', $clean);
    }

    public function testSanitiserStripsScriptsAndDangerousUrls(): void
    {
        require_once __DIR__ . '/../../includes/document_sanitizer.php';
        $clean = vk_sanitize_document_html(
            '<p>hi</p><script>alert(1)</script>'
            . '<a href="javascript:alert(1)">bad</a>'
            . '<img src="x" onerror="alert(1)">'
        );
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function testDependencyFreeFallbackSanitises(): void
    {
        // This is the path production runs when HTMLPurifier is not installed.
        require_once __DIR__ . '/../../includes/document_sanitizer.php';
        $clean = vk_dom_sanitize_html(
            '<h1>Notice</h1><p>hi <b>x</b></p><ul><li>a</li></ul>'
            . '<script>alert(1)</script>'
            . '<a href="javascript:x">l</a><a href="https://x.com" target="_blank">ok</a>'
            . '<div onclick="y()">d</div><iframe src="x"></iframe>'
            . '<span style="color:red;position:fixed">c</span>'
        );
        // formatting kept
        $this->assertStringContainsString('<b>x</b>', $clean);
        $this->assertStringContainsString('<h1>Notice</h1>', $clean);
        $this->assertStringContainsString('<li>a</li>', $clean);
        $this->assertStringContainsString('color: red', $clean);
        $this->assertStringContainsString('href="https://x.com"', $clean);
        // dangerous stripped
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('position:fixed', $clean);
    }

    public function testSanitiserKeepsTextAlignmentOnBlocks(): void
    {
        // Summernote stores alignment as text-align on <p> / headings; the
        // sanitiser must keep it (both paths) while dropping unsafe CSS.
        require_once __DIR__ . '/../../includes/document_sanitizer.php';
        $in = '<p style="text-align:center">Subject</p>'
            . '<p style="text-align:right;position:fixed">Sender</p>';

        $dom = vk_dom_sanitize_html($in);
        $this->assertStringContainsString('text-align: center', $dom);
        $this->assertStringContainsString('text-align: right', $dom);
        $this->assertStringNotContainsString('position', $dom);

        $any = vk_sanitize_document_html($in);
        $this->assertMatchesRegularExpression('/text-align:\s*center/', $any);
        $this->assertStringNotContainsString('position', $any);
    }

    public function testSanitiserKeepsFontFamilyAndLineHeight(): void
    {
        // The font-family picker (Calibri / Times New Roman) and the line-spacing
        // control store inline styles on spans / paragraphs. Like alignment, the
        // sanitiser must keep them on both paths or the choice is lost on save.
        require_once __DIR__ . '/../../includes/document_sanitizer.php';
        $in = '<p style="line-height:2"><span style="font-family:\'Times New Roman\'">Dear Sir</span></p>';

        $dom = vk_dom_sanitize_html($in);
        $this->assertStringContainsString('font-family', $dom);
        $this->assertStringContainsString('Times New Roman', $dom);
        $this->assertStringContainsString('line-height', $dom);

        $any = vk_sanitize_document_html($in);
        $this->assertStringContainsString('font-family', $any);
        $this->assertStringContainsString('line-height', $any);
    }

    public function testSanitiserDoesNotHardRequireHtmlpurifier(): void
    {
        $src = $this->src('includes/document_sanitizer.php');
        // autoload include is guarded, and there is a dependency-free fallback
        $this->assertStringContainsString('is_file($__vk_autoload)', $src);
        $this->assertStringContainsString("class_exists('HTMLPurifier_Config')", $src);
        $this->assertStringContainsString('return vk_dom_sanitize_html($html)', $src);
    }

    // ── Actions gated + sanitised ─────────────────────────────────────────────

    public function testSaveActionGatedAndSanitises(): void
    {
        $p = $this->src('actions/save_document.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson(\$doc_id > 0 ? 'edit' : 'create', 'manage_documents')", $p);
        // the body is sanitised, never stored raw
        $this->assertStringContainsString('vk_sanitize_document_html($_POST[\'body_html\'] ?? \'\')', $p);
        $this->assertStringContainsString('logCreate', $p);
        $this->assertStringContainsString('logUpdate', $p);
    }

    public function testDeleteActionGated(): void
    {
        $p = $this->src('actions/delete_document.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson('delete', 'manage_documents')", $p);
        $this->assertStringContainsString('logDelete', $p);
    }

    // ── Pages gated + editor wired ────────────────────────────────────────────

    public function testPagesGatedByPermission(): void
    {
        $this->assertStringContainsString("requireViewPermission('manage_documents')", $this->src('app/constant/document/documents_authored.php'));
        $this->assertStringContainsString("requireViewPermission('manage_documents')", $this->src('app/constant/document/edit_document.php'));
    }

    public function testEditorUsesSummernoteAndLetterheadToggle(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        $this->assertStringContainsString('summernote-bs5.min.js', $p);
        $this->assertStringContainsString("summernote('code')", $p);
        $this->assertStringContainsString('docLetterhead', $p); // letterhead on/off
    }

    public function testEditorTogglesDropdownsWithoutBootstrapDependency(): void
    {
        // Summernote 0.8 emits Bootstrap-4 dropdown markup. Re-pointing it at
        // Bootstrap 5's Dropdown component regressed whenever the toolbar changed,
        // so the editor now toggles the menus itself — no dependency on Bootstrap.
        $p = $this->src('app/constant/document/edit_document.php');
        $this->assertStringContainsString('.note-btn.dropdown-toggle', $p);
        $this->assertStringContainsString("classList.add('show')", $p);
        // the brittle Bootstrap-instance approach must be gone
        $this->assertStringNotContainsString('getOrCreateInstance', $p);
    }

    public function testEditorHasFontFamilyAlignmentAndHistory(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        // font-family picker, with the Windows-only fonts forced to stay visible
        $this->assertStringContainsString("['fontname', ['fontname']]", $p);
        $this->assertStringContainsString('fontNamesIgnoreCheck', $p);
        $this->assertStringContainsString('Times New Roman', $p);
        // explicit alignment buttons (not only the paragraph dropdown), plus line height
        $this->assertStringContainsString('justifyCenter', $p);
        $this->assertStringContainsString("['height', ['height']]", $p);
        // undo / redo
        $this->assertStringContainsString("['history', ['undo', 'redo']]", $p);
    }

    // ── Wiring ────────────────────────────────────────────────────────────────

    public function testRoutesAndMigrationRegistered(): void
    {
        $roots = $this->src('roots.php');
        $this->assertStringContainsString("'documents_authored'", $roots);
        $this->assertStringContainsString("'edit_document'", $roots);
        $this->assertStringContainsString("'actions/save_document'", $roots);
        $this->assertStringContainsString("'actions/delete_document'", $roots);
        $this->assertStringContainsString('create_authored_documents_table.php', $this->src('database/migrate.php'));
    }
}
