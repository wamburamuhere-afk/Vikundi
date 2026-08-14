<?php
// app/constant/voting/manage_leadership_applications.php — the Committee reviews
// applications to stand for leadership.
//
// Approving writes a `vote_options` row, which is exactly what the existing ballot
// renders and what cast_vote.php tallies. So this screen is the only new step in
// the chain: member applies -> Committee reviews -> members vote.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/leadership_helpers.php';
require_once __DIR__ . '/../../../includes/contribution_standing.php';

requireViewPermission('manage_leadership_applications');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = fn($en, $sw) => $is_sw ? $sw : $en;

// Elections that have applications, or could take them. Draft first — that is where
// the Committee's work is.
$elections = $pdo->query("
    SELECT v.id, v.title, v.status, v.created_at,
           (SELECT COUNT(*) FROM leadership_applications a WHERE a.vote_id = v.id) AS app_count
      FROM votes v
     WHERE v.vote_type = 'candidate'
     ORDER BY (v.status = 'draft') DESC, v.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selected = isset($_GET['election']) && ctype_digit((string) $_GET['election'])
    ? (int) $_GET['election']
    : (int) ($elections[0]['id'] ?? 0);

$election = null;
foreach ($elections as $e) {
    if ((int) $e['id'] === $selected) {
        $election = $e;
    }
}

$apps = [];
if ($election) {
    $q = $pdo->prepare("
        SELECT a.*,
               TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
               c.phone,
               TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS proposer_name,
               TRIM(CONCAT_WS(' ', u.first_name, u.last_name))                AS reviewer_name
          FROM leadership_applications a
          LEFT JOIN customers c ON c.customer_id = a.member_id
          LEFT JOIN customers p ON p.customer_id = a.proposer_member_id
          LEFT JOIN users     u ON u.user_id     = a.reviewed_by
         WHERE a.vote_id = ?
         ORDER BY a.position ASC, a.created_at ASC");
    $q->execute([$selected]);
    $apps = $q->fetchAll(PDO::FETCH_ASSOC);
}

// Contribution standing for every applicant, in one pass. The Committee should know
// whether the person standing for Treasurer is themselves behind — not to block
// them, but so the decision is made knowingly.
$standing = [];
if ($apps) {
    $schedules = cs_group_schedules($pdo);
    foreach ($apps as $a) {
        $mid = (int) $a['member_id'];
        if (isset($schedules[$mid])) {
            $standing[$mid] = cs_arrears_from_grid(cs_calendar_grid($schedules[$mid]['schedule']));
        }
    }
}

// A ballot lets a member choose ONE option. If an election carries candidates for
// more than one office, members are forced to pick a single person across different
// offices — almost certainly not what was intended.
$offices = array_unique(array_column(array_filter($apps, fn($a) => in_array($a['status'], ['approved', 'pending'], true)), 'position'));
$multiOffice = count($offices) > 1;

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'withdrawn' => 0];
foreach ($apps as $a) {
    $counts[$a['status']] = ($counts[$a['status']] ?? 0) + 1;
}

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">

    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #6f42c1 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h3 class="fw-bold mb-1" style="color:#6f42c1;"><i class="bi bi-clipboard-check me-2"></i><?= $t('Review Leadership Applications', 'Pitia Maombi ya Uongozi') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Approved applications become the names on the ballot.', 'Maombi yaliyokubaliwa huwa majina yanayopigiwa kura.') ?></p>
            </div>
            <?php if ($elections): ?>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="small text-muted mb-0"><?= $t('Election', 'Uchaguzi') ?></label>
                <select name="election" class="form-select form-select-sm" style="min-width:260px" onchange="this.form.submit()">
                    <?php foreach ($elections as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $selected ? 'selected' : '' ?>>
                            <?= safe_output($e['title']) ?> — <?= safe_output(ucfirst($e['status'])) ?> (<?= (int) $e['app_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$elections): ?>
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            <?= $t('No leadership election has been created yet. Create one in Manage Voting, and members can then apply while it stays in draft.',
                   'Hakuna uchaguzi wa uongozi uliotengenezwa. Tengeneza mmoja kwenye Simamia Kura, kisha wanachama wataweza kuomba wakati bado ni rasimu.') ?>
        </div></div>
    <?php else: ?>

        <?php if ($election && $election['status'] !== 'draft'): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-lock me-1"></i>
            <?= $t('Voting has started or finished for this election, so applications can no longer be changed.',
                   'Upigaji kura umeanza au umeisha kwa uchaguzi huu, kwa hiyo maombi hayabadiliki tena.') ?>
        </div>
        <?php endif; ?>

        <?php if ($multiOffice): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= $t('This election covers more than one position.', 'Uchaguzi huu una nafasi zaidi ya moja.') ?></strong>
            <?= $t('A member casts one vote per election, so they would have to choose a single person across different offices. Consider creating one election per position.',
                   'Mwanachama hupiga kura moja kwa kila uchaguzi, kwa hiyo atalazimika kuchagua mtu mmoja kati ya nafasi tofauti. Ni vyema kutengeneza uchaguzi mmoja kwa kila nafasi.') ?>
        </div>
        <?php endif; ?>

        <div class="row g-2 mb-4">
            <?php foreach ([
                ['warning',   'bi-hourglass-split', $t('Awaiting review', 'Zinasubiri'), $counts['pending']],
                ['success',   'bi-check2-circle',   $t('Approved', 'Zimekubaliwa'),      $counts['approved']],
                ['danger',    'bi-x-circle',        $t('Rejected', 'Zimekataliwa'),      $counts['rejected']],
                ['secondary', 'bi-slash-circle',    $t('Withdrawn', 'Zimeondolewa'),     $counts['withdrawn']],
            ] as [$color, $icon, $label, $val]): ?>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
                    <div class="fw-bold text-<?= $color ?>" style="font-size:1.25rem;"><?= (int) $val ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><i class="bi <?= $icon ?>"></i> <?= $label ?></div>
                </div></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$apps): ?>
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
                <i class="bi bi-person-plus fs-1 d-block mb-2"></i>
                <?= $t('No applications have been submitted for this election yet.', 'Hakuna maombi yaliyotumwa kwa uchaguzi huu bado.') ?>
            </div></div>
        <?php endif; ?>

        <?php
        $currentPosition = null;
        foreach ($apps as $a):
            $mid  = (int) $a['member_id'];
            $arr  = $standing[$mid] ?? null;
            $open = $election && $election['status'] === 'draft' && $a['status'] !== 'withdrawn';
            if ($a['position'] !== $currentPosition):
                $currentPosition = $a['position'];
        ?>
        <h5 class="fw-bold text-dark mt-4 mb-3"><i class="bi bi-award me-2" style="color:#6f42c1;"></i><?= safe_output($currentPosition) ?></h5>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h6 class="fw-bold mb-1"><?= safe_output($a['member_name']) ?>
                            <span class="badge bg-<?= vk_application_status_badge($a['status']) ?> ms-2"><?= safe_output(vk_application_status_label($a['status'], $is_sw)) ?></span>
                        </h6>
                        <div class="small text-muted">
                            <?php if (!empty($a['phone'])): ?><i class="bi bi-telephone me-1"></i><?= safe_output($a['phone']) ?><?php endif; ?>
                            <?php if (!empty($a['proposer_name'])): ?>
                                <span class="ms-3"><i class="bi bi-people me-1"></i><?= $t('Proposed by', 'Amedhaminiwa na') ?>: <?= safe_output($a['proposer_name']) ?></span>
                            <?php endif; ?>
                            <span class="ms-3"><i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($a['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php if ($arr): ?>
                        <span class="badge <?= $arr['behind'] ? 'bg-danger' : 'bg-success' ?> px-3 py-2">
                            <?php if ($arr['behind']): ?>
                                <?= $t('Behind', 'Amechelewa') ?> TSh <?= number_format($arr['amount'], 0) ?> · <?= (int) $arr['months'] ?> <?= $t('months', 'miezi') ?>
                            <?php else: ?>
                                <?= $t('Contributions up to date', 'Michango iko sawa') ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <p class="mb-2 small"><strong><?= $t('Why they are standing', 'Kwa nini anagombea') ?>:</strong><br><?= nl2br(safe_output($a['statement'])) ?></p>
                <?php if (!empty($a['experience'])): ?>
                    <p class="mb-2 small"><strong><?= $t('Experience', 'Uzoefu') ?>:</strong><br><?= nl2br(safe_output($a['experience'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($a['review_note'])): ?>
                    <div class="alert alert-<?= $a['status'] === 'rejected' ? 'danger' : 'success' ?> py-2 small mb-2">
                        <strong><?= $t('Committee note', 'Maelezo ya Kamati') ?>:</strong> <?= safe_output($a['review_note']) ?>
                        <?php if (!empty($a['reviewer_name'])): ?>
                            <span class="text-muted">— <?= safe_output($a['reviewer_name']) ?><?= $a['reviewed_at'] ? ', ' . date('d M Y', strtotime($a['reviewed_at'])) : '' ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($open && canEdit('manage_leadership_applications')): ?>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php if ($a['status'] !== 'approved'): ?>
                        <button class="btn btn-sm btn-success rounded-pill px-3 review-btn" data-id="<?= (int) $a['id'] ?>" data-decision="approve" data-name="<?= safe_output($a['member_name']) ?>">
                            <i class="bi bi-check2 me-1"></i><?= $t('Approve', 'Kubali') ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($a['status'] !== 'rejected'): ?>
                        <button class="btn btn-sm btn-outline-danger rounded-pill px-3 review-btn" data-id="<?= (int) $a['id'] ?>" data-decision="reject" data-name="<?= safe_output($a['member_name']) ?>">
                            <i class="bi bi-x me-1"></i><?= $t('Reject', 'Kataa') ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($a['status'] !== 'pending'): ?>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 review-btn" data-id="<?= (int) $a['id'] ?>" data-decision="reset" data-name="<?= safe_output($a['member_name']) ?>">
                            <i class="bi bi-arrow-counterclockwise me-1"></i><?= $t('Reopen', 'Rudisha') ?>
                        </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
const mlaIsSw = <?= $is_sw ? 'true' : 'false' ?>;

$('.review-btn').on('click', function () {
    const id = $(this).data('id');
    const decision = $(this).data('decision');
    const name = $(this).data('name');

    const titles = {
        approve: mlaIsSw ? 'Kubali ' + name + '?' : 'Approve ' + name + '?',
        reject:  mlaIsSw ? 'Kataa ' + name + '?'  : 'Reject ' + name + '?',
        reset:   mlaIsSw ? 'Rudisha ombi la ' + name + '?' : 'Reopen ' + name + "'s application?"
    };
    const texts = {
        approve: mlaIsSw ? 'Jina lake litaingia kwenye kura.' : 'Their name will go onto the ballot.',
        reject:  mlaIsSw ? 'Lazima uandike sababu.' : 'A reason is required.',
        reset:   mlaIsSw ? 'Litarudi kusubiri, na jina litatolewa kwenye kura.' : 'It returns to pending and the name comes off the ballot.'
    };

    Swal.fire({
        title: titles[decision],
        text: texts[decision],
        icon: decision === 'approve' ? 'question' : 'warning',
        input: decision === 'reset' ? undefined : 'textarea',
        inputPlaceholder: decision === 'reject'
            ? (mlaIsSw ? 'Sababu ya kukataa (lazima)' : 'Reason for rejecting (required)')
            : (mlaIsSw ? 'Maelezo (si lazima)' : 'Note (optional)'),
        showCancelButton: true,
        confirmButtonText: mlaIsSw ? 'Thibitisha' : 'Confirm',
        confirmButtonColor: decision === 'reject' ? '#dc3545' : '#6f42c1',
        preConfirm: (value) => {
            if (decision === 'reject' && !String(value || '').trim()) {
                Swal.showValidationMessage(mlaIsSw ? 'Andika sababu ya kukataa.' : 'Please give a reason for rejecting.');
                return false;
            }
            return value || '';
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('/actions/review_leadership_application',
            { application_id: id, decision: decision, note: r.value || '' },
            function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: mlaIsSw ? 'Imehifadhiwa' : 'Saved', text: res.message, timer: 1800, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire(mlaIsSw ? 'Hitilafu' : 'Error', res.message || 'Error', 'error');
                }
            }, 'json').fail(() => Swal.fire(mlaIsSw ? 'Hitilafu' : 'Error', mlaIsSw ? 'Hitilafu ya seva' : 'Server error', 'error'));
    });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
