<?php
// actions/manage_signatories.php — add / remove signatories on an authored
// document. Only leadership (edit on manage_documents) manages the list; the
// signatories themselves sign via actions/sign_document.php.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/document_signatories.php';

header('Content-Type: application/json');
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$fail  = function (string $m) { echo json_encode(['success' => false, 'message' => $m]); exit; };

requirePermissionJson('edit', 'manage_documents');

$op     = $_POST['op'] ?? '';
$doc_id = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
if ($doc_id <= 0) { $fail($is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.'); }

$docStmt = $pdo->prepare("SELECT title FROM authored_documents WHERE id = ?");
$docStmt->execute([$doc_id]);
$title = $docStmt->fetchColumn();
if ($title === false) { $fail($is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.'); }

if ($op === 'add') {
    $user_id    = isset($_POST['user_id']) && ctype_digit((string) $_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $role_label = trim((string) ($_POST['role_label'] ?? ''));
    $role_label = $role_label !== '' ? mb_substr($role_label, 0, 100) : null;
    if ($user_id <= 0) { $fail($is_sw ? 'Chagua mtumiaji.' : 'Choose a user to assign.'); }

    $u = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS name FROM users WHERE user_id = ?");
    $u->execute([$user_id]);
    $signerName = $u->fetchColumn();
    if ($signerName === false) { $fail($is_sw ? 'Mtumiaji hakupatikana.' : 'User not found.'); }

    if (vk_find_doc_signatory($pdo, $doc_id, $user_id)) {
        $fail($is_sw ? 'Tayari ni mmoja wa wanaosaini.' : 'This user is already a signatory.');
    }

    $maxOrd = (int) $pdo->query(
        "SELECT COALESCE(MAX(sign_order), 0) FROM document_signatories WHERE document_id = " . (int) $doc_id
    )->fetchColumn();

    $pdo->prepare(
        "INSERT INTO document_signatories (document_id, user_id, role_label, sign_order, assigned_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$doc_id, $user_id, $role_label, $maxOrd + 1, (int) ($_SESSION['user_id'] ?? 0)]);

    // Nudge the assignee — high priority so it stands out in the bell.
    $viewUrl = function_exists('getUrl') ? getUrl('view_document') . '?id=' . $doc_id : ('view_document?id=' . $doc_id);
    vk_notify(
        $pdo,
        $user_id,
        $is_sw ? 'Nyaraka inasubiri saini yako' : 'A document awaits your signature',
        ($is_sw ? 'Umeombwa kusaini: ' : 'You have been asked to sign: ') . $title,
        $viewUrl,
        'high'
    );

    logCreate('Documents', "Assigned signatory {$signerName} to: $title", "DOC#$doc_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mwanaosaini ameongezwa.' : 'Signatory added.']);
    exit;
}

if ($op === 'remove') {
    $sig_id = isset($_POST['signatory_id']) && ctype_digit((string) $_POST['signatory_id']) ? (int) $_POST['signatory_id'] : 0;
    if ($sig_id <= 0) { $fail($is_sw ? 'Ombi batili.' : 'Invalid request.'); }

    // Only pending slots may be removed — never erase a captured signature.
    $del = $pdo->prepare("DELETE FROM document_signatories WHERE id = ? AND document_id = ? AND status = 'pending'");
    $del->execute([$sig_id, $doc_id]);
    if ($del->rowCount() === 0) {
        $fail($is_sw ? 'Haiwezi kuondolewa (huenda tayari imesainiwa).' : 'Cannot remove (it may already be signed).');
    }
    logDelete('Documents', "Removed a pending signatory from: $title", "DOC#$doc_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Ameondolewa.' : 'Signatory removed.']);
    exit;
}

$fail($is_sw ? 'Operesheni batili.' : 'Invalid operation.');
