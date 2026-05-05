<?php
// Start the buffer
ob_start();

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
includeHeader();

autoEnforcePermission('journals');

// Get date from request or default to today
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

// Helper function to format currency
function format_currency($amount) {
    return 'TSh ' . number_format($amount, 2);
}

// Get trial balance data
$trial_balance_sql = "
    SELECT 
        a.account_id,
        a.account_name,
        a.account_code,
        at.type_name as account_type,
        SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END) as total_debit,
        SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END) as total_credit
    FROM accounts a
    LEFT JOIN account_types at ON a.account_type_id = at.type_id
    LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
    LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id 
        AND je.entry_date <= ? 
        AND je.status = 'posted'
    WHERE a.status = 'active'
    GROUP BY a.account_id, a.account_name, a.account_code, at.type_name
    HAVING ABS(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END)) > 0.001 
       OR ABS(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END)) > 0.001
    ORDER BY at.type_name, a.account_code
";

$stmt = $pdo->prepare($trial_balance_sql);
$stmt->execute([$as_of_date]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals and net balances
$total_debits = 0;
$total_credits = 0;

foreach ($accounts as &$account) {
    $net = $account['total_debit'] - $account['total_credit'];
    if ($net > 0) {
        $account['display_debit'] = $net;
        $account['display_credit'] = 0;
        $total_debits += $net;
    } else {
        $account['display_debit'] = 0;
        $account['display_credit'] = abs($net);
        $total_credits += abs($net);
    }
}

$difference = $total_debits - $total_credits;
$is_balanced = abs($difference) < 0.01;

// Group accounts by type
$account_types = [
    'asset' => ['name' => 'Assets', 'accounts' => []],
    'liability' => ['name' => 'Liabilities', 'accounts' => []],
    'equity' => ['name' => 'Equity', 'accounts' => []],
    'income' => ['name' => 'Income', 'accounts' => []],
    'expense' => ['name' => 'Expenses', 'accounts' => []]
];

foreach ($accounts as $account) {
    $type = strtolower($account['account_type']);
    if (isset($account_types[$type])) {
        $account_types[$type]['accounts'][] = $account;
    }
}
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-calculator"></i> Trial Balance Report</h2>
                    <p class="text-muted mb-0">Financial verification as of <?= date('F j, Y', strtotime($as_of_date)) ?></p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="printTrialBalance()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportToExcel()">
                        <i class="bi bi-file-excel"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Report Options</h6>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="mb-3">
                            <label class="form-label">As of Date</label>
                            <input type="date" class="form-control" name="as_of_date" value="<?= $as_of_date ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update Report</button>
                    </form>
                </div>
            </div>

            <div class="card mb-4 border-<?= $is_balanced ? 'success' : 'danger' ?>">
                <div class="card-body text-center">
                    <h6 class="text-muted">Balance Status</h6>
                    <div class="h4 mb-2 text-<?= $is_balanced ? 'success' : 'danger' ?>">
                        <?= $is_balanced ? 'BALANCED' : 'OUT OF BALANCE' ?>
                    </div>
                    <?php if (!$is_balanced): ?>
                        <div class="text-danger small">Difference: <?= format_currency($difference) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="trialBalanceTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Account</th>
                                    <th class="text-end">Debit (TSh)</th>
                                    <th class="text-end pe-4">Credit (TSh)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($account_types as $type => $type_data): ?>
                                    <?php if (!empty($type_data['accounts'])): ?>
                                        <tr class="bg-light">
                                            <td colspan="3" class="ps-4 fw-bold text-uppercase small text-muted"><?= $type_data['name'] ?></td>
                                        </tr>
                                        <?php foreach ($type_data['accounts'] as $account): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold"><?= htmlspecialchars($account['account_name']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($account['account_code']) ?></div>
                                            </td>
                                            <td class="text-end">
                                                <?= $account['display_debit'] > 0 ? format_currency($account['display_debit']) : '-' ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <?= $account['display_credit'] > 0 ? format_currency($account['display_credit']) : '-' ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td class="ps-4">TOTALS</td>
                                    <td class="text-end"><?= format_currency($total_debits) ?></td>
                                    <td class="text-end pe-4"><?= format_currency($total_credits) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function printTrialBalance() {
    window.print();
}

function exportToExcel() {
    const table = document.getElementById('trialBalanceTable');
    const html = table.outerHTML;
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'trial_balance_<?= $as_of_date ?>.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
</script>

<style>
@media print {
    .btn, .card-header, form, .col-md-3 { display: none !important; }
    .col-md-9 { width: 100% !important; }
    .table { width: 100% !important; }
}
</style>

<?php
includeFooter();
ob_end_flush();
?>