<?php
/**
 * includes/document_signatories.php
 * ---------------------------------
 * Helpers for multi-party document signing (see database/create_document_signatories_table.php).
 *
 * Pure functions (progress, ordering) are unit-tested; the DB functions wrap the
 * signatory table, the active e-signature lookup and the in-app notification the
 * assignee receives ("you have a document to sign").
 */

// ── Pure helpers (no DB — unit tested) ───────────────────────────────────────

if (!function_exists('vk_doc_signing_progress')) {
    /**
     * @param array $sigs rows each with a 'status' key ('pending'|'signed'|'declined')
     * @return array ['total'=>int,'signed'=>int,'pending'=>int,'declined'=>int,'complete'=>bool]
     */
    function vk_doc_signing_progress(array $sigs): array
    {
        $total = count($sigs);
        $signed = $pending = $declined = 0;
        foreach ($sigs as $s) {
            switch ($s['status'] ?? 'pending') {
                case 'signed':   $signed++;   break;
                case 'declined': $declined++; break;
                default:         $pending++;  break;
            }
        }
        // "complete" = at least one signatory and none still pending (declines don't block).
        return [
            'total'    => $total,
            'signed'   => $signed,
            'pending'  => $pending,
            'declined' => $declined,
            'complete' => $total > 0 && $pending === 0,
        ];
    }
}

if (!function_exists('vk_next_expected_order')) {
    /** Lowest sign_order that is still pending, or null when none pend. */
    function vk_next_expected_order(array $sigs): ?int
    {
        $next = null;
        foreach ($sigs as $s) {
            if (($s['status'] ?? 'pending') !== 'pending') { continue; }
            $ord = (int) ($s['sign_order'] ?? 1);
            if ($next === null || $ord < $next) { $next = $ord; }
        }
        return $next;
    }
}

if (!function_exists('vk_can_sign_slot')) {
    /**
     * May this pending slot be signed now?
     *  - any-order mode (default): yes, as long as it is still pending.
     *  - sequential mode: only when it is the lowest pending sign_order.
     */
    function vk_can_sign_slot(array $slot, array $allSigs, bool $sequential = false): bool
    {
        if (($slot['status'] ?? 'pending') !== 'pending') { return false; }
        if (!$sequential) { return true; }
        return (int) ($slot['sign_order'] ?? 1) === vk_next_expected_order($allSigs);
    }
}

// ── DB helpers ───────────────────────────────────────────────────────────────

if (!function_exists('vk_active_signature_path')) {
    /** The user's active uploaded e-signature image path, or null. */
    function vk_active_signature_path(PDO $pdo, int $userId): ?string
    {
        $sig = $pdo->prepare(
            'SELECT file_path FROM user_signatures
              WHERE user_id = ? AND status = "active"
              ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $sig->execute([$userId]);
        return $sig->fetchColumn() ?: null;
    }
}

if (!function_exists('vk_doc_signatories')) {
    /** All signatory rows for a document, with the signer's display name, ordered. */
    function vk_doc_signatories(PDO $pdo, int $docId): array
    {
        $stmt = $pdo->prepare(
            'SELECT ds.*,
                    TRIM(CONCAT_WS(" ", u.first_name, u.last_name)) AS user_name,
                    u.username AS username
               FROM document_signatories ds
               LEFT JOIN users u ON u.user_id = ds.user_id
              WHERE ds.document_id = ?
              ORDER BY ds.sign_order ASC, ds.id ASC'
        );
        $stmt->execute([$docId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_find_doc_signatory')) {
    /** The signatory row for this user on this document, or null. */
    function vk_find_doc_signatory(PDO $pdo, int $docId, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM document_signatories WHERE document_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$docId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('vk_user_has_signatory_rows')) {
    /**
     * Is this user a signatory on ANY document? Drives scoped access to the
     * document list for people who do not hold manage_documents.
     */
    function vk_user_has_signatory_rows(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) { return false; }
        $stmt = $pdo->prepare('SELECT 1 FROM document_signatories WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('vk_user_pending_signature_count')) {
    /** How many documents are waiting on this user's signature (indexed count). */
    function vk_user_pending_signature_count(PDO $pdo, int $userId): int
    {
        if ($userId <= 0) { return 0; }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_signatories WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('vk_signer_documents_where')) {
    /**
     * Pure: the extra FROM/WHERE a non-leadership signer needs so the document
     * list shows ONLY documents they were assigned to. Returns [sqlJoin, params].
     */
    function vk_signer_documents_join(bool $isLeadership, int $userId): array
    {
        if ($isLeadership) { return ['', []]; }
        return [' JOIN document_signatories ds ON ds.document_id = d.id AND ds.user_id = ? ', [$userId]];
    }
}

if (!function_exists('vk_notify')) {
    /** Create an in-app notification (reuses the existing notifications table). */
    function vk_notify(PDO $pdo, int $userId, string $title, string $message, ?string $actionUrl = null, string $priority = 'medium'): void
    {
        if ($userId <= 0) { return; }
        $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, type, priority, action_url)
             VALUES (?, ?, ?, "system", ?, ?)'
        )->execute([$userId, $title, $message, $priority, $actionUrl]);
    }
}
