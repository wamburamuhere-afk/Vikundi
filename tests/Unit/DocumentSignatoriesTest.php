<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Multi-party document signing (PR B). Real tests for the pure progress /
 * ordering helpers, plus source-guards pinning the scoped access, the gated
 * assignment action, the signatory-slot signing path, the migration and routes.
 */
class DocumentSignatoriesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/document_signatories.php';
    }

    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // ── Pure logic ────────────────────────────────────────────────────────────

    public function testProgressCountsAndCompletion(): void
    {
        $sigs = [
            ['status' => 'signed'],
            ['status' => 'signed'],
            ['status' => 'pending'],
        ];
        $p = vk_doc_signing_progress($sigs);
        $this->assertSame(3, $p['total']);
        $this->assertSame(2, $p['signed']);
        $this->assertSame(1, $p['pending']);
        $this->assertFalse($p['complete']); // one still pending
    }

    public function testProgressCompleteIgnoresDeclines(): void
    {
        $p = vk_doc_signing_progress([['status' => 'signed'], ['status' => 'declined']]);
        $this->assertTrue($p['complete']); // no pending left → complete
        $this->assertSame(1, $p['declined']);
    }

    public function testProgressEmptyIsNotComplete(): void
    {
        $this->assertFalse(vk_doc_signing_progress([])['complete']);
    }

    public function testNextExpectedOrderIsLowestPending(): void
    {
        $sigs = [
            ['status' => 'signed',  'sign_order' => 1],
            ['status' => 'pending', 'sign_order' => 3],
            ['status' => 'pending', 'sign_order' => 2],
        ];
        $this->assertSame(2, vk_next_expected_order($sigs));
        $this->assertNull(vk_next_expected_order([['status' => 'signed', 'sign_order' => 1]]));
    }

    public function testCanSignSlotAnyOrderVsSequential(): void
    {
        $slotLate  = ['status' => 'pending', 'sign_order' => 3];
        $all = [
            ['status' => 'pending', 'sign_order' => 1],
            ['status' => 'pending', 'sign_order' => 3],
        ];
        // any-order: a later slot can still be signed
        $this->assertTrue(vk_can_sign_slot($slotLate, $all, false));
        // sequential: it must wait for order 1
        $this->assertFalse(vk_can_sign_slot($slotLate, $all, true));
        // already-signed slot can never be re-signed
        $this->assertFalse(vk_can_sign_slot(['status' => 'signed', 'sign_order' => 1], $all, false));
    }

    // ── Access + gating (source guards) ───────────────────────────────────────

    public function testViewScopesAccessToLeadershipOrAssignedSignatory(): void
    {
        $p = $this->src('app/constant/document/view_document.php');
        // not the blanket requireViewPermission any more
        $this->assertStringContainsString('vk_find_doc_signatory', $p);
        $this->assertStringContainsString("canView('manage_documents')", $p);
        $this->assertStringContainsString('!$can_docs && !$mySlot', $p);
        // signatory management + multi-block render
        $this->assertStringContainsString('addSignatory', $p);
        $this->assertStringContainsString('doc-signatures', $p);
    }

    public function testAssignmentActionGatedAndNotifies(): void
    {
        $p = $this->src('actions/manage_signatories.php');
        $this->assertStringContainsString('require_csrf.php', $p);
        $this->assertStringContainsString("requirePermissionJson('edit', 'manage_documents')", $p);
        $this->assertStringContainsString('vk_notify(', $p);       // assignee is nudged
        $this->assertStringContainsString('logCreate', $p);
        $this->assertStringContainsString('logDelete', $p);
    }

    public function testSignActionSignsOwnSlotWithScopedAccess(): void
    {
        $p = $this->src('actions/sign_document.php');
        // the signatory path runs BEFORE any manage_documents gate (scoped)
        $this->assertStringContainsString('vk_find_doc_signatory', $p);
        $this->assertStringContainsString("UPDATE document_signatories SET status = 'signed'", $p);
        // non-signatory on a multi-sign doc is refused
        $this->assertStringContainsString('not a signatory', $p);
        // legacy single-sign still requires edit permission
        $this->assertStringContainsString("requirePermissionJson('edit', 'manage_documents')", $p);
    }

    public function testNotifyInsertsIntoNotifications(): void
    {
        $p = $this->src('includes/document_signatories.php');
        $this->assertStringContainsString('INSERT INTO notifications', $p);
    }

    // ── Signer (member) access ────────────────────────────────────────────────

    public function testSignerDocumentsJoinScopesTheListToAssignedDocsOnly(): void
    {
        // Leadership sees everything — no extra join.
        [$sql, $params] = vk_signer_documents_join(true, 7);
        $this->assertSame('', $sql);
        $this->assertSame([], $params);

        // A signer without manage_documents only sees documents they're assigned to.
        [$sql, $params] = vk_signer_documents_join(false, 7);
        $this->assertStringContainsString('JOIN document_signatories ds', $sql);
        $this->assertStringContainsString('ds.user_id = ?', $sql);
        $this->assertSame([7], $params);
    }

    public function testNavLinkIsGatedAndOffersSignersAScopedEntry(): void
    {
        // The Document Writer link used to render for EVERYONE, so a member clicked
        // it and hit "unauthorized". It is now leadership-only, with a scoped
        // "Documents to Sign" entry when something awaits that user's signature.
        $h = $this->src('header.php');
        $this->assertStringContainsString("\$vk_can_docs = canView('manage_documents')", $h);
        $this->assertStringContainsString('vk_user_pending_signature_count', $h);
        $this->assertStringContainsString('Documents to Sign', $h);
    }

    public function testListPageAllowsAssignedSignerWithScopedQuery(): void
    {
        $p = $this->src('app/constant/document/documents_authored.php');
        // no longer a blanket requireViewPermission that 403s an assigned member
        $this->assertStringNotContainsString("requireViewPermission('manage_documents')", $p);
        $this->assertStringContainsString('vk_user_has_signatory_rows', $p);
        $this->assertStringContainsString('vk_signer_documents_join', $p);
        $this->assertStringContainsString('$is_signer', $p);
    }

    // ── Wiring ────────────────────────────────────────────────────────────────

    public function testMigrationAndRouteRegistered(): void
    {
        $this->assertStringContainsString('create_document_signatories_table.php', $this->src('database/migrate.php'));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `document_signatories`', $this->src('database/create_document_signatories_table.php'));
        $this->assertStringContainsString("'actions/manage_signatories'", $this->src('roots.php'));
    }
}
