<?php
// includes/expense_attachments.php
//
// One reusable place to fetch and render the documents attached to a source
// record (currently general/other expenses and death-assistance expenses).
//
// Attachments live in the Document Library (`documents`). New uploads carry a
// structured link (documents.related_type + related_id); older rows only have a
// free-text reference in the description, so a legacy fallback is included.
//
// Access control: every returned document is filtered through
// vk_user_can_access_document() (audit H-Doc / PR #147), and the view/download
// links point ONLY at the gated download route (document_library?action=download)
// — never the raw uploads/ path — so private receipts are never exposed.
//
// Flexibility: the render function takes a $show flag. The two expense DETAIL
// views pass true. To show attachments on the PRINT pages later, include this
// file there and call vk_render_attachments_section($docs, true, $isSw) — one
// line, no rework.

require_once __DIR__ . '/document_access.php';

if (!function_exists('vk_parse_expense_id_from_description')) {
    /**
     * Pull the numeric id out of a legacy description like
     * "Receipt for expense: rent (Expense ID: 42)". Returns null when absent.
     */
    function vk_parse_expense_id_from_description(?string $desc): ?int {
        if (!$desc) {
            return null;
        }
        if (preg_match('/Expense ID:\s*(\d+)/i', $desc, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

if (!function_exists('vk_expense_attachment_viewer')) {
    /** The current viewer's access context for document filtering. */
    function vk_expense_attachment_viewer(): array {
        $uid      = (int) ($_SESSION['user_id'] ?? 0);
        $isAdmin  = function_exists('isAdmin') && isAdmin();
        $isLeader = $isAdmin || (function_exists('canEdit') && canEdit('document_library'));
        return [$uid, $isAdmin, $isLeader];
    }
}

if (!function_exists('vk_fetch_expense_attachments')) {
    /**
     * Documents attached to one expense record, filtered to what the viewer may
     * see. Combines the structured link with a legacy text/tag fallback for rows
     * created before the link existed.
     *
     * @param string   $type            'general_expense' | 'death_expense'
     * @param int      $id              the expense/death-expense record id
     * @param int|null $memberIdLegacy  member id, for the death-expense fallback
     */
    function vk_fetch_expense_attachments(PDO $pdo, string $type, int $id, ?int $memberIdLegacy = null): array {
        $rows   = [];
        $params = [];

        // 1) Structured link (exact, preferred).
        $sql = "SELECT * FROM documents WHERE related_type = :t AND related_id = :id";
        $params['t']  = $type;
        $params['id'] = $id;

        // 2) Legacy fallback for rows with no structured link yet.
        if ($type === 'general_expense') {
            // description carries "(Expense ID: N)" — match the id with a boundary
            // so 4 does not match 42.
            $sql .= " OR (related_id IS NULL AND description REGEXP :rex)";
            $params['rex'] = 'Expense ID:[[:space:]]*' . $id . '([^0-9]|$)';
        } elseif ($type === 'death_expense' && $memberIdLegacy) {
            // Historical death docs are keyed only by member id + a Death tag; we
            // cannot pin them to one death record, so show that member's death docs.
            $sql .= " OR (related_id IS NULL AND tags LIKE :dtag AND description REGEXP :mrex)";
            $params['dtag'] = '%Death%';
            $params['mrex'] = 'Member ID:[[:space:]]*' . $memberIdLegacy . '([^0-9]|$)';
        }

        $sql .= " ORDER BY uploaded_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$uid, $isAdmin, $isLeader] = vk_expense_attachment_viewer();

        $seen = [];
        foreach ($all as $doc) {
            if (isset($seen[$doc['id']])) {
                continue;
            }
            $seen[$doc['id']] = true;
            if (vk_user_can_access_document($doc, $uid, $isAdmin, $isLeader)) {
                $rows[] = $doc;
            }
        }
        return $rows;
    }
}

if (!function_exists('vk_format_filesize')) {
    function vk_format_filesize($bytes): string {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 0) . ' KB';
        return $bytes . ' B';
    }
}

if (!function_exists('vk_render_attachments_section')) {
    /**
     * Render the "Attached Documents" card. Returns '' when $show is false or
     * there are no (accessible) documents, so callers can drop it in freely.
     *
     * Links/thumbnails use the gated download route only, so access control and
     * the download audit log both apply.
     */
    function vk_render_attachments_section(array $docs, bool $show = true, bool $isSw = false): string {
        if (!$show || empty($docs)) {
            return '';
        }
        $base = function_exists('getUrl') ? getUrl('document_library') : '/document_library';
        $title = $isSw ? 'Nyaraka Zilizoambatishwa' : 'Attached Documents';
        $imgExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $cards = '';
        foreach ($docs as $d) {
            $url  = htmlspecialchars($base . '?action=download&document_id=' . (int) $d['id']);
            $name = htmlspecialchars($d['document_name'] ?: ($d['original_filename'] ?? 'Document'));
            $ext  = strtolower($d['file_type'] ?? pathinfo($d['original_filename'] ?? '', PATHINFO_EXTENSION));
            $size = vk_format_filesize($d['file_size'] ?? 0);

            if (in_array($ext, $imgExt, true)) {
                $media = '<a href="' . $url . '" target="_blank" rel="noopener">'
                    . '<img src="' . $url . '" alt="' . $name . '" '
                    . 'style="width:100%;height:110px;object-fit:cover;border-radius:6px 6px 0 0;display:block;"></a>';
            } else {
                $icon = $ext === 'pdf' ? 'bi-file-earmark-pdf text-danger'
                    : (in_array($ext, ['doc', 'docx'], true) ? 'bi-file-earmark-word text-primary'
                    : 'bi-file-earmark-text text-secondary');
                $media = '<a href="' . $url . '" target="_blank" rel="noopener" '
                    . 'style="height:110px;border-radius:6px 6px 0 0;background:#f8f9fa;display:flex;align-items:center;justify-content:center;text-decoration:none;">'
                    . '<i class="bi ' . $icon . '" style="font-size:2.4rem;"></i></a>';
            }

            $view = $isSw ? 'Fungua' : 'View';
            $cards .= '<div class="col-6 col-md-3 mb-3"><div class="border rounded shadow-sm h-100 bg-white">'
                . $media
                . '<div class="p-2">'
                . '<div class="small fw-semibold text-truncate" title="' . $name . '">' . $name . '</div>'
                . '<div class="text-muted" style="font-size:11px;">' . htmlspecialchars(strtoupper($ext)) . ' &middot; ' . $size . '</div>'
                . '<a href="' . $url . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary w-100 mt-2 py-0">'
                . '<i class="bi bi-box-arrow-up-right me-1"></i>' . $view . '</a>'
                . '</div></div></div>';
        }

        return '<div class="card border-0 shadow-sm mt-3"><div class="card-header bg-white py-3">'
            . '<h6 class="mb-0 fw-bold text-dark"><i class="bi bi-paperclip me-2"></i>' . $title
            . ' <span class="badge bg-primary-subtle text-primary rounded-pill ms-1">' . count($docs) . '</span></h6>'
            . '</div><div class="card-body"><div class="row g-2">' . $cards . '</div></div></div>';
    }
}
