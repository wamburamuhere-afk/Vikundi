<?php
// app/constant/reports/loan_repayments_report.php — repayments received in a period and the income they brought in.
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
$r = vr_loan_repayments($pdo, $from, $to);

vr_open($pdo, 'reports/loan_repayments', $t('Repayments received, and what the group earned from lending', 'Marejesho yaliyopokelewa, na mapato ya kikundi kutokana na mikopo'),
        $day($from) . ' – ' . $day($to), vr_period_filter($from, $to, $is_sw), $is_sw);

vr_kpis([
    [$t('Repayments', 'Marejesho'), number_format(count($r['rows'])), 'primary'],
    [$t('Received (TZS)', 'Kilichopokelewa (TZS)'), number_format($r['totals']['amount']), 'primary'],
    [$t('Principal returned (TZS)', 'Mkopo uliorejeshwa (TZS)'), number_format($r['totals']['principal']), 'secondary'],
    [$t('Interest earned (TZS)', 'Riba (TZS)'), number_format($r['totals']['interest']), 'success'],
    [$t('Penalties earned (TZS)', 'Adhabu (TZS)'), number_format($r['totals']['penalty']), 'success'],
]);
?>
    <div class="alert alert-success border-0 shadow-sm">
        <i class="bi bi-graph-up-arrow me-2"></i><?= $t('Income from lending in this period', 'Mapato ya mikopo kwa kipindi hiki') ?>:
        <strong>TZS <?= $num($r['totals']['income']) ?></strong>
        <span class="text-muted small ms-1"><?= $t('(interest + penalties). This is what a dividend is shared from; principal returned is not income.', '(riba + adhabu). Gawio hutokana na kiasi hiki; mkopo uliorejeshwa si mapato.') ?></span>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold"><?= $t('Repayments', 'Marejesho') ?> · <?= $day($from) ?> – <?= $day($to) ?></div>
        <div class="card-body">
            <?php if (!$r['rows']): ?><p class="text-muted mb-0"><?= $t('No repayment was received in this period.', 'Hakuna rejesho lililopokelewa kwa kipindi hiki.') ?></p><?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th><?= $t('Date', 'Tarehe') ?></th><th><?= $t('Loan', 'Mkopo') ?></th><th><?= $t('Member', 'Mwanachama') ?></th><th><?= $t('Type', 'Aina') ?></th>
                        <th class="text-end"><?= $t('Received', 'Kiasi') ?></th><th class="text-end"><?= $t('Principal', 'Mkopo') ?></th>
                        <th class="text-end"><?= $t('Interest', 'Riba') ?></th><th class="text-end"><?= $t('Penalty', 'Adhabu') ?></th>
                        <th><?= $t('Account', 'Akaunti') ?></th><th><?= $t('Receipt', 'Risiti') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($r['rows'] as $p): ?>
                        <tr>
                            <td><?= $day($p['payment_date']) ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($p['reference']) ?></td>
                            <td><?= htmlspecialchars($p['member_name']) ?></td>
                            <td><?= htmlspecialchars($p['type_name']) ?></td>
                            <td class="text-end fw-bold"><?= $num($p['amount']) ?></td>
                            <td class="text-end"><?= $num($p['principal_part']) ?></td>
                            <td class="text-end text-success"><?= $num($p['interest_part']) ?></td>
                            <td class="text-end <?= (float) $p['penalty_part'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $num($p['penalty_part']) ?></td>
                            <td><?= htmlspecialchars((string) $p['account']) ?></td>
                            <td><?= htmlspecialchars((string) $p['receipt_number']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr>
                        <td colspan="4"><?= $t('Total', 'Jumla') ?></td>
                        <td class="text-end"><?= $num($r['totals']['amount']) ?></td><td class="text-end"><?= $num($r['totals']['principal']) ?></td>
                        <td class="text-end"><?= $num($r['totals']['interest']) ?></td><td class="text-end"><?= $num($r['totals']['penalty']) ?></td><td colspan="2"></td>
                    </tr></tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php vr_close(); includeFooter(); ob_end_flush(); ?>
