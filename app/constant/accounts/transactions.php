<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
includeHeader();

// Enforce permission
autoEnforcePermission('transactions');

// Fetch transactions (journal entries) with related data
$stmt = $pdo->query("
    SELECT 
        je.*,
        u.username as created_by_name,
        (SELECT COALESCE(SUM(amount), 0) FROM journal_entry_items WHERE entry_id = je.entry_id AND type = 'debit') as total_amount
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.user_id
    ORDER BY je.entry_date DESC, je.created_at DESC
");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch accounts for filters and modal
$accounts = $pdo->query("
    SELECT ca.*, at.type_name as account_type 
    FROM accounts ca 
    LEFT JOIN account_types at ON ca.account_type_id = at.type_id 
    WHERE ca.status = 'active' 
    ORDER BY ca.account_name
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_transactions = 0;
$current_month_total = 0;
$current_year_total = 0;
$current_month = date('Y-m');
$current_year = date('Y');

foreach ($transactions as $transaction) {
    $amount = $transaction['total_amount'];
    $total_transactions += $amount;
    
    if (date('Y-m', strtotime($transaction['entry_date'])) === $current_month) {
        $current_month_total += $amount;
    }
    
    if (date('Y', strtotime($transaction['entry_date'])) === $current_year) {
        $current_year_total += $amount;
    }
}


?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-arrow-left-right"></i> Transactions Management</h2>
                    <p class="text-muted mb-0">Track and manage all financial transactions</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="bi bi-plus-circle"></i> Add New Transaction
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="exportTransactions()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($total_transactions) ?></h4>
                            <p class="mb-0">Total Volume</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($current_month_total) ?></h4>
                            <p class="mb-0">This Month</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-month" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($current_year_total) ?></h4>
                            <p class="mb-0">This Year</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-year" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($transactions) ?></h4>
                            <p class="mb-0">Total Records</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-receipt" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="posted">Posted</option>
                        <option value="void">Void</option>
                        <option value="reversed">Reversed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFromFilter">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateToFilter">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                    <button type="button" class="btn btn-primary" onclick="applyFilters()">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="searchTransactions" placeholder="Search by description, reference, or amount...">
                </div>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Transactions</h5>
                <div class="d-flex">
                    <span class="badge bg-light text-dark me-2">
                        <?= count($transactions) ?> records
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($transactions) > 0): ?>
                <div class="table-responsive">
                    <table id="transactionsTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Reference</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <strong><?= date('M d, Y', strtotime($transaction['entry_date'])) ?></strong>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($transaction['description']) ?></strong>
                                        <?php if (!empty($transaction['notes'])): ?>
                                        <br>
                                        <small class="text-muted"><?= safe_output(substr($transaction['notes'], 0, 50)) ?>...</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <code><?= safe_output($transaction['reference_number']) ?></code>
                                </td>
                                <td>
                                    <strong><?= format_currency($transaction['total_amount']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($transaction['status']) ?>">
                                        <?= ucfirst($transaction['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= safe_output($transaction['created_by_name']) ?>
                                    <br>
                                    <small class="text-muted"><?= date('M d', strtotime($transaction['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="dropdown action-dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="/accounts/transaction_details?id=<?= $transaction['entry_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($transaction['status'] === 'draft'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editTransaction(<?= $transaction['entry_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Transaction
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateStatus(<?= $transaction['entry_id'] ?>, 'posted')">
                                                    <i class="bi bi-check-circle"></i> Post
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($transaction['status'] === 'posted'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateStatus(<?= $transaction['entry_id'] ?>, 'reversed')">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Reverse
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $transaction['entry_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-arrow-left-right" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Transactions Found</h4>
                    <p class="text-muted">Get started by recording your first transaction.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Transaction
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addTransactionModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Transaction
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addTransactionForm">
                <div class="modal-body">
                    <div id="add-transaction-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="entry_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="debit_account_id" class="form-label">Debit Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="debit_account_id" name="debit_account_id" required>
                                <option value="">Select Debit Account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= $account['account_id'] ?>"><?= safe_output($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="credit_account_id" class="form-label">Credit Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="credit_account_id" name="credit_account_id" required>
                                <option value="">Select Credit Account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= $account['account_id'] ?>"><?= safe_output($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="description" name="description" required placeholder="Brief description of the transaction">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="reference_number" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Transaction ID, Receipt #, etc.">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes or details"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="draft" selected>Draft</option>
                                <option value="posted">Posted</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="/assets/js/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="/assets/css/bootstrap-icons.css">

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#transactionsTable').DataTable({
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ transactions per page",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
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
                extend: 'copyHtml5',
                text: '<i class="bi bi-clipboard"></i> Copy',
                titleAttr: 'Copy to clipboard'
            },
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-excel"></i> Excel',
                titleAttr: 'Export to Excel',
                title: 'Transactions_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-text"></i> CSV',
                titleAttr: 'Export to CSV',
                title: 'Transactions_List_' + new Date().toISOString().slice(0,10)
            }
        ]
    });

    // Add transaction form submission
    $('#addTransactionForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: '/api/add_transaction.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-transaction-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-transaction-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
                }
            },
            error: function() {
                $('#add-transaction-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
            }
        });
    });

    // Reset form when modal is closed
    $('#addTransactionModal').on('hidden.bs.modal', function() {
        $('#addTransactionForm')[0].reset();
        $('#add-transaction-message').html('');
        $('#addTransactionForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
        
        // Reset edit mode
        $('#addTransactionModalLabel').html('<i class="bi bi-plus-circle"></i> Add New Transaction');
        $('#addTransactionForm').attr('id', 'addTransactionForm');
        $('input[name="entry_id"]').remove();
    });
});

function applyFilters() {
    const table = $('#transactionsTable').DataTable();
    
    // Status filter
    const status = $('#statusFilter').val();
    table.column(4).search(status).draw();
    
    // Date range filter
    const dateFrom = $('#dateFromFilter').val();
    const dateTo = $('#dateToFilter').val();
    
    if (dateFrom || dateTo) {
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                const date = new Date(data[0]);
                const from = dateFrom ? new Date(dateFrom) : null;
                const to = dateTo ? new Date(dateTo) : null;
                
                if ((from === null && to === null) ||
                    (from === null && date <= to) ||
                    (from <= date && to === null) ||
                    (from <= date && date <= to)) {
                    return true;
                }
                return false;
            }
        );
        table.draw();
        $.fn.dataTable.ext.search.pop();
    }
    
    // Search filter
    const search = $('#searchTransactions').val();
    table.search(search).draw();
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#dateFromFilter').val('');
    $('#dateToFilter').val('');
    $('#searchTransactions').val('');
    
    const table = $('#transactionsTable').DataTable();
    table.search('').columns().search('').draw();
}

function viewTransaction(entryId) {
    // Redirect to transaction details page
    window.location.href = '/accounts/transaction-details?id=' + entryId;
}

function editTransaction(entryId) {
    // Load transaction data and open edit modal
    $.ajax({
        url: '/api/get_transaction.php',
        type: 'GET',
        data: { id: entryId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate form and show modal
                $('#entry_date').val(response.data.entry_date);
                $('#amount').val(response.data.amount);
                $('#debit_account_id').val(response.data.debit_account_id);
                $('#credit_account_id').val(response.data.credit_account_id);
                $('#description').val(response.data.description);
                $('#reference_number').val(response.data.reference_number);
                $('#notes').val(response.data.notes);
                $('#status').val(response.data.status);
                
                // Change modal to edit mode
                $('#addTransactionModalLabel').html('<i class="bi bi-pencil"></i> Edit Transaction');
                $('#addTransactionForm').attr('id', 'editTransactionForm');
                $('#editTransactionForm').append('<input type="hidden" name="entry_id" value="' + entryId + '">');
                $('#editTransactionForm [type="submit"]').html('<i class="bi bi-check-circle"></i> Update Transaction');
                
                // Update form submission for edit
                $('#editTransactionForm').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    updateTransaction(entryId, $(this).serialize());
                });
                
                $('#addTransactionModal').modal('show');
            } else {
                alert('Error loading transaction data: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading transaction data. Please try again.');
        }
    });
}

function updateTransaction(entryId, formData) {
    const submitBtn = $('#editTransactionForm [type="submit"]');
    
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

    $.ajax({
        url: '/api/update_transaction.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#add-transaction-message').html('<div class="alert alert-success">' + response.message + '</div>');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                $('#add-transaction-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Transaction');
            }
        },
        error: function() {
            $('#add-transaction-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
            submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Transaction');
        }
    });
}

function updateStatus(entryId, status) {
    if (!confirm('Are you sure you want to ' + status + ' this transaction?')) {
        return;
    }

    $.ajax({
        url: '/api/update_transaction_status.php',
        type: 'POST',
        data: { 
            entry_id: entryId,
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
        error: function() {
            alert('Error updating status. Please try again.');
        }
    });
}

function confirmDelete(entryId) {
    if (confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
        $.ajax({
            url: '/api/delete_transaction.php',
            method: 'POST',
            data: { entry_id: entryId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error deleting transaction: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting transaction. Please try again.');
            }
        });
    }
}

function exportTransactions() {
    window.location.href = '/api/export_transactions.php';
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 180px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:last-child {
        align-self: stretch;
    }
}
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>
