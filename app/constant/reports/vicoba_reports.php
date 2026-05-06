<?php
ob_start();
require_once HEADER_FILE;

// Check permission properly using the new RBAC system
if (!canView('vicoba_reports')) {
    $lang = $_SESSION['preferred_language'] ?? 'en';
    $title = ($lang === 'sw') ? 'Ufikiaji Umekataliwa' : 'Access Denied';
    $msg = ($lang === 'sw') ? 'Samahani, huna ruhusa ya kuona ripoti hii.' : 'Sorry, you do not have permission to view this report.';
    $btn = ($lang === 'sw') ? 'Rudi Dashboard' : 'Back to Dashboard';
    $url = getUrl('dashboard');

    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'error',
            title: '$title',
            text: '$msg',
            confirmButtonColor: '#0d6efd',
            confirmButtonText: '$btn',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '$url';
            }
        });
    });
    </script>
    <div class='container py-5 text-center' style='min-height: 70vh; display: flex; align-items: center; justify-content: center;'>
        <div>
            <div class='display-1 text-danger opacity-25 mb-4'><i class='bi bi-shield-lock'></i></div>
            <h3 class='text-muted'>$title</h3>
            <p class='text-muted'>$msg</p>
            <a href='$url' class='btn btn-primary mt-3'>$btn</a>
        </div>
    </div>";
    require_once FOOTER_FILE;
    exit();
}

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// ============================================================
// FETCH ALL DATA FOR REPORTS
// ============================================================

// 1) Member Savings Summary (Grouped by Member)
$savings_data = $pdo->query("
    SELECT
        c.customer_id,
        COALESCE(CONCAT(c.first_name,' ',c.last_name), c.first_name, c.last_name, 'Mwanachama') AS member_name,
        c.phone,
        COALESCE(SUM(CASE WHEN co.contribution_type='entrance'  AND co.status='confirmed' THEN co.amount ELSE 0 END),0) AS entrance,
        COALESCE(SUM(CASE WHEN co.contribution_type='monthly'   AND co.status='confirmed' THEN co.amount ELSE 0 END),0) AS monthly,
        COALESCE(SUM(CASE WHEN co.contribution_type='agm'       AND co.status='confirmed' THEN co.amount ELSE 0 END),0) AS agm,
        COALESCE(SUM(CASE WHEN co.contribution_type='other'     AND co.status='confirmed' THEN co.amount ELSE 0 END),0) AS other,
        COALESCE(SUM(CASE WHEN co.status='confirmed'            THEN co.amount ELSE 0 END),0) AS total_savings
    FROM customers c
    LEFT JOIN contributions co ON c.customer_id = co.member_id AND co.contribution_type != 'fine'
    WHERE c.status = 'active'
    GROUP BY c.customer_id, c.first_name, c.last_name, c.phone
    ORDER BY total_savings DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 2) Expenses (General + Death Assistance)
$expenses_data = $pdo->query("
    (SELECT 'exp' as type, e.id as id, e.expense_date as date, e.amount, e.description, e.status
     FROM general_expenses e WHERE e.status='approved')
    UNION ALL
    (SELECT 'death' as type, d.id as id, d.expense_date as date, d.amount, d.description, d.status
     FROM death_expenses d WHERE d.status='approved')
    ORDER BY date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_savings   = array_sum(array_column($savings_data, 'total_savings'));
$total_expenses  = array_sum(array_column($expenses_data, 'amount'));
$available_fund  = $total_savings - $total_expenses;
$active_members  = count($savings_data);

// Chart data for savings (Top 10)
$chart_labels = array_map(fn($m) => explode(' ', $m['member_name'] ?? 'Mwanachama')[0], array_slice($savings_data, 0, 10));
$chart_values = array_map(fn($m) => round($m['total_savings']), array_slice($savings_data, 0, 10));
?>

<div class="container-fluid py-4 no-print-bg">
    <!-- Page Header (Action Bar) -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-file-earmark-bar-graph me-2"></i> <?= $is_sw ? 'Ripoti za Kikundi' : 'Group Reports' ?></h4>
            <div class="text-muted small"><?= $is_sw ? 'Tathmini ya hali ya kifedha na michango' : 'Financial status and contributions overview' ?></div>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm border-0 d-flex align-items-center">
                <i class="bi bi-printer-fill me-2"></i> <?= $is_sw ? 'Chapa Ripoti' : 'Print Report' ?>
            </button>
        </div>
    </div>

    <!-- Print-Only Header -->
    <div class="d-none d-print-block mb-5">
        <div class="text-center py-4 border-bottom border-primary border-4 mb-4">
            <h2 class="fw-bold mb-1"><?= $is_sw ? 'RIPOTI YA KIKUNDI' : 'GROUP FINANCIAL REPORT' ?></h2>
            <div class="text-muted small"><?= $is_sw ? 'Imetengenezwa Tarehe:' : 'Generated Date:' ?> <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-4 mb-5">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Jumla ya Akiba' : 'Total Savings' ?></div>
                    <div class="fs-4 fw-bold text-primary">TZS <?= number_format($total_savings) ?></div>
                </div>
                <div class="bg-primary" style="height: 4px;"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Matumizi Yote' : 'Total Expenses' ?></div>
                    <div class="fs-4 fw-bold text-danger">TZS <?= number_format($total_expenses) ?></div>
                </div>
                <div class="bg-danger" style="height: 4px;"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Baki (Cash)' : 'Balance (Cash)' ?></div>
                    <div class="fs-4 fw-bold text-success">TZS <?= number_format($available_fund) ?></div>
                </div>
                <div class="bg-success" style="height: 4px;"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Wanachama' : 'Active Members' ?></div>
                    <div class="fs-4 fw-bold text-info"><?= $active_members ?></div>
                </div>
                <div class="bg-info" style="height: 4px;"></div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="d-print-none mb-4">
        <ul class="nav nav-pills custom-pills p-1 bg-white shadow-sm rounded-pill d-inline-flex" id="reportsTabs">
            <li class="nav-item">
                <button class="nav-link active rounded-pill px-4 py-2" data-bs-toggle="tab" data-bs-target="#tab-savings">
                    <i class="bi bi-person-lines-fill me-2"></i> <?= $is_sw ? 'Akiba za Wanachama' : 'Member Savings' ?>
                </button>
            </li>
            <li class="nav-item ms-2">
                <button class="nav-link rounded-pill px-4 py-2" data-bs-toggle="tab" data-bs-target="#tab-expenses">
                    <i class="bi bi-cash-stack me-2"></i> <?= $is_sw ? 'Matumizi' : 'Expenses History' ?>
                </button>
            </li>
            <li class="nav-item ms-2">
                <button class="nav-link rounded-pill px-4 py-2" data-bs-toggle="tab" data-bs-target="#tab-summary text-nowrap">
                    <i class="bi bi-pie-chart-fill me-2"></i> <?= $is_sw ? 'Mchanganuo' : 'Asset Summary' ?>
                </button>
            </li>
        </ul>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="tab-content" id="reportContentArea">

        <!-- SAVINGS TAB -->
        <div class="tab-pane fade show active d-print-block" id="tab-savings">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary"><?= $is_sw ? 'Akiba na Michango Kila Mwanachama' : 'Member Contributions & Savings' ?></h6>
                    <span class="badge bg-primary rounded-pill px-3"><?= $active_members ?> <?= $is_sw ? 'Wanachama' : 'Members' ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block d-print-block">
                        <table class="table table-hover align-middle mb-0" id="savingsReportTable">
                            <thead class="bg-light text-uppercase small text-muted">
                                <tr>
                                    <th class="ps-4">No.</th>
                                    <th><?= $is_sw ? 'Mwanachama' : 'Member Name' ?></th>
                                    <th class="text-end pe-4 fw-bold"><?= $is_sw ? 'JUMLA YOTE YA MICHANGO' : 'TOTAL CONTRIBUTIONS' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($savings_data as $i => $row): ?>
                                <tr>
                                    <td class="ps-4 small text-muted"><?= $i+1 ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($row['member_name']) ?></div>
                                        <small class="text-muted"><?= $row['phone'] ?></small>
                                    </td>
                                    <td class="text-end pe-4 fw-bold text-primary">TZS <?= number_format($row['total_savings']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-primary bg-opacity-10 fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end ps-4 py-3 border-0"><?= $is_sw ? 'JUMLA YA MICHANGO YOTE:' : 'TOTAL ACCUMULATED CONTRIBUTIONS:' ?></td>
                                    <td class="text-end pe-4 py-3 text-primary border-0">TZS <?= number_format($total_savings) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <!-- ═══ CARD VIEW — Mobile Only ═══ -->
                    <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="savingsCardsWrapper">
                        <?php foreach ($savings_data as $row):
                            $sv_avatar = strtoupper(substr($row['member_name'] ?? 'M', 0, 1));
                        ?>
                        <div class="vk-member-card">
                            <div class="vk-card-header d-flex align-items-center gap-2">
                                <div class="vk-card-avatar" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);"><?= $sv_avatar ?></div>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="fw-bold text-dark lh-sm" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($row['member_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($row['phone'] ?? '—') ?></small>
                                </div>
                            </div>
                            <div class="vk-card-body">
                                <div class="vk-card-row">
                                    <span class="vk-card-label"><?= $is_sw ? 'Jumla Akiba' : 'Total Savings' ?></span>
                                    <span class="vk-card-value fw-bold text-primary">TZS <?= number_format($row['total_savings']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- EXPENSES TAB -->
        <div class="tab-pane fade d-print-block mt-print-5" id="tab-expenses">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold text-danger"><?= $is_sw ? 'Historia ya Matumizi Endelevu' : 'Cumulative Expenses History' ?></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block d-print-block">
                        <table class="table table-hover align-middle mb-0" id="expensesReportDetailTable">
                            <thead class="bg-light text-uppercase small text-muted">
                                <tr>
                                    <th class="ps-4">S/NO</th>
                                    <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                                    <th><?= $is_sw ? 'Aina' : 'Type' ?></th>
                                    <th><?= $is_sw ? 'Maelezo' : 'Note' ?></th>
                                    <th class="text-end pe-4"><?= $is_sw ? 'Kiasi' : 'Amount' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses_data as $idx => $exp):
                                    $exp_type = $exp['type'] === 'death' ? ($is_sw ? 'Msaada wa Msiba' : 'Funeral Aid') : ($is_sw ? 'Matumizi Kawaida' : 'General');
                                    $exp_class = 'bg-primary';
                                ?>
                                <tr>
                                    <td class="ps-4 small text-muted"><?= $idx+1 ?></td>
                                    <td class="small text-muted"><?= date('d/m/Y', strtotime($exp['date'])) ?></td>
                                    <td><span class="badge rounded-pill <?= $exp_class ?> px-3"><?= $exp_type ?></span></td>
                                    <td class="text-wrap" style="max-width: 300px;"><?= htmlspecialchars($exp['description']) ?></td>
                                    <td class="text-end pe-4 fw-bold">TZS <?= number_format($exp['amount']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($expenses_data)): ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted"><?= $is_sw ? 'Hakuna matumizi bado' : 'No expenses recorded' ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-primary bg-opacity-10 fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end ps-4 py-3 border-0"><?= $is_sw ? 'JUMLA KUU YA MATUMIZI:' : 'CUMULATIVE TOTAL EXPENSES:' ?></td>
                                    <td class="text-end pe-4 py-3 text-primary border-0">TZS <?= number_format($total_expenses) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <!-- ═══ CARD VIEW — Mobile Only ═══ -->
                    <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="expensesCardsWrapper">
                        <?php if (empty($expenses_data)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-cash-stack fs-1 d-block mb-3"></i>
                            <p><?= $is_sw ? 'Hakuna matumizi bado' : 'No expenses recorded' ?></p>
                        </div>
                        <?php else: foreach ($expenses_data as $exp):
                            $exp_type_lbl  = $exp['type'] === 'death' ? ($is_sw ? 'Msaada wa Msiba' : 'Funeral Aid') : ($is_sw ? 'Matumizi Kawaida' : 'General');
                            $exp_avatar    = $exp['type'] === 'death' ? 'F' : 'G';
                            $exp_av_color  = $exp['type'] === 'death'
                                ? 'linear-gradient(135deg,#dc3545,#b02a37)'
                                : 'linear-gradient(135deg,#6f42c1,#5a32a3)';
                        ?>
                        <div class="vk-member-card">
                            <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="vk-card-avatar" style="background:<?= $exp_av_color ?>;"><?= $exp_avatar ?></div>
                                    <div class="fw-bold text-dark" style="font-size:13px;"><?= $exp_type_lbl ?></div>
                                </div>
                                <small class="text-muted"><?= date('d/m/Y', strtotime($exp['date'])) ?></small>
                            </div>
                            <div class="vk-card-body">
                                <div class="vk-card-row">
                                    <span class="vk-card-label"><?= $is_sw ? 'Maelezo' : 'Note' ?></span>
                                    <span class="vk-card-value"><?= htmlspecialchars($exp['description'] ?? '—') ?></span>
                                </div>
                                <div class="vk-card-row">
                                    <span class="vk-card-label"><?= $is_sw ? 'Kiasi' : 'Amount' ?></span>
                                    <span class="vk-card-value fw-bold text-danger">TZS <?= number_format($exp['amount']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- SUMMARY TAB -->
        <div class="tab-pane fade d-print-block mt-print-5" id="tab-summary">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h6 class="mb-0 fw-bold text-success"><i class="bi bi-wallet-fill me-2"></i> <?= $is_sw ? 'Muhtasari wa Makusanyo' : 'Income Breakdown' ?></h6>
                        </div>
                        <div class="card-body p-4">
                            <!-- Pie chart placeholder if we want it here too, but graph is at bottom as per user req -->
                            <div class="d-flex justify-content-between mb-3 pb-2 border-bottom">
                                <span class="text-muted"><?= $is_sw ? 'Michango ya Wanachama' : 'Member Savings' ?></span>
                                <span class="fw-bold">TZS <?= number_format($total_savings) ?></span>
                            </div>
                            <div class="text-muted small mt-4">
                                <i class="bi bi-info-circle me-1"></i> <?= $is_sw ? 'Ripoti imejikita kwenye michango iliyothibitishwa pekee.' : 'Report considers confirmed contributions only.' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-shield-check me-2"></i> <?= $is_sw ? 'Hali ya Sasa ya Fedha' : 'Current Liquidity Status' ?></h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="display-6 fw-bold text-primary mb-2">TZS <?= number_format($available_fund) ?></div>
                            <div class="text-muted mb-4"><?= $is_sw ? 'Baki inayopatikana mfukoni/benki' : 'Available cash balance in bank/hand' ?></div>
                            <div class="progress rounded-pill mb-2" style="height: 10px;">
                                <?php $perc = $total_savings > 0 ? ($available_fund / $total_savings) * 100 : 0; ?>
                                <div class="progress-bar bg-primary" style="width: <?= $perc ?>%"></div>
                            </div>
                            <small class="text-muted"><?= round($perc, 1) ?>% <?= $is_sw ? 'ya akiba inapatikana' : 'of savings available' ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- tab-content end -->

    <!-- CHARTS SECTION (At the bottom as requested) -->
    <div class="row g-4 mt-5 pt-3 d-print-none">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-graph-up-arrow me-2"></i> <?= $is_sw ? 'Mwelekeo wa Michango (Wanachama 10 we kwanza)' : 'Top 10 Savers Growth' ?></h6>
                </div>
                <div class="card-body">
                    <canvas id="savingsComparisonChart" height="150"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold text-info"><i class="bi bi-pie-chart-fill me-2"></i> <?= $is_sw ? 'Uwiano wa Fedha' : 'Asset Allocation' ?></h6>
                </div>
                <div class="card-body d-flex align-items-center">
                    <canvas id="fundAllocationChart"></canvas>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    :root { --primary-blue: #0d6efd; }
    .custom-pills .nav-link { color: #6c757d; font-weight: 500; border: 1px solid transparent; }
    .custom-pills .nav-link.active { background-color: var(--primary-blue); color: white; border-color: var(--primary-blue); }
    .tracking-wider { letter-spacing: 0.05em; }
    
    @media print {
        body { background: white !important; font-size: 11px; color: #000 !important; }
        .no-print-bg, .container-fluid { background: white !important; padding: 0 !important; margin: 0 !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; max-width: 50% !important; }
        .card { border: 1px solid #eee !important; box-shadow: none !important; margin-bottom: 20px !important; page-break-inside: avoid; }
        .card-header { background-color: #f8f9fa !important; border-bottom: 2px solid #0d6efd !important; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table thead th { background-color: #f8f9fa !important; color: #0d6efd !important; border-bottom: 2px solid #0d6efd !important; text-transform: uppercase; font-size: 10px; }
        .table td, .table th { padding: 8px 12px !important; border-bottom: 1px solid #eee !important; }
        .badge { border: 1px solid #0d6efd !important; color: #0d6efd !important; background: transparent !important; border-radius: 4px !important; text-transform: uppercase; font-size: 9px; }
        
        /* Show all tabs in print */
        .tab-content > .tab-pane { display: block !important; opacity: 1 !important; visibility: visible !important; position: static !important; }
        .d-print-block { display: block !important; }
        
        /* Hide UI clutter */
        .d-print-none, .nav-pills, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate, .btn { display: none !important; }
        
        /* Page break management */
        .mt-print-5 { margin-top: 50px !important; }
        h4, h6 { color: #0d6efd !important; }
        .text-primary { color: #0d6efd !important; }
        
        /* Charts in print */
        canvas { max-width: 100% !important; height: auto !important; }
    }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(document).ready(function() {
    // Shared DataTables Lang
    const lang = { 
        search: '<?= $is_sw ? "Tafuta:" : "Search:" ?>', 
        lengthMenu: '<?= $is_sw ? "Onyesha _MENU_" : "Show _MENU_" ?>', 
        info: '<?= $is_sw ? "_START_-_END_ ya _TOTAL_" : "_START_-_END_ of _TOTAL_" ?>', 
        paginate: { previous: '<?= $is_sw ? "Nyuma" : "Previous" ?>', next: '<?= $is_sw ? "Mbele" : "Next" ?>' },
        zeroRecords: '<?= $is_sw ? "Hakuna data inayolingana" : "No matching records found" ?>'
    };

    $('#savingsReportTable').DataTable({ language: lang, order:[[2,'desc']], pageLength: 25 });
    $('#expensesReportDetailTable').DataTable({ language: lang, order:[[1,'desc']], pageLength: 25 });

    // Bar Chart
    const barCtx = document.getElementById('savingsComparisonChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: '<?= $is_sw ? "Akiba (TZS)" : "Savings (TZS)" ?>',
                    data: <?= json_encode($chart_values) ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderColor: '#0d6efd',
                    borderWidth: 2, borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] }, ticks: { callback: v => 'TZS ' + v.toLocaleString() } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Pie Chart
    const pieCtx = document.getElementById('fundAllocationChart');
    if (pieCtx) {
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: ['<?= $is_sw ? "Inapatikana" : "Available" ?>', '<?= $is_sw ? "Yaliyotumika" : "Expenses" ?>'],
                datasets: [{
                    data: [<?= $available_fund ?>, <?= $total_expenses ?>],
                    backgroundColor: ['#198754', '#dc3545'],
                    borderWidth: 0, hoverOffset: 15
                }]
            },
            options: {
                responsive: true, cutout: '75%',
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } }
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
