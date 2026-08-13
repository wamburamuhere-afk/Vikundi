<?php
ob_start();
date_default_timezone_set('Africa/Nairobi');
require_once HEADER_FILE;

$member_id = intval($_GET['id'] ?? 0);

// Same gate as the contributions statement, and for the same reason: ordinary
// members hold view-only report access, so leadership alone may open ANY member
// via ?id — everyone else is forced back to their own record. Relaxing this here
// would reopen the hole the contributions statement already had closed.
$own_stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$own_stmt->execute([$_SESSION['user_id']]);
$own_cid = (int) ($own_stmt->fetchColumn() ?: 0);

$is_leader = isAdmin() || canCreate('manage_contributions');
if (!$is_leader || !$member_id) {
    $member_id = $own_cid;
}

if (!$member_id) {
    echo '<div class="alert alert-danger m-4">' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama hajapatikana.' : 'Member not found.') . '</div>';
    $content = ob_get_clean(); echo $content; require_once FOOTER_FILE; exit();
}

require_once __DIR__ . '/../../../includes/contribution_standing.php';
require_once __DIR__ . '/../../../includes/statement_layout.php';

$member = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$member->execute([$member_id]);
$member = $member->fetch(PDO::FETCH_ASSOC);

$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly_amt  = floatval($settings_raw['monthly_contribution'] ?? 0);
$entrance_amt = floatval($settings_raw['entrance_fee'] ?? 0);

$isSw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

$as_of_raw = (string) ($_GET['as_of'] ?? '');
$as_of = preg_match('/^\d{4}-\d{2}$/', $as_of_raw)
    ? DateTime::createFromFormat('Y-m-d', $as_of_raw . '-01')
    : new DateTime('today');
if (!$as_of) {
    $as_of = new DateTime('today');
}
$as_of_value = $as_of->format('Y-m');

// The schedule is read only for the figures that must agree with the contributions
// statement — anchor, entrance, totals. The grid below is built from dates instead.
$sched     = cs_member_schedule($pdo, $member_id, $as_of);
$total_paid = $sched['total_paid'];

// Dated receipts, using the identical filter the schedule sums over.
$receipts = cs_member_transactions($pdo, $member_id);

// customers.initial_savings carries no date, so it cannot sit in a month. It is
// shown as a brought-forward opening line — the same thing a bank statement does
// with a balance carried in — so that opening + dated receipts equals the total on
// the contributions statement rather than quietly falling short of it.
$opening_bf = (float) ($member['initial_savings'] ?? 0);

$grid    = cs_transaction_grid(
    array_map(fn(array $r): array => ['date' => $r['date'], 'amount' => (float) $r['amount']], $receipts),
    $monthly_amt,
    $sched['anchor_ym'],
    $as_of
);
$summary = cs_year_summary($grid);
$received_total = $summary['total']['actual'];

// Fines are real events and the group counts them as transactions, but they are not
// savings — they are listed and totalled separately so they can never inflate the
// contribution figures the two statements must agree on.
$fines_stmt = $pdo->prepare("SELECT fine_id, amount, reason, created_at FROM fines WHERE customer_id = ? AND status = 'paid' ORDER BY created_at ASC");
$fines_stmt->execute([$member_id]);
$fines = $fines_stmt->fetchAll(PDO::FETCH_ASSOC);
$fines_total = array_sum(array_map(fn($f) => (float) $f['amount'], $fines));

$stmt = $pdo->prepare("SELECT * FROM death_expenses WHERE member_id = ? AND status IN ('approved','paid') ORDER BY expense_date DESC");
$stmt->execute([$member_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$group     = stmt_group($pdo);
$doc_title = $isSw ? 'Taarifa ya Miamala ya Mwanachama hadi' : 'Member Statement of Transactions as of';
$as_of_lbl = stmt_as_of_label($as_of, $isSw);

$member_name = trim(implode(' ', array_filter([
    $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
])));
$residence = trim(implode(', ', array_filter([
    $member['ward'] ?? '', $member['district'] ?? '', $member['state'] ?? '',
])));

$money = fn(float $n): string => 'TSh ' . number_format($n, 0);
$blank = '<span class="text-muted">&mdash;</span>';
$val   = fn(?string $v): string => trim((string) $v) === '' ? '<span class="text-muted">&mdash;</span>' : htmlspecialchars($v);
$dt    = fn(?string $v): string => empty($v) || strtotime($v) === false
    ? '<span class="text-muted">&mdash;</span>' : date('d M Y', strtotime($v));

// One chronological ledger of everything that touched this member's account.
$ledger = [];
foreach ($receipts as $r) {
    $ledger[] = [
        'date'   => $r['date'],
        'type'   => $isSw ? 'Mchango' : 'Contribution',
        'detail' => trim((string) ($r['description'] ?: ucfirst((string) $r['type']))),
        'ref'    => $r['receipt_number'] ?: $r['mkoba_trans_id'],
        'in'     => (float) $r['amount'],
        'out'    => 0.0,
    ];
}
foreach ($fines as $f) {
    $ledger[] = [
        'date'   => $f['created_at'],
        'type'   => $isSw ? 'Faini' : 'Fine',
        'detail' => (string) $f['reason'],
        'ref'    => '#' . $f['fine_id'],
        'in'     => (float) $f['amount'],
        'out'    => 0.0,
    ];
}
foreach ($expenses as $ex) {
    $ledger[] = [
        'date'   => $ex['expense_date'],
        'type'   => $isSw ? 'Rambirambi' : 'Condolence',
        'detail' => ($isSw ? 'Kwa: ' : 'For: ') . $ex['deceased_name'],
        'ref'    => 'DE#' . $ex['id'],
        'in'     => 0.0,
        'out'    => (float) $ex['amount'],
    ];
}
usort($ledger, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
?>
<?php stmt_css(); ?>

<div class="no-print mb-3">
    <div class="row align-items-center g-3">
        <div class="col-md">
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-journal-text me-2"></i>
                <?= $isSw ? 'Taarifa ya Miamala ya Mwanachama' : 'Member Statement of Transactions' ?></h3>
            <p class="text-muted small mb-0"><?= htmlspecialchars($member_name) ?> | #<?= (int) $member['customer_id'] ?></p>
        </div>
        <div class="col-md-auto d-flex gap-2 align-items-center">
            <form method="get" class="d-flex gap-2 align-items-center" action="">
                <?php if ($is_leader && !empty($_GET['id'])): ?>
                    <input type="hidden" name="id" value="<?= (int) $member_id ?>">
                <?php endif; ?>
                <label class="small text-muted mb-0"><?= $isSw ? 'Hadi' : 'As of' ?></label>
                <input type="month" name="as_of" value="<?= htmlspecialchars($as_of_value) ?>"
                       max="<?= date('Y-m') ?>" class="form-control form-control-sm" style="width:150px;">
                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" type="submit">
                    <?= $isSw ? 'Onesha' : 'Show' ?>
                </button>
            </form>
            <a href="<?= getUrl('member_statement') ?><?= $is_leader && !empty($_GET['id']) ? '?id=' . (int) $member_id : '' ?>"
               class="btn btn-outline-primary rounded-pill px-4 shadow-sm fw-bold">
                <i class="bi bi-calendar-check me-2"></i> <?= $isSw ? 'Michango' : 'Contributions' ?>
            </a>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> <?= $isSw ? 'Chapisha' : 'Print' ?>
            </button>
        </div>
    </div>
</div>

<div class="vk-stmt-sheet shadow-sm">

    <?php stmt_head($group, $doc_title, $as_of_lbl); ?>

    <?php
    stmt_panels(
        [
            'heading' => $isSw ? 'Taarifa za Mwanachama' : 'Member Details',
            'rows'    => [
                ($isSw ? 'Namba ya Mwanachama' : 'Member Number') => $val($member['registration_number'] ?: ('#' . $member['customer_id'])),
                ($isSw ? 'Jina Kamili'         : 'Member Name')   => $val($member_name),
                ($isSw ? 'Namba ya NIDA'       : 'NIDA Number')   => $val($member['nida_number'] ?? ''),
                ($isSw ? 'Simu'                : 'Phone')         => $val($member['phone'] ?: ($member['mobile'] ?? '')),
                ($isSw ? 'Tarehe ya Kuzaliwa'  : 'Date of Birth') => $dt($member['dob'] ?? null),
                ($isSw ? 'Tarehe ya Kujiunga'  : 'Date of Join')  => $dt($member['created_at'] ?? null),
                ($isSw ? 'Makazi'              : 'Residence')     => $val($residence),
            ],
        ],
        [
            'heading' => $isSw ? 'Muhtasari wa Miamala' : 'Transaction Summary',
            'rows'    => [
                ($isSw ? 'Salio Lililoletwa'  : 'Opening Brought Forward') => $money($opening_bf),
                ($isSw ? 'Michango Iliyopokelewa' : 'Contributions Received') => $money($received_total),
                ($isSw ? 'Jumla ya Michango'  : 'Total Contributions')     => '<strong>' . $money($opening_bf + $received_total) . '</strong>',
                ($isSw ? 'Faini Zilizolipwa'  : 'Fines Paid')              => $money($fines_total),
                ($isSw ? 'Rambirambi Zilizolipwa' : 'Condolences Paid Out')=> $money(array_sum(array_map(fn($e) => (float) $e['amount'], $expenses))),
                ($isSw ? 'Idadi ya Miamala'   : 'Number of Transactions')  => count($ledger),
                ($isSw ? 'Muamala wa Mwisho'  : 'Last Transaction')        => $ledger ? date('d M Y', strtotime(end($ledger)['date'])) : $blank,
            ],
        ]
    );
    ?>

    <?php
    stmt_bar_table(
        $isSw ? 'Rambirambi' : 'Condolences',
        [
            'date'     => $isSw ? 'Tarehe' : 'Date',
            'deceased' => $isSw ? 'Marehemu' : 'Name of Deceased',
            'relation' => $isSw ? 'Uhusiano' : 'Relationship',
            'amount'   => $isSw ? 'Kiasi Kilicholipwa' : 'Amount Paid',
        ],
        array_map(fn(array $ex): array => [
            'date'     => date('d M Y', strtotime($ex['expense_date'])),
            'deceased' => htmlspecialchars($ex['deceased_name']),
            'relation' => htmlspecialchars(ucfirst((string) ($ex['deceased_relationship'] ?: $ex['deceased_type']))),
            'amount'   => '<strong>' . number_format((float) $ex['amount'], 0) . '</strong>',
        ], $expenses),
        $isSw ? 'Hakuna rambirambi iliyolipwa kwa mwanachama huyu.' : 'No condolences have been paid to this member.'
    );
    ?>

    <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
        <?= $isSw ? 'MCHANGANUO WA MIAMALA' : 'TRANSACTIONS BREAKDOWN' ?>
    </div>
    <?php stmt_calendar($grid, $isSw); ?>
    <?php stmt_legend($isSw, 'transactions'); ?>
    <p class="text-muted" style="font-size:8.5pt;margin:-6px 0 14px;">
        <?= $isSw
            ? 'Fedha zimeonyeshwa kwenye mwezi zilipopokelewa. Kwenye Taarifa ya Michango fedha hizo hizo zimegawanywa kwenye miezi zinazolipia.'
            : 'Money is shown in the month it was received. On the Statement of Contributions the same money is spread across the months it covers.' ?>
    </p>

    <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
        <?= $isSw ? 'MUHTASARI' : 'SUMMARY' ?>
    </div>
    <?php stmt_summary($summary, $isSw); ?>

    <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
        <?= $isSw ? 'ORODHA YA MIAMALA' : 'TRANSACTION HISTORY' ?>
    </div>
    <table class="vk-stmt-grid">
        <thead>
            <tr>
                <th class="vk-stmt-bar"><?= $isSw ? 'TAREHE' : 'DATE' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'AINA' : 'TYPE' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'MAELEZO' : 'DETAILS' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'KUMBUKUMBU' : 'REFERENCE' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'IMEINGIA' : 'IN' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'IMETOKA' : 'OUT' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($opening_bf > 0): ?>
            <tr>
                <td><?= $dt($member['created_at'] ?? null) ?></td>
                <td><?= $isSw ? 'Salio' : 'Opening' ?></td>
                <td class="text-start"><?= $isSw ? 'Salio lililoletwa' : 'Balance brought forward' ?></td>
                <td><?= $blank ?></td>
                <td class="vk-stmt-num"><?= number_format($opening_bf, 0) ?></td>
                <td class="vk-stmt-num"><?= $blank ?></td>
            </tr>
            <?php endif; ?>
            <?php if (empty($ledger) && $opening_bf <= 0): ?>
                <tr><td colspan="6" class="vk-stmt-empty"><?= $isSw ? 'Hakuna muamala uliorekodiwa.' : 'No transactions recorded.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($ledger as $row): ?>
            <tr>
                <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                <td><?= htmlspecialchars($row['type']) ?></td>
                <td class="text-start"><?= $row['detail'] !== '' ? htmlspecialchars($row['detail']) : $blank ?></td>
                <td><?= $row['ref'] ? htmlspecialchars($row['ref']) : $blank ?></td>
                <td class="vk-stmt-num"><?= $row['in'] > 0 ? number_format($row['in'], 0) : '' ?></td>
                <td class="vk-stmt-num vk-neg"><?= $row['out'] > 0 ? number_format($row['out'], 0) : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
