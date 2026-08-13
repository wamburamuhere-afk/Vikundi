<?php
/**
 * includes/group_statement.php — the two group statements.
 *
 *   Group Statement of Contributions as of ...
 *   Group Statement of Transactions as of ...
 *
 * Both documents are the same page with a different way of filling the calendar,
 * so they share one renderer rather than being copied. The calling page sets
 * $vk_statement_type to 'contributions' or 'transactions' and includes this file.
 *
 * Two views, switchable, because the group asked for both:
 *   combined   — the group as a single member: one calendar, one summary
 *   per-member — one row per member with their own totals
 * The per-member rows must sum to the combined totals, and a test pins that.
 */

if (!isset($vk_statement_type) || !in_array($vk_statement_type, ['contributions', 'transactions'], true)) {
    $vk_statement_type = 'contributions';
}

require_once ROOT_DIR . '/includes/contribution_standing.php';
require_once ROOT_DIR . '/includes/statement_layout.php';

// Same gate the Group Financial Ledger and Group Reports already use. Group-wide
// figures are visible to members in this product by existing policy; this page
// does not widen that, and must not narrow it either.
if (!canView('vicoba_reports')) {
    echo '<div class="alert alert-danger m-4">'
       . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hauna ruhusa ya kuona ripoti hii.' : 'You do not have permission to view this report.')
       . '</div>';
    $content = ob_get_clean(); echo $content; require_once FOOTER_FILE; exit();
}

$isSw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$isTx = $vk_statement_type === 'transactions';

$as_of_raw = (string) ($_GET['as_of'] ?? '');
$as_of = preg_match('/^\d{4}-\d{2}$/', $as_of_raw)
    ? DateTime::createFromFormat('Y-m-d', $as_of_raw . '-01')
    : new DateTime('today');
if (!$as_of) {
    $as_of = new DateTime('today');
}
$as_of_value = $as_of->format('Y-m');
$view = ($_GET['view'] ?? 'combined') === 'members' ? 'members' : 'combined';

$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly_amt  = floatval($settings_raw['monthly_contribution'] ?? 0);

// One query for every member's schedule, one more for receipts if this is the
// transactions document. Never a query per member — see cs_group_schedules().
$schedules = cs_group_schedules($pdo, $as_of);
$receipts  = $isTx ? cs_group_receipts($pdo) : [];

$per_member = [];
$grids      = [];
foreach ($schedules as $cid => $row) {
    $grid = $isTx
        ? cs_transaction_grid($receipts[$cid] ?? [], $monthly_amt, $row['schedule']['anchor_ym'], $as_of)
        : cs_calendar_grid($row['schedule'], $as_of);

    $summary = cs_year_summary($grid);
    $grids[] = $grid;

    $per_member[] = [
        'id'       => $cid,
        'name'     => $row['name'],
        'joined'   => $row['joined'],
        'target'   => $summary['total']['target'],
        'actual'   => $summary['total']['actual'],
        'variance' => $summary['total']['variance'],
        'paid'     => $summary['total']['paid'],
    ];
}

$group_grid    = cs_merge_grids($grids);
$group_summary = cs_year_summary($group_grid);

$member_count  = count($per_member);
$behind_count  = count(array_filter($per_member, fn($m) => $m['variance'] < 0));

$group     = stmt_group($pdo);
$doc_title = $isTx
    ? ($isSw ? 'Taarifa ya Miamala ya Kikundi hadi' : 'Group Statement of Transactions as of')
    : ($isSw ? 'Taarifa ya Michango ya Kikundi hadi' : 'Group Statement of Contributions as of');
$as_of_lbl = stmt_as_of_label($as_of, $isSw);
$money     = fn(float $n): string => 'TSh ' . number_format($n, 0);
$self      = $isTx ? getUrl('group_statement_transactions') : getUrl('group_statement_contributions');
?>
<?php stmt_css(); ?>
<style>
.vk-stmt-members td { font-size:9pt; }
.vk-stmt-members td.vk-name { text-align:left; padding-left:10px; font-weight:600; }
.vk-stmt-members tbody tr:nth-child(even) { background:#fafbfc; }
</style>

<div class="no-print mb-3">
    <div class="row align-items-center g-3">
        <div class="col-md">
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-people me-2"></i>
                <?= $isTx
                    ? ($isSw ? 'Taarifa ya Miamala ya Kikundi' : 'Group Statement of Transactions')
                    : ($isSw ? 'Taarifa ya Michango ya Kikundi' : 'Group Statement of Contributions') ?></h3>
            <p class="text-muted small mb-0">
                <?= $member_count ?> <?= $isSw ? 'wanachama' : 'members' ?>
                <?php if ($behind_count > 0): ?>
                    &middot; <span class="text-danger fw-bold"><?= $behind_count ?> <?= $isSw ? 'wamechelewa' : 'behind' ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="col-md-auto d-flex gap-2 align-items-center flex-wrap">
            <div class="btn-group btn-group-sm" role="group">
                <a href="<?= $self ?>?view=combined&as_of=<?= htmlspecialchars($as_of_value) ?>"
                   class="btn <?= $view === 'combined' ? 'btn-primary' : 'btn-outline-primary' ?> rounded-start-pill px-3 fw-bold">
                    <?= $isSw ? 'Kwa Pamoja' : 'Combined' ?>
                </a>
                <a href="<?= $self ?>?view=members&as_of=<?= htmlspecialchars($as_of_value) ?>"
                   class="btn <?= $view === 'members' ? 'btn-primary' : 'btn-outline-primary' ?> rounded-end-pill px-3 fw-bold">
                    <?= $isSw ? 'Kila Mwanachama' : 'Per Member' ?>
                </a>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center" action="">
                <input type="hidden" name="view" value="<?= $view ?>">
                <label class="small text-muted mb-0"><?= $isSw ? 'Hadi' : 'As of' ?></label>
                <input type="month" name="as_of" value="<?= htmlspecialchars($as_of_value) ?>"
                       max="<?= date('Y-m') ?>" class="form-control form-control-sm" style="width:150px;">
                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" type="submit"><?= $isSw ? 'Onesha' : 'Show' ?></button>
            </form>
            <a href="<?= $isTx ? getUrl('group_statement_contributions') : getUrl('group_statement_transactions') ?>"
               class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
                <?= $isTx ? ($isSw ? 'Michango' : 'Contributions') : ($isSw ? 'Miamala' : 'Transactions') ?>
            </a>
            <button class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> <?= $isSw ? 'Chapisha' : 'Print' ?>
            </button>
        </div>
    </div>
</div>

<div class="vk-stmt-sheet shadow-sm">

    <?php stmt_head($group, $doc_title, $as_of_lbl); ?>

    <?php
    $t = $group_summary['total'];
    stmt_panels(
        [
            'heading' => $isSw ? 'Taarifa za Kikundi' : 'Group Details',
            'rows'    => [
                ($isSw ? 'Jina la Kikundi'    : 'Group Name')      => htmlspecialchars($group['name']),
                ($isSw ? 'Namba ya Usajili'   : 'Registration')    => trim((string) $group['registration']) !== ''
                    ? htmlspecialchars($group['registration']) : '<span class="text-muted">&mdash;</span>',
                ($isSw ? 'Wanachama'          : 'Members')         => $member_count,
                ($isSw ? 'Kiwango cha Mwezi'  : 'Monthly Target')  => $monthly_amt > 0 ? $money($monthly_amt) : ($isSw ? 'Hakijawekwa' : 'Not set'),
                ($isSw ? 'Hadi'               : 'Statement Period')=> htmlspecialchars($as_of_lbl),
            ],
        ],
        [
            'heading' => $isSw ? 'Muhtasari' : 'Summary',
            'rows'    => [
                ($isSw ? 'Kinachotakiwa'  : 'Total Target')   => $money($t['target']),
                ($isSw ? 'Kilichotolewa'  : 'Total Actual')   => '<strong>' . $money($t['actual']) . '</strong>',
                ($isSw ? 'Tofauti'        : 'Variance')       => $t['variance'] < 0
                    ? '<span class="vk-neg">(' . number_format(abs($t['variance']), 0) . ')</span>'
                    : '<span class="vk-pos">' . number_format($t['variance'], 0) . '</span>',
                ($isSw ? 'Waliochelewa'   : 'Members Behind') => $behind_count . ' / ' . $member_count,
                ($isSw ? 'Jumla Kuu'      : 'Grand Total')    => '<strong>' . $money($t['paid']) . '</strong>',
            ],
        ]
    );
    ?>

    <?php if ($view === 'combined'): ?>

        <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
            <?= $isTx
                ? ($isSw ? 'MCHANGANUO WA MIAMALA' : 'TRANSACTIONS BREAKDOWN')
                : ($isSw ? 'MCHANGANUO WA MICHANGO' : 'CONTRIBUTIONS BREAKDOWN') ?>
        </div>
        <?php stmt_calendar($group_grid, $isSw); ?>
        <?php stmt_legend($isSw, $isTx ? 'transactions' : 'contributions'); ?>
        <p class="text-muted" style="font-size:8.5pt;margin:-6px 0 14px;">
            <?= $isTx
                ? ($isSw ? 'Fedha za wanachama wote zimeonyeshwa kwenye mwezi zilipopokelewa.'
                         : 'All members\' money is shown in the month it was received.')
                : ($isSw ? 'Fedha za wanachama wote zimegawanywa kwenye miezi zinazolipia.'
                         : 'All members\' money is spread across the months it covers.') ?>
        </p>

        <div class="vk-stmt-title text-center" style="margin:16px 0 8px;"><?= $isSw ? 'MUHTASARI' : 'SUMMARY' ?></div>
        <?php stmt_summary($group_summary, $isSw); ?>

    <?php else: ?>

        <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
            <?= $isSw ? 'KILA MWANACHAMA' : 'PER MEMBER' ?>
        </div>
        <table class="vk-stmt-grid vk-stmt-members">
            <thead>
                <tr>
                    <th class="vk-stmt-bar">#</th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'MWANACHAMA' : 'MEMBER' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'ALIJIUNGA' : 'JOINED' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'KINACHOTAKIWA' : 'TARGET' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'KILICHOTOLEWA' : 'ACTUAL' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'TOFAUTI' : 'VARIANCE' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'HALI' : 'STATUS' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; foreach ($per_member as $m): $n++; ?>
                <tr>
                    <td><?= $n ?></td>
                    <td class="vk-name"><?= htmlspecialchars($m['name']) ?></td>
                    <td><?= $m['joined'] && strtotime($m['joined']) ? date('M Y', strtotime($m['joined'])) : '&mdash;' ?></td>
                    <td class="vk-stmt-num"><?= number_format($m['target'], 0) ?></td>
                    <td class="vk-stmt-num"><?= number_format($m['actual'], 0) ?></td>
                    <td class="vk-stmt-num <?= $m['variance'] < 0 ? 'vk-neg' : ($m['variance'] > 0 ? 'vk-pos' : '') ?>">
                        <?= $m['variance'] < 0 ? '(' . number_format(abs($m['variance']), 0) . ')' : number_format($m['variance'], 0) ?>
                    </td>
                    <td class="<?= $m['variance'] < 0 ? 'vk-c-unpaid' : ($m['target'] <= 0 ? 'vk-c-notarget' : 'vk-c-paid') ?>">
                        <?php if ($m['target'] <= 0): ?>
                            <?= $isSw ? 'Hakuna lengo' : 'No target' ?>
                        <?php elseif ($m['variance'] < 0): ?>
                            <?= $isSw ? 'Amechelewa' : 'Behind' ?>
                        <?php else: ?>
                            <?= $isSw ? 'Yuko sawa' : 'Up to date' ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$per_member): ?>
                    <tr><td colspan="7" class="vk-stmt-empty"><?= $isSw ? 'Hakuna wanachama.' : 'No members.' ?></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="vk-stmt-total">
                    <td colspan="3"><?= $isSw ? 'JUMLA' : 'TOTAL' ?></td>
                    <td class="vk-stmt-num"><?= number_format(array_sum(array_column($per_member, 'target')), 0) ?></td>
                    <td class="vk-stmt-num"><?= number_format(array_sum(array_column($per_member, 'actual')), 0) ?></td>
                    <?php $vsum = array_sum(array_column($per_member, 'variance')); ?>
                    <td class="vk-stmt-num"><?= $vsum < 0 ? '(' . number_format(abs($vsum), 0) . ')' : number_format($vsum, 0) ?></td>
                    <td><?= $behind_count ?> <?= $isSw ? 'wamechelewa' : 'behind' ?></td>
                </tr>
            </tfoot>
        </table>

    <?php endif; ?>

</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
