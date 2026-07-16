<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Document Writer templates (PR C): reusable starting points for letters,
 * contracts and notices. Source-guards the gates, sanitising, the template
 * picker, the migration and the routes.
 */
class DocumentTemplatesTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── Actions: gated, sanitised, audited ────────────────────────────────────

    public function testSaveTemplateGatedAndSanitises(): void
    {
        $p = $this->src('actions/save_writer_template.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson(\$tpl_id > 0 ? 'edit' : 'create', 'manage_documents')", $p);
        // a template body is rich text too — it must never be stored raw
        $this->assertStringContainsString("vk_sanitize_document_html(\$_POST['body_html'] ?? '')", $p);
        $this->assertStringContainsString('logCreate', $p);
        $this->assertStringContainsString('logUpdate', $p);
    }

    public function testDeleteTemplateGated(): void
    {
        $p = $this->src('actions/delete_writer_template.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson('delete', 'manage_documents')", $p);
        $this->assertStringContainsString('logDelete', $p);
    }

    public function testTemplateApiGatedAndReSanitisesOnTheWayOut(): void
    {
        $p = $this->src('api/get_writer_template.php');
        $this->assertStringContainsString("requirePermissionJson('view', 'manage_documents')", $p);
        // defence in depth: sanitise again before it reaches the editor
        $this->assertStringContainsString('vk_sanitize_document_html', $p);
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public function testTemplatePagesAreLeadershipOnly(): void
    {
        $this->assertStringContainsString("requireViewPermission('manage_documents')", $this->src('app/constant/document/writer_templates.php'));
        $this->assertStringContainsString("requireViewPermission('manage_documents')", $this->src('app/constant/document/edit_writer_template.php'));
    }

    public function testNewDocumentCanStartFromATemplate(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        // server-side prefill via ?tpl=ID, only for a NEW document so a stray ?tpl
        // can never overwrite saved work
        $this->assertStringContainsString('$doc_id === 0 && $from_tpl > 0', $p);
        $this->assertStringContainsString('FROM authored_document_templates', $p);
        // in-editor picker + the API it pulls from
        $this->assertStringContainsString('tplPicker', $p);
        $this->assertStringContainsString('/api/get_writer_template', $p);
    }

    public function testTemplatePickerConfirmsBeforeReplacingContent(): void
    {
        // Choosing a template must not silently wipe what the user already typed.
        $p = $this->src('app/constant/document/edit_document.php');
        $this->assertStringContainsString('Replace the content?', $p);
    }

    // ── Nav + wiring ──────────────────────────────────────────────────────────

    public function testNavExposesTemplatesToLeadershipAndGatesTheLegacyLink(): void
    {
        $h = $this->src('header.php');
        $this->assertStringContainsString("getUrl('writer_templates')", $h);
        // the legacy BMS templates page requires its own key — gate it so it isn't
        // a dead link for members
        $this->assertStringContainsString("canView('document_templates')", $h);
    }

    public function testMigrationAndRoutesRegistered(): void
    {
        $this->assertStringContainsString('create_authored_document_templates_table.php', $this->src('database/migrate.php'));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `authored_document_templates`', $this->src('database/create_authored_document_templates_table.php'));
        $roots = $this->src('roots.php');
        foreach (["'writer_templates'", "'edit_writer_template'", "'actions/save_writer_template'",
                  "'actions/delete_writer_template'", "'api/get_writer_template'"] as $route) {
            $this->assertStringContainsString($route, $roots, "route $route must be registered");
        }
    }
}
