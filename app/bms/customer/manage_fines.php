<?php
// app/bms/customer/manage_fines.php — leadership: view & manage member fines.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();

requireViewPermission('manage_fines');
$can_edit = canEdit('manage_fines');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$members_list = $pdo->query("
    SELECT customer_id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
      FROM customers WHERE (status IS NULL OR status <> 'deleted')
     ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-left:5px solid #dc3545 !important;">
                <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h3 class="fw-bold mb-1 text-danger"><i class="bi bi-cash-coin me-2"></i><?= $t('Fines', 'Faini') ?></h3>
                        <p class="text-muted mb-0 small"><?= $t('View member fines, mark them paid or waive them', 'Angalia faini za wanachama, weka zimelipwa au zisamehe') ?></p>
                    </div>
                    <button type="button" class="btn btn-outline-primary rounded-pill px-4" onclick="printFinesRegister()"><i class="bi bi-printer me-2"></i><?= $t('Print', 'Chapisha') ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['stat-pending', 'text-warning', 'bi-hourglass-split', $t('Pending', 'Zinasubiri')],
            ['stat-paid', 'text-success', 'bi-check2-circle', $t('Paid', 'Zilizolipwa')],
            ['stat-waived', 'text-secondary', 'bi-slash-circle', $t('Waived', 'Zilizosamehewa')],
        ] as [$id, $color, $icon, $label]): ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="d-flex justify-content-between">
                    <div><h4 class="mb-0 fw-bold <?= $color ?>"><small class="text-muted">TSh</small> <span id="<?= $id ?>">0.00</span></h4><p class="mb-0 text-muted small"><?= $label ?></p></div>
                    <div class="align-self-center"><i class="bi <?= $icon ?> <?= $color ?>" style="font-size:2rem;opacity:.3;"></i></div>
                </div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body"><div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold small"><?= $t('Member', 'Mwanachama') ?></label>
                <select class="form-select" id="fMember">
                    <option value=""><?= $t('All members', 'Wanachama wote') ?></option>
                    <?php foreach ($members_list as $m): ?>
                        <option value="<?= (int) $m['customer_id'] ?>"><?= htmlspecialchars($m['name'] !== '' ? $m['name'] : ('Member #' . $m['customer_id'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small"><?= $t('Status', 'Hali') ?></label>
                <select class="form-select" id="fStatus">
                    <option value=""><?= $t('All', 'Zote') ?></option>
                    <option value="pending"><?= $t('Pending', 'Inasubiri') ?></option>
                    <option value="paid"><?= $t('Paid', 'Imelipwa') ?></option>
                    <option value="waived"><?= $t('Waived', 'Imesamehewa') ?></option>
                </select>
            </div>
        </div></div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="table-responsive d-none d-md-block">
            <table id="finesTable" class="table table-hover align-middle" style="width:100%">
                <thead class="bg-light text-muted small text-center">
                    <tr>
                        <th style="width:50px">#</th>
                        <th class="text-start"><?= $t('Member', 'Mwanachama') ?></th>
                        <th class="text-start"><?= $t('Reason', 'Sababu') ?></th>
                        <th><?= $t('Amount', 'Kiasi') ?></th>
                        <th><?= $t('Date', 'Tarehe') ?></th>
                        <th><?= $t('Status', 'Hali') ?></th>
                        <?php if ($can_edit): ?><th class="text-end"><?= $t('Actions', 'Vitendo') ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="small"></tbody>
            </table>
        </div>
        <div class="p-2 d-md-none" id="finesCards"></div>
    </div></div>
</div>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
const CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;
function money(v){ return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2}); }
function badge(s){ return s==='paid'?'success':(s==='waived'?'secondary':'warning'); }
function esc(s){ return s==null?'':String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function printFinesRegister(){
    // Open the printable register honouring the current Member + Status filters.
    const p = new URLSearchParams({ member_id: $('#fMember').val(), status: $('#fStatus').val() });
    window.open('<?= getUrl('fines_print') ?>?' + p.toString(), '_blank');
}

$(function(){
    const table = $('#finesTable').DataTable({
        serverSide:true, processing:true, responsive:true,
        ajax:{
            url:'/api/get_fines',
            data:d=>{ d.member_id=$('#fMember').val(); d.status=$('#fStatus').val(); },
            dataSrc:j=>{
                $('#stat-pending').text(money(j.totalPending)); $('#stat-paid').text(money(j.totalPaid)); $('#stat-waived').text(money(j.totalWaived));
                renderCards(j.data||[]);
                return j.data;
            }
        },
        columns:[
            { data:null, render:(d,t,r,m)=>`<strong>${m.row+1}</strong>` },
            { data:'member_name', className:'text-start', render:(d,t,r)=>`${esc(d||('Member #'+r.customer_id))}${r.member_phone?`<div class="text-muted" style="font-size:11px;">${esc(r.member_phone)}</div>`:''}` },
            { data:'reason', className:'text-start', render:d=>esc(d||'—') },
            { data:'amount', render:d=>`<strong class="text-danger">TSh ${money(d)}</strong>` },
            { data:'created_at', render:d=>d?new Date(d).toLocaleDateString():'—' },
            { data:'status', render:d=>`<span class="badge bg-${badge(d)}">${d}</span>` },
            <?php if ($can_edit): ?>{ data:null, className:'text-end', render:(d,t,r)=>actions(r) }<?php endif; ?>
        ],
        order:[], dom:'lrtp',
        language:{ processing:isSw?'Inachakata...':'Processing...', paginate:{next:'>',previous:'<'} }
    });

    function actions(r){
        if(!CAN_EDIT) return '';
        let h = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0">`;
        if(r.status!=='paid')   h += `<li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="setStatus(${r.fine_id},'paid')"><i class="bi bi-check2-circle me-1"></i>${isSw?'Weka Imelipwa':'Mark Paid'}</a></li>`;
        if(r.status!=='waived') h += `<li><a class="dropdown-item text-secondary" href="javascript:void(0)" onclick="setStatus(${r.fine_id},'waived')"><i class="bi bi-slash-circle me-1"></i>${isSw?'Samehe':'Waive'}</a></li>`;
        if(r.status!=='pending')h += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="javascript:void(0)" onclick="setStatus(${r.fine_id},'pending')"><i class="bi bi-arrow-counterclockwise me-1"></i>${isSw?'Rudisha Inasubiri':'Mark Pending'}</a></li>`;
        return h + `</ul></div>`;
    }

    function renderCards(rows){
        const $w=$('#finesCards'); $w.empty();
        if(!rows.length){ $w.html(`<div class="text-center text-muted py-4">${isSw?'Hakuna faini.':'No fines.'}</div>`); return; }
        rows.forEach(r=>{
            let btns='';
            if(CAN_EDIT){
                if(r.status!=='paid')   btns+=`<button class="btn btn-sm btn-outline-success" onclick="setStatus(${r.fine_id},'paid')"><i class="bi bi-check2-circle"></i></button> `;
                if(r.status!=='waived') btns+=`<button class="btn btn-sm btn-outline-secondary" onclick="setStatus(${r.fine_id},'waived')"><i class="bi bi-slash-circle"></i></button>`;
            }
            $w.append(`<div class="border rounded shadow-sm mb-2 p-2 bg-white">
                <div class="d-flex justify-content-between"><span class="fw-semibold">${esc(r.member_name||('Member #'+r.customer_id))}</span><span class="badge bg-${badge(r.status)}">${r.status}</span></div>
                <div class="small text-muted">${esc(r.reason||'—')}</div>
                <div class="d-flex justify-content-between align-items-center mt-1"><span class="fw-bold text-danger">TSh ${money(r.amount)}</span><span>${btns}</span></div>
            </div>`);
        });
    }

    $('#fMember,#fStatus').on('change', ()=>table.ajax.reload());
    window.__ftable = table;
});

function setStatus(id, status){
    const label = status==='paid'?(isSw?'imelipwa':'as paid'):(status==='waived'?(isSw?'imesamehewa':'as waived'):(isSw?'inasubiri':'as pending'));
    Swal.fire({ title:(isSw?'Thibitisha':'Confirm'), text:(isSw?'Weka faini hii ':'Set this fine ')+label+'?', icon:'question', showCancelButton:true, confirmButtonText:isSw?'Ndio':'Yes' })
    .then(r=>{ if(!r.isConfirmed) return;
        $.post('/actions/update_fine_status', { fine_id:id, status:status }, res=>{
            if(res.success){ $('#finesTable').DataTable().ajax.reload(); Swal.fire({icon:'success',title:isSw?'Imefanyika':'Done',text:res.message,timer:1300,showConfirmButton:false}); }
            else Swal.fire('Error', res.message||'Error','error');
        },'json').fail(()=>Swal.fire('Error','Server error','error'));
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
