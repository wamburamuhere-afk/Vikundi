<?php
/**
 * POST /api/v1/authored-documents/{id}/sign — apply the caller's e-signature.
 *
 * Mirrors actions/sign_document.php exactly, two modes:
 *
 *   Multi-party: the document has signatory slots — the caller signs THEIR
 *   OWN slot. Works for an ordinary member assigned as a signatory, who never
 *   needs `manage_documents` for this — scoped entirely to their own row.
 *
 *   Legacy single-sign: no signatory list exists — a leadership user (edit on
 *   manage_documents) applies one authoritative signature.
 *
 * Deliberately does NOT go through vk_api_authored_authorize_item()'s general
 * view check first: a signatory's right to sign comes from being assigned,
 * which is exactly what vk_find_doc_signatory() below already establishes,
 * and re-deriving it through the visibility helper would just repeat the
 * same query under a different name.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';
require_once __DIR__ . '/../../core/workflow.php'; // workflowActorSnapshot(), workflowCaptureSignature()

vk_api_cors();
vk_api_require_method(['POST']);

$auth   = vk_api_require_auth();
$uid    = (int) $auth['user_id'];
$id     = (int) ($_GET['id'] ?? 0);
$doc    = vk_api_authored_load($pdo, $id);

$mySlot     = vk_find_doc_signatory($pdo, $id, $uid);
$sigCountSt = $pdo->prepare('SELECT COUNT(*) FROM document_signatories WHERE document_id = ?');
$sigCountSt->execute([$id]);
$sigCount   = (int) $sigCountSt->fetchColumn();

// ── Multi-party: sign my own slot ────────────────────────────────────────────
if ($mySlot) {
    if (($mySlot['status'] ?? '') === 'signed') {
        vk_api_error(409, 'already_signed', 'You have already signed this document.');
    }

    $sigPath = vk_active_signature_path($pdo, $uid);
    $pdo->prepare(
        "UPDATE document_signatories SET status = 'signed', sig_path = ?, signed_at = CURRENT_TIMESTAMP WHERE id = ?"
    )->execute([$sigPath, (int) $mySlot['id']]);

    $_SESSION['user_id'] = $uid;
    logUpdate('Documents', 'Signed (signatory): ' . $doc['title'], 'DOC#' . $id, $uid);

    $progress = vk_doc_signing_progress(vk_doc_signatories($pdo, $id));
    if ($progress['complete'] && !empty($doc['created_by']) && (int) $doc['created_by'] !== $uid) {
        vk_notify(
            $pdo,
            (int) $doc['created_by'],
            'All signatures collected',
            'Everyone has signed: ' . $doc['title'],
            null
        );
    }

    vk_api_ok([
        'has_signature' => $sigPath !== null,
        'progress'      => $progress,
        'message'       => $sigPath !== null
            ? 'You have signed the document with your e-signature.'
            : 'Signed. You have no signature image on file.',
    ]);
}

// ── Not one of my slots ──────────────────────────────────────────────────────
if ($sigCount > 0) {
    vk_api_error(403, 'not_a_signatory', 'You are not a signatory on this document.');
}

// ── Legacy single-sign: leadership only ──────────────────────────────────────
vk_api_require_permission($auth, 'edit', 'manage_documents');

$actor = workflowActorSnapshot();
$_SESSION['user_id'] = $uid; // workflowActorSnapshot() and workflowCaptureSignature() both read the session
$res = workflowCaptureSignature($pdo, 'authored_document', $id, 'signed', $uid, $actor['name'], $actor['role']);
logUpdate('Documents', 'Signed: ' . $doc['title'], 'DOC#' . $id, $uid);

vk_api_ok([
    'has_signature' => $res['has_signature'],
    'message'       => $res['has_signature']
        ? 'Document signed with your e-signature.'
        : 'Document signed. You have no signature image on file.',
]);
