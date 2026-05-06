<?php
ob_start();
date_default_timezone_set('Africa/Nairobi');
require_once HEADER_FILE;

$member_id = intval($_GET['id'] ?? 0);

// If the user has permission to view group reports, they can see any member's statement.
// Otherwise, they are restricted to seeing only their own statement.
if (!canView('vicoba_reports')) {
    $cust_stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $cust_stmt->execute([$_SESSION['user_id']]);
    $logged_cid = $cust_stmt->fetchColumn();
    $member_id = $logged_cid;
}

if (!$member_id) {
    echo '<div class="alert alert-danger m-4">' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama hajapatikana.' : 'Member not found.') . '</div>';
    $content = ob_get_clean(); echo $content; require_once FOOTER_FILE; exit();
}

// 1. Fetch Member Details
$member = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$member->execute([$member_id]);
$member = $member->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Group Settings (Monthly Contribution, Entrance Fee, Start Date)
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly_amt = floatval($settings_raw['monthly_contribution'] ?? 10000);
$entrance_amt = floatval($settings_raw['entrance_fee'] ?? 20000);
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

// 4. Fetch All Confirmed Contributions (Total Pot)
// IMPORTANT: We must include the 'initial_savings' from the registration as part of the total paid pot.
$stmt = $pdo->prepare("SELECT SUM(amount) FROM contributions WHERE member_id = ? AND status = 'confirmed' AND (contribution_type = 'monthly' OR contribution_type = 'entrance' OR contribution_type = 'other')");
$stmt->execute([$member_id]);
$contributions_total = floatval($stmt->fetchColumn());

// Total paid = Initial Savings (from registration) + Confirmed Contributions (from table)
$total_paid = floatval($member['initial_savings'] ?? 0) + $contributions_total;

// 5. Query Dynamic Columns needed
// We show at least 12 months, or up to the current month, or up to what they've paid
$total_paid_for_monthly = max(0, $total_paid - $entrance_amt);
$total_months_covered = floor($total_paid_for_monthly / $monthly_amt);
$has_remainder = ($total_paid_for_monthly % $monthly_amt) > 0;

$current_month_idx = (intval(date('Y')) - intval(date('Y', strtotime($contribution_start_date)))) * 12 + (intval(date('m')) - intval(date('m', strtotime($contribution_start_date))));
$columns_count = max(12, $total_months_covered, $current_month_idx + 1);

// 6. Logic: Distribute $total_paid to periods (THE INTELLIGENCE)
$remaining_pot = $total_paid;

// A. Deduct Entrance Fee but don't show it in grid
$entrance_paid_amt = min($remaining_pot, $entrance_amt);
$remaining_pot -= $entrance_paid_amt;
$entrance_status = ($entrance_paid_amt >= $entrance_amt) ? 'paid' : ($entrance_paid_amt > 0 ? 'partial' : 'unpaid');

// B. Fill Monthly grid from the remaining pot
$distribution = [];
for ($i = 0; $i < $columns_count; $i++) {
    $month_ts = strtotime($contribution_start_date . " +$i months");
    $month_label = date('M Y', $month_ts);
    
    $paid_for_this_month = min($remaining_pot, $monthly_amt);
    $remaining_pot -= $paid_for_this_month;
    
    $status = ($paid_for_this_month >= $monthly_amt) ? 'paid' : ($paid_for_this_month > 0 ? 'partial' : 'unpaid');
    
    $distribution[] = [
        'label' => $month_label,
        'amount' => $paid_for_this_month,
        'status' => $status,
        'target' => $monthly_amt
    ];
}

// C. If there is STILL money left (Advanced beyond our columns), OR a partial remainder
if ($remaining_pot > 0) {
    $distribution[] = [
        'label' => ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'ZIADA (ADVANCE)' : 'ADVANCE / CREDIT',
        'amount' => $remaining_pot,
        'status' => 'paid',
        'target' => 0
    ];
}

// 7. Expenses (Death Benefits)
$stmt = $pdo->prepare("SELECT * FROM death_expenses WHERE member_id = ? ORDER BY expense_date DESC");
$stmt->execute([$member_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_expenses = array_sum(array_column($expenses, 'amount'));

?>
<!-- 1. PRINT HEADER (Visible only during print) -->
<?= getPrintHeader(($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'HALI YA KIFEDHA YA MWANACHAMA' : 'MEMBER FINANCIAL STATEMENT', ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama:' : 'Member:' . ' ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . ' | #' . $member['customer_id']) ?>

<div class="no-print mb-4">
    <div class="row align-items-center g-3">
        <div class="col-md">
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-bank me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali ya Kifedha ya Mwanachama' : 'Member Financial Statement' ?></h3>
            <p class="text-muted small mb-0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?> | #<?= $member['customer_id'] ?></p>
        </div>
        <div class="col-md-auto d-flex gap-2">
            <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-outline-primary rounded-pill px-4 shadow-sm fw-bold">
                <i class="bi bi-arrow-left me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudi Kwenye Orodha' : 'Back to List' ?>
            </a>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chapisha (Print)' : 'Print Report' ?>
            </button>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- INFO CARDS -->
    <div class="col-md-3">
        <div class="card border shadow-sm h-100" style="background-color: #d1e7dd !important; color: #000000 !important;">
            <div class="card-body p-3 text-center">
                <small class="text-uppercase fw-bold small mb-1" style="color: #495057;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla Aliyolipa' : 'Total Paid' ?></small>
                <div class="fs-4 fw-bold">TZS <?= number_format($total_paid, 0) ?></div>
                <div class="mt-2 small px-2 py-1 rounded-pill bg-white d-inline-block border text-dark">
                    <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiingilio: ' : 'Entrance: ' ?> 
                    <span class="fw-bold"><?= $entrance_status === 'paid' ? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'KIMESHAJAA' : 'FULLY PAID') : number_format($entrance_paid_amt, 0) . ' / ' . number_format($entrance_amt, 0) ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border shadow-sm h-100" style="background-color: #d1e7dd !important; color: #000000 !important;">
            <div class="card-body p-3 text-center">
                <small class="text-uppercase fw-bold small mb-1" style="color: #495057;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wategemezi Hai' : 'Active Dependants' ?></small>
                <div class="fs-4 fw-bold"><?= $dependant_count ?> Members</div>
                <small class="opacity-75"><?= $active_children ?> Children, <?= $spouse_active ?> Spouse</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border shadow-sm h-100" style="background-color: #d1e7dd !important; color: #000000 !important;">
            <div class="card-body p-3 text-center">
                <small class="text-uppercase fw-bold small mb-1" style="color: #495057;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Misaada Aliyopokea' : 'Benefits Received' ?></small>
                <div class="fs-4 fw-bold">TZS <?= number_format($total_expenses, 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border shadow-sm h-100" style="background-color: #f0f7ff !important;">
            <div class="card-body p-3 text-center">
                <small class="text-uppercase fw-bold small mb-1 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali ya Akiba' : 'Savings Status' ?></small>
                <div class="fs-4 fw-bold">
                    <?php if ($total_months_covered >= 12): ?>
                        <span class="text-success">12 + <?= ($total_months_covered - 12) ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MIEZI YA ZIADA' : 'EXTRA MONTHS' ?></span>
                    <?php else: ?>
                        <span class="text-dark"><?= $total_months_covered ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MIEZI' : 'MONTHS' ?></span>
                    <?php endif; ?>
                </div>
                <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla ya Miezi: ' : 'Total Covered: ' ?> <span class="fw-bold"><?= $total_months_covered ?></span></small>
            </div>
        </div>
    </div>
</div>

<!-- FINANCIAL GRID -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchanganuo wa Michango ya Kila Mwezi' : 'Monthly Contribution Analysis' ?></h6>
        <span class="badge bg-light text-dark border"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchango: ' : 'Monthly: ' ?> <?= number_format($monthly_amt, 0) ?> TZS</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive w-100">
            <table id="monthly-analysis-table" class="table table-bordered align-middle mb-0 text-center w-100" style="table-layout: auto;">
                <thead class="bg-light small fw-bold text-uppercase">
                    <tr>
                        <th class="py-3 bg-light" style="min-width: 150px; position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'PERIOD / MWEZI' : 'PERIOD / MONTH' ?></th>
                        <?php foreach($distribution as $d): ?>
                            <th style="min-width: 100px;"><?= $d['label'] ?></th>
                        <?php endforeach; ?>
                        <th class="bg-dark text-white" style="min-width: 120px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'JUMLA (TOTAL)' : 'TOTAL' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold bg-light text-start ps-3" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiwango Inatakiwa' : 'Amount Target' ?></td>
                        <?php foreach($distribution as $d): ?>
                            <td class="bg-light"><?= number_format($d['target'], 0) ?></td>
                        <?php endforeach; ?>
                        <?php 
                        $total_required = 0;
                        foreach($distribution as $d) { $total_required += $d['target']; }
                        ?>
                        <td class="bg-light fw-bold"><?= number_format($total_required, 0) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-start ps-3 bg-white" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi Kilicholipwa' : 'Actual Paid' ?></td>
                        <?php foreach($distribution as $d): ?>
                            <td class="<?= $d['status'] === 'paid' ? 'bg-success text-white' : ($d['status'] === 'partial' ? 'bg-warning text-dark' : 'bg-danger text-white border-danger border-opacity-25') ?>">
                                <?= number_format($d['amount'], 0) ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="fw-bold fs-5 bg-dark text-white border-0"><?= number_format($total_paid - $entrance_paid_amt, 0) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold text-start ps-3 bg-white" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Baki (Balance)' : 'Balance' ?></td>
                        <?php foreach($distribution as $d): ?>
                            <td class="small text-muted font-monospace">
                                <?= number_format(max(0, $d['target'] - $d['amount']), 0) ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="small text-muted bg-light">—</td>
                    </tr>
                </tbody>
                <!-- 2. TABLE SPACER (<tfoot>) -->
                <tfoot class="d-none d-print-table-footer">
                    <tr><td colspan="<?= count($distribution) + 2 ?>" style="height: 2.2cm; border: none !important;">&nbsp;</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white py-3 small d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex align-items-center"><span class="badge bg-success me-1">&nbsp;</span> Fully Paid</div>
        <div class="d-flex align-items-center"><span class="badge bg-warning me-1">&nbsp;</span> Partial Payment</div>
        <div class="d-flex align-items-center"><span class="badge bg-danger me-1">&nbsp;</span> Not Paid</div>
        <div class="ms-auto text-muted font-italic">Starting from: <?= date('d M Y', strtotime($contribution_start_date)) ?></div>
    </div>
</div>

<!-- EXPENSES SECTION -->
<?php if (!empty($expenses)): ?>
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
    <div class="card-header bg-primary text-white py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-heart-break me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Historia ya Misaada ya Misiba' : 'Death Benefit History' ?></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive d-none d-md-block d-print-block">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small">
                    <tr>
                        <th class="ps-4">DATE</th>
                        <th>NAME OF DECEASED</th>
                        <th>TYPE</th>
                        <th class="text-end pe-4">AMOUNT DISBURSED</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($expenses as $ex): ?>
                    <tr>
                        <td class="ps-4 small text-muted"><?= date('d/m/Y', strtotime($ex['expense_date'])) ?></td>
                        <td><div class="fw-bold"><?= htmlspecialchars($ex['deceased_name']) ?></div></td>
                        <td><span class="badge bg-light text-dark border px-2"><?= ucfirst($ex['deceased_type']) ?></span></td>
                        <td class="text-end pe-4 fw-bold text-danger">- <?= number_format($ex['amount'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- ═══ CARD VIEW — Mobile Only ═══ -->
        <?php $_ms_sw = (($_SESSION['preferred_language'] ?? 'en') === 'sw'); ?>
        <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="deathBenefitCardsWrapper">
            <?php foreach ($expenses as $ex):
                $db_avatar = strtoupper(substr($ex['deceased_name'] ?? 'D', 0, 1));
            ?>
            <div class="vk-member-card">
                <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="vk-card-avatar" style="background:linear-gradient(135deg,#dc3545,#b02a37);"><?= $db_avatar ?></div>
                        <div class="fw-bold text-dark" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($ex['deceased_name']) ?></div>
                    </div>
                    <span class="badge bg-light text-dark border px-2" style="font-size:10px;"><?= ucfirst($ex['deceased_type'] ?? '—') ?></span>
                </div>
                <div class="vk-card-body">
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_ms_sw ? 'Tarehe' : 'Date' ?></span>
                        <span class="vk-card-value"><?= date('d/m/Y', strtotime($ex['expense_date'])) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_ms_sw ? 'Kiasi' : 'Amount' ?></span>
                        <span class="vk-card-value fw-bold text-danger">- TZS <?= number_format($ex['amount'], 0) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    /* Hide UI elements */
    .header-wrapper, .navbar, .top-header, .bottom-header, .d-print-none, .no-print, .btn, footer, .modal {
        display: none !important;
    }
    
    body { padding-top: 0 !important; margin: 0 !important; background: white !important; font-size: 10px; color: black !important; }
    .container-fluid, .container { width: 100% !important; max-width: none !important; padding: 0 15px !important; margin: 0 !important; }
    
    /* Footer Positioning */
    .print-footer {
        position: fixed; bottom: 0.8cm; left: 0; right: 0; width: 100%;
        background: white !important; font-size: 10px; z-index: 9999;
        text-align: center; padding-top: 15px; border-top: 1px solid #dee2e6;
    }

    /* Safety Zone Logic */
    .d-print-table-footer { display: table-footer-group !important; }
    @page { margin: 1cm 1cm 2cm 1cm; }

    /* Layout Flexibility */
    .card { border: 1px solid #ccc !important; box-shadow: none !important; margin-bottom: 5px !important; page-break-inside: avoid; background: transparent !important; }
    .row { display: flex !important; flex-wrap: wrap !important; }
    .col-md-3 { flex: 1 1 23% !important; width: 23% !important; }
    
    /* Default Table Optimization */
    table { 
        width: 100% !important; 
        border-collapse: collapse !important; 
    }
    tr { page-break-inside: avoid; page-break-after: auto; }
    .table th, .table td { 
        padding: 5px 4px !important; 
        border: 1px solid #ccc !important; 
        -webkit-print-color-adjust: exact; 
        font-size: 10px !important; /* Larger font for standard tables */
        white-space: normal !important; 
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
    }

    /* SPECIFIC Optimization for the dense Monthly Analysis Table (12+ columns) */
    #monthly-analysis-table {
        table-layout: fixed !important;
        width: 100% !important;
    }
    #monthly-analysis-table th, 
    #monthly-analysis-table td {
        font-size: 7.5px !important; /* Smaller font ONLY for the wide 12-month table */
        padding: 3px 1px !important;
    }
    
    /* Headers specific adjustment */
    .table thead th {
        line-height: 1.1 !important;
    }
    
    .bg-light { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; }
    .bg-success { background-color: #d1e7dd !important; color: #000 !important; -webkit-print-color-adjust: exact; }
    .bg-warning { background-color: #fff3cd !important; color: #000 !important; -webkit-print-color-adjust: exact; }
    .bg-danger { background-color: #f8d7da !important; color: #000 !important; -webkit-print-color-adjust: exact; }
    .bg-primary { background-color: #cfe2ff !important; color: #000 !important; -webkit-print-color-adjust: exact; }
    .bg-dark { background-color: #e2e3e5 !important; color: #000 !important; -webkit-print-color-adjust: exact; }
    
    .table-responsive { overflow: visible !important; width: 100% !important; }
    th, td, .fw-bold { position: static !important; }
}

/* 4. PRINT FOOTER (Visible only during print) */
/* Shared print footer styles are now in helpers.php */

.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
th { font-size: 0.65rem; letter-spacing: 0.02em; font-weight: 800; }
.card { border-radius: 12px; }
@media (min-width: 992px) {
    #monthly-analysis-table { table-layout: fixed; }
}
</style>

<!-- 4. PRINT FOOTER (Visible only during print) -->
<?= getPrintFooter() ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
