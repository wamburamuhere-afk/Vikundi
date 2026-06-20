<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_contributions');

$is_sw       = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$can_review  = canReview('manage_contributions');
$can_approve = canApprove('manage_contributions');
$is_admin    = isAdmin();

$id = intval($_GET['id'] ?? 0);
if (!$id) { redirectTo('manage_contributions'); }

global $pdo;

$stmt = $pdo->prepare("
    SELECT con.*,
           c.customer_name, c.first_name, c.last_name, c.phone, c.customer_id,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username,
           rc.role_name AS creator_role,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM contributions con
      JOIN customers c  ON con.member_id   = c.customer_id
      LEFT JOIN users uc ON con.created_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id      = rc.role_id
      LEFT JOIN users ur ON con.reviewed_by = ur.user_id
      LEFT JOIN roles rr ON ur.role_id      = rr.role_id
      LEFT JOIN users ua ON con.approved_by = ua.user_id
      LEFT JOIN roles ra ON ua.role_id      = ra.role_id
     WHERE con.contribution_id = ?
");
$stmt->execute([$id]);
$con = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$con) { redirectTo('manage_contributions'); }

$settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = $settings['currency'] ?? 'TZS';

$creator_name  = trim($con['creator_name'])  ?: ($con['creator_username']  ?? '—');
$reviewer_name = trim($con['reviewer_name']) ?: '';
$approver_name = trim($con['approver_name']) ?: '';

$wf = [
    'created_by_name'  => $creator_name,
    'created_by_role'  => $con['creator_role']  ?? '',
    'created_at'       => $con['created_at']     ?? '',
    'reviewed_by_name' => $reviewer_name,
    'reviewed_by_role' => $con['reviewer_role']  ?? '',
    'reviewed_at'      => $con['reviewed_at']    ?? '',
    'approved_by_name' => $approver_name,
    'approved_by_role' => $con['approver_role']  ?? '',
    'approved_at'      => $con['approved_at']    ?? '',
];

$status = $con['status'] ?? 'pending';
$status_colors = [
    'pending'  => 'warning',
    'reviewed' => 'info',
    'approved' => 'success',
    'cancelled'=> 'secondary',
];
$badge_color = $status_colors[$status] ?? 'secondary';

includeHeader();
?>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold">
                <?= $is_sw ? 'Maelezo ya Mchango' : 'Contribution Details' ?>
                <span class="badge bg-<?= $badge_color ?> ms-2"><?= ucfirst($status) ?></span>
            </h4>
            <p class="text-muted small mb-0"><?= $is_sw ? 'Mchango #' : 'Contribution #' ?><?= $con['contribution_id'] ?> &mdash; <?= htmlspecialchars($con['customer_name'] ?: trim($con['first_name'].' '.$con['last_name'])) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= $is_sw ? 'Rudi' : 'Back' ?>
            </a>
            <?php if ($status === 'pending' && $can_review): ?>
            <button class="btn btn-primary btn-sm" onclick="reviewContrib(<?= $id ?>)">
                <i class="bi bi-clipboard-check me-1"></i><?= $is_sw ? 'Pitia' : 'Mark Reviewed' ?>
            </button>
            <?php endif; ?>
            <?php if ($status === 'reviewed' && $can_approve): ?>
            <button class="btn btn-success btn-sm" onclick="approveContrib(<?= $id ?>)">
                <i class="bi bi-check2-circle me-1"></i><?= $is_sw ? 'Idhinisha' : 'Approve' ?>
            </button>
            <?php endif; ?>
            <a href="<?= getUrl('print_contribution') ?>?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i><?= $is_sw ? 'Chapa' : 'Print' ?>
            </a>
        </div>
    </div>

    <!-- Workflow Audit Panel -->
    <?php require WORKFLOW_AUDIT_PANEL_FILE; ?>

    <!-- Details Card -->
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-bold text-primary border-0">
                    <i class="bi bi-info-circle me-1"></i><?= $is_sw ? 'Maelezo ya Mchango' : 'Contribution Details' ?>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted small fw-semibold" style="width:40%"><?= $is_sw ? 'Mwanachama' : 'Member' ?></td>
                            <td class="fw-bold"><?= safe_output($con['customer_name'] ?: trim($con['first_name'].' '.$con['last_name'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Kiasi' : 'Amount' ?></td>
                            <td class="fw-bold text-success"><?= $currency ?> <?= number_format($con['amount'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Tarehe' : 'Date' ?></td>
                            <td><?= date('d M Y', strtotime($con['contribution_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Aina' : 'Type' ?></td>
                            <td><?= ucfirst($con['contribution_type'] ?? 'monthly') ?></td>
                        </tr>
                        <?php if (!empty($con['description'])): ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Maelezo' : 'Description' ?></td>
                            <td><?= htmlspecialchars($con['description']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($con['mkoba_receipt'])): ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Risiti (Mkoba)' : 'Mkoba Receipt' ?></td>
                            <td><code><?= htmlspecialchars($con['mkoba_receipt']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted small fw-semibold"><?= $is_sw ? 'Hali' : 'Status' ?></td>
                            <td><span class="badge bg-<?= $badge_color ?>"><?= ucfirst($status) ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2 fw-bold text-primary border-0">
                    <i class="bi bi-diagram-3 me-1"></i><?= $is_sw ? 'Hali ya Idhini' : 'Approval Progress' ?>
                </div>
                <div class="card-body">
                    <?php
                    $steps = [
                        ['pending',  'bi-pencil-square', 'warning', $is_sw?'Imeundwa':'Created',  $creator_name,  $con['creator_role']??'',  $con['created_at']??''],
                        ['reviewed', 'bi-clipboard-check','info',   $is_sw?'Imepitwa':'Reviewed',  $reviewer_name, $con['reviewer_role']??'', $con['reviewed_at']??''],
                        ['approved', 'bi-check2-all',   'success', $is_sw?'Imeidhinishwa':'Approved', $approver_name, $con['approver_role']??'', $con['approved_at']??''],
                    ];
                    $order = ['pending'=>0,'reviewed'=>1,'approved'=>2];
                    $cur_order = $order[$status] ?? 0;
                    foreach ($steps as $i => [$s, $icon, $color, $label, $person, $role, $dt]):
                        $done = ($i <= $cur_order && !empty($person));
                        $active = ($i === $cur_order);
                    ?>
                    <div class="d-flex align-items-start mb-3">
                        <div class="me-3 mt-1">
                            <span class="badge rounded-circle p-2 bg-<?= $done ? $color : 'light text-muted' ?>" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi <?= $icon ?> small"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small text-<?= $done ? $color : 'muted' ?>"><?= $label ?></div>
                            <?php if (!empty($person)): ?>
                                <div class="small"><?= htmlspecialchars($person) ?><?= $role ? ' &mdash; <span class="text-muted">' . htmlspecialchars($role) . '</span>' : '' ?></div>
                                <?php if ($dt): ?><div class="x-small text-muted"><?= date('d M Y, h:i A', strtotime($dt)) ?></div><?php endif; ?>
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
function postWorkflow(url, id, msg) {
    Swal.fire({ title: msg, didOpen: () => Swal.showLoading() });
    $.post(url, { id: id }, function(res) {
        if (res.success) {
            Swal.fire({ icon:'success', title:'Done', text: res.message, timer:1500, showConfirmButton:false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').fail(() => Swal.fire('Error', 'Server communication failed', 'error'));
}
function reviewContrib(id) {
    Swal.fire({ title: '<?= $is_sw?"Pitia mchango huu?":"Mark as Reviewed?" ?>', icon:'question',
        showCancelButton:true, confirmButtonText:'<?= $is_sw?"Ndio, Pitia":"Yes, Reviewed" ?>'
    }).then(r => { if(r.isConfirmed) postWorkflow('<?= getUrl('api/review_contribution') ?>', id, '<?= $is_sw?"Inatumwa...":"Submitting..." ?>'); });
}
function approveContrib(id) {
    Swal.fire({ title: '<?= $is_sw?"Idhinisha mchango huu?":"Approve Contribution?" ?>', icon:'question',
        showCancelButton:true, confirmButtonText:'<?= $is_sw?"Ndio, Idhinisha":"Yes, Approve" ?>',
        confirmButtonColor:'#198754'
    }).then(r => { if(r.isConfirmed) postWorkflow('<?= getUrl('api/approve_contribution') ?>', id, '<?= $is_sw?"Inaidhinisha...":"Approving..." ?>'); });
}
</script>
<style>.x-small { font-size: 0.72rem; }</style>

<?php includeFooter(); ?>
