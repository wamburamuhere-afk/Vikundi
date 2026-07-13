<?php
// actions/sign_document.php — apply the current user's e-signature to an
// authored document, reusing the shared e-signature system (user_signatures →
// workflow_signatures via workflowCaptureSignature).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/workflow.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id  = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;

requirePermissionJson('edit', 'manage_documents');

if ($doc_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
    exit;
}
$chk = $pdo->prepare("SELECT title FROM authored_documents WHERE id = ?");
$chk->execute([$doc_id]);
$title = $chk->fetchColumn();
if ($title === false) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
    exit;
}

$actor = workflowActorSnapshot();
$res = workflowCaptureSignature($pdo, 'authored_document', $doc_id, 'signed', $user_id, $actor['name'], $actor['role']);
logUpdate('Documents', "Signed: $title", "DOC#$doc_id");

$msg = $res['has_signature']
    ? ($is_sw ? 'Nyaraka imesainiwa kwa saini yako.' : 'Document signed with your e-signature.')
    : ($is_sw
        ? 'Umesaini nyaraka. Hujapakia picha ya saini — ipakie kwenye ukurasa wa Saini za Kielektroniki ili ionekane.'
        : 'Document signed. You have no signature image on file — upload one on the E-Signatures page to show it.');
echo json_encode(['success' => true, 'has_signature' => $res['has_signature'], 'message' => $msg]);
