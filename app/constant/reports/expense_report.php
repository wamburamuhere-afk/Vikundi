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
// DATA FETCHING (CONSOLIDATED EXPENSES)
// ============================================================

// All approved expenses combined
$expenses_data = $pdo->query("
    (SELECT 'General' as category, expense_date as date, amount, description FROM general_expenses WHERE status IN ('approved','paid'))
    UNION ALL
    (SELECT 'Death Assistance' as category, expense_date as date, amount, description FROM death_expenses WHERE status IN ('approved','paid'))
    ORDER BY date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Totals
$total_general = $pdo->query("SELECT SUM(amount) FROM general_expenses WHERE status IN ('approved','paid')")->fetchColumn() ?: 0;
$total_death   = $pdo->query("SELECT SUM(amount) FROM death_expenses WHERE status IN ('approved','paid')")->fetchColumn() ?: 0;
$total_overall = $total_general + $total_death;
$total_records = count($expenses_data);

// Trend data (Last 6 Months combined)
$trend_query = "
    SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(amount) as total
    FROM (
        SELECT expense_date as date, amount FROM general_expenses WHERE status IN ('approved','paid')
        UNION ALL
        SELECT expense_date as date, amount FROM death_expenses WHERE status IN ('approved','paid')
    ) combined
    GROUP BY month
    ORDER BY month ASC
    LIMIT 6
";
$trend_data = $pdo->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);
$trend_labels = array_column($trend_data, 'month');
$trend_values = array_map('floatval', array_column($trend_data, 'total'));
// 'YYYY-MM' -> short "Mon YY" for the trend chart axis
$trend_fmt_labels = array_map(fn($m) => date('M y', strtotime($m . '-01')), $trend_labels);

$pct_general = $total_overall > 0 ? round(($total_general / $total_overall) * 100) : 0;
$pct_death   = $total_overall > 0 ? round(($total_death   / $total_overall) * 100) : 0;

?>

<div class="container-fluid py-4 no-print-bg">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-coin me-2"></i> <?= $is_sw ? 'Mchanganuo wa Matumizi' : 'Expense Analysis & Summary' ?></h4>
            <div class="text-muted small"><?= $is_sw ? 'Ripoti ya kina ya matumizi yote ya kikundi' : 'Detailed report for all group expenditures' ?></div>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm border-0 d-flex align-items-center">
                <i class="bi bi-printer-fill me-2"></i> <?= $is_sw ? 'Chapa Ripoti' : 'Print Summary' ?>
            </button>
        </div>
    </div>

    <?php PrintHeader::css(); ?>
    <!-- PRINT HEADER (Visible only during print) -->
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'RIPOTI YA JUMUISHI YA MATUMIZI' : 'CONSOLIDATED EXPENSE REPORT'); ?>
    </div>



    <!-- Summary Cards -->
    <div class="row g-2 g-md-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-primary">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Jumla ya Matumizi' : 'Total Expenditures' ?></div>
                    <div class="fs-4 fw-bold text-primary">TSh <?= number_format($total_overall) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-info">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Matumizi Kawaida' : 'General Expenses' ?></div>
                    <div class="fs-4 fw-bold text-info">TSh <?= number_format($total_general) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-danger">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Msaada wa Misiba' : 'Funeral Assistance' ?></div>
                    <div class="fs-4 fw-bold text-danger">TSh <?= number_format($total_death) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-secondary">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Idadi ya Matukio' : 'Total Records' ?></div>
                    <div class="fs-4 fw-bold text-secondary"><?= number_format($total_records) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <!-- Expense Table -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Historia kamili ya Matumizi' : 'Detailed Expenditure History' ?></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block d-print-block">
                        <table class="table table-hover align-middle mb-0" id="expenseDetailTable">
                            <thead class="bg-light text-uppercase small text-muted">
                                <tr>
                                    <th class="ps-4">S/NO</th>
                                    <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                                    <th><?= $is_sw ? 'Aina' : 'Category' ?></th>
                                    <th><?= $is_sw ? 'Maelezo' : 'Note' ?></th>
                                    <th class="text-end pe-4"><?= $is_sw ? 'Kiasi (TSh)' : 'Amount (TSh)' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses_data as $idx => $exp):
                                    $cat_sw = $exp['category'] === 'General' ? 'Matumizi ya Kikundi' : 'Msaada wa Msiba';
                                    $cat_en = $exp['category'];
                                    $cat_class = $exp['category'] === 'General' ? 'bg-info' : 'bg-danger';
                                ?>
                                <tr>
                                    <td class="ps-4 small text-muted"><?= $idx + 1 ?></td>
                                    <td><?= date('d/m/Y', strtotime($exp['date'])) ?></td>
                                    <td><span class="badge rounded-pill <?= $cat_class ?> bg-opacity-10 text-dark border px-3"><?= $is_sw ? $cat_sw : $cat_en ?></span></td>
                                    <td class="text-wrap" style="max-width: 250px;"><?= htmlspecialchars($exp['description']) ?></td>
                                    <td class="text-end pe-4 fw-bold">TSh <?= number_format($exp['amount']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- ═══ CARD VIEW — Mobile Only ═══ -->
                    <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="expenseReportCardsWrapper">
                        <?php if (empty($expenses_data)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-cash-stack fs-1 d-block mb-3"></i>
                            <p><?= $is_sw ? 'Hakuna matumizi bado' : 'No expenses recorded' ?></p>
                        </div>
                        <?php else: foreach ($expenses_data as $exp):
                            $er_is_general = ($exp['category'] === 'General');
                            $er_cat_lbl    = $is_sw ? ($er_is_general ? 'Matumizi ya Kikundi' : 'Msaada wa Msiba') : $exp['category'];
                            $er_avatar     = $er_is_general ? 'G' : 'D';
                            $er_av_color   = $er_is_general
                                ? 'linear-gradient(135deg,#0dcaf0,#0aa2c0)'
                                : 'linear-gradient(135deg,#dc3545,#b02a37)';
                        ?>
                        <div class="vk-member-card">
                            <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="vk-card-avatar" style="background:<?= $er_av_color ?>;"><?= $er_avatar ?></div>
                                    <div class="fw-bold text-dark" style="font-size:13px;"><?= $er_cat_lbl ?></div>
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
                                    <span class="vk-card-value fw-bold text-danger">TSh <?= number_format($exp['amount']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Sidebar -->
        <div class="col-md-4">
            <!-- Proportion Chart -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Uwiano wa Matumizi' : 'Expenditure Proportion' ?></h6>
                </div>
                <div class="card-body">
                    <?php if ($total_overall > 0): ?>
                        <div class="position-relative mx-auto" style="height:220px;max-width:260px;">
                            <canvas id="propChart"></canvas>
                        </div>
                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small"><span class="d-inline-block rounded-circle me-2 align-middle" style="width:10px;height:10px;background:#0dcaf0;"></span><?= $is_sw ? 'Ya Kawaida' : 'General' ?></span>
                                <span class="small fw-bold text-nowrap">TSh <?= number_format($total_general) ?> <span class="text-muted fw-normal">· <?= $pct_general ?>%</span></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small"><span class="d-inline-block rounded-circle me-2 align-middle" style="width:10px;height:10px;background:#dc3545;"></span><?= $is_sw ? 'Ya Misiba' : 'Funeral Aid' ?></span>
                                <span class="small fw-bold text-nowrap">TSh <?= number_format($total_death) ?> <span class="text-muted fw-normal">· <?= $pct_death ?>%</span></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-pie-chart fs-1 d-block mb-2 opacity-50"></i>
                            <div class="small"><?= $is_sw ? 'Hakuna matumizi bado' : 'No expenses yet' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 6-Month Trend -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Miezi 6 Iliyopita' : 'Last 6 Months' ?></h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($trend_data)): ?>
                        <div class="position-relative" style="height:180px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-bar-chart fs-1 d-block mb-2 opacity-50"></i>
                            <div class="small"><?= $is_sw ? 'Hakuna data ya mwelekeo' : 'No trend data yet' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    @media print {
        body { background: white !important; font-size: 10px; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 10px !important; page-break-inside: avoid; }
        .card-body { padding: 10px !important; }
        .fs-4 { font-size: 1rem !important; }
        .d-print-none, .nav-pills, .btn, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate { display: none !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
    }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(document).ready(function() {
    // Shared DataTables Lang
    const lang = { 
        search: '<?= $is_sw ? "Tafuta:" : "Search:" ?>', 
        zeroRecords: '<?= $is_sw ? "Hakuna matumizi" : "No expenses found" ?>'
    };
    $('#expenseDetailTable').DataTable({ language: lang, pageLength: 15, order:[[1,'desc']] });

    // Compact money formatter (e.g. 1,200,000 -> "1.2M", 800,000 -> "800K")
    const fmtCompact = v => {
        if (v >= 1e6) return (v / 1e6).toFixed(v >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (v >= 1e3) return Math.round(v / 1e3) + 'K';
        return Math.round(v).toLocaleString('en-US');
    };

    // ---- Expenditure proportion doughnut (with a total in the centre) ----
    const propCtx = document.getElementById('propChart');
    if (propCtx) {
        const totalOverall = <?= (float) $total_overall ?>;
        const centreTotal = {
            id: 'centreTotal',
            afterDraw(chart) {
                const { ctx, chartArea: { left, right, top, bottom } } = chart;
                const cx = (left + right) / 2, cy = (top + bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillStyle = '#212529';
                ctx.font = "700 17px system-ui, -apple-system, sans-serif";
                ctx.fillText('TSh ' + fmtCompact(totalOverall), cx, cy - 6);
                ctx.fillStyle = '#6c757d';
                ctx.font = "600 11px system-ui, -apple-system, sans-serif";
                ctx.fillText('<?= $is_sw ? "Jumla" : "Total" ?>', cx, cy + 13);
                ctx.restore();
            }
        };
        new Chart(propCtx, {
            type: 'doughnut',
            data: {
                labels: ['<?= $is_sw ? "Ya Kawaida" : "General" ?>', '<?= $is_sw ? "Ya Misiba" : "Funeral Aid" ?>'],
                datasets: [{
                    data: [<?= (float) $total_general ?>, <?= (float) $total_death ?>],
                    backgroundColor: ['#0dcaf0', '#dc3545'],
                    borderColor: '#fff', borderWidth: 2, borderRadius: 6, spacing: 3, cutout: '68%'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label(c) {
                                const v = c.parsed || 0;
                                const pct = totalOverall ? Math.round(v / totalOverall * 100) : 0;
                                return ' ' + c.label + ': TSh ' + v.toLocaleString('en-US') + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            },
            plugins: [centreTotal]
        });
    }

    // ---- Last 6 months trend (bar) ----
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($trend_fmt_labels) ?>,
                datasets: [{
                    data: <?= json_encode($trend_values) ?>,
                    backgroundColor: '#0d6efd', borderRadius: 4, maxBarThickness: 30
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ' TSh ' + (c.parsed.y || 0).toLocaleString('en-US') } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f1f1' }, ticks: { font: { size: 10 }, callback: v => fmtCompact(v) } }
                }
            }
        });
    }

});
</script>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
