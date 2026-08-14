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

// The group asked for a member to be able to see their own fines AND everyone
// else's. Own fines stay the default: this page is reached from "My Fines", and
// opening it on somebody else's list would be a surprise.
//
// This is the same disclosure the group already makes elsewhere — the Group
// Financial Ledger shows every member's contributions and shortfall to any member —
// so it widens no boundary that was not already open.
$view = (($_GET['view'] ?? 'mine') === 'all') ? 'all' : 'mine';

$fines = [];
if ($view === 'all') {
    $fines = $pdo->query("
        SELECT f.*, m.title AS meeting_title,
               TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
          FROM fines f
          LEFT JOIN meetings m ON f.meeting_id = m.id
          LEFT JOIN customers c ON c.customer_id = f.customer_id
         ORDER BY f.created_at DESC, f.fine_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($customer_id > 0) {
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
$fined_members = $view === 'all'
    ? count(array_unique(array_column($fines, 'customer_id')))
    : 0;

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render(
            $pdo,
            $view === 'all'
                ? ($is_sw ? 'FAINI ZA WANACHAMA WOTE' : "ALL MEMBERS' FINES")
                : ($is_sw ? 'FAINI ZANGU' : 'MY FINES'),
            $view === 'all' ? '' : $member_name
        ); ?>
    </div>

    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-left:5px solid #dc3545 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-danger"><i class="bi bi-cash-coin me-2"></i>
                    <?= $view === 'all' ? $t("All Members' Fines", 'Faini za Wanachama Wote') : $t('My Fines', 'Faini Zangu') ?></h3>
                <p class="text-muted mb-0 small">
                    <?= $view === 'all'
                        ? $t('Every fine recorded in the group', 'Faini zote zilizorekodiwa kwenye kikundi')
                        : $t('Fines recorded against your account', 'Faini zilizorekodiwa kwenye akaunti yako') ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?= getUrl('my_fines') ?>"
                       class="btn <?= $view === 'mine' ? 'btn-danger' : 'btn-outline-danger' ?> rounded-start-pill px-3 fw-bold">
                        <?= $t('Mine', 'Zangu') ?>
                    </a>
                    <a href="<?= getUrl('my_fines') ?>?view=all"
                       class="btn <?= $view === 'all' ? 'btn-danger' : 'btn-outline-danger' ?> rounded-end-pill px-3 fw-bold">
                        <?= $t('All Members', 'Wanachama Wote') ?>
                    </a>
                </div>
                <button type="button" class="btn btn-outline-primary rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer me-2"></i><?= $t('Print', 'Chapisha') ?></button>
            </div>
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
                <div class="text-muted text-truncate" style="font-size:.7rem;"><i class="bi <?= $icon ?>"></i> <?= $label ?> <span class="d-none d-sm-inline">TSh</span></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <?php if (empty($fines)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
                <?= $view === 'all'
                    ? $t('No fines have been recorded in the group.', 'Hakuna faini iliyorekodiwa kwenye kikundi.')
                    : $t('You have no fines. Well done!', 'Huna faini yoyote. Hongera!') ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-light text-muted small">
                        <tr>
                            <th style="width:44px">#</th>
                            <?php if ($view === 'all'): ?><th><?= $t('Member', 'Mwanachama') ?></th><?php endif; ?>
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
                            <?php if ($view === 'all'): ?>
                            <td class="fw-semibold<?= (int) $f['customer_id'] === $customer_id ? ' text-danger' : '' ?>">
                                <?= safe_output($f['member_name'] ?: '—') ?>
                                <?php if ((int) $f['customer_id'] === $customer_id): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1"><?= $t('You', 'Wewe') ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?= safe_output($f['reason'] ?: '—') ?></td>
                            <td class="text-end fw-bold text-danger text-nowrap"><?= number_format($f['amount'], 0) ?></td>
                            <td class="text-nowrap"><?= $f['created_at'] ? date('d M Y', strtotime($f['created_at'])) : '—' ?></td>
                            <td class="text-center"><span class="badge bg-<?= $badge ?>"><?= ucfirst($f['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="<?= $view === 'all' ? 3 : 2 ?>" class="text-end">
                                <?= $view === 'all' ? $t('Group total owing', 'Jumla ya deni la kikundi') : $t('Total owing', 'Jumla ya deni') ?>
                            </td>
                            <td class="text-end text-danger text-nowrap"><?= number_format($summary['pending'], 0) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($view === 'all'): ?>
            <!-- Scope of the list, and it DOES print: a printed page of other people's
                 fines has to say how many members it covers, or the reader cannot tell
                 whether they are holding all of it. -->
            <p class="text-muted small mt-3 mb-0">
                <i class="bi bi-people me-1"></i><?= count($fines) ?> <?= $t('fines across', 'faini kwa') ?>
                <?= $fined_members ?> <?= $t('members.', 'wanachama.') ?>
            </p>
            <?php endif; ?>
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
