<?php
// app/constant/document/documents_authored.php — list of in-system authored
// documents (letters / contracts / notices) with create / edit / delete.
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

$docs = $pdo->query("
    SELECT d.id, d.title, d.doc_type, d.status, d.use_letterhead, d.updated_at,
           TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS author
      FROM authored_documents d
      LEFT JOIN users u ON u.user_id = d.created_by
     ORDER BY d.updated_at DESC, d.id DESC
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
                <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-file-earmark-text me-2"></i><?= $t('Document Writer', 'Uandishi wa Nyaraka') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Write, print and sign letters, contracts and notices', 'Andika, chapisha na saini barua, mikataba na matangazo') ?></p>
            </div>
            <?php if ($can_create): ?>
            <a href="<?= getUrl('edit_document') ?>" class="btn btn-primary rounded-pill px-4"><i class="bi bi-plus-lg me-2"></i><?= $t('New Document', 'Nyaraka Mpya') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="table-responsive">
            <table id="documentsTable" class="table table-hover align-middle" style="width:100%">
                <thead class="bg-light text-muted small">
                    <tr>
                        <th style="width:50px">#</th>
                        <th><?= $t('Title', 'Kichwa') ?></th>
                        <th><?= $t('Type', 'Aina') ?></th>
                        <th><?= $t('Status', 'Hali') ?></th>
                        <th><?= $t('Author', 'Mwandishi') ?></th>
                        <th class="text-nowrap"><?= $t('Updated', 'Imesasishwa') ?></th>
                        <th class="text-end"><?= $t('Actions', 'Vitendo') ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php foreach ($docs as $i => $d): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($d['title']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($type_labels[$d['doc_type']] ?? $d['doc_type']) ?></span></td>
                        <td><span class="badge bg-<?= $d['status'] === 'final' ? 'success' : 'secondary' ?>"><?= $d['status'] === 'final' ? $t('Final', 'Kamili') : $t('Draft', 'Rasimu') ?></span></td>
                        <td><?= htmlspecialchars($d['author'] ?: '—') ?></td>
                        <td class="text-nowrap text-muted"><?= $d['updated_at'] ? date('d M Y', strtotime($d['updated_at'])) : '—' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($can_edit): ?>
                            <a href="<?= getUrl('edit_document') ?>?id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= $t('Edit', 'Hariri') ?>"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteDocument(<?= (int) $d['id'] ?>)" title="<?= $t('Delete', 'Futa') ?>"><i class="bi bi-trash"></i></button>
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
const docIsSw = <?= $is_sw ? 'true' : 'false' ?>;
$(function () {
    $('#documentsTable').DataTable({
        order: [[5, 'desc']],
        columnDefs: [{ orderable: false, targets: [0, 6] }],
        language: { search: docIsSw ? 'Tafuta:' : 'Search:', emptyTable: docIsSw ? 'Hakuna nyaraka bado.' : 'No documents yet.' }
    });
});

function deleteDocument(id) {
    Swal.fire({
        title: docIsSw ? 'Thibitisha' : 'Confirm',
        text: docIsSw ? 'Futa nyaraka hii?' : 'Delete this document?',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: docIsSw ? 'Ndio, futa' : 'Yes, delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/delete_document', { doc_id: id }, res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: docIsSw ? 'Imefanyika' : 'Done', text: res.message, timer: 1300, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Error', 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
    });
}
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
