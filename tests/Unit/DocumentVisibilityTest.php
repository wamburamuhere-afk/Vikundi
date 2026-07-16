<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-author visibility for the Document Writer (PR D). Real tests for the access
 * matrix and the list-scoping SQL, plus source-guards on the pages/actions.
 */
class DocumentVisibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load BOTH access files together: the Document Library owns
        // vk_document_visibility_where(), so the Writer's helpers must not collide
        // with it (function_exists guards would silently skip a duplicate).
        require_once __DIR__ . '/../../includes/document_access.php';
        require_once __DIR__ . '/../../includes/authored_document_access.php';
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── The access matrix ─────────────────────────────────────────────────────

    public function testAdminSeesEverythingIncludingOthersPrivate(): void
    {
        $this->assertTrue(vk_can_view_authored_document('private', true, false, false, false));
    }

    public function testAuthorAlwaysSeesTheirOwnPrivateDocument(): void
    {
        $this->assertTrue(vk_can_view_authored_document('private', false, true, false, true));
        // even if they somehow lost leadership
        $this->assertTrue(vk_can_view_authored_document('private', false, true, false, false));
    }

    public function testAssignedSignatoryCanReadAPrivateDocument(): void
    {
        // The whole point: a private document assigned to a member must stay
        // readable by them, or they could never sign it.
        $this->assertTrue(vk_can_view_authored_document('private', false, false, true, false));
    }

    public function testLeadershipSeesSharedButNotSomeoneElsesPrivate(): void
    {
        $this->assertTrue(vk_can_view_authored_document('shared', false, false, false, true));
        $this->assertFalse(vk_can_view_authored_document('private', false, false, false, true));
    }

    public function testOutsiderSeesNothing(): void
    {
        $this->assertFalse(vk_can_view_authored_document('shared', false, false, false, false));
        $this->assertFalse(vk_can_view_authored_document('private', false, false, false, false));
    }

    // ── List scoping ──────────────────────────────────────────────────────────

    public function testAdminListIsUnrestricted(): void
    {
        [$sql, $params] = vk_authored_visibility_where(true, true, 7);
        $this->assertSame('', $sql);
        $this->assertSame([], $params);
    }

    public function testSignerOnlyListIsNotVisibilityFiltered(): void
    {
        // Their list is already limited to assigned documents by the signer join,
        // and they must read those whatever the visibility.
        [$sql, $params] = vk_authored_visibility_where(false, false, 7);
        $this->assertSame('', $sql);
        $this->assertSame([], $params);
    }

    public function testLeadershipListScopesToSharedOwnOrAssigned(): void
    {
        [$sql, $params] = vk_authored_visibility_where(false, true, 7);
        $this->assertStringContainsString("d.visibility = 'shared'", $sql);
        $this->assertStringContainsString('d.created_by = ?', $sql);
        $this->assertStringContainsString('document_signatories', $sql); // anything they must sign
        $this->assertSame([7, 7], $params);
    }

    public function testWriterHelpersDoNotCollideWithTheLibraryOnes(): void
    {
        // Both files are loaded above. The library's function must still have its
        // own signature/behaviour, and the writer's must exist separately.
        $this->assertTrue(function_exists('vk_document_visibility_where'), 'library helper');
        $this->assertTrue(function_exists('vk_authored_visibility_where'), 'writer helper');
        $this->assertTrue(function_exists('vk_user_can_access_document'), 'library matrix');
        $this->assertTrue(function_exists('vk_can_view_authored_document'), 'writer matrix');
        // the writer file must not try to define the library's name
        $this->assertStringNotContainsString(
            'function vk_document_visibility_where',
            $this->src('includes/authored_document_access.php')
        );
    }

    // ── Wiring ────────────────────────────────────────────────────────────────

    public function testSaveActionPersistsVisibilityAndGuardsPrivateEdits(): void
    {
        $p = $this->src('actions/save_document.php');
        $this->assertStringContainsString("'private' : 'shared'", $p);        // whitelisted
        $this->assertStringContainsString('visibility=?', $p);                 // persisted on update
        $this->assertStringContainsString('visibility, created_by', $p);       // persisted on insert
        // someone else's private document cannot be edited
        $this->assertStringContainsString("\$cur['visibility'] === 'private' && !\$is_author && !\$is_admin", $p);
        // only author/admin may change the setting
        $this->assertStringContainsString('$new_visibility = ($is_author || $is_admin)', $p);
    }

    public function testEditorHasVisibilityToggleAndGuard(): void
    {
        $p = $this->src('app/constant/document/edit_document.php');
        $this->assertStringContainsString('docVisibility', $p);
        $this->assertStringContainsString('vk_can_view_authored_document', $p);
        $this->assertStringContainsString('$can_set_visibility', $p);
    }

    public function testViewPageEnforcesVisibility(): void
    {
        $p = $this->src('app/constant/document/view_document.php');
        $this->assertStringContainsString('vk_can_view_authored_document', $p);
        $this->assertStringContainsString("redirectTo('unauthorized')", $p);
    }

    public function testListPageScopesByVisibility(): void
    {
        $p = $this->src('app/constant/document/documents_authored.php');
        $this->assertStringContainsString('vk_authored_visibility_where', $p);
        $this->assertStringContainsString('vk_authored_visibility_badge', $p);
    }

    public function testMigrationRegistered(): void
    {
        $this->assertStringContainsString('add_visibility_to_authored_documents.php', $this->src('database/migrate.php'));
        $this->assertStringContainsString("ENUM('shared','private') NOT NULL DEFAULT 'shared'", $this->src('database/add_visibility_to_authored_documents.php'));
    }
}
