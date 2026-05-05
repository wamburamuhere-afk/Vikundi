<?php
// File: income_statement.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('reports');

// ── Activity Log: Page View ───────────────────────────────────────────────────
require_once ROOT_DIR . '/includes/activity_logger.php';
$lang = $_SESSION['preferred_language'] ?? 'en';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$log_desc = $lang === 'sw'
    ? "Alitazama Taarifa ya Mapato: $start_date hadi $end_date"
    : "Viewed Income Statement: $start_date to $end_date";
logActivity('Viewed', 'Financial Reports', $log_desc, 'INCOME-STATEMENT');
// ─────────────────────────────────────────────────────────────────────────────
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('invoices') ?>">Invoices</a></li>
            <li class="breadcrumb-item active">Income Statement</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-graph-up text-success"></i> Income Statement</h2>
                    <p class="text-muted mb-0">Financial performance report for accounting</p>
                </div>
                <div>
                    <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="printAndLog()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button class="btn btn-primary btn-sm shadow-sm" onclick="exportExcel()">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4 shadow-sm border-0 d-print-none">
        <div class="card-body p-3">
            <form action="" method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Start Date</label>
                    <input type="date" class="form-control form-control-sm" name="start_date" id="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">End Date</label>
                    <input type="date" class="form-control form-control-sm" name="end_date" id="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-filter"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4 g-3" id="summaryCards">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0">
                <div class="card-body p-3 text-center">
                    <p class="small fw-bold mb-1 text-uppercase">Total Revenue</p>
                    <h3 class="mb-0 fw-bold" id="totalRevenue">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0" style="background-color: #fff8e1 !important; border: 1px solid #ffe0b2 !important;">
                <div class="card-body p-3 text-center">
                    <p class="text-warning-dark small fw-bold mb-1 text-uppercase">Cost of Goods Sold</p>
                    <h3 class="text-warning-dark mb-0 fw-bold" id="totalCOGS">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0" style="background-color: #fdecea !important; border: 1px solid #ffcdd2 !important;">
                <div class="card-body p-3 text-center">
                    <p class="text-danger small fw-bold mb-1 text-uppercase">Operating Expenses</p>
                    <h3 class="text-danger mb-0 fw-bold" id="totalExpenses">0.00</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0" style="background-color: #e3f2fd !important; border: 1px solid #bbdefb !important;">
                <div class="card-body p-3 text-center">
                    <p class="text-primary-dark small fw-bold mb-1 text-uppercase">Net Income</p>
                    <h3 class="text-primary-dark mb-0 fw-bold" id="netIncome">0.00</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Report -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Detailed Statement</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="reportTable">
                    <thead class="bg-light text-uppercase small fw-bold text-muted">
                        <tr>
                            <th width="15%" class="ps-4">Code</th>
                            <th width="60%">Account</th>
                            <th width="25%" class="text-end pe-4">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Revenue Section -->
                        <tr class="table-light"><td colspan="3" class="ps-4"><strong>REVENUE</strong></td></tr>
                        <tbody id="revenueBody"></tbody>
                        <tr class="fw-bold"><td colspan="2" class="ps-4">Total Revenue</td><td class="text-end pe-4" id="revenueSubtotal">0.00</td></tr>
                        
                        <!-- COGS Section -->
                        <tr class="table-light"><td colspan="3" class="ps-4"><strong>COST OF GOODS SOLD</strong></td></tr>
                        <tbody id="cogsBody"></tbody>
                        <tr class="fw-bold"><td colspan="2" class="ps-4">Total COGS</td><td class="text-end pe-4" id="cogsSubtotal">0.00</td></tr>
                        
                        <!-- Gross Profit -->
                        <tr class="bg-light fw-bold"><td colspan="2" class="ps-4 text-primary">GROSS PROFIT</td><td class="text-end pe-4 text-primary" id="grossProfit">0.00</td></tr>
                        
                        <!-- Expenses Section -->
                        <tr class="table-light"><td colspan="3" class="ps-4"><strong>OPERATING EXPENSES</strong></td></tr>
                        <tbody id="expensesBody"></tbody>
                        <tr class="fw-bold"><td colspan="2" class="ps-4">Total Expenses</td><td class="text-end pe-4" id="expensesSubtotal">0.00</td></tr>
                        
                        <!-- Net Income -->
                        <tr class="custom-stat-card fw-bold fs-5"><td colspan="2" class="ps-4">NET INCOME</td><td class="text-end pe-4" id="netIncomeFinal">0.00</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    loadReport();
    
    $('form').on('submit', function(e) {
        e.preventDefault();
        loadReport();
    });
});

function loadReport() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    
    $('body').css('cursor', 'wait');
    
    $.ajax({
        url: '<?= buildUrl('api/account/get_income_statement.php') ?>',
        type: 'GET',
        data: { start_date: start, end_date: end },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderReport(response.data);
            } else {
                Swal.fire('Error', 'Error loading report', 'error');
            }
            $('body').css('cursor', 'default');
        },
        error: function() {
            Swal.fire('Error', 'Failed to load report data', 'error');
            $('body').css('cursor', 'default');
        }
    });
}

function renderReport(data) {
    const revenue = data.revenue_accounts || [];
    const expenses = data.expense_accounts || [];
    
    let totalRev = 0;
    let totalCogs = 0;
    let totalExp = 0;
    
    let revHtml = '';
    revenue.forEach(acc => {
        const val = parseFloat(acc.current_period);
        totalRev += val;
        revHtml += `<tr><td class="ps-4 text-muted small"><span class="custom-code">${acc.account_code || ''}</span></td><td>${acc.account_name}</td><td class="text-end pe-4 font-monospace">${formatMoney(val)}</td></tr>`;
    });
    $('#revenueBody').html(revHtml);
    $('#revenueSubtotal, #totalRevenue').text(formatMoney(totalRev));
    
    let expHtml = '';
    let cogsHtml = '';
    expenses.forEach(acc => {
        const val = parseFloat(acc.current_period);
        if (acc.account_type === 'cost_of_sales') {
            totalCogs += val;
            cogsHtml += `<tr><td class="ps-4 text-muted small"><span class="custom-code">${acc.account_code || ''}</span></td><td>${acc.account_name}</td><td class="text-end pe-4 font-monospace">${formatMoney(val)}</td></tr>`;
        } else {
            totalExp += val;
            expHtml += `<tr><td class="ps-4 text-muted small"><span class="custom-code">${acc.account_code || ''}</span></td><td>${acc.account_name}</td><td class="text-end pe-4 font-monospace">${formatMoney(val)}</td></tr>`;
        }
    });
    
    $('#cogsBody').html(cogsHtml);
    $('#expensesBody').html(expHtml);
    $('#cogsSubtotal, #totalCOGS').text(formatMoney(totalCogs));
    $('#expensesSubtotal, #totalExpenses').text(formatMoney(totalExp));
    
    const grossProfit = totalRev - totalCogs;
    const netIncome = grossProfit - totalExp;
    
    $('#grossProfit').text(formatMoney(grossProfit));
    $('#netIncome, #netIncomeFinal').text(formatMoney(netIncome));
    
    if (netIncome < 0) {
        $('#netIncome').parent().parent().css('background-color', '#fdecea');
        $('#netIncomeFinal').parent().css('background-color', '#fdecea').css('color', '#c62828');
    } else {
        $('#netIncome').parent().parent().css('background-color', '#d1e7dd');
        $('#netIncomeFinal').parent().css('background-color', '#d1e7dd').css('color', '#0f5132');
    }
}

function formatMoney(amount) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(amount);
}

function exportExcel() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    window.location.href = `<?= buildUrl('api/account/export_income_statement.php') ?>?start_date=${start}&end_date=${end}`;
}

function printAndLog() {
    const start = $('#start_date').val();
    const end = $('#end_date').val();
    // Log the print action via AJAX, then print
    $.post('<?= getUrl('api/log_action') ?>', {
        action: 'Printed',
        module: 'Financial Reports',
        description: `Printed Income Statement: ${start} to ${end}`,
        reference: 'INCOME-STATEMENT'
    }).always(function() {
        window.print();
    });
}
</script>

<style>
/* Soft Green Theme from expenses.php */
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 1rem;
    transition: transform 0.2s;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h3, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}
.table thead th { 
    background-color: #f8f9fa; 
    border-bottom: 2px solid #dee2e6; 
    padding: 1rem 0.5rem;
}
.card { border-radius: 0.75rem; }
.text-warning-dark { color: #f57f17 !important; }
.text-primary-dark { color: #1565c0 !important; }
</style>

<?php includeFooter(); ?>