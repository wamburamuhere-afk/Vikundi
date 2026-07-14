<?php
// app/constant/document/edit_document.php — write / edit an authored document
// (letter, contract, notice) with a lightweight rich-text editor (Summernote).
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_documents');
require_once 'header.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$doc_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$doc = ['title' => '', 'doc_type' => 'letter', 'body_html' => '', 'use_letterhead' => 1, 'status' => 'draft'];
if ($doc_id > 0) {
    $stmt = $pdo->prepare("SELECT title, doc_type, body_html, use_letterhead, status FROM authored_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $doc = $row; }
}
?>

<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">

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

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="docLetterhead" <?= (int) $doc['use_letterhead'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="docLetterhead"><?= $t('Print on group letterhead', 'Chapisha kwenye kichwa cha kikundi') ?></label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small"><?= $t('Document body', 'Maandishi ya nyaraka') ?></label>
                <div id="docBody"><?= $doc['body_html'] ?></div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= getUrl('documents_authored') ?>" class="btn btn-light border rounded-pill px-4"><?= $t('Cancel', 'Ghairi') ?></a>
                <button type="submit" class="btn btn-primary rounded-pill px-4" id="docSaveBtn"><i class="bi bi-save me-2"></i><?= $t('Save', 'Hifadhi') ?></button>
            </div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script>
const docIsSw = <?= $is_sw ? 'true' : 'false' ?>;
$(function () {
    $('#docBody').summernote({
        placeholder: docIsSw ? 'Andika nyaraka hapa...' : 'Write your document here...',
        height: 460,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'hr']],
            ['view', ['codeview', 'fullscreen']]
        ]
    });

    // Summernote 0.8 tags its font-size / paragraph / alignment dropdowns with
    // the Bootstrap-4 attribute `data-toggle="dropdown"`, which Bootstrap 5.3
    // ignores — so those menus never open. Re-point them at Bootstrap 5 and
    // instantiate so they drop down. (Colour/table use Summernote's own popups.)
    $('.note-editor [data-toggle="dropdown"]').each(function () {
        this.setAttribute('data-bs-toggle', 'dropdown');
        this.removeAttribute('data-toggle');
        if (window.bootstrap && bootstrap.Dropdown) {
            try { bootstrap.Dropdown.getOrCreateInstance(this); } catch (e) {}
        }
    });

    $('#documentForm').on('submit', function (e) {
        e.preventDefault();
        const title = $('#docTitle').val().trim();
        if (!title) { Swal.fire('', docIsSw ? 'Tafadhali weka kichwa.' : 'Please enter a title.', 'warning'); return; }

        const $btn = $('#docSaveBtn').prop('disabled', true);
        $.post('/actions/save_document', {
            doc_id: $('#docId').val(),
            title: title,
            doc_type: $('#docType').val(),
            status: $('#docStatus').val(),
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
    });
});
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
