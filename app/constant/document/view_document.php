<?php
// app/constant/document/view_document.php — read-only view of an authored
// document, with Print (optional group letterhead) and Sign (reusing the shared
// e-signature system). The stored body_html was sanitised on save.
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_documents');
require_once __DIR__ . '/../../../core/workflow.php';
require_once 'header.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
$can_edit = canEdit('manage_documents');

$doc_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$doc = null;
if ($doc_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM authored_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$doc) {
    echo '<div class="alert alert-danger m-4">' . $t('Document not found.', 'Nyaraka haijapatikana.') . '</div>';
    include 'footer.php'; ob_end_flush(); return;
}

// Signature (single authoritative 'signed' slot) + its public URL.
$sig = getWorkflowSignatures($pdo, 'authored_document', $doc_id)['signed'] ?? null;
$sig_url = null;
if ($sig && !empty($sig['sig_path'])) {
    $base = rtrim((function () {
        $root = str_replace('\\', '/', ROOT_DIR);
        $docroot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $b = trim(str_replace($docroot, '', $root), '/');
        return $b !== '' ? '/' . $b : '';
    })(), '/');
    $sig_url = $base . '/' . ltrim($sig['sig_path'], '/');
}
?>

<?php PrintHeader::css(); ?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('documents_authored') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <div class="d-flex gap-2">
            <?php if ($can_edit): ?>
            <a href="<?= getUrl('edit_document') ?>?id=<?= (int) $doc_id ?>" class="btn btn-sm btn-outline-primary rounded-pill"><i class="bi bi-pencil me-1"></i><?= $t('Edit', 'Hariri') ?></a>
            <button type="button" class="btn btn-sm btn-outline-success rounded-pill" onclick="signDocument()"><i class="bi bi-pen me-1"></i><?= $sig && $sig['user_name'] ? $t('Re-sign', 'Saini upya') : $t('Sign', 'Saini') ?></button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
        </div>
    </div>

    <!-- The document sheet -->
    <div class="card border-0 shadow-sm mx-auto doc-sheet" style="max-width:860px;">
        <div class="card-body p-5">
            <?php if ((int) $doc['use_letterhead'] === 1): ?>
                <div class="doc-letterhead"><?php PrintHeader::render($pdo, strtoupper($doc['title'])); ?></div>
            <?php endif; ?>

            <div class="doc-content"><?= $doc['body_html'] ?></div>

            <div class="doc-signature mt-5">
                <?php if ($sig && !empty($sig['user_name'])): ?>
                    <?php if ($sig_url): ?>
                        <div class="mb-1"><img src="<?= htmlspecialchars($sig_url) ?>" alt="e-signature" style="max-height:60px;max-width:210px;object-fit:contain;"></div>
                    <?php endif; ?>
                    <div class="doc-sign-line">
                        <strong><?= htmlspecialchars($sig['user_name']) ?></strong><?= $sig['user_role'] ? ' &mdash; ' . htmlspecialchars($sig['user_role']) : '' ?><br>
                        <small class="text-muted"><i class="bi bi-patch-check me-1"></i><?= $t('Digitally signed', 'Imesainiwa kidijitali') ?> · <?= $sig['signed_at'] ? date('d M Y H:i', strtotime($sig['signed_at'])) : '' ?></small>
                    </div>
                <?php else: ?>
                    <div class="doc-sign-line"><small class="text-muted"><?= $t('Signature', 'Saini') ?></small></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .doc-content { line-height: 1.6; color: #212529; }
    .doc-content table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .doc-content th, .doc-content td { border: 1px solid #ccc; padding: 4px 6px; }
    .doc-sign-line { border-top: 1px solid #333; width: 260px; padding-top: 5px; }
    @media print {
        .no-print { display: none !important; }
        #main-content { background: #fff !important; padding: 0 !important; min-height: 0 !important; }
        .doc-sheet { box-shadow: none !important; max-width: none !important; margin: 0 !important; }
        .doc-sheet .card-body { padding: 0 !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        .doc-content tr { page-break-inside: avoid; }
        .doc-signature { page-break-inside: avoid; }
    }
</style>

<script>
const docIsSw = <?= $is_sw ? 'true' : 'false' ?>;
const DOC_ID = <?= (int) $doc_id ?>;
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
</script>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php include 'footer.php'; ob_end_flush(); ?>
