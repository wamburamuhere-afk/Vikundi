<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
ob_start();
require_once __DIR__ . '/../../../roots.php';
global $pdo;

requireViewPermission('documents');

$sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Compute base URL paths — normalise slashes first so str_ireplace matches on Windows
// (ROOT_DIR uses backslashes; DOCUMENT_ROOT uses forward slashes)
$doc_root  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$proj_root = str_replace('\\', '/', ROOT_DIR);
$base_path = trim(str_ireplace($doc_root, '', $proj_root), '/');
$api_base  = (!empty($base_path) ? '/' . $base_path : '') . '/api';
$ajax_base = (!empty($base_path) ? '/' . $base_path : '') . '/ajax';

// Categories for quick upload
$categories = $pdo->query("SELECT id, category_name FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../../header.php';
?>

<div class="container-fluid mt-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="mb-1">
                        <i class="bi bi-file-earmark-check text-primary"></i>
                        <?= $sw ? 'Mchakato wa Kutia Saini Hati' : 'Document Signing Wizard' ?>
                    </h4>
                    <p class="text-muted mb-0 small">
                        <?= $sw
                            ? 'Chagua hati na uweke saini yako ya kidijitali kwa hatua rahisi'
                            : 'Choose a document and apply your digital signature in simple steps' ?>
                    </p>
                </div>
                <a href="<?= getUrl('e_signatures') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i>
                    <?= $sw ? 'Rudi Saini' : 'Back to Signatures' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Wizard Card -->
    <div class="card shadow-sm">

        <!-- Step Indicators -->
        <div class="card-header bg-white border-bottom pt-4 pb-0">
            <div class="wizard-steps d-flex justify-content-between mb-0 pb-0">
                <div class="wizard-step active" data-step="1">
                    <div class="step-icon"><i class="bi bi-folder2-open"></i></div>
                    <div class="step-text"><?= $sw ? 'Chagua Hati' : 'Choose Document' ?></div>
                </div>
                <div class="wizard-step" data-step="2">
                    <div class="step-icon"><i class="bi bi-vector-pen"></i></div>
                    <div class="step-text"><?= $sw ? 'Chagua Saini' : 'Select Signature' ?></div>
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-icon"><i class="bi bi-pencil-square"></i></div>
                    <div class="step-text"><?= $sw ? 'Weka & Tia Saini' : 'Position & Sign' ?></div>
                </div>
                <div class="wizard-step" data-step="4">
                    <div class="step-icon"><i class="bi bi-check-circle"></i></div>
                    <div class="step-text"><?= $sw ? 'Maliza' : 'Finish' ?></div>
                </div>
            </div>
        </div>

        <div class="card-body">

            <!-- ======================== STEP 1: Choose Document ======================== -->
            <div class="step-content" id="step-1">
                <h5 class="mb-3">
                    <i class="bi bi-folder2-open text-primary me-2"></i>
                    <?= $sw ? 'Hatua ya 1: Chagua Hati' : 'Step 1: Choose Document' ?>
                </h5>

                <ul class="nav nav-pills mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-library" type="button">
                            <i class="bi bi-collection"></i>
                            <?= $sw ? 'Kutoka Maktaba' : 'From Library' ?>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-upload" type="button">
                            <i class="bi bi-cloud-upload"></i>
                            <?= $sw ? 'Pakia Mpya' : 'Upload New' ?>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Library Pane -->
                    <div class="tab-pane fade show active" id="pane-library">
                        <div class="table-responsive">
                            <table id="wizardDocsTable" class="table table-hover align-middle w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40"></th>
                                        <th><?= $sw ? 'Jina la Hati' : 'Document Name' ?></th>
                                        <th><?= $sw ? 'Aina' : 'Type' ?></th>
                                        <th><?= $sw ? 'Ukubwa' : 'Size' ?></th>
                                        <th><?= $sw ? 'Tarehe' : 'Uploaded' ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-2">
                            <i class="bi bi-info-circle"></i>
                            <?= $sw ? 'Bonyeza mstari wa hati kuchagua.' : 'Click a document row to select it.' ?>
                        </p>
                    </div>

                    <!-- Upload Pane -->
                    <div class="tab-pane fade" id="pane-upload">
                        <form id="wizardUploadForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <?= $sw ? 'Jina la Hati' : 'Document Name' ?> <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" name="document_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><?= $sw ? 'Kategoria' : 'Category' ?></label>
                                    <select class="form-select" name="category_id">
                                        <option value=""><?= $sw ? '-- Chagua Kategoria --' : '-- Select Category --' ?></option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>">
                                                <?= htmlspecialchars($cat['category_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">
                                        <?= $sw ? 'Faili' : 'File' ?> <span class="text-danger">*</span>
                                    </label>
                                    <input type="file" class="form-control" name="document_file" required
                                           accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg">
                                    <div class="form-text">
                                        <?= $sw
                                            ? 'Aina zinazoruhusiwa: PDF, Word, Excel, PNG, JPG. Ukubwa mkubwa: 50MB.'
                                            : 'Allowed types: PDF, Word, Excel, PNG, JPG. Max size: 50MB.' ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary" id="btnWizardUpload">
                                        <i class="bi bi-cloud-upload"></i>
                                        <?= $sw ? 'Pakia na Chagua' : 'Upload and Select' ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Selected document badge (hidden until selection) -->
                <div class="alert alert-success py-2 mt-3 d-none" id="step1-selected-badge">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <?= $sw ? 'Umechagua:' : 'Selected:' ?>
                    <strong id="step1-selected-name"></strong>
                </div>
            </div>

            <!-- ======================== STEP 2: Select Signature ======================== -->
            <div class="step-content d-none" id="step-2">
                <h5 class="mb-3">
                    <i class="bi bi-vector-pen text-primary me-2"></i>
                    <?= $sw ? 'Hatua ya 2: Chagua Saini' : 'Step 2: Select Signature' ?>
                </h5>
                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-file-earmark-text me-1"></i>
                    <?= $sw ? 'Hati iliyochaguliwa:' : 'Document selected:' ?>
                    <strong id="step2-doc-name"></strong>
                </div>
                <div class="row g-3" id="signaturePickerGrid">
                    <div class="col-12 text-center py-5">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>

            <!-- ======================== STEP 3: Position & Sign ======================== -->
            <div class="step-content d-none" id="step-3">
                <h5 class="mb-3">
                    <i class="bi bi-pencil-square text-primary me-2"></i>
                    <?= $sw ? 'Hatua ya 3: Weka na Tia Saini' : 'Step 3: Position & Sign' ?>
                </h5>

                <div class="row g-4">
                    <!-- Signature Preview -->
                    <div class="col-lg-5">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body text-center d-flex flex-column justify-content-center">
                                <p class="text-muted small mb-2">
                                    <?= $sw ? 'Hakiki ya Saini Yako' : 'Your Signature Preview' ?>
                                </p>
                                <div class="sig-preview-box rounded border bg-white p-3 mx-auto"
                                     style="max-width:260px; min-height:100px; display:flex; align-items:center; justify-content:center;">
                                    <img id="step3-sig-preview" src="" alt="Signature"
                                         style="max-width:100%; max-height:90px; object-fit:contain;">
                                </div>
                                <p class="text-muted small mt-2 mb-0" id="step3-sig-type-label"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Positioning Controls -->
                    <div class="col-lg-7">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-grid-3x3-gap me-1"></i>
                                <?= $sw ? 'Mahali pa Saini' : 'Signature Position' ?>
                            </label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary btn-sm preset-btn active"
                                        data-pos="bottom_left">
                                    <i class="bi bi-align-bottom-left"></i>
                                    <?= $sw ? 'Chini Kushoto' : 'Bottom Left' ?>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm preset-btn"
                                        data-pos="bottom_center">
                                    <i class="bi bi-align-bottom"></i>
                                    <?= $sw ? 'Chini Katikati' : 'Bottom Center' ?>
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm preset-btn"
                                        data-pos="bottom_right">
                                    <i class="bi bi-align-bottom-right"></i>
                                    <?= $sw ? 'Chini Kulia' : 'Bottom Right' ?>
                                </button>
                            </div>
                            <input type="hidden" id="selectedPosition" value="bottom_left">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <?= $sw ? 'Ukubwa wa Saini' : 'Signature Size' ?>:
                                <span id="scale-label">100%</span>
                            </label>
                            <input type="range" class="form-range" id="sigScaleRange" min="50" max="200" value="100">
                            <div class="d-flex justify-content-between small text-muted">
                                <span>50%</span><span>100%</span><span>200%</span>
                            </div>
                        </div>

                        <div class="alert alert-warning small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            <?= $sw
                                ? 'Saini itawekwa kwenye nafasi uliyochagua kwenye hati.'
                                : 'The signature will be placed at the selected position on the document.' ?>
                        </div>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="legalAgreementCheck">
                            <label class="form-check-label" for="legalAgreementCheck">
                                <strong>
                                <?= $sw
                                    ? 'Nakubali kwamba hii ni saini yangu ya kisheria inayofunga.'
                                    : 'I confirm this is my legally binding signature.' ?>
                                </strong>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ======================== STEP 4: Finish ======================== -->
            <div class="step-content d-none" id="step-4">
                <!-- Spinner while applying -->
                <div id="step4-progress" class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;"></div>
                    <h5><?= $sw ? 'Inatia saini...' : 'Applying Signature...' ?></h5>
                    <p class="text-muted">
                        <?= $sw
                            ? 'Tafadhali subiri hati inasainiwa.'
                            : 'Please wait while the document is being signed.' ?>
                    </p>
                </div>

                <!-- Success state -->
                <div id="step4-success" class="text-center py-5 d-none">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:4rem;"></i>
                    <h4 class="mt-3">
                        <?= $sw ? 'Hati Imesainiwa!' : 'Document Signed!' ?>
                    </h4>
                    <p class="text-muted">
                        <?= $sw
                            ? 'Saini yako imewekwa kwenye hati na imeandikwa katika historia ya saini.'
                            : 'Your signature has been applied and recorded in signature history.' ?>
                    </p>
                    <div class="mt-4 d-flex justify-content-center gap-3 flex-wrap">
                        <a href="<?= getUrl('e_signatures') ?>" class="btn btn-success btn-lg px-4">
                            <i class="bi bi-house"></i>
                            <?= $sw ? 'Nenda Saini' : 'Go to Signatures' ?>
                        </a>
                        <a href="<?= getUrl('document_library') ?>" class="btn btn-outline-primary btn-lg px-4">
                            <i class="bi bi-folder2-open"></i>
                            <?= $sw ? 'Maktaba ya Hati' : 'Document Library' ?>
                        </a>
                    </div>
                </div>

                <!-- Error state -->
                <div id="step4-error" class="text-center py-5 d-none">
                    <i class="bi bi-x-circle-fill text-danger" style="font-size:4rem;"></i>
                    <h4 class="mt-3"><?= $sw ? 'Imeshindwa!' : 'Signing Failed' ?></h4>
                    <p class="text-muted" id="step4-error-msg"></p>
                    <button type="button" class="btn btn-outline-danger mt-2" onclick="changeStep(-1)">
                        <i class="bi bi-arrow-left"></i>
                        <?= $sw ? 'Rudi Nyuma' : 'Go Back' ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer Nav Buttons -->
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
            <button class="btn btn-outline-secondary" id="btnBack" onclick="changeStep(-1)" disabled>
                <i class="bi bi-arrow-left"></i>
                <?= $sw ? 'Nyuma' : 'Previous' ?>
            </button>

            <span class="text-muted small">
                <?= $sw ? 'Hatua' : 'Step' ?> <span id="currentStepNum">1</span> / 4
            </span>

            <div>
                <button class="btn btn-primary" id="btnNext" onclick="changeStep(1)" disabled>
                    <?= $sw ? 'Hatua Inayofuata' : 'Next Step' ?>
                    <i class="bi bi-arrow-right"></i>
                </button>
                <button class="btn btn-success d-none" id="btnFinalSign" onclick="processFinalSign()">
                    <i class="bi bi-pen-fill"></i>
                    <?= $sw ? 'Tia Saini' : 'Apply Signature' ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Wizard Step Indicators ─────────────────────────────────────────── */
.wizard-steps {
    position: relative;
    padding-bottom: 24px;
}
.wizard-steps::before {
    content: '';
    position: absolute;
    top: 24px;
    left: 12%;
    right: 12%;
    height: 3px;
    background: #e9ecef;
    z-index: 0;
}
.wizard-step {
    position: relative;
    z-index: 1;
    text-align: center;
    flex: 1;
}
.step-icon {
    width: 48px;
    height: 48px;
    background: #fff;
    border: 3px solid #dee2e6;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #adb5bd;
    transition: all 0.3s ease;
}
.step-text {
    font-size: 0.8rem;
    font-weight: 600;
    color: #adb5bd;
    letter-spacing: 0.3px;
}
.wizard-step.active .step-icon {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
    transform: scale(1.1);
    box-shadow: 0 0 18px rgba(13,110,253,.3);
}
.wizard-step.active  .step-text { color: #0d6efd; }
.wizard-step.completed .step-icon {
    background: #198754;
    border-color: #198754;
    color: #fff;
}
.wizard-step.completed .step-text { color: #198754; }

/* ── Signature Picker Cards ─────────────────────────────────────────── */
.sig-pick-card {
    border: 2px solid #dee2e6;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.25s ease;
}
.sig-pick-card:hover {
    border-color: #0d6efd;
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
}
.sig-pick-card.selected {
    border-color: #0d6efd;
    background: #f0f7ff;
    box-shadow: 0 4px 12px rgba(13,110,253,.12);
}

/* ── Position preset buttons ────────────────────────────────────────── */
.preset-btn.active {
    background-color: #0d6efd;
    color: #fff;
    border-color: #0d6efd;
}
</style>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   Wizard State
   ═══════════════════════════════════════════════════════════════════════ */
let currentStep     = 1;
let selectedDocId   = null;
let selectedDocName = '';
let selectedSigId   = null;
let selectedSigPath = '';
let selectedSigType = '';

/* ═══════════════════════════════════════════════════════════════════════
   Step Navigation
   ═══════════════════════════════════════════════════════════════════════ */
function changeStep(dir) {
    const next = currentStep + dir;
    if (next < 1 || next > 4) return;

    if (dir > 0) {
        if (currentStep === 1 && !selectedDocId) {
            swalToast('warning', '<?= $sw ? 'Tafadhali chagua hati kwanza.' : 'Please select a document first.' ?>');
            return;
        }
        if (currentStep === 2 && !selectedSigId) {
            swalToast('warning', '<?= $sw ? 'Tafadhali chagua saini kwanza.' : 'Please select a signature first.' ?>');
            return;
        }
    }

    $(`#step-${currentStep}`).addClass('d-none');
    $(`#step-${next}`).removeClass('d-none');

    if (dir > 0) {
        $(`.wizard-step[data-step="${currentStep}"]`).removeClass('active').addClass('completed');
    } else {
        $(`.wizard-step[data-step="${currentStep}"]`).removeClass('active completed');
    }
    $(`.wizard-step[data-step="${next}"]`).addClass('active').removeClass('completed');

    currentStep = next;
    $('#currentStepNum').text(currentStep);

    if (currentStep === 2) loadSignaturePicker();
    if (currentStep === 3) populateStep3();
    if (currentStep === 4) applySignatureNow();

    updateButtons();
}

function updateButtons() {
    $('#btnBack').prop('disabled', currentStep === 1 || currentStep === 4);

    // Show/hide Next vs Apply
    if (currentStep === 3) {
        $('#btnNext').addClass('d-none');
        $('#btnFinalSign').removeClass('d-none');
        $('#btnFinalSign').prop('disabled', !$('#legalAgreementCheck').is(':checked'));
    } else if (currentStep === 4) {
        $('#btnNext').addClass('d-none');
        $('#btnFinalSign').addClass('d-none');
        $('#btnBack').addClass('d-none');
    } else {
        $('#btnNext').removeClass('d-none');
        $('#btnFinalSign').addClass('d-none');
        const ready = (currentStep === 1 && selectedDocId) || (currentStep === 2 && selectedSigId);
        $('#btnNext').prop('disabled', !ready);
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   Step 1 — Document Library DataTable
   ═══════════════════════════════════════════════════════════════════════ */
$(document).ready(function () {
    $('#wizardDocsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: { url: '<?= $api_base ?>/get_documents.php', type: 'GET' },
        columns: [
            {
                data: 'id', orderable: false,
                render: (d, t, row) =>
                    `<input type="radio" class="form-check-input" name="wizardDoc"
                            value="${d}"
                            data-name="${escHtml(row.document_name)}">`
            },
            { data: 'document_name', render: d => `<strong>${escHtml(d)}</strong>` },
            {
                data: 'file_type',
                render: d => d ? `<span class="badge bg-secondary text-uppercase">${escHtml(d)}</span>` : '—'
            },
            { data: 'file_size', render: d => fmtSize(d) },
            { data: 'uploaded_at', render: d => d ? new Date(d).toLocaleDateString() : '—' }
        ],
        order: [[4, 'desc']],
        pageLength: 5,
        lengthMenu: [5, 10, 25],
        responsive: true,
        language: {
            search: '<?= $sw ? 'Tafuta:' : 'Search:' ?>',
            emptyTable: '<?= $sw ? 'Hakuna hati zilizopo.' : 'No documents available.' ?>',
            processing: '<?= $sw ? 'Inapakia...' : 'Loading...' ?>'
        }
    });

    $('#wizardDocsTable').on('change', 'input[name="wizardDoc"]', function () {
        selectedDocId   = $(this).val();
        selectedDocName = $(this).data('name');
        $('#step2-doc-name').text(selectedDocName);
        $('#step1-selected-name').text(selectedDocName);
        $('#step1-selected-badge').removeClass('d-none');
        updateButtons();
    });

    // Quick Upload
    $('#wizardUploadForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $('#btnWizardUpload');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= $sw ? 'Inapakia...' : 'Uploading...' ?>');

        $.ajax({
            url: '<?= $ajax_base ?>/quick_upload_document.php',
            type: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            dataType: 'json',
            skipModalClose: true,
            success: function (res) {
                if (res.success) {
                    selectedDocId   = res.document_id;
                    selectedDocName = res.document_name;
                    $('#step2-doc-name').text(selectedDocName);
                    $('#step1-selected-name').text(selectedDocName);
                    $('#step1-selected-badge').removeClass('d-none');
                    swalToast('success', '<?= $sw ? 'Faili limepakiwa.' : 'File uploaded successfully.' ?>');
                    setTimeout(() => changeStep(1), 900);
                } else {
                    swalToast('error', res.message || '<?= $sw ? 'Imeshindwa kupakia.' : 'Upload failed.' ?>');
                }
            },
            error: () => swalToast('error', '<?= $sw ? 'Hitilafu ya seva.' : 'Server error during upload.' ?>'),
            complete: () => btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> <?= $sw ? 'Pakia na Chagua' : 'Upload and Select' ?>')
        });
    });

    // Legal checkbox enables Apply button
    $('#legalAgreementCheck').on('change', function () {
        $('#btnFinalSign').prop('disabled', !this.checked);
    });

    // Position presets
    $(document).on('click', '.preset-btn', function () {
        $('.preset-btn').removeClass('active');
        $(this).addClass('active');
        $('#selectedPosition').val($(this).data('pos'));
    });

    // Scale label
    $('#sigScaleRange').on('input', function () {
        $('#scale-label').text($(this).val() + '%');
    });
});

/* ═══════════════════════════════════════════════════════════════════════
   Step 2 — Signature Picker
   ═══════════════════════════════════════════════════════════════════════ */
function loadSignaturePicker() {
    const grid = $('#signaturePickerGrid');
    grid.html('<div class="col-12 text-center py-5"><div class="spinner-border text-primary"></div></div>');

    $.getJSON('<?= $api_base ?>/document/get_user_signatures_list.php', function (res) {
        const sigs = res.data || res;
        if (!sigs || sigs.length === 0) {
            grid.html(`
                <div class="col-12 text-center py-5 text-muted">
                    <i class="bi bi-exclamation-circle fs-2 mb-2 d-block"></i>
                    <?= $sw ? 'Huna saini bado. ' : 'You have no signatures yet. ' ?>
                    <a href="<?= getUrl('e_signatures') ?>"><?= $sw ? 'Unda saini' : 'Create one' ?></a>
                </div>`);
            return;
        }
        let html = '';
        sigs.forEach(sig => {
            const imgSrc   = escHtml(sig.thumbnail_path || sig.file_path || '');
            const typeLabel = (sig.signature_type || '').toUpperCase();
            html += `
                <div class="col-md-4 col-sm-6">
                    <div class="sig-pick-card card h-100 ${selectedSigId == sig.id ? 'selected' : ''}"
                         onclick="pickSignature(${sig.id}, '${imgSrc}', '${typeLabel}', this)">
                        <div class="card-body text-center p-3">
                            <img src="${imgSrc}" alt="signature"
                                 class="img-fluid mb-2" style="max-height:80px; object-fit:contain;">
                            <div class="small fw-semibold text-uppercase text-secondary">${typeLabel}</div>
                        </div>
                    </div>
                </div>`;
        });
        grid.html(html);
    }).fail(() => {
        grid.html('<div class="col-12 text-center py-4 text-danger"><?= $sw ? 'Imeshindwa kupakia saini.' : 'Failed to load signatures.' ?></div>');
    });
}

function pickSignature(id, path, type, el) {
    selectedSigId   = id;
    selectedSigPath = path;
    selectedSigType = type;
    $('.sig-pick-card').removeClass('selected');
    $(el).addClass('selected');
    updateButtons();
}

/* ═══════════════════════════════════════════════════════════════════════
   Step 3 — Populate preview & reset controls
   ═══════════════════════════════════════════════════════════════════════ */
function populateStep3() {
    $('#step3-sig-preview').attr('src', selectedSigPath);
    $('#step3-sig-type-label').text(selectedSigType);
    $('#legalAgreementCheck').prop('checked', false);
    $('#btnFinalSign').prop('disabled', true);
    $('.preset-btn').removeClass('active');
    $('.preset-btn[data-pos="bottom_left"]').addClass('active');
    $('#selectedPosition').val('bottom_left');
    $('#sigScaleRange').val(100);
    $('#scale-label').text('100%');
}

/* ═══════════════════════════════════════════════════════════════════════
   Step 4 — Apply Signature
   ═══════════════════════════════════════════════════════════════════════ */
function applySignatureNow() {
    $('#step4-progress').removeClass('d-none');
    $('#step4-success').addClass('d-none');
    $('#step4-error').addClass('d-none');

    $.ajax({
        url: '<?= $api_base ?>/document/apply_signature.php',
        type: 'POST',
        data: {
            document_id:        selectedDocId,
            signature_id:       selectedSigId,
            signature_position: $('#selectedPosition').val()
        },
        dataType: 'json',
        skipModalClose: true,
        success: function (res) {
            if (res.success) {
                $('#step4-progress').addClass('d-none');
                $('#step4-success').removeClass('d-none');
            } else {
                $('#step4-progress').addClass('d-none');
                $('#step4-error-msg').text(res.message || '<?= $sw ? 'Hitilafu isiyojulikana.' : 'Unknown error.' ?>');
                $('#step4-error').removeClass('d-none');
            }
        },
        error: function () {
            $('#step4-progress').addClass('d-none');
            $('#step4-error-msg').text('<?= $sw ? 'Hitilafu ya seva.' : 'Server error.' ?>');
            $('#step4-error').removeClass('d-none');
        }
    });
}

// Called from "Apply Signature" button on step 3
function processFinalSign() {
    if (!$('#legalAgreementCheck').is(':checked')) {
        swalToast('warning', '<?= $sw ? 'Tafadhali kubali masharti ya kisheria.' : 'Please accept the legal terms.' ?>');
        return;
    }
    changeStep(1); // advances to step 4 which calls applySignatureNow()
}

/* ═══════════════════════════════════════════════════════════════════════
   Helpers
   ═══════════════════════════════════════════════════════════════════════ */
function swalToast(icon, message) {
    Swal.fire({
        icon: icon, title: message,
        toast: true, position: 'top-end',
        timer: 3000, showConfirmButton: false, timerProgressBar: true
    });
}
function fmtSize(bytes) {
    if (!bytes) return '—';
    const k = 1024, s = ['B','KB','MB','GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + s[i];
}
function escHtml(str) {
    if (!str) return '';
    return $('<div>').text(String(str)).html();
}
</script>

<?php
include __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
