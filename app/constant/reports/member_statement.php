<?php
ob_start();
date_default_timezone_set('Africa/Nairobi');
require_once HEADER_FILE;

$member_id = intval($_GET['id'] ?? 0);

// Only leadership may view ANY member's statement (via ?id). Ordinary members —
// who also hold view-only report access — must always see only their own, and
// default to it when no id is given (e.g. the "My statement" link on their home).
// Using canView('vicoba_reports') alone was wrong: members have it too, which both
// broke the no-id case ("Member not found") and let a member read another
// member's statement via ?id.
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

// 1. Fetch Member Details
$member = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$member->execute([$member_id]);
$member = $member->fetch(PDO::FETCH_ASSOC);

// Shared contribution-standing rules — the one place every screen agrees on the
// month-counting and "no fixed rule => no target" behaviour.
require_once __DIR__ . '/../../../includes/contribution_standing.php';
// Shared NSSF-style skeleton — the same one the other three statements use.
require_once __DIR__ . '/../../../includes/statement_layout.php';

// 2. Fetch Group Settings (Monthly Contribution, Entrance Fee, Start Date)
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
// No fixed monthly / entrance => no target (save-what-you-can), matching the module.
// The old `?? 10000` / `?? 20000` fabricated a target and a false unpaid entrance for
// every member whenever the group had these unset (the case on the current data).
$monthly_amt = floatval($settings_raw['monthly_contribution'] ?? 0);
$entrance_amt = floatval($settings_raw['entrance_fee'] ?? 0);
$contribution_start_date = $settings_raw['contribution_start_date'] ?? ($settings_raw['group_founded_date'] ?? date('Y') . '-01-01');

// 3. Calculate Dependant Count
$spouse_active = ($member['marital_status'] == 'Married' && !($member['spouse_deceased'] ?? 0)) ? 1 : 0;
$children_json = json_decode($member['children_data'] ?? '[]', true);
$active_children = 0;
if (is_array($children_json)) {
    foreach ($children_json as $child) {
        if (!($child['is_deceased'] ?? false)) $active_children++;
    }
}
$dependant_count = $spouse_active + $active_children;

$isSw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// "as of" — the period the whole document is true for. A statement handed over in a
// meeting has to say WHEN it was true, and the group needs to be able to reprint an
// earlier month without the figures silently moving to today.
$as_of_raw = (string) ($_GET['as_of'] ?? '');
$as_of = preg_match('/^\d{4}-\d{2}$/', $as_of_raw)
    ? DateTime::createFromFormat('Y-m-d', $as_of_raw . '-01')
    : new DateTime('today');
if (!$as_of) {
    $as_of = new DateTime('today');
}
$as_of_value = $as_of->format('Y-m');

// 4–7. The member's monthly schedule — the opening-vs-new split, entrance taken from
// NEW money only, and the pot laid across the elapsed months — comes from the shared
// module, so the member statement and the profile page compute it identically.
$sched = cs_member_schedule($pdo, $member_id, $as_of);
$opening              = $sched['opening'];
$new_money            = $sched['new_money'];
$total_paid           = $sched['total_paid'];
$entrance_paid_amt    = $sched['entrance_paid'];
$entrance_status      = $sched['entrance_status'];
$total_months_covered = $sched['total_months_covered'];

// The calendar the page actually prints: whole years, twelve columns, plus the
// Target/Actual block beneath it.
$grid    = cs_calendar_grid($sched, $as_of);
$summary = cs_year_summary($grid);

// Expected of this member by now, and where that leaves them. Same helpers the
// ledger and the dashboard use, so the three screens cannot disagree.
$expected  = cs_expected_to_date($monthly_amt, $sched['anchor_ym'], $as_of);
$standing  = cs_standing($opening, $new_money, $expected);

// 7. Expenses (Condolences)
// Only APPROVED (disbursed) benefits count as "received" — pending/rejected
// claims must not inflate the Condolences Received total or the history table.
$stmt = $pdo->prepare("SELECT * FROM death_expenses WHERE member_id = ? AND status IN ('approved','paid') ORDER BY expense_date DESC");
$stmt->execute([$member_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_expenses = array_sum(array_column($expenses, 'amount'));

$group = stmt_group($pdo);

// The group asked for this wording verbatim, so it is not assembled from parts.
$doc_title = $isSw ? 'Taarifa ya Michango ya Mwanachama hadi' : 'Member Statement of Contributions as of';
$as_of_lbl = stmt_as_of_label($as_of, $isSw);

/** Registration fields print blank when unset — never a fabricated placeholder. */
function ms_val(?string $v): string
{
    $v = trim((string) $v);
    return $v === '' ? '<span class="text-muted">&mdash;</span>' : htmlspecialchars($v);
}
function ms_date(?string $v): string
{
    return empty($v) || strtotime($v) === false
        ? '<span class="text-muted">&mdash;</span>'
        : date('d M Y', strtotime($v));
}

$member_name = trim(implode(' ', array_filter([
    $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
])));
$residence = trim(implode(', ', array_filter([
    $member['ward'] ?? '', $member['district'] ?? '', $member['state'] ?? '',
])));

$money = fn(float $n): string => 'TSh ' . number_format($n, 0);
?>
<?php stmt_css(); ?>

<div class="no-print mb-3">
    <div class="row align-items-center g-3">
        <div class="col-md">
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-bank me-2"></i>
                <?= $isSw ? 'Taarifa ya Michango ya Mwanachama' : 'Member Statement of Contributions' ?></h3>
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
            <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-outline-primary rounded-pill px-4 shadow-sm fw-bold">
                <i class="bi bi-arrow-left me-2"></i> <?= $isSw ? 'Rudi Kwenye Orodha' : 'Back to List' ?>
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
                ($isSw ? 'Namba ya Mwanachama' : 'Member Number') => ms_val($member['registration_number'] ?: ('#' . $member['customer_id'])),
                ($isSw ? 'Jina Kamili'         : 'Member Name')   => ms_val($member_name),
                ($isSw ? 'Namba ya NIDA'       : 'NIDA Number')   => ms_val($member['nida_number'] ?? ''),
                ($isSw ? 'Simu'                : 'Phone')         => ms_val($member['phone'] ?: ($member['mobile'] ?? '')),
                ($isSw ? 'Tarehe ya Kuzaliwa'  : 'Date of Birth') => ms_date($member['dob'] ?? null),
                ($isSw ? 'Tarehe ya Kujiunga'  : 'Date of Join')  => ms_date($member['created_at'] ?? null),
                ($isSw ? 'Makazi'              : 'Residence')     => ms_val($residence),
                ($isSw ? 'Wategemezi'          : 'Dependants')    => (int) $dependant_count
                    . ' (' . $active_children . ' ' . ($isSw ? 'watoto' : 'children') . ', ' . $spouse_active . ' ' . ($isSw ? 'mwenzi' : 'spouse') . ')',
            ],
        ],
        [
            'heading' => $isSw ? 'Taarifa za Michango' : 'Contribution Details',
            'rows'    => [
                ($isSw ? 'Kiwango cha Mwezi'    : 'Monthly Target')     => $monthly_amt > 0 ? $money($monthly_amt) : ($isSw ? 'Hakijawekwa' : 'Not set'),
                ($isSw ? 'Kiingilio'            : 'Entrance Fee')       => $entrance_amt > 0
                    ? $money($entrance_paid_amt) . ' / ' . $money($entrance_amt)
                    : ($isSw ? 'Hakijawekwa' : 'Not set'),
                ($isSw ? 'Akiba ya M-Koba'      : 'Opening (M-Koba)')   => $money($opening),
                ($isSw ? 'Michango Mipya'       : 'New Contributions')  => $money($new_money),
                ($isSw ? 'Jumla Aliyotoa'       : 'Total Contributed')  => '<strong>' . $money($total_paid) . '</strong>',
                ($isSw ? 'Kinachotakiwa Hadi Sasa' : 'Expected to Date')=> $money($expected),
                ($isSw ? 'Ziada / Upungufu'     : 'Surplus / Deficit')  => $standing['surplus_deficit'] < 0
                    ? '<span class="vk-neg">(' . number_format(abs($standing['surplus_deficit']), 0) . ')</span>'
                    : '<span class="vk-pos">' . number_format($standing['surplus_deficit'], 0) . '</span>',
                ($isSw ? 'Miezi Iliyofunikwa'   : 'Months Covered')     => (int) $total_months_covered,
            ],
        ]
    );
    ?>

    <?php
    // NSSF puts "LAST PAID BENEFIT DETAILS" here. For this group the benefit IS the
    // condolence paid when the member lost a beneficiary, which is what the band shows.
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
        <?= $isSw ? 'MCHANGANUO WA MICHANGO' : 'CONTRIBUTIONS BREAKDOWN' ?>
    </div>
    <?php stmt_calendar($grid, $isSw); ?>
    <?php stmt_legend($isSw); ?>

    <div class="vk-stmt-title text-center" style="margin:16px 0 8px;">
        <?= $isSw ? 'MUHTASARI' : 'SUMMARY' ?>
    </div>
    <?php stmt_summary($summary, $isSw); ?>

</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
