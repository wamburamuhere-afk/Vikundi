<?php
// app/constant/document/writer_templates.php — reusable starting points for the
// Document Writer (letters / contracts / notices). Leadership-only, like writing.
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_documents');
require_once 'header.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
$can_create = canCreate('manage_documents');
$can_edit   = canEdit('manage_documents');
$can_delete = canDelete('manage_documents');

$tpls = $pdo->query("
    SELECT tpl.id, tpl.name, tpl.doc_type, tpl.use_letterhead, tpl.updated_at,
           TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS author
      FROM authored_document_templates tpl
      LEFT JOIN users u ON u.user_id = tpl.created_by
     ORDER BY tpl.updated_at DESC, tpl.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$type_labels = [
    'letter'   => $t('Letter', 'Barua'),
    'contract' => $t('Contract', 'Mkataba'),
    'notice'   => $t('Notice', 'Tangazo'),
    'other'    => $t('Other', 'Nyingine'),
];
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0d6efd !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-files me-2"></i><?= $t('Document Templates', 'Violezo vya Nyaraka') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Reusable starting points for letters, contracts and notices', 'Violezo vya kuanzia kwa barua, mikataba na matangazo') ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= getUrl('documents_authored') ?>" class="btn btn-outline-secondary rounded-pill px-4"><i class="bi bi-file-earmark-text me-2"></i><?= $t('Documents', 'Nyaraka') ?></a>
                <?php if ($can_create): ?>
                <a href="<?= getUrl('edit_writer_template') ?>" class="btn btn-primary rounded-pill px-4"><i class="bi bi-plus-lg me-2"></i><?= $t('New Template', 'Kiolezo Kipya') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="table-responsive">
            <table id="templatesTable" class="table table-hover align-middle" style="width:100%">
                <thead class="bg-light text-muted small">
                    <tr>
                        <th style="width:50px">#</th>
                        <th><?= $t('Name', 'Jina') ?></th>
                        <th><?= $t('Type', 'Aina') ?></th>
                        <th><?= $t('Letterhead', 'Kichwa cha barua') ?></th>
                        <th><?= $t('Author', 'Mwandishi') ?></th>
                        <th class="text-nowrap"><?= $t('Updated', 'Imesasishwa') ?></th>
                        <th class="text-end"><?= $t('Actions', 'Vitendo') ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php foreach ($tpls as $i => $tp): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($tp['name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($type_labels[$tp['doc_type']] ?? $tp['doc_type']) ?></span></td>
                        <td><?= (int) $tp['use_letterhead'] === 1
                                ? '<span class="badge bg-success-subtle text-success border border-success-subtle">' . $t('Yes', 'Ndio') . '</span>'
                                : '<span class="badge bg-light text-muted border">' . $t('No', 'Hapana') . '</span>' ?></td>
                        <td><?= htmlspecialchars($tp['author'] ?: '—') ?></td>
                        <td class="text-nowrap text-muted"><?= $tp['updated_at'] ? date('d M Y', strtotime($tp['updated_at'])) : '—' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($can_create): ?>
                            <a href="<?= getUrl('edit_document') ?>?tpl=<?= (int) $tp['id'] ?>" class="btn btn-sm btn-outline-success" title="<?= $t('Use this template', 'Tumia kiolezo hiki') ?>"><i class="bi bi-file-earmark-plus"></i></a>
                            <?php endif; ?>
                            <?php if ($can_edit): ?>
                            <a href="<?= getUrl('edit_writer_template') ?>?id=<?= (int) $tp['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= $t('Edit', 'Hariri') ?>"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(<?= (int) $tp['id'] ?>)" title="<?= $t('Delete', 'Futa') ?>"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div></div>
</div>

<script>
const tplIsSw = <?= $is_sw ? 'true' : 'false' ?>;
$(function () {
    $('#templatesTable').DataTable({
        order: [[5, 'desc']],
        columnDefs: [{ orderable: false, targets: [0, 6] }],
        language: { search: tplIsSw ? 'Tafuta:' : 'Search:', emptyTable: tplIsSw ? 'Hakuna violezo bado.' : 'No templates yet.' }
    });
});

function deleteTemplate(id) {
    Swal.fire({
        title: tplIsSw ? 'Thibitisha' : 'Confirm',
        text: tplIsSw ? 'Futa kiolezo hiki? Nyaraka zilizoandikwa kutoka kwake hazitaguswa.' : 'Delete this template? Documents already written from it are not affected.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: tplIsSw ? 'Ndio, futa' : 'Yes, delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/delete_writer_template', { tpl_id: id }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: tplIsSw ? 'Imefanyika' : 'Done', text: res.message, timer: 1300, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Error', 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
    });
}
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
