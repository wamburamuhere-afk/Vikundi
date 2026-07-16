<?php
/**
 * includes/authored_document_access.php
 * -------------------------------------
 * Who may read a Document *Writer* document (`authored_documents`).
 *
 * NOTE: this is deliberately separate from includes/document_access.php, which
 * governs the Document *Library* (`documents` table, access_level
 * public/restricted/private, uploaded_by). Different table, different model —
 * and that file already owns the name vk_document_visibility_where(), so the
 * names here are prefixed `authored_` to avoid colliding with it.
 *
 * The matrix:
 *
 *   Admin              → every document, always.
 *   Author             → their own document, always (even when private).
 *   Assigned signatory → a document they must sign, always. A private document
 *                        assigned to someone must stay readable by them, or they
 *                        could never sign it.
 *   Leadership         → every 'shared' document; NOT someone else's 'private' one.
 *   Anyone else        → nothing.
 *
 * 'shared' is the default, so documents written before this behave exactly as before.
 */

if (!function_exists('vk_can_view_authored_document')) {
    /**
     * @param string $visibility   'shared' | 'private'
     * @param bool   $isAdmin      admin bypass
     * @param bool   $isAuthor     viewer created the document
     * @param bool   $isSignatory  viewer is assigned to sign this document
     * @param bool   $isLeadership viewer holds manage_documents view
     */
    function vk_can_view_authored_document(
        string $visibility,
        bool $isAdmin,
        bool $isAuthor,
        bool $isSignatory,
        bool $isLeadership
    ): bool {
        if ($isAdmin || $isAuthor || $isSignatory) {
            return true;
        }
        if ($visibility === 'private') {
            return false;   // someone else's private document
        }
        return $isLeadership;
    }
}

if (!function_exists('vk_authored_visibility_where')) {
    /**
     * WHERE clause scoping a document LIST by visibility. Returns [sql, params];
     * sql is '' when no restriction applies.
     *
     * Admins are unrestricted. A signer-only list is already limited to the
     * documents they were assigned (vk_signer_documents_join) and they must be
     * able to read those whatever the visibility — so no filter there either.
     * Leadership sees shared documents, their own, and anything they must sign.
     *
     * @param string $alias table alias for authored_documents in the query
     */
    function vk_authored_visibility_where(bool $isAdmin, bool $isLeadership, int $userId, string $alias = 'd'): array
    {
        if ($isAdmin || !$isLeadership) {
            return ['', []];
        }
        $sql = " WHERE ({$alias}.visibility = 'shared'"
             . " OR {$alias}.created_by = ?"
             . " OR EXISTS (SELECT 1 FROM document_signatories s WHERE s.document_id = {$alias}.id AND s.user_id = ?)) ";
        return [$sql, [$userId, $userId]];
    }
}

if (!function_exists('vk_authored_visibility_badge')) {
    /** Badge markup for a document's visibility on list pages. */
    function vk_authored_visibility_badge(string $visibility, bool $isSw = false): string
    {
        if ($visibility === 'private') {
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">'
                 . '<i class="bi bi-lock-fill me-1"></i>' . ($isSw ? 'Binafsi' : 'Private') . '</span>';
        }
        return '<span class="badge bg-light text-muted border">'
             . '<i class="bi bi-people me-1"></i>' . ($isSw ? 'Uongozi' : 'Shared') . '</span>';
    }
}
