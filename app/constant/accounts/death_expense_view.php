<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('death_expenses');

$is_sw       = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$can_review  = canReview('death_expenses');
$can_approve = canApprove('death_expenses');

$id = intval($_GET['id'] ?? 0);
if (!$id) { redirectTo('accounts/death_expenses'); }

global $pdo;

$stmt = $pdo->prepare("
    SELECT de.*,
           c.customer_name, c.first_name, c.last_name, c.phone,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username, rc.role_name AS creator_role,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM death_expenses de
      JOIN customers c   ON de.member_id   = c.customer_id
      LEFT JOIN users uc ON de.created_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id     = rc.role_id
      LEFT JOIN users ur ON de.reviewed_by = ur.user_id
      LEFT JOIN roles rr ON ur.role_id     = rr.role_id
      LEFT JOIN users ua ON de.approved_by = ua.user_id
      LEFT JOIN roles ra ON ua.role_id     = ra.role_id
     WHERE de.id = ?
");
$stmt->execute([$id]);
$de = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$de) { redirectTo('accounts/death_expenses'); }

$settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency'] ?? 'TZS';

$status = $de['status'] ?? 'pending';
$badge_map = ['pending'=>'warning','reviewed'=>'info','approved'=>'success','rejected'=>'danger','inactive'=>'secondary'];
$badge = $badge_map[$status] ?? 'secondary';

$creator_name  = trim($de['creator_name'])  ?: ($de['creator_username'] ?? '—');
$reviewer_name = trim($de['reviewer_name']) ?: '';
$approver_name = trim($de['approver_name']) ?: '';
$member_name   = $de['customer_name'] ?: trim($de['first_name'].' '.$de['last_name']);

$wf = [
    'created_by_name'  => $creator_name,
    'created_by_role'  => $de['creator_role']  ?? '',
    'created_at'       => $de['created_at']    ?? '',
    'reviewed_by_name' => $reviewer_name,
    'reviewed_by_role' => $de['reviewer_role'] ?? '',
    'reviewed_at'      => $de['reviewed_at']   ?? '',
    'approved_by_name' => $approver_name,
    'approved_by_role' => $de['approver_role'] ?? '',
    'approved_at'      => $de['approved_at']   ?? '',
];

includeHeader();
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold">
                <?= $is_sw ? 'Maelezo ya Gharama ya Msiba' : 'Death Expense Details' ?>
                <span class="badge bg-<?= $badge ?> ms-2"><?= ucfirst($status) ?></span>
            </h4>
            <p class="text-muted small mb-0">DE #<?= $de['id'] ?> &mdash; <?= htmlspecialchars($member_name) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= getUrl('accounts/death_expenses') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= $is_sw ? 'Rudi' : 'Back' ?>
            </a>
            <?php if ($status === 'pending' && $can_review): ?>
            <button class="btn btn-primary btn-sm" onclick="reviewDE(<?= $id ?>)">
                <i class="bi bi-clipboard-check me-1"></i><?= $is_sw ? 'Pitia' : 'Mark Reviewed' ?>
            </button>
            <?php endif; ?>
            <?php if ($status === 'reviewed' && $can_approve): ?>
            <button class="btn btn-success btn-sm" onclick="approveDE(<?= $id ?>)">
                <i class="bi bi-check2-circle me-1"></i><?= $is_sw ? 'Idhinisha' : 'Approve' ?>
            </button>
            <?php endif; ?>
            <a href="<?= getUrl('print_death_expense') ?>?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i><?= $is_sw ? 'Chapa' : 'Print' ?>
            </a>
        </div>
    </div>

    <?php require WORKFLOW_AUDIT_PANEL_FILE; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-bold text-danger border-0">
                    <i class="bi bi-heart-pulse me-1"></i><?= $is_sw ? 'Maelezo ya Msiba' : 'Death Expense Details' ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted small fw-semibold" style="width:42%"><?= $is_sw?'Mwanachama':'Member' ?></td>
                            <td class="fw-bold"><?= safe_output($member_name) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Jina la Marehemu':'Deceased Name' ?></td>
                            <td><?= safe_output($de['deceased_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Uhusiano':'Relationship' ?></td>
                            <td><?= safe_output(ucfirst($de['deceased_type'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Kiasi':'Amount' ?></td>
                            <td class="fw-bold text-danger"><?= $currency ?> <?= number_format($de['amount'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Tarehe':'Date' ?></td>
                            <td><?= date('d M Y', strtotime($de['expense_date'])) ?></td>
                        </tr>
                        <?php if (!empty($de['description'])): ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw?'Maelezo':'Description' ?></td>
                            <td><?= htmlspecialchars($de['description']) ?></td>
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
                        ['pending',  'bi-pencil-square', 'warning', $is_sw?'Imeundwa':'Created',       $creator_name,  $de['creator_role']??'',  $de['created_at']??''],
                        ['reviewed', 'bi-clipboard-check','info',   $is_sw?'Imepitwa':'Reviewed',       $reviewer_name, $de['reviewer_role']??'', $de['reviewed_at']??''],
                        ['approved', 'bi-check2-all',    'success', $is_sw?'Imeidhinishwa':'Approved',  $approver_name, $de['approver_role']??'', $de['approved_at']??''],
                    ];
                    $order = ['pending'=>0,'reviewed'=>1,'approved'=>2];
                    $cur_order = $order[$status] ?? 0;
                    foreach ($steps as $i => [$s, $icon, $color, $label, $person, $role, $dt]):
                    ?>
                    <div class="d-flex align-items-start mb-3">
                        <div class="me-3 mt-1">
                            <span class="badge rounded-circle p-2 bg-<?= (!empty($person)) ? $color : 'light text-muted' ?>" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi <?= $icon ?> small"></i>
                            </span>
                        </div>
                        <div>
                            <div class="fw-semibold small text-<?= (!empty($person))?$color:'muted' ?>"><?= $label ?></div>
                            <?php if (!empty($person)): ?>
                                <div class="small"><?= htmlspecialchars($person) ?><?= $role ? ' &mdash; <span class="text-muted">'.htmlspecialchars($role).'</span>':'' ?></div>
                                <?php if ($dt): ?><div style="font-size:0.72rem;" class="text-muted"><?= date('d M Y, h:i A', strtotime($dt)) ?></div><?php endif; ?>
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
function _dePost(url, id, msg) {
    Swal.fire({ title: msg, didOpen: () => Swal.showLoading() });
    $.post(url, { id: id }, function(r) {
        if (r.success) {
            Swal.fire({ icon:'success', title:'Done', text:r.message, timer:1500, showConfirmButton:false }).then(() => location.reload());
        } else { Swal.fire('Error', r.message, 'error'); }
    }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
}
function reviewDE(id) {
    Swal.fire({ title:'<?= $is_sw?"Pitia gharama hii?":"Mark as Reviewed?" ?>', icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio":"Yes, Reviewed" ?>'
    }).then(r => { if (r.isConfirmed) _dePost('<?= getUrl('api/review_death_expense') ?>', id, '<?= $is_sw?"Inatuma...":"Submitting..." ?>'); });
}
function approveDE(id) {
    Swal.fire({ title:'<?= $is_sw?"Idhinisha gharama hii?":"Approve this expense?" ?>',
        text: '<?= $is_sw?"Salio la kikundi litapunguzwa.":"Group balance will be deducted." ?>',
        icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio, Idhinisha":"Yes, Approve" ?>', confirmButtonColor:'#198754'
    }).then(r => { if (r.isConfirmed) _dePost('<?= getUrl('actions/approve_death_expense') ?>', id, '<?= $is_sw?"Inaidhinisha...":"Approving..." ?>'); });
}
</script>

<?php includeFooter(); ?>
