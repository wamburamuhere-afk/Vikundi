<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';

// includeHeader() must run first — it sets the global $user_role variable
includeHeader();

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Same role-based access as petty_cash.php list ($user_role is now set by header)
$allowed_roles = ['Admin', 'Secretary', 'Katibu', 'Treasurer', 'Mhazini'];
if (!in_array($user_role ?? '', $allowed_roles) && !isAdmin()) {
    redirectTo('dashboard');
}

$can_review  = canReview('petty_cash');
$can_approve = canApprove('petty_cash');

$id = intval($_GET['id'] ?? 0);
if (!$id) { redirectTo('petty_cash'); }

global $pdo;

$stmt = $pdo->prepare("
    SELECT v.*,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username, rc.role_name AS creator_role,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM petty_cash_vouchers v
      LEFT JOIN users uc ON v.prepared_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id     = rc.role_id
      LEFT JOIN users ur ON v.reviewed_by  = ur.user_id
      LEFT JOIN roles rr ON ur.role_id     = rr.role_id
      LEFT JOIN users ua ON v.approved_by  = ua.user_id
      LEFT JOIN roles ra ON ua.role_id     = ra.role_id
     WHERE v.id = ?
");
$stmt->execute([$id]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$v) { redirectTo('petty_cash'); }

$settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency'] ?? 'TZS';

$status    = $v['status'] ?? 'pending';
$badge_map = ['pending'=>'warning','reviewed'=>'info','approved'=>'success','rejected'=>'danger'];
$badge     = $badge_map[$status] ?? 'secondary';

$creator_name  = trim($v['creator_name'])  ?: ($v['creator_username'] ?? '—');
$reviewer_name = trim($v['reviewer_name']) ?: '';
$approver_name = trim($v['approver_name']) ?: '';

$wf = [
    'created_by_name'  => $creator_name,
    'created_by_role'  => $v['creator_role']  ?? '',
    'created_at'       => $v['created_at']    ?? '',
    'reviewed_by_name' => $reviewer_name,
    'reviewed_by_role' => $v['reviewer_role'] ?? '',
    'reviewed_at'      => $v['reviewed_at']   ?? '',
    'approved_by_name' => $approver_name,
    'approved_by_role' => $v['approver_role'] ?? '',
    'approved_at'      => $v['approval_date']  ?? '',
];
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold">
                <?= $is_sw ? 'Maelezo ya Vocha' : 'Petty Cash Voucher Details' ?>
                <span class="badge bg-<?= $badge ?> ms-2"><?= ucfirst($status) ?></span>
            </h4>
            <p class="text-muted small mb-0"><?= htmlspecialchars($v['voucher_no']) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= getUrl('petty_cash') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= $is_sw ? 'Rudi' : 'Back' ?>
            </a>
            <?php if ($status === 'pending' && $can_review): ?>
            <button class="btn btn-primary btn-sm" onclick="reviewVoucher(<?= $id ?>)">
                <i class="bi bi-clipboard-check me-1"></i><?= $is_sw ? 'Pitia' : 'Mark Reviewed' ?>
            </button>
            <?php endif; ?>
            <?php if ($status === 'reviewed' && $can_approve): ?>
            <button class="btn btn-success btn-sm" onclick="approveVoucher(<?= $id ?>)">
                <i class="bi bi-check2-circle me-1"></i><?= $is_sw ? 'Idhinisha' : 'Approve' ?>
            </button>
            <?php endif; ?>
            <a href="<?= getUrl('print_petty_cash') ?>?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i><?= $is_sw ? 'Chapa' : 'Print' ?>
            </a>
        </div>
    </div>

    <?php require WORKFLOW_AUDIT_PANEL_FILE; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-bold text-purple border-0" style="color:#6f42c1;">
                    <i class="bi bi-receipt me-1"></i><?= $is_sw ? 'Maelezo ya Vocha' : 'Voucher Details' ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted small fw-semibold" style="width:42%"><?= $is_sw?'Nambari ya Vocha':'Voucher No' ?></td>
                            <td class="fw-bold"><code><?= safe_output($v['voucher_no']) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Mlipwa':'Payee' ?></td>
                            <td class="fw-bold"><?= safe_output($v['payee_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Kiasi':'Amount' ?></td>
                            <td class="fw-bold text-danger"><?= $currency ?> <?= number_format($v['amount'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Tarehe':'Date' ?></td>
                            <td><?= date('d M Y', strtotime($v['transaction_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Kategoria':'Category' ?></td>
                            <td><?= safe_output($v['category']) ?></td>
                        </tr>
                        <?php if (!empty($v['description'])): ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Maelezo':'Description' ?></td>
                            <td><?= htmlspecialchars($v['description']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Hali':'Status' ?></td>
                            <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($status) ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-bold text-primary border-0">
                    <i class="bi bi-diagram-3 me-1"></i><?= $is_sw?'Hali ya Idhini':'Approval Progress' ?>
                </div>
                <div class="card-body">
                    <?php
                    $steps = [
                        ['bi-pencil-square',  'warning', $is_sw?'Imeandaliwa':'Prepared',    $creator_name,  $v['creator_role']??'',  $v['created_at']??''],
                        ['bi-clipboard-check','info',    $is_sw?'Imepitwa':'Reviewed',        $reviewer_name, $v['reviewer_role']??'', $v['reviewed_at']??''],
                        ['bi-check2-all',     'success', $is_sw?'Imeidhinishwa':'Approved',   $approver_name, $v['approver_role']??'', $v['approval_date']??''],
                    ];
                    foreach ($steps as [$icon, $color, $label, $person, $role, $dt]):
                    ?>
                    <div class="d-flex align-items-start mb-3">
                        <div class="me-3 mt-1">
                            <span class="badge rounded-circle p-2 bg-<?= !empty($person)?$color:'light text-muted' ?>" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi <?= $icon ?> small"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small text-<?= !empty($person)?$color:'muted' ?>"><?= $label ?></div>
                            <?php if (!empty($person)): ?>
                                <div class="small"><?= htmlspecialchars($person) ?><?= $role ? ' &mdash; <span class="text-muted">'.htmlspecialchars($role).'</span>':'' ?></div>
                                <?php if ($dt): ?><div style="font-size:.72rem;" class="text-muted"><?= date('d M Y, h:i A', strtotime($dt)) ?></div><?php endif; ?>
                            <?php else: ?>
                                <div class="small text-muted fst-italic"><?= $is_sw?'Inasubiri...':'Pending...' ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function _pvPost(url, id, msg) {
    Swal.fire({ title: msg, didOpen: () => Swal.showLoading() });
    $.post(url, { id: id }, function(r) {
        if (r.success) {
            Swal.fire({ icon:'success', title:'Done', text:r.message, timer:1500, showConfirmButton:false }).then(() => location.reload());
        } else { Swal.fire('Error', r.message, 'error'); }
    }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
}
function reviewVoucher(id) {
    Swal.fire({ title:'<?= $is_sw?"Pitia vocha hii?":"Mark as Reviewed?" ?>', icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio":"Yes, Reviewed" ?>'
    }).then(r => { if (r.isConfirmed) _pvPost('<?= getUrl('api/review_petty_cash') ?>', id, '<?= $is_sw?"Inatuma...":"Submitting..." ?>'); });
}
function approveVoucher(id) {
    Swal.fire({ title:'<?= $is_sw?"Idhinisha vocha hii?":"Approve this voucher?" ?>',
        text:'<?= $is_sw?"Uhakiki huu utaruhusu malipo haya.":"This will approve the payment." ?>',
        icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio, Idhinisha":"Yes, Approve" ?>', confirmButtonColor:'#198754'
    }).then(r => { if (r.isConfirmed) _pvPost('<?= getUrl('actions/approve_petty_cash') ?>', id, '<?= $is_sw?"Inaidhinisha...":"Approving..." ?>'); });
}
</script>

<?php includeFooter(); ?>
