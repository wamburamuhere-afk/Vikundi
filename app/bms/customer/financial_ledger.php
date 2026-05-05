<?php
// app/bms/customer/financial_ledger.php
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/header.php';

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
    require_once ROOT_DIR . '/footer.php';
    exit();
}

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// 1. Fetch Group Settings (Rates & Branding)
$stmt = $pdo->query("SELECT setting_key, setting_value FROM group_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly_rate = (float)($settings['monthly_contribution'] ?? 10000);
$entrance_fee_rate = (float)($settings['entrance_fee'] ?? 0);
$agm_fee_rate = (float)($settings['agm_fee'] ?? 0);
$group_logo = $settings['group_logo'] ?? 'logo1.png';
$group_name = $settings['group_name'] ?? 'VIKUNDI SYSTEM';
$contribution_start_date = $settings['contribution_start_date'] ?? null;

// 2. Filter logic (Date Range)
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');

// Calculate months in range for Target
$ts1 = strtotime($start_date);
$ts2 = strtotime($end_date);
$year1 = date('Y', $ts1);
$year2 = date('Y', $ts2);
$month1 = date('m', $ts1);
$month2 = date('m', $ts2);
$diff_months = (($year2 - $year1) * 12) + ($month2 - $month1) + 1;

// 3. Fetch All Members
$stmt = $pdo->query("SELECT customer_id, first_name, last_name, mpesa_name, status, created_at FROM customers WHERE status != 'deleted' ORDER BY first_name ASC");
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch All Confirmed Contributions in Range
$stmt = $pdo->prepare("
    SELECT member_id, amount, contribution_type, contribution_date 
    FROM contributions 
    WHERE status = 'confirmed' 
    AND contribution_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$contributions = $stmt->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

// 5. Fetch Member Payouts (Assistance)
$stmt = $pdo->prepare("
    SELECT member_id, SUM(amount) as total_assistance 
    FROM member_payouts 
    WHERE status = 'paid' 
    AND payout_date BETWEEN ? AND ?
    GROUP BY member_id
");
$stmt->execute([$start_date, $end_date]);
$payouts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

function fmt($n) { return number_format($n, 0); }
?>


    <div class="row mb-3 align-items-center">
        <div class="col-12">
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i> <?= $is_sw ? 'Ledger ya Fedha ya Kikundi' : 'Group Financial Ledger' ?></h2>
            <p class="text-muted mb-3 small"><?= $is_sw ? 'Ripoti ya michango, makato, na ziada ya kila mwanachama.' : 'Report of contributions, deductions, and balance for each member.' ?></p>
            
            <div class="bg-white p-3 rounded-3 shadow-sm d-print-none mb-3 border">
                <div class="row g-3 align-items-center">
                    <!-- Unified Toolbar: Action Buttons & Show Entries on the Right -->
                    <div class="col-12 d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" onclick="window.print()" class="btn btn-sm btn-light border shadow-sm px-3 text-primary fw-bold bg-white">
                                <i class="bi bi-printer me-1"></i> <?= $is_sw ? 'Print' : 'Print' ?>
                            </button>
                            <div class="btn-group shadow-sm">
                                <button type="button" class="btn btn-sm btn-light border px-3 text-success fw-bold bg-white dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="bi bi-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu border-0 shadow">
                                    <li><a class="dropdown-item py-2" href="#" onclick="exportLedger('excel')"><i class="bi bi-file-earmark-excel text-success me-2"></i> Excel Spreadsheet</a></li>
                                    <li><a class="dropdown-item py-2" href="#" onclick="exportLedger('pdf')"><i class="bi bi-file-earmark-pdf text-danger me-2"></i> PDF Document</a></li>
                                </ul>
                            </div>
                            <div class="vr mx-1 d-none d-md-block" style="height: 25px;"></div>
                            <div id="customLengthMenu" class="small"></div>
                        </div>

                        <form class="d-flex mt-2 mt-md-0">
                            <div class="input-group input-group-sm border rounded shadow-sm bg-white overflow-hidden">
                                <span class="input-group-text bg-white border-0 text-muted small"><?= $is_sw ? 'Kuanzia' : 'From' ?></span>
                                <input type="date" name="start_date" class="form-control border-0" value="<?= $start_date ?>" style="width: 125px;">
                                <span class="input-group-text bg-white border-0 text-muted small"><?= $is_sw ? 'Mpaka' : 'To' ?></span>
                                <input type="date" name="end_date" class="form-control border-0" value="<?= $end_date ?>" style="width: 125px;">
                                <button type="submit" class="btn btn-primary border-0 px-3">
                                    <i class="bi bi-filter"></i>
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 border-top border-primary border-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive w-100" style="overflow-x: auto;">
                <table id="ledgerTable" class="table table-hover align-middle mb-0 w-100" style="font-size: 0.85rem;">
                    <thead class="bg-white align-middle border-bottom">
                        <tr>
                            <th rowspan="2" class="bg-light border-0 text-center" style="width: 45px;">S/N</th>
                            <th rowspan="2" class="text-primary text-center"><?= $is_sw ? 'Jina la Mwanachama' : 'Member Name' ?></th>
                            <th rowspan="2" class="text-center"><?= $is_sw ? 'Jina la M-Koba' : 'M-Koba Name' ?></th>
                            <th rowspan="2" class="text-center"><?= $is_sw ? 'Kiingilio' : 'Entrance' ?></th>
                            <th colspan="<?= $diff_months ?>" class="text-primary border-bottom text-center"><?= $is_sw ? 'Michango ya Kila Mwezi (Monthly)' : 'Monthly Subscriptions' ?></th>
                            <th rowspan="2" class="bg-light fw-bold text-center"><?= $is_sw ? 'Jumla' : 'Total' ?></th>
                            <th rowspan="2" class="text-center"><?= $is_sw ? 'Misaada' : 'Aid' ?></th>
                            <th rowspan="2" class="text-center"><?= $is_sw ? 'AGM' : 'AGM' ?></th>
                            <th rowspan="2" class="fw-bold text-center text-primary"><?= $is_sw ? 'Baki' : 'Balance' ?></th>
                            <th colspan="2" class="bg-light text-center"><?= $is_sw ? 'Target' : 'Target' ?></th>
                            <th rowspan="2" class="fw-bold text-center text-primary"><?= $is_sw ? 'Ziada / Pungufu' : 'Surplus / Deficit' ?></th>
                        </tr>
                        <tr>
                            <?php 
                            for($i=0; $i<$diff_months; $i++) {
                                echo "<th class='fw-normal text-center'>" . date($is_sw ? 'M y' : 'M y', strtotime("+$i months", $ts1)) . "</th>";
                            }
                            ?>
                            <th class="bg-light fw-normal text-center"><?= $is_sw ? 'Miezi' : 'M' ?></th>
                            <th class="bg-light fw-normal text-center"><?= $is_sw ? 'Target' : 'T' ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php 
                        $grand_total_contributed = 0;
                        $grand_total_target = 0;
                        foreach ($members as $idx => $m): 
                            $mid = $m['customer_id'];
                            $m_contribs = $contributions[$mid] ?? [];
                            
                            $total_raw_pool = 0; // Pool for Entrance + Monthly
                            $agm_paid = 0;

                            foreach ($m_contribs as $c) {
                                if ($c['contribution_type'] == 'agm') {
                                    $agm_paid += $c['amount'];
                                } elseif ($c['contribution_type'] == 'entrance' || $c['contribution_type'] == 'monthly') {
                                    $total_raw_pool += $c['amount'];
                                }
                            }

                            // Intelligence: Distribution Logic
                            // 1. Take Entrance Fee first
                            $entrance_paid = min($total_raw_pool, $entrance_fee_rate);
                            $remaining_for_months = $total_raw_pool - $entrance_paid;
                            
                            $monthly_total = 0;
                            $monthly_by_month = array_fill(0, $diff_months, 0);
                            $valid_months_count = 0;

                            // 2. Distribute the rest to months sequentially (Only if month >= start date)
                            for ($i = 0; $i < $diff_months; $i++) {
                                $current_col_month = date('Y-m-01', strtotime("+$i months", $ts1));
                                
                                // Check if this month is valid for contribution
                                $is_valid_month = true;
                                if ($contribution_start_date) {
                                    $start_month = date('Y-m-01', strtotime($contribution_start_date));
                                    if ($current_col_month < $start_month) {
                                        $is_valid_month = false;
                                    }
                                }

                                if ($is_valid_month) {
                                    $valid_months_count++;
                                    if ($remaining_for_months > 0) {
                                        $allocation = min($remaining_for_months, $monthly_rate);
                                        $monthly_by_month[$i] = $allocation;
                                        $monthly_total += $allocation;
                                        $remaining_for_months -= $allocation;
                                    }
                                }
                            }
                            
                            // If any leftover after all moths, add it to the LAST month in view OR keep in total
                            if ($remaining_for_months > 0 && $diff_months > 0) {
                                $monthly_by_month[$diff_months - 1] += $remaining_for_months;
                                $monthly_total += $remaining_for_months;
                            }

                            $total_assistance = (float)($payouts[$mid] ?? 0);
                            $total_member_contributed = $total_raw_pool + $agm_paid; // All confirmed money
                            $balance = $total_member_contributed - $total_assistance - $agm_paid; 
                            
                            $target_amt = $monthly_rate * $valid_months_count;
                            // Surplus/Deficit is based on the logic: (Pool - Entrance) vs Target
                            // Actually it's simpler: What is the total monthly we got vs what we need?
                            $surplus_deficit = ($total_raw_pool - $entrance_fee_rate) - $target_amt;
                            
                            $status_class = $surplus_deficit >= 0 ? 'text-success' : 'text-danger';
                        ?>
                        <tr>
                            <td class="text-center text-muted small"><?= $idx + 1 ?></td>
                            <td class="fw-bold">
                                <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                <?php if ($m['status'] == 'suspended' || $m['status'] == 'terminated'): ?><span class="badge bg-light text-dark shadow-sm ms-1 border" style="font-size: 0.6rem;">OS</span><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($m['mpesa_name'] ?? '—') ?></td>
                            <td class="text-end"><?= $entrance_paid > 0 ? fmt($entrance_paid) : '—' ?></td>
                            
                            <?php foreach ($monthly_by_month as $amt): ?>
                                <td class="text-end <?= $amt < $monthly_rate && $amt > 0 ? 'text-warning' : ($amt >= $monthly_rate ? 'text-dark' : 'text-muted') ?>"><?= $amt > 0 ? fmt($amt) : '—' ?></td>
                            <?php endforeach; ?>

                            <td class="text-end fw-bold bg-light border-start"><?= fmt($total_member_contributed) ?></td>
                            <td class="text-end text-danger"><?= $total_assistance > 0 ? fmt($total_assistance) : '—' ?></td>
                            <td class="text-end text-muted small"><?= fmt($agm_paid) ?></td>
                            <td class="text-end fw-bold text-primary"><?= fmt($balance) ?></td>
                            
                            <td class="text-center bg-light small"><?= $diff_months ?></td>
                            <td class="text-end bg-light small"><?= fmt($target_amt) ?></td>
                            
                            <td class="text-end fw-bold <?= $status_class ?> border-start">
                                <?= ($surplus_deficit >= 0 ? '+' : '') . fmt($surplus_deficit) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- Summary or Footer can go here if needed in the future -->


<script>
$(document).ready(function() {
    if ($.fn.DataTable) {
        window.ledgerTable = $('#ledgerTable').DataTable({
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "language": {
                "lengthMenu": "<?= $is_sw ? '_MENU_' : '_MENU_' ?>",
                "search": "<?= $is_sw ? 'Tafuta:' : 'Search:' ?>",
                "info": "<?= $is_sw ? 'Inaonyesha _START_ hadi _END_ kati ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_' ?>",
                "paginate": {
                    "next": "<?= $is_sw ? 'Mbele' : 'Next' ?>",
                    "previous": "<?= $is_sw ? 'Nyuma' : 'Previous' ?>"
                }
            },
            "dom": '<"d-none"Bl><"d-flex flex-wrap justify-content-between align-items-center px-3 pt-3 d-print-none"f>rt<"d-flex flex-wrap justify-content-between align-items-center p-3 d-print-none"ip>',
            "buttons": [
                {
                    extend: 'excelHtml5',
                    title: '<?= $is_sw ? "Ledger ya Fedha" : "Financial Ledger" ?> - <?= date("d-m-Y") ?>',
                    className: 'd-none'
                },
                {
                    extend: 'pdfHtml5',
                    orientation: 'landscape',
                    pageSize: 'A4',
                    title: '<?= $is_sw ? "Ledger ya Fedha" : "Financial Ledger" ?> - <?= date("d-m-Y") ?>',
                    className: 'd-none',
                    customize: function (doc) {
                        doc.defaultStyle.fontSize = 8;
                        doc.styles.tableHeader.fontSize = 9;
                        doc.styles.tableHeader.alignment = 'center';
                        doc.content[1].table.widths = Array(doc.content[1].table.body[0].length + 1).join('*').split('');
                    }
                }
            ],
            "initComplete": function() {
                $('.dataTables_length select').addClass('form-select form-select-sm border shadow-sm').css('width', '80px');
                $('.dataTables_length').appendTo('#customLengthMenu');
            }
        });
    }
});

function exportLedger(type) {
    if (type === 'excel') {
        window.ledgerTable.button(0).trigger();
    } else if (type === 'pdf') {
        window.ledgerTable.button(1).trigger();
    }
}
</script>

<style>
.table-responsive { overflow-x: hidden; }
#ledgerTable th, #ledgerTable td { padding: 10px 12px; }
.table-hover tbody tr:hover { background-color: rgba(13, 110, 253, 0.03); }
.dataTables_wrapper .dataTables_length select { border-radius: 5px; padding: 3px 10px; border: 1px solid #dee2e6; outline: none; }
.dataTables_wrapper .dataTables_filter input { border-radius: 5px; padding: 5px 15px; border: 1px solid #dee2e6; outline: none; margin-left: 10px; }

@media print {
    .table-responsive { overflow: visible !important; }
    .btn, .btn-group, form, .dataTables_length, .dataTables_filter, .dataTables_paginate, .dataTables_info { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .container-fluid { width: 100% !important; max-width: none !important; padding: 0 !important; }
    table { font-size: 0.75rem !important; border: 1px solid #eee !important; width: 100% !important; table-layout: auto !important; }
    th, td { border: 1px solid #eee !important; padding: 4px !important; white-space: normal !important; text-align: center !important; }
    .text-primary { color: #000 !important; }
    .bg-light { background-color: #f9f9f9 !important; -webkit-print-color-adjust: exact; }
    .text-end { text-align: right !important; }
}
</style>

<?php
require_once ROOT_DIR . '/footer.php';
ob_end_flush();
?>
