<?php
// File: app/dashboard.php
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}

$user_id = $_SESSION['user_id'];
$viongozi_roles = ['admin', 'secretary', 'katibu', 'chairman', 'mwenyekiti', 'mhazini', 'treasurer'];
$is_viongozi = in_array($user_role_lower, $viongozi_roles);

// ── ROLES PERMISSIONS FOR DASHBOARD UI ──────────────────────────────────────
$can_manage_members = canView('customers');
$can_view_audit     = canView('audit_logs');
$can_manage_fin     = canView('manage_contributions');
$can_view_library   = canView('library');
$can_view_reports   = canView('vicoba_reports');

// ── Fetch key metrics (NOW PUBLIC FOR ALL ROLES) ──────────────
$total_members = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status != 'deleted' AND user_role != 'Admin'")->fetchColumn();
$active_members = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND user_role != 'Admin'")->fetchColumn();
$pending_members = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$total_contributions = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status = 'confirmed'")->fetchColumn();
$month_contributions = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND MONTH(contribution_date)=MONTH(NOW()) AND YEAR(contribution_date)=YEAR(NOW())")->fetchColumn();
$pending_contributions_global = (int) $pdo->query("SELECT COUNT(*) FROM contributions c JOIN customers cust ON c.member_id = cust.customer_id WHERE c.status = 'pending' AND cust.status = 'active'")->fetchColumn();

// Specific pending for current user logic
$stmt = $pdo->prepare("SELECT COUNT(*) FROM contributions c JOIN customers cust ON c.member_id = cust.customer_id WHERE cust.user_id = ? AND c.status = 'pending'");
$stmt->execute([$user_id]); 
$my_pending_contributions = (int) $stmt->fetchColumn();
$pending_contributions = $is_viongozi ? $pending_contributions_global : $my_pending_contributions;

$total_pending_fines = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'pending'")->fetchColumn();

// ── Pending Expenses (General & Death) ───────────────────────────────────
$pending_death_expenses = (int) $pdo->query("SELECT COUNT(*) FROM death_expenses WHERE status = 'pending'")->fetchColumn();
$pending_general_expenses = (int) $pdo->query("SELECT COUNT(*) FROM expenses WHERE status = 'pending'")->fetchColumn();
$pending_budgets = (int) $pdo->query("SELECT COUNT(*) FROM budgets WHERE status = 'pending'")->fetchColumn();

// ── Death Expenses & Net Balance ──────────────────────────────────────────
$total_death_expenses = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM death_expenses")->fetchColumn();
$total_other_expenses = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='paid'")->fetchColumn();
$total_all_expenses = $total_death_expenses + $total_other_expenses;
$net_balance = $total_contributions - $total_all_expenses;

// ── My own stats if Member ───────────────────────────────────────────────
$my_total_contributions = 0; $my_pending_contributions = 0;
if (!$is_viongozi) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(c.amount),0) FROM contributions c JOIN customers cu ON c.member_id=cu.customer_id WHERE cu.user_id=? AND c.status='confirmed'");
    $stmt->execute([$user_id]); $my_total_contributions = (float) $stmt->fetchColumn();
}

// ── Monthly contributions trend (last 6 months) ───────────────────────────
$months_labels = []; $months_data = [];
for ($i = 5; $i >= 0; $i--) {
    $months_labels[] = date('M Y', strtotime("-$i months"));
    $y = date('Y', strtotime("-$i months")); $m = date('m', strtotime("-$i months"));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND YEAR(contribution_date)=? AND MONTH(contribution_date)=?");
    $stmt->execute([$y, $m]); $months_data[] = (float) $stmt->fetchColumn();
}

// ── AUDIT LOGS - REAL DATA & TIME ──────────────────────────────────────────
$activity_logs = [];
if (in_array($user_role_lower, ['admin', 'chairman', 'mwenyekiti'])) {
    try {
        $stmt = $pdo->query("
            SELECT al.*, 
                CONCAT(u.first_name, ' ', u.last_name) as full_name,
                u.user_role as role,
                TIMESTAMPDIFF(SECOND, al.created_at, NOW()) as secs_ago,
                TIMESTAMPDIFF(MINUTE, al.created_at, NOW()) as mins_ago,
                TIMESTAMPDIFF(HOUR, al.created_at, NOW()) as hrs_ago
            FROM activity_logs al 
            JOIN users u ON al.user_id = u.user_id 
            ORDER BY al.created_at DESC 
            LIMIT 10
        ");
        $activity_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $activity_logs = []; }
}

$lang = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');

function fmt_currency($n) { return 'TZS ' . number_format($n, 0); }

function fmt_time_ago($log, $is_sw) {
    if ($log['hrs_ago'] > 23) return date('d/m/Y', strtotime($log['created_at']));
    if ($log['hrs_ago'] > 0) return $log['hrs_ago'] . ($is_sw ? 'h iliyopita' : 'h ago');
    if ($log['mins_ago'] > 0) return $log['mins_ago'] . ($is_sw ? 'm iliyopita' : 'm ago');
    return ($is_sw ? 'sasa hivi' : 'just now');
}

$sw_months = [1=>'Januari', 2=>'Februari', 3=>'Machi', 4=>'Aprili', 5=>'Mei', 6=>'Juni', 7=>'Julai', 8=>'Agosti', 9=>'Septemba', 10=>'Oktoba', 11=>'Novemba', 12=>'Desemba'];
$en_months = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
$display_month = $is_sw ? $sw_months[date('n')] : $en_months[date('n')];
?>

<div class="vk-dashboard">
    <!-- ── ALERT BANNER ─────────────────── -->
    <?php 
    if ($is_viongozi) {
        $total_pending = ($can_manage_members ? $pending_members : 0) + $pending_contributions + $pending_death_expenses + $pending_general_expenses + $pending_budgets;
    } else {
        $total_pending = $pending_contributions;
    }
    if ($total_pending > 0): 
    ?>
    <div class="vk-alert-strip shadow-sm">
        <div class="px-3 px-md-4 py-2 d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill text-warning fs-5"></i>
                <strong class="text-white"><?= $is_sw ? 'Hatua Inahitajika:' : 'Action Required:' ?></strong>
                <span class="badge bg-danger rounded-pill px-3"><?= $total_pending ?></span>
            </div>

            <button id="btnToggleAlerts" class="btn btn-sm btn-outline-light py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#alertDetails" style="font-size: 0.75rem;">
                <i class="bi bi-eye me-1"></i> <?= $is_sw ? 'Angalia Maelezo' : 'View Details' ?>
            </button>

            <div class="collapse flex-grow-1" id="alertDetails">
                <div class="d-flex flex-wrap gap-2 py-1">
                    <?php if ($can_manage_members && $pending_members > 0): ?>
                    <a href="<?= getUrl('member_approvals') ?>" class="vk-alert-chip">
                        <i class="bi bi-person-check"></i> <?= $pending_members ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama Wapya' : 'New Members' ?>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($pending_contributions > 0): ?>
                    <a href="<?= getUrl('manage_contributions') ?>" class="vk-alert-chip vk-chip-yellow">
                        <i class="bi bi-cash-coin"></i> <?= $pending_contributions ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango Inayosubiri' : 'Pending Contributions' ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($is_viongozi && $pending_death_expenses > 0): ?>
                    <a href="<?= getUrl('death_expenses') ?>" class="vk-alert-chip vk-chip-red">
                        <i class="bi bi-heart-pulse"></i> <?= $pending_death_expenses ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Misaada ya Misiba' : 'Funeral Supports' ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($is_viongozi && $pending_general_expenses > 0): ?>
                    <a href="<?= getUrl('expenses') ?>" class="vk-alert-chip vk-chip-orange">
                        <i class="bi bi-cart-dash"></i> <?= $pending_general_expenses ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Matumizi Mengineyo' : 'General Expenses' ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($is_viongozi && $pending_budgets > 0): ?>
                    <a href="<?= getUrl('budget') ?>" class="vk-alert-chip vk-chip-teal">
                        <i class="bi bi-pie-chart"></i> <?= $pending_budgets ?> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Bajeti Inayosubiri' : 'Pending Budgets' ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ad = document.getElementById('alertDetails');
        const bt = document.getElementById('btnToggleAlerts');
        if (ad && bt) {
            ad.addEventListener('show.bs.collapse', () => bt.innerHTML = '<i class="bi bi-eye-slash me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ficha' : 'Hide' ?>');
            ad.addEventListener('hide.bs.collapse', () => bt.innerHTML = '<i class="bi bi-eye me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Angalia Maelezo' : 'View Details' ?>');
        }
    });
    </script>
    <?php endif; ?>

    <div class="pt-4 pb-5 px-3 px-md-4">
        <!-- ── QUICK ACTIONS ────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom"><h6 class="mb-0 fw-bold text-dark uppercase small"><i class="bi bi-lightning-charge-fill text-warning me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua za Haraka' : 'Quick Actions' ?></h6></div>
            <div class="card-body">
                <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
                    <?php if ($can_manage_members): ?>
                    <div class="col"><a href="<?= getUrl('member_approvals') ?>" class="vk-quick-btn vk-qb-blue"><i class="bi bi-person-plus"></i><span><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sajili / Idhinisha' : 'Register / Approve' ?></span></a></div>
                    <?php endif; ?>

                    <div class="col"><a href="<?= getUrl('manage_contributions') ?>" class="vk-quick-btn vk-qb-green"><i class="bi bi-piggy-bank"></i><span><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango' : 'Contributions' ?></span></a></div>

                    <?php if ($can_manage_fin): ?>
                    <div class="col"><a href="<?= getUrl('death_expenses') ?>" class="vk-quick-btn vk-qb-red"><i class="bi bi-heart-pulse"></i><span><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Misaada (Misiba)' : 'Funeral Support' ?></span></a></div>
                    <?php endif; ?>

                    <?php if ($is_viongozi): ?>
                    <div class="col"><a href="<?= getUrl('customers') ?>" class="vk-quick-btn vk-qb-purple"><i class="bi bi-people"></i><span><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Orodha ya Wanachama' : 'Members List' ?></span></a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── KPI CARDS (NOW 5 IN ONE ROW) ────────────────────────── -->
        <?php
        $curr_y = date('Y'); $curr_m = date('n');
        $b_stmt = $pdo->prepare("SELECT SUM(allocated_amount) as t_alloc FROM budgets WHERE budget_year = ? AND budget_month = ?");
        $b_stmt->execute([$curr_y, $curr_m]);
        $dash_alloc = (float)($b_stmt->fetchColumn() ?? 0);
        ?>
        <div class="row g-2 g-md-3 mb-4 row-cols-2 row-cols-md-3 row-cols-lg-5">
            <!-- 1. Members -->
            <div class="col">
                <div class="vk-kpi-card vk-kpi-blue h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="vk-kpi-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama' : 'Members' ?></div>
                        <div class="vk-kpi-val mt-1"><?= $total_members ?></div>
                    </div>
                    <div class="vk-kpi-sub mt-2"><?= $active_members ?> <?= $is_sw ? 'Wapo Hai' : 'Active' ?></div>
                </div>
            </div>
            <!-- 2. Balance -->
            <div class="col">
                <div class="vk-kpi-card vk-kpi-green h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="vk-kpi-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Salio' : 'Balance' ?></div>
                        <div class="vk-kpi-val mt-1" style="font-size: 1.1rem;"><?= fmt_currency($net_balance) ?></div>
                    </div>
                    <div class="vk-kpi-sub mt-2"><?= $is_sw ? 'Hadi sasa' : 'As of today' ?></div>
                </div>
            </div>
            <!-- 3. Expenses -->
            <div class="col">
                <div class="vk-kpi-card vk-kpi-orange h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="vk-kpi-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Matumizi' : 'Expenses' ?></div>
                        <div class="vk-kpi-val mt-1" style="font-size: 1.1rem;"><?= fmt_currency($total_all_expenses) ?></div>
                    </div>
                    <div class="vk-kpi-sub mt-2"><?= $is_sw ? 'Jumla' : 'Total' ?></div>
                </div>
            </div>
            <!-- 4. Contributions -->
            <div class="col">
                <div class="vk-kpi-card vk-kpi-purple h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="vk-kpi-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango' : 'Contributions' ?></div>
                        <div class="vk-kpi-val mt-1" style="font-size: 1.1rem;"><?= fmt_currency($total_contributions) ?></div>
                    </div>
                    <div class="vk-kpi-sub mt-2"><?= $is_sw ? 'Imethibitishwa' : 'Verified' ?></div>
                </div>
            </div>
            <!-- 5. Budget Allocated (NEW - Teal Color) -->
            <div class="col">
                <div class="vk-kpi-card h-100 d-flex flex-column justify-content-between" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                    <div>
                        <div class="vk-kpi-label text-white opacity-75"><?= $is_sw ? 'Bajeti Iliyopangwa' : 'Budget Allocated' ?></div>
                        <div class="vk-kpi-val mt-1 text-white" style="font-size: 1.1rem;"><?= fmt_currency($dash_alloc) ?></div>
                    </div>
                    <div class="vk-kpi-sub mt-2 text-white opacity-75"><?= $display_month ?></div>
                </div>
            </div>
        </div>

        <!-- ── MAIN CONTENT (Audit Logs Replaces Pending List) ────────────────── -->
        <div class="row g-4">
            <?php 
            $show_logs = in_array($user_role_lower, ['admin', 'chairman', 'mwenyekiti']); 
            $chart_col = $show_logs ? 'col-lg-7' : 'col-lg-12';
            ?>
            <!-- Chart Left -->
            <div class="<?= $chart_col ?>">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up-arrow text-primary me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwelekeo wa Michango (Miezi 6 iliyopita)' : 'Contribution Trend (Last 6 Months)' ?></h6>
                        <small class="text-muted"><?= date('Y') ?></small>
                    </div>
                    <div class="card-body"><canvas id="contributionsChart" height="200"></canvas></div>
                </div>
            </div>

            <!-- Audit Logs Right (NEW PLACEMENT) - Limited to Admin & Chairman -->
            <?php if (in_array($user_role_lower, ['admin', 'chairman', 'mwenyekiti'])): ?>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text text-danger me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kumbukumbu za Shughuli' : 'Audit Logs' ?></h6>
                        <a href="<?= getUrl('audit-logs') ?>" class="btn btn-sm btn-outline-danger px-3 rounded-pill fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tazama Vyote' : 'View All' ?></a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: .85rem;">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 border-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mtumiaji' : 'User' ?></th>
                                        <th class="border-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maelezo' : 'Description' ?></th>
                                        <th class="pe-3 text-end border-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda' : 'Time' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($activity_logs)): ?>
                                    <tr><td colspan="3" class="text-center py-5 text-muted small">No activity recorded yet.</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($activity_logs as $log): ?>
                                    <tr>
                                        <td class="ps-3 py-3">
                                            <div class="fw-bold"><?= htmlspecialchars($log['full_name'] ?? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfumo' : 'System')) ?></div>
                                            <span class="badge bg-light text-dark border-0 small px-0 text-uppercase" style="font-size: 10px;"><?= $log['role'] ?? '-' ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            // The user requested to show the full description rather than just the action type here
                                            $desc = $log['description'];
                                            $module = $log['module'];
                                            $lang = $_SESSION['preferred_language'] ?? 'en';
                                            
                                            $clean_module = $module;
                                            if ($lang === 'en') {
                                                $translations = [
                                                    'Mifumo' => 'System',
                                                    'Mwanachama' => 'Member',
                                                    'Mkopo' => 'Loan',
                                                    'Mchango' => 'Contribution',
                                                    'Faini' => 'Fine'
                                                ];
                                                $clean_module = str_replace(array_keys($translations), array_values($translations), $module);
                                            }
                                            ?>
                                            <div class="text-truncate text-dark" style="max-width: 250px;" title="<?= htmlspecialchars($desc) ?>">
                                                <?= htmlspecialchars($desc) ?>
                                            </div>
                                            <?php if (strtolower($module) !== 'navigation'): ?>
                                            <small class="text-muted text-uppercase fw-bold" style="font-size: 9px;"><?= htmlspecialchars($clean_module) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="pe-3 text-end text-muted small fw-bold"><?= fmt_time_ago($log, $is_sw) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('contributionsChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($months_labels) ?>,
        datasets: [{ label: 'Contributions (TZS)', data: <?= json_encode($months_data) ?>, backgroundColor: 'rgba(37,99,235,0.1)', borderColor: '#2563eb', borderWidth: 2, borderRadius: 5 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => 'TZS ' + v.toLocaleString() } }, x: { grid: { display: false } } }
    }
});
</script>

<style>
.vk-dashboard { background: #f8fafc; min-height: 100vh; }
.vk-alert-strip { background: #1e293b; border-bottom: 3px solid #f59e0b; }
.vk-alert-chip { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #fbbf24; border-radius: 20px; padding: 2px 12px; font-size: .8rem; text-decoration: none; }
.vk-kpi-card { border-radius: 20px; padding: 25px; color: #fff; overflow: hidden; position: relative; transition: .3s; }
.vk-kpi-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
.vk-kpi-val { font-size: 1.8rem; font-weight: 800; }
.vk-kpi-label { font-size: .8rem; opacity: .8; font-weight: 700; text-transform: uppercase; }
.vk-kpi-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.vk-kpi-green { background: linear-gradient(135deg, #10b981, #059669); }
.vk-kpi-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
.vk-kpi-purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.vk-quick-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 18px; border-radius: 12px; text-decoration: none; font-size: .85rem; font-weight: 600; background: #fff; border: 1px solid #e2e8f0; color: #475569; transition: all .3s; }
.vk-quick-btn:hover { background: #3b82f6 !important; color: #fff !important; transform: translateY(-3px); }
@media (max-width: 768px) {
    .vk-kpi-val { font-size: 1.1rem !important; }
    .vk-kpi-label { font-size: 0.7rem !important; }
    .vk-kpi-sub { font-size: 0.65rem !important; }
    .vk-kpi-card { padding: 15px !important; }
    .vk-quick-btn { padding: 12px !important; font-size: 0.75rem !important; min-height: 100px; text-align: center; }
    .vk-quick-btn i { font-size: 1.25rem !important; }
}
.vk-quick-btn i { font-size: 1.5rem; }
.vk-qb-blue { color: #2563eb; }
.vk-qb-green { color: #10b981; }
.vk-qb-purple { color: #8b5cf6; }
.vk-qb-teal { color: #14b8a6; }
.vk-qb-red { color: #ef4444; }
.vk-chip-yellow { color: #fbbf24 !important; background: rgba(245,158,11,0.1) !important; }
.vk-chip-red { color: #ef4444 !important; background: rgba(239,68,68,0.1) !important; border-color: rgba(239,68,68,0.3) !important; }
.vk-chip-orange { color: #f97316 !important; background: rgba(249,115,22,0.1) !important; border-color: rgba(249,115,22,0.3) !important; }
.vk-chip-teal { color: #14b8a6 !important; background: rgba(20,184,166,0.1) !important; border-color: rgba(20,184,166,0.3) !important; }
</style>

<?php require_once ROOT_DIR . '/footer.php'; ?>