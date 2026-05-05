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
    (SELECT 'General' as category, expense_date as date, amount, description FROM general_expenses WHERE status='approved')
    UNION ALL
    (SELECT 'Death Assistance' as category, expense_date as date, amount, description FROM death_expenses WHERE status='approved')
    ORDER BY date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Totals
$total_general = $pdo->query("SELECT SUM(amount) FROM general_expenses WHERE status='approved'")->fetchColumn() ?: 0;
$total_death   = $pdo->query("SELECT SUM(amount) FROM death_expenses WHERE status='approved'")->fetchColumn() ?: 0;
$total_overall = $total_general + $total_death;
$total_records = count($expenses_data);

// Trend data (Last 6 Months combined)
$trend_query = "
    SELECT DATE_FORMAT(date, '%Y-%m') as month, SUM(amount) as total
    FROM (
        SELECT expense_date as date, amount FROM general_expenses WHERE status='approved'
        UNION ALL
        SELECT expense_date as date, amount FROM death_expenses WHERE status='approved'
    ) combined
    GROUP BY month
    ORDER BY month ASC
    LIMIT 6
";
$trend_data = $pdo->query($trend_query)->fetchAll(PDO::FETCH_ASSOC);
$trend_labels = array_column($trend_data, 'month');
$trend_values = array_column($trend_data, 'total');

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

    <!-- Print-Only Header -->
    <div class="d-none d-print-block mb-4">
        <div class="text-center py-4 border-bottom border-primary border-4 mb-4">
            <h2 class="fw-bold mb-1"><?= $is_sw ? 'RIPOTI YA JUMUISHI YA MATUMIZI' : 'CONSOLIDATED EXPENSE REPORT' ?></h2>
            <div class="text-muted small"><?= $is_sw ? 'Imetengenezwa Tarehe:' : 'Generated Date:' ?> <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-primary">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Jumla ya Matumizi' : 'Total Expenditures' ?></div>
                    <div class="fs-4 fw-bold text-primary">TZS <?= number_format($total_overall) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-info">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Matumizi Kawaida' : 'General Expenses' ?></div>
                    <div class="fs-4 fw-bold text-info">TZS <?= number_format($total_general) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-danger">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Msaada wa Misiba' : 'Funeral Assistance' ?></div>
                    <div class="fs-4 fw-bold text-danger">TZS <?= number_format($total_death) ?></div>
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
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="expenseDetailTable">
                            <thead class="bg-light text-uppercase small text-muted">
                                <tr>
                                    <th class="ps-4">S/NO</th>
                                    <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                                    <th><?= $is_sw ? 'Aina' : 'Category' ?></th>
                                    <th><?= $is_sw ? 'Maelezo' : 'Note' ?></th>
                                    <th class="text-end pe-4"><?= $is_sw ? 'Kiasi (TZS)' : 'Amount (TZS)' ?></th>
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
                                    <td class="text-end pe-4 fw-bold">TZS <?= number_format($exp['amount']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    <canvas id="propChart" height="250"></canvas>
                    <div class="mt-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="small text-muted"><?= $is_sw ? 'Ya Kawaida :' : 'General :' ?></span>
                            <span class="small fw-bold"><?= round(($total_general / max($total_overall, 1)) * 100) ?>%</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="small text-muted"><?= $is_sw ? 'Ya Misiba :' : 'Funeral Aid :' ?></span>
                            <span class="small fw-bold"><?= round(($total_death / max($total_overall, 1)) * 100) ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    @media print {
        body { background: white !important; font-size: 11px; }
        .card { border: 1px solid #eee !important; box-shadow: none !important; margin-bottom: 20px !important; page-break-inside: avoid; }
        .nav-pills, .btn, .d-print-none, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate { display: none !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
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
        zeroRecords: '<?= $is_sw ? "Hakuna matumizi" : "No expenses found" ?>'
    };
    $('#expenseDetailTable').DataTable({ language: lang, pageLength: 15, order:[[1,'desc']] });

    // Proportion Chart
    const propCtx = document.getElementById('propChart');
    if (propCtx) {
        new Chart(propCtx, {
            type: 'doughnut',
            data: {
                labels: ['<?= $is_sw ? "Kawaida" : "General" ?>', '<?= $is_sw ? "Vifo" : "Death" ?>'],
                datasets: [{
                    data: [<?= $total_general ?>, <?= $total_death ?>],
                    backgroundColor: ['#0dcaf0', '#dc3545'],
                    borderWidth: 0, cutout: '70%'
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 10 } } } } }
        });
    }

});
</script>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
