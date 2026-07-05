<?php
// app/bms/customer/manage_contributions.php
ob_start();
require_once 'header.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}
// Permission check
requireViewPermission('manage_contributions');
$is_leader   = isAdmin() || canEdit('manage_contributions');
$is_admin    = isAdmin() || canDelete('manage_contributions');
$can_review  = canReview('manage_contributions');
$can_approve = canApprove('manage_contributions');

// 1. Fetch Basic Group Settings
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$start_date = $settings_raw['contribution_start_date'] ?? $settings_raw['group_founded_date'] ?? date('Y-m-01');
$cycle = $settings_raw['cycle_type'] ?? 'monthly';
$currency = $settings_raw['currency'] ?? 'TZS';
// Language flag used in the print/footer JS below. Defining it here prevents an
// "Undefined variable" warning from being printed straight into the inline
// <script> (which broke the whole script with "Unexpected token '<'" and left
// the Contribution Analysis Grid empty).
$isSw = (($_SESSION['preferred_language'] ?? 'en') === 'sw');

// 2. Fetch Pending Contributions (Leaders see all, Members see only theirs)
// Awaiting-action queue: 'pending' items need review, 'reviewed' items need
// approval. Including 'reviewed' is what lets the Approve button appear (it
// renders only for reviewed rows) — otherwise reviewed items get stranded.
$pending_query = "
    SELECT con.*, c.customer_name, c.first_name, c.last_name, c.phone
    FROM contributions con
    JOIN customers c ON con.member_id = c.customer_id
    WHERE con.status IN ('pending', 'reviewed')
";
$pending_order = " ORDER BY FIELD(con.status, 'pending', 'reviewed'), con.contribution_date DESC";
if (!$is_leader) {
    $pending_query .= " AND c.user_id = ? ";
    $stmt = $pdo->prepare($pending_query . $pending_order);
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->query($pending_query . $pending_order);
}
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Grid Navigation Logic (Block of 4 months)
require_once __DIR__ . '/../../../includes/contribution_grid_helpers.php';
$block = intval($_GET['block'] ?? 0);
$periods_per_block = 4;
$start_offset = $block * $periods_per_block;

$monthly_val  = floatval($settings_raw['monthly_contribution'] ?? 10000);
$entrance_val = floatval($settings_raw['entrance_fee'] ?? 20000);

$columns = [];
for ($i = 0; $i < $periods_per_block; $i++) {
    $idx = $start_offset + $i;
    $ts = strtotime($start_date . " +$idx months");
    $columns[] = [
        'ym'          => date('Y-m', $ts),          // for matching contribution sums
        'month_label' => date('M', $ts),            // "Mar" (year shown once, in the caption)
        'year'        => date('Y', $ts),
        'start'       => date('Y-m-01 00:00:00', $ts),
        'end'         => date('Y-m-t 23:59:59', $ts),
    ];
}
$block_start = $columns[0]['start'];
$block_end   = $columns[count($columns) - 1]['end'];
$block_label = vk_grid_block_label($columns); // e.g. "Mar – Jun 2026" (cross-year aware)

// 4. Fetch All Active Members (Leaders see all, Members see themselves)
if (!$is_leader) {
    $stmt = $pdo->prepare("
        SELECT c.customer_id, c.customer_name, c.first_name, c.last_name, c.phone 
        FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.user_id = ? AND u.status = 'active' AND c.is_deceased = 0 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->query("
        SELECT c.customer_id, c.customer_name, c.first_name, c.last_name, c.phone 
        FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE u.status = 'active' AND c.is_deceased = 0 
        ORDER BY c.first_name ASC
    ");
}
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Build Ledger Data from REAL per-month contributions (approved), for the
//    visible 4-month block — one grouped query, not a synthetic spread.
$memberIds = array_map(fn($m) => (int) $m['customer_id'], $members);
$blockSums = []; // [member_id][ym] => amount actually paid that month
$grandTotals = [];
if ($memberIds) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $st = $pdo->prepare("
        SELECT member_id, DATE_FORMAT(contribution_date, '%Y-%m') ym, SUM(amount) amt
        FROM contributions
        WHERE status IN ('confirmed','approved','')
          AND contribution_date BETWEEN ? AND ?
          AND member_id IN ($ph)
        GROUP BY member_id, ym
    ");
    $st->execute(array_merge([$block_start, $block_end], $memberIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $blockSums[(int) $r['member_id']][$r['ym']] = (float) $r['amt'];
    }
    $gt = $pdo->prepare("SELECT member_id, SUM(amount) amt FROM contributions
                         WHERE status IN ('confirmed','approved','') AND member_id IN ($ph) GROUP BY member_id");
    $gt->execute($memberIds);
    foreach ($gt->fetchAll(PDO::FETCH_ASSOC) as $r) { $grandTotals[(int) $r['member_id']] = (float) $r['amt']; }
}

$ledger = [];
$sum_collected = 0.0; $members_full = 0; $members_behind = 0;
$col_totals = array_fill(0, $periods_per_block, 0.0);
foreach ($members as $m) {
    $mid = (int) $m['customer_id'];
    $cells = []; $rowBlock = 0.0; $rowFull = true; $rowAny = false;
    foreach ($columns as $ci => $col) {
        $paid = $blockSums[$mid][$col['ym']] ?? 0.0;
        $status = vk_contribution_cell_status($paid, $monthly_val);
        if ($status !== 'full') $rowFull = false;
        if ($paid > 0) $rowAny = true;
        $rowBlock += $paid;
        $col_totals[$ci] += $paid;
        $cells[] = ['paid' => $paid, 'status' => $status];
    }
    $ledger[] = [
        'member' => $m, 'cells' => $cells,
        'block_total' => $rowBlock, 'grand_total' => $grandTotals[$mid] ?? 0.0,
    ];
    $sum_collected += $rowBlock;
    if ($rowFull) { $members_full++; } elseif (!$rowAny) { $members_behind++; }
}
$expected_block  = count($members) * $periods_per_block * $monthly_val;
$collection_rate = vk_collection_rate($sum_collected, $expected_block);

// Members for the statement filter dropdown.
$statement_members = $pdo->query("
    SELECT customer_id, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS name
    FROM customers WHERE (status IS NULL OR status <> 'deleted') ORDER BY first_name, last_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── (The itemised transactions table was removed — the grid is the single
//    table; a date-range statement is available on demand.) ──────────────────
?>

<!-- Header Section -->
<div class="row align-items-center mb-4 g-3">
    <!-- Success/Error Feedback from Import -->
    <?php if (isset($_SESSION['import_response'])): 
        $res = $_SESSION['import_response'];
        unset($_SESSION['import_response']);
    ?>
    <div class="col-12">
        <div class="alert alert-<?= $res['success'] ? 'success' : 'danger' ?> alert-dismissible fade show shadow-sm border-0 rounded-4 p-3 mb-0" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-<?= $res['success'] ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-4 me-3"></i>
                <div>
                    <h6 class="alert-heading fw-bold mb-1"><?= $res['success'] ? 'Import Successful' : 'Import Failed' ?></h6>
                    <p class="mb-0 small"><?= $res['message'] ?></p>
                    <?php if (!empty($res['errors'])): ?>
                    <ul class="mb-0 mt-2 small ps-3">
                        <?php foreach($res['errors'] as $err): ?><li><?= $err ?></li><?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md">
        <h2 class="fw-bold text-primary mb-1"><i class="bi bi-bank2 me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchanganuo wa Michango (Ledger)' : 'Contribution Ledger' ?></h2>
        <p class="text-muted small mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tazama na uhakiki michango ya kila mwanachama.' : 'Analyze and approve member contribution records.' ?></p>
    </div>
    <div class="col-md-auto d-flex gap-2 justify-content-end align-items-center">
        <!-- Compact Navigation Group -->
        <div class="btn-group shadow-sm border rounded-2 overflow-hidden bg-white">
            <a href="?block=<?= max(0, $block - 1) ?>" class="btn btn-light border-0 px-2 px-md-3" title="Previous"><i class="bi bi-chevron-left"></i></a>
            <span class="btn btn-white border-0 disabled fw-bold py-2 shadow-none"><?= htmlspecialchars($block_label) ?></span>
            <a href="?block=<?= $block + 1 ?>" class="btn btn-light border-0 px-2 px-md-3" title="Next"><i class="bi bi-chevron-right"></i></a>
        </div>

        <?php if ($is_leader): ?>
        <button type="button" class="btn btn-outline-primary rounded-2 shadow-sm" style="min-height:42px;" data-bs-toggle="modal" data-bs-target="#statementModal" title="<?= $isSw ? 'Taarifa ya Miamala' : 'Transactions Statement' ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span class="d-none d-md-inline ms-1"><?= $isSw ? 'Taarifa' : 'Statement' ?></span>
        </button>
        <?php endif; ?>

        <?php if ($is_leader): ?>
        <!-- Recording (manual + bulk + M-Koba) now lives on the Transactions page. -->
        <a href="<?= getUrl('transactions') ?>" class="btn btn-primary rounded-2 p-2 p-md-2 px-md-4 shadow-sm" style="min-height: 42px; width: auto;">
            <i class="bi bi-plus-lg"></i>
            <span class="d-none d-md-inline ms-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rekodi Muamala' : 'Record Transaction' ?></span>
        </a>
        <?php else: ?>
        <a href="<?= getUrl('submit_contribution') ?>" class="btn btn-primary rounded-2 p-2 p-md-2 px-md-4 shadow-sm" style="min-height: 42px; width: auto;">
            <i class="bi bi-send-plus"></i>
            <span class="d-none d-md-inline ms-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lipia Mchango' : 'Pay Contribution' ?></span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- SECTION 1: PENDING APPROVALS -->
<?php if (!empty($pending_list)): ?>
<div class="card border border-primary-subtle shadow-sm rounded-4 overflow-hidden mb-5">
    <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-hourglass-split me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango Inayosubiri Uhakiki' : 'Pending Approvals' ?></h6>
        <div class="d-flex align-items-center gap-2">
            <div id="pending-length-container"></div>
            <div id="pending-search-container"></div>
            <span class="badge bg-white text-primary rounded-pill fw-bold"><?= count($pending_list) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive p-3 d-none d-md-block d-print-block">
            <table class="table hover align-middle mb-0 w-100" id="pendingTable">
                <thead class="small bg-light">
                    <tr>
                        <th class="ps-4">S/No</th>
                        <th>Submitted At</th>
                        <th>Member (Phone)</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $psno = 1; foreach ($pending_list as $p): ?>
                    <tr>
                        <td class="ps-4 fw-bold"><?= $psno++ ?></td>
                        <td class="small">
                            <div class="fw-bold"><?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
                            <div class="text-primary small fw-bold"><i class="bi bi-clock me-1"></i> <?= date('H:i', strtotime($p['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($p['customer_name'] ?: ($p['first_name'] . ' ' . $p['last_name'])) ?></div>
                            <small class="text-muted"><?= $p['phone'] ?></small>
                        </td>
                        <td class="fw-bold text-primary"><?= number_format($p['amount'], 0) ?></td>
                        <td><span class="badge bg-light text-primary border border-primary-subtle"><?= strtoupper($p['contribution_type']) ?></span></td>
                        <td>
                            <?php
                            $p_status = $p['status'] ?? 'pending';
                            $p_badge  = ['pending'=>'warning','reviewed'=>'info','approved'=>'success','cancelled'=>'secondary'][$p_status] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $p_badge ?>"><?= ucfirst($p_status) ?></span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="dropdown">
                                <button class="btn btn-primary btn-sm rounded-1 dropdown-toggle px-3 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-gear-fill me-1"></i> Action
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="z-index: 1050 !important;">
                                    <li><a class="dropdown-item py-2 rounded mb-1" href="<?= getUrl('contribution_view') ?>?id=<?= $p['contribution_id'] ?>"><i class="bi bi-eye me-2 text-secondary"></i> View Details</a></li>
                                    <li><a class="dropdown-item py-2 rounded mb-1" href="<?= getUrl('print_contribution') ?>?id=<?= $p['contribution_id'] ?>" target="_blank"><i class="bi bi-printer me-2 text-secondary"></i> Print</a></li>
                                    <?php if ($p_status === 'pending' && $can_review): ?>
                                    <li><hr class="dropdown-divider opacity-10"></li>
                                    <li><a class="dropdown-item py-2 fw-bold text-primary rounded mb-1" href="javascript:void(0)" onclick="reviewContribution(<?= $p['contribution_id'] ?>)"><i class="bi bi-clipboard-check me-2"></i> Mark Reviewed</a></li>
                                    <?php endif; ?>
                                    <?php if ($p_status === 'reviewed' && $can_approve): ?>
                                    <li><hr class="dropdown-divider opacity-10"></li>
                                    <li><a class="dropdown-item py-2 fw-bold text-success rounded mb-1" href="javascript:void(0)" onclick="approveContribution(<?= $p['contribution_id'] ?>)"><i class="bi bi-check2-all me-2"></i> Approve</a></li>
                                    <?php endif; ?>
                                    <?php if (!empty($p['evidence_path'])): ?>
                                    <li><a class="dropdown-item py-2 rounded mb-1" href="<?= getUrl($p['evidence_path']) ?>" target="_blank"><i class="bi bi-receipt me-2 text-primary"></i> View Receipt</a></li>
                                    <?php endif; ?>
                                    <?php if ($is_leader && $p_status !== 'approved'): ?>
                                    <li><hr class="dropdown-divider opacity-10"></li>
                                    <li><a class="dropdown-item py-2 rounded text-secondary" href="javascript:void(0)" onclick="rejectContribution(<?= $p['contribution_id'] ?>)"><i class="bi bi-x-circle me-2"></i> Reject Entry</a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- ═══ CARD VIEW — Mobile Only ═══ -->
        <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="pendingCardsWrapper">
            <?php foreach ($pending_list as $p):
                $pc_name   = $p['customer_name'] ?: ($p['first_name'] . ' ' . $p['last_name']);
                $pc_letter = strtoupper(substr($pc_name, 0, 1));
                $pc_isSw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
            ?>
            <div class="vk-member-card">
                <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="vk-card-avatar" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);"><?= $pc_letter ?></div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size:13px;"><?= htmlspecialchars($pc_name) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($p['phone'] ?? '') ?></small>
                        </div>
                    </div>
                    <span class="badge bg-light text-primary border border-primary-subtle rounded-pill px-2" style="font-size:10px;"><?= strtoupper($p['contribution_type']) ?></span>
                </div>
                <div class="vk-card-body">
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $pc_isSw ? 'Tarehe' : 'Date' ?></span>
                        <span class="vk-card-value"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $pc_isSw ? 'Kiasi' : 'Amount' ?></span>
                        <span class="vk-card-value fw-bold text-primary"><?= number_format($p['amount'], 0) ?></span>
                    </div>
                </div>
                <div class="vk-card-actions">
                    <a href="<?= getUrl('contribution_view') ?>?id=<?= $p['contribution_id'] ?>" class="btn vk-btn-action btn-secondary" title="View">
                        <i class="bi bi-eye"></i>
                    </a>
                    <?php if (($p['status']??'pending') === 'pending' && $can_review): ?>
                    <button onclick="reviewContribution(<?= $p['contribution_id'] ?>)" class="btn vk-btn-action btn-primary" title="Review">
                        <i class="bi bi-clipboard-check"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (($p['status']??'pending') === 'reviewed' && $can_approve): ?>
                    <button onclick="approveContribution(<?= $p['contribution_id'] ?>)" class="btn vk-btn-action btn-success" title="Approve">
                        <i class="bi bi-check-circle-fill"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($p['evidence_path'])): ?>
                    <a href="<?= getUrl($p['evidence_path']) ?>" target="_blank" class="btn vk-btn-action btn-primary" title="Receipt">
                        <i class="bi bi-receipt"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($is_leader): ?>
                    <button onclick="rejectContribution(<?= $p['contribution_id'] ?>)" class="btn vk-btn-action btn-danger" title="Reject">
                        <i class="bi bi-x-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Cell colouring vs the monthly target.
$cellClass = ['full' => 'vk-cell-full', 'partial' => 'vk-cell-partial', 'none' => 'vk-cell-none'];
?>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['text-primary','bi-cash-coin', $isSw?'Zimekusanywa (Robo)':'Collected (block)', number_format($sum_collected,0).' TZS'],
        ['text-secondary','bi-bullseye', $isSw?'Zinatarajiwa (Robo)':'Expected (block)', number_format($expected_block,0).' TZS'],
        ['text-success','bi-graph-up-arrow', $isSw?'Kiwango cha Ukusanyaji':'Collection Rate', $collection_rate.'%'],
        ['text-info','bi-people', $isSw?'Wamekamilisha / Nyuma':'Full / Behind', $members_full.' / '.$members_behind],
    ] as [$color,$icon,$label,$val]): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex justify-content-between">
            <div><h5 class="mb-0 fw-bold <?= $color ?>"><?= $val ?></h5><p class="mb-0 text-muted small"><?= $label ?></p></div>
            <div class="align-self-center"><i class="bi <?= $icon ?> <?= $color ?>" style="font-size:1.8rem;opacity:.3;"></i></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- CONTRIBUTION ANALYSIS GRID (the single table) -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="mb-0 fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i> <?= $isSw ? 'Jedwali la Michango' : 'Contribution Analysis Grid' ?>
            <span class="badge bg-primary-subtle text-primary ms-2"><?= htmlspecialchars($block_label) ?></span></h6>
        <div class="d-flex align-items-center gap-2 no-print">
            <span class="small"><span class="vk-cell-full px-2 rounded">&nbsp;</span> <?= $isSw?'Imekamilika':'Full' ?>
                &nbsp;<span class="vk-cell-partial px-2 rounded">&nbsp;</span> <?= $isSw?'Sehemu':'Partial' ?>
                &nbsp;<span class="vk-cell-none px-2 rounded border">&nbsp;</span> <?= $isSw?'Hakuna':'None' ?></span>
            <input type="text" id="gridSearch" class="form-control form-control-sm" style="width:160px;" placeholder="<?= $isSw?'Tafuta mwanachama...':'Search member...' ?>">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center mb-0 vk-grid" style="width:100%">
                <thead class="small bg-light fw-bold text-uppercase">
                    <tr>
                        <th class="py-3 px-2">#</th>
                        <th class="text-start vk-sticky-col"><?= $isSw ? 'Mwanachama' : 'Member' ?></th>
                        <?php foreach ($columns as $col): ?>
                            <th class="bg-primary-subtle text-primary" style="min-width:92px;">
                                <?= htmlspecialchars($col['month_label']) ?>
                                <?php if (($col['year'] ?? '') !== ($columns[0]['year'] ?? '')): ?><small class="text-muted d-block" style="font-size:9px;">'<?= substr($col['year'],2) ?></small><?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="fw-bolder" style="min-width:100px;"><?= $isSw ? 'Jumla ya Robo' : 'Block Total' ?></th>
                        <th class="text-primary fw-bolder" style="min-width:110px;"><?= $isSw ? 'Jumla Kuu' : 'Grand Total' ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($ledger)): ?>
                        <tr><td colspan="<?= $periods_per_block + 4 ?>" class="text-center text-muted py-4"><?= $isSw ? 'Hakuna wanachama wa kuonyesha.' : 'No members to display.' ?></td></tr>
                    <?php else: $sno = 1; foreach ($ledger as $row):
                        $mname = $row['member']['customer_name'] ?: trim($row['member']['first_name'].' '.$row['member']['last_name']); ?>
                        <tr class="vk-grid-row" data-name="<?= htmlspecialchars(strtolower($mname.' '.($row['member']['phone']??''))) ?>">
                            <td class="text-muted"><?= $sno++ ?></td>
                            <td class="text-start vk-sticky-col">
                                <div class="fw-semibold"><?= htmlspecialchars($mname) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($row['member']['phone'] ?? '') ?></small>
                            </td>
                            <?php foreach ($row['cells'] as $cell): ?>
                                <td class="<?= $cellClass[$cell['status']] ?>"><?= $cell['paid'] > 0 ? number_format($cell['paid'], 0) : '<span class="opacity-25">—</span>' ?></td>
                            <?php endforeach; ?>
                            <td class="fw-bold"><?= number_format($row['block_total'], 0) ?></td>
                            <td class="fw-bold text-primary"><?= number_format($row['grand_total'], 0) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($ledger)): ?>
                <tfoot class="bg-light fw-bold small">
                    <tr>
                        <td colspan="2" class="text-end"><?= $isSw ? 'Zimekusanywa' : 'Collected' ?></td>
                        <?php foreach ($col_totals as $ct): ?><td><?= number_format($ct, 0) ?></td><?php endforeach; ?>
                        <td><?= number_format($sum_collected, 0) ?></td><td></td>
                    </tr>
                    <tr class="text-muted">
                        <td colspan="2" class="text-end"><?= $isSw ? 'Lengo' : 'Target' ?></td>
                        <?php foreach ($columns as $col): ?><td><?= number_format(count($members) * $monthly_val, 0) ?></td><?php endforeach; ?>
                        <td><?= number_format($expected_block, 0) ?></td><td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Date-range statement modal -->
<div class="modal fade" id="statementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0"><h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><?= $isSw ? 'Taarifa ya Miamala' : 'Transactions Statement' ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="statementForm" target="_blank" method="get" action="<?= getUrl('contribution_statement') ?>">
                <div class="modal-body p-4"><div class="row g-3">
                    <div class="col-md-6"><label class="form-label small fw-bold"><?= $isSw ? 'Kuanzia' : 'From' ?></label><input type="date" name="from" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label small fw-bold"><?= $isSw ? 'Hadi' : 'To' ?></label><input type="date" name="to" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label small fw-bold"><?= $isSw ? 'Mwanachama (si lazima)' : 'Member (optional)' ?></label>
                        <select name="member_id" class="form-select"><option value=""><?= $isSw ? 'Wote (Kikundi)' : 'All (group-wide)' ?></option>
                        <?php foreach ($statement_members as $sm): ?><option value="<?= (int)$sm['customer_id'] ?>"><?= htmlspecialchars($sm['name'] !== '' ? $sm['name'] : ('Member #'.$sm['customer_id'])) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label small fw-bold"><?= $isSw ? 'Hali (si lazima)' : 'Status (optional)' ?></label>
                        <select name="status" class="form-select"><option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                        <?php foreach (['pending','reviewed','approved','cancelled'] as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
                </div></div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal"><?= $isSw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-printer me-1"></i><?= $isSw ? 'Chapisha' : 'Print' ?></button>
                    <button type="button" class="btn btn-success btn-sm rounded-pill px-3" onclick="exportStatement()"><i class="bi bi-file-earmark-excel me-1"></i><?= $isSw ? 'Excel' : 'Export Excel' ?></button>
                    <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="exportStatementMkoba()" title="<?= $isSw ? 'Muundo unaofanana na taarifa ya M-Koba (kwa ulinganishaji)' : 'Same column layout as an M-Koba extract (for reconciliation)' ?>"><i class="bi bi-arrow-left-right me-1"></i><?= $isSw ? 'Muundo wa M-Koba' : 'M-Koba format' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Recording (manual + bulk + M-Koba) moved to the Transactions page.

// Pending Approvals DataTable Initialization
$(document).ready(function() {
    const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    $('#pendingTable').DataTable({
        "paging": true,
        "ordering": true,
        "info": false,
        "responsive": true,
        "pageLength": 5,
        "dom": 'rtp', // Only table and pagination
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": isSw ? "Tafuta inayosubiri..." : "Search pending...",
            "url": 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/' + (isSw ? 'sw.json' : 'en-GB.json')
        }
    });

    // Custom Search for Pending Table
    let pendingSearch = $('<div class="input-group input-group-sm" style="width: 200px;"><span class="input-group-text bg-transparent border-white text-white"><i class="bi bi-search"></i></span><input type="text" class="form-control bg-transparent border-white text-white placeholder-white" placeholder="Search..."></div>');
    $('#pending-search-container').append(pendingSearch);
    pendingSearch.find('input').on('keyup', function() {
        $('#pendingTable').DataTable().search($(this).val()).draw();
    });
});


// Grid: client-side member search + statement Excel export.
$(document).ready(function () {
    $('#gridSearch').on('keyup', function () {
        var q = $(this).val().toLowerCase();
        $('.vk-grid-row').each(function () {
            $(this).toggle(('' + $(this).data('name')).indexOf(q) !== -1);
        });
    });
});
// Both exports share the same From/To (+ optional member/status) filters; only
// the endpoint differs — the M-Koba one mirrors an M-Koba extract's columns.
function _statementQs() {
    var f = document.getElementById('statementForm');
    if (!f.from.value || !f.to.value) { Swal.fire('Error', '<?= $isSw ? "Weka tarehe za mwanzo na mwisho." : "Please choose the From and To dates." ?>', 'warning'); return null; }
    return new URLSearchParams({ from: f.from.value, to: f.to.value, member_id: f.member_id.value, status: f.status.value }).toString();
}
function exportStatement() {
    var qs = _statementQs();
    if (qs === null) return;
    window.open('<?= getUrl("api/export_contributions_statement") ?>?' + qs, '_blank');
}
function exportStatementMkoba() {
    var qs = _statementQs();
    if (qs === null) return;
    window.open('<?= getUrl("api/export_contributions_statement_mkoba") ?>?' + qs, '_blank');
}

function _wfPost(url, id, loadingMsg) {
    Swal.fire({ title: loadingMsg, didOpen: () => Swal.showLoading() });
    $.post(url, { id: id }, function(r) {
        if (r.success) {
            Swal.fire({ icon:'success', title:'Done', text: r.message, timer:1500, showConfirmButton:false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', r.message, 'error');
        }
    }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
}

function reviewContribution(id) {
    const isSw = (<?= json_encode($_SESSION['preferred_language'] ?? 'en') ?> === 'sw');
    Swal.fire({
        title: isSw ? 'Pitia mchango huu?' : 'Mark as Reviewed?',
        text:  isSw ? 'Thibitisha umepitia mchango huu.' : 'Confirm you have reviewed this contribution.',
        icon: 'question', showCancelButton: true,
        confirmButtonText: isSw ? 'Ndio, Pitia' : 'Yes, Reviewed'
    }).then(r => { if (r.isConfirmed) _wfPost('<?= getUrl("api/review_contribution") ?>', id, isSw?'Inatuma...':'Submitting...'); });
}

function approveContribution(id) {
    const isSw = (<?= json_encode($_SESSION['preferred_language'] ?? 'en') ?> === 'sw');
    Swal.fire({
        title: isSw ? 'Idhinisha mchango huu?' : 'Approve Contribution?',
        text:  isSw ? 'Mchango utahamia hali ya "Imeidhinishwa".' : 'This will move the contribution to Approved status.',
        icon: 'question', showCancelButton: true,
        confirmButtonText: isSw ? 'Ndio, Idhinisha' : 'Yes, Approve',
        confirmButtonColor: '#198754'
    }).then(r => { if (r.isConfirmed) _wfPost('<?= getUrl("api/approve_contribution") ?>', id, isSw?'Inaidhinisha...':'Approving...'); });
}

function rejectContribution(id) {
    Swal.fire({
        title: 'Reject Payment?',
        text: 'This will remove/cancel this entry.',
        icon: 'warning',
        confirmButtonColor: '#6c757d'
    }).then((res) => {
        if (res.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("actions/update_contribution") ?>',
                type: 'POST',
                data: { id: id, status: 'cancelled' },
                dataType: 'json',
                success: function(r) { location.reload(); }
            });
        }
    });
}
</script>

<style>
.table-responsive { scrollbar-width: thin; }
th { font-size: 0.7rem !important; }
.bg-primary-subtle { background-color: #e7f1ff !important; }

/* Contribution grid: colour-coded cells vs the monthly target */
.vk-grid td { font-size: 0.85rem; }
.vk-cell-full    { background-color: #d1e7dd !important; color: #0f5132 !important; font-weight: 600; }
.vk-cell-partial { background-color: #fff3cd !important; color: #664d03 !important; font-weight: 600; }
.vk-cell-none    { background-color: #fff !important; }
/* Sticky member column so it stays visible while scrolling months */
.vk-grid .vk-sticky-col { position: sticky; left: 0; z-index: 2; background: #fff; min-width: 150px; box-shadow: 2px 0 4px -2px rgba(0,0,0,.12); }
.vk-grid thead .vk-sticky-col { z-index: 3; background: #f8f9fa; }
@media print { .vk-grid .vk-sticky-col { position: static; box-shadow: none; } }

/* Autocomplete Premium Styles */
#autocomplete_results .list-group-item {
    border-left: 0; border-right: 0;
    transition: all 0.2s ease;
    cursor: pointer;
    font-size: 0.85rem;
}
#autocomplete_results .list-group-item:hover {
    background-color: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding-left: 0.75rem !important;
}
.bg-success-subtle { background-color: #d1e7dd !important; }
.bg-warning-subtle { background-color: #fff3cd !important; }

/* White Buttons for DataTables */
.btn-white {
    background: #fff !important;
    border: 1px solid #dee2e6 !important;
    color: #333 !important;
    font-size: 0.75rem !important;
    text-transform: uppercase;
    transition: all 0.3s;
}
.btn-white:hover {
    background: #f8f9fa !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1) !important;
    transform: translateY(-1px);
}
.dt-buttons { gap: 10px; display: flex; }
.dataTables_length select {
    border-radius: 5px;
    padding: 0.15rem 1.75rem 0.15rem 0.75rem;
    border: 1px solid #dee2e6;
    font-size: 0.75rem;
    font-weight: bold;
    color: #333;
    background-color: #fff;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.dataTables_length label {
    font-size: 0.75rem;
    font-weight: bold;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.dataTables_length select {
    margin: 0 !important;
}
@media print {
    .no-print { display: none !important; }
}

/* Mobile View Optimization (Kwa ajili ya simu tu) */
@media (max-width: 768px) {
    * { box-sizing: border-box !important; }
    .table-responsive {
        overflow-x: hidden !important; /* Kata scrolling ya pembeni */
        padding: 0 !important;
        margin: 0 !important;
    }
    .table {
        width: 100% !important;
        max-width: 100% !important;
        font-size: 0.72rem !important; /* Punguza kidogo zaidi kutoshea vizuri */
        margin-bottom: 0 !important;
    }
    .table th, .table td {
        padding: 8px 2px !important; /* Bana nafasi pembeni sana kuzuia kukatwa */
        white-space: normal !important; 
        word-break: break-all !important;
    }
    .table th {
        font-size: 0.65rem !important;
        white-space: nowrap !important; /* Headers zibaki mstari mmoja kama inawezekana */
    }
    /* Zuia kukata herufi (Truncation protection) */
    .table td div, .table td span {
        overflow: visible !important;
        text-overflow: clip !important;
        white-space: normal !important;
    }

    /* Compact Buttons and Selects for Mobile */
    .btn-white {
        padding: 4px 10px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        display: flex !important;
        align-items: center !important;
    }
    .dt-buttons {
        gap: 4px !important;
        display: flex !important;
        align-items: center !important;
    }
    #ledger-length-container .input-group {
        width: auto !important;
        height: 28px !important;
        flex-wrap: nowrap !important;
    }
    #ledger-length-container .form-select {
        padding: 0 20px 0 8px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        min-height: 28px !important;
        border: none !important;
    }
    #ledger-length-container .input-group-text {
        padding: 0 6px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        background: white !important;
        border: none !important;
    }
    #ledger-search-container .input-group {
        width: 150px !important; /* Made slightly longer for mobile */
        height: 28px !important;
    }
    #ledger-search-container input {
        padding: 4px 8px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
    }

    /* Buttons na Inputs ziwe rafiki kwa simu */
    h2 { font-size: 1.4rem !important; }
    .card-body { padding: 10px !important; }
    #pending-search-container { display: none !important; } /* Hide compact search on mobile to save space */
}

/* Web View Specific Overrides */
@media (min-width: 769px) {
    #ledger-search-container .input-group {
        width: 220px !important; /* Keep search bar short on web */
        float: right;
    }
}

.placeholder-white::placeholder { color: rgba(255,255,255,0.7) !important; }
</style>

<?php
$content = ob_get_clean();
echo $content;
require_once 'footer.php';
?>
