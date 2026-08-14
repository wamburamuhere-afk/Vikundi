<?php
// app/constant/voting/leadership_application.php — a member applies for a
// leadership position in an election that is still gathering candidates.
//
// The Committee reviews what is submitted here; approved applications become the
// names on the ballot. Members apply and vote — they do not approve.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/leadership_helpers.php';

requireViewPermission('leadership_applications');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = fn($en, $sw) => $is_sw ? $sw : $en;

$uid = (int) ($_SESSION['user_id'] ?? 0);
$cstmt = $pdo->prepare("SELECT customer_id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
                          FROM customers WHERE user_id = ? LIMIT 1");
$cstmt->execute([$uid]);
$me = $cstmt->fetch(PDO::FETCH_ASSOC) ?: [];
$member_id   = (int) ($me['customer_id'] ?? 0);
$member_name = (string) ($me['name'] ?? '');

$positions = vk_leadership_positions($pdo);
$elections = vk_elections_accepting_applications($pdo);

// Every application this member has made, across all elections — so a closed
// election still shows them what they submitted and what came of it.
$mine = [];
if ($member_id > 0) {
    $q = $pdo->prepare("
        SELECT a.*, v.title AS election_title, v.status AS election_status
          FROM leadership_applications a
          JOIN votes v ON v.id = a.vote_id
         WHERE a.member_id = ?
         ORDER BY a.created_at DESC");
    $q->execute([$member_id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mine[(int) $r['vote_id']] = $r;
    }
}

// Fellow members, for the optional proposer field. The applicant is excluded —
// proposing yourself is not a proposal.
$others = [];
if ($member_id > 0) {
    $o = $pdo->prepare("
        SELECT customer_id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
          FROM customers
         WHERE status <> 'deleted' AND customer_id <> ?
         ORDER BY first_name, last_name");
    $o->execute([$member_id]);
    $others = $o->fetchAll(PDO::FETCH_ASSOC);
}

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">

    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #6f42c1 !important;">
        <div class="card-body p-3 p-md-4 bg-white">
            <h3 class="fw-bold mb-1" style="color:#6f42c1;"><i class="bi bi-person-badge me-2"></i><?= $t('Leadership Applications', 'Maombi ya Uongozi') ?></h3>
            <p class="text-muted mb-0 small">
                <?= $t('Apply to stand for a position. The Committee reviews every application, and approved names go onto the ballot.',
                       'Omba kugombea nafasi. Kamati hupitia kila ombi, na majina yaliyokubaliwa huingia kwenye kura.') ?>
            </p>
        </div>
    </div>

    <?php if ($member_id <= 0): ?>
        <div class="alert alert-warning"><?= $t('Your account is not a member account, so you cannot apply.', 'Akaunti yako si ya mwanachama, kwa hiyo huwezi kuomba.') ?></div>
    <?php elseif (!$positions): ?>
        <div class="alert alert-warning">
            <?= $t('Leadership positions have not been set up yet. Ask the Committee to configure them in Group Settings.',
                   'Nafasi za uongozi hazijawekwa bado. Muombe Kamati aziweke kwenye Mipangilio ya Kikundi.') ?>
        </div>
    <?php elseif (!$elections): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-hourglass fs-1 d-block mb-2"></i>
            <?= $t('No election is accepting applications at the moment.', 'Hakuna uchaguzi unaopokea maombi kwa sasa.') ?>
        </div></div>
    <?php endif; ?>

    <?php foreach ($elections as $e):
        $vid = (int) $e['id'];
        $app = $mine[$vid] ?? null;
        $editable = vk_application_is_editable($app, 'draft');
    ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="mb-0 fw-bold"><?= safe_output($e['title']) ?></h6>
                <?php if (!empty($e['description'])): ?>
                    <small class="text-muted"><?= safe_output($e['description']) ?></small>
                <?php endif; ?>
            </div>
            <?php if ($app): ?>
                <span class="badge bg-<?= vk_application_status_badge($app['status']) ?> px-3 py-2">
                    <?= safe_output(vk_application_status_label($app['status'], $is_sw)) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">

            <?php if ($app && !$editable): ?>
                <!-- Ruled on, or the ballot is open: the record is fixed. -->
                <div class="row g-3 small">
                    <div class="col-md-4"><span class="text-muted d-block"><?= $t('Position', 'Nafasi') ?></span><span class="fw-bold"><?= safe_output($app['position']) ?></span></div>
                    <div class="col-md-8"><span class="text-muted d-block"><?= $t('Your statement', 'Maelezo yako') ?></span><?= nl2br(safe_output($app['statement'])) ?></div>
                    <?php if (!empty($app['review_note'])): ?>
                    <div class="col-12">
                        <div class="alert alert-<?= $app['status'] === 'rejected' ? 'danger' : 'success' ?> mb-0 py-2">
                            <strong><?= $t('Committee note', 'Maelezo ya Kamati') ?>:</strong> <?= safe_output($app['review_note']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form class="application-form" data-vote="<?= $vid ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small"><?= $t('Position applied for', 'Nafasi unayoomba') ?> <span class="text-danger">*</span></label>
                            <select name="position" class="form-select" required>
                                <option value=""><?= $t('— choose —', '— chagua —') ?></option>
                                <?php foreach ($positions as $p): ?>
                                    <option value="<?= safe_output($p) ?>" <?= $app && $app['position'] === $p ? 'selected' : '' ?>><?= safe_output($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small"><?= $t('Proposed by (optional)', 'Mdhamini (si lazima)') ?></label>
                            <select name="proposer_member_id" class="form-select">
                                <option value=""><?= $t('— none —', '— hakuna —') ?></option>
                                <?php foreach ($others as $o): ?>
                                    <option value="<?= (int) $o['customer_id'] ?>" <?= $app && (int) $app['proposer_member_id'] === (int) $o['customer_id'] ? 'selected' : '' ?>><?= safe_output($o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small"><?= $t('Why you are standing', 'Kwa nini unagombea') ?> <span class="text-danger">*</span></label>
                            <textarea name="statement" class="form-control" rows="3" maxlength="2000" required><?= $app ? safe_output($app['statement']) : '' ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small"><?= $t('Relevant experience', 'Uzoefu ulionao') ?></label>
                            <textarea name="experience" class="form-control" rows="2" maxlength="2000"><?= $app ? safe_output($app['experience']) : '' ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="declaration" value="1" id="decl<?= $vid ?>" <?= $app ? 'checked' : '' ?> required>
                                <label class="form-check-label small" for="decl<?= $vid ?>">
                                    <?= $t('I confirm the information above is true and I accept the group\'s rules for holding office.',
                                           'Nathibitisha taarifa hizi ni za kweli na nakubali kanuni za kikundi za kushika nafasi ya uongozi.') ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn rounded-pill px-4 text-white" style="background:#6f42c1;">
                                <i class="bi bi-send me-1"></i><?= $app ? $t('Update application', 'Sasisha ombi') : $t('Submit application', 'Tuma ombi') ?>
                            </button>
                            <?php if ($app): ?>
                                <button type="button" class="btn btn-outline-danger rounded-pill px-4 withdraw-btn" data-vote="<?= $vid ?>">
                                    <i class="bi bi-x-circle me-1"></i><?= $t('Withdraw', 'Ondoa ombi') ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php
    // Applications to elections that have moved on — kept visible so a member can
    // still see what they submitted and what the Committee decided.
    $past = array_filter($mine, fn($a) => $a['election_status'] !== 'draft');
    if ($past): ?>
    <h5 class="fw-bold text-dark mb-3 mt-4"><i class="bi bi-clock-history me-2"></i><?= $t('Past applications', 'Maombi yaliyopita') ?></h5>
    <div class="card border-0 shadow-sm"><div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light small text-muted">
                    <tr>
                        <th class="ps-3"><?= $t('Election', 'Uchaguzi') ?></th>
                        <th><?= $t('Position', 'Nafasi') ?></th>
                        <th><?= $t('Submitted', 'Ilitumwa') ?></th>
                        <th class="text-center"><?= $t('Outcome', 'Matokeo') ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php foreach ($past as $a): ?>
                    <tr>
                        <td class="ps-3"><?= safe_output($a['election_title']) ?></td>
                        <td><?= safe_output($a['position']) ?></td>
                        <td><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                        <td class="text-center"><span class="badge bg-<?= vk_application_status_badge($a['status']) ?>"><?= safe_output(vk_application_status_label($a['status'], $is_sw)) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div></div>
    <?php endif; ?>
</div>

<script>
const laIsSw = <?= $is_sw ? 'true' : 'false' ?>;

$('.application-form').on('submit', function (e) {
    e.preventDefault();
    const form = $(this);
    const data = form.serializeArray();
    data.push({ name: 'vote_id', value: form.data('vote') });
    data.push({ name: 'do', value: 'apply' });

    $.post('/actions/save_leadership_application', $.param(data), function (res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: laIsSw ? 'Imetumwa' : 'Submitted', text: res.message, timer: 2000, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire(laIsSw ? 'Hitilafu' : 'Error', res.message || 'Error', 'error');
        }
    }, 'json').fail(() => Swal.fire(laIsSw ? 'Hitilafu' : 'Error', laIsSw ? 'Hitilafu ya seva' : 'Server error', 'error'));
});

$('.withdraw-btn').on('click', function () {
    const voteId = $(this).data('vote');
    Swal.fire({
        title: laIsSw ? 'Ondoa ombi lako?' : 'Withdraw your application?',
        text: laIsSw ? 'Unaweza kuomba tena kabla maombi hayajafungwa.' : 'You can apply again before applications close.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: laIsSw ? 'Ndio, ondoa' : 'Yes, withdraw',
        confirmButtonColor: '#dc3545'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/save_leadership_application', { vote_id: voteId, do: 'withdraw' }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: laIsSw ? 'Imeondolewa' : 'Withdrawn', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire(laIsSw ? 'Hitilafu' : 'Error', res.message || 'Error', 'error');
            }
        }, 'json').fail(() => Swal.fire(laIsSw ? 'Hitilafu' : 'Error', laIsSw ? 'Hitilafu ya seva' : 'Server error', 'error'));
    });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
