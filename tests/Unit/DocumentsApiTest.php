<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_documents.php';

/**
 * Module 13 — Documents.
 *
 * Two independent subsystems, tested separately: the plain Document LIBRARY
 * (`documents` table, gated on `document_library`) and the Document WRITER /
 * authored documents (`authored_documents` table, gated on `manage_documents`,
 * exposed as the separate `authored-documents` resource — see
 * includes/api_documents.php's header for why).
 *
 * DB-touching loaders (vk_api_doc_load, vk_api_authored_load,
 * vk_api_authored_authorize_item) are exercised live, not here — same
 * precedent as every prior module's *_load() functions.
 */
final class DocumentsApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function auth(bool $leader, bool $admin = false, int $userId = 1): array
    {
        return [
            'user_id' => $userId,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'permissions' => $leader || $admin
                ? [
                    'document_library'  => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
                    'manage_documents'  => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
                ]
                : [
                    'document_library'  => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0],
                    // A Member holds no view on manage_documents at all — scoped
                    // access to an assigned document comes from the query, not
                    // this permission.
                ],
        ];
    }

    private static function libraryRaw(array $over = []): array
    {
        return $over + [
            'id'                => 7,
            'document_name'     => 'AGM Minutes 2026',
            'description'       => 'Annual general meeting minutes',
            'file_path'         => 'uploads/document_library/abc.pdf',
            'original_filename' => 'agm_minutes.pdf',
            'file_size'         => 204800,
            'file_type'         => 'pdf',
            'category_id'       => null,
            'category_name'     => null,
            'version'           => '1.0',
            'tags'              => null,
            'access_level'      => 'restricted',
            'uploaded_by'       => 2,
            'uploaded_by_name'  => 'Amina Hando',
            'download_count'    => 3,
            'uploaded_at'       => '2026-08-01 09:00:00',
        ];
    }

    private static function authoredRaw(array $over = []): array
    {
        return $over + [
            'id'             => 5,
            'title'          => 'Demand Letter',
            'doc_type'       => 'letter',
            'body_html'      => '<p>Dear Member...</p>',
            'use_letterhead' => 1,
            'status'         => 'draft',
            'visibility'     => 'shared',
            'created_by'     => 2,
            'creator_name'   => 'Amina Hando',
            'created_at'     => '2026-08-01 09:00:00',
            'updated_at'     => '2026-08-02 10:00:00',
        ];
    }

    // ── library: the row ─────────────────────────────────────────────────────

    public function testLibraryRowNeverIncludesFilePath(): void
    {
        $this->assertArrayNotHasKey('file_path', vk_api_doc_row(self::libraryRaw()));
    }

    public function testLibraryRowCategoryIsNullWhenUnset(): void
    {
        $this->assertNull(vk_api_doc_row(self::libraryRaw())['category']);
    }

    public function testLibraryRowCategoryIsPopulatedWhenSet(): void
    {
        $row = vk_api_doc_row(self::libraryRaw(['category_id' => 3, 'category_name' => 'Minutes']));
        $this->assertSame(['id' => 3, 'name' => 'Minutes'], $row['category']);
    }

    public function testLibraryRowBlankTagsAndDescriptionAreNullNotEmptyString(): void
    {
        $row = vk_api_doc_row(self::libraryRaw(['description' => '  ', 'tags' => '']));
        $this->assertNull($row['description']);
        $this->assertNull($row['tags']);
    }

    // ── library: actions — ownership or admin, NOT the catalog delete flag ──

    public function testLibraryOwnerMayDeleteEvenWithoutTheDeletePermission(): void
    {
        $auth = self::auth(false); // Member, document_library delete = 0
        $auth['user_id'] = 2;      // matches libraryRaw()'s uploaded_by
        $this->assertTrue(vk_api_doc_actions($auth, self::libraryRaw())['delete']);
    }

    public function testLibraryNonOwnerNonAdminMayNotDelete(): void
    {
        $auth = self::auth(true); // leadership, holds document_library delete = 1
        $auth['user_id'] = 99;    // not the uploader
        $this->assertFalse(vk_api_doc_actions($auth, self::libraryRaw())['delete']);
    }

    public function testLibraryAdminMayDeleteAnyonesUpload(): void
    {
        $auth = self::auth(false, true);
        $auth['user_id'] = 99;
        $this->assertTrue(vk_api_doc_actions($auth, self::libraryRaw())['delete']);
    }

    // ── library: filters — named placeholders (shared query with vk_document_visibility_where) ──

    public function testLibraryInvalidAccessLevelIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_access_level/');
        vk_api_doc_filters(['access_level' => 'top-secret']);
    }

    public function testLibraryNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_doc_filters([]));
    }

    public function testLibraryFiltersUseNamedPlaceholdersNotPositional(): void
    {
        [$where, $params] = vk_api_doc_filters(['category_id' => 3, 'file_type' => 'PDF', 'access_level' => 'public']);
        $this->assertCount(3, $where);
        foreach ($where as $clause) {
            $this->assertStringNotContainsString('?', $clause);
        }
        $this->assertSame([':f_category_id' => 3, ':f_file_type' => 'pdf', ':f_access_level' => 'public'], $params);
    }

    public function testLibrarySearchBindsThreeNamedPlaceholdersNotInterpolated(): void
    {
        [$where, $params] = vk_api_doc_filters(['search' => 'AGM']);
        $this->assertCount(1, $where);
        $this->assertStringNotContainsString('AGM', $where[0]);
        $this->assertSame(['%AGM%', '%AGM%', '%AGM%'], array_values($params));
    }

    // ── library: item-level authorization (pure — no PDO) ────────────────────

    public function testLibraryPublicDocumentIsVisibleToAnyone(): void
    {
        $doc = self::libraryRaw(['access_level' => 'public', 'uploaded_by' => 999]);
        $this->assertNull(vk_api_doc_authorize_item(self::auth(false), $doc));
    }

    public function testLibraryPrivateDocumentIsHiddenFromANonOwnerNonAdmin(): void
    {
        $doc = self::libraryRaw(['access_level' => 'private', 'uploaded_by' => 999]);
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/^\[404 not_found\]/');
        vk_api_doc_authorize_item(self::auth(true), $doc); // leadership, still not the owner
    }

    public function testLibraryPrivateDocumentIsVisibleToItsOwner(): void
    {
        $auth = self::auth(false);
        $auth['user_id'] = 2;
        $doc = self::libraryRaw(['access_level' => 'private', 'uploaded_by' => 2]);
        $this->assertNull(vk_api_doc_authorize_item($auth, $doc));
    }

    public function testLibraryRestrictedDocumentIsHiddenFromAnOrdinaryMember(): void
    {
        $doc = self::libraryRaw(['access_level' => 'restricted', 'uploaded_by' => 999]);
        $this->expectException(Throwable::class);
        vk_api_doc_authorize_item(self::auth(false), $doc);
    }

    public function testLibraryRestrictedDocumentIsVisibleToLeadership(): void
    {
        $doc = self::libraryRaw(['access_level' => 'restricted', 'uploaded_by' => 999]);
        $this->assertNull(vk_api_doc_authorize_item(self::auth(true), $doc));
    }

    // ── authored: the row ─────────────────────────────────────────────────────

    public function testAuthoredRowNeverIncludesBodyHtml(): void
    {
        // The list endpoint adds body_html back in only where it needs it
        // (create/detail responses) — the shared row shape stays light.
        $this->assertArrayNotHasKey('body_html', vk_api_authored_row(self::authoredRaw()));
    }

    public function testAuthoredRowUseLetterheadIsBoolean(): void
    {
        $this->assertTrue(vk_api_authored_row(self::authoredRaw())['use_letterhead']);
        $this->assertFalse(vk_api_authored_row(self::authoredRaw(['use_letterhead' => 0]))['use_letterhead']);
    }

    // ── authored: actions — private-document ownership rule ─────────────────

    public function testSharedDocumentIsEditableByAnyLeadershipHolder(): void
    {
        $auth = self::auth(true);
        $auth['user_id'] = 999; // not the author
        $this->assertTrue(vk_api_authored_actions($auth, false, 'shared')['edit']);
    }

    public function testSomeoneElsesPrivateDocumentIsNeverEditable(): void
    {
        // Same rule actions/save_document.php applies: manage_documents edit
        // rights are not enough on a PRIVATE document that is not yours.
        $auth = self::auth(true);
        $auth['user_id'] = 999;
        $actions = vk_api_authored_actions($auth, false, 'private');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['delete']);
    }

    public function testTheAuthorMayAlwaysEditTheirOwnPrivateDocument(): void
    {
        $auth = self::auth(true);
        $this->assertTrue(vk_api_authored_actions($auth, true, 'private')['edit']);
    }

    public function testAnAdminMayEditAnyonesPrivateDocument(): void
    {
        $auth = self::auth(false, true);
        $this->assertTrue(vk_api_authored_actions($auth, false, 'private')['edit']);
    }

    public function testAMemberWithNoManageDocumentsPermissionCanNeverEditOrDelete(): void
    {
        $auth = self::auth(false); // Member — no manage_documents grant at all
        $actions = vk_api_authored_actions($auth, false, 'shared');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['delete']);
    }

    // ── authored: input validation ────────────────────────────────────────────

    public function testABlankTitleIsRejected(): void
    {
        $this->assertNotEmpty(vk_api_authored_input_errors(['title' => '  ', 'doc_type' => 'letter']));
    }

    public function testAnUnknownDocTypeIsRejected(): void
    {
        $this->assertNotEmpty(vk_api_authored_input_errors(['title' => 'X', 'doc_type' => 'memo']));
    }

    public function testAValidTitleAndDocTypePassesCleanly(): void
    {
        $this->assertSame([], vk_api_authored_input_errors(['title' => 'Demand Letter', 'doc_type' => 'letter']));
    }

    // ── authored: list filters — positional, matches vk_authored_visibility_where() ──

    public function testAuthoredInvalidStatusIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_authored_filters(['status' => 'archived']);
    }

    public function testAuthoredInvalidDocTypeIsRefused(): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_doc_type/');
        vk_api_authored_filters(['doc_type' => 'memo']);
    }

    public function testAuthoredFiltersBindPositionallyNotByName(): void
    {
        [$where, $params] = vk_api_authored_filters(['status' => 'final', 'search' => 'Demand']);
        $this->assertCount(2, $where);
        foreach ($where as $clause) {
            $this->assertStringNotContainsString(':', $clause);
            $this->assertStringNotContainsString('Demand', $clause);
        }
        $this->assertSame(['final', '%Demand%'], $params);
    }

    // ── signatory & template rows ──────────────────────────────────────────────

    public function testSignatoryRowFallsBackToUsernameWhenNameIsBlank(): void
    {
        $row = vk_api_signatory_row([
            'id' => 1, 'user_id' => 4, 'user_name' => '', 'username' => 'jsecretary',
            'role_label' => null, 'sign_order' => 1, 'status' => 'pending', 'signed_at' => null, 'note' => null,
        ]);
        $this->assertSame('jsecretary', $row['user']['name']);
    }

    public function testTemplateRowShape(): void
    {
        $row = vk_api_template_row([
            'id' => 9, 'name' => 'Warning Letter', 'doc_type' => 'letter',
            'use_letterhead' => 1, 'updated_at' => '2026-07-01 08:00:00',
        ]);
        $this->assertSame(9, $row['id']);
        $this->assertTrue($row['use_letterhead']);
    }

    // ── structural: gates fire before any query ──────────────────────────────

    public function testLibraryListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/documents.php');
        $gate  = strpos($code, "vk_api_doc_library_can(\$auth, 'view')");
        $query = strpos($code, 'FROM documents d');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testLibraryGateChecksBothLiveKeysNotJustOne(): void
    {
        // Found live, post-deploy: demo/production's actual grants are under
        // the literal key 'library', not the migration-tracked
        // 'document_library' a fresh local install gets — see
        // includes/api_documents.php's header. vk_api_doc_library_can()
        // checks both; no endpoint may call vk_api_can()/vk_api_require_permission()
        // with a single hardcoded document-library key directly.
        foreach (['api/v1/documents.php', 'api/v1/documents_detail.php', 'api/v1/documents_download.php'] as $file) {
            $code = self::code($file);
            $this->assertStringNotContainsString("'document_library'", $code);
            $this->assertStringNotContainsString(
                "vk_api_can(\$auth, 'view', 'library')",
                $code,
                "$file must gate through vk_api_doc_library_can(), not a single key directly."
            );
        }

        $helperCode = self::code('includes/api_documents.php');
        $this->assertStringContainsString("vk_api_can(\$auth, \$action, 'library')", $helperCode);
        $this->assertStringContainsString("vk_api_can(\$auth, \$action, 'document_library')", $helperCode);
    }

    public function testTemplatesGateOnManageDocuments(): void
    {
        $code = self::code('api/v1/document-templates.php');
        $gate = strpos($code, "vk_api_require_permission(\$auth, 'view', 'manage_documents')");
        $query = strpos($code, 'FROM authored_document_templates');
        $this->assertNotFalse($gate);
        $this->assertLessThan($query, $gate);
    }

    public function testAuthoredListIsNotHardGatedOnManageDocuments(): void
    {
        // A Member with no manage_documents grant must still be able to list
        // the document(s) they are assigned to sign — see the file's own
        // header note. There is no vk_api_require_permission() call for view.
        $code = self::code('api/v1/authored-documents.php');
        $this->assertStringNotContainsString("vk_api_require_permission(\$auth, 'view', 'manage_documents')", $code);
    }

    public function testAuthoredCreateRequiresManageDocuments(): void
    {
        $code = self::code('api/v1/authored-documents_create.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'create', 'manage_documents')", $code);
    }

    // ── the delete_document.php ownership fix (found while building this module) ──

    public function testDeleteDocumentWebActionNowChecksVisibilityOwnership(): void
    {
        $code = self::code('actions/delete_document.php');
        $this->assertStringContainsString("visibility'] === 'private'", $code);
        $this->assertStringContainsString('is_author', $code);
    }

    public function testAuthoredDeleteAlsoClearsSignatoryRows(): void
    {
        // Found while verifying locally: there is no FK between
        // document_signatories and authored_documents, and
        // actions/delete_document.php never clears these either, so a delete
        // leaves an orphaned row. This endpoint cleans it up.
        $code   = self::code('api/v1/authored-documents_detail.php');
        $sigDel = strpos($code, 'DELETE FROM document_signatories WHERE document_id = ?');
        $docDel = strpos($code, 'DELETE FROM authored_documents WHERE id = ?');
        $this->assertNotFalse($sigDel);
        $this->assertNotFalse($docDel);
        $this->assertLessThan($docDel, $sigDel);
    }

    public function testDocumentLibraryWebDeleteNowChecksThePermission(): void
    {
        // deleteDocumentLocal() itself only ever checked ownership — this
        // module added the missing can_delete gate before it is even called.
        $code = self::code('app/constant/document/document_library.php');
        $this->assertStringContainsString("canDelete('document_library')", $code);
    }

    // ── routing ────────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/documents'                          => 'documents.php',
            'api/v1/documents/7'                        => 'documents_detail.php',
            'api/v1/documents/7/download'                => 'documents_download.php',
            'api/v1/authored-documents'                  => 'authored-documents.php',
            'api/v1/authored-documents/5'                => 'authored-documents_detail.php',
            'api/v1/authored-documents/5/sign'           => 'authored-documents_sign.php',
            'api/v1/authored-documents/5/workflow'       => 'authored-documents_workflow.php',
            'api/v1/document-templates'                  => 'document-templates.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    // ── auditing ────────────────────────────────────────────────────────────────

    public function testEveryWriteIsAuditedAgainstTheRealUser(): void
    {
        foreach ([
            'api/v1/authored-documents_create.php' => "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            'api/v1/authored-documents_detail.php' => "/logUpdate\([^;]*\\\$auth\['user_id'\]\)/s",
            'api/v1/documents_detail.php'          => "/logDelete\([^;]*\\\$auth\['user_id'\]\)/s",
        ] as $file => $pattern) {
            $this->assertMatchesRegularExpression($pattern, self::code($file));
        }
    }
}
