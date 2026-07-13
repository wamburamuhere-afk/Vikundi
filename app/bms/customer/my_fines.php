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

$member_name = '';
if ($customer_id > 0) {
    $nm = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM customers WHERE customer_id = ?");
    $nm->execute([$customer_id]);
    $member_name = (string) $nm->fetchColumn();
}

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
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'FAINI ZANGU' : 'MY FINES', $member_name); ?>
    </div>

    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-left:5px solid #dc3545 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-danger"><i class="bi bi-cash-coin me-2"></i><?= $t('My Fines', 'Faini Zangu') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Fines recorded against your account', 'Faini zilizorekodiwa kwenye akaunti yako') ?></p>
            </div>
            <button type="button" class="btn btn-outline-primary rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer me-2"></i><?= $t('Print', 'Chapisha') ?></button>
        </div>
    </div>

    <!-- Compact 3-across summary chips: stay side-by-side even on phones so they
         don't stack into three tall cards and push the table down. -->
    <div class="row g-2 mb-4">
        <?php foreach ([
            ['warning', 'bi-hourglass-split', $t('Owing', 'Deni'), $summary['pending']],
            ['success', 'bi-check2-circle', $t('Paid', 'Zilizolipwa'), $summary['paid']],
            ['secondary', 'bi-slash-circle', $t('Waived', 'Zilizosamehewa'), $summary['waived']],
        ] as [$color, $icon, $label, $val]): ?>
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-1 text-center">
                <div class="fw-bold text-<?= $color ?>" style="font-size:1rem;line-height:1.15;"><?= number_format($val, 0) ?></div>
                <div class="text-muted text-truncate" style="font-size:.7rem;"><i class="bi <?= $icon ?>"></i> <?= $label ?> <span class="d-none d-sm-inline">TZS</span></div>
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
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light text-muted small">
                        <tr>
                            <th style="width:44px">#</th>
                            <th><?= $t('Reason', 'Sababu') ?></th>
                            <th class="text-end text-nowrap"><?= $t('Amount', 'Kiasi') ?></th>
                            <th class="text-nowrap"><?= $t('Date', 'Tarehe') ?></th>
                            <th class="text-center"><?= $t('Status', 'Hali') ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php foreach ($fines as $i => $f): $badge = vk_fine_status_badge($f['status']); ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><?= safe_output($f['reason'] ?: '—') ?></td>
                            <td class="text-end fw-bold text-danger text-nowrap"><?= number_format($f['amount'], 0) ?></td>
                            <td class="text-nowrap"><?= $f['created_at'] ? date('d M Y', strtotime($f['created_at'])) : '—' ?></td>
                            <td class="text-center"><span class="badge bg-<?= $badge ?>"><?= ucfirst($f['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="2" class="text-end"><?= $t('Total owing', 'Jumla ya deni') ?></td>
                            <td class="text-end text-danger text-nowrap"><?= number_format($summary['pending'], 0) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-muted small mt-3 mb-0 d-print-none"><i class="bi bi-info-circle me-1"></i><?= $t('Payments are confirmed by the group leadership.', 'Malipo huthibitishwa na uongozi wa kikundi.') ?></p>
        <?php endif; ?>
    </div></div>
</div>

<style>
    /* Print the "Total owing" row once at the end; a <tfoot> otherwise repeats on
       every page and overlaps the fixed footer. Keep each row intact across breaks. */
    @media print {
        .table tfoot { display: table-row-group; }
        .table tfoot td { border-top: 2px solid #333 !important; }
        .table tbody tr { page-break-inside: avoid; }
    }
</style>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
