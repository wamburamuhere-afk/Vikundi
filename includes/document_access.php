<?php
/**
 * includes/document_access.php
 * ----------------------------
 * Pure, testable access rules for Document Library files (`documents` table).
 * Access levels:
 *   public     -> every logged-in user
 *   restricted -> leadership (admin/chairperson/secretary/treasurer) + the uploader
 *   private    -> the uploader + admins
 * Admins always see everything.
 *
 * The caller computes the three booleans/ids (from isAdmin()/canEdit() + session)
 * and passes them in, so this stays free of globals and unit-testable. Used by
 * api/get_documents.php (listing) and document_library.php (download).
 */

if (!function_exists('vk_user_can_access_document')) {
    /**
     * @param array $doc Must contain 'access_level' and 'uploaded_by'.
     */
    function vk_user_can_access_document(array $doc, int $uid, bool $isAdmin, bool $isLeader): bool
    {
        if ($isAdmin) return true;                                   // admin sees everything
        if ((int) ($doc['uploaded_by'] ?? 0) === $uid) return true;  // owner sees their own
        $level = (string) ($doc['access_level'] ?? 'private');
        if ($level === 'public') return true;                        // public -> everyone
        if ($level === 'restricted' && $isLeader) return true;       // restricted -> leadership
        return false;                                                // private (non-owner) / restricted (non-leader)
    }
}

if (!function_exists('vk_document_visibility_where')) {
    /**
     * SQL condition (for a WHERE) limiting `documents` rows to those the user may
     * see. Binds the needed values into $params. Returns "1=1" for admins.
     *
     * @param string $alias  Table alias used in the query (e.g. 'd').
     * @param string $pfx    Bind-parameter prefix (unique per query if reused).
     */
    function vk_document_visibility_where(string $alias, int $uid, bool $isAdmin, bool $isLeader, array &$params, string $pfx = ':vis'): string
    {
        if ($isAdmin) return '1=1';
        $conds = [
            "$alias.access_level = 'public'",
            "$alias.uploaded_by = {$pfx}_uid",
        ];
        $params["{$pfx}_uid"] = $uid;
        if ($isLeader) {
            $conds[] = "$alias.access_level = 'restricted'";
        }
        return '(' . implode(' OR ', $conds) . ')';
    }
}
