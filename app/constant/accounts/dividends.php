<?php
// app/constant/accounts/dividends.php — leadership: work out, confirm and
// distribute a year-end dividend. See includes/dividends.php for the rules.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();
requireViewPermission('dividends');
require_once ROOT_DIR . '/includes/dividends.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };
$tzs = fn($v) => 'TZS ' . number_format((float) $v, 2);

$raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$dv  = dv_settings($raw);
[$def_from, $def_to] = dv_current_year($dv['year_start_month'], date('Y-m-d'));
$def_to = min($def_to, date('Y-m-d')); // a period cannot end in the future

// Either one stored run, or a live preview of a period.
$run_id = isset($_GET['run']) && ctype_digit((string) $_GET['run']) ? (int) $_GET['run'] : 0;
$run    = $run_id > 0 ? dv_run($pdo, $run_id) : null;

if ($run) {
    $from = $run['period_from'];
    $to   = $run['period_to'];
    $totals  = ['interest' => (float) $run['interest_income'], 'penalties' => (float) $run['penalty_income'], 'fines' => (float) $run['fines_income'], 'expenses' => (float) $run['expenses']];
    $figures = dv_surplus($totals, (float) $run['distribute_pct']);
    $pct     = (float) $run['distribute_pct'];
    $total_shares = (float) $run['total_share_capital'];
    $lines = array_map(static fn($l) => ['name' => $l['name'], 'shares' => (float) $l['share_capital'], 'amount' => (float) $l['amount']], dv_run_lines($pdo, $run_id));
} else {
    $from = (string) ($_GET['from'] ?? $def_from);
    $to   = (string) ($_GET['to'] ?? $def_to);
    if (!dv_valid_period($from, $to)) { $from = $def_from; $to = $def_to; }
    $p = dv_preview($pdo, $from, $to);
    $totals = $p['totals']; $figures = $p['figures']; $pct = $p['distribute_pct']; $total_shares = $p['total_shares']; $lines = $p['lines'];
}

$runs   = dv_runs($pdo);
$social = fb_social_fund_position($pdo);
$status_badge = ['confirmed' => ['warning', $t('Confirmed — not yet distributed', 'Limethibitishwa — bado halijagawanywa')],
                 'distributed' => ['success', $t('Distributed', 'Limegawanywa')],
                 'cancelled' => ['secondary', $t('Cancelled', 'Limeghairiwa')]];
$method_label = ['cash' => $t('Paid out in cash', 'Hulipwa taslimu'), 'shares' => $t("Added to members' shares", 'Huongezwa kwenye hisa za wanachama')];
$period_label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'TAARIFA YA GAWIO' : 'DIVIDEND STATEMENT', ($run ? $run['reference'] . ' · ' : '') . $period_label); ?>
    </div>

    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-left:5px solid #1a7f4b !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-success"><i class="bi bi-pie-chart-fill me-2"></i><?= $t('Dividends', 'Gawio') ?>
                    <?php if ($run): [$bc, $bl] = $status_badge[$run['status']]; ?><span class="badge bg-<?= $bc ?> fs-6 align-middle ms-2"><?= htmlspecialchars($run['reference']) ?> · <?= $bl ?></span><?php endif; ?></h3>
                <p class="text-muted mb-0 small"><?= $t("What the group earned, shared out by each member's share capital", 'Mapato ya kikundi, yanagawanywa kwa mtaji wa hisa wa kila mwanachama') ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($run && $run['status'] === 'confirmed'): ?>
                    <?php if (canApprove('dividends')): ?><button type="button" class="btn btn-success rounded-pill px-3 dvBtn" data-action="distribute"><i class="bi bi-send-check me-1"></i><?= $t('Distribute now', 'Gawanya sasa') ?></button><?php endif; ?>
                    <?php if (canDelete('dividends')): ?><button type="button" class="btn btn-outline-danger rounded-pill px-3 dvBtn" data-action="cancel"><?= $t('Cancel run', 'Ghairi') ?></button><?php endif; ?>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-primary rounded-pill px-3" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
                <?php if ($run): ?><a class="btn btn-light rounded-pill px-3" href="<?= getUrl('dividends') ?>"><i class="bi bi-arrow-left me-1"></i><?= $t('New calculation', 'Hesabu mpya') ?></a><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$run): ?>
    <div class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-body">
            <form method="get" action="<?= getUrl('dividends') ?>" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label fw-bold"><?= $t('Period from', 'Kuanzia') ?></label><input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" required></div>
                <div class="col-md-3"><label class="form-label fw-bold"><?= $t('Period to', 'Hadi') ?></label><input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" max="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-3"><button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-calculator me-1"></i><?= $t('Calculate', 'Hesabu') ?></button></div>
                <div class="col-md-3 small text-muted"><?= $t('This is a preview. Nothing is saved until you confirm.', 'Haya ni makadirio. Hakuna kinachohifadhiwa hadi uthibitishe.') ?></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><?= $t('Surplus for', 'Ziada ya') ?> <?= $period_label ?></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td><?= $t('Loan interest received', 'Riba ya mikopo iliyopokelewa') ?></td><td class="text-end"><?= $tzs($totals['interest']) ?></td></tr>
                        <tr><td><?= $t('Loan penalties received', 'Adhabu za mikopo zilizopokelewa') ?></td><td class="text-end"><?= $tzs($totals['penalties']) ?></td></tr>
                        <tr><td><?= $t('Fines paid', 'Faini zilizolipwa') ?></td><td class="text-end"><?= $tzs($totals['fines']) ?></td></tr>
                        <tr class="table-light fw-bold"><td><?= $t('Income', 'Mapato') ?></td><td class="text-end"><?= $tzs($figures['income']) ?></td></tr>
                        <tr><td><?= $t('Less: expenses and petty cash paid', 'Toa: matumizi na fedha ndogo') ?></td><td class="text-end text-danger">− <?= $tzs($figures['expenses']) ?></td></tr>
                        <tr class="table-light fw-bold"><td><?= $t('Surplus', 'Ziada') ?></td><td class="text-end <?= $figures['surplus'] < 0 ? 'text-danger' : '' ?>"><?= $tzs($figures['surplus']) ?></td></tr>
                        <tr><td><?= $t('Kept as reserve', 'Inabaki kama akiba') ?> (<?= rtrim(rtrim(number_format(100 - $pct, 2), '0'), '.') ?>%)</td><td class="text-end"><?= $tzs($figures['reserve']) ?></td></tr>
                        <tr class="table-success fw-bold fs-5"><td><?= $t('To share out', 'Ya kugawa') ?> (<?= rtrim(rtrim(number_format($pct, 2), '0'), '.') ?>%)</td><td class="text-end"><?= $tzs($figures['distributable']) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><?= $t('Kept apart — never part of a dividend', 'Huwekwa kando — haviingii kwenye gawio') ?></div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tr><td><?= $t('Share capital at period end', 'Mtaji wa hisa mwisho wa kipindi') ?></td><td class="text-end fw-bold"><?= $tzs($total_shares) ?></td></tr>
                        <tr><td><?= $t('Social Fund paid in', 'Mfuko wa Jamii uliochangwa') ?></td><td class="text-end"><?= $tzs($social['paid_in']) ?></td></tr>
                        <tr><td><?= $t('Welfare paid from the Social Fund', 'Misaada iliyolipwa kutoka Mfuko wa Jamii') ?></td><td class="text-end">− <?= $tzs($social['paid_out']) ?></td></tr>
                        <tr class="table-light fw-bold"><td><?= $t('Social Fund balance', 'Salio la Mfuko wa Jamii') ?></td><td class="text-end <?= $social['balance'] < 0 ? 'text-danger' : '' ?>"><?= $tzs($social['balance']) ?></td></tr>
                    </table>
                    <p class="small text-muted mb-0"><?= $t('Death expenses and member assistance are charged to the Social Fund, so they do not reduce the dividend. Membership deposits and loan principal are not income either.', 'Gharama za misiba na misaada ya wanachama hulipwa kutoka Mfuko wa Jamii, hivyo hazipunguzi gawio. Amana za uanachama na mikopo yenyewe si mapato.') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><?= $t("Each member's dividend", 'Gawio la kila mwanachama') ?> <span class="text-muted fw-normal">(<?= count($lines) ?>)</span></span>
            <?php if ($run): ?><span class="small text-muted"><?= $method_label[$run['method']] ?></span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$lines): ?>
                <p class="text-muted mb-0"><?= $t('No member held shares at the end of this period. Set a share price in Group Settings and record share contributions first.', 'Hakuna mwanachama mwenye hisa mwishoni mwa kipindi hiki. Weka bei ya hisa kwenye Mipangilio na urekodi michango ya hisa kwanza.') ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>#</th><th><?= $t('Member', 'Mwanachama') ?></th><th class="text-end"><?= $t('Share capital', 'Mtaji wa hisa') ?></th>
                        <th class="text-end"><?= $t('% of shares', '% ya hisa') ?></th><th class="text-end"><?= $t('Dividend', 'Gawio') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($lines as $n => $l): ?>
                        <tr>
                            <td><?= $n + 1 ?></td>
                            <td><?= htmlspecialchars($l['name']) ?></td>
                            <td class="text-end"><?= number_format($l['shares'], 2) ?></td>
                            <td class="text-end"><?= $total_shares > 0 ? number_format($l['shares'] / $total_shares * 100, 2) : '0.00' ?>%</td>
                            <td class="text-end fw-bold text-success"><?= number_format($l['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr>
                        <td colspan="2"><?= $t('Total', 'Jumla') ?></td><td class="text-end"><?= number_format($total_shares, 2) ?></td><td class="text-end">100%</td>
                        <td class="text-end"><?= number_format(array_sum(array_column($lines, 'amount')), 2) ?></td>
                    </tr></tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$run && $lines && $figures['distributable'] > 0 && canCreate('dividends')): ?>
    <div class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-3">
            <div>
                <label class="form-label fw-bold"><?= $t('How is this dividend paid?', 'Gawio hili hulipwaje?') ?></label>
                <select id="dvMethod" class="form-select" style="min-width:280px;">
                    <option value="shares"><?= $method_label['shares'] ?></option>
                    <option value="cash"><?= $method_label['cash'] ?></option>
                </select>
                <div class="form-text"><?= $t('One choice applies to every member in this run.', 'Chaguo moja hutumika kwa wanachama wote.') ?></div>
            </div>
            <button type="button" class="btn btn-success rounded-pill px-4" id="dvConfirm"><i class="bi bi-check2-circle me-1"></i><?= $t('Confirm these figures', 'Thibitisha hesabu hizi') ?></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($runs): ?>
    <div class="card border-0 shadow-sm d-print-none">
        <div class="card-header bg-white fw-bold"><?= $t('Dividend runs', 'Magawio yaliyopita') ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th><?= $t('Reference', 'Kumbukumbu') ?></th><th><?= $t('Period', 'Kipindi') ?></th><th class="text-end"><?= $t('Shared out', 'Kilichogawanywa') ?></th><th><?= $t('Paid as', 'Hulipwa kama') ?></th><th><?= $t('Status', 'Hali') ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $r): [$bc, $bl] = $status_badge[$r['status']]; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($r['reference']) ?></td>
                            <td><?= date('d M Y', strtotime($r['period_from'])) ?> – <?= date('d M Y', strtotime($r['period_to'])) ?></td>
                            <td class="text-end"><?= number_format((float) $r['distributable'], 2) ?></td>
                            <td><?= $method_label[$r['method']] ?></td>
                            <td><span class="badge bg-<?= $bc ?>"><?= $bl ?></span></td>
                            <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= getUrl('dividends') ?>?run=<?= (int) $r['id'] ?>"><?= $t('Open', 'Fungua') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const isSw = <?= json_encode($is_sw) ?>;
    const tr = (en, sw) => isSw ? sw : en;
    const url = '<?= getUrl("actions/dividend_action") ?>';
    const base = '<?= getUrl("dividends") ?>';

    function send(data, title, text) {
        Swal.fire({ icon: 'question', title: title, text: text, showCancelButton: true, confirmButtonText: tr('Yes', 'Ndiyo'), cancelButtonText: tr('No', 'Hapana') })
            .then(function (r) {
                if (!r.isConfirmed) return;
                $.ajax({ url: url, type: 'POST', dataType: 'json', data: data })
                    .done(function (res) {
                        Swal.fire({ icon: res.success ? 'success' : 'error', title: res.message, timer: res.success ? 1500 : undefined, showConfirmButton: !res.success })
                            .then(function () { if (res.success) location.href = base + '?run=' + res.run_id; });
                    })
                    .fail(function (xhr) { Swal.fire(tr('Error', 'Hitilafu'), (xhr.responseJSON && xhr.responseJSON.message) || tr('Server error.', 'Hitilafu ya seva.'), 'error'); });
            });
    }

    $('#dvConfirm').on('click', function () {
        send({ action: 'confirm', period_from: <?= json_encode($from) ?>, period_to: <?= json_encode($to) ?>, method: $('#dvMethod').val() },
             tr('Confirm this dividend?', 'Thibitisha gawio hili?'),
             tr('The figures are frozen. Nothing is paid until you distribute.', 'Hesabu zitafungwa. Hakuna kinacholipwa hadi ugawanye.'));
    });
    $('.dvBtn').on('click', function () {
        const action = $(this).data('action');
        send({ action: action, run_id: <?= (int) $run_id ?> },
             action === 'distribute' ? tr('Distribute this dividend now?', 'Gawanya gawio hili sasa?') : tr('Cancel this run?', 'Ghairi gawio hili?'),
             action === 'distribute' ? tr('This cannot be undone.', 'Hatua hii haiwezi kutenduliwa.') : '');
    });
})();
</script>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
