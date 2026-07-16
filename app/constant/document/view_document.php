<?php
// app/constant/document/view_document.php — read-only view of an authored
// document, with Print (optional group letterhead) and multi-party signing.
//
// Access is scoped: leadership (manage_documents) can always open it; an ordinary
// user who was ASSIGNED as a signatory can open this one document to sign it,
// without holding the manage_documents permission.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/document_signatories.php';
require_once __DIR__ . '/../../../includes/authored_document_access.php';

global $pdo;
if (!isAuthenticated()) { redirectTo('login'); }

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id  = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$mySlot  = ($doc_id > 0) ? vk_find_doc_signatory($pdo, $doc_id, $user_id) : null;

$can_docs = canView('manage_documents');           // leadership
if (!$can_docs && !$mySlot) {                       // not leadership and not a signatory
    http_response_code(403);
    redirectTo('unauthorized');
}

require_once 'header.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
$can_edit   = canEdit('manage_documents');
$can_manage = $can_edit;                            // leadership manages the signatory list

if ($doc_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM authored_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (empty($doc)) {
    echo '<div class="alert alert-danger m-4">' . $t('Document not found.', 'Nyaraka haijapatikana.') . '</div>';
    include 'footer.php'; ob_end_flush(); return;
}

$is_author = (int) ($doc['created_by'] ?? 0) === $user_id;
// A private document is only for its author, an admin, or someone assigned to
// sign it — assignment must keep working regardless of visibility.
if (!vk_can_view_authored_document(
        (string) ($doc['visibility'] ?? 'shared'),
        isAdmin(),
        $is_author,
        (bool) $mySlot,
        $can_docs
    )) {
    http_response_code(403);
    redirectTo('unauthorized');
}
// Editing someone else's private document is never allowed, and a signatory
// reading a private document must not get an Edit button either.
$can_edit = $can_edit && (isAdmin() || $is_author || ($doc['visibility'] ?? 'shared') !== 'private');
$can_manage = $can_edit;

// A single URL builder for stored signature images (same base logic as before).
$sigBase = rtrim((function () {
    $root    = str_replace('\\', '/', ROOT_DIR);
    $docroot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $b = trim(str_replace($docroot, '', $root), '/');
    return $b !== '' ? '/' . $b : '';
})(), '/');
$sigUrl = function (?string $path) use ($sigBase) {
    return $path ? $sigBase . '/' . ltrim($path, '/') : null;
};

// Signatory list drives the whole signature area. When empty, fall back to the
// legacy single authoritative signature (kept for documents signed before this).
$sigs           = vk_doc_signatories($pdo, $doc_id);
$hasSignatories = count($sigs) > 0;
$progress       = vk_doc_signing_progress($sigs);

$legacySig = $hasSignatories ? null : (getWorkflowSignatures($pdo, 'authored_document', $doc_id)['signed'] ?? null);

// My pending slot (if any) — controls whether the Sign button shows for me.
$myPending = null;
foreach ($sigs as $s) {
    if ((int) $s['user_id'] === $user_id && $s['status'] === 'pending') { $myPending = $s; break; }
}
$showSign = $myPending || (!$hasSignatories && $can_edit);

// The "add signatory" picker searches members on demand (Select2 AJAX via
// api/search_document_members, excluding people already on this document), so the
// whole membership is never loaded into the page.

$statusBadge = function (string $st) use ($t) {
    return match ($st) {
        'signed'   => '<span class="badge bg-success">' . $t('Signed', 'Imesainiwa') . '</span>',
        'declined' => '<span class="badge bg-danger">' . $t('Declined', 'Imekataliwa') . '</span>',
        default    => '<span class="badge bg-warning text-dark">' . $t('Pending', 'Inasubiri') . '</span>',
    };
};
?>

<?php PrintHeader::css(); ?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('documents_authored') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <div class="d-flex gap-2">
            <?php if ($can_edit): ?>
            <a href="<?= getUrl('edit_document') ?>?id=<?= (int) $doc_id ?>" class="btn btn-sm btn-outline-primary rounded-pill"><i class="bi bi-pencil me-1"></i><?= $t('Edit', 'Hariri') ?></a>
            <?php endif; ?>
            <?php if ($showSign): ?>
            <button type="button" class="btn btn-sm btn-outline-success rounded-pill" onclick="signDocument()"><i class="bi bi-pen me-1"></i><?= $t('Sign', 'Saini') ?></button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
        </div>
    </div>

    <?php if ($can_manage): ?>
    <!-- Signatory management (screen only) -->
    <div class="card border-0 shadow-sm mb-3 no-print">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0"><i class="bi bi-people me-2"></i><?= $t('Signatories', 'Wanaosaini') ?></h6>
                <?php if ($hasSignatories): ?>
                <span class="small text-muted"><?= $progress['signed'] ?>/<?= $progress['total'] ?> <?= $t('signed', 'wamesaini') ?><?= $progress['complete'] ? ' · ' . $t('complete', 'imekamilika') : '' ?></span>
                <?php endif; ?>
            </div>

            <?php if ($hasSignatories): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <tbody class="small">
                        <?php foreach ($sigs as $s): ?>
                        <tr>
                            <td class="text-muted" style="width:34px"><?= (int) $s['sign_order'] ?>.</td>
                            <td class="fw-semibold"><?= htmlspecialchars($s['user_name'] ?: $s['username'] ?: '—') ?></td>
                            <td><?= $s['role_label'] ? '<span class="badge bg-light text-dark border">' . htmlspecialchars($s['role_label']) . '</span>' : '' ?></td>
                            <td><?= $statusBadge($s['status']) ?></td>
                            <td class="text-nowrap text-muted"><?= $s['signed_at'] ? date('d M Y H:i', strtotime($s['signed_at'])) : '' ?></td>
                            <td class="text-end">
                                <?php if ($s['status'] === 'pending'): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger border-0" title="<?= $t('Remove', 'Ondoa') ?>" onclick="removeSignatory(<?= (int) $s['id'] ?>)"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-3"><?= $t('No signatories yet. Add people below to require their signature — or use the Sign button for a single signature.', 'Hakuna wanaosaini bado. Ongeza watu hapa chini ili wahitajike kusaini — au tumia kitufe cha Saini kwa saini moja.') ?></p>
            <?php endif; ?>

            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold mb-1"><?= $t('Add signatory', 'Ongeza mwanaosaini') ?></label>
                    <select class="form-select form-select-sm" id="sigUser" data-placeholder="<?= $t('Search a member by name…', 'Tafuta mwanachama kwa jina…') ?>">
                        <option value=""></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold mb-1"><?= $t('Role label (optional)', 'Cheo (hiari)') ?></label>
                    <input type="text" class="form-control form-control-sm" id="sigRole" placeholder="<?= $t('e.g. Chairperson, Witness', 'k.m. Mwenyekiti, Shahidi') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-sm btn-primary w-100" onclick="addSignatory()"><i class="bi bi-plus-lg me-1"></i><?= $t('Add', 'Ongeza') ?></button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- The document sheet -->
    <div class="card border-0 shadow-sm mx-auto doc-sheet" style="max-width:860px;">
        <div class="card-body p-5">
            <?php if ((int) $doc['use_letterhead'] === 1): ?>
                <div class="doc-letterhead"><?php PrintHeader::render($pdo, strtoupper($doc['title'])); ?></div>
            <?php endif; ?>

            <div class="doc-content"><?= $doc['body_html'] ?></div>

            <?php if ($hasSignatories): ?>
            <!-- Multi-party signatures -->
            <div class="doc-signatures mt-5">
                <?php foreach ($sigs as $s): $u = $sigUrl($s['sig_path']); ?>
                <div class="doc-sign-block">
                    <?php if ($s['status'] === 'signed' && $u): ?>
                        <div class="mb-1"><img src="<?= htmlspecialchars($u) ?>" alt="e-signature" style="max-height:56px;max-width:200px;object-fit:contain;"></div>
                    <?php else: ?>
                        <div class="doc-sign-space"></div>
                    <?php endif; ?>
                    <div class="doc-sign-line">
                        <strong><?= htmlspecialchars($s['user_name'] ?: $s['username'] ?: '—') ?></strong><?= $s['role_label'] ? ' &mdash; ' . htmlspecialchars($s['role_label']) : '' ?><br>
                        <?php if ($s['status'] === 'signed'): ?>
                            <small class="text-muted"><i class="bi bi-patch-check me-1"></i><?= $t('Digitally signed', 'Imesainiwa kidijitali') ?> · <?= $s['signed_at'] ? date('d M Y H:i', strtotime($s['signed_at'])) : '' ?></small>
                        <?php elseif ($s['status'] === 'declined'): ?>
                            <small class="text-danger"><?= $t('Declined', 'Imekataliwa') ?></small>
                        <?php else: ?>
                            <small class="text-muted"><?= $t('Awaiting signature', 'Inasubiri saini') ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- Legacy single signature -->
            <div class="doc-signature mt-5">
                <?php if ($legacySig && !empty($legacySig['user_name'])): $u = $sigUrl($legacySig['sig_path']); ?>
                    <?php if ($u): ?>
                        <div class="mb-1"><img src="<?= htmlspecialchars($u) ?>" alt="e-signature" style="max-height:60px;max-width:210px;object-fit:contain;"></div>
                    <?php endif; ?>
                    <div class="doc-sign-line">
                        <strong><?= htmlspecialchars($legacySig['user_name']) ?></strong><?= $legacySig['user_role'] ? ' &mdash; ' . htmlspecialchars($legacySig['user_role']) : '' ?><br>
                        <small class="text-muted"><i class="bi bi-patch-check me-1"></i><?= $t('Digitally signed', 'Imesainiwa kidijitali') ?> · <?= $legacySig['signed_at'] ? date('d M Y H:i', strtotime($legacySig['signed_at'])) : '' ?></small>
                    </div>
                <?php else: ?>
                    <div class="doc-sign-line"><small class="text-muted"><?= $t('Signature', 'Saini') ?></small></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .doc-content { line-height: 1.6; color: #212529; }
    .doc-content table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .doc-content th, .doc-content td { border: 1px solid #ccc; padding: 4px 6px; }
    .doc-sign-line { border-top: 1px solid #333; width: 260px; padding-top: 5px; }
    .doc-signatures { display: flex; flex-wrap: wrap; gap: 34px 48px; }
    .doc-sign-block { min-width: 260px; }
    .doc-sign-space { height: 56px; }
    @media print {
        .no-print { display: none !important; }
        #main-content { background: #fff !important; padding: 0 !important; min-height: 0 !important; }
        .doc-sheet { box-shadow: none !important; max-width: none !important; margin: 0 !important; }
        .doc-sheet .card-body { padding: 0 !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        .doc-content tr { page-break-inside: avoid; }
        .doc-signature, .doc-sign-block { page-break-inside: avoid; }
    }
</style>

<script>
const docIsSw = <?= $is_sw ? 'true' : 'false' ?>;
const DOC_ID = <?= (int) $doc_id ?>;

$(function () {
    // Searchable signatory picker — searches members on demand and hides anyone
    // already assigned to this document. First 20 on open, filters as you type.
    if ($('#sigUser').length) {
        $('#sigUser').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: $('#sigUser').data('placeholder'),
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: '/api/search_document_members',
                dataType: 'json',
                delay: 250,
                data: params => ({ q: params.term || '', exclude_doc: DOC_ID }),
                processResults: data => data,
                cache: true
            }
        });
    }
});

function signDocument() {
    Swal.fire({
        title: docIsSw ? 'Saini Nyaraka' : 'Sign Document',
        text: docIsSw ? 'Weka saini yako ya kielektroniki kwenye nyaraka hii?' : 'Apply your e-signature to this document?',
        icon: 'question', showCancelButton: true, confirmButtonText: docIsSw ? 'Ndio, saini' : 'Yes, sign'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/sign_document', { doc_id: DOC_ID }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: docIsSw ? 'Imesainiwa' : 'Signed', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Error', 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
    });
}

function addSignatory() {
    const userId = $('#sigUser').val();
    const role = $('#sigRole').val().trim();
    if (!userId) { Swal.fire('', docIsSw ? 'Chagua mtumiaji.' : 'Please choose a user.', 'warning'); return; }
    $.post('/actions/manage_signatories', { op: 'add', doc_id: DOC_ID, user_id: userId, role_label: role }, res => {
        if (res.success) { location.reload(); }
        else { Swal.fire('Error', res.message || 'Error', 'error'); }
    }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
}

function removeSignatory(id) {
    Swal.fire({
        title: docIsSw ? 'Ondoa' : 'Remove',
        text: docIsSw ? 'Ondoa mwanaosaini huyu?' : 'Remove this signatory?',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545',
        confirmButtonText: docIsSw ? 'Ndio, ondoa' : 'Yes, remove'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/manage_signatories', { op: 'remove', doc_id: DOC_ID, signatory_id: id }, res => {
            if (res.success) { location.reload(); }
            else { Swal.fire('Error', res.message || 'Error', 'error'); }
        }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
    });
}
</script>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php include 'footer.php'; ob_end_flush(); ?>
