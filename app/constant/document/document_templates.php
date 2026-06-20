<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('document_templates');
require_once HEADER_FILE;

$is_sw      = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$categories = $pdo->query("SELECT * FROM template_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Base URL for serving uploaded files directly
$doc_root  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$proj_root = str_replace('\\', '/', ROOT_DIR);
$base_path = trim(str_replace($doc_root, '', $proj_root), '/');
$site_base = !empty($base_path) ? '/' . $base_path : '';
?>

<?php PrintHeader::css(); ?>
<!-- PRINT HEADER (Visible only during print) -->
<div class="d-none d-print-block">
    <?php PrintHeader::render($pdo, $is_sw ? 'MAKTABA YA VIOLEZO VYA HATI' : 'DOCUMENT TEMPLATES LIBRARY'); ?>
</div>

<div class="container-fluid mt-4">

    <!-- Page Title -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h4 class="fw-bold text-primary mb-0">
                        <i class="bi bi-file-earmark-richtext me-2"></i>
                        <?= $is_sw ? 'Violezo vya Hati' : 'Document Templates' ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?= $is_sw
                            ? 'Unda na simamia violezo vya hati kwa kikundi chako'
                            : 'Create and manage standardized document templates for your group' ?>
                    </p>
                </div>
                <?php if (canCreate('document_templates')): ?>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm" onclick="openUploadModal()">
                        <i class="bi bi-cloud-upload me-1"></i>
                        <?= $is_sw ? 'Pakia Kiolezo' : 'Upload Template' ?>
                    </button>
                    <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" onclick="openCreateModal()">
                        <i class="bi bi-plus-circle me-1"></i>
                        <?= $is_sw ? 'Unda Kiolezo' : 'Create Template' ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-4 fw-bold" id="stat-total-templates">—</div>
                        <div class="small text-uppercase fw-bold"><?= $is_sw ? 'Violezo Vyote' : 'Total Templates' ?></div>
                    </div>
                    <i class="bi bi-file-earmark-text" style="font-size:2rem;opacity:.4;"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-4 fw-bold" id="stat-active-templates">—</div>
                        <div class="small text-uppercase fw-bold"><?= $is_sw ? 'Violezo Vya Sasa' : 'Active Templates' ?></div>
                    </div>
                    <i class="bi bi-check-circle-fill" style="font-size:2rem;opacity:.4;"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-4 fw-bold" id="stat-total-usage">—</div>
                        <div class="small text-uppercase fw-bold"><?= $is_sw ? 'Matumizi Yote' : 'Total Downloads' ?></div>
                    </div>
                    <i class="bi bi-download" style="font-size:2rem;opacity:.4;"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-4 fw-bold" id="stat-categories">—</div>
                        <div class="small text-uppercase fw-bold"><?= $is_sw ? 'Makundi' : 'Categories' ?></div>
                    </div>
                    <i class="bi bi-tags" style="font-size:2rem;opacity:.4;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 rounded-4">
        <div class="card-header bg-white border-bottom py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2 text-primary"></i><?= $is_sw ? 'Vichujio na Utafutaji' : 'Filters & Search' ?></h6>
        </div>
        <div class="card-body py-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?= $is_sw ? 'Kundi' : 'Category' ?></label>
                    <select class="form-select form-select-sm" id="categoryFilter">
                        <option value=""><?= $is_sw ? 'Makundi Yote' : 'All Categories' ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?= $is_sw ? 'Aina ya Kiolezo' : 'Template Type' ?></label>
                    <select class="form-select form-select-sm" id="typeFilter">
                        <option value=""><?= $is_sw ? 'Aina Zote' : 'All Types' ?></option>
                        <option value="uploaded"><?= $is_sw ? 'Faili Iliyopakiwa' : 'Uploaded File' ?></option>
                        <option value="html"><?= $is_sw ? 'Mjenzi wa HTML' : 'HTML Builder' ?></option>
                        <option value="built_in"><?= $is_sw ? 'Mfumo wa Asili' : 'System Default' ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold"><?= $is_sw ? 'Hali' : 'Status' ?></label>
                    <select class="form-select form-select-sm" id="statusFilter">
                        <option value=""><?= $is_sw ? 'Hali Zote' : 'All Statuses' ?></option>
                        <option value="1"><?= $is_sw ? 'Inafanya Kazi' : 'Active' ?></option>
                        <option value="0"><?= $is_sw ? 'Haifanyi Kazi' : 'Inactive' ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls + Table -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <h6 class="mb-0 fw-bold">
                <?= $is_sw ? 'Orodha ya Violezo' : 'Templates List' ?>
                <span class="badge bg-light text-dark border ms-2" id="stat-count-badge">—</span>
            </h6>
            <div class="input-group input-group-sm shadow-sm" style="width:260px;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="tplSearch" class="form-control border-0"
                       placeholder="<?= $is_sw ? 'Tafuta kiolezo...' : 'Search templates...' ?>">
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Desktop Table -->
            <div class="table-responsive d-none d-md-block">
                <table id="templatesTable" class="table table-hover align-middle mb-0 small" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-3" style="width:50px;">#</th>
                            <th><?= $is_sw ? 'Jina la Kiolezo' : 'Template Name' ?></th>
                            <th><?= $is_sw ? 'Kundi' : 'Category' ?></th>
                            <th><?= $is_sw ? 'Aina' : 'Type' ?></th>
                            <th class="text-center"><?= $is_sw ? 'Matumizi' : 'Usage' ?></th>
                            <th><?= $is_sw ? 'Iliundwa na' : 'Created By' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th class="text-end pe-3"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <!-- Mobile Card View -->
            <div class="d-md-none" id="mobileCards">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>
        <div class="card-footer bg-white py-2 d-md-none" id="mobilePager"></div>
    </div>
</div>

<!-- Template Modal (Upload / Create / Edit) -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="templateModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="templateForm" enctype="multipart/form-data">
                <input type="hidden" name="template_id" id="form_template_id" value="">
                <div class="modal-body pt-2">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">
                            <?= $is_sw ? 'Jina la Kiolezo' : 'Template Name' ?> <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" name="template_name" id="form_template_name" required
                               placeholder="<?= $is_sw ? 'Weka jina la kiolezo...' : 'Enter template name...' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">
                            <?= $is_sw ? 'Kundi' : 'Category' ?> <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" name="category_id" id="form_category_id" required>
                            <option value=""><?= $is_sw ? 'Chagua kundi...' : 'Select category...' ?></option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="type_wrap">
                        <label class="form-label small fw-bold"><?= $is_sw ? 'Aina ya Kiolezo' : 'Template Type' ?></label>
                        <select class="form-select" name="template_type" id="form_template_type">
                            <option value="uploaded"><?= $is_sw ? 'Faili Iliyopakiwa' : 'Uploaded File' ?></option>
                            <option value="html"><?= $is_sw ? 'Mjenzi wa HTML' : 'HTML Builder' ?></option>
                        </select>
                    </div>
                    <div class="mb-3" id="file_input_wrap">
                        <label class="form-label small fw-bold"><?= $is_sw ? 'Chagua Faili' : 'File Selection' ?></label>
                        <input type="file" class="form-control" name="template_file" id="form_template_file"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.odt,.ods">
                        <div class="form-text text-muted"><?= $is_sw
                            ? 'Inaruhusiwa: PDF, DOC, DOCX, XLS, XLSX, PPT, TXT — (ukibadilisha, chagua faili mpya)'
                            : 'Allowed: PDF, DOC, DOCX, XLS, XLSX, PPT, TXT — (leave blank to keep existing file)' ?>
                        </div>
                    </div>
                    <div class="mb-3" id="html_content_wrap">
                        <label class="form-label small fw-bold" id="desc_label">
                            <?= $is_sw ? 'Maelezo' : 'Description' ?>
                        </label>
                        <textarea class="form-control" name="description" id="form_description" rows="4"
                                  placeholder="<?= $is_sw ? 'Maelezo ya kiolezo (si lazima)...' : 'Template description or notes (optional)...' ?>"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" value="1" checked>
                        <label class="form-check-label small" for="form_is_active">
                            <?= $is_sw ? 'Kiolezo kinapatikana kwa matumizi' : 'Template available for use' ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">
                        <?= $is_sw ? 'Ghairi' : 'Cancel' ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm" id="btnSaveTemplate">
                        <?= $is_sw ? 'Hifadhi' : 'Save Template' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.stat-card {
    background-color: #d1e7dd !important;
    color: #000 !important;
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,.05);
    transition: transform .15s ease, box-shadow .15s ease;
}
.stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 15px rgba(0,0,0,.1); }
.stat-card .fs-4, .stat-card .small, .stat-card i { color: #000 !important; }
.vk-mobile-card { background:#fff; border:1px solid #e9ecef; border-radius:12px; padding:14px; margin-bottom:10px; }
.vk-mobile-card .meta { font-size:.75rem; color:#6c757d; }
</style>

<script>
const SITE_BASE  = '<?= $site_base ?>';
const IS_SW      = <?= $is_sw ? 'true' : 'false' ?>;
const CAN_EDIT   = <?= canEdit('document_templates')   ? 'true' : 'false' ?>;
const CAN_DELETE = <?= canDelete('document_templates') ? 'true' : 'false' ?>;

const API_TEMPLATES  = '<?= getUrl('api/get_document_templates') ?>';
const API_TPL_SINGLE = '<?= getUrl('api/get_document_template') ?>';
const API_TPL_SAVE   = '<?= getUrl('api/save_document_template') ?>';
const API_TPL_DELETE = '<?= getUrl('api/delete_document_template') ?>';
const URL_TEMPLATES  = '<?= getUrl('document-templates') ?>';

/* Row data keyed by id — avoids ANY quoting/escaping issue in onclick attrs */
const tplRows = {};

let tplTable;

$(document).ready(function() {
    tplTable = $('#templatesTable').DataTable({
        serverSide: true,
        processing: true,
        responsive: false,
        ajax: {
            url: API_TEMPLATES,
            data: d => {
                d.category_id = $('#categoryFilter').val();
                d.file_type   = $('#typeFilter').val();
                d.status      = $('#statusFilter').val();
            },
            dataSrc: json => {
                if (json.error) { Swal.fire('Error', json.error, 'error'); return []; }
                const s = json.stats || {};
                $('#stat-total-templates').text(s.totalTemplates  ?? 0);
                $('#stat-active-templates').text(s.activeTemplates ?? 0);
                $('#stat-total-usage').text(s.totalUsage       ?? 0);
                $('#stat-categories').text(s.categoriesCount  ?? 0);
                $('#stat-count-badge').text((json.recordsFiltered ?? 0) + ' <?= $is_sw ? 'violezo' : 'templates' ?>');
                renderMobileCards(json.data || []);
                return json.data || [];
            }
        },
        columns: [
            { data: null, render: (d,t,r,m) => m.row + m.settings._iDisplayStart + 1, className: 'ps-3 text-muted fw-bold' },
            { data: 'template_name', render: (d,t,r) =>
                `<div class="fw-bold">${esc(d)}</div>` +
                (r.description ? `<div class="small text-muted text-truncate" style="max-width:200px;">${esc(r.description)}</div>` : '')
            },
            { data: 'category_name', render: d =>
                d ? `<span class="badge bg-light text-dark border px-2">${esc(d)}</span>`
                  : `<span class="text-muted small">${IS_SW ? 'Hakuna Kundi' : 'Uncategorized'}</span>`
            },
            { data: 'file_type', render: d => {
                if (!d) return '<span class="badge bg-secondary-subtle text-secondary border px-2">—</span>';
                const t = d.toLowerCase();
                let c = 'secondary', icon = 'bi-file-earmark', label = d.toUpperCase();
                if (t === 'uploaded') { c = 'primary'; icon = 'bi-cloud-upload'; label = IS_SW ? 'Faili' : 'Uploaded'; }
                else if (t === 'html') { c = 'info'; icon = 'bi-code-slash'; label = 'HTML'; }
                else if (t === 'built_in') { c = 'dark'; icon = 'bi-gear'; label = IS_SW ? 'Mfumo' : 'Built-in'; }
                else if (t === 'pdf') { c = 'danger'; icon = 'bi-file-earmark-pdf'; label = 'PDF'; }
                else if (t === 'docx' || t === 'doc') { c = 'primary'; icon = 'bi-file-earmark-word'; label = d.toUpperCase(); }
                else if (t === 'xlsx' || t === 'xls') { c = 'success'; icon = 'bi-file-earmark-excel'; label = d.toUpperCase(); }
                return `<span class="badge bg-${c}-subtle text-${c} border border-${c}-subtle px-2"><i class="bi ${icon} me-1"></i>${label}</span>`;
            }},
            { data: 'usage_count', className: 'text-center fw-bold' },
            { data: 'created_by_name', render: d => d ? esc(d) : `<span class="text-muted">—</span>` },
            { data: 'created_at', render: d => d ? new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—' },
            { data: 'is_active', render: d => d==1
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2"><i class="bi bi-check-circle me-1"></i><?= $is_sw ? "Inafanya" : "Active" ?></span>'
                : '<span class="badge bg-secondary-subtle text-secondary border px-2"><i class="bi bi-x-circle me-1"></i><?= $is_sw ? "Haifanyi" : "Inactive" ?></span>'
            },
            { data: null, orderable: false, className: 'text-end pe-3', render: (d,t,r) => buildActions(r) }
        ],
        order: [[6, 'desc']],
        dom: '<"d-none"l>rt<"d-flex justify-content-between align-items-center px-3 py-2 border-top"ip>',
        language: {
            processing: '<div class="spinner-border spinner-border-sm text-primary"></div>',
            info: IS_SW ? '_START_ - _END_ ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_',
            paginate: { previous: IS_SW ? 'Nyuma' : 'Previous', next: IS_SW ? 'Mbele' : 'Next' }
        }
    });

    $('#tplSearch').on('keyup', function() { tplTable.search(this.value).draw(); });
    $('#categoryFilter, #typeFilter, #statusFilter').on('change', function() { tplTable.ajax.reload(); });
});

function buildActions(r) {
    tplRows[r.id] = r; // safe storage — no string escaping needed in onclick
    const previewHref   = r.file_path ? `${SITE_BASE}/${r.file_path}` : '#';
    const previewExtras = r.file_path ? 'target="_blank"' : 'onclick="return false;"';
    let html = `<div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><a class="dropdown-item" href="#" onclick="generateDoc(${r.id});return false;">
                <i class="bi bi-play-circle me-2 text-success"></i>${IS_SW ? 'Tengeneza Hati' : 'Generate Doc'}</a></li>
            <li><a class="dropdown-item" href="${previewHref}" ${previewExtras}>
                <i class="bi bi-eye me-2 text-primary"></i>${IS_SW ? 'Angalia / Tazama' : 'Preview / View'}</a></li>`;
    if (CAN_EDIT) {
        html += `<li><a class="dropdown-item" href="#" onclick="editTemplate(${r.id});return false;">
                     <i class="bi bi-pencil me-2 text-warning"></i>${IS_SW ? 'Hariri Maelezo' : 'Edit Details'}</a></li>`;
    }
    if (CAN_DELETE) {
        html += `<li><hr class="dropdown-divider"></li>
                 <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${r.id});return false;">
                     <i class="bi bi-trash me-2"></i>${IS_SW ? 'Futa' : 'Delete'}</a></li>`;
    }
    html += '</ul></div>';
    return html;
}

function generateDoc(id) {
    const row  = tplRows[id] || {};
    const name = row.template_name || '';
    Swal.fire({
        icon: 'question',
        title: IS_SW ? 'Tengeneza Hati' : 'Generate Document',
        html: `<p class="mb-1">${IS_SW ? 'Kiolezo kilichochaguliwa:' : 'Selected template:'} <strong>${esc(name)}</strong></p>
               <p class="small text-muted mb-0">${IS_SW
                   ? 'Bonyeza "Endelea" kutengeneza hati kutoka kiolezo hiki.'
                   : 'Click "Proceed" to generate a document from this template.'}</p>`,
        showCancelButton: true,
        confirmButtonText: IS_SW ? 'Endelea' : 'Proceed',
        cancelButtonText: IS_SW ? 'Ghairi' : 'Cancel',
        confirmButtonColor: '#198754'
    }).then(result => {
        if (result.isConfirmed) {
            window.location.href = `${URL_TEMPLATES}?action=generate&template_id=${id}`;
        }
    });
}

function renderMobileCards(data) {
    const $c = $('#mobileCards').empty();
    if (!data.length) {
        $c.html(`<div class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2"></i><p class="mt-2">${IS_SW ? 'Hakuna violezo' : 'No templates found'}</p></div>`);
        return;
    }
    data.forEach(r => {
        tplRows[r.id] = r; // safe storage for onclick lookups
        const typeBadge = r.file_type
            ? `<span class="badge bg-primary-subtle text-primary border px-2 text-uppercase">${esc(r.file_type)}</span>`
            : '<span class="badge bg-secondary-subtle text-secondary border px-2">—</span>';
        const statusBadge = r.is_active==1
            ? `<span class="badge bg-success-subtle text-success border">${IS_SW ? 'Inafanya' : 'Active'}</span>`
            : `<span class="badge bg-secondary-subtle text-secondary border">${IS_SW ? 'Haifanyi' : 'Inactive'}</span>`;
        const previewHref   = r.file_path ? `${SITE_BASE}/${r.file_path}` : '#';
        const previewExtras = r.file_path ? 'target="_blank"' : 'onclick="return false;"';
        $c.append(`<div class="vk-mobile-card mx-3 mt-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="fw-bold">${esc(r.template_name)}</div>
                ${statusBadge}
            </div>
            ${r.description ? `<div class="meta mb-2">${esc(r.description)}</div>` : ''}
            <div class="d-flex flex-wrap gap-2 mb-2">
                ${r.category_name ? `<span class="badge bg-light text-dark border">${esc(r.category_name)}</span>` : ''}
                ${typeBadge}
                <span class="badge bg-light text-dark border"><i class="bi bi-download me-1"></i>${r.usage_count || 0}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <small class="meta">${r.created_at ? new Date(r.created_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</small>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="#" onclick="generateDoc(${r.id});return false;">
                            <i class="bi bi-play-circle me-2 text-success"></i>${IS_SW ? 'Tengeneza Hati' : 'Generate Doc'}</a></li>
                        <li><a class="dropdown-item" href="${previewHref}" ${previewExtras}>
                            <i class="bi bi-eye me-2 text-primary"></i>${IS_SW ? 'Angalia / Tazama' : 'Preview / View'}</a></li>
                        ${CAN_EDIT ? `<li><a class="dropdown-item" href="#" onclick="editTemplate(${r.id});return false;">
                            <i class="bi bi-pencil me-2 text-warning"></i>${IS_SW ? 'Hariri Maelezo' : 'Edit Details'}</a></li>` : ''}
                        ${CAN_DELETE ? `<li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${r.id});return false;">
                            <i class="bi bi-trash me-2"></i>${IS_SW ? 'Futa' : 'Delete'}</a></li>` : ''}
                    </ul>
                </div>
            </div>
        </div>`);
    });
}

function openUploadModal() {
    resetModal();
    $('#templateModalTitle').html('<i class="bi bi-cloud-upload me-2"></i><?= $is_sw ? "Pakia Kiolezo" : "Upload Template File" ?>');
    $('#type_wrap').addClass('d-none');
    $('#form_template_type').val('uploaded');
    $('#file_input_wrap').removeClass('d-none');
    $('#html_content_wrap').addClass('d-none');
    $('#templateModal').modal('show');
}

function openCreateModal() {
    resetModal();
    $('#templateModalTitle').html('<i class="bi bi-plus-circle me-2"></i><?= $is_sw ? "Unda Kiolezo cha HTML" : "Create HTML Template" ?>');
    $('#type_wrap').addClass('d-none');
    $('#form_template_type').val('html');
    $('#file_input_wrap').addClass('d-none');
    $('#html_content_wrap').removeClass('d-none');
    $('#desc_label').text(IS_SW ? 'Maudhui ya HTML' : 'HTML / Text Content');
    $('#templateModal').modal('show');
}

function resetModal() {
    $('#templateForm')[0].reset();
    $('#form_template_id').val('');
    $('#type_wrap').removeClass('d-none');
    $('#file_input_wrap').removeClass('d-none');
    $('#html_content_wrap').removeClass('d-none');
    $('#desc_label').text(IS_SW ? 'Maelezo' : 'Description');
}

$('#form_template_type').on('change', function() {
    if ($(this).val() === 'html') {
        $('#file_input_wrap').addClass('d-none');
        $('#desc_label').text(IS_SW ? 'Maudhui ya HTML' : 'HTML / Text Content');
    } else {
        $('#file_input_wrap').removeClass('d-none');
        $('#desc_label').text(IS_SW ? 'Maelezo' : 'Description');
    }
});

function editTemplate(id) {
    $.getJSON(API_TPL_SINGLE, { id }, function(res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        const t = res.data;
        resetModal();
        $('#templateModalTitle').html('<i class="bi bi-pencil me-2"></i><?= $is_sw ? "Hariri Kiolezo" : "Edit Template" ?>');
        $('#form_template_id').val(t.id);
        $('#form_template_name').val(t.template_name);
        $('#form_category_id').val(t.category_id);
        $('#form_template_type').val(t.file_type || 'uploaded').trigger('change');
        $('#form_description').val(t.description);
        $('#form_is_active').prop('checked', t.is_active == 1);
        $('#templateModal').modal('show');
    }).fail(() => Swal.fire('Error', IS_SW ? 'Imeshindwa kupata data' : 'Failed to load template data', 'error'));
}

$('#templateForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnSaveTemplate').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= $is_sw ? "Inahifadhi..." : "Saving..." ?>');
    $.ajax({
        url: API_TPL_SAVE, type: 'POST',
        data: new FormData(this), processData: false, contentType: false,
        success(res) {
            if (res.success) {
                $('#templateModal').modal('hide');
                tplTable.ajax.reload();
                Swal.fire({ icon:'success', title: IS_SW ? 'Imehifadhiwa!' : 'Saved!',
                    text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error() { Swal.fire('Error', IS_SW ? 'Tatizo la mtandao' : 'Network error', 'error'); },
        complete() { $('#btnSaveTemplate').prop('disabled', false).html('<?= $is_sw ? "Hifadhi" : "Save Template" ?>'); }
    });
});

function confirmDelete(id) {
    const row  = tplRows[id] || {};
    const name = row.template_name || ('ID ' + id);
    Swal.fire({
        title: IS_SW ? 'Una uhakika?' : 'Are you sure?',
        html: (IS_SW ? 'Utafuta kiolezo: ' : 'You are deleting: ') + `<strong>${esc(name)}</strong>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: IS_SW ? 'Ndiyo, futa!' : 'Yes, delete!',
        cancelButtonText: IS_SW ? 'Ghairi' : 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            $.post(API_TPL_DELETE, { id }, function(res) {
                if (res.success) {
                    delete tplRows[id];
                    tplTable.ajax.reload();
                    Swal.fire({ icon:'success', title: IS_SW ? 'Imefutwa!' : 'Deleted!',
                        text: res.message, timer: 1800, showConfirmButton: false });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json').fail(() => Swal.fire('Error', IS_SW ? 'Tatizo la mtandao' : 'Network error', 'error'));
        }
    });
}

function esc(str) { return str ? $('<div>').text(str).html() : ''; }
</script>

<?php
require_once FOOTER_FILE;
$content = ob_get_clean();
echo $content;
