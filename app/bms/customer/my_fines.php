<?php
// app/bms/customer/my_fines.php — a member views their OWN fines (view-only).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/require_login.php'; // authenticated member
require_once __DIR__ . '/../../../includes/fine_helpers.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$uid = (int) ($_SESSION['user_id'] ?? 0);
$cstmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
$cstmt->execute([$uid]);
$customer_id = (int) ($cstmt->fetchColumn() ?: 0);

$fines = [];
if ($customer_id > 0) {
    $fstmt = $pdo->prepare("
        SELECT f.*, m.title AS meeting_title
          FROM fines f LEFT JOIN meetings m ON f.meeting_id = m.id
         WHERE f.customer_id = ?
         ORDER BY f.created_at DESC
    ");
    $fstmt->execute([$customer_id]);
    $fines = $fstmt->fetchAll(PDO::FETCH_ASSOC);
}
$summary = vk_fine_summary($fines);

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #dc3545 !important;">
        <div class="card-body p-3 p-md-4 bg-white">
            <h3 class="fw-bold mb-1 text-danger"><i class="bi bi-cash-coin me-2"></i><?= $t('My Fines', 'Faini Zangu') ?></h3>
            <p class="text-muted mb-0 small"><?= $t('Fines recorded against your account', 'Faini zilizorekodiwa kwenye akaunti yako') ?></p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['warning', 'bi-hourglass-split', $t('Owing (Pending)', 'Deni (Zinasubiri)'), $summary['pending']],
            ['success', 'bi-check2-circle', $t('Paid', 'Zilizolipwa'), $summary['paid']],
            ['secondary', 'bi-slash-circle', $t('Waived', 'Zilizosamehewa'), $summary['waived']],
        ] as [$color, $icon, $label, $val]): ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex justify-content-between">
                <div><h4 class="mb-0 fw-bold text-<?= $color ?>"><?= number_format($val, 2) ?> <small class="text-muted">TZS</small></h4><p class="mb-0 text-muted small"><?= $label ?></p></div>
                <div class="align-self-center"><i class="bi <?= $icon ?> text-<?= $color ?>" style="font-size:2rem;opacity:.3;"></i></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <?php if (empty($fines)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
                <?= $t('You have no fines. Well done!', 'Huna faini yoyote. Hongera!') ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-center">
                        <tr>
                            <th style="width:50px">#</th>
                            <th class="text-start"><?= $t('Reason', 'Sababu') ?></th>
                            <th><?= $t('Amount', 'Kiasi') ?></th>
                            <th><?= $t('Date', 'Tarehe') ?></th>
                            <th><?= $t('Status', 'Hali') ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php foreach ($fines as $i => $f): $badge = vk_fine_status_badge($f['status']); ?>
                        <tr>
                            <td class="text-center"><strong><?= $i + 1 ?></strong></td>
                            <td><?= safe_output($f['reason'] ?: '—') ?></td>
                            <td class="text-center fw-bold text-danger"><?= number_format($f['amount'], 2) ?></td>
                            <td class="text-center"><?= $f['created_at'] ? date('d M Y', strtotime($f['created_at'])) : '—' ?></td>
                            <td class="text-center"><span class="badge bg-<?= $badge ?>"><?= ucfirst($f['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= $t('Payments are confirmed by the group leadership.', 'Malipo huthibitishwa na uongozi wa kikundi.') ?></p>
        <?php endif; ?>
    </div></div>
</div>

<?php includeFooter(); ob_end_flush(); ?>
