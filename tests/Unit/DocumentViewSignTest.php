<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Document Writer (PR 2 — view, print & sign). Source-guards the read-only
 * view/print page (optional group letterhead via PrintHeader), the signing
 * action (reuses the shared e-signature system), the enum migration, the nav
 * link and the routes.
 */
class DocumentViewSignTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testViewPageGatedPrintsAndTogglesLetterhead(): void
    {
        $p = $this->src('app/constant/document/view_document.php');
        $this->assertStringContainsString("requireViewPermission('manage_documents')", $p);
        // letterhead only rendered when the document opts in
        $this->assertStringContainsString("(int) \$doc['use_letterhead'] === 1", $p);
        $this->assertStringContainsString('PrintHeader::render($pdo, strtoupper($doc[\'title\']))', $p);
        // the sanitised body is rendered and the page is printable
        $this->assertStringContainsString("\$doc['body_html']", $p);
        $this->assertStringContainsString('window.print()', $p);
    }

    public function testViewRendersSignatureFromWorkflow(): void
    {
        $p = $this->src('app/constant/document/view_document.php');
        $this->assertStringContainsString("getWorkflowSignatures(\$pdo, 'authored_document', \$doc_id)['signed']", $p);
    }

    public function testSignActionReusesEsignatureSystem(): void
    {
        $p = $this->src('actions/sign_document.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson('edit', 'manage_documents')", $p);
        $this->assertStringContainsString("workflowCaptureSignature(\$pdo, 'authored_document', \$doc_id, 'signed'", $p);
        $this->assertStringContainsString('logUpdate', $p);
    }

    public function testWiringMigrationNavAndRoutes(): void
    {
        $this->assertStringContainsString('add_signed_to_workflow_signatures.php', $this->src('database/migrate.php'));
        // nav link into the Documents dropdown
        $this->assertStringContainsString("getUrl('documents_authored')", $this->src('header.php'));
        // routes
        $roots = $this->src('roots.php');
        $this->assertStringContainsString("'view_document'", $roots);
        $this->assertStringContainsString("'actions/sign_document'", $roots);
        // list page links to the view page
        $this->assertStringContainsString("getUrl('view_document')", $this->src('app/constant/document/documents_authored.php'));
    }
}
