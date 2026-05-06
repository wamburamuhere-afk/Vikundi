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
// DATA AGGREGATION: CONTRIBUTIONS VS FUNERAL AID
// ============================================================

// Fetch Deceased Members who received aid
// We join death_expenses with contributions summed per member
$query = "
    SELECT 
        d.member_id,
        MAX(d.expense_date) as latest_date,
        SUM(d.amount) as benefit_paid,
        COUNT(d.id) as cases_count,
        c.customer_name,
        c.status as member_status,
        c.is_deceased,
        (SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id = d.member_id AND status = 'confirmed') as total_contributed
    FROM death_expenses d
    LEFT JOIN customers c ON d.member_id = c.customer_id
    WHERE d.status = 'approved'
    GROUP BY d.member_id, c.customer_name, c.status, c.is_deceased
    ORDER BY latest_date DESC
";

$recipients = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Totals
$total_paid    = array_sum(array_column($recipients, 'benefit_paid'));
$total_inbound = array_sum(array_column($recipients, 'total_contributed'));
$net_fund_impact = $total_paid - $total_inbound;
$case_count    = count($recipients);

// Prepare Chart Data (Top 15 latest cases)
$chart_cases = array_slice($recipients, 0, 15);
$chart_labels = array_map(fn($r) => explode(' ', $r['customer_name'] ?? ($is_sw ? 'Mwanachama' : 'Member'))[0], $chart_cases);
$chart_contrib = array_column($chart_cases, 'total_contributed');
$chart_benefit = array_column($chart_cases, 'benefit_paid');

?>

<div class="container-fluid py-4 no-print-bg">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-heart-pulse me-2"></i> <?= $is_sw ? 'Uchambuzi wa Mafao ya Misiba' : 'Funeral Aid Sustainability Analysis' ?></h4>
            <div class="text-muted small"><?= $is_sw ? 'Tofauti kati ya michango ya mwanachama na msaada aliopewa akifariki' : 'Comparison between lifetime contributions and funeral assistance paid' ?></div>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm border-0 d-flex align-items-center">
                <i class="bi bi-printer-fill me-2"></i> <?= $is_sw ? 'Chapa Ripoti' : 'Print Analysis' ?>
            </button>
        </div>
    </div>

    <!-- Print Header (Visible ONLY on Print) -->
    <div class="d-none d-print-block">
        <div class="text-center mb-4">
            <img src="/assets/images/<?= htmlspecialchars($group_logo ?? 'logo1.png') ?>" alt="Logo" style="height: 80px; width: auto; margin-bottom: 10px; object-fit: contain;">
            <h2 class="fw-bold mb-1 text-uppercase" style="color: #0d6efd !important;"><?= htmlspecialchars($group_name ?? 'KIKUNDI') ?></h2>
            <h4 class="fw-bold text-dark text-uppercase border-top border-bottom py-2 mt-2">
                <?= $is_sw ? 'UCHAMBUZI WA KIFEDHA: MAFAO YA MISIBA' : 'FUNERAL AID SUSTAINABILITY ANALYSIS' ?>
            </h4>
            <div class="small text-muted mt-1"><?= $is_sw ? 'Tarehe ya Printi:' : 'Print Date:' ?> <?= date('d m, Y H:i') ?></div>
        </div>
    </div>

    <!-- Print-only summary table (replaces stat cards) -->
    <div class="d-none d-print-block mb-3">
        <table class="table table-bordered table-sm mb-0" style="font-size: 11px;">
            <thead class="table-light"><tr>
                <th><?= $is_sw ? 'Jumla ya Misaada' : 'Total Aid Paid' ?></th>
                <th><?= $is_sw ? 'Jumla Michango' : 'Total Contributions' ?></th>
                <th><?= $is_sw ? 'Impact ya Mfuko' : 'Fund Impact' ?></th>
                <th><?= $is_sw ? 'Jumla ya Vifo' : 'Total Cases' ?></th>
            </tr></thead>
            <tbody><tr>
                <td class="fw-bold text-primary">TZS <?= number_format($total_paid) ?></td>
                <td class="fw-bold text-success">TZS <?= number_format($total_inbound) ?></td>
                <td class="fw-bold text-danger">- TZS <?= number_format($net_fund_impact) ?></td>
                <td class="fw-bold"><?= $case_count ?></td>
            </tr></tbody>
        </table>
    </div>

    <!-- Stats Review (screen only) -->
    <div class="row g-4 mb-5 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-primary">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Jumla ya Misaada' : 'Total Aid Paid' ?></div>
                    <div class="fs-4 fw-bold text-primary">TZS <?= number_format($total_paid) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-success">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Jumla Michango Yao' : 'Total Contrib (Recv)' ?></div>
                    <div class="fs-4 fw-bold text-success">TZS <?= number_format($total_inbound) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-danger">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Impact ya Mfuko' : 'Fund Impact' ?></div>
                    <div class="fs-4 fw-bold text-danger">- TZS <?= number_format($net_fund_impact) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-body p-4 bg-white border-bottom border-4 border-info">
                    <div class="text-uppercase small fw-bold text-muted mb-2 tracking-wider"><?= $is_sw ? 'Jumla ya Vifo' : 'Total Cases' ?></div>
                    <div class="fs-4 fw-bold text-info"><?= $case_count ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comparative Chart (screen only) -->
    <div class="card border-0 shadow-sm rounded-4 mb-5 d-print-none">
        <div class="card-header bg-white py-3 border-bottom">
            <h6 class="mb-0 fw-bold"><?= $is_sw ? 'Mlinganisho wa Michango vs Msaada (Visa 15 vya mwisho)' : 'Contribution vs Benefit Comparison (Top 15 Cases)' ?></h6>
        </div>
        <div class="card-body">
            <canvas id="comparisonBarChart" height="120"></canvas>
            <div class="mt-3 text-center small text-muted">
                <span class="badge bg-success bg-opacity-25 text-success p-2 px-3 mx-2"><i class="bi bi-graph-up me-1"></i> <?= $is_sw ? 'Michango' : 'Contribution' ?></span>
                <span class="badge bg-primary bg-opacity-25 text-primary p-2 px-3 mx-2"><i class="bi bi-cash-stack me-1"></i> <?= $is_sw ? 'Msaada' : 'Benefit Paid' ?></span>
            </div>
        </div>
    </div>

    <!-- Detailed Analysis Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-primary"><?= $is_sw ? 'Mchanganuo wa Kila Aliyefanyiwa Expense' : 'Case-by-Case Payout Analysis' ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive d-none d-md-block d-print-block">
                <table class="table table-hover align-middle mb-0" id="deathSustainabilityTable">
                    <thead class="bg-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-4">S/NO</th>
                            <th><?= $is_sw ? 'Mwanachama' : 'Member Name' ?></th>
                            <th class="text-center"><?= $is_sw ? 'Visa' : 'Cases' ?></th>
                            <th class="text-end"><?= $is_sw ? 'Michango (TZS)' : 'Contrib (TZS)' ?></th>
                            <th class="text-end"><?= $is_sw ? 'Msaada (TZS)' : 'Benefit (TZS)' ?></th>
                            <th class="text-end text-primary"><?= $is_sw ? 'Tofauti (TZS)' : 'Variance (TZS)' ?></th>
                            <th class="text-end pe-4"><?= $is_sw ? 'Status' : 'Status' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recipients as $idx => $r): 
                            $variance = $r['total_contributed'] - $r['benefit_paid'];
                            $var_class = $variance >= 0 ? 'bg-success text-success' : 'bg-danger text-danger';
                            $var_sign = $variance >= 0 ? '+' : '-';
                        ?>
                        <tr>
                            <td class="ps-4 small text-muted"><?= $idx + 1 ?></td>
                            <td class="fw-bold">
                                <?= htmlspecialchars($r['customer_name'] ?? ($is_sw ? 'Mwanachama' : 'Member')) ?>
                                <div class="text-muted small" style="font-size: 0.75rem;"><?= $is_sw ? 'Hadi tarehe:' : 'Latest:' ?> <?= date('d/m/Y', strtotime($r['latest_date'])) ?></div>
                            </td>
                            <td class="text-center"><span class="badge bg-light text-dark border px-2"><?= $r['cases_count'] ?></span></td>
                            <td class="text-end fw-semibold"><?= number_format($r['total_contributed']) ?></td>
                            <td class="text-end fw-semibold text-primary"><?= number_format($r['benefit_paid']) ?></td>
                            <td class="text-end">
                                <span class="badge bg-opacity-10 border px-3 <?= $var_class ?>">
                                    <?= $var_sign ?> <?= number_format(abs($variance)) ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <?php 
                                $m_status = strtolower($r['member_status'] ?? '');
                                $is_deceased_member = (int)($r['is_deceased'] ?? 0);

                                if ($is_deceased_member == 1) {
                                    echo '<span class="badge bg-danger bg-opacity-10 text-danger border px-2">' . ($is_sw ? 'Marehemu' : 'Deceased') . '</span>';
                                } elseif ($m_status == 'active') {
                                    echo '<span class="badge bg-success bg-opacity-10 text-success border px-2">' . ($is_sw ? 'Active' : 'Active') . '</span>';
                                } else {
                                    echo '<span class="badge bg-warning bg-opacity-10 text-warning border px-2">' . ($is_sw ? 'Dormant' : 'Dormant') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recipients)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-info-circle me-1"></i> <?= $is_sw ? 'Hakuna taarifa za misaada ya misiba zilizopatikana.' : 'No funeral aid analysis records found.' ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- ═══ CARD VIEW — Mobile Only ═══ -->
            <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="deathAnalysisCardsWrapper">
                <?php if (empty($recipients)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-info-circle fs-1 d-block mb-3"></i>
                    <p><?= $is_sw ? 'Hakuna taarifa zilizopatikana.' : 'No analysis records found.' ?></p>
                </div>
                <?php else: foreach ($recipients as $idx => $r):
                    $da_variance   = $r['total_contributed'] - $r['benefit_paid'];
                    $da_pos        = $da_variance >= 0;
                    $da_name       = $r['customer_name'] ?? ($is_sw ? 'Mwanachama' : 'Member');
                    $da_letter     = strtoupper(substr($da_name, 0, 1));
                    $da_is_dec     = (int)($r['is_deceased'] ?? 0);
                    $da_status     = strtolower($r['member_status'] ?? '');
                    $da_av_color   = $da_is_dec
                        ? 'linear-gradient(135deg,#343a40,#212529)'
                        : ($da_status === 'active'
                            ? 'linear-gradient(135deg,#198754,#146c43)'
                            : 'linear-gradient(135deg,#fd7e14,#e85d04)');
                    $da_search     = strtolower($da_name . ' ' . date('d/m/Y', strtotime($r['latest_date'])));
                ?>
                <div class="vk-member-card" data-search="<?= htmlspecialchars($da_search) ?>">
                    <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="vk-card-avatar" style="background:<?= $da_av_color ?>;"><?= $da_letter ?></div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13px;"><?= htmlspecialchars($da_name) ?></div>
                                <small class="text-muted"><?= $is_sw ? 'Hadi:' : 'Latest:' ?> <?= date('d/m/Y', strtotime($r['latest_date'])) ?></small>
                            </div>
                        </div>
                        <?php if ($da_is_dec): ?>
                        <span class="badge bg-danger rounded-pill px-2" style="font-size:10px;"><?= $is_sw ? 'Marehemu' : 'Deceased' ?></span>
                        <?php elseif ($da_status === 'active'): ?>
                        <span class="badge bg-success rounded-pill px-2" style="font-size:10px;">Active</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark rounded-pill px-2" style="font-size:10px;">Dormant</span>
                        <?php endif; ?>
                    </div>
                    <div class="vk-card-body">
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Visa' : 'Cases' ?></span>
                            <span class="vk-card-value"><span class="badge bg-light text-dark border"><?= $r['cases_count'] ?></span></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Michango' : 'Contrib.' ?></span>
                            <span class="vk-card-value fw-bold"><?= number_format($r['total_contributed']) ?></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Msaada' : 'Benefit' ?></span>
                            <span class="vk-card-value fw-bold text-primary"><?= number_format($r['benefit_paid']) ?></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Tofauti' : 'Variance' ?></span>
                            <span class="vk-card-value">
                                <span class="badge bg-opacity-10 border <?= $da_pos ? 'bg-success text-success' : 'bg-danger text-danger' ?> px-2">
                                    <?= ($da_pos ? '+' : '-') . number_format(abs($da_variance)) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        @page { margin: 1cm; }
        body { background: white !important; font-size: 11px; color: black; padding-bottom: 40px; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 25px !important; page-break-inside: avoid; }
        .d-print-none, .btn, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate { display: none !important; }
        .col-6 { width: 50% !important; flex: 0 0 50% !important; }
        .row { display: flex !important; flex-wrap: wrap !important; }
    }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(document).ready(function() {
    const lang = { search: '<?= $is_sw ? "Tafuta:" : "Search:" ?>' };
    $('#deathSustainabilityTable').DataTable({
        language: lang,
        pageLength: 25,
        order:[[4, 'desc']],
        drawCallback: function() {
            var term = (this.api().search() || '').toLowerCase().trim();
            $('#deathAnalysisCardsWrapper .vk-member-card').each(function() {
                var text = ($(this).data('search') || '').toLowerCase();
                $(this).toggle(!term || text.includes(term));
            });
        }
    });

    const ctx = document.getElementById('comparisonBarChart');
    if (ctx) {
        new Chart(ctx, {
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [
                    {
                        type: 'bar',
                        label: '<?= $is_sw ? "Michango" : "Contributions" ?>',
                        data: <?= json_encode($chart_contrib) ?>,
                        backgroundColor: 'rgba(25, 135, 84, 0.6)',
                        borderColor: '#198754',
                        borderWidth: 1, borderRadius: 5,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: '<?= $is_sw ? "Msaada" : "Benefit Paid" ?>',
                        data: <?= json_encode($chart_benefit) ?>,
                        backgroundColor: 'rgba(13, 110, 253, 0.2)',
                        borderColor: '#0d6efd',
                        borderWidth: 3, tension: 0.4,
                        pointRadius: 6, pointBackgroundColor: '#0d6efd',
                        fill: false,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] }, ticks: { callback: v => 'TZS ' + v.toLocaleString(), font: { size: 10 } } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }
});
</script>

<?php include PRINT_FOOTER_FILE; ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
