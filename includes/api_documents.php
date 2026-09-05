<?php
/**
 * includes/api_documents.php — Module 13: Documents.
 *
 * TWO independent subsystems live here, exactly as the web keeps them apart:
 *
 *   Document LIBRARY (`documents` table) — plain file uploads. access_level
 *   public/restricted/private, visibility owned by includes/document_access.php.
 *   Gated on the catalog key `document_library`.
 *
 *   Document WRITER / authored documents (`authored_documents` table) —
 *   rich-text letters/contracts/notices composed in-app. visibility
 *   shared/private, multi-party signing via `document_signatories`, visibility
 *   owned by includes/authored_document_access.php. Gated on `manage_documents`.
 *
 *   Exposed under a SEPARATE top-level API resource, `authored-documents`, not
 *   nested under /documents/authored/... — roots.php's router only resolves
 *   /api/v1/{resource}/{id}(/{action})? and /api/v1/{resource}/{subresource};
 *   it has no third static segment before an id, so /documents/authored/{id}
 *   could never route.
 *
 * PERMISSION-KEY NOTE (investigated, not changed): app/constant/document/
 * document_library.php and five sibling files (ajax/quick_upload_document.php,
 * select_document_add_esignature.php, api/document/apply_signature.php,
 * e_signatures.php x2) gate on the strings 'library'/'documents', which do not
 * exist as page_keys in a fresh install's `permissions` table — only
 * `document_library` does, correctly granted. That reads as a hard lockout for
 * every non-admin role. Verified live on demo instead: a Treasurer session
 * loads /library successfully. The live databases (demo, and by the same
 * deploy history presumably production) carry a legacy 'library'/'documents'
 * permissions row predating this repo's migration tracking — this is inert
 * data drift on already-deployed environments, not an active lockout. Left
 * as-is: repointing those checks at `document_library` could not be verified
 * safe against production's actual (inaccessible to this session) grants for
 * that key. This module's API gates on the canonical, migration-tracked key.
 */
require_once __DIR__ . '/api_auth.php';                     // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/document_access.php';               // vk_user_can_access_document(), vk_document_visibility_where()
require_once __DIR__ . '/authored_document_access.php';      // vk_can_view_authored_document(), vk_authored_visibility_where()
require_once __DIR__ . '/document_signatories.php';          // vk_doc_signatories(), vk_doc_signing_progress(), vk_find_doc_signatory(), ...

// ═══════════════════════════ Document Library ═══════════════════════════════

if (!function_exists('vk_api_doc_row')) {
    /** One library document, as the app renders it. Never includes file_path. */
    function vk_api_doc_row(array $r): array
    {
        return [
            'id'                 => (int) $r['id'],
            'name'               => (string) $r['document_name'],
            'description'        => trim((string) ($r['description'] ?? '')) ?: null,
            'original_filename'  => (string) $r['original_filename'],
            'file_size'          => (int) $r['file_size'],
            'file_type'          => (string) $r['file_type'],
            'category'           => !empty($r['category_id']) ? [
                'id'   => (int) $r['category_id'],
                'name' => (string) ($r['category_name'] ?? ''),
            ] : null,
            'version'            => (string) ($r['version'] ?? '1.0'),
            'tags'               => trim((string) ($r['tags'] ?? '')) ?: null,
            'access_level'       => (string) $r['access_level'],
            'uploaded_by'        => [
                'id'   => (int) $r['uploaded_by'],
                'name' => trim((string) ($r['uploaded_by_name'] ?? '')) ?: null,
            ],
            'download_count'     => (int) ($r['download_count'] ?? 0),
            'uploaded_at'        => !empty($r['uploaded_at'])
                ? date(DATE_ATOM, strtotime((string) $r['uploaded_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_doc_actions')) {
    /**
     * Mirrors deleteDocumentLocal() exactly: ownership or admin — NOT the
     * document_library catalog delete flag, which that web action never checks.
     */
    function vk_api_doc_actions(array $auth, array $doc): array
    {
        $isAdmin = vk_api_is_admin((int) $auth['role_id']);
        $isOwner = (int) ($doc['uploaded_by'] ?? 0) === (int) $auth['user_id'];
        return ['delete' => $isAdmin || $isOwner];
    }
}

if (!function_exists('vk_api_doc_filters')) {
    /**
     * Named placeholders (not positional) because the caller also binds
     * vk_document_visibility_where()'s own named params in the same query, and
     * PDO cannot mix the two styles in one prepared statement.
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_doc_filters(array $q, string $alias = 'd'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $categoryId = (int) ($q['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = "{$a}category_id = :f_category_id";
            $params[':f_category_id'] = $categoryId;
        }

        $fileType = strtolower(trim((string) ($q['file_type'] ?? '')));
        if ($fileType !== '') {
            $where[] = "{$a}file_type = :f_file_type";
            $params[':f_file_type'] = $fileType;
        }

        $accessLevel = trim((string) ($q['access_level'] ?? ''));
        if ($accessLevel !== '') {
            if (!in_array($accessLevel, ['public', 'restricted', 'private'], true)) {
                vk_api_error(422, 'invalid_access_level', 'access_level must be one of: public, restricted, private.');
            }
            $where[] = "{$a}access_level = :f_access_level";
            $params[':f_access_level'] = $accessLevel;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = "({$a}document_name LIKE :f_search1 OR {$a}original_filename LIKE :f_search2 OR {$a}tags LIKE :f_search3)";
            $like = '%' . $search . '%';
            $params[':f_search1'] = $like;
            $params[':f_search2'] = $like;
            $params[':f_search3'] = $like;
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_doc_load')) {
    /** One library document by id, or a 404. */
    function vk_api_doc_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A document id is required.');
        }
        $st = $pdo->prepare(
            "SELECT d.*, c.category_name, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS uploaded_by_name
               FROM documents d
               LEFT JOIN document_categories c ON c.id = d.category_id
               LEFT JOIN users u ON u.user_id = d.uploaded_by
              WHERE d.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No document was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_doc_authorize_item')) {
    /**
     * A caller must hold the catalog `document_library` view permission (checked
     * by the endpoint before this runs) AND the item's own access_level rule
     * (vk_user_can_access_document). Hidden items 404 rather than 403, so a
     * private document's existence is not revealed to a caller who cannot see it.
     */
    function vk_api_doc_authorize_item(array $auth, array $doc): void
    {
        $uid      = (int) $auth['user_id'];
        $isAdmin  = vk_api_is_admin((int) $auth['role_id']);
        $isLeader = $isAdmin || vk_api_can($auth, 'edit', 'document_library');
        if (!vk_user_can_access_document($doc, $uid, $isAdmin, $isLeader)) {
            vk_api_error(404, 'not_found', 'No document was found with that id.');
        }
    }
}

// ═══════════════════ Authored Documents (Document Writer) ═══════════════════

if (!function_exists('vk_api_authored_row')) {
    /**
     * One authored document for a LIST response. Deliberately omits body_html —
     * the detail endpoint adds it separately, so listing a page of drafts never
     * pulls every document's full rich-text body over the wire.
     */
    function vk_api_authored_row(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'title'          => (string) $r['title'],
            'doc_type'       => (string) $r['doc_type'],
            'use_letterhead' => (bool) $r['use_letterhead'],
            'status'         => (string) $r['status'],
            'visibility'     => (string) $r['visibility'],
            'created_by'     => [
                'id'   => (int) ($r['created_by'] ?? 0),
                'name' => trim((string) ($r['creator_name'] ?? '')) ?: null,
            ],
            'created_at'     => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'updated_at'     => !empty($r['updated_at'])
                ? date(DATE_ATOM, strtotime((string) $r['updated_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_authored_actions')) {
    /**
     * Same ownership rule actions/save_document.php and (after this module's
     * fix) actions/delete_document.php both apply: someone else's PRIVATE
     * document is never editable or deletable, manage_documents rights
     * notwithstanding.
     */
    function vk_api_authored_actions(array $auth, bool $isAuthor, string $visibility): array
    {
        $isAdmin     = vk_api_is_admin((int) $auth['role_id']);
        $ownershipOk = $isAdmin || $isAuthor || $visibility !== 'private';
        return [
            'edit'   => vk_api_can($auth, 'edit', 'manage_documents') && $ownershipOk,
            'delete' => vk_api_can($auth, 'delete', 'manage_documents') && $ownershipOk,
        ];
    }
}

if (!function_exists('vk_api_authored_filters')) {
    /**
     * Positional placeholders — matches vk_authored_visibility_where() and
     * vk_signer_documents_join(), both of which bind positionally too.
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_authored_filters(array $q, string $alias = 'd'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['draft', 'final'], true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: draft, final.');
            }
            $where[]  = "{$a}status = ?";
            $params[] = $status;
        }

        $docType = trim((string) ($q['doc_type'] ?? ''));
        if ($docType !== '') {
            if (!in_array($docType, ['letter', 'contract', 'notice', 'other'], true)) {
                vk_api_error(422, 'invalid_doc_type', 'doc_type must be one of: letter, contract, notice, other.');
            }
            $where[]  = "{$a}doc_type = ?";
            $params[] = $docType;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[]  = "{$a}title LIKE ?";
            $params[] = '%' . $search . '%';
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_authored_input_errors')) {
    /** @return string[] */
    function vk_api_authored_input_errors(array $body): array
    {
        $errors = [];
        if (trim((string) ($body['title'] ?? '')) === '') {
            $errors[] = 'title is required.';
        }
        $docType = (string) ($body['doc_type'] ?? 'letter');
        if (!in_array($docType, ['letter', 'contract', 'notice', 'other'], true)) {
            $errors[] = 'doc_type must be one of: letter, contract, notice, other.';
        }
        return $errors;
    }
}

if (!function_exists('vk_api_authored_load')) {
    /** One authored document by id, or a 404. */
    function vk_api_authored_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A document id is required.');
        }
        $st = $pdo->prepare(
            "SELECT a.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS creator_name
               FROM authored_documents a
               LEFT JOIN users u ON u.user_id = a.created_by
              WHERE a.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No document was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_authored_authorize_item')) {
    /**
     * Enforces the same visibility matrix as app/constant/document/view_document.php.
     * A document the caller may not see 404s rather than 403s.
     *
     * @return array{is_author:bool,is_leadership:bool,my_slot:?array}
     */
    function vk_api_authored_authorize_item(PDO $pdo, array $auth, array $doc): array
    {
        $uid          = (int) $auth['user_id'];
        $isAdmin      = vk_api_is_admin((int) $auth['role_id']);
        $isAuthor     = (int) ($doc['created_by'] ?? 0) === $uid;
        $isLeadership = $isAdmin || vk_api_can($auth, 'view', 'manage_documents');
        $mySlot       = vk_find_doc_signatory($pdo, (int) $doc['id'], $uid);

        if (!vk_can_view_authored_document(
            (string) ($doc['visibility'] ?? 'shared'),
            $isAdmin,
            $isAuthor,
            (bool) $mySlot,
            $isLeadership
        )) {
            vk_api_error(404, 'not_found', 'No document was found with that id.');
        }

        return ['is_author' => $isAuthor, 'is_leadership' => $isLeadership, 'my_slot' => $mySlot];
    }
}

if (!function_exists('vk_api_signatory_row')) {
    function vk_api_signatory_row(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'user'       => [
                'id'   => (int) $r['user_id'],
                'name' => trim((string) ($r['user_name'] ?? '')) ?: (string) ($r['username'] ?? ''),
            ],
            'role_label' => trim((string) ($r['role_label'] ?? '')) ?: null,
            'sign_order' => (int) $r['sign_order'],
            'status'     => (string) $r['status'],
            'signed_at'  => !empty($r['signed_at'])
                ? date(DATE_ATOM, strtotime((string) $r['signed_at'])) : null,
            'note'       => trim((string) ($r['note'] ?? '')) ?: null,
        ];
    }
}

// ═══════════════════════════ Templates (Writer) ══════════════════════════════

if (!function_exists('vk_api_template_row')) {
    function vk_api_template_row(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'name'           => (string) $r['name'],
            'doc_type'       => (string) $r['doc_type'],
            'use_letterhead' => (bool) $r['use_letterhead'],
            'updated_at'     => !empty($r['updated_at'])
                ? date(DATE_ATOM, strtotime((string) $r['updated_at'])) : null,
        ];
    }
}
