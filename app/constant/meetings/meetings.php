<?php
// app/constant/meetings/meetings.php — Meetings list + create/edit.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();

requireViewPermission('meetings');
$can_create = canCreate('meetings');
$can_edit   = canEdit('meetings');
$can_delete = canDelete('meetings');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-left:5px solid #0d6efd !important;">
                <div class="card-body p-3 p-md-4 bg-white d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                    <div class="text-center text-md-start">
                        <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-people-fill me-2"></i><?= $t('Meetings', 'Mikutano') ?></h3>
                        <p class="text-muted mb-0 small"><?= $t('Record group meetings, attendance and supporting documents', 'Rekodi mikutano ya kikundi, mahudhurio na nyaraka') ?></p>
                    </div>
                    <?php if ($can_create): ?>
                    <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#meetingModal" onclick="openCreateMeeting()">
                        <i class="bi bi-plus-lg me-2"></i><?= $t('New Meeting', 'Mkutano Mpya') ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['stat-total', 'text-primary', 'bi-calendar-event', $t('Total Meetings', 'Jumla ya Mikutano')],
            ['stat-month', 'text-success', 'bi-calendar-month', $t('This Month', 'Mwezi Huu')],
            ['stat-scheduled', 'text-warning', 'bi-clock-history', $t('Scheduled', 'Yaliyopangwa')],
            ['stat-held', 'text-info', 'bi-check2-circle', $t('Held', 'Yaliyofanyika')],
        ];
        foreach ($cards as [$id, $color, $icon, $label]): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="d-flex justify-content-between">
                    <div><h4 class="mb-0 fw-bold <?= $color ?>" id="<?= $id ?>">0</h4><p class="mb-0 text-muted small"><?= $label ?></p></div>
                    <div class="align-self-center"><i class="bi <?= $icon ?> <?= $color ?>" style="font-size:2rem;opacity:.3;"></i></div>
                </div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body"><div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold small"><?= $t('Status', 'Hali') ?></label>
                <select class="form-select" id="fStatus">
                    <option value=""><?= $t('All', 'Zote') ?></option>
                    <option value="scheduled"><?= $t('Scheduled', 'Imepangwa') ?></option>
                    <option value="held"><?= $t('Held', 'Imefanyika') ?></option>
                    <option value="cancelled"><?= $t('Cancelled', 'Imeghairiwa') ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small"><?= $t('Type', 'Aina') ?></label>
                <select class="form-select" id="fType">
                    <option value=""><?= $t('All', 'Zote') ?></option>
                    <option value="regular"><?= $t('Regular', 'Kawaida') ?></option>
                    <option value="special"><?= $t('Special', 'Maalum') ?></option>
                    <option value="agm"><?= $t('AGM', 'Mkutano Mkuu') ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small"><?= $t('From', 'Kuanzia') ?></label>
                <input type="date" class="form-control" id="fFrom">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small"><?= $t('To', 'Hadi') ?></label>
                <input type="date" class="form-control" id="fTo">
            </div>
        </div></div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table id="meetingsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small text-center">
                        <tr>
                            <th style="width:50px">#</th>
                            <th class="text-start"><?= $t('Title', 'Kichwa') ?></th>
                            <th><?= $t('Date', 'Tarehe') ?></th>
                            <th><?= $t('Type', 'Aina') ?></th>
                            <th><?= $t('Attendance', 'Mahudhurio') ?></th>
                            <th><?= $t('Status', 'Hali') ?></th>
                            <th class="text-end"><?= $t('Actions', 'Vitendo') ?></th>
                        </tr>
                    </thead>
                    <tbody class="small"></tbody>
                </table>
            </div>
            <div class="p-2 d-md-none" id="meetingsCards"></div>
        </div>
    </div>
</div>

<!-- Create / Edit modal -->
<div class="modal fade" id="meetingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title" id="meetingModalLabel"><i class="bi bi-calendar-plus me-2"></i><?= $t('New Meeting', 'Mkutano Mpya') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="meetingForm">
                <input type="hidden" name="meeting_id" id="meeting_id" value="">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold"><?= $t('Title / Heading', 'Kichwa cha Mkutano') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="m_title" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold"><?= $t('Type', 'Aina') ?></label>
                            <select name="meeting_type" id="m_type" class="form-select">
                                <option value="regular"><?= $t('Regular', 'Kawaida') ?></option>
                                <option value="special"><?= $t('Special', 'Maalum') ?></option>
                                <option value="agm"><?= $t('AGM', 'Mkutano Mkuu') ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold"><?= $t('Date', 'Tarehe') ?> <span class="text-danger">*</span></label>
                            <input type="date" name="meeting_date" id="m_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold"><?= $t('Time', 'Muda') ?></label>
                            <input type="time" name="meeting_time" id="m_time" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold"><?= $t('Status', 'Hali') ?></label>
                            <select name="status" id="m_status" class="form-select">
                                <option value="scheduled"><?= $t('Scheduled', 'Imepangwa') ?></option>
                                <option value="held"><?= $t('Held', 'Imefanyika') ?></option>
                                <option value="cancelled"><?= $t('Cancelled', 'Imeghairiwa') ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $t('Location', 'Mahali') ?></label>
                            <input type="text" name="location" id="m_location" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $t('Agenda', 'Ajenda') ?></label>
                            <textarea name="agenda" id="m_agenda" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $t('Minutes / Notes', 'Muhtasari / Maelezo') ?></label>
                            <textarea name="minutes" id="m_minutes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-12" id="attachWrap">
                            <label class="form-label small fw-bold"><i class="bi bi-paperclip me-1"></i><?= $t('Supporting Documents', 'Nyaraka za Ambatisho') ?></label>
                            <div class="row g-2">
                                <div class="col-md-5"><input type="text" class="form-control form-control-sm" name="attachment_names[]" placeholder="<?= $t('Doc name', 'Jina la hati') ?>"></div>
                                <div class="col-md-7"><input type="file" class="form-control form-control-sm" name="attachments[]"></div>
                            </div>
                            <small class="text-muted"><?= $t('Optional. PDF, Word or image (max 10MB).', 'Si lazima. PDF, Word au picha (max 10MB).') ?></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal"><?= $t('Cancel', 'Ghairi') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4"><i class="bi bi-check-circle me-1"></i><?= $t('Save Meeting', 'Hifadhi Mkutano') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
const CAN = { edit: <?= $can_edit ? 'true' : 'false' ?>, del: <?= $can_delete ? 'true' : 'false' ?> };
const VIEW_URL = '<?= getUrl('meeting_view') ?>';

function typeBadge(t){ return t==='agm'?'danger':(t==='special'?'warning':'secondary'); }
function statusBadge(s){ return s==='held'?'success':(s==='cancelled'?'danger':'info'); }
function fmtDate(d){ return d ? new Date(d).toLocaleDateString() : '—'; }
function esc(s){ return s==null?'':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

$(function(){
    const table = $('#meetingsTable').DataTable({
        serverSide: true, processing: true, responsive: true,
        ajax: {
            url: '/api/get_meetings',
            data: d => { d.status=$('#fStatus').val(); d.type=$('#fType').val(); d.date_from=$('#fFrom').val(); d.date_to=$('#fTo').val(); },
            dataSrc: j => {
                $('#stat-total').text(j.statTotal||0); $('#stat-month').text(j.statMonth||0);
                $('#stat-scheduled').text(j.statScheduled||0); $('#stat-held').text(j.statHeld||0);
                renderCards(j.data||[]);
                return j.data;
            }
        },
        columns: [
            { data:null, render:(d,t,r,m)=>`<strong>${m.row+1}</strong>` },
            { data:'title', className:'text-start', render:(d,t,r)=>`<a href="${VIEW_URL}?id=${r.id}" class="fw-semibold text-decoration-none">${esc(d)}</a>${r.location?`<div class="text-muted" style="font-size:11px;"><i class="bi bi-geo-alt me-1"></i>${esc(r.location)}</div>`:''}` },
            { data:'meeting_date', render:d=>fmtDate(d) },
            { data:'meeting_type', render:d=>`<span class="badge bg-${typeBadge(d)}-subtle text-${typeBadge(d)} border border-${typeBadge(d)}-subtle text-uppercase">${d}</span>` },
            { data:'present_count', render:d=>`<span class="badge bg-light text-dark border"><i class="bi bi-people me-1"></i>${d||0}</span>` },
            { data:'status', render:d=>`<span class="badge bg-${statusBadge(d)}">${d}</span>` },
            { data:null, className:'text-end', render:(d,t,r)=>actions(r) }
        ],
        order: [], dom:'lrtp',
        language: { processing: isSw?'Inachakata...':'Processing...', paginate:{ next:'>', previous:'<' } }
    });

    function actions(r){
        let h = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0">`;
        h += `<li><a class="dropdown-item" href="${VIEW_URL}?id=${r.id}"><i class="bi bi-eye me-1"></i>${isSw?'Angalia':'View'}</a></li>`;
        if (CAN.edit) h += `<li><a class="dropdown-item" href="javascript:void(0)" onclick='openEditMeeting(${r.id})'><i class="bi bi-pencil me-1"></i>${isSw?'Hariri':'Edit'}</a></li>`;
        if (CAN.del)  h += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteMeeting(${r.id})"><i class="bi bi-trash me-1"></i>${isSw?'Futa':'Delete'}</a></li>`;
        h += `</ul></div>`;
        return h;
    }

    function renderCards(rows){
        const $w = $('#meetingsCards'); $w.empty();
        if(!rows.length){ $w.html(`<div class="text-center text-muted py-4">${isSw?'Hakuna mikutano.':'No meetings.'}</div>`); return; }
        rows.forEach(r=>{
            $w.append(`<div class="border rounded shadow-sm mb-2 p-2 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                    <a href="${VIEW_URL}?id=${r.id}" class="fw-semibold text-decoration-none">${esc(r.title)}</a>
                    <span class="badge bg-${statusBadge(r.status)}">${r.status}</span>
                </div>
                <div class="small text-muted mt-1"><i class="bi bi-calendar me-1"></i>${fmtDate(r.meeting_date)} &middot; <span class="text-uppercase">${r.meeting_type}</span> &middot; <i class="bi bi-people me-1"></i>${r.present_count||0}</div>
            </div>`);
        });
    }

    $('#fStatus,#fType,#fFrom,#fTo').on('change', ()=>table.ajax.reload());

    $('#meetingForm').on('submit', function(e){
        e.preventDefault();
        const $btn = $(this).find('button[type=submit]'); const old = $btn.html();
        $btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
        $.ajax({
            url:'/actions/save_meeting', type:'POST', data:new FormData(this), processData:false, contentType:false,
            success:res=>{
                if(res.success){ $('#meetingModal').modal('hide'); table.ajax.reload(); Swal.fire({icon:'success',title:isSw?'Imehifadhiwa':'Saved',timer:1300,showConfirmButton:false}); this.reset(); $('#meeting_id').val(''); }
                else Swal.fire('Error', res.message||'Error','error');
                $btn.prop('disabled',false).html(old);
            },
            error:()=>{ Swal.fire('Error','Server error','error'); $btn.prop('disabled',false).html(old); }
        });
    });

    window.deleteMeeting = function(id){
        Swal.fire({ title:isSw?'Una uhakika?':'Are you sure?', text:isSw?'Mkutano huu utafutwa.':'This meeting will be deleted.', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:isSw?'Ndio, Futa':'Yes, delete' })
        .then(r=>{ if(r.isConfirmed) $.post('/actions/delete_meeting',{id},res=>{ if(res.success){ $('#meetingsTable').DataTable().ajax.reload(); Swal.fire({icon:'success',title:isSw?'Imefutwa':'Deleted',timer:1200,showConfirmButton:false}); } else Swal.fire('Error',res.message,'error'); },'json'); });
    };
    window.__mtable = table;
});

function openCreateMeeting(){
    document.getElementById('meetingForm').reset();
    document.getElementById('meeting_id').value='';
    document.getElementById('attachWrap').style.display='';
    document.getElementById('meetingModalLabel').innerHTML='<i class="bi bi-calendar-plus me-2"></i>'+(isSw?'Mkutano Mpya':'New Meeting');
}
function openEditMeeting(id){
    fetch('/api/get_meeting_details?id='+id).then(r=>r.json()).then(j=>{
        if(!j.success){ Swal.fire('Error', j.message||'Not found','error'); return; }
        const m=j.meeting;
        document.getElementById('meeting_id').value=m.id;
        document.getElementById('m_title').value=m.title||'';
        document.getElementById('m_type').value=m.meeting_type||'regular';
        document.getElementById('m_date').value=m.meeting_date||'';
        document.getElementById('m_time').value=m.meeting_time||'';
        document.getElementById('m_status').value=m.status||'scheduled';
        document.getElementById('m_location').value=m.location||'';
        document.getElementById('m_agenda').value=m.agenda||'';
        document.getElementById('m_minutes').value=m.minutes||'';
        document.getElementById('meetingModalLabel').innerHTML='<i class="bi bi-pencil me-2"></i>'+(isSw?'Hariri Mkutano':'Edit Meeting');
        new bootstrap.Modal(document.getElementById('meetingModal')).show();
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
