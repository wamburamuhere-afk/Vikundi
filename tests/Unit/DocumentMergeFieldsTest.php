<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Merge fields for the Document Writer (PR E). Real tests for the pure registry
 * and resolver, plus source-guards on the freeze-at-save wiring and the editor UI.
 */
class DocumentMergeFieldsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/document_merge_fields.php';
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── Registry ──────────────────────────────────────────────────────────────

    public function testRegistryExposesGroupedTokens(): void
    {
        $groups = vk_document_merge_fields();
        $this->assertArrayHasKey('group', $groups);
        $this->assertArrayHasKey('member', $groups);

        // flatten every token
        $tokens = [];
        foreach ($groups as $g) {
            foreach ($g['fields'] as $f) { $tokens[] = $f['token']; }
        }
        foreach (['{group_name}', '{today}', '{author_name}', '{member_name}', '{member_contributions}'] as $tok) {
            $this->assertContains($tok, $tokens, "registry must expose $tok");
        }
    }

    public function testRegistryLocalisesLabels(): void
    {
        $en = vk_document_merge_fields(false);
        $sw = vk_document_merge_fields(true);
        $this->assertSame('Group', $en['group']['label']);
        $this->assertSame('Kikundi', $sw['group']['label']);
    }

    // ── Resolver (pure) ───────────────────────────────────────────────────────

    public function testResolverReplacesKnownTokens(): void
    {
        $html = '<p>Dear {member_name}, from {group_name} on {today}.</p>';
        $out = vk_resolve_merge_fields($html, [
            '{member_name}' => 'Amina Mwanga',
            '{group_name}'  => 'Umoja VICOBA',
            '{today}'       => '16 Jul 2026',
        ]);
        $this->assertSame('<p>Dear Amina Mwanga, from Umoja VICOBA on 16 Jul 2026.</p>', $out);
    }

    public function testResolverLeavesUnknownTokensLiteral(): void
    {
        // no member value supplied → the token must survive so the writer notices
        $out = vk_resolve_merge_fields('<p>{member_name} / {group_name}</p>', ['{group_name}' => 'G']);
        $this->assertStringContainsString('{member_name}', $out);
        $this->assertStringContainsString('G', $out);
    }

    public function testResolverIsANoopWithoutContentOrValues(): void
    {
        $this->assertSame('', vk_resolve_merge_fields('', ['{a}' => 'b']));
        $this->assertSame('<p>x</p>', vk_resolve_merge_fields('<p>x</p>', []));
    }

    // ── Freeze-at-save wiring ─────────────────────────────────────────────────

    public function testSaveSanitisesThenResolvesAndFreezes(): void
    {
        $p = $this->src('actions/save_document.php');
        // sanitise the authored structure FIRST, then inject data values
        $sanPos = strpos($p, 'vk_sanitize_document_html($_POST');
        $resPos = strpos($p, 'vk_resolve_merge_fields($body_html');
        $this->assertNotFalse($sanPos);
        $this->assertNotFalse($resPos);
        $this->assertLessThan($resPos, $sanPos, 'must sanitise before resolving');
        $this->assertStringContainsString('vk_build_merge_values($pdo', $p);
        $this->assertStringContainsString("\$_POST['member_id']", $p);
    }

    public function testValueBuilderEscapesAndScopesMemberFields(): void
    {
        $p = $this->src('includes/document_merge_fields.php');
        // values are injected into HTML, so they must be escaped
        $this->assertStringContainsString('htmlspecialchars', $p);
        // member values only when a member is chosen
        $this->assertStringContainsString('$memberId !== null && $memberId > 0', $p);
        // contributions use the approved total, matching the ledger
        $this->assertStringContainsString("status = 'approved'", $p);
    }

    // ── Editor UI ─────────────────────────────────────────────────────────────

    public function testDocumentEditorHasMemberPickerAndInsertMenu(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        $this->assertStringContainsString('docMember', $p);                    // generate-for picker
        $this->assertStringContainsString("vk_render_merge_field_menu('#docBody'", $p);
        $this->assertStringContainsString('member_id:', $p);                   // sent on save
        // warns when member fields are present but no member is picked
        $this->assertStringContainsString('\\{member_[a-z_]+\\}', $p);
    }

    public function testTemplateEditorHasInsertMenuButDoesNotResolve(): void
    {
        // Templates keep tokens raw — the insert menu is there, but save_writer_template
        // must NOT resolve them.
        $this->assertStringContainsString("vk_render_merge_field_menu('#tplBody'", $this->src('app/constant/document/edit_writer_template.php'));
        $this->assertStringNotContainsString('vk_resolve_merge_fields', $this->src('actions/save_writer_template.php'));
    }
}
