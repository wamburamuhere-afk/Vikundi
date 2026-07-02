<?php
// app/constant/voting/manage_voting.php — leadership: create and run votes.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();

requireViewPermission('manage_voting');
$can_edit   = canEdit('manage_voting');
$can_delete = canDelete('manage_voting');

// Auto-close any vote whose deadline has passed.
$pdo->prepare("UPDATE votes SET status='closed' WHERE status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")->execute();

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #6f42c1 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div class="text-center text-md-start">
                <h3 class="fw-bold mb-1" style="color:#6f42c1;"><i class="bi bi-check2-square me-2"></i><?= $t('Manage Voting', 'Simamia Kura') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Create group votes, open and close them, and view results', 'Tengeneza kura za kikundi, zifungue na zifunge, na uone matokeo') ?></p>
            </div>
            <?php if ($can_edit): ?>
            <button type="button" class="btn rounded-pill px-4 shadow-sm text-white" style="background:#6f42c1;" onclick="openCreate()"><i class="bi bi-plus-lg me-2"></i><?= $t('New Vote', 'Kura Mpya') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div id="votesList"><div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span><?= $t('Loading...', 'Inapakia...') ?></div></div>
    </div></div>
</div>

<!-- Create / Edit modal -->
<div class="modal fade" id="voteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white border-0" style="background:#6f42c1;">
                <h5 class="modal-title" id="voteModalLabel"><i class="bi bi-plus-circle me-2"></i><?= $t('New Vote', 'Kura Mpya') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="voteForm">
                <input type="hidden" name="vote_id" id="vote_id" value="">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold"><?= $t('Title', 'Kichwa') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="v_title" class="form-control" required placeholder="<?= $t('e.g. Election: New Treasurer', 'Mfano: Uchaguzi: Mweka Hazina') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold"><?= $t('Type', 'Aina') ?></label>
                            <select name="vote_type" id="v_type" class="form-select" onchange="toggleType()">
                                <option value="candidate"><?= $t('Election (candidates)', 'Uchaguzi (wagombea)') ?></option>
                                <option value="motion"><?= $t('Motion (Yes/No)', 'Hoja (Ndio/Hapana)') ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $t('Description', 'Maelezo') ?></label>
                            <textarea name="description" id="v_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold"><?= $t('Closes at (optional)', 'Inafunga (si lazima)') ?></label>
                            <input type="datetime-local" name="closes_at" id="v_closes" class="form-control">
                            <small class="text-muted"><?= $t('Auto-closes at this time.', 'Hufunga yenyewe wakati huu.') ?></small>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="publish_results" id="v_publish" value="1">
                                <label class="form-check-label small" for="v_publish"><?= $t('Publish results to members after closing', 'Onyesha matokeo kwa wanachama baada ya kufunga') ?></label>
                            </div>
                        </div>

                        <div class="col-12" id="candidateBox">
                            <label class="form-label small fw-bold"><?= $t('Candidates / Options', 'Wagombea / Machaguo') ?></label>
                            <div id="optionRows"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="addOption()"><i class="bi bi-plus"></i> <?= $t('Add candidate', 'Ongeza mgombea') ?></button>
                        </div>
                        <div class="col-12 d-none" id="motionBox">
                            <div class="alert alert-light border small mb-0"><i class="bi bi-info-circle me-1"></i><?= $t('A motion uses fixed choices: Yes, No, Abstain.', 'Hoja hutumia machaguo: Ndio, Hapana, Sitoi kauli.') ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal"><?= $t('Cancel', 'Ghairi') ?></button>
                    <button type="submit" class="btn btn-sm rounded-pill px-4 text-white" style="background:#6f42c1;"><i class="bi bi-check-circle me-1"></i><?= $t('Save Vote', 'Hifadhi Kura') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Results modal -->
<div class="modal fade" id="resultsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white border-0"><h5 class="modal-title"><i class="bi bi-bar-chart me-2"></i><?= $t('Results', 'Matokeo') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4" id="resultsBody"></div>
        </div>
    </div>
</div>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
const CAN = { edit: <?= $can_edit ? 'true':'false' ?>, del: <?= $can_delete ? 'true':'false' ?> };
function esc(s){ return s==null?'':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function statusBadge(s){ return s==='open'?'success':(s==='closed'?'secondary':'warning'); }

$(function(){ loadVotes(); });

function loadVotes(){
    fetch('/api/get_votes').then(r=>r.json()).then(j=>{
        const rows = j.data||[]; const $w = $('#votesList');
        if(!rows.length){ $w.html(`<div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>${isSw?'Hakuna kura bado.':'No votes yet.'}</div>`); return; }
        let h = '<div class="table-responsive"><table class="table table-hover align-middle"><thead class="bg-light text-muted small text-center"><tr>'
            + `<th class="text-start">${isSw?'Kichwa':'Title'}</th><th>${isSw?'Aina':'Type'}</th><th>${isSw?'Waliopiga':'Turnout'}</th><th>${isSw?'Hali':'Status'}</th><th class="text-end">${isSw?'Vitendo':'Actions'}</th></tr></thead><tbody class="small">`;
        rows.forEach(v=>{
            const turnout = `${v.voted_count||0}/${v.eligible_count||0}`;
            h += `<tr>
                <td class="text-start"><span class="fw-semibold">${esc(v.title)}</span><div class="text-muted" style="font-size:11px;">${v.option_count||0} ${isSw?'machaguo':'options'}</div></td>
                <td class="text-center"><span class="badge bg-light text-dark border text-uppercase">${v.vote_type==='motion'?(isSw?'Hoja':'Motion'):(isSw?'Uchaguzi':'Election')}</span></td>
                <td class="text-center">${v.status==='draft'?'—':turnout}</td>
                <td class="text-center"><span class="badge bg-${statusBadge(v.status)}">${v.status}</span></td>
                <td class="text-end">${actions(v)}</td></tr>`;
        });
        h += '</tbody></table></div>';
        $w.html(h);
    }).catch(()=>$('#votesList').html(`<div class="text-danger text-center py-4">${isSw?'Hitilafu ya seva':'Server error'}</div>`));
}

function actions(v){
    let h = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0">`;
    if(v.status==='closed' || v.status==='open') h += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="showResults(${v.id})"><i class="bi bi-bar-chart me-1"></i>${v.status==='open'?(isSw?'Waliopiga':'Turnout'):(isSw?'Matokeo':'Results')}</a></li>`;
    if(CAN.edit && v.status==='draft') h += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="editVote(${v.id})"><i class="bi bi-pencil me-1"></i>${isSw?'Hariri':'Edit'}</a></li>`;
    if(CAN.edit && v.status==='draft') h += `<li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="setStatus(${v.id},'open')"><i class="bi bi-unlock me-1"></i>${isSw?'Fungua':'Open'}</a></li>`;
    if(CAN.edit && v.status==='open')  h += `<li><a class="dropdown-item text-secondary" href="javascript:void(0)" onclick="setStatus(${v.id},'closed')"><i class="bi bi-lock me-1"></i>${isSw?'Funga':'Close'}</a></li>`;
    if(CAN.del  && v.status!=='open')  h += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteVote(${v.id})"><i class="bi bi-trash me-1"></i>${isSw?'Futa':'Delete'}</a></li>`;
    return h + `</ul></div>`;
}

function optionRow(val){ return `<div class="input-group input-group-sm mb-2"><span class="input-group-text"><i class="bi bi-person"></i></span><input type="text" class="form-control" name="option_labels[]" placeholder="${isSw?'Jina la mgombea':'Candidate name'}" value="${esc(val||'')}"><button type="button" class="btn btn-outline-danger" onclick="$(this).closest('.input-group').remove()"><i class="bi bi-x"></i></button></div>`; }
function addOption(val){ $('#optionRows').append(optionRow(val)); }
function toggleType(){ const m = $('#v_type').val()==='motion'; $('#motionBox').toggleClass('d-none', !m); $('#candidateBox').toggleClass('d-none', m); }

function openCreate(){
    document.getElementById('voteForm').reset(); $('#vote_id').val(''); $('#optionRows').empty(); addOption(); addOption();
    $('#voteModalLabel').html('<i class="bi bi-plus-circle me-2"></i>'+(isSw?'Kura Mpya':'New Vote')); toggleType();
    new bootstrap.Modal(document.getElementById('voteModal')).show();
}
function editVote(id){
    fetch('/api/get_vote?id='+id).then(r=>r.json()).then(j=>{
        if(!j.success){ Swal.fire('Error', j.message||'Not found','error'); return; }
        const v=j.vote; document.getElementById('voteForm').reset();
        $('#vote_id').val(v.id); $('#v_title').val(v.title); $('#v_desc').val(v.description||''); $('#v_type').val(v.vote_type);
        $('#v_publish').prop('checked', v.publish_results==1);
        if(v.closes_at) $('#v_closes').val(v.closes_at.replace(' ','T').slice(0,16));
        $('#optionRows').empty();
        if(v.vote_type==='candidate'){ (v.options||[]).forEach(o=>addOption(o.label)); if(!(v.options||[]).length){ addOption(); addOption(); } }
        toggleType();
        $('#voteModalLabel').html('<i class="bi bi-pencil me-2"></i>'+(isSw?'Hariri Kura':'Edit Vote'));
        new bootstrap.Modal(document.getElementById('voteModal')).show();
    });
}

$('#voteForm').on('submit', function(e){
    e.preventDefault();
    const $btn=$(this).find('button[type=submit]'); const old=$btn.html(); $btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post('/actions/save_vote', $(this).serialize(), res=>{
        if(res.success){ bootstrap.Modal.getInstance(document.getElementById('voteModal')).hide(); loadVotes(); Swal.fire({icon:'success',title:isSw?'Imehifadhiwa':'Saved',timer:1200,showConfirmButton:false}); }
        else Swal.fire('Error', res.message||'Error','error');
        $btn.prop('disabled',false).html(old);
    },'json').fail(()=>{ Swal.fire('Error','Server error','error'); $btn.prop('disabled',false).html(old); });
});

function setStatus(id, status){
    const isOpen = status==='open';
    Swal.fire({ title:isOpen?(isSw?'Fungua kura?':'Open this vote?'):(isSw?'Funga kura?':'Close this vote?'),
        text:isOpen?(isSw?'Wanachama wataweza kupiga kura. Waliostahili watahifadhiwa sasa.':'Members will be able to vote. Eligible members are locked in now.'):(isSw?'Hakuna kura zaidi zitakubaliwa.':'No more votes will be accepted.'),
        icon:'question', showCancelButton:true, confirmButtonText:isSw?'Ndio':'Yes' })
    .then(r=>{ if(!r.isConfirmed) return;
        $.post('/actions/set_vote_status', {vote_id:id, status:status}, res=>{
            if(res.success){ loadVotes(); Swal.fire({icon:'success',title:isSw?'Imefanyika':'Done',text:res.message,timer:1300,showConfirmButton:false}); }
            else Swal.fire('Error', res.message,'error');
        },'json').fail(()=>Swal.fire('Error','Server error','error'));
    });
}

function deleteVote(id){
    Swal.fire({ title:isSw?'Una uhakika?':'Are you sure?', text:isSw?'Kura hii itafutwa kabisa.':'This vote will be permanently deleted.', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:isSw?'Ndio, Futa':'Yes, delete' })
    .then(r=>{ if(!r.isConfirmed) return;
        $.post('/actions/delete_vote', {id:id}, res=>{ if(res.success){ loadVotes(); Swal.fire({icon:'success',title:isSw?'Imefutwa':'Deleted',timer:1200,showConfirmButton:false}); } else Swal.fire('Error',res.message,'error'); },'json');
    });
}

function showResults(id){
    $('#resultsBody').html(`<div class="text-center py-4"><span class="spinner-border"></span></div>`);
    new bootstrap.Modal(document.getElementById('resultsModal')).show();
    fetch('/api/get_vote_results?id='+id).then(r=>r.json()).then(j=>{
        if(!j.success){ $('#resultsBody').html(`<div class="text-danger">${esc(j.message||'Error')}</div>`); return; }
        let h = `<h6 class="fw-bold mb-2">${esc(j.title)}</h6>`;
        h += `<div class="mb-3 small text-muted"><i class="bi bi-people me-1"></i>${isSw?'Waliopiga kura':'Turnout'}: <b>${j.turnout.voted}/${j.turnout.eligible}</b> (${j.turnout.percent}%)</div>`;
        if(j.can_see_tally && j.tally){
            const max = Math.max(1, ...j.tally.map(t=>t.votes));
            j.tally.forEach(t=>{
                const pct = Math.round((t.votes/max)*100);
                h += `<div class="mb-2"><div class="d-flex justify-content-between small"><span>${esc(t.label)}</span><span class="fw-bold">${t.votes}</span></div>
                    <div class="progress" style="height:8px;"><div class="progress-bar" style="width:${pct}%;background:#6f42c1;"></div></div></div>`;
            });
        } else if(j.status==='open'){
            h += `<div class="alert alert-warning small mb-0"><i class="bi bi-lock me-1"></i>${isSw?'Matokeo yatapatikana baada ya kura kufungwa.':'Results are available once the vote is closed.'}</div>`;
        } else {
            h += `<div class="alert alert-light border small mb-0">${isSw?'Kura haijafunguliwa bado.':'This vote has not been opened yet.'}</div>`;
        }
        $('#resultsBody').html(h);
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
