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

// 3. Grid Navigation Logic (Block of 4)
$block = intval($_GET['block'] ?? 0);
$periods_per_block = 4;
$start_offset = $block * $periods_per_block;

$columns = [];
for ($i = 0; $i < $periods_per_block; $i++) {
    $idx = $start_offset + $i;
    $ts = strtotime($start_date . " +$idx " . ($cycle === 'weekly' ? 'weeks' : 'months'));
    $columns[] = [
        'idx' => $idx,
        'label' => date($cycle === 'weekly' ? 'Wk d M' : 'M Y', $ts),
        'start_ts' => date('Y-m-d 00:00:00', $ts),
        'end_ts' => date('Y-m-t 23:59:59', $ts) // Simplified for monthly
    ];
    // Adjust end date for weekly
    if ($cycle === 'weekly') {
        $columns[$i]['end_ts'] = date('Y-m-d 23:59:59', strtotime(date('Y-m-d', $ts) . " +6 days"));
    }
}

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

// 5. Build Ledger Data (Intelligent Distribution)
$ledger = [];
$monthly_val = floatval($settings_raw['monthly_contribution'] ?? 10000);
$entrance_val = floatval($settings_raw['entrance_fee'] ?? 20000);

foreach ($members as $m) {
    $row = [
        'member' => $m,
        'periods' => [],
        'block_total' => 0,
        'grand_total' => 0
    ];
    
    // Total Confirmed Pot for this member
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM contributions WHERE member_id = ? AND status IN ('confirmed', 'approved', '')");
    $stmt->execute([$m['customer_id']]);
    $row['grand_total'] = floatval($stmt->fetchColumn());

    // Intelligence: Subtract Entrance first, then distribute rest to months
    $pot = $row['grand_total']; 
    $pot -= min($pot, $entrance_val); 
    
    // Fill all periods from the start up to our current view window
    for ($i = 0; $i < ($start_offset + $periods_per_block); $i++) {
        $amt_for_this_idx = min($pot, $monthly_val);
        $pot -= $amt_for_this_idx;
        
        // Only add to 'periods' if it falls within the current 4-period block view
        if ($i >= $start_offset) {
            $row['periods'][] = $amt_for_this_idx;
            $row['block_total'] += $amt_for_this_idx;
        }
    }
    
    $ledger[] = $row;
}

// ── Filtered Contributions List (this page is now the dedicated listing) ───────
$f = [
    'from'    => trim($_GET['from'] ?? ''),
    'to'      => trim($_GET['to'] ?? ''),
    'member'  => trim($_GET['member'] ?? ''),
    'type'    => trim($_GET['type'] ?? ''),
    'fstatus' => trim($_GET['fstatus'] ?? ''),
    'account' => trim($_GET['account'] ?? ''),
];
$validYmd = function ($d) {
    $dt = \DateTime::createFromFormat('Y-m-d', $d);
    return $d !== '' && $dt && $dt->format('Y-m-d') === $d;
};
$where = ['1=1'];
$params = [];
if ($validYmd($f['from'])) { $where[] = 'con.contribution_date >= ?'; $params[] = $f['from']; }
if ($validYmd($f['to']))   { $where[] = 'con.contribution_date <= ?'; $params[] = $f['to']; }
if ($f['member'] !== '') {
    $where[] = '(c.customer_name LIKE ? OR c.phone LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ?)';
    $like = '%' . $f['member'] . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($f['type'], ['entrance', 'monthly', 'agm', 'fine', 'other'], true))         { $where[] = 'con.contribution_type = ?'; $params[] = $f['type']; }
if (in_array($f['fstatus'], ['pending', 'reviewed', 'approved', 'cancelled'], true))     { $where[] = 'con.status = ?'; $params[] = $f['fstatus']; }
if (in_array($f['account'], ['M-Koba', 'Bank', 'Cash', 'Mobile Money'], true))           { $where[] = 'con.account = ?'; $params[] = $f['account']; }

$stmtList = $pdo->prepare("
    SELECT con.contribution_id, con.amount, con.contribution_type, con.contribution_date,
           con.receipt_number, con.account, con.status,
           c.customer_name, c.first_name, c.last_name, c.phone
    FROM contributions con
    JOIN customers c ON con.member_id = c.customer_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY con.contribution_date DESC, con.contribution_id DESC
    LIMIT 500
");
$stmtList->execute($params);
$contribList = $stmtList->fetchAll(PDO::FETCH_ASSOC);
$contribListTotal = array_sum(array_column($contribList, 'amount'));
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
            <span class="btn btn-white border-0 disabled fw-bold py-2 d-none d-md-inline-block shadow-none"><?= $columns[0]['label'] ?> — <?= $columns[3]['label'] ?></span>
            <span class="btn btn-white border-0 disabled fw-bold py-2 d-md-none small shadow-none px-1"><?= substr($columns[0]['label'],0,3) ?>-<?= substr($columns[3]['label'],0,3) ?></span>
            <a href="?block=<?= $block + 1 ?>" class="btn btn-light border-0 px-2 px-md-3" title="Next"><i class="bi bi-chevron-right"></i></a>
        </div>
        
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

<!-- SECTION 2: CONTRIBUTIONS LIST (filterable — this page's dedicated listing) -->
<div class="card border-0 shadow-sm rounded-4 mb-5">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i><?= $isSw ? 'Orodha ya Michango' : 'Contributions List' ?></h6>
        <span class="small text-muted"><?= count($contribList) ?> <?= $isSw ? 'kumbukumbu' : 'records' ?> · <?= number_format($contribListTotal, 0) ?> TZS</span>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-6 col-md-2"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Kuanzia' : 'From' ?></label><input type="date" name="from" value="<?= htmlspecialchars($f['from']) ?>" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Hadi' : 'To' ?></label><input type="date" name="to" value="<?= htmlspecialchars($f['to']) ?>" class="form-control form-control-sm"></div>
            <div class="col-12 col-md-3"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Mwanachama' : 'Member' ?></label><input type="text" name="member" value="<?= htmlspecialchars($f['member']) ?>" class="form-control form-control-sm" placeholder="<?= $isSw ? 'Jina au simu' : 'Name or phone' ?>"></div>
            <div class="col-6 col-md-1"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Aina' : 'Type' ?></label>
                <select name="type" class="form-select form-select-sm"><option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                <?php foreach (['monthly', 'entrance', 'agm', 'fine', 'other'] as $t): ?><option value="<?= $t ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Hali' : 'Status' ?></label>
                <select name="fstatus" class="form-select form-select-sm"><option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                <?php foreach (['pending', 'reviewed', 'approved', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $f['fstatus'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1 fw-bold"><?= $isSw ? 'Akaunti' : 'Account' ?></label>
                <select name="account" class="form-select form-select-sm"><option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                <?php foreach (['M-Koba', 'Bank', 'Cash', 'Mobile Money'] as $a): ?><option value="<?= $a ?>" <?= $f['account'] === $a ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3"><i class="bi bi-funnel me-1"></i><?= $isSw ? 'Chuja' : 'Filter' ?></button>
                <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-light btn-sm rounded-pill px-3"><?= $isSw ? 'Futa' : 'Clear' ?></a>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= $isSw ? 'Tarehe' : 'Date' ?></th><th><?= $isSw ? 'Mwanachama' : 'Member' ?></th>
                        <th><?= $isSw ? 'Risiti' : 'Receipt' ?></th><th><?= $isSw ? 'Akaunti' : 'Account' ?></th>
                        <th><?= $isSw ? 'Aina' : 'Type' ?></th><th class="text-end"><?= $isSw ? 'Kiasi' : 'Amount' ?></th>
                        <th class="text-center"><?= $isSw ? 'Hali' : 'Status' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$contribList): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4"><?= $isSw ? 'Hakuna michango inayolingana na vichujio.' : 'No contributions match the filters.' ?></td></tr>
                    <?php else: foreach ($contribList as $r): $sb = ['pending' => 'warning', 'reviewed' => 'info', 'approved' => 'success', 'cancelled' => 'secondary']; ?>
                        <tr>
                            <td><?= safe_output($r['contribution_date'], '—') ?></td>
                            <td><?= htmlspecialchars($r['customer_name'] ?: ($r['first_name'] . ' ' . $r['last_name'])) ?><div class="text-muted" style="font-size:.75rem;"><?= safe_output($r['phone'], '') ?></div></td>
                            <td><?= safe_output($r['receipt_number'], '—') ?></td>
                            <td><?= safe_output($r['account'], '—') ?></td>
                            <td><?= safe_output(ucfirst($r['contribution_type']), '—') ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $r['amount'], 0) ?></td>
                            <td class="text-center"><span class="badge bg-<?= $sb[$r['status']] ?? 'secondary' ?>"><?= safe_output($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION 3: DYNAMIC GRID LEDGER -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jedwali la Michango' : 'Contribution Analysis Grid' ?></h6>
        <div class="small text-muted italic">* Confirmed totals for the selected 4-period block.</div>
    </div>
    <div class="card-body p-4">
        <!-- STANDALONE TOOLS (WHITE & RECTANGULAR) -->
        <div class="row g-1 g-md-2 mb-4 align-items-center no-print">
            <div class="col-auto d-flex align-items-center gap-1" id="ledger-tools-left">
                <!-- PRINT and CSV -->
            </div>
            <div class="col-auto" id="ledger-length-container">
                <!-- SHOW Rows -->
            </div>
            <div class="col ms-auto text-end" id="ledger-search-container">
                <!-- SEARCH -->
            </div>
        </div>

        <div class="table-responsive d-none d-md-block d-print-block">
            <table class="table table-bordered align-middle text-center mb-0" id="ledgerTable" style="width: 100% !important;">
                <thead class="small bg-light fw-bold text-uppercase">
                    <tr>
                        <th class="py-3 px-3 text-center">S/No</th>
                        <th class="text-center"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member' ?></th>
                        <th class="text-center" style="min-width: 110px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone No' ?></th>
                        
                        <?php foreach ($columns as $idx => $col): ?>
                            <th class="bg-primary-subtle text-primary border-primary border-opacity-25 text-center" style="min-width: 100px;"><?= $col['label'] ?></th>
                        <?php endforeach; ?>
                        
                        <th class="bg-white text-dark text-center fw-bolder" style="min-width: 120px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla ya Robo' : 'Block Total' ?></th>
                        <th class="bg-white text-primary text-center fw-bolder" style="min-width: 130px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla Kuu' : 'Grand Total' ?></th>
                        <th class="bg-light text-center no-print"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Loaded via AJAX -->
                </tbody>
            </table>
        </div>
        <!-- ═══ CARD VIEW — Mobile Only ═══ -->
        <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="ledgerCardsWrapper"></div>
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

// DataTables AJAX Initialization
$(document).ready(function() {
    const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    const currentUsername = '<?= htmlspecialchars($username) ?>';
    const currentUserRole = '<?= htmlspecialchars($user_role) ?>';
    const currentDate = new Date().toLocaleDateString(isSw ? 'sw-TZ' : 'en-US', { day: 'numeric', month: 'long', year: 'numeric' });
    const currentTime = new Date().toLocaleTimeString(isSw ? 'sw-TZ' : 'en-US', { hour: '2-digit', minute: '2-digit' });

    let ledgerTable = $('#ledgerTable').DataTable({
        "processing": true,
        "serverSide": true,
        "responsive": true,
        "ajax": {
            "url": "<?= getUrl('api/get_contribution_ledger') ?>?block=<?= $block ?>",
            "type": "GET"
        },
        "columns": [
            { "data": "sno", "width": "30px" },
            { "data": "name", "className": "text-start fw-bold" }, /* Let name be flexible but dominant */
            { "data": "phone", "className": "small text-muted", "width": "100px" },
            { "data": "periods.0", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.1", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.2", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.3", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "block_total", "render": (v) => '<b style="color:black; font-weight:900;">'+v.toLocaleString()+'</b>', "className": "bg-white text-center" },
            { "data": "grand_total", "render": (v) => '<b style="color:#0d6efd; font-weight:900;">'+v.toLocaleString()+'</b>', "className": "bg-white text-center fs-6" },
            { 
                "data": "customer_id", 
                "className": "no-print",
                "render": (id) => `
                    <div class="dropdown no-print">
                        <button class="btn btn-white btn-sm border shadow-sm dropdown-toggle rounded-2 px-3" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear-fill me-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><a class="dropdown-item py-2 rounded-2 small fw-bold" href="<?= getUrl('member_statement') ?>?id=${id}"><i class="bi bi-eye me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'View (Tazama)' : 'View Statement' ?></a></li>
                        </ul>
                    </div>
                ` 
            }
        ],
        "dom": 'Brtip', // Buttons, Table, Info, Pagination (Removed default Length 'l' and Filter 'f')
        "buttons": [
            {
                extend: 'print',
                title: '', /* Remove default title completely */
                header: true,
                text: '<i class="bi bi-printer me-1"></i> PRINT',
                className: 'btn btn-white border shadow-sm rounded-2 px-3 py-1 small fw-bold text-dark',
                exportOptions: {
                    columns: ':not(.no-print)'
                },
                customize: function (win) {
                    // 1. HIDDEN BROWSER HEADERS & CUSTOM VIRTUAL MARGINS
                    // 1. Canonical page margins + shared footer CSS (mirrors includes/print_footer_css.php)
                    $(win.document.head).append(`
                        <style>
                            @page { margin: 10mm 8mm 16mm 8mm; }
                            * { box-sizing: border-box !important; }
                            html, body { width: 100% !important; margin: 0 !important; }
                            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important; font-size: 11pt !important; color: #1a252f !important; line-height: 1.5 !important; padding: 20px 20px 0 20px !important; background: white !important; }
                            table { width: 100% !important; border-collapse: collapse !important; margin: 0 !important; page-break-after: auto !important; }
                            table th, table td { border: 1px solid #000 !important; padding: 6px 4px !important; text-align: center !important; vertical-align: middle !important; font-size: 10.5pt !important; }
                            table th { background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; text-transform: uppercase; font-size: 11pt !important; }
                            table td { word-break: break-word !important; }
                            .print-footer {
                                position: fixed;
                                bottom: 0; left: 0; right: 0;
                                height: 16px;
                                background: #fff;
                                border-top: 1px solid #dee2e6;
                                padding: 0 22px;
                                text-align: center;
                                display: flex;
                                flex-direction: column;
                                justify-content: flex-end;
                                print-color-adjust: exact;
                                -webkit-print-color-adjust: exact;
                            }
                            .print-footer p { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1; }
                            .print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; }
                            tfoot.print-spacer { display: table-footer-group; }
                            tfoot.print-spacer td { height: 12px !important; border: none !important; }
                            .no-print { display: none !important; }
                        </style><?php echo PrintHeader::popupCss(); ?>
                    `);

                    // 2. Clear out any browser-injected title
                    $(win.document.body).find('h1').remove();

                    // 3. Branded Header
                    $(win.document.body).prepend(`<div class="vk-print-header">
                        <img src="<?= !empty($logo_base64) ? $logo_base64 : '/assets/images/' . $group_logo ?>" alt="Logo" class="vk-ph-logo">
                        <div class="vk-ph-org"><?= htmlspecialchars($group_name) ?></div>
                        <div class="vk-ph-sys">VICOBA Group Management System</div>
                        <div class="vk-ph-title"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MCHANGANUO WA MICHANGO' : 'CONTRIBUTION ANALYSIS LEDGER' ?></div>
                        <div class="vk-ph-ref">Block Period: <?= $columns[0]['label'] ?> — <?= $columns[3]['label'] ?></div>
                        <div class="vk-ph-rule"></div>
                    </div>`);

                    // 4. Shared-style footer (bilingual — mirrors includes/print_footer_html.php)
                    let mcNow = new Date();
                    let _mcT = mcNow.getHours().toString().padStart(2,'0') + ':' +
                               mcNow.getMinutes().toString().padStart(2,'0') + ':' +
                               mcNow.getSeconds().toString().padStart(2,'0');
                    let _mcD  = mcNow.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
                    let _mcSw = <?= $isSw ? 'true' : 'false' ?>;
                    let _mcBy = _mcSw ? 'Nyaraka hii imechapishwa na' : 'This document was Printed by';
                    let _mcOn = _mcSw ? 'mnamo' : 'on';
                    let _mcAt = _mcD + ' ' + (_mcSw ? 'saa' : 'at') + ' ' + _mcT;

                    $(win.document.body).append(`
                        <div class="print-footer">
                            <p>${_mcBy} <strong><?= htmlspecialchars($username ?? $_SESSION['username']) ?></strong> &mdash; <strong><?= htmlspecialchars(ucfirst($user_role ?? 'Member')) ?></strong> ${_mcOn} ${_mcAt}</p>
                            <p class="brand">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
                        </div>
                    `);

                    // 5. Spacer for multi-page data integrity
                    $(win.document.body).find('table').append('<tfoot class="print-spacer"><tr><td colspan="10">&nbsp;</td></tr></tfoot>');
                    
                    $(win.document.body).find('.no-print').remove();
                }
            },
            {
                extend: 'csv',
                text: '<i class="bi bi-filetype-csv me-1"></i> CSV',
                className: 'btn btn-white border shadow-sm rounded-2 px-3 py-1 small fw-bold text-dark'
            }
        ],
        "pageLength": 25,
        "language": {
            "url": 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/' + (isSw ? 'sw.json' : 'en-GB.json')
        },
        "drawCallback": function() {
            renderLedgerCards(this.api());
        }
    });

    function vkEscL(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderLedgerCards(api) {
        var isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
        var html = '';
        api.rows({page:'current'}).data().each(function(row) {
            if (!row) return;
            var initial = row.name ? row.name.trim()[0].toUpperCase() : '?';
            html += '<div class="vk-member-card">'
                + '<div class="vk-card-header d-flex justify-content-between align-items-center gap-2">'
                + '<div class="d-flex align-items-center gap-2">'
                + '<div class="vk-card-avatar" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);">'+initial+'</div>'
                + '<div><div class="fw-bold text-dark" style="font-size:13px;">'+vkEscL(row.name)+'</div>'
                + '<small class="text-muted">'+vkEscL(row.phone)+'</small></div></div>'
                + '<span class="badge bg-primary rounded-pill px-2" style="font-size:10px;">'+vkEscL(row.grand_total ? row.grand_total.toLocaleString() : 0)+'</span>'
                + '</div>'
                + '<div class="vk-card-body">'
                + '<div class="vk-card-row"><span class="vk-card-label">'+(isSw?'Jumla ya Robo':'Block Total')+'</span><span class="vk-card-value fw-bold">'+vkEscL(row.block_total ? row.block_total.toLocaleString() : '—')+'</span></div>'
                + '<div class="vk-card-row"><span class="vk-card-label">'+(isSw?'Jumla Kuu':'Grand Total')+'</span><span class="vk-card-value fw-bold text-primary">'+vkEscL(row.grand_total ? row.grand_total.toLocaleString() : '—')+'</span></div>'
                + '</div>'
                + '<div class="vk-card-actions">'
                + '<a href="<?= getUrl('member_statement') ?>?id='+vkEscL(row.customer_id)+'" class="btn vk-btn-action btn-primary" title="'+(isSw?'Taarifa':'Statement')+'"><i class="bi bi-file-earmark-person"></i></a>'
                + '</div>'
                + '</div>';
        });
        $('#ledgerCardsWrapper').html(html || '<div class="text-center py-5 text-muted"><p>'+(isSw?'Hakuna data':'No data')+'</p></div>');
    }

    // Custom Length Menu for Ledger
    let ledgerLength = $(`
        <div class="input-group input-group-sm shadow-sm border rounded-2 bg-white overflow-hidden">
            <span class="input-group-text bg-white border-0"><i class="bi bi-view-list"></i></span>
            <select class="form-select border-0 bg-transparent fw-bold" style="cursor: pointer;">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
        </div>
    `);
    $('#ledger-length-container').append(ledgerLength);
    ledgerLength.find('select').on('change', function() {
        ledgerTable.page.len($(this).val()).draw();
    });

    // Custom Search for Ledger
    let ledgerSearch = $(`<div class="input-group shadow-sm border rounded-2 bg-white overflow-hidden"><span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span><input type="text" class="form-control border-0 px-3" placeholder="${isSw ? 'Tafuta...' : 'Search...'}"></div>`);
    $('#ledger-search-container').append(ledgerSearch);
    ledgerSearch.find('input').on('keyup', function() {
        ledgerTable.search($(this).val()).draw();
    });

    // Move buttons
    setTimeout(() => {
        $('.dt-buttons').appendTo('#ledger-tools-left');
    }, 50);
});

// Removed auto-lookup keyup trigger to ensure modal stability

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
#ledgerTable td { font-size: 0.85rem; }
#ledgerTable th:nth-child(2), #ledgerTable td:nth-child(2) {
    min-width: 140px !important; /* Member column iwe na nafasi ya kutosha lakini isizidi */
}
#ledgerTable th:nth-child(3), #ledgerTable td:nth-child(3) {
    white-space: nowrap !important; /* Phone No isikatwe */
}
.bg-primary-subtle { background-color: #e7f1ff !important; }

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
