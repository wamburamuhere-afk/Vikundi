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
// DATA AGGREGATION
// ============================================================

// 1) General Stats
$total_members  = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role != 'Admin' AND status != 'deleted'")->fetchColumn();
$active_members = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role != 'Admin' AND status = 'active'")->fetchColumn();
$deceased_count = $pdo->query("SELECT COUNT(*) FROM customers WHERE is_deceased = 1")->fetchColumn() ?: 0;
$new_last_30    = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role != 'Admin' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

// 2) Regional Distribution (Top 8)
$regions_data = $pdo->query("
    SELECT state as region, COUNT(*) as count 
    FROM customers 
    GROUP BY state 
    ORDER BY count DESC 
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// 3) Growth Trend (Last 6 Months)
$growth_data = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
    FROM users 
    WHERE user_role != 'Admin' 
    GROUP BY month 
    ORDER BY month ASC 
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$months_labels = array_column($growth_data, 'month');
$months_counts = array_column($growth_data, 'count');

// 4) Latest Members (Joined with customers to track deceased/dormant state)
$latest_members = $pdo->query("
    SELECT u.first_name, u.last_name, u.created_at, u.status, c.is_deceased 
    FROM users u 
    LEFT JOIN customers c ON u.user_id = c.user_id
    WHERE u.user_role != 'Admin' AND u.status != 'deleted' 
    ORDER BY u.created_at DESC 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid py-4 no-print-bg">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i> <?= $is_sw ? 'Uchambuzi wa Wanachama' : 'Member Analysis' ?></h4>
            <div class="text-muted small"><?= $is_sw ? 'Tathmini ya ukuaji na idadi ya wanachama' : 'Member growth and demographic assessment' ?></div>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm border-0 d-flex align-items-center">
                <i class="bi bi-printer-fill me-2"></i> <?= $is_sw ? 'Chapa Ripoti' : 'Print Analysis' ?>
            </button>
        </div>
    </div>

    <!-- Print Header -->
    <?= getPrintHeader($is_sw ? 'RIPOTI YA UCHAMBUZI WA WANACHAMA' : 'MEMBER ANALYSIS REPORT') ?>

    <!-- Stats Row -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden border-start border-4 border-primary">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Wanachama Wote' : 'Total Members' ?></div>
                    <div class="fs-3 fw-bold text-primary"><?= number_format($total_members) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden border-start border-4 border-success">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Walio Active' : 'Active Members' ?></div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($active_members) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden border-start border-4 border-info">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Wapya (Siku 30)' : 'New (Last 30d)' ?></div>
                    <div class="fs-3 fw-bold text-info">+ <?= number_format($new_last_30) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 rounded-4 overflow-hidden border-start border-4 border-danger">
                <div class="card-body p-4 bg-white">
                    <div class="text-uppercase small fw-bold text-muted mb-2"><?= $is_sw ? 'Waliofariki' : 'Deceased' ?></div>
                    <div class="fs-3 fw-bold text-danger"><?= number_format($deceased_count) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Growth Chart -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Mwelekeo wa Ukuaji wa Wanachama' : 'Member Registration Trend' ?></h6>
                </div>
                <div class="card-body">
                    <canvas id="growthChart" height="150"></canvas>
                </div>
            </div>

            <!-- Latest Members Table -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Wanachama Waliojiunga Karibuni' : 'Recently Joined Members' ?></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small text-muted">
                                <tr>
                                    <th class="ps-4">S/NO</th>
                                    <th><?= $is_sw ? 'Mwanachama' : 'Name' ?></th>
                                    <th><?= $is_sw ? 'Tarehe' : 'Joined Date' ?></th>
                                    <th class="text-end pe-4"><?= $is_sw ? 'Hali' : 'Status' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($latest_members as $idx => $m): 
                                    // Determine actual status based on account state and deceased flag
                                    $is_active = ($m['status'] === 'active' && ($m['is_deceased'] ?? 0) == 0);
                                    $display_status = $is_active ? ($is_sw ? 'HAI' : 'ACTIVE') : ($is_sw ? 'DORMANT' : 'DORMANT');
                                    $status_color = $is_active ? 'success' : 'warning';
                                ?>
                                <tr>
                                    <td class="ps-4 small text-muted"><?= $idx + 1 ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></td>
                                    <td class="text-muted small"><?= date('d M, Y', strtotime($m['created_at'])) ?></td>
                                    <td class="text-end pe-4">
                                        <span class="badge rounded-pill bg-<?= $status_color ?> bg-opacity-10 text-<?= $status_color ?> px-3 border">
                                            <?= $display_status ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Demographics Sidebar -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Usambazaji wa Mikoa (Top 8)' : 'Regional Distribution' ?></h6>
                </div>
                <div class="card-body">
                    <canvas id="regionChart" height="300"></canvas>
                    <div class="mt-4">
                        <?php foreach ($regions_data as $r): 
                            $perc = ($total_members > 0) ? round(($r['count'] / $total_members) * 100, 1) : 0;
                        ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-semibold text-muted"><?= htmlspecialchars($r['region'] ?: 'N/A') ?></span>
                            <span class="small badge bg-primary bg-opacity-10 text-primary border border-primary-subtle"><?= $perc ?>%</span>
                        </div>
                        <div class="progress rounded-pill mb-3" style="height: 6px;">
                            <div class="progress-bar bg-primary" style="width: <?= $perc ?>%"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>    <!-- Print Footer -->
    <?= getPrintFooter() ?>
</div>

<style>
    @media print {
        body { background: white !important; font-size: 11px; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 20px !important; page-break-inside: avoid; }
        .nav-pills, .btn, .d-print-none { display: none !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
        canvas { max-width: 100% !important; height: auto !important; }
    }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(document).ready(function() {
    // Growth Trend Chart
    const growthCtx = document.getElementById('growthChart');
    if (growthCtx) {
        new Chart(growthCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($months_labels) ?>,
                datasets: [{
                    label: '<?= $is_sw ? "Wapya" : "New Members" ?>',
                    data: <?= json_encode($months_counts) ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    borderColor: '#0d6efd',
                    borderWidth: 3, tension: 0.4, fill: true,
                    pointRadius: 5, pointBackgroundColor: '#0d6efd'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] }, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Regional Doughnut
    const regionCtx = document.getElementById('regionChart');
    if (regionCtx) {
        new Chart(regionCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($regions_data, 'region')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($regions_data, 'count')) ?>,
                    backgroundColor: ['#0d6efd', '#0dcaf0', '#198754', '#ffc107', '#fd7e14', '#dc3545', '#6610f2', '#6f42c1'],
                    borderWidth: 0, hoverOffset: 15
                }]
            },
            options: {
                responsive: true, cutout: '75%',
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: { size: 10 } } } }
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
