<?php
// app/constant/voting/voting.php — a member casts votes in open polls.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/vote_helpers.php';

requireViewPermission('voting');

// Auto-close any vote whose deadline has passed.
$pdo->prepare("UPDATE votes SET status='closed' WHERE status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")->execute();

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$uid = (int) ($_SESSION['user_id'] ?? 0);
$cstmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
$cstmt->execute([$uid]);
$member_id = (int) ($cstmt->fetchColumn() ?: 0);

// Open votes this member is eligible for.
$open = [];
if ($member_id > 0) {
    $q = $pdo->prepare("
        SELECT v.* FROM votes v
          JOIN vote_eligibility e ON e.vote_id = v.id AND e.member_id = ?
         WHERE v.status = 'open'
         ORDER BY v.created_at DESC
    ");
    $q->execute([$member_id]);
    $open = $q->fetchAll(PDO::FETCH_ASSOC);
}

$optStmt   = $pdo->prepare("SELECT id, label FROM vote_options WHERE vote_id = ? ORDER BY position, id");
$votedStmt = $pdo->prepare("SELECT COUNT(*) FROM vote_participation WHERE vote_id = ? AND member_id = ?");

// Closed votes whose results were published — visible to all members.
$published = $pdo->query("SELECT * FROM votes WHERE status='closed' AND publish_results=1 ORDER BY created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #6f42c1 !important;">
        <div class="card-body p-3 p-md-4 bg-white">
            <h3 class="fw-bold mb-1" style="color:#6f42c1;"><i class="bi bi-check2-square me-2"></i><?= $t('Voting', 'Kura') ?></h3>
            <p class="text-muted mb-0 small"><?= $t('Cast your vote in the group’s open polls. Your vote is secret.', 'Piga kura kwenye kura zilizo wazi. Kura yako ni siri.') ?></p>
        </div>
    </div>

    <?php if ($member_id <= 0): ?>
        <div class="alert alert-warning"><?= $t('Your account is not linked to a member record, so you cannot vote.', 'Akaunti yako haijaunganishwa na mwanachama, huwezi kupiga kura.') ?></div>
    <?php endif; ?>

    <!-- Open votes -->
    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-unlock me-2"></i><?= $t('Open Votes', 'Kura Zilizo Wazi') ?></h5>
    <?php if (empty($open)): ?>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i><?= $t('There are no open votes right now.', 'Hakuna kura zilizo wazi kwa sasa.') ?>
        </div></div>
    <?php else: foreach ($open as $v):
        $votedStmt->execute([$v['id'], $member_id]);
        $hasVoted = (int) $votedStmt->fetchColumn() > 0;
        $optStmt->execute([$v['id']]);
        $opts = $optStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold"><?= safe_output($v['title']) ?></h6>
                    <?php if (!empty($v['description'])): ?><small class="text-muted"><?= safe_output($v['description']) ?></small><?php endif; ?>
                </div>
                <span class="badge bg-<?= $v['vote_type'] === 'motion' ? 'info' : 'primary' ?>-subtle text-<?= $v['vote_type'] === 'motion' ? 'info' : 'primary' ?> border text-uppercase"><?= $v['vote_type'] === 'motion' ? $t('Motion', 'Hoja') : $t('Election', 'Uchaguzi') ?></span>
            </div>
            <div class="card-body">
                <?php if ($hasVoted): ?>
                    <div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill me-1"></i><?= $t('You have voted in this poll. Thank you!', 'Umepiga kura kwenye kura hii. Asante!') ?></div>
                <?php else: ?>
                    <form class="vote-form" data-vote="<?= (int) $v['id'] ?>">
                        <?php foreach ($opts as $o): ?>
                        <div class="form-check border rounded p-2 mb-2">
                            <input class="form-check-input" type="radio" name="option_id" id="opt<?= (int) $o['id'] ?>" value="<?= (int) $o['id'] ?>" required>
                            <label class="form-check-label w-100" for="opt<?= (int) $o['id'] ?>"><?= safe_output($o['label']) ?></label>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm rounded-pill px-4 text-white mt-2" style="background:#6f42c1;"><i class="bi bi-send me-1"></i><?= $t('Cast Vote', 'Piga Kura') ?></button>
                        <?php if (!empty($v['closes_at'])): ?><small class="text-muted ms-2"><?= $t('Closes', 'Inafunga') ?>: <?= date('d M Y, h:i A', strtotime($v['closes_at'])) ?></small><?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; endif; ?>

    <!-- Published results -->
    <?php if (!empty($published)): ?>
    <h5 class="fw-bold text-dark mb-3 mt-4"><i class="bi bi-bar-chart me-2"></i><?= $t('Published Results', 'Matokeo Yaliyotangazwa') ?></h5>
    <?php foreach ($published as $v):
        $optStmt->execute([$v['id']]);
        $opts = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        $cnt = $pdo->prepare("SELECT option_id, COUNT(*) n FROM vote_ballots WHERE vote_id = ? GROUP BY option_id");
        $cnt->execute([$v['id']]);
        $counts = [];
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int) $r['option_id']] = (int) $r['n'];
        $tally = vk_vote_tally($opts, $counts);
        $max = 1; foreach ($tally as $tt) $max = max($max, $tt['votes']);
        $voted = (int) $pdo->query("SELECT COUNT(*) FROM vote_participation WHERE vote_id = " . (int) $v['id'])->fetchColumn();
        $elig  = (int) $pdo->query("SELECT COUNT(*) FROM vote_eligibility WHERE vote_id = " . (int) $v['id'])->fetchColumn();
    ?>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 fw-bold"><?= safe_output($v['title']) ?></h6>
                <small class="text-muted"><i class="bi bi-people me-1"></i><?= $voted ?>/<?= $elig ?> (<?= vk_turnout_percent($voted, $elig) ?>%)</small>
            </div>
            <?php foreach ($tally as $tt): ?>
            <div class="mb-2">
                <div class="d-flex justify-content-between small"><span><?= safe_output($tt['label']) ?></span><span class="fw-bold"><?= $tt['votes'] ?></span></div>
                <div class="progress" style="height:8px;"><div class="progress-bar" style="width:<?= (int) round(($tt['votes'] / $max) * 100) ?>%;background:#6f42c1;"></div></div>
            </div>
            <?php endforeach; ?>
        </div></div>
    <?php endforeach; endif; ?>
</div>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
$('.vote-form').on('submit', function(e){
    e.preventDefault();
    const voteId = $(this).data('vote');
    const optionId = $(this).find('input[name=option_id]:checked').val();
    if(!optionId){ Swal.fire('Error', isSw?'Chagua jibu kwanza.':'Please choose an option.', 'warning'); return; }
    Swal.fire({ title:isSw?'Thibitisha kura yako':'Confirm your vote', text:isSw?'Hutaweza kubadilisha baada ya kutuma.':'You cannot change it after submitting.', icon:'question', showCancelButton:true, confirmButtonText:isSw?'Ndio, Piga Kura':'Yes, cast my vote', confirmButtonColor:'#6f42c1' })
    .then(r=>{ if(!r.isConfirmed) return;
        $.post('/actions/cast_vote', { vote_id:voteId, option_id:optionId }, res=>{
            if(res.success){ Swal.fire({icon:'success',title:isSw?'Asante!':'Thank you!',text:res.message,timer:1600,showConfirmButton:false}).then(()=>location.reload()); }
            else Swal.fire('Error', res.message||'Error','error');
        },'json').fail(()=>Swal.fire('Error','Server error','error'));
    });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
