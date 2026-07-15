<?php
// actions/sign_document.php — apply the current user's e-signature to an authored
// document.
//
// Two modes:
//   • Multi-party: the document has signatory slots — the current user signs THEIR
//     own slot. This works even for an ordinary member who was assigned (scoped
//     access), so they never need the manage_documents permission.
//   • Legacy single-sign: the document has no signatory list — a leadership user
//     (edit on manage_documents) applies one authoritative signature.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/document_signatories.php';

header('Content-Type: application/json');
$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id  = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
$fail    = function (string $m) { echo json_encode(['success' => false, 'message' => $m]); exit; };

if ($doc_id <= 0) { $fail($is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.'); }

$chk = $pdo->prepare("SELECT title, created_by FROM authored_documents WHERE id = ?");
$chk->execute([$doc_id]);
$doc = $chk->fetch(PDO::FETCH_ASSOC);
if (!$doc) { $fail($is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.'); }
$title = $doc['title'];

$mySlot   = vk_find_doc_signatory($pdo, $doc_id, $user_id);
$sigCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM document_signatories WHERE document_id = " . (int) $doc_id
)->fetchColumn();

// ── Multi-party: sign my own slot (scoped — no manage_documents needed) ──────
if ($mySlot) {
    if (($mySlot['status'] ?? '') === 'signed') {
        $fail($is_sw ? 'Tayari umesaini nyaraka hii.' : 'You have already signed this document.');
    }
    $sigPath = vk_active_signature_path($pdo, $user_id);
    $pdo->prepare(
        "UPDATE document_signatories SET status = 'signed', sig_path = ?, signed_at = CURRENT_TIMESTAMP WHERE id = ?"
    )->execute([$sigPath, (int) $mySlot['id']]);
    logUpdate('Documents', "Signed (signatory): $title", "DOC#$doc_id");

    // If everyone has now signed, tell the document's creator.
    $prog = vk_doc_signing_progress(vk_doc_signatories($pdo, $doc_id));
    if ($prog['complete'] && !empty($doc['created_by']) && (int) $doc['created_by'] !== $user_id) {
        $viewUrl = function_exists('getUrl') ? getUrl('view_document') . '?id=' . $doc_id : ('view_document?id=' . $doc_id);
        vk_notify(
            $pdo,
            (int) $doc['created_by'],
            $is_sw ? 'Saini zote zimekamilika' : 'All signatures collected',
            ($is_sw ? 'Nyaraka imesainiwa na wote: ' : 'Everyone has signed: ') . $title,
            $viewUrl
        );
    }

    $msg = $sigPath !== null
        ? ($is_sw ? 'Umesaini nyaraka kwa saini yako.' : 'You have signed the document with your e-signature.')
        : ($is_sw
            ? 'Umesaini nyaraka. Hujapakia picha ya saini — ipakie kwenye ukurasa wa Saini za Kielektroniki ili ionekane.'
            : 'Signed. You have no signature image on file — upload one on the E-Signatures page to show it.');
    echo json_encode(['success' => true, 'has_signature' => ($sigPath !== null), 'message' => $msg]);
    exit;
}

// ── Not one of my slots ──────────────────────────────────────────────────────
if ($sigCount > 0) {
    // The document uses the signatory list; only assigned signatories may sign.
    $fail($is_sw ? 'Wewe si mmoja wa wanaosaini nyaraka hii.' : 'You are not a signatory on this document.');
}

// ── Legacy single-sign (no signatory list): leadership applies one signature ──
requirePermissionJson('edit', 'manage_documents');
$actor = workflowActorSnapshot();
$res = workflowCaptureSignature($pdo, 'authored_document', $doc_id, 'signed', $user_id, $actor['name'], $actor['role']);
logUpdate('Documents', "Signed: $title", "DOC#$doc_id");

$msg = $res['has_signature']
    ? ($is_sw ? 'Nyaraka imesainiwa kwa saini yako.' : 'Document signed with your e-signature.')
    : ($is_sw
        ? 'Umesaini nyaraka. Hujapakia picha ya saini — ipakie kwenye ukurasa wa Saini za Kielektroniki ili ionekane.'
        : 'Document signed. You have no signature image on file — upload one on the E-Signatures page to show it.');
echo json_encode(['success' => true, 'has_signature' => $res['has_signature'], 'message' => $msg]);
