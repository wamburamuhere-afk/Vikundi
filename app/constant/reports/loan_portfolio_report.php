<?php
// app/constant/reports/loan_portfolio_report.php — every loan and where it stands today, with overdue instalments.
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

$labels = vl_status_labels($is_sw);
$status = isset($labels[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '';
$r      = vr_loan_portfolio($pdo, $status);
$today  = date('Y-m-d');

$filters = '<div class="col-md-4"><label class="form-label fw-bold">' . $t('Status', 'Hali') . '</label><select name="status" class="form-select">'
         . '<option value="">' . $t('All loans', 'Mikopo yote') . '</option>';
foreach ($labels as $key => [$label]) {
    $filters .= '<option value="' . $key . '"' . ($key === $status ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
}
$filters .= '</select></div>';

vr_open($pdo, 'reports/loans', $t('Every loan and where it stands today', 'Kila mkopo na hali yake leo'),
        $t('As at', 'Hadi') . ' ' . $day($today) . ($status !== '' ? ' · ' . $labels[$status][0] : ''), $filters, $is_sw);

vr_kpis([
    [$t('Loans listed', 'Mikopo iliyoorodheshwa'), number_format(count($r['loans'])), 'primary'],
    [$t('Paid out (TZS)', 'Iliyotolewa (TZS)'), number_format($r['totals']['amount']), 'primary'],
    [$t('Repaid so far (TZS)', 'Iliyorejeshwa (TZS)'), number_format($r['totals']['paid']), 'success'],
    [$t('Still owed (TZS)', 'Deni lililobaki (TZS)'), number_format($r['totals']['balance']), 'danger'],
    [$t('Overdue instalments', 'Marejesho yaliyochelewa'), number_format(count($r['overdue'])), count($r['overdue']) ? 'danger' : 'muted'],
]);
?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold"><?= $t('Loans', 'Mikopo') ?> <span class="text-muted fw-normal">(<?= count($r['loans']) ?>)</span></div>
        <div class="card-body">
            <?php if (!$r['loans']): ?><p class="text-muted mb-0"><?= $t('No loans to show.', 'Hakuna mikopo ya kuonyesha.') ?></p><?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th><?= $t('Reference', 'Kumbukumbu') ?></th><th><?= $t('Member', 'Mwanachama') ?></th><th><?= $t('Type', 'Aina') ?></th>
                        <th><?= $t('Paid out', 'Umetolewa') ?></th><th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
                        <th class="text-end"><?= $t('To repay', 'Jumla ya marejesho') ?></th><th class="text-end"><?= $t('Repaid', 'Imerejeshwa') ?></th>
                        <th class="text-end"><?= $t('Still owed', 'Deni') ?></th><th class="text-end"><?= $t('Penalty due', 'Adhabu') ?></th><th><?= $t('Status', 'Hali') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($r['loans'] as $l): [$label, $color] = $labels[$l['status']]; ?>
                        <tr>
                            <td class="fw-bold"><a class="text-decoration-none" href="<?= getUrl('loans/details') ?>?id=<?= (int) $l['loan_id'] ?>"><?= htmlspecialchars($l['reference']) ?></a></td>
                            <td><?= htmlspecialchars($l['member_name']) ?></td>
                            <td><?= htmlspecialchars($l['type_name']) ?></td>
                            <td><?= $day($l['disbursed_at']) ?></td>
                            <td class="text-end"><?= $num($l['amount']) ?></td>
                            <td class="text-end"><?= $num($l['total_repayable']) ?></td>
                            <td class="text-end"><?= $num($l['paid']) ?></td>
                            <td class="text-end fw-bold"><?= $l['status'] === 'disbursed' ? $num($l['owed']) : '—' ?></td>
                            <td class="text-end <?= $l['penalty_due'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= $l['status'] === 'disbursed' ? $num($l['penalty_due']) : '—' ?></td>
                            <td><span class="badge bg-<?= $color ?>"><?= htmlspecialchars($label) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr>
                        <td colspan="4"><?= $t('Total, loans paid out', 'Jumla, mikopo iliyotolewa') ?></td>
                        <td class="text-end"><?= $num($r['totals']['amount']) ?></td><td></td>
                        <td class="text-end"><?= $num($r['totals']['paid']) ?></td>
                        <td class="text-end"><?= $num($r['totals']['balance']) ?></td>
                        <td class="text-end"><?= $num($r['totals']['penalty_due']) ?></td><td></td>
                    </tr></tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold"><?= $t('Overdue instalments', 'Marejesho yaliyochelewa') ?> <span class="text-muted fw-normal">(<?= count($r['overdue']) ?>)</span></div>
        <div class="card-body">
            <?php if (!$r['overdue']): ?><p class="text-success mb-0"><i class="bi bi-check-circle me-1"></i><?= $t('No instalment is overdue.', 'Hakuna rejesho lililochelewa.') ?></p><?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th><?= $t('Loan', 'Mkopo') ?></th><th><?= $t('Member', 'Mwanachama') ?></th><th><?= $t('Instalment', 'Rejesho') ?></th><th><?= $t('Due', 'Tarehe') ?></th>
                        <th class="text-end"><?= $t('Days late', 'Siku za kuchelewa') ?></th><th class="text-end"><?= $t('Unpaid', 'Hakijalipwa') ?></th><th class="text-end"><?= $t('Penalty', 'Adhabu') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($r['overdue'] as $o): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($o['reference']) ?></td>
                            <td><?= htmlspecialchars($o['member_name']) ?></td>
                            <td>#<?= (int) $o['seq'] ?></td>
                            <td><?= $day($o['due_date']) ?></td>
                            <td class="text-end text-danger fw-bold"><?= (int) $o['days_late'] ?></td>
                            <td class="text-end"><?= $num($o['unpaid']) ?></td>
                            <td class="text-end text-danger"><?= $num($o['penalty']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php vr_close(); includeFooter(); ob_end_flush(); ?>
