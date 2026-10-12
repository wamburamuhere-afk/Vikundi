<?php
// app/constant/reports/shares_register.php — each member's share capital and share of the group, as at a date.
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

$as_at = (string) ($_GET['as_at'] ?? '');
if (!dv_valid_period($as_at, $as_at)) {
    $as_at = date('Y-m-d');
}
$r = vr_shares_register($pdo, $as_at);
$count = fn($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

$filters = '<div class="col-md-3"><label class="form-label fw-bold">' . $t('As at', 'Hadi tarehe') . '</label>'
         . '<input type="date" name="as_at" class="form-control" value="' . htmlspecialchars($as_at) . '" required></div>';

vr_open($pdo, 'reports/shares', $t("Each member's share capital and their part of the group", 'Mtaji wa hisa wa kila mwanachama na sehemu yake ya kikundi'),
        $t('As at', 'Hadi') . ' ' . $day($as_at), $filters, $is_sw);

vr_kpis([
    [$t('Members with shares', 'Wanachama wenye hisa'), number_format(count($r['rows'])), 'primary'],
    [$t('Share capital (TZS)', 'Mtaji wa hisa (TZS)'), number_format($r['total']), 'success'],
    [$t('Shares issued', 'Hisa zilizotolewa'), $r['share_price'] > 0 ? $count($r['total_shares']) : '—', 'primary'],
    [$t('Price per share (TZS)', 'Bei ya hisa (TZS)'), $r['share_price'] > 0 ? number_format($r['share_price']) : $t('Not set', 'Haijawekwa'), 'secondary'],
]);
?>
    <?php if ($r['share_price'] <= 0): ?>
    <div class="alert alert-warning"><?= $t('No price per share is set, so share counts cannot be shown. Set it under Administration → Main Settings → Shares & Funds.', 'Bei ya hisa haijawekwa, hivyo idadi ya hisa haiwezi kuonyeshwa. Iweke kwenye Utawala → Mipangilio Mikuu → Hisa na Mifuko.') ?></div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold"><?= $t('Shares register', 'Daftari la hisa') ?> · <?= $t('as at', 'hadi') ?> <?= $day($as_at) ?></div>
        <div class="card-body">
            <?php if (!$r['rows']): ?><p class="text-muted mb-0"><?= $t('No member held shares on this date.', 'Hakuna mwanachama aliyekuwa na hisa tarehe hii.') ?></p><?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>#</th><th><?= $t('Member', 'Mwanachama') ?></th><th class="text-end"><?= $t('Share capital', 'Mtaji wa hisa') ?></th>
                        <th class="text-end"><?= $t('Shares held', 'Idadi ya hisa') ?></th><th class="text-end"><?= $t('% of group', '% ya kikundi') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($r['rows'] as $n => $m): ?>
                        <tr>
                            <td><?= $n + 1 ?></td>
                            <td><?= htmlspecialchars($m['name']) ?></td>
                            <td class="text-end fw-bold"><?= $num($m['capital']) ?></td>
                            <td class="text-end"><?= $r['share_price'] > 0 ? $count($m['shares']) : '—' ?></td>
                            <td class="text-end"><?= number_format($m['pct'], 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr>
                        <td colspan="2"><?= $t('Total', 'Jumla') ?></td><td class="text-end"><?= $num($r['total']) ?></td>
                        <td class="text-end"><?= $r['share_price'] > 0 ? $count($r['total_shares']) : '—' ?></td><td class="text-end">100%</td>
                    </tr></tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php vr_close(); includeFooter(); ob_end_flush(); ?>
