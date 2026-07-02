<?php
// app/constant/meetings/meeting_view.php — meeting details, attendance, documents.
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('meetings');

$is_sw     = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$can_edit  = canEdit('meetings');
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$id = intval($_GET['id'] ?? 0);
if (!$id) { redirectTo('meetings'); }

global $pdo;

$stmt = $pdo->prepare("SELECT m.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS creator_name
                       FROM meetings m LEFT JOIN users u ON m.created_by = u.user_id WHERE m.id = ?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { redirectTo('meetings'); }

// Roster + current attendance for this meeting.
$members = $pdo->prepare("
    SELECT c.customer_id,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS name,
           COALESCE(a.status, 'absent') AS att_status
      FROM customers c
      LEFT JOIN meeting_attendance a ON a.member_id = c.customer_id AND a.meeting_id = :mid
     WHERE (c.status IS NULL OR c.status <> 'deleted') AND COALESCE(c.is_deceased, 0) = 0
     ORDER BY c.first_name, c.last_name
");
$members->execute(['mid' => $id]);
$roster = $members->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../includes/meeting_helpers.php';
$att = vk_attendance_summary(array_map(fn($r) => ['status' => $r['att_status']], $roster));

// Attached documents (reuses the #164 component; gated + access-filtered).
require_once __DIR__ . '/../../../includes/expense_attachments.php';
$__docs = vk_fetch_expense_attachments($pdo, 'meeting', $id);

$typeBadge   = ['agm' => 'danger', 'special' => 'warning', 'regular' => 'secondary'][$m['meeting_type']] ?? 'secondary';
$statusBadge = ['held' => 'success', 'cancelled' => 'danger', 'scheduled' => 'info'][$m['status']] ?? 'info';

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="<?= getUrl('meetings') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <span class="badge bg-<?= $statusBadge ?> px-3 py-2"><?= ucfirst($m['status']) ?></span>
    </div>

    <div class="row g-3">
        <!-- Details -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i><?= safe_output($m['title']) ?></h5>
                    <span class="badge bg-<?= $typeBadge ?>-subtle text-<?= $typeBadge ?> border border-<?= $typeBadge ?>-subtle text-uppercase mt-1"><?= $m['meeting_type'] ?></span>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted small fw-semibold" style="width:35%"><?= $t('Date', 'Tarehe') ?></td><td><?= date('d M Y', strtotime($m['meeting_date'])) ?><?= $m['meeting_time'] ? ' &middot; ' . date('h:i A', strtotime($m['meeting_time'])) : '' ?></td></tr>
                        <tr><td class="text-muted small fw-semibold"><?= $t('Location', 'Mahali') ?></td><td><?= $m['location'] ? safe_output($m['location']) : '<span class="text-muted">—</span>' ?></td></tr>
                        <tr><td class="text-muted small fw-semibold"><?= $t('Recorded by', 'Imeandikwa na') ?></td><td><?= safe_output($m['creator_name'] ?: '—') ?></td></tr>
                    </table>
                    <?php if ($m['agenda']): ?>
                        <h6 class="fw-bold mt-3 mb-1 small text-uppercase text-muted"><?= $t('Agenda', 'Ajenda') ?></h6>
                        <div class="border rounded p-2 bg-light small" style="white-space:pre-wrap;"><?= safe_output($m['agenda']) ?></div>
                    <?php endif; ?>
                    <?php if ($m['minutes']): ?>
                        <h6 class="fw-bold mt-3 mb-1 small text-uppercase text-muted"><?= $t('Minutes', 'Muhtasari') ?></h6>
                        <div class="border rounded p-2 bg-light small" style="white-space:pre-wrap;"><?= safe_output($m['minutes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Attendance -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-check2-square me-2"></i><?= $t('Attendance', 'Mahudhurio') ?></h6>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" id="attCount"><?= $att['present'] ?>/<?= $att['total'] ?></span>
                </div>
                <div class="card-body">
                    <form id="attendanceForm">
                        <input type="hidden" name="meeting_id" value="<?= $id ?>">
                        <div style="max-height:340px;overflow-y:auto;">
                            <?php foreach ($roster as $r): $mid = (int) $r['customer_id']; $present = $r['att_status'] === 'present'; ?>
                            <div class="form-check d-flex align-items-center justify-content-between border-bottom py-2">
                                <label class="form-check-label small" for="att<?= $mid ?>"><?= safe_output($r['name'] !== '' ? $r['name'] : ('Member #' . $mid)) ?></label>
                                <input type="hidden" name="member_ids[]" value="<?= $mid ?>">
                                <input class="form-check-input att-box" type="checkbox" id="att<?= $mid ?>" name="present[]" value="<?= $mid ?>" <?= $present ? 'checked' : '' ?> <?= $can_edit ? '' : 'disabled' ?>>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($roster)): ?><div class="text-muted small text-center py-3"><?= $t('No members yet.', 'Hakuna wanachama bado.') ?></div><?php endif; ?>
                        </div>
                        <?php if ($can_edit && !empty($roster)): ?>
                        <button type="submit" class="btn btn-primary btn-sm w-100 mt-3 rounded-pill"><i class="bi bi-save me-1"></i><?= $t('Save Attendance', 'Hifadhi Mahudhurio') ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php echo vk_render_attachments_section($__docs, true, $is_sw); ?>
</div>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
$('.att-box').on('change', function(){
    const total = $('.att-box').length, present = $('.att-box:checked').length;
    $('#attCount').text(present + '/' + total);
});
$('#attendanceForm').on('submit', function(e){
    e.preventDefault();
    const $btn = $(this).find('button[type=submit]'); const old = $btn.html();
    $btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({ url:'/actions/save_meeting_attendance', type:'POST', data:$(this).serialize(),
        success:res=>{ if(res.success){ Swal.fire({icon:'success',title:isSw?'Imehifadhiwa':'Saved',timer:1200,showConfirmButton:false}); } else Swal.fire('Error',res.message||'Error','error'); $btn.prop('disabled',false).html(old); },
        error:()=>{ Swal.fire('Error','Server error','error'); $btn.prop('disabled',false).html(old); }, dataType:'json' });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
