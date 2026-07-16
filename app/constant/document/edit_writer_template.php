<?php
// app/constant/document/edit_writer_template.php — create / edit a Document Writer
// template. Uses the shared editor partial so it is identical to the document
// editor (and can never drift back into the toolbar bugs it encodes).
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_documents');
require_once __DIR__ . '/../../../includes/document_editor_assets.php';
require_once 'header.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$tpl_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$tpl = ['name' => '', 'doc_type' => 'letter', 'body_html' => '', 'use_letterhead' => 1];
if ($tpl_id > 0) {
    $stmt = $pdo->prepare("SELECT name, doc_type, body_html, use_letterhead FROM authored_document_templates WHERE id = ?");
    $stmt->execute([$tpl_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $tpl = $row; }
}
?>

<?php vk_document_editor_head(); ?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="fw-bold mb-0 text-primary"><i class="bi bi-files me-2"></i><?= $tpl_id > 0 ? $t('Edit Template', 'Hariri Kiolezo') : $t('New Template', 'Kiolezo Kipya') ?></h3>
        </div>
        <a href="<?= getUrl('writer_templates') ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="bi bi-arrow-left me-2"></i><?= $t('Back', 'Rudi') ?></a>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body p-4">
        <form id="templateForm">
            <input type="hidden" id="tplId" value="<?= (int) $tpl_id ?>">
            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-bold small"><?= $t('Template name', 'Jina la kiolezo') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="tplName" value="<?= htmlspecialchars($tpl['name']) ?>" maxlength="150" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small"><?= $t('Type', 'Aina') ?></label>
                    <select class="form-select" id="tplType">
                        <?php foreach ([
                            'letter'   => $t('Letter', 'Barua'),
                            'contract' => $t('Contract', 'Mkataba'),
                            'notice'   => $t('Notice', 'Tangazo'),
                            'other'    => $t('Other', 'Nyingine'),
                        ] as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $tpl['doc_type'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="tplLetterhead" <?= (int) $tpl['use_letterhead'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="tplLetterhead"><?= $t('Documents from this template print on the group letterhead', 'Nyaraka za kiolezo hiki zichapishwe kwenye kichwa cha kikundi') ?></label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small"><?= $t('Template body', 'Maandishi ya kiolezo') ?></label>
                <div id="tplBody"><?= $tpl['body_html'] ?></div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="<?= getUrl('writer_templates') ?>" class="btn btn-light border rounded-pill px-4"><?= $t('Cancel', 'Ghairi') ?></a>
                <button type="submit" class="btn btn-primary rounded-pill px-4" id="tplSaveBtn"><i class="bi bi-save me-2"></i><?= $t('Save', 'Hifadhi') ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php vk_document_editor_init('#tplBody', $t('Write the template here...', 'Andika kiolezo hapa...')); ?>
<script>
const tplIsSw = <?= $is_sw ? 'true' : 'false' ?>;
$(function () {
    $('#templateForm').on('submit', function (e) {
        e.preventDefault();
        const name = $('#tplName').val().trim();
        if (!name) { Swal.fire('', tplIsSw ? 'Tafadhali weka jina.' : 'Please enter a name.', 'warning'); return; }

        const $btn = $('#tplSaveBtn').prop('disabled', true);
        $.post('/actions/save_writer_template', {
            tpl_id: $('#tplId').val(),
            name: name,
            doc_type: $('#tplType').val(),
            use_letterhead: $('#tplLetterhead').is(':checked') ? 1 : 0,
            body_html: $('#tplBody').summernote('code')
        }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: tplIsSw ? 'Imehifadhiwa' : 'Saved', text: res.message, timer: 1200, showConfirmButton: false })
                    .then(() => window.location.href = '<?= getUrl('writer_templates') ?>');
            } else {
                Swal.fire('Error', res.message || 'Error', 'error');
                $btn.prop('disabled', false);
            }
        }, 'json').fail(() => { Swal.fire('Error', 'Server error', 'error'); $btn.prop('disabled', false); });
    });
});
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
