<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('message_center');

require_once __DIR__ . '/../../../includes/sms_helper.php';

$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int)$_SESSION['user_id'];

try { sms_ensure_logs_table($pdo); } catch (Throwable $e) { /* surfaced via API */ }

$cfg = sms_get_config($pdo);

require_once __DIR__ . '/../../../header.php';

$can_create = canCreate('message_center');
$can_delete = canDelete('message_center');
$API = getUrl('api/sms_center');
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-phone text-primary"></i> <?= $is_sw ? 'Kituo cha SMS' : 'SMS Center' ?></h2>
                <p class="text-muted mb-0"><?= $is_sw ? 'Tuma na fuatilia SMS kwa wanachama' : 'Send and track SMS to members' ?></p>
            </div>
            <div class="d-flex gap-2">
                <?php if (isAdmin() || canEdit('system_settings')): ?>
                <a href="<?= getUrl('sms-settings') ?>" class="btn btn-outline-secondary" title="<?= $is_sw ? 'Mipangilio ya SMS' : 'SMS Settings' ?>">
                    <i class="bi bi-gear"></i><span class="d-none d-sm-inline ms-1"><?= $is_sw ? 'Mipangilio' : 'Settings' ?></span>
                </a>
                <?php endif; ?>
                <?php if ($can_create): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#composeSmsModal">
                    <i class="bi bi-pencil-square me-1"></i> <?= $is_sw ? 'Andika SMS' : 'Compose SMS' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$cfg['has_gateway'] && (isAdmin() || canEdit('system_settings'))): ?>
    <div class="alert alert-light border d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-circle text-primary"></i>
        <div class="flex-grow-1 small"><?= $is_sw ? 'Lango la SMS bado halijasanidiwa. Sanidi ili kuanza kutuma.' : 'No SMS gateway is set up yet. Configure one to start sending.' ?></div>
        <a href="<?= getUrl('sms-settings') ?>" class="btn btn-sm btn-primary"><?= $is_sw ? 'Sanidi SMS' : 'Set up SMS' ?></a>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['total',  $is_sw ? 'Jumla' : 'Total',          'bi-chat-dots'],
            ['sent',   $is_sw ? 'Zilizotumwa' : 'Sent',      'bi-check-circle'],
            ['failed', $is_sw ? 'Zilizoshindwa' : 'Failed',  'bi-exclamation-triangle'],
            ['queued', $is_sw ? 'Zilizosubiri' : 'Queued',   'bi-hourglass-split'],
        ];
        foreach ($cards as [$id, $label, $icon]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 vk-stat-card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase"><?= $label ?></div>
                        <h3 class="mb-0 fw-bold text-primary" id="stat-<?= $id ?>">0</h3>
                    </div>
                    <i class="bi <?= $icon ?> text-primary fs-2"></i>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- SMS Log -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary"><i class="bi bi-clock-history me-1"></i> <?= $is_sw ? 'Kumbukumbu za SMS' : 'SMS Log' ?></h5>
            <button class="btn btn-sm btn-outline-secondary" id="btnRefresh" title="<?= $is_sw ? 'Onyesha upya' : 'Refresh' ?>"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table id="smsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th><?= $is_sw ? 'Mpokeaji' : 'Recipient' ?></th>
                            <th><?= $is_sw ? 'Ujumbe' : 'Message' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                            <th class="text-end"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="row g-2 d-md-none" id="cardView"></div>
        </div>
    </div>
</div>

<!-- Compose SMS Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="composeSmsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg my-4">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i> <?= $is_sw ? 'Andika SMS' : 'Compose SMS' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="composeSmsForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="recipients" class="form-label"><?= $is_sw ? 'Wapokeaji *' : 'Recipients *' ?></label>
                        <select class="form-select" id="recipients" name="recipients_select[]" multiple></select>
                        <div class="form-text small">
                            <?= $is_sw ? 'Tafuta mwanachama au andika namba mpya kisha bonyeza Enter.' : 'Search a member or type a new number and press Enter.' ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="message" class="form-label mb-0"><?= $is_sw ? 'Ujumbe *' : 'Message *' ?></label>
                            <?php if (canCreate('ai_assistant')): ?>
                            <button type="button" class="ai-assist-btn" data-target="#message"
                                    data-module="communication" data-submodule="sms" data-field-type="message">
                                <i class="bi bi-stars ai-spark"></i> <?= $is_sw ? 'Andika kwa AI' : 'Write with AI' ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <textarea class="form-control" id="message" name="message" rows="4" required></textarea>
                        <div class="form-text small d-flex justify-content-between">
                            <span><?= $is_sw ? 'Tumia {{member_name}} kwa jina la mwanachama.' : 'Use {{member_name}} for the member name.' ?></span>
                            <span><span id="charCount">0</span> · <span id="segCount">0</span> SMS</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary" id="sendBtn"><i class="bi bi-send me-1"></i> <?= $is_sw ? 'Tuma' : 'Send SMS' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View SMS Modal -->
<div class="modal fade" id="viewSmsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg my-4">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-chat-square-text me-1"></i> <?= $is_sw ? 'Maelezo ya SMS' : 'SMS Details' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewSmsBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Funga' : 'Close' ?></button>
            </div>
        </div>
    </div>
</div>

<?php include("footer.php"); ?>

<style>
.vk-stat-card { background:#e7f0ff; border:1px solid #b6ccfe !important; border-radius:.5rem; }
#smsTable thead th { font-weight:600; border-bottom:none; }
.dropdown-toggle::after { display:none; }
.vk-badge { display:inline-block; padding:.2rem .6rem; border-radius:.35rem; font-size:.75rem; font-weight:600; }
.modal { overflow-y:auto; }
#composeSmsModal .modal-body, #viewSmsModal .modal-body { overflow:visible; }
#composeSmsModal .select2-container--bootstrap-5 .select2-selection { min-height:48px; border-color:#b6ccfe; }
.select2-container--bootstrap-5 .select2-search--inline .select2-search__field { font-size:.95rem; min-width:14rem !important; height:1.9rem; }
.select2-container--bootstrap-5 .select2-results__option { padding:.5rem .75rem; font-size:.9rem; }
.select2-container--bootstrap-5 .select2-results__group { font-weight:600; color:#0d6efd; }
@media (max-width:767px){ .container-fluid > .row:first-child { position:sticky; top:0; z-index:1020; background:#fff; } .modal-dialog.my-4 { margin:.75rem !important; } }
</style>

<?php if (canCreate('ai_assistant')): ?>
<script>window.AI_ASSIST_CONFIG = { generateUrl: '<?= getUrl('api/ai/generate') ?>', isSw: <?= $is_sw ? 'true' : 'false' ?> };</script>
<script src="/assets/js/ai-assistant.js"></script>
<?php endif; ?>

<script>
(function () {
    const isSw       = <?= $is_sw ? 'true' : 'false' ?>;
    const API        = '<?= $API ?>';
    const CAN_CREATE = <?= $can_create ? 'true' : 'false' ?>;
    const CAN_DELETE = <?= $can_delete ? 'true' : 'false' ?>;
    const t = (en, sw) => isSw ? sw : en;

    function safeOutput(v) { return (v === null || v === undefined || v === '') ? '' : $('<div>').text(v).html(); }

    function statusBadge(status) {
        const map = {
            sent:   { bg:'#0d6efd', fg:'#fff',    label:t('Sent','Imetumwa') },
            failed: { bg:'#dc3545', fg:'#fff',    label:t('Failed','Imeshindwa') },
            queued: { bg:'#e9ecef', fg:'#495057', label:t('Queued','Inasubiri') }
        };
        const s = map[status] || map.queued;
        return `<span class="vk-badge" style="background:${s.bg};color:${s.fg}">${s.label}</span>`;
    }
    function fmtDate(d) {
        if (!d) return '';
        const dt = new Date(String(d).replace(' ', 'T'));
        return isNaN(dt) ? safeOutput(d) : dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
    }
    function truncate(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n) + '…' : s; }

    function actionMenu(row) {
        let items = `<li><button class="dropdown-item py-2 rounded" onclick="smsView(${row.sms_id})"><i class="bi bi-eye text-primary me-2"></i> ${t('View','Ona')}</button></li>`;
        if (CAN_CREATE) items += `<li><button class="dropdown-item py-2 rounded" onclick="smsResend(${row.sms_id})"><i class="bi bi-arrow-repeat text-primary me-2"></i> ${t('Resend','Tuma tena')}</button></li>`;
        if (CAN_DELETE) {
            items += `<li><hr class="dropdown-divider"></li>`;
            items += `<li><button class="dropdown-item py-2 rounded text-danger" onclick="smsDelete(${row.sms_id})"><i class="bi bi-trash text-danger me-2"></i> ${t('Delete','Futa')}</button></li>`;
        }
        return `<div class="dropdown d-flex justify-content-end">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
        </div>`;
    }

    const table = $('#smsTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[3, 'desc']], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
        language: { emptyTable: t('No SMS found.','Hakuna SMS.'), zeroRecords: t('No matching records.','Hakuna rekodi zinazolingana.') },
        columns: [
            { data: null, render: r => {
                const name = r.recipient_name ? `<div class="small text-muted">${safeOutput(r.recipient_name)}</div>` : '';
                return `<div class="fw-semibold">${safeOutput(r.recipient_phone)}</div>${name}`;
            }},
            { data: 'message', render: d => `<span title="${safeOutput(d)}">${safeOutput(truncate(d, 60))}</span>` },
            { data: 'status', render: d => statusBadge(d) },
            { data: 'created_at', render: (d, type) => type === 'display' ? `<span class="small">${fmtDate(d)}</span>` : (d || '') },
            { data: null, orderable: false, className: 'text-end', render: r => actionMenu(r) }
        ],
        drawCallback: function () { renderCards(this.api().rows({ page:'current' }).data().toArray()); }
    });

    function renderCards(rows) {
        if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted">' + t('No SMS found','Hakuna SMS') + '</div>'); return; }
        let html = '';
        rows.forEach(r => {
            let a = `<button class="btn btn-sm btn-outline-primary" onclick="smsView(${r.sms_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-eye"></i></button>`;
            if (CAN_CREATE) a += `<button class="btn btn-sm btn-outline-primary" onclick="smsResend(${r.sms_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-arrow-repeat"></i></button>`;
            if (CAN_DELETE) a += `<button class="btn btn-sm btn-outline-danger" onclick="smsDelete(${r.sms_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-trash"></i></button>`;
            html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">${safeOutput(r.recipient_phone)}</div>${statusBadge(r.status)}</div>
                <div class="small text-muted">${safeOutput(truncate(r.message, 80))}</div>
                <div class="small text-muted">${fmtDate(r.created_at)}</div>
                </div><div class="card-footer bg-white border-top p-0"><div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${a}</div></div></div></div>`;
        });
        $('#cardView').html(html);
    }

    function loadData() {
        $.getJSON(API, { action:'list' })
            .done(res => {
                if (!res.success) { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); return; }
                $('#stat-total').text(res.stats.total); $('#stat-sent').text(res.stats.sent);
                $('#stat-failed').text(res.stats.failed); $('#stat-queued').text(res.stats.queued);
                table.clear().rows.add(res.data).draw();
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to load SMS.','Imeshindwa kupakia SMS.') }));
    }

    window.smsView = function (id) {
        $.getJSON(API, { action:'get', id }).done(res => {
            if (!res.success) { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); return; }
            const s = res.data;
            const err = s.error_message ? `<div class="alert alert-danger py-2 small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>${safeOutput(s.error_message)}</div>` : '';
            $('#viewSmsBody').html(`
                <dl class="row mb-2 small">
                    <dt class="col-sm-3">${t('To','Kwa')}</dt><dd class="col-sm-9">${safeOutput(s.recipient_name ? s.recipient_name + ' (' + s.recipient_phone + ')' : s.recipient_phone)}</dd>
                    <dt class="col-sm-3">${t('Status','Hali')}</dt><dd class="col-sm-9">${statusBadge(s.status)}</dd>
                    <dt class="col-sm-3">${t('Gateway','Lango')}</dt><dd class="col-sm-9">${safeOutput(s.provider) || '<span class="text-muted">—</span>'}</dd>
                    <dt class="col-sm-3">${t('Sent','Imetumwa')}</dt><dd class="col-sm-9">${fmtDate(s.sent_at) || '<span class="text-muted">—</span>'}</dd>
                </dl>${err}
                <div class="border rounded p-3 bg-light" style="white-space:pre-wrap">${safeOutput(s.message)}</div>`);
            new bootstrap.Modal(document.getElementById('viewSmsModal')).show();
        });
    };

    window.smsResend = function (id) {
        Swal.fire({ title:t('Resend SMS?','Tuma SMS tena?'), icon:'question', showCancelButton:true, confirmButtonColor:'#0d6efd', confirmButtonText:t('Yes, Resend','Ndiyo, Tuma') })
            .then(r => { if (!r.isConfirmed) return;
                Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
                $.post(API + '?action=resend', { id }, null, 'json').done(res => {
                    if (res.success) { loadData(); Swal.fire({ icon:'success', title:t('Done!','Imekamilika!'), text:res.message, timer:2000, showConfirmButton:false }); }
                    else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                }).fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Something went wrong.','Hitilafu imetokea.') }));
            });
    };

    window.smsDelete = function (id) {
        Swal.fire({ title:t('Delete?','Futa?'), text:t('This cannot be undone.','Hatua hii haiwezi kurekebishwa.'), icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:t('Yes, Delete','Ndiyo, Futa') })
            .then(r => { if (!r.isConfirmed) return;
                $.post(API + '?action=delete', { id }, null, 'json').done(res => {
                    if (res.success) { loadData(); Swal.fire({ icon:'success', title:t('Deleted','Imefutwa'), text:res.message, timer:2000, showConfirmButton:false }); }
                    else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
                }).fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Something went wrong.','Hitilafu imetokea.') }));
            });
    };

    $('#btnRefresh').on('click', loadData);

    <?php if ($can_create): ?>
    function initRecipientSelect() {
        const $sel = $('#recipients');
        if ($sel.hasClass('select2-hidden-accessible')) return;
        $sel.select2({
            theme:'bootstrap-5', dropdownParent:$('#composeSmsModal'),
            placeholder:t('Search a member or type a number...','Tafuta mwanachama au andika namba...'),
            allowClear:true, width:'100%', tags:true, tokenSeparators:[',',';',' '], minimumInputLength: 0,
            ajax:{ url:API, dataType:'json', delay:300, data:p => ({ action: 'search_recipients', q:p.term }), processResults:d => ({ results:d.results || [] }), cache:true },
            createTag:p => { const term = $.trim(p.term); return term === '' ? null : { id:term, text:term, newTag:true }; }
        });
    }
    function updateCount() {
        const len = $('#message').val().length;
        $('#charCount').text(len + ' ' + t('chars','herufi'));
        $('#segCount').text(len === 0 ? 0 : (len <= 160 ? 1 : Math.ceil(len / 153)));
    }
    $('#message').on('input', updateCount);
    $('#composeSmsModal').on('shown.bs.modal', function () { initRecipientSelect(); updateCount(); $('#message').trigger('focus'); });

    $('#composeSmsForm').on('submit', function (e) {
        e.preventDefault();
        const recips = ($('#recipients').val() || []).join(',');
        const message = $('#message').val().trim();
        if (!recips)  { Swal.fire({ icon:'warning', title:t('Recipients required','Wapokeaji wanahitajika') }); return; }
        if (!message) { Swal.fire({ icon:'warning', title:t('Message required','Ujumbe unahitajika') }); return; }
        const $btn = $('#sendBtn').prop('disabled', true);
        Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        $.post(API + '?action=send', { recipients: recips, message }, null, 'json')
            .done(res => {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('composeSmsModal')).hide();
                    $('#composeSmsForm')[0].reset(); $('#recipients').val(null).trigger('change'); updateCount();
                    loadData();
                    Swal.fire({ icon:'success', title:t('Sent!','Imetumwa!'), text:res.message, timer:2200, showConfirmButton:false });
                } else { Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message }); }
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to send SMS.','Imeshindwa kutuma SMS.') }))
            .always(() => $btn.prop('disabled', false));
    });
    <?php endif; ?>

    loadData();
})();
</script>
