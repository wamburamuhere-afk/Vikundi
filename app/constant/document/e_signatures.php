<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
ob_start();

require_once __DIR__ . '/../../../roots.php';
// Your own e-signature is personal, like a profile setting — every signed-in user
// manages their own. Every endpoint behind this page is already auth-only and
// self-scoped (WHERE user_id = session user), so there is nothing here to gate:
// the 'documents' permission governs the document LIBRARY, not your signature.
// This also matters for multi-party signing — a member assigned to sign a document
// must be able to upload a signature image, or their signature prints blank.
if (!isAuthenticated()) { redirectTo('login'); }
require_once 'header.php';

$lang = $_SESSION['preferred_language'] ?? 'en';
$sw   = ($lang === 'sw');

?>

<!-- Stat Cards -->
<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="mb-0"><i class="bi bi-pen-fill"></i>
                        <?= $sw ? 'Saini za Kielektroniki' : 'Electronic Signatures' ?>
                    </h4>
                    <p class="text-muted mb-0 small">
                        <?= $sw
                            ? 'Simamia saini zako za kidijitali na tia saini nyaraka kwa njia ya kielektroniki'
                            : 'Manage your digital signatures and sign documents electronically' ?>
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <!-- Managing your OWN signature needs no permission. -->
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openUploadSignatureModal()">
                        <i class="bi bi-cloud-upload"></i>
                        <?= $sw ? 'Pakia Saini' : 'Upload Signature' ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="openDrawSignatureModal()">
                        <i class="bi bi-pencil"></i>
                        <?= $sw ? 'Chora Saini' : 'Draw Signature' ?>
                    </button>
                    <?php if (canView('documents')): ?>
                    <!-- This one goes into the document library, which IS permissioned —
                         hide it rather than hand out a link that dead-ends. -->
                    <a href="<?= getUrl('select_document_add_esignature') ?>"
                       class="btn btn-sm btn-primary">
                        <i class="bi bi-file-earmark-check"></i>
                        <?= $sw ? 'Tia Saini Hati' : 'Sign Document' ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- §UI-1 Stat Cards -->
    <div class="row mb-4">
        <div class="col-6 col-md-3 mb-3">
            <div class="card vk-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold text-primary" id="stat-my-signatures">—</div>
                            <div class="small text-muted"><?= $sw ? 'Saini Zangu' : 'My Signatures' ?></div>
                        </div>
                        <i class="bi bi-vector-pen fs-2 text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card vk-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold text-warning" id="stat-pending-signatures">—</div>
                            <div class="small text-muted"><?= $sw ? 'Zinazosubiri' : 'Pending' ?></div>
                        </div>
                        <i class="bi bi-clock-history fs-2 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card vk-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold text-success" id="stat-signed-documents">—</div>
                            <div class="small text-muted"><?= $sw ? 'Nyaraka Zilizotiwa Saini' : 'Signed Documents' ?></div>
                        </div>
                        <i class="bi bi-check-circle-fill fs-2 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card vk-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold text-secondary" id="stat-total-history">—</div>
                            <div class="small text-muted"><?= $sw ? 'Historia Yote' : 'Total History' ?></div>
                        </div>
                        <i class="bi bi-archive fs-2 text-secondary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="signatureTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="my-signatures-tab" data-bs-toggle="tab"
                data-bs-target="#my-signatures" type="button" role="tab">
                <i class="bi bi-vector-pen"></i>
                <?= $sw ? 'Saini Zangu' : 'My Signatures' ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab"
                data-bs-target="#pending" type="button" role="tab">
                <i class="bi bi-clock-history"></i>
                <?= $sw ? 'Zinazosubiri' : 'Pending' ?>
                <span class="badge bg-warning text-dark ms-1" id="pending-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab"
                data-bs-target="#history" type="button" role="tab">
                <i class="bi bi-archive"></i>
                <?= $sw ? 'Historia' : 'History' ?>
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="signatureTabContent">

        <!-- My Signatures Tab -->
        <div class="tab-pane fade show active" id="my-signatures" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        <i class="bi bi-vector-pen me-1"></i>
                        <?= $sw ? 'Saini Zilizohifadhiwa' : 'Saved Signatures' ?>
                    </span>
                    <span class="badge bg-light text-dark border" id="stat-signatures-count">
                        0 <?= $sw ? 'saini' : 'signatures' ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="signaturesTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th><?= $sw ? 'Picha' : 'Preview' ?></th>
                                    <th><?= $sw ? 'Aina' : 'Type' ?></th>
                                    <th><?= $sw ? 'Iliundwa' : 'Created' ?></th>
                                    <th><?= $sw ? 'Hali' : 'Status' ?></th>
                                    <th class="text-end"><?= $sw ? 'Vitendo' : 'Actions' ?></th>
                                </tr>
                            </thead>
                            <tbody class="small"></tbody>
                        </table>
                    </div>
                    <!-- Mobile card view (§UI-6) -->
                    <div id="signaturesCards" class="d-md-none p-2"></div>
                </div>
            </div>
        </div>

        <!-- Pending Tab -->
        <div class="tab-pane fade" id="pending" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        <i class="bi bi-clock-history me-1"></i>
                        <?= $sw ? 'Nyaraka Zinazongoja Saini Yako' : 'Documents Awaiting Your Signature' ?>
                    </span>
                    <span class="badge bg-light text-dark border" id="stat-pending-count">
                        0 <?= $sw ? 'nyaraka' : 'documents' ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="pendingTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th><?= $sw ? 'Hati' : 'Document' ?></th>
                                    <th><?= $sw ? 'Aliyeomba' : 'Requested By' ?></th>
                                    <th><?= $sw ? 'Mteja' : 'Customer' ?></th>
                                    <th><?= $sw ? 'Tarehe ya Mwisho' : 'Due Date' ?></th>
                                    <th><?= $sw ? 'Hali' : 'Status' ?></th>
                                    <th class="text-end"><?= $sw ? 'Vitendo' : 'Actions' ?></th>
                                </tr>
                            </thead>
                            <tbody class="small"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- History Tab -->
        <div class="tab-pane fade" id="history" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        <i class="bi bi-archive me-1"></i>
                        <?= $sw ? 'Historia ya Saini' : 'Signature History' ?>
                    </span>
                    <span class="badge bg-light text-dark border" id="stat-history-count">
                        0 <?= $sw ? 'rekodi' : 'records' ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="historyTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th><?= $sw ? 'Hati' : 'Document' ?></th>
                                    <th><?= $sw ? 'Mteja' : 'Customer' ?></th>
                                    <th><?= $sw ? 'Ilisainiwa' : 'Signed At' ?></th>
                                    <th><?= $sw ? 'Anwani ya IP' : 'IP Address' ?></th>
                                    <th><?= $sw ? 'Nafasi' : 'Position' ?></th>
                                    <th class="text-end"><?= $sw ? 'Vitendo' : 'Actions' ?></th>
                                </tr>
                            </thead>
                            <tbody class="small"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div><!-- /container-fluid -->

<!-- ===== Upload Signature Modal ===== -->
<div class="modal fade" id="uploadSignatureModal" tabindex="-1"
     aria-labelledby="uploadSignatureModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadSignatureModalLabel">
                    <i class="bi bi-cloud-upload me-1"></i>
                    <?= $sw ? 'Pakia Saini' : 'Upload Signature' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="uploadSignatureForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="signature_file" class="form-label">
                            <?= $sw ? 'Picha ya Saini' : 'Signature Image' ?>
                        </label>
                        <input type="file" class="form-control" id="signature_file"
                               name="signature_file" accept=".png,.jpg,.jpeg" required>
                        <div class="form-text">
                            <?= $sw
                                ? 'Pakia picha wazi ya saini yako. Aina zinazoruhusiwa: PNG, JPG. Ukubwa wa juu: 2MB.'
                                : 'Upload a clear image of your signature. Allowed formats: PNG, JPG. Max size: 2MB.' ?>
                        </div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <?= $sw
                            ? 'Kwa matokeo bora, tumia mandhari nyeupe na wino mweusi.'
                            : 'For best results, use a white background and black ink.' ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $sw ? 'Ghairi' : 'Cancel' ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnUploadSignature">
                        <i class="bi bi-cloud-upload me-1"></i>
                        <?= $sw ? 'Pakia' : 'Upload' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Draw Signature Modal ===== -->
<div class="modal fade" id="drawSignatureModal" tabindex="-1"
     aria-labelledby="drawSignatureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="drawSignatureModalLabel">
                    <i class="bi bi-pencil me-1"></i>
                    <?= $sw ? 'Chora Saini Yako' : 'Draw Your Signature' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="drawSignatureForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <canvas id="signaturePad"
                            style="border: 2px solid #0d6efd; border-radius: 8px; cursor: crosshair;
                                   width: 100%; height: 200px; min-height: 200px; display: block;
                                   background: repeating-linear-gradient(45deg,#f8f9fa,#f8f9fa 10px,#fff 10px,#fff 20px);
                                   touch-action: none;">
                        </canvas>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearSignature()">
                            <i class="bi bi-eraser me-1"></i><?= $sw ? 'Futa' : 'Clear' ?>
                        </button>
                        <small class="text-muted">
                            <?= $sw ? 'Chora saini yako katika sanduku hapo juu' : 'Draw your signature in the box above' ?>
                        </small>
                    </div>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= $sw
                            ? 'Kwa kuhifadhi saini hii, unakubali kuwa ni saini yako ya kisheria ya kielektroniki.'
                            : 'By saving this signature, you acknowledge it as your legally binding electronic signature.' ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $sw ? 'Ghairi' : 'Cancel' ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearSignature()">
                        <i class="bi bi-eraser me-1"></i><?= $sw ? 'Futa' : 'Clear' ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveSignatureBtn" disabled>
                        <i class="bi bi-floppy me-1"></i><?= $sw ? 'Hifadhi Saini' : 'Save Signature' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Apply Signature Modal (from Pending tab) ===== -->
<div class="modal fade" id="applySignatureModal" tabindex="-1"
     aria-labelledby="applySignatureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="applySignatureModalLabel">
                    <i class="bi bi-pen me-1"></i>
                    <?= $sw ? 'Tia Saini Hati' : 'Sign Document' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="applySignatureForm">
                <input type="hidden" name="document_id" id="applyDocumentId">
                <input type="hidden" name="signature_id" id="applySignatureId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= $sw ? 'Chagua Saini Yako' : 'Select Your Signature' ?>
                        </label>
                        <div id="signatureSelection" class="row g-2"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= $sw ? 'Nafasi ya Saini' : 'Signature Position' ?>
                        </label>
                        <select class="form-select" name="signature_position" id="signaturePosition">
                            <option value="bottom_right"><?= $sw ? 'Chini Kulia' : 'Bottom Right' ?></option>
                            <option value="bottom_left"><?= $sw ? 'Chini Kushoto' : 'Bottom Left' ?></option>
                            <option value="bottom_center"><?= $sw ? 'Chini Katikati' : 'Bottom Center' ?></option>
                            <option value="custom"><?= $sw ? 'Nafasi Maalum' : 'Custom Position' ?></option>
                        </select>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-shield-check me-1"></i>
                        <?= $sw
                            ? 'Kitendo hiki kitatia saini yako ya kielektroniki kwenye hati. Hii ni ya kisheria.'
                            : 'This action will apply your electronic signature to the document. This is legally binding.' ?>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="legalAgreement">
                        <label class="form-check-label small" for="legalAgreement">
                            <?= $sw
                                ? 'Nakubali kwamba saini hii ya kielektroniki ina nguvu ya kisheria sawa na saini yangu ya mkono.'
                                : 'I agree that this electronic signature is legally binding and equivalent to my handwritten signature.' ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?= $sw ? 'Ghairi' : 'Cancel' ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="applySignatureBtn" disabled>
                        <i class="bi bi-pen me-1"></i>
                        <?= $sw ? 'Tia Saini' : 'Apply Signature' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Compute API/AJAX base URLs for JS (same pattern as document_library.php)
$doc_root  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$proj_root = str_replace('\\', '/', ROOT_DIR);
$base_path = trim(str_ireplace($doc_root, '', $proj_root), '/');
$api_base  = (!empty($base_path) ? '/' . $base_path : '') . '/api';
$ajax_base = (!empty($base_path) ? '/' . $base_path : '') . '/ajax';
?>

<style>
/* §UI-1 — Stat Cards */
.vk-stat-card {
    background: #e7f0ff;
    border: 1px solid #b6ccfe;
    border-radius: 12px;
    box-shadow: 0 2px 6px rgba(13,110,253,.08);
    transition: transform .2s, box-shadow .2s;
}
.vk-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(13,110,253,.14);
}

/* Signature canvas */
#signaturePad { touch-action: none; }

/* Signature picker card */
.sig-card {
    cursor: pointer;
    border: 2px solid transparent;
    border-radius: 8px;
    transition: border-color .15s, box-shadow .15s;
}
.sig-card:hover { border-color: #86b7fe; box-shadow: 0 2px 8px rgba(13,110,253,.12); }
.sig-card.selected { border-color: #0d6efd !important; background: #f0f5ff; }

</style>

<script>
const _sw = <?= $sw ? 'true' : 'false' ?>;
// Your own signature is yours to add or remove — no library permission involved.
// The delete endpoint is self-scoped (WHERE id = ? AND user_id = ?), so a user can
// only ever remove their own.
const _canCreate = true;
const _canDelete = true;

// — Bilingual strings —
const T = {
    selectFirst:   _sw ? 'Chagua saini kwanza kutoka kwenye kichupo cha "Saini Zangu".' : 'Please select a signature first from the "My Signatures" tab.',
    sigSelected:   _sw ? 'Saini imechaguliwa!' : 'Signature selected!',
    deleteConfirm: _sw ? 'Una uhakika unataka kufuta saini hii? Kitendo hiki hakiwezi kutenduliwa.' : 'Are you sure you want to delete this signature? This action cannot be undone.',
    deleteTitle:   _sw ? 'Futa Saini' : 'Delete Signature',
    deleteYes:     _sw ? 'Ndiyo, Futa' : 'Yes, Delete',
    deleteNo:      _sw ? 'Hapana' : 'Cancel',
    deletedOk:     _sw ? 'Saini imefutwa.' : 'Signature deleted.',
    uploadOk:      _sw ? 'Saini imepakiwa kwa mafanikio!' : 'Signature uploaded successfully!',
    drawOk:        _sw ? 'Saini imehifadhiwa kwa mafanikio!' : 'Signature drawn and saved!',
    applyOk:       _sw ? 'Saini imetumika kwa mafanikio!' : 'Signature applied successfully!',
    uploadFail:    _sw ? 'Imeshindwa kupakia saini.' : 'Failed to upload signature.',
    drawFail:      _sw ? 'Imeshindwa kuhifadhi saini.' : 'Failed to save signature.',
    applyFail:     _sw ? 'Imeshindwa kutia saini.' : 'Failed to apply signature.',
    deleteFail:    _sw ? 'Imeshindwa kufuta saini.' : 'Failed to delete signature.',
    noSigs:        _sw ? 'Huna saini zilizohifadhiwa. Chora au pakia saini kwanza.' : 'No saved signatures. Draw or upload one first.',
    drawOne:       _sw ? 'Chora Saini' : 'Draw One Now',
    signatures:    _sw ? 'saini' : 'signatures',
    documents:     _sw ? 'nyaraka' : 'documents',
    records:       _sw ? 'rekodi' : 'records',
    signedOk:      _sw ? 'Umesaini kwa mafanikio!' : 'Signed successfully!',
    error:         _sw ? 'Hitilafu' : 'Error',
    success:       _sw ? 'Mafanikio' : 'Success',
    confirm:       _sw ? 'Thibitisha' : 'Confirm',
    uploading:     _sw ? 'Inapakia...' : 'Uploading...',
    saving:        _sw ? 'Inahifadhi...' : 'Saving...',
    applying:      _sw ? 'Inatumika...' : 'Applying...',
    processing:    _sw ? 'Inashughulikia...' : 'Processing...',
    noDocFound:    _sw ? 'Hakuna hati zilizopatikana' : 'No documents found',
    searchFirst:   _sw ? 'Bonyeza tafuta kupakia hati' : 'Click search to load documents',
};

let selectedSignatureId = null; // selected from "My Signatures" tab (for Apply modal)

/* ================================================================
   DataTables
================================================================ */
$(document).ready(function() {

    // — My Signatures table —
    const signaturesTable = $('#signaturesTable').DataTable({
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= $api_base ?>/get_user_signatures.php',
            dataSrc: function(json) {
                const n = json.recordsTotal || 0;
                $('#stat-my-signatures').text(n);
                $('#stat-signatures-count').text(n + ' ' + T.signatures);
                return json.data;
            }
        },
        columns: [
            {
                data: null, orderable: false,
                render: function(d, t, row) {
                    if (row.thumbnail_path) {
                        return '<img src="' + escHtml(row.thumbnail_path) + '" alt="sig" '
                            + 'style="max-height:56px;max-width:110px;border:1px solid #dee2e6;'
                            + 'border-radius:4px;padding:4px;background:#f8f9fa;object-fit:contain">';
                    }
                    return '<div style="width:110px;height:56px;background:#f8f9fa;border:1px solid #dee2e6;'
                        + 'border-radius:4px;display:flex;align-items:center;justify-content:center">'
                        + '<i class="bi bi-' + sigTypeIcon(row.signature_type) + ' fs-3 text-muted"></i></div>';
                }
            },
            {
                data: 'signature_type',
                render: function(d) {
                    const map = { uploaded:'primary', drawn:'success', typed:'info' };
                    const c   = map[d] || 'secondary';
                    return '<span class="badge bg-' + c + '-subtle text-' + c + ' border border-' + c
                        + '-subtle px-2 text-uppercase small"><i class="bi bi-'
                        + sigTypeIcon(d) + ' me-1"></i>' + d + '</span>';
                }
            },
            {
                data: 'created_at',
                render: function(d) {
                    return d ? new Date(d).toLocaleDateString(
                        _sw ? 'sw-TZ' : 'en-US',
                        {month:'short', day:'numeric', year:'numeric'}
                    ) : '—';
                }
            },
            {
                data: 'status',
                render: function(d) {
                    return d === 'active'
                        ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2">'
                          + '<i class="bi bi-check-circle me-1"></i>' + (_sw ? 'Amilifu' : 'Active') + '</span>'
                        : '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2">'
                          + '<i class="bi bi-x-circle me-1"></i>' + (_sw ? 'Imezimwa' : 'Inactive') + '</span>';
                }
            },
            {
                data: null, orderable: false, className: 'text-end',
                render: function(d, t, row) {
                    let html = '<div class="dropdown">'
                        + '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" '
                        + 'type="button" data-bs-toggle="dropdown" aria-expanded="false">'
                        + '<i class="bi bi-gear"></i></button>'
                        + '<ul class="dropdown-menu dropdown-menu-end">'
                        + '<li><a class="dropdown-item" href="#" onclick="selectSignature(' + row.id + ');return false">'
                        + '<i class="bi bi-check2-circle me-1"></i>' + (_sw ? 'Chagua' : 'Select') + '</a></li>';
                    if (row.file_path) {
                        html += '<li><a class="dropdown-item" href="' + escHtml(row.file_path) + '" target="_blank">'
                            + '<i class="bi bi-eye me-1"></i>' + (_sw ? 'Ona Ukubwa Kamili' : 'View Full Size') + '</a></li>';
                    }
                    if (_canDelete) {
                        html += '<li><hr class="dropdown-divider"></li>'
                            + '<li><a class="dropdown-item text-danger" href="#" '
                            + 'onclick="confirmDeleteSignature(' + row.id + ');return false">'
                            + '<i class="bi bi-trash me-1"></i>' + (_sw ? 'Futa' : 'Delete') + '</a></li>';
                    }
                    html += '</ul></div>';
                    return html;
                }
            }
        ],
        order: [[2, 'desc']]
    });

    // — Pending Signatures table —
    const pendingTable = $('#pendingTable').DataTable({
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= $api_base ?>/get_pending_signatures.php',
            dataSrc: function(json) {
                const n = json.recordsTotal || 0;
                $('#stat-pending-signatures').text(n);
                $('#pending-count').text(n);
                $('#stat-pending-count').text(n + ' ' + T.documents);
                return json.data;
            }
        },
        columns: [
            {
                data: 'document_name',
                render: function(d, t, row) {
                    return '<strong>' + escHtml(d) + '</strong>'
                        + '<br><small class="text-muted">' + escHtml(row.document_type || '—') + '</small>';
                }
            },
            { data: 'requested_by_name', render: function(d) { return escHtml(d || '—'); } },
            { data: 'customer_name',     render: function(d) { return d ? escHtml(d) : '<span class="text-muted">N/A</span>'; } },
            {
                data: 'due_date',
                render: function(d) {
                    if (!d) return '<span class="text-muted">—</span>';
                    const due = new Date(d), now = new Date();
                    const col = due < now ? 'danger' : 'warning';
                    return '<span class="badge bg-' + col + '-subtle text-' + col + ' border border-' + col + '-subtle">'
                        + due.toLocaleDateString(_sw ? 'sw-TZ' : 'en-US', {month:'short', day:'numeric', year:'numeric'})
                        + '</span>';
                }
            },
            {
                data: 'status',
                render: function(d) {
                    const map = { pending:'warning', signed:'success', rejected:'danger' };
                    const c   = map[d] || 'secondary';
                    return '<span class="badge bg-' + c + '-subtle text-' + c + ' border border-' + c
                        + '-subtle px-2 text-uppercase small">' + d + '</span>';
                }
            },
            {
                data: null, orderable: false, className: 'text-end',
                render: function(d, t, row) {
                    return '<div class="dropdown">'
                        + '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" '
                        + 'type="button" data-bs-toggle="dropdown" aria-expanded="false">'
                        + '<i class="bi bi-gear"></i></button>'
                        + '<ul class="dropdown-menu dropdown-menu-end">'
                        + '<li><a class="dropdown-item" href="#" '
                        + 'onclick="openApplySignatureModal(' + row.document_id + ');return false">'
                        + '<i class="bi bi-pen me-1"></i>' + (_sw ? 'Tia Saini' : 'Sign') + '</a></li>'
                        + '</ul></div>';
                }
            }
        ],
        order: [[3, 'asc']]
    });

    // — Signature History table —
    const historyTable = $('#historyTable').DataTable({
        dom: "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-sm-5'i><'col-sm-7'p>>",
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= $api_base ?>/get_signature_history.php',
            dataSrc: function(json) {
                const n = json.recordsTotal || 0;
                $('#stat-signed-documents').text(json.stats?.signedDocuments || n);
                $('#stat-total-history').text(n);
                $('#stat-history-count').text(n + ' ' + T.records);
                return json.data;
            }
        },
        columns: [
            {
                data: 'document_name',
                render: function(d, t, row) {
                    return '<strong>' + escHtml(d) + '</strong>'
                        + '<br><small class="text-muted">' + escHtml(row.document_type || '—') + '</small>';
                }
            },
            { data: 'customer_name', render: function(d) { return d ? escHtml(d) : '<span class="text-muted">N/A</span>'; } },
            {
                data: 'signed_at',
                render: function(d) {
                    return d ? new Date(d).toLocaleString(_sw ? 'sw-TZ' : 'en-US',
                        {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '—';
                }
            },
            { data: 'ip_address', render: function(d) { return '<code class="small">' + escHtml(d || '—') + '</code>'; } },
            {
                data: 'signature_position',
                render: function(d) {
                    return '<span class="badge bg-secondary-subtle text-secondary small">'
                        + (d || '—').replace(/_/g, ' ') + '</span>';
                }
            },
            {
                data: null, orderable: false, className: 'text-end',
                render: function(d, t, row) {
                    return '<div class="dropdown">'
                        + '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" '
                        + 'type="button" data-bs-toggle="dropdown">'
                        + '<i class="bi bi-gear"></i></button>'
                        + '<ul class="dropdown-menu dropdown-menu-end">'
                        + '<li><a class="dropdown-item" href="'
                        + '<?= htmlspecialchars(getUrl("document_library")) ?>?action=download&document_id=' + row.document_id + '">'
                        + '<i class="bi bi-download me-1"></i>' + (_sw ? 'Pakua Hati' : 'Download Document') + '</a></li>'
                        + '</ul></div>';
                }
            }
        ],
        order: [[2, 'desc']]
    });

    // Legal agreement toggle for Apply modal
    $('#legalAgreement').on('change', function() {
        $('#applySignatureBtn').prop('disabled', !(this.checked && applySelectedSigId));
    });
});

/* ================================================================
   Signature Pad (Draw modal)
================================================================ */
let canvas, ctx, isDrawing = false, lastX = 0, lastY = 0;

function initSignaturePad() {
    const orig = document.getElementById('signaturePad');
    if (!orig) return;
    // Clone to strip old listeners
    const fresh = orig.cloneNode(true);
    orig.parentNode.replaceChild(fresh, orig);
    canvas = fresh;
    ctx    = canvas.getContext('2d');

    const rect = canvas.getBoundingClientRect();
    const dpr  = window.devicePixelRatio || 1;
    canvas.width  = rect.width  * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#000';
    ctx.lineWidth   = 2.5;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';

    canvas.addEventListener('mousedown',  startDraw);
    canvas.addEventListener('mousemove',  doDraw);
    canvas.addEventListener('mouseup',    stopDraw);
    canvas.addEventListener('mouseleave', stopDraw);
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); startDraw(e); }, { passive:false });
    canvas.addEventListener('touchmove',  function(e) { e.preventDefault(); doDraw(e);   }, { passive:false });
    canvas.addEventListener('touchend',   stopDraw);

    $('#saveSignatureBtn').prop('disabled', true);
}

function coords(e) {
    const r  = canvas.getBoundingClientRect();
    const sx = canvas.width  / r.width;
    const sy = canvas.height / r.height;
    const dpr = window.devicePixelRatio || 1;
    if (e.touches && e.touches.length) {
        return { x: (e.touches[0].clientX - r.left) * sx / dpr,
                 y: (e.touches[0].clientY - r.top)  * sy / dpr };
    }
    return { x: (e.clientX - r.left) * sx / dpr,
             y: (e.clientY - r.top)  * sy / dpr };
}

function startDraw(e) { e.preventDefault(); isDrawing = true; const p = coords(e); lastX = p.x; lastY = p.y; ctx.beginPath(); ctx.moveTo(lastX, lastY); }
function doDraw(e) {
    if (!isDrawing) return;
    e.preventDefault();
    const p = coords(e);
    ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.stroke();
    lastX = p.x; lastY = p.y;
    $('#saveSignatureBtn').prop('disabled', false);
}
function stopDraw(e) { if (isDrawing) { e.preventDefault(); isDrawing = false; ctx.beginPath(); } }

function clearSignature() {
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    $('#saveSignatureBtn').prop('disabled', true);
}

/* ================================================================
   Modal openers
================================================================ */
function openUploadSignatureModal() {
    new bootstrap.Modal(document.getElementById('uploadSignatureModal')).show();
}
function openDrawSignatureModal() {
    new bootstrap.Modal(document.getElementById('drawSignatureModal')).show();
    setTimeout(initSignaturePad, 320);
}

function selectSignature(id) {
    selectedSignatureId = id;
    Swal.fire({
        icon: 'success',
        title: T.sigSelected,
        text: _sw ? 'Unaweza sasa kutia saini hati.' : 'You can now use it to sign documents.',
        timer: 2000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
}

function openApplySignatureModal(documentId) {
    $('#applyDocumentId').val(documentId);
    $('#applySignatureId').val('');
    $('#legalAgreement').prop('checked', false);
    $('#applySignatureBtn').prop('disabled', true);
    applySelectedSigId = null;

    loadSignaturePickerInto('#signatureSelection', function(id) {
        applySelectedSigId = id;
        $('#applySignatureId').val(id);
        $('#applySignatureBtn').prop('disabled', !$('#legalAgreement').is(':checked'));
    });

    new bootstrap.Modal(document.getElementById('applySignatureModal')).show();
}

/* ================================================================
   Upload Signature Form
================================================================ */
$('#uploadSignatureForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnUploadSignature');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + T.uploading);
    $.ajax({
        url: '<?= $api_base ?>/document/upload_signature.php',
        type: 'POST',
        data: new FormData(this),
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('uploadSignatureModal'))?.hide();
                $('#signaturesTable').DataTable().ajax.reload();
                $('#uploadSignatureForm')[0].reset();
                swalToast('success', T.uploadOk);
            } else {
                swalToast('error', res.message || T.uploadFail);
            }
        },
        error: function() { swalToast('error', T.uploadFail); },
        complete: function() {
            btn.prop('disabled', false).html(
                '<i class="bi bi-cloud-upload me-1"></i>' + (_sw ? 'Pakia' : 'Upload'));
        }
    });
});

/* ================================================================
   Draw Signature Form
================================================================ */
$('#drawSignatureForm').on('submit', function(e) {
    e.preventDefault();
    const cvs = document.getElementById('signaturePad');
    if (!cvs) { swalToast('error', _sw ? 'Canvas haikupatikana.' : 'Canvas not found.'); return; }
    const btn = $('#saveSignatureBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + T.saving);
    $.ajax({
        url: '<?= $ajax_base ?>/save_drawn_signature.php',
        type: 'POST',
        data: { signature_data: cvs.toDataURL('image/png') },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('drawSignatureModal'))?.hide();
                $('#signaturesTable').DataTable().ajax.reload();
                clearSignature();
                swalToast('success', T.drawOk);
            } else {
                swalToast('error', res.message || T.drawFail);
            }
        },
        error: function() { swalToast('error', T.drawFail); },
        complete: function() {
            btn.prop('disabled', false).html(
                '<i class="bi bi-floppy me-1"></i>' + (_sw ? 'Hifadhi Saini' : 'Save Signature'));
        }
    });
});

/* ================================================================
   Apply Signature Form (from Pending tab)
================================================================ */
let applySelectedSigId = null;

$('#applySignatureForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#applySignatureBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + T.applying);
    $.ajax({
        url: '<?= $api_base ?>/document/apply_signature.php',
        type: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('applySignatureModal'))?.hide();
                $('#pendingTable').DataTable().ajax.reload();
                $('#historyTable').DataTable().ajax.reload();
                swalToast('success', T.applyOk);
            } else {
                swalToast('error', res.message || T.applyFail);
            }
        },
        error: function() { swalToast('error', T.applyFail); },
        complete: function() {
            btn.prop('disabled', false).html(
                '<i class="bi bi-pen me-1"></i>' + (_sw ? 'Tia Saini' : 'Apply Signature'));
        }
    });
});

/* ================================================================
   Delete Signature (§UI-4 — SweetAlert2 confirm)
================================================================ */
function confirmDeleteSignature(id) {
    Swal.fire({
        icon: 'warning',
        title: T.deleteTitle,
        text: T.deleteConfirm,
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: T.deleteYes,
        cancelButtonText: T.deleteNo
    }).then(function(result) {
        if (!result.isConfirmed) return;
        $.ajax({
            url: '<?= $api_base ?>/document/delete_signature.php',
            type: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#signaturesTable').DataTable().ajax.reload();
                    swalToast('success', T.deletedOk);
                } else {
                    swalToast('error', res.message || T.deleteFail);
                }
            },
            error: function() { swalToast('error', T.deleteFail); }
        });
    });
}

/* ================================================================
   Signature Picker (shared between Apply modal & Sign wizard)
================================================================ */
function loadSignaturePickerInto(containerSelector, onSelect) {
    const $c = $(containerSelector);
    $c.html('<div class="col-12 text-center text-muted py-3">'
        + '<div class="spinner-border spinner-border-sm me-1" role="status"></div>'
        + (_sw ? 'Inapakia saini...' : 'Loading signatures...') + '</div>');

    $.getJSON('<?= $api_base ?>/document/get_user_signatures_list.php', function(sigs) {
        if (!sigs || !sigs.length) {
            $c.html('<div class="col-12 text-center p-4">'
                + '<p class="text-muted">' + T.noSigs + '</p>'
                + '<button class="btn btn-sm btn-outline-primary" '
                + 'onclick="bootstrap.Modal.getInstance(document.querySelector(\'.modal.show\'))?.hide();'
                + 'openDrawSignatureModal()">'
                + '<i class="bi bi-pencil me-1"></i>' + T.drawOne + '</button></div>');
            return;
        }
        let html = '';
        sigs.forEach(function(sig) {
            const img = sig.thumbnail_path || sig.file_path;
            html += '<div class="col-6 col-md-4 col-lg-3 mb-2">'
                + '<div class="card sig-card" onclick="pickSig(this,' + sig.id + ',\'' + containerSelector + '\')">'
                + '<div class="card-body text-center p-2">'
                + (img ? '<img src="' + escHtml(img) + '" style="max-height:70px;max-width:100%;object-fit:contain">'
                       : '<div class="py-2"><i class="bi bi-' + sigTypeIcon(sig.signature_type) + ' fs-1 text-muted"></i></div>')
                + '<div class="mt-1 small text-uppercase fw-semibold">' + escHtml(sig.signature_type) + '</div>'
                + '</div></div></div>';
        });
        $c.html(html);
        // Store callback reference on the container
        $c.data('onselect', onSelect);
    }).fail(function() {
        $c.html('<div class="col-12 text-center text-danger p-3">'
            + (_sw ? 'Imeshindwa kupakia saini.' : 'Failed to load signatures.') + '</div>');
    });
}

function pickSig(el, id, containerSelector) {
    $(containerSelector + ' .sig-card').removeClass('selected');
    $(el).addClass('selected');
    const cb = $(containerSelector).data('onselect');
    if (cb) cb(id);
}

/* ================================================================
   Helpers
================================================================ */
function sigTypeIcon(type) {
    const m = { uploaded:'upload', drawn:'pencil', typed:'fonts' };
    return m[type] || 'vector-pen';
}
function escHtml(s) {
    return s ? $('<div>').text(String(s)).html() : '';
}
function fmtSize(bytes) {
    if (!bytes) return '—';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)    return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}
function swalToast(icon, msg) {
    Swal.fire({
        icon: icon,
        title: msg,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true
    });
}
</script>

<?php
include 'footer.php';
ob_end_flush();
?>
