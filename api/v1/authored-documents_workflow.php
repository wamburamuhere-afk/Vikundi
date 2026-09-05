<?php
/**
 * GET /api/v1/authored-documents/{id}/workflow — signing progress only.
 *
 * Kept SEPARATE from GET .../{id} on purpose: a client polling "has everyone
 * signed yet?" (e.g. after sending a signature reminder) has no reason to
 * re-transfer the document's full body_html on every check.
 *
 * Same visibility gate as the detail endpoint — a document the caller cannot
 * see 404s here too.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';
require_once __DIR__ . '/../../core/workflow.php'; // getWorkflowSignatures()

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

$doc = vk_api_authored_load($pdo, $id);
$ctx = vk_api_authored_authorize_item($pdo, $auth, $doc);

$sigs = vk_doc_signatories($pdo, $id);

if ($sigs) {
    vk_api_ok([
        'mode'        => 'multi_party',
        'signatories' => array_map('vk_api_signatory_row', $sigs),
        'progress'    => vk_doc_signing_progress($sigs),
    ]);
}

// Legacy single-sign: no signatory list — report the one authoritative signature.
$legacy = getWorkflowSignatures($pdo, 'authored_document', $id)['signed'] ?? null;
$signed = $legacy !== null && !empty($legacy['signed_at']);

vk_api_ok([
    'mode'     => 'legacy_single_sign',
    'signed'   => $signed,
    'signed_by'=> $signed ? [
        'name'      => (string) ($legacy['user_name'] ?? ''),
        'role'      => (string) ($legacy['user_role'] ?? ''),
        'signed_at' => date(DATE_ATOM, strtotime((string) $legacy['signed_at'])),
    ] : null,
]);
