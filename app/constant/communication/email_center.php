<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('message_center');

require_once __DIR__ . '/../../../includes/email_helper.php';

$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int)$_SESSION['user_id'];

// Self-healing schema: make sure the log table exists before the page renders.
try { email_ensure_logs_table($pdo); } catch (Throwable $e) { /* surfaced via API */ }

require_once __DIR__ . '/../../../header.php';

$can_create = canCreate('message_center');
$can_delete = canDelete('message_center');

$API = getUrl('api/email_center');
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-envelope text-primary"></i> <?= $is_sw ? 'Kituo cha Barua Pepe' : 'Email Center' ?></h2>
                <p class="text-muted mb-0"><?= $is_sw ? 'Tuma na fuatilia barua pepe kwa wanachama na watumiaji' : 'Send and track emails to members and users' ?></p>
            </div>
            <?php if ($can_create): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#composeEmailModal">
                <i class="bi bi-pencil-square me-1"></i> <?= $is_sw ? 'Andika Barua Pepe' : 'Compose Email' ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat Cards (blue scale per §UI-1) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Jumla' : 'Total' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-total">0</h3>
                    </div>
                    <i class="bi bi-envelope-paper text-primary fs-2"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Zilizotumwa' : 'Sent' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-sent">0</h3>
                    </div>
                    <i class="bi bi-check-circle text-primary fs-2"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Zilizoshindwa' : 'Failed' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-failed">0</h3>
                    </div>
                    <i class="bi bi-exclamation-triangle text-primary fs-2"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $is_sw ? 'Zilizosubiri' : 'Queued' ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-queued">0</h3>
                    </div>
                    <i class="bi bi-hourglass-split text-primary fs-2"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Log Card -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary"><i class="bi bi-clock-history me-1"></i> <?= $is_sw ? 'Kumbukumbu za Barua Pepe' : 'Email Log' ?></h5>
            <button class="btn btn-sm btn-outline-secondary" id="btnRefresh" title="<?= $is_sw ? 'Onyesha upya' : 'Refresh' ?>">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
        <div class="card-body">
            <!-- Desktop table -->
            <div class="table-responsive d-none d-md-block">
                <table id="emailTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th><?= $is_sw ? 'Mpokeaji' : 'Recipient' ?></th>
                            <th><?= $is_sw ? 'Mada' : 'Subject' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th><?= $is_sw ? 'Mtumaji' : 'Sender' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
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

<!-- Compose Email Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="composeEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg my-4">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> <?= $is_sw ? 'Andika Barua Pepe' : 'Compose Email' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="composeEmailForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="templatePick" class="form-label">
                            <i class="bi bi-files text-primary me-1"></i><?= $is_sw ? 'Tumia Kiolezo (hiari)' : 'Use a Template (optional)' ?>
                        </label>
                        <select class="form-select" id="templatePick">
                            <option value=""><?= $is_sw ? '— Hakuna kiolezo —' : '— No template —' ?></option>
                        </select>
                        <div class="form-text small">
                            <?= $is_sw
                                ? 'Kuchagua kiolezo kutajaza mada na maudhui. Unaweza kuhariri baadaye.'
                                : 'Choosing a template fills in the subject and message. You can still edit them.' ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="recipients" class="form-label"><?= $is_sw ? 'Wapokeaji *' : 'Recipients *' ?></label>
                        <select class="form-select" id="recipients" name="recipients_select[]" multiple></select>
                        <div class="form-text small">
                            <?= $is_sw
                                ? 'Chagua kutoka kwenye orodha au andika anwani mpya kisha bonyeza Enter.'
                                : 'Pick from the list or type a new address and press Enter.' ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="subject" class="form-label"><?= $is_sw ? 'Mada *' : 'Subject *' ?></label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="body" class="form-label mb-0"><?= $is_sw ? 'Maudhui *' : 'Message *' ?></label>
                            <?php if (canCreate('ai_assistant')): ?>
                            <button type="button" class="ai-assist-btn" data-target="#body"
                                    data-module="communication" data-submodule="email" data-field-type="message">
                                <i class="bi bi-stars ai-spark"></i> <?= $is_sw ? 'Andika kwa AI' : 'Write with AI' ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <textarea class="form-control" id="body" name="body" rows="9" required></textarea>
                        <div class="form-text small">
                            <?= $is_sw
                                ? 'HTML inaruhusiwa. Tumia {{member_name}}, {{group_name}} kwa maudhui yanayobadilika.'
                                : 'HTML allowed. Use {{member_name}}, {{group_name}} for dynamic content.' ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary" id="sendBtn">
                        <i class="bi bi-send me-1"></i> <?= $is_sw ? 'Tuma' : 'Send Email' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Email Modal -->
<div class="modal fade" id="viewEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg my-4">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-envelope-open me-1"></i> <?= $is_sw ? 'Maelezo ya Barua Pepe' : 'Email Details' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewEmailBody">
                <!-- populated by JS -->
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
#emailTable thead th { font-weight:600; border-bottom:none; }
.dropdown-toggle::after { display:none; }
.vk-badge { display:inline-block; padding:.2rem .6rem; border-radius:.35rem; font-size:.75rem; font-weight:600; }

/* Professional, readable recipient picker (Select2 — §UI-3) */
#composeEmailModal .select2-container { width: 100% !important; }
#composeEmailModal .select2-container--bootstrap-5 .select2-selection {
    min-height: 48px;
    padding: .35rem .55rem;
    border-color: #b6ccfe;
}
/* Roomy, clearly visible typing area inside the multi-select */
.select2-container--bootstrap-5 .select2-search--inline .select2-search__field {
    font-size: .95rem;
    min-width: 14rem !important;
    height: 1.9rem;
    margin-top: .2rem;
}
.select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__choice {
    font-size: .85rem;
    padding: .2rem .5rem;
    margin-top: .25rem;
}
/* Comfortable, easy-to-scan dropdown */
.select2-container--bootstrap-5 .select2-dropdown { border-color: #b6ccfe; }
.select2-container--bootstrap-5 .select2-results__option { padding: .5rem .75rem; font-size: .9rem; }
.select2-container--bootstrap-5 .select2-results__group {
    font-weight: 600; color: #0d6efd; padding: .4rem .6rem;
}
.select2-container--bootstrap-5 .select2-results > .select2-results__options { max-height: 320px; }

/* Flexible, fully scrollable modals: the whole dialog scrolls so every field,
   the Select2 dropdown options and the footer buttons stay reachable. The body
   must NOT clip overflow, otherwise the recipient dropdown gets cut off. */
.modal { overflow-y: auto; }
#composeEmailModal .modal-body,
#viewEmailModal .modal-body { overflow: visible; }
#composeEmailModal .modal-content,
#viewEmailModal .modal-content { max-height: none; }

@media (max-width: 767px) {
    .container-fluid > .row:first-child { position:sticky; top:0; z-index:1020; background:#fff; }
    .modal-dialog.my-4 { margin: 0.75rem !important; }
}
</style>

<?php if (canCreate('ai_assistant')): ?>
<script>window.AI_ASSIST_CONFIG = { generateUrl: '<?= getUrl('api/ai/generate') ?>', isSw: <?= $is_sw ? 'true' : 'false' ?> };</script>
<script src="/assets/js/ai-assistant.js"></script>
<?php endif; ?>

<script>
(function () {
    const isSw      = <?= $is_sw ? 'true' : 'false' ?>;
    const API       = '<?= $API ?>';
    const CAN_CREATE = <?= $can_create ? 'true' : 'false' ?>;
    const CAN_DELETE = <?= $can_delete ? 'true' : 'false' ?>;

    const t = (en, sw) => isSw ? sw : en;

    function safeOutput(v) {
        if (v === null || v === undefined || v === '') return '';
        return $('<div>').text(v).html();
    }

    // Blue-scale status badge per §UI-1 (no green / yellow).
    function statusBadge(status) {
        const map = {
            sent:   { bg:'#0d6efd', fg:'#fff',     label:t('Sent','Imetumwa') },
            failed: { bg:'#dc3545', fg:'#fff',     label:t('Failed','Imeshindwa') },
            queued: { bg:'#e9ecef', fg:'#495057',  label:t('Queued','Inasubiri') }
        };
        const s = map[status] || map.queued;
        return `<span class="vk-badge" style="background:${s.bg};color:${s.fg}">${s.label}</span>`;
    }

    function fmtDate(d) {
        if (!d) return '';
        const dt = new Date(d.replace(' ', 'T'));
        if (isNaN(dt)) return safeOutput(d);
        return dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
    }

    function actionMenu(row) {
        let items = `<li><button class="dropdown-item py-2 rounded" onclick="emailView(${row.email_id})"><i class="bi bi-eye text-primary me-2"></i> ${t('View','Ona')}</button></li>`;
        if (CAN_CREATE) {
            items += `<li><button class="dropdown-item py-2 rounded" onclick="emailResend(${row.email_id})"><i class="bi bi-arrow-repeat text-primary me-2"></i> ${t('Resend','Tuma tena')}</button></li>`;
        }
        if (CAN_DELETE) {
            items += `<li><hr class="dropdown-divider"></li>`;
            items += `<li><button class="dropdown-item py-2 rounded text-danger" onclick="emailDelete(${row.email_id})"><i class="bi bi-trash text-danger me-2"></i> ${t('Delete','Futa')}</button></li>`;
        }
        return `<div class="dropdown d-flex justify-content-end">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-gear-fill me-1"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
        </div>`;
    }

    const table = $('#emailTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[4, 'desc']],
        dom: 'rtipB',
        buttons: [
            { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
        ],
        language: { emptyTable: t('No emails found.','Hakuna barua pepe.'), zeroRecords: t('No matching records.','Hakuna rekodi zinazolingana.') },
        columns: [
            { data: null, render: r => {
                const name = r.recipient_name ? `<div class="small text-muted">${safeOutput(r.recipient_name)}</div>` : '';
                return `<div class="fw-semibold">${safeOutput(r.recipient_email)}</div>${name}`;
            }},
            { data: 'subject', render: d => safeOutput(d) },
            { data: 'status', render: d => statusBadge(d) },
            { data: 'sender_name', render: d => safeOutput(d) || '<span class="text-muted">—</span>' },
            { data: 'created_at', render: (d, type) => type === 'display' ? `<span class="small">${fmtDate(d)}</span>` : (d || '') },
            { data: null, orderable: false, className: 'text-end', render: r => actionMenu(r) }
        ],
        drawCallback: function () {
            renderCards(this.api().rows({ page:'current' }).data().toArray());
        }
    });

    function renderCards(rows) {
        if (!rows.length) {
            $('#cardView').html('<div class="col-12 text-center py-5 text-muted">' + t('No emails found','Hakuna barua pepe') + '</div>');
            return;
        }
        let html = '';
        rows.forEach(r => {
            let actions = `<button class="btn btn-sm btn-outline-primary" onclick="emailView(${r.email_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-eye"></i></button>`;
            if (CAN_CREATE) actions += `<button class="btn btn-sm btn-outline-primary" onclick="emailResend(${r.email_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-arrow-repeat"></i></button>`;
            if (CAN_DELETE) actions += `<button class="btn btn-sm btn-outline-danger" onclick="emailDelete(${r.email_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-trash"></i></button>`;
            html += `
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="fw-bold text-truncate me-2">${safeOutput(r.subject)}</div>
                            ${statusBadge(r.status)}
                        </div>
                        <div class="small text-muted">${safeOutput(r.recipient_email)}</div>
                        <div class="small text-muted">${fmtDate(r.created_at)}</div>
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
        $.getJSON(API, { action:'list' })
            .done(res => {
                if (!res.success) { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); return; }
                $('#stat-total').text(res.stats.total);
                $('#stat-sent').text(res.stats.sent);
                $('#stat-failed').text(res.stats.failed);
                $('#stat-queued').text(res.stats.queued);
                table.clear().rows.add(res.data).draw();
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to load emails.','Imeshindwa kupakia barua pepe.') }));
    }

    // --- Row actions (exposed globally for inline handlers) ---
    window.emailView = function (id) {
        $.getJSON(API, { action:'get', id }).done(res => {
            if (!res.success) { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); return; }
            const e = res.data;
            const err = e.error_message ? `<div class="alert alert-danger py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>${safeOutput(e.error_message)}</div>` : '';
            // The body is user-authored HTML. Render it in a sandboxed iframe
            // (no allow-scripts) so stored markup can never execute script in
            // the viewer's session — prevents stored XSS from the compose form.
            $('#viewEmailBody').html(`
                <dl class="row mb-2 small">
                    <dt class="col-sm-3">${t('To','Kwa')}</dt><dd class="col-sm-9">${safeOutput(e.recipient_name ? e.recipient_name + ' <' + e.recipient_email + '>' : e.recipient_email)}</dd>
                    <dt class="col-sm-3">${t('Subject','Mada')}</dt><dd class="col-sm-9">${safeOutput(e.subject)}</dd>
                    <dt class="col-sm-3">${t('Status','Hali')}</dt><dd class="col-sm-9">${statusBadge(e.status)}</dd>
                    <dt class="col-sm-3">${t('Sent','Imetumwa')}</dt><dd class="col-sm-9">${fmtDate(e.sent_at) || '<span class="text-muted">—</span>'}</dd>
                </dl>
                ${err}
                <iframe id="viewEmailFrame" sandbox class="w-100 border rounded bg-white" style="min-height:260px"></iframe>
            `);
            // srcdoc is assigned (not concatenated into markup) so the HTML is
            // parsed only inside the sandboxed document.
            document.getElementById('viewEmailFrame').srcdoc = e.body || '';
            new bootstrap.Modal(document.getElementById('viewEmailModal')).show();
        });
    };

    window.emailResend = function (id) {
        Swal.fire({
            title: t('Resend email?','Tuma barua pepe tena?'),
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: t('Yes, Resend','Ndiyo, Tuma')
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
            $.post(API + '?action=resend', { id }, null, 'json')
                .done(res => {
                    if (res.success) { loadData(); Swal.fire({ icon:'success', title:t('Done!','Imekamilika!'), text:res.message, timer:2000, showConfirmButton:false }); }
                    else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                })
                .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Something went wrong.','Hitilafu imetokea.') }));
        });
    };

    window.emailDelete = function (id) {
        Swal.fire({
            title: t('Delete?','Futa?'),
            text: t('This cannot be undone.','Hatua hii haiwezi kurekebishwa.'),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: t('Yes, Delete','Ndiyo, Futa')
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(API + '?action=delete', { id }, null, 'json')
                .done(res => {
                    if (res.success) { loadData(); Swal.fire({ icon:'success', title:t('Deleted','Imefutwa'), text:res.message, timer:2000, showConfirmButton:false }); }
                    else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                })
                .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Something went wrong.','Hitilafu imetokea.') }));
        });
    };

    $('#btnRefresh').on('click', loadData);

    // --- Compose modal wiring ---
    <?php if ($can_create): ?>
    // AJAX Select2 (§UI-3: large dataset → search by typing, filtered
    // server-side). `tags:true` still lets the user enter an address that is
    // not a member/staff and press Enter to add it.
    function initRecipientSelect() {
        const $sel = $('#recipients');
        if ($sel.hasClass('select2-hidden-accessible')) return;
        $sel.select2({
            theme: 'bootstrap-5',
            dropdownParent: $('#composeEmailModal'),
            placeholder: t('Search a name or email, or type a new address...','Tafuta jina au barua pepe, au andika anwani mpya...'),
            allowClear: true,
            width: '100%',
            tags: true,
            tokenSeparators: [',', ';', ' '],
            minimumInputLength: 0,   // show a short preview before typing (§UI-3)
            ajax: {
                url: API,
                dataType: 'json',
                delay: 300,
                data: params => ({ action: 'search_recipients', q: params.term }),
                processResults: data => ({ results: data.results || [] }),
                cache: true
            },
            createTag: function (params) {
                const term = $.trim(params.term);
                if (term === '') return null;
                return { id: term, text: term, newTag: true };
            }
        });
    }

    // Load active email templates into the compose picker (once).
    let templatesLoaded = false;
    let TEMPLATES = {};
    function loadTemplates() {
        if (templatesLoaded) return;
        $.getJSON('<?= getUrl('api/get_email_templates') ?>', { active_only: 1 }).done(res => {
            if (res.success) {
                const $pick = $('#templatePick');
                (res.data || []).forEach(tpl => {
                    TEMPLATES[tpl.id] = tpl;
                    $pick.append(new Option(tpl.template_name, tpl.id, false, false));
                });
                templatesLoaded = true;
            }
        });
    }

    // Applying a template prefills subject + body (only overwrites when empty
    // or the user confirms, to avoid clobbering work in progress).
    $('#templatePick').on('change', function () {
        const tpl = TEMPLATES[$(this).val()];
        if (!tpl) return;
        const apply = () => { $('#subject').val(tpl.subject); $('#body').val(tpl.content); };
        if (($('#subject').val().trim() === '') && ($('#body').val().trim() === '')) {
            apply();
        } else {
            Swal.fire({
                title: t('Replace current content?','Badilisha maudhui yaliyopo?'),
                text: t('This will overwrite the subject and message.','Hii itafuta mada na maudhui yaliyopo.'),
                icon: 'question', showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                confirmButtonText: t('Yes, use template','Ndiyo, tumia kiolezo')
            }).then(r => { if (r.isConfirmed) apply(); else $('#templatePick').val(''); });
        }
    });

    $('#composeEmailModal').on('shown.bs.modal', function () {
        initRecipientSelect();
        loadTemplates();
        $('#subject').trigger('focus');
    });

    $('#composeEmailForm').on('submit', function (e) {
        e.preventDefault();
        const recips = ($('#recipients').val() || []).join(',');
        const subject = $('#subject').val().trim();
        const body = $('#body').val().trim();

        if (!recips) { Swal.fire({ icon:'warning', title:t('Recipients required','Wapokeaji wanahitajika'), text:t('Add at least one recipient.','Ongeza mpokeaji mmoja angalau.') }); return; }
        if (!subject) { Swal.fire({ icon:'warning', title:t('Subject required','Mada inahitajika') }); return; }
        if (!body)    { Swal.fire({ icon:'warning', title:t('Message required','Maudhui yanahitajika') }); return; }

        const $btn = $('#sendBtn').prop('disabled', true);
        Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });

        $.post(API + '?action=send', { recipients: recips, subject, body }, null, 'json')
            .done(res => {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('composeEmailModal')).hide();
                    $('#composeEmailForm')[0].reset();
                    $('#recipients').val(null).trigger('change');
                    $('#templatePick').val('');
                    loadData();
                    Swal.fire({ icon:'success', title:t('Sent!','Imetumwa!'), text:res.message, timer:2200, showConfirmButton:false });
                } else {
                    Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                }
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to send email.','Imeshindwa kutuma barua pepe.') }))
            .always(() => $btn.prop('disabled', false));
    });
    <?php endif; ?>

    loadData();
})();
</script>
