<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('message_center');

require_once __DIR__ . '/../../../includes/email_helper.php';

$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int)$_SESSION['user_id'];

// Self-healing schema so the page works on a fresh install.
try { email_ensure_templates_table($pdo); } catch (Throwable $e) { /* surfaced via API */ }

$types = email_template_types($is_sw);

require_once __DIR__ . '/../../../header.php';

$can_create = canCreate('message_center');
$can_edit   = canEdit('message_center');
$can_delete = canDelete('message_center');

$GET_URL    = getUrl('api/get_email_templates');
$SAVE_URL   = getUrl('api/save_email_template');
$DELETE_URL = getUrl('api/delete_email_template');
$SEND_URL   = getUrl('api/email_center'); // reuse the real send+log path for test emails
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-envelope-paper text-primary"></i> <?= $is_sw ? 'Violezo vya Barua Pepe' : 'Email Templates' ?></h2>
                <p class="text-muted mb-0"><?= $is_sw ? 'Simamia violezo vya arifa na majibu ya barua pepe' : 'Manage system email notifications and response templates' ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= getUrl('email_center') ?>" class="btn btn-outline-primary">
                    <i class="bi bi-envelope me-1"></i> <?= $is_sw ? 'Kituo cha Barua Pepe' : 'Email Center' ?>
                </a>
                <?php if ($can_create): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal" onclick="tplReset()">
                    <i class="bi bi-plus-circle me-1"></i> <?= $is_sw ? 'Kiolezo Kipya' : 'New Template' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat Cards (blue scale per §UI-1) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Jumla ya Violezo' : 'Total Templates' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-total">0</h3>
                    </div>
                    <i class="bi bi-files text-primary fs-2"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Violezo Hai' : 'Active Templates' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-active">0</h3>
                    </div>
                    <i class="bi bi-check-circle text-primary fs-2"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Template Library Card -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary"><i class="bi bi-collection me-1"></i> <?= $is_sw ? 'Maktaba ya Violezo' : 'Template Library' ?></h5>
            <button class="btn btn-sm btn-outline-secondary" id="btnRefresh" title="<?= $is_sw ? 'Onyesha upya' : 'Refresh' ?>">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
        <div class="card-body">
            <!-- Desktop table -->
            <div class="table-responsive d-none d-md-block">
                <table id="tplTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th><?= $is_sw ? 'Jina la Kiolezo' : 'Template Name' ?></th>
                            <th><?= $is_sw ? 'Mada' : 'Subject' ?></th>
                            <th><?= $is_sw ? 'Aina' : 'Type' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th><?= $is_sw ? 'Imeundwa' : 'Created' ?></th>
                            <th class="text-end"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <!-- Mobile card view (§UI-7) -->
            <div class="row g-2 d-md-none" id="cardView"></div>
        </div>
    </div>
</div>

<!-- Create/Edit Template Modal -->
<?php if ($can_create || $can_edit): ?>
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="tplModalTitle"><i class="bi bi-pencil-square me-1"></i> <?= $is_sw ? 'Unda Kiolezo' : 'Create Template' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="templateForm">
                <input type="hidden" id="tpl_id" name="id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="tpl_name" class="form-label"><?= $is_sw ? 'Jina la Kiolezo *' : 'Template Name *' ?></label>
                            <input type="text" class="form-control" id="tpl_name" name="template_name" required
                                   placeholder="<?= $is_sw ? 'mf. Taarifa ya Idhini ya Mkopo' : 'e.g. Loan Approval Notice' ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="tpl_type" class="form-label"><?= $is_sw ? 'Aina' : 'Type' ?></label>
                            <select class="form-select select2-static" id="tpl_type" name="template_type">
                                <?php foreach ($types as $k => $label): ?>
                                <option value="<?= $k ?>"><?= safe_output($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="tpl_subject" class="form-label"><?= $is_sw ? 'Mada ya Barua Pepe *' : 'Email Subject *' ?></label>
                            <input type="text" class="form-control" id="tpl_subject" name="subject" required
                                   placeholder="<?= $is_sw ? 'Andika mstari wa mada' : 'Enter the email subject line' ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="tpl_content" class="form-label mb-0"><?= $is_sw ? 'Maudhui ya Barua Pepe (HTML inaruhusiwa) *' : 'Email Content (HTML allowed) *' ?></label>
                                <?php if (canCreate('ai_assistant')): ?>
                                <button type="button" class="ai-assist-btn" data-target="#tpl_content"
                                        data-module="communication" data-submodule="email_template" data-field-type="message">
                                    <i class="bi bi-stars ai-spark"></i> <?= $is_sw ? 'Andika kwa AI' : 'Write with AI' ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <textarea class="form-control" id="tpl_content" name="content" rows="9" required
                                      placeholder="<?= $is_sw ? 'Mpendwa {{member_name}}, ...' : 'Dear {{member_name}}, ...' ?>"></textarea>
                            <div class="form-text small">
                                <?= $is_sw
                                    ? 'Tumia vibambo kama {{member_name}}, {{loan_id}}, {{amount}} kwa maudhui yanayobadilika.'
                                    : 'Use placeholders like {{member_name}}, {{loan_id}}, {{amount}} for dynamic content.' ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="tpl_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="tpl_active"><?= $is_sw ? 'Kiolezo kinatumika' : 'Template is Active' ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary" id="tplSaveBtn"><?= $is_sw ? 'Hifadhi Kiolezo' : 'Save Template' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Preview / Send Test Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i> <span id="previewTitle"><?= $is_sw ? 'Hakiki Kiolezo' : 'Preview Template' ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-2 small">
                    <dt class="col-sm-3"><?= $is_sw ? 'Mada' : 'Subject' ?></dt><dd class="col-sm-9" id="previewSubject"></dd>
                </dl>
                <iframe id="previewFrame" sandbox class="w-100 border rounded bg-white" style="min-height:280px"></iframe>
                <?php if ($can_create): ?>
                <hr>
                <label for="testEmail" class="form-label"><?= $is_sw ? 'Tuma jaribio kwa' : 'Send a test to' ?></label>
                <div class="input-group">
                    <input type="email" class="form-control" id="testEmail" placeholder="test@example.com">
                    <button class="btn btn-primary" type="button" id="sendTestBtn">
                        <i class="bi bi-send me-1"></i> <?= $is_sw ? 'Tuma Jaribio' : 'Send Test' ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Funga' : 'Close' ?></button>
            </div>
        </div>
    </div>
</div>

<?php include("footer.php"); ?>

<style>
.vk-stat-card { background:#e7f0ff; border:1px solid #b6ccfe !important; border-radius:.5rem; }
#tplTable thead th { font-weight:600; border-bottom:none; }
.dropdown-toggle::after { display:none; }
.vk-badge { display:inline-block; padding:.2rem .6rem; border-radius:.35rem; font-size:.75rem; font-weight:600; }
@media (max-width: 767px) {
    .container-fluid > .row:first-child { position:sticky; top:0; z-index:1020; background:#fff; }
}
</style>

<?php if (canCreate('ai_assistant')): ?>
<script>window.AI_ASSIST_CONFIG = { generateUrl: '<?= getUrl('api/ai/generate') ?>', isSw: <?= $is_sw ? 'true' : 'false' ?> };</script>
<script src="/assets/js/ai-assistant.js"></script>
<?php endif; ?>

<script>
(function () {
    const isSw       = <?= $is_sw ? 'true' : 'false' ?>;
    const GET_URL    = '<?= $GET_URL ?>';
    const SAVE_URL   = '<?= $SAVE_URL ?>';
    const DELETE_URL = '<?= $DELETE_URL ?>';
    const SEND_URL   = '<?= $SEND_URL ?>';
    const CAN_EDIT   = <?= $can_edit ? 'true' : 'false' ?>;
    const CAN_DELETE = <?= $can_delete ? 'true' : 'false' ?>;
    const TYPES      = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;

    const t = (en, sw) => isSw ? sw : en;
    function safeOutput(v) {
        if (v === null || v === undefined || v === '') return '';
        return $('<div>').text(v).html();
    }
    function typeBadge(key) {
        const label = TYPES[key] || key;
        return `<span class="vk-badge" style="background:#cfe2ff;color:#084298">${safeOutput(label)}</span>`;
    }
    function statusBadge(active) {
        return Number(active) === 1
            ? `<span class="vk-badge" style="background:#0d6efd;color:#fff">${t('Active','Inatumika')}</span>`
            : `<span class="vk-badge" style="background:#6c757d;color:#fff">${t('Inactive','Haitumiki')}</span>`;
    }
    function fmtDate(d) {
        if (!d) return '';
        const dt = new Date(String(d).replace(' ', 'T'));
        return isNaN(dt) ? safeOutput(d) : dt.toLocaleDateString();
    }

    // Keep the latest rows so view/edit handlers work off cached data.
    let ROWS = {};

    function actionMenu(row) {
        let items = `<li><button class="dropdown-item py-2 rounded" onclick="tplPreview(${row.id})"><i class="bi bi-eye text-primary me-2"></i> ${t('Preview','Hakiki')}</button></li>`;
        if (CAN_EDIT) {
            items += `<li><button class="dropdown-item py-2 rounded" onclick="tplEdit(${row.id})"><i class="bi bi-pencil text-primary me-2"></i> ${t('Edit','Hariri')}</button></li>`;
        }
        if (CAN_DELETE) {
            items += `<li><hr class="dropdown-divider"></li>`;
            items += `<li><button class="dropdown-item py-2 rounded text-danger" onclick="tplDelete(${row.id})"><i class="bi bi-trash text-danger me-2"></i> ${t('Delete','Futa')}</button></li>`;
        }
        return `<div class="dropdown d-flex justify-content-end">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-gear-fill me-1"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
        </div>`;
    }

    const table = $('#tplTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[4, 'desc']],
        dom: 'rtipB',
        buttons: [
            { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
        ],
        language: { emptyTable: t('No templates found.','Hakuna violezo.'), zeroRecords: t('No matching records.','Hakuna rekodi zinazolingana.') },
        columns: [
            { data: 'template_name', render: d => `<span class="fw-semibold">${safeOutput(d)}</span>` },
            { data: 'subject', render: d => safeOutput(d) },
            { data: 'template_type', render: d => typeBadge(d) },
            { data: 'is_active', render: d => statusBadge(d) },
            { data: 'created_at', render: (d, type) => type === 'display' ? `<span class="small">${fmtDate(d)}</span>` : (d || '') },
            { data: null, orderable: false, className: 'text-end', render: r => actionMenu(r) }
        ],
        drawCallback: function () {
            renderCards(this.api().rows({ page:'current' }).data().toArray());
        }
    });

    function renderCards(rows) {
        if (!rows.length) {
            $('#cardView').html('<div class="col-12 text-center py-5 text-muted">' + t('No templates found','Hakuna violezo') + '</div>');
            return;
        }
        let html = '';
        rows.forEach(r => {
            let actions = `<button class="btn btn-sm btn-outline-primary" onclick="tplPreview(${r.id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-eye"></i></button>`;
            if (CAN_EDIT)   actions += `<button class="btn btn-sm btn-outline-primary" onclick="tplEdit(${r.id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-pencil"></i></button>`;
            if (CAN_DELETE) actions += `<button class="btn btn-sm btn-outline-danger" onclick="tplDelete(${r.id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-trash"></i></button>`;
            html += `
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="fw-bold text-truncate me-2">${safeOutput(r.template_name)}</div>
                            ${statusBadge(r.is_active)}
                        </div>
                        <div class="small text-muted text-truncate">${safeOutput(r.subject)}</div>
                        <div class="mt-1">${typeBadge(r.template_type)}</div>
                    </div>
                    <div class="card-footer bg-white border-top p-0">
                        <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${actions}</div>
                    </div>
                </div>
            </div>`;
        });
        $('#cardView').html(html);
    }

    function loadData() {
        $.getJSON(GET_URL)
            .done(res => {
                if (!res.success) { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); return; }
                ROWS = {};
                (res.data || []).forEach(r => { ROWS[r.id] = r; });
                $('#stat-total').text(res.stats.totalTemplates);
                $('#stat-active').text(res.stats.activeTemplates);
                table.clear().rows.add(res.data).draw();
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to load templates.','Imeshindwa kupakia violezo.') }));
    }

    // ----- Row actions -----
    window.tplPreview = function (id) {
        const r = ROWS[id];
        if (!r) return;
        $('#previewTitle').text(r.template_name);
        $('#previewSubject').text(r.subject);
        new bootstrap.Modal(document.getElementById('previewModal')).show();
        document.getElementById('previewFrame').srcdoc = r.content || '';
        $('#previewModal').data('tpl', { subject: r.subject, body: r.content });
    };

    window.tplEdit = function (id) {
        const r = ROWS[id];
        if (!r) return;
        $('#tplModalTitle').html('<i class="bi bi-pencil-square me-1"></i> ' + t('Edit Template','Hariri Kiolezo'));
        $('#tpl_id').val(r.id);
        $('#tpl_name').val(r.template_name);
        $('#tpl_type').val(r.template_type).trigger('change');
        $('#tpl_subject').val(r.subject);
        $('#tpl_content').val(r.content);
        $('#tpl_active').prop('checked', Number(r.is_active) === 1);
        new bootstrap.Modal(document.getElementById('templateModal')).show();
    };

    window.tplDelete = function (id) {
        Swal.fire({
            title: t('Delete?','Futa?'),
            text: t('This cannot be undone.','Hatua hii haiwezi kurekebishwa.'),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: t('Yes, Delete','Ndiyo, Futa')
        }).then(rr => {
            if (!rr.isConfirmed) return;
            $.post(DELETE_URL, { id }, null, 'json')
                .done(res => {
                    if (res.success) { loadData(); Swal.fire({ icon:'success', title:t('Deleted','Imefutwa'), text:res.message, timer:2000, showConfirmButton:false }); }
                    else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                })
                .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Something went wrong.','Hitilafu imetokea.') }));
        });
    };

    window.tplReset = function () {
        $('#tplModalTitle').html('<i class="bi bi-pencil-square me-1"></i> ' + t('Create Template','Unda Kiolezo'));
        $('#templateForm')[0].reset();
        $('#tpl_id').val('');
        $('#tpl_type').val('general').trigger('change');
        $('#tpl_active').prop('checked', true);
    };

    $('#btnRefresh').on('click', loadData);

    // ----- Save (create/update) -----
    <?php if ($can_create || $can_edit): ?>
    $('#templateModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme:'bootstrap-5', dropdownParent: $('#templateModal'), width:'100%', minimumResultsForSearch: Infinity });
            }
        });
        $('#tpl_name').trigger('focus');
    });

    $('#templateForm').on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#tplSaveBtn').prop('disabled', true);
        $.post(SAVE_URL, $(this).serialize(), null, 'json')
            .done(res => {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
                    loadData();
                    Swal.fire({ icon:'success', title:t('Saved!','Imehifadhiwa!'), text:res.message, timer:2000, showConfirmButton:false });
                } else {
                    Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                }
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to save template.','Imeshindwa kuhifadhi kiolezo.') }))
            .always(() => $btn.prop('disabled', false));
    });
    <?php endif; ?>

    // ----- Send test (reuses the Email Center send + log path) -----
    <?php if ($can_create): ?>
    $('#sendTestBtn').on('click', function () {
        const tpl = $('#previewModal').data('tpl') || {};
        const to = $('#testEmail').val().trim();
        if (!to) { Swal.fire({ icon:'warning', title:t('Recipient required','Mpokeaji anahitajika') }); return; }
        const $btn = $(this).prop('disabled', true);
        Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        $.post(SEND_URL + '?action=send', { recipients: to, subject: tpl.subject || '(test)', body: tpl.body || '' }, null, 'json')
            .done(res => {
                if (res.success) Swal.fire({ icon:'success', title:t('Sent!','Imetumwa!'), text:res.message, timer:2200, showConfirmButton:false });
                else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to send test email.','Imeshindwa kutuma barua pepe ya jaribio.') }))
            .always(() => $btn.prop('disabled', false));
    });
    <?php endif; ?>

    loadData();
})();
</script>
