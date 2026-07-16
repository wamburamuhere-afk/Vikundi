<?php
// app/constant/document/edit_document.php — write / edit an authored document
// (letter, contract, notice) with a lightweight rich-text editor (Summernote).
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_documents');
require_once __DIR__ . '/../../../includes/document_editor_assets.php';
require_once __DIR__ . '/../../../includes/authored_document_access.php';
require_once __DIR__ . '/../../../includes/document_signatories.php';
require_once __DIR__ . '/../../../includes/document_merge_fields.php';
require_once 'header.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$doc = ['title' => '', 'doc_type' => 'letter', 'body_html' => '', 'use_letterhead' => 1, 'status' => 'draft', 'visibility' => 'shared', 'created_by' => $user_id];
if ($doc_id > 0) {
    $stmt = $pdo->prepare("SELECT title, doc_type, body_html, use_letterhead, status, visibility, created_by FROM authored_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $doc = $row; }

    // Someone else's private document is not theirs to open in the editor.
    // (A signatory may READ a private document to sign it, but never edit it.)
    if (!vk_can_view_authored_document(
            (string) $doc['visibility'], isAdmin(),
            (int) $doc['created_by'] === $user_id,
            false,                       // signing access does not grant editing
            true                         // reaching this page already required manage_documents
        )) {
        http_response_code(403);
        redirectTo('unauthorized');
    }
}
// Only the author (or an admin) may change the visibility setting.
$can_set_visibility = $doc_id === 0 || isAdmin() || (int) $doc['created_by'] === $user_id;

// A NEW document may start from a template (?tpl=ID — the "use this template"
// button on the templates list). Editing an existing document never does, so a
// stray ?tpl can't overwrite saved work.
$from_tpl = isset($_GET['tpl']) && ctype_digit((string) $_GET['tpl']) ? (int) $_GET['tpl'] : 0;
if ($doc_id === 0 && $from_tpl > 0) {
    $ts = $pdo->prepare("SELECT doc_type, body_html, use_letterhead FROM authored_document_templates WHERE id = ?");
    $ts->execute([$from_tpl]);
    if ($trow = $ts->fetch(PDO::FETCH_ASSOC)) {
        $doc['doc_type']       = $trow['doc_type'];
        $doc['body_html']      = $trow['body_html']; // sanitised on save
        $doc['use_letterhead'] = $trow['use_letterhead'];
    }
}

// Templates offered in the in-editor picker (new documents only).
$templates = ($doc_id === 0)
    ? $pdo->query("SELECT id, name FROM authored_document_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// Active members for the merge-field "generate for" picker.
$members = $pdo->query(
    "SELECT user_id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, username
       FROM users WHERE status = 'active' ORDER BY first_name, last_name"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<?php vk_document_editor_head(); ?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold mb-0 text-primary"><i class="bi bi-file-earmark-text me-2"></i><?= $doc_id > 0 ? $t('Edit Document', 'Hariri Nyaraka') : $t('New Document', 'Nyaraka Mpya') ?></h3>
        </div>
        <a href="<?= getUrl('documents_authored') ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="bi bi-arrow-left me-2"></i><?= $t('Back', 'Rudi') ?></a>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <form id="documentForm">
            <input type="hidden" id="docId" value="<?= (int) $doc_id ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small"><?= $t('Title', 'Kichwa') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="docTitle" value="<?= htmlspecialchars($doc['title']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small"><?= $t('Type', 'Aina') ?></label>
                    <select class="form-select" id="docType">
                        <?php foreach ([
                            'letter'   => $t('Letter', 'Barua'),
                            'contract' => $t('Contract', 'Mkataba'),
                            'notice'   => $t('Notice', 'Tangazo'),
                            'other'    => $t('Other', 'Nyingine'),
                        ] as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $doc['doc_type'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small"><?= $t('Status', 'Hali') ?></label>
                    <select class="form-select" id="docStatus">
                        <option value="draft" <?= $doc['status'] === 'draft' ? 'selected' : '' ?>><?= $t('Draft', 'Rasimu') ?></option>
                        <option value="final" <?= $doc['status'] === 'final' ? 'selected' : '' ?>><?= $t('Final', 'Kamili') ?></option>
                    </select>
                </div>
            </div>

            <?php if ($doc_id === 0 && $templates): ?>
            <div class="mb-3">
                <label class="form-label fw-bold small" for="tplPicker"><i class="bi bi-files me-1"></i><?= $t('Start from a template', 'Anza na kiolezo') ?></label>
                <select class="form-select" id="tplPicker">
                    <option value=""><?= $t('Blank document', 'Nyaraka tupu') ?></option>
                    <?php foreach ($templates as $tp): ?>
                    <option value="<?= (int) $tp['id'] ?>" <?= $from_tpl === (int) $tp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="docLetterhead" <?= (int) $doc['use_letterhead'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="docLetterhead"><?= $t('Print on group letterhead', 'Chapisha kwenye kichwa cha kikundi') ?></label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small"><i class="bi bi-eye me-1"></i><?= $t('Who can see this document', 'Nani anaweza kuona nyaraka hii') ?></label>
                <select class="form-select" id="docVisibility" <?= $can_set_visibility ? '' : 'disabled' ?>>
                    <option value="shared" <?= ($doc['visibility'] ?? 'shared') !== 'private' ? 'selected' : '' ?>><?= $t('All leadership', 'Uongozi wote') ?></option>
                    <option value="private" <?= ($doc['visibility'] ?? 'shared') === 'private' ? 'selected' : '' ?>><?= $t('Only me (and admins)', 'Mimi tu (na wasimamizi)') ?></option>
                </select>
                <div class="form-text small">
                    <?= $can_set_visibility
                        ? $t('Anyone you assign to sign can always open it, even when private.', 'Yeyote unayemteua kusaini ataweza kuifungua, hata ikiwa ni ya binafsi.')
                        : $t('Only the author can change this.', 'Mwandishi pekee ndiye anayeweza kubadilisha hili.') ?>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small" for="docMember"><i class="bi bi-person me-1"></i><?= $t('Generate for member (optional)', 'Tengeneza kwa mwanachama (hiari)') ?></label>
                <select class="form-select" id="docMember">
                    <option value=""><?= $t('— none —', '— hakuna —') ?></option>
                    <?php foreach ($members as $m): ?>
                    <option value="<?= (int) $m['user_id'] ?>"><?= htmlspecialchars($m['name'] ?: $m['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text small"><?= $t('Member fields like {member_name} are filled in from this person when you save.', 'Uga kama {member_name} hujazwa kutoka kwa mtu huyu unapohifadhi.') ?></div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-bold small mb-0"><?= $t('Document body', 'Maandishi ya nyaraka') ?></label>
                    <?php vk_render_merge_field_menu('#docBody', $is_sw); ?>
                </div>
                <div id="docBody"><?= $doc['body_html'] ?></div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= getUrl('documents_authored') ?>" class="btn btn-light border rounded-pill px-4"><?= $t('Cancel', 'Ghairi') ?></a>
                <button type="submit" class="btn btn-primary rounded-pill px-4" id="docSaveBtn"><i class="bi bi-save me-2"></i><?= $t('Save', 'Hifadhi') ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php vk_document_editor_init('#docBody', $t('Write your document here...', 'Andika nyaraka hapa...')); ?>
<script>
const docIsSw = <?= $is_sw ? 'true' : 'false' ?>;
$(function () {
    // Start-from-template (new documents only). Never clobber typed content
    // without asking.
    $('#tplPicker').on('change', function () {
        const id = $(this).val();
        if (!id) { return; }
        const current = $('#docBody').summernote('code').replace(/<[^>]*>/g, '').trim();
        const apply = () => {
            $.getJSON('/api/get_writer_template', { id: id }, res => {
                if (res.status !== 'success' || !res.data) {
                    Swal.fire('Error', (res && res.message) || 'Template not found', 'error');
                    return;
                }
                $('#docBody').summernote('code', res.data.body_html || '');
                $('#docType').val(res.data.doc_type || 'letter');
                $('#docLetterhead').prop('checked', Number(res.data.use_letterhead) === 1);
            }).fail(() => Swal.fire('Error', 'Server error', 'error'));
        };
        if (current === '') { apply(); return; }
        Swal.fire({
            title: docIsSw ? 'Badilisha maandishi?' : 'Replace the content?',
            text: docIsSw ? 'Maandishi yaliyopo yatabadilishwa na kiolezo hiki.' : 'What you have written will be replaced by this template.',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: docIsSw ? 'Ndio, tumia kiolezo' : 'Yes, use template'
        }).then(r => { if (r.isConfirmed) { apply(); } });
    });

    function doSaveDocument() {
        const title = $('#docTitle').val().trim();
        const $btn = $('#docSaveBtn').prop('disabled', true);
        $.post('/actions/save_document', {
            doc_id: $('#docId').val(),
            title: title,
            doc_type: $('#docType').val(),
            status: $('#docStatus').val(),
            visibility: $('#docVisibility').val(),
            member_id: $('#docMember').val() || '',
            use_letterhead: $('#docLetterhead').is(':checked') ? 1 : 0,
            body_html: $('#docBody').summernote('code')
        }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: docIsSw ? 'Imehifadhiwa' : 'Saved', text: res.message, timer: 1200, showConfirmButton: false })
                    .then(() => window.location.href = '<?= getUrl('documents_authored') ?>');
            } else {
                Swal.fire('Error', res.message || 'Error', 'error');
                $btn.prop('disabled', false);
            }
        }, 'json').fail(() => { Swal.fire('Error', 'Server error', 'error'); $btn.prop('disabled', false); });
    }

    $('#documentForm').on('submit', function (e) {
        e.preventDefault();
        const title = $('#docTitle').val().trim();
        if (!title) { Swal.fire('', docIsSw ? 'Tafadhali weka kichwa.' : 'Please enter a title.', 'warning'); return; }

        // If the body uses {member_*} fields but no member is chosen, they can't be
        // filled in — warn rather than silently freezing the raw tokens.
        const usesMemberFields = /\{member_[a-z_]+\}/.test($('#docBody').summernote('code'));
        if (usesMemberFields && !$('#docMember').val()) {
            Swal.fire({
                title: docIsSw ? 'Hakuna mwanachama' : 'No member selected',
                text: docIsSw
                    ? 'Nyaraka ina uga wa mwanachama lakini hujachagua mwanachama. Uga huo utabaki kama ulivyo.'
                    : 'This document has member fields but no member is selected. Those fields will be left as-is.',
                icon: 'warning', showCancelButton: true,
                confirmButtonText: docIsSw ? 'Hifadhi hivyo' : 'Save anyway',
                cancelButtonText: docIsSw ? 'Rudi' : 'Go back'
            }).then(r => { if (r.isConfirmed) { doSaveDocument(); } });
            return;
        }
        doSaveDocument();
    });
});
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
