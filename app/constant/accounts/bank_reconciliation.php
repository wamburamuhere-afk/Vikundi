<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for bank reconciliation permissions
$can_view_reconciliation = in_array($user_role, ['Admin', 'Manager', 'Accountant']);
$can_edit_reconciliation = in_array($user_role, ['Admin', 'Accountant']);
$can_finalize_reconciliation = in_array($user_role, ['Admin', 'Manager']);

if (!$can_view_reconciliation) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get bank accounts
$bank_accounts = $pdo->query("
    SELECT ba.*, b.bank_name, b.bank_code, b.account_number 
    FROM accounts ba 
    LEFT JOIN banks b ON ba.account_id = b.bank_id 
    WHERE ba.status = 'active' 
    ORDER BY b.bank_name, ba.account_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get current period (default to current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$bank_account_id = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : null;

// Helper functions


?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-bank"></i> Bank Reconciliation</h2>
                    <p class="text-muted mb-0">Reconcile bank statements with your accounting records</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_reconciliation): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importStatementModal">
                        <i class="bi bi-upload"></i> Import Statement
                    </button>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newReconciliationModal">
                        <i class="bi bi-plus-circle"></i> New Reconciliation
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-info" onclick="exportReconciliation()">
                        <i class="bi bi-download"></i> Export Report
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Reconciliation Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="reconciliationFilterForm" method="GET">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Bank Account</label>
                            <select class="form-select" id="bank_account_id" name="bank_account_id">
                                <option value="">All Bank Accounts</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?= $account['account_id'] ?>" <?= ($bank_account_id == $account['account_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($account['bank_name']) ?> - <?= safe_output($account['account_name']) ?> (<?= safe_output($account['account_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending">Pending</option>
                                <option value="reconciled">Reconciled</option>
                                <option value="disputed">Disputed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-12 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                                <i class="bi bi-arrow-clockwise"></i> Clear
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <?php
        // Calculate statistics
        $stats_query = "
            SELECT 
                COUNT(*) as total_reconciliations,
                SUM(CASE WHEN status = 'reconciled' THEN 1 ELSE 0 END) as reconciled,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) as disputed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM bank_reconciliations 
            WHERE 1=1
        ";
        
        $stats_params = [];
        
        if ($bank_account_id) {
            $stats_query .= " AND bank_account_id = ?";
            $stats_params[] = $bank_account_id;
        }
        
        $stats_stmt = $pdo->prepare($stats_query);
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['total_reconciliations'] ?? 0 ?></h4>
                            <p class="mb-0">Total Reconciliations</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-list-check" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['reconciled'] ?? 0 ?></h4>
                            <p class="mb-0">Reconciled</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['pending'] ?? 0 ?></h4>
                            <p class="mb-0">Pending</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['disputed'] ?? 0 ?></h4>
                            <p class="mb-0">Disputed</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reconciliation Summary -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Reconciliation Summary</h5>
            <span class="badge bg-light text-dark">
                <?= date('F Y', strtotime($start_date)) ?>
            </span>
        </div>
        <div class="card-body">
            <?php if ($bank_account_id): 
                // Get reconciliation summary for selected account
                $summary_query = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals,
                        COALESCE(SUM(CASE WHEN status = 'unreconciled' THEN amount ELSE 0 END), 0) as unreconciled_amount,
                        COUNT(CASE WHEN status = 'unreconciled' THEN 1 END) as unreconciled_count
                    FROM bank_transactions 
                    WHERE account_id = ? 
                    AND transaction_date BETWEEN ? AND ?
                ";
                
                $summary_stmt = $pdo->prepare($summary_query);
                $summary_stmt->execute([$bank_account_id, $start_date, $end_date]);
                $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
            ?>
            <div class="row">
                <div class="col-md-3">
                    <div class="card bg-light mb-3">
                        <div class="card-body text-center">
                            <h3 class="text-success"><?= format_currency($summary['total_deposits']) ?></h3>
                            <p class="text-muted mb-0">Total Deposits</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light mb-3">
                        <div class="card-body text-center">
                            <h3 class="text-danger"><?= format_currency($summary['total_withdrawals']) ?></h3>
                            <p class="text-muted mb-0">Total Withdrawals</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light mb-3">
                        <div class="card-body text-center">
                            <h3 class="text-warning"><?= format_currency($summary['unreconciled_amount']) ?></h3>
                            <p class="text-muted mb-0">Unreconciled Amount</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light mb-3">
                        <div class="card-body text-center">
                            <h3 class="text-info"><?= $summary['unreconciled_count'] ?></h3>
                            <p class="text-muted mb-0">Unreconciled Items</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-4">
                <i class="bi bi-bank" style="font-size: 3rem; color: #6c757d;"></i>
                <h4 class="mt-3 text-muted">Select a Bank Account</h4>
                <p class="text-muted">Please select a bank account to view reconciliation summary.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reconciliation List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Reconciliation Records</h5>
            <span class="badge bg-light text-dark">
                Showing <?= $stats['total_reconciliations'] ?? 0 ?> records
            </span>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive">
                <table id="reconciliationsTable" class="table table-striped table-hover" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Account</th>
                            <th>Date</th>
                            <th>Statement Bal</th>
                            <th>Difference</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- New Reconciliation Modal -->
<?php if ($can_edit_reconciliation): ?>
<div class="modal fade" id="newReconciliationModal" tabindex="-1" aria-labelledby="newReconciliationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="newReconciliationModalLabel">
                    <i class="bi bi-plus-circle"></i> New Bank Reconciliation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newReconciliationForm">
                <div class="modal-body">
                    <div id="new-reconciliation-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reconciliation_bank_account_id" class="form-label">Bank Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="reconciliation_bank_account_id" name="bank_account_id" required>
                                <option value="">Select Bank Account</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?= $account['account_id'] ?>">
                                    <?= safe_output($account['bank_name']) ?> - <?= safe_output($account['account_name']) ?> (<?= safe_output($account['account_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="reconciliation_date" class="form-label">Reconciliation Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="reconciliation_date" name="reconciliation_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="period_start" class="form-label">Period Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="period_start" name="period_start" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="period_end" class="form-label">Period End <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="period_end" name="period_end" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="statement_balance" class="form-label">Statement Balance <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="statement_balance" name="statement_balance" step="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="book_balance" class="form-label">Book Balance <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="book_balance" name="book_balance" step="0.01" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Any notes or comments about this reconciliation"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Create Reconciliation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Import Statement Modal -->
<?php if ($can_edit_reconciliation): ?>
<div class="modal fade" id="importStatementModal" tabindex="-1" aria-labelledby="importStatementModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importStatementModalLabel">
                    <i class="bi bi-upload"></i> Import Bank Statement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importStatementForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Supported formats: CSV, Excel (.xlsx)</li>
                            <li>Date format: YYYY-MM-DD</li>
                            <li>Amount format: 1234.56 (no currency symbols)</li>
                            <li>Transaction types: deposit, withdrawal</li>
                            <li>Maximum file size: 10MB</li>
                        </ul>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="import_bank_account_id" class="form-label">Bank Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="import_bank_account_id" name="bank_account_id" required>
                                <option value="">Select Bank Account</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?= $account['account_id'] ?>">
                                    <?= safe_output($account['bank_name']) ?> - <?= safe_output($account['account_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="statement_file" class="form-label">Statement File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="statement_file" name="statement_file" accept=".csv,.xlsx,.xls" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="import_action" class="form-label">Import Action</label>
                            <select class="form-select" id="import_action" name="import_action">
                                <option value="add_new">Add New Transactions Only</option>
                                <option value="replace">Replace All Transactions for Period</option>
                                <option value="update">Update Existing Transactions</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_match" name="auto_match" checked>
                                <label class="form-check-label" for="auto_match">
                                    Attempt to auto-match with existing transactions
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Statement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include DataTables and other scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#reconciliationsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/account/get_bank_reconciliations.php',
            type: 'POST'
        },
        columns: [
            { data: 'reconciliation_id' },
            { data: 'account_name' },
            { 
                data: 'reconciliation_date',
                render: function(data) {
                    return new Date(data).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                }
            },
            { 
                data: 'statement_balance',
                render: function(data) {
                    return parseFloat(data).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            },
            { 
                data: 'difference',
                render: function(data) {
                    const val = parseFloat(data);
                    const color = Math.abs(val) > 0.01 ? (val > 0 ? 'success' : 'danger') : 'success';
                    return `<span class="badge bg-${color}">${val.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    const colors = {
                        'pending': 'warning',
                        'reconciled': 'success',
                        'disputed': 'danger',
                        'cancelled': 'secondary'
                    };
                    return `<span class="badge bg-${colors[data] || 'secondary'}">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                }
            },
            {
                data: 'reconciliation_id',
                orderable: false,
                render: function(data, type, row) {
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="/accounts/reconciliation_details?id=${data}">
                                        <i class="bi bi-eye"></i> View Details
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteReconciliation(${data})">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ records",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-excel"></i> Excel',
                className: 'btn btn-sm btn-outline-success',
                title: 'Bank_Reconciliations_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                title: 'Bank_Reconciliations_' + new Date().toISOString().slice(0,10)
            }
        ],
        pageLength: 25,
        order: [[0, 'desc']]
    });

    // New reconciliation form submission
    $('#newReconciliationForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...');

        $.ajax({
            url: '/api/account/create_reconciliation.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#new-reconciliation-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        window.location.href = '/accounts/reconciliation_details?id=' + response.reconciliation_id;
                    }, 1500);
                } else {
                    $('#new-reconciliation-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Reconciliation');
                }
            },
            error: function(xhr, status, error) {
                $('#new-reconciliation-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Reconciliation');
                console.error('Error:', error);
            }
        });
    });

    // Import statement form submission
    $('#importStatementForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: '/api/account/import_bank_statement.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#import-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    if (response.results) {
                        let resultsHtml = '<div class="mt-2"><small>';
                        resultsHtml += 'Total rows: ' + response.results.total_rows + '<br>';
                        resultsHtml += 'Imported: ' + response.results.imported + '<br>';
                        resultsHtml += 'Failed: ' + response.results.failed + '<br>';
                        resultsHtml += 'Matched: ' + response.results.matched + '<br>';
                        if (response.results.errors && response.results.errors.length > 0) {
                            resultsHtml += '<strong>Errors:</strong><ul>';
                            response.results.errors.forEach(function(error) {
                                resultsHtml += '<li>' + error + '</li>';
                            });
                            resultsHtml += '</ul>';
                        }
                        resultsHtml += '</small></div>';
                        $('#import-message').append(resultsHtml);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $('#import-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
                }
            },
            error: function(xhr, status, error) {
                $('#import-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
                console.error('Error:', error);
            }
        });
    });

    // Load book balance when bank account is selected
    $('#reconciliation_bank_account_id').on('change', function() {
        const bankAccountId = $(this).val();
        if (bankAccountId) {
            $.ajax({
                url: '/api/account/get_bank_balance.php',
                type: 'GET',
                data: { bank_account_id: bankAccountId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#book_balance').val(response.book_balance);
                    }
                }
            });
        }
    });

    // Reset forms when modals are closed
    $('#newReconciliationModal').on('hidden.bs.modal', function() {
        $('#newReconciliationForm')[0].reset();
        $('#new-reconciliation-message').html('');
        $('#newReconciliationForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Reconciliation');
    });
    
    $('#importStatementModal').on('hidden.bs.modal', function() {
        $('#importStatementForm')[0].reset();
        $('#import-message').html('');
        $('#importStatementForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
    });
});

function clearFilters() {
    $('#bank_account_id').val('');
    $('#start_date').val('');
    $('#end_date').val('');
    $('#status').val('');
    window.location.href = 'bank_reconciliation.php';
}

function exportReconciliation() {
    // Trigger DataTable export
    $('#reconciliationsTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    // Create a CSV template file for bank statements
    const headers = [
        'transaction_date', 'value_date', 'description', 'reference', 'transaction_type',
        'amount', 'balance_after', 'category', 'counterparty_name', 'counterparty_account'
    ];
    
    const sampleData = [
        ['2023-10-01', '2023-10-01', 'Salary Payment', 'SAL-001', 'deposit', '500000.00', '1500000.00', 'income', 'ABC Company', '1234567890'],
        ['2023-10-02', '2023-10-02', 'Office Rent', 'RENT-001', 'withdrawal', '250000.00', '1250000.00', 'expense', 'Landlord Inc', '9876543210']
    ];
    
    let csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n";
    sampleData.forEach(function(row) {
        csvContent += row.join(',') + "\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "bank_statement_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function editReconciliation(reconciliationId) {
    window.location.href = 'reconciliation_edit.php?id=' + reconciliationId;
}

function finalizeReconciliation(reconciliationId) {
    if (!confirm('Are you sure you want to finalize this reconciliation? This action cannot be undone.')) {
        return;
    }

    $.ajax({
        url: '/api/account/finalize_reconciliation.php',
        type: 'POST',
        data: { reconciliation_id: reconciliationId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error finalizing reconciliation: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error finalizing reconciliation. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateStatus(reconciliationId, status) {
    const actionMap = {
        'disputed': 'mark as disputed',
        'cancelled': 'cancel',
        'reconciled': 'reconcile'
    };
    
    const action = actionMap[status] || 'update';
    
    if (!confirm('Are you sure you want to ' + action + ' this reconciliation?')) {
        return;
    }

    $.ajax({
        url: '/api/account/update_reconciliation_status.php',
        type: 'POST',
        data: { 
            reconciliation_id: reconciliationId,
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error updating status: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating status. Please try again.');
            console.error('Error:', error);
        }
    });
}
function deleteReconciliation(id) {
    if (confirm('Are you sure you want to delete this reconciliation?')) {
        $.ajax({
            url: '/api/account/delete_reconciliation.php',
            type: 'POST',
            data: { reconciliation_id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#reconciliationsTable').DataTable().ajax.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('An error occurred while deleting.');
            }
        });
    }
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.table thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
}
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.badge {
    font-size: 0.75em;
}

.table td, .table th {
    vertical-align: middle;
}

.dropdown-menu {
    min-width: 200px;
}

.dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-warning,
.card.bg-danger {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }

/* Print styles */
@media print {
    .navbar, .card-header, .btn, .dropdown, .dataTables_length, 
    .dataTables_filter, .dataTables_info, .dataTables_paginate, 
    .dt-buttons, .modal {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    table {
        width: 100% !important;
        font-size: 12px;
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
}

@media (max-width: 576px) {
    .btn-group {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .btn-group .btn {
        flex: 1;
    }
    
    .col-xl-3 {
        margin-bottom: 0.5rem;
    }
}
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>