<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Searchable member pickers for the Document Writer. The "generate for member"
 * and "add signatory" pickers no longer render the whole membership into the
 * page — they search on demand via a Select2 AJAX endpoint (LIMIT 20).
 */
class DocumentMemberSearchTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testSearchEndpointIsGatedLimitedAndSelect2Shaped(): void
    {
        $p = $this->src('api/search_document_members.php');
        $this->assertStringContainsString("requirePermissionJson('view', 'manage_documents')", $p);
        // only a small page is ever returned
        $this->assertStringContainsString('LIMIT 20', $p);
        // active members only, searched by name / username / phone
        $this->assertStringContainsString("status = 'active'", $p);
        $this->assertStringContainsString('first_name LIKE :q', $p);
        // Select2 result shape { results: [{ id, text }] }
        $this->assertStringContainsString("'results' => \$results", $p);
        $this->assertStringContainsString("'id' => (int) \$r['user_id']", $p);
        // signatory picker exclusion
        $this->assertStringContainsString('document_signatories WHERE document_id = :doc', $p);
    }

    public function testEndpointRouteRegistered(): void
    {
        $this->assertStringContainsString("'api/search_document_members'", $this->src('roots.php'));
    }

    public function testEditorMemberPickerIsAjaxNotServerRendered(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        // the whole membership is no longer queried and baked into <option>s
        $this->assertStringNotContainsString("WHERE status = 'active' ORDER BY first_name, last_name\"\n    )->fetchAll", $p);
        $this->assertStringNotContainsString('foreach ($members as $m)', $p);
        // it's a Select2 AJAX picker instead
        $this->assertStringContainsString("\$('#docMember').select2(", $p);
        $this->assertStringContainsString('/api/search_document_members', $p);
    }

    public function testSignatoryPickerIsAjaxAndExcludesExisting(): void
    {
        $p = $this->src('app/constant/document/view_document.php');
        $this->assertStringNotContainsString('foreach ($userOptions as $u)', $p);
        $this->assertStringContainsString("\$('#sigUser').select2(", $p);
        // passes the document so already-assigned signatories are hidden
        $this->assertStringContainsString('exclude_doc: DOC_ID', $p);
    }
}
