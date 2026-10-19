<?php
// app/constant/reports/social_fund_statement.php — the Social Fund over a period: paid in, welfare paid out, and the balance.
// Leadership report; figures come from includes/vicoba_reports.php.
ob_start();
global $pdo;
require_once __DIR__ . "/../../../roots.php";
includeHeader();
require_once ROOT_DIR . "/includes/vicoba_reports.php";
// Leadership only — see vr_can_view() for why this is not the "vicoba_reports" key.
if (!vr_can_view()) {
    requireViewPermission("approve_loan");
}

$is_sw = ($_SESSION["preferred_language"] ?? "en") === "sw";
$t   = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
$num = fn($v) => number_format((float) $v, 2);
$day = fn($d) => $d ? date("d M Y", strtotime((string) $d)) : "—";

[$from, $to] = vr_period($pdo, $_GET);
$r = vr_social_fund($pdo, $from, $to);

vr_open($pdo, 'reports/social_fund', $t('What members paid in, the welfare paid out, and the balance', 'Walichochangia wanachama, misaada iliyotolewa, na salio'),
        $day($from) . ' – ' . $day($to), vr_period_filter($from, $to, $is_sw), $is_sw);

vr_kpis([
    [$t('Balance brought forward (TZS)', 'Salio la mwanzo (TZS)'), number_format($r['opening']), 'secondary'],
    [$t('Paid in (TZS)', 'Kilichochangwa (TZS)'), number_format($r['paid_in']), 'success'],
    [$t('Welfare paid out (TZS)', 'Misaada iliyotolewa (TZS)'), number_format($r['paid_out']), 'danger'],
    [$t('Closing balance (TZS)', 'Salio la mwisho (TZS)'), number_format($r['closing']), $r['closing'] < 0 ? 'danger' : 'primary'],
]);
?>
    <?php if ($r['closing'] < 0): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= $t('The Social Fund shows a negative balance: more welfare has been paid out than members have paid into the fund. If the group paid welfare from general savings before it began recording Social Fund contributions, record an opening Social Fund contribution to bring this in line.', 'Mfuko wa Jamii unaonyesha salio hasi: misaada iliyotolewa ni mingi kuliko michango ya mfuko. Kama kikundi kililipa misaada kutoka akiba ya jumla kabla ya kuanza kurekodi michango ya Mfuko wa Jamii, rekodi mchango wa mwanzo wa mfuko ili kusawazisha.') ?></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><?= $t('Paid in, by member', 'Michango, kwa mwanachama') ?> <span class="text-muted fw-normal">(<?= count($r['members']) ?>)</span></div>
                <div class="card-body">
                    <?php if (!$r['members']): ?><p class="text-muted mb-0"><?= $t('No Social Fund contribution in this period.', 'Hakuna mchango wa Mfuko wa Jamii kwa kipindi hiki.') ?></p><?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>#</th><th><?= $t('Member', 'Mwanachama') ?></th><th class="text-end"><?= $t('Payments', 'Malipo') ?></th><th class="text-end"><?= $t('Paid in', 'Kiasi') ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($r['members'] as $n => $m): ?>
                                <tr><td><?= $n + 1 ?></td><td><?= htmlspecialchars($m['name']) ?></td><td class="text-end"><?= (int) $m['payments'] ?></td><td class="text-end fw-bold"><?= $num($m['paid']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold"><tr><td colspan="3"><?= $t('Total', 'Jumla') ?></td><td class="text-end"><?= $num($r['paid_in']) ?></td></tr></tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-bold"><?= $t('Welfare paid out', 'Misaada iliyotolewa') ?> <span class="text-muted fw-normal">(<?= count($r['welfare']) ?>)</span></div>
                <div class="card-body">
                    <?php if (!$r['welfare']): ?><p class="text-muted mb-0"><?= $t('No welfare was paid out in this period.', 'Hakuna msaada uliotolewa kwa kipindi hiki.') ?></p><?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th><?= $t('Date', 'Tarehe') ?></th><th><?= $t('Member', 'Mwanachama') ?></th><th><?= $t('For', 'Kwa ajili ya') ?></th><th class="text-end"><?= $t('Amount', 'Kiasi') ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($r['welfare'] as $w): ?>
                                <tr><td><?= $day($w['paid_on']) ?></td><td><?= htmlspecialchars((string) $w['name']) ?></td><td><?= htmlspecialchars((string) $w['what']) ?></td><td class="text-end fw-bold text-danger"><?= $num($w['amount']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold"><tr><td colspan="3"><?= $t('Total', 'Jumla') ?></td><td class="text-end"><?= $num($r['paid_out']) ?></td></tr></tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><?= $t('Summary', 'Muhtasari') ?></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td><?= $t('Balance brought forward', 'Salio la mwanzo') ?> (<?= $day($from) ?>)</td><td class="text-end"><?= $num($r['opening']) ?></td></tr>
                        <tr><td><?= $t('Add: paid in by members', 'Ongeza: michango ya wanachama') ?></td><td class="text-end text-success"><?= $num($r['paid_in']) ?></td></tr>
                        <tr><td><?= $t('Less: welfare paid out', 'Toa: misaada iliyotolewa') ?></td><td class="text-end text-danger">− <?= $num($r['paid_out']) ?></td></tr>
                        <tr class="table-light fw-bold"><td><?= $t('Closing balance', 'Salio la mwisho') ?> (<?= $day($to) ?>)</td><td class="text-end"><?= $num($r['closing']) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php vr_close(); includeFooter(); ob_end_flush(); ?>
