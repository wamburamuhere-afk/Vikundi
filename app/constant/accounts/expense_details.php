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
autoEnforcePermission();

// Get Expense ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirectTo('accounts/expenses');
}

$expense_id = $_GET['id'];

// Fetch Expense Details
$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        ea.account_name as expense_account_name, 
        ba.account_name as bank_account_name,
        u.username as created_by_name, 
        u2.username as updated_by_name
    FROM expenses e 
    LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id 
    LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id 
    LEFT JOIN users u ON e.created_by = u.user_id 
    LEFT JOIN users u2 ON e.updated_by = u2.user_id
    WHERE e.expense_id = ?
");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch();

if (!$expense) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Expense not found. <a href='/accounts/expenses'>Return to list</a></div></div>";
    includeFooter();
    exit;
}

// Status Badge Helper
function get_expense_status_badge($status) {
    return match($status) {
        'paid' => 'success',
        'approved' => 'primary',
        'pending' => 'warning',
        'rejected' => 'danger',
        default => 'secondary'
    };
}

$statusClass = get_expense_status_badge($expense['status']);

?>

<div class="container-fluid mt-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="/accounts/expenses">Expenses</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Expense Details</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark">Expense Voucher #<?php echo str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT); ?></h2>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="/accounts/expenses" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <button onclick="window.print()" class="btn btn-outline-primary">
                <i class="bi bi-printer"></i> Print Voucher
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold text-dark">Expense Information</h5>
                    <span class="badge rounded-pill bg-<?php echo $statusClass; ?> px-3 py-2">
                        <i class="bi bi-circle-fill me-1 small"></i> <?php echo strtoupper($expense['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Description</label>
                            <p class="fs-5 fw-semibold text-dark mb-0"><?php echo htmlspecialchars($expense['description']); ?></p>
                        </div>
                        
                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Expense Date</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?php echo date('F d, Y', strtotime($expense['expense_date'])); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Expense Account</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-tag me-2 text-primary"></i><?php echo htmlspecialchars($expense['expense_account_name'] ?? ''); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Total Amount</label>
                            <p class="fs-4 fw-bold text-primary mb-0">
                                <?php echo number_format($expense['amount'], 2); ?> <small class="text-muted fs-6">TSh</small>
                            </p>
                        </div>

                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Vendor / Payee</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-shop me-2 text-primary"></i><?php echo htmlspecialchars($expense['vendor'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Bank Account</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-wallet2 me-2 text-primary"></i><?php echo htmlspecialchars($expense['bank_account_name'] ?? ''); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Reference No.</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-hash me-1 text-primary"></i><?php echo htmlspecialchars($expense['reference_number'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($expense['notes'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Notes & Remarks</h5>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded border-start border-4 border-primary">
                        <p class="mb-0 text-dark italic"><?php echo nl2br(htmlspecialchars($expense['notes'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approval History / Audit -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Audit Trail</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-3 text-uppercase small fw-bold text-muted">Action</th>
                                    <th class="py-3 text-uppercase small fw-bold text-muted">User</th>
                                    <th class="py-3 text-uppercase small fw-bold text-muted text-end pe-4">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <span class="badge bg-light text-dark border">Created</span>
                                    </td>
                                    <td class="py-3 fw-medium text-dark"><?php echo htmlspecialchars($expense['created_by_name'] ?? 'System'); ?></td>
                                    <td class="py-3 text-end text-muted pe-4"><?php echo date('M d, Y H:i', strtotime($expense['created_at'])); ?></td>
                                </tr>
                                <?php if ($expense['updated_at'] && $expense['updated_at'] != $expense['created_at']): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <span class="badge bg-light text-dark border">Last Modified</span>
                                    </td>
                                    <td class="py-3 fw-medium text-dark"><?php echo htmlspecialchars($expense['updated_by_name'] ?? 'System'); ?></td>
                                    <td class="py-3 text-end text-muted pe-4"><?php echo date('M d, Y H:i', strtotime($expense['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Quick Actions -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php if ($expense['status'] === 'pending' && canEdit('expenses')): ?>
                            <button onclick="updateStatus('approved')" class="btn btn-primary text-start">
                                <i class="bi bi-check-circle me-2"></i> Approve Expense
                            </button>
                            <button onclick="updateStatus('rejected')" class="btn btn-outline-danger text-start">
                                <i class="bi bi-x-circle me-2"></i> Reject Expense
                            </button>
                        <?php elseif ($expense['status'] === 'approved' && canEdit('expenses')): ?>
                            <button onclick="updateStatus('paid')" class="btn btn-success text-start">
                                <i class="bi bi-cash-coin me-2"></i> Mark as Paid
                            </button>
                        <?php endif; ?>

                        <?php if (canEdit('expenses')): ?>
                        <a href="#" onclick="editExpense(<?php echo $expense_id; ?>)" class="btn btn-light text-start border">
                            <i class="bi bi-pencil-square me-2 text-primary"></i> Edit Details
                        </a>
                        <?php endif; ?>
                        
                        <?php if (canDelete('expenses')): ?>
                        <button onclick="deleteExpense()" class="btn btn-light text-start border text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Expense
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Financial Impact Summary -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-down-arrow me-2"></i>Financial Impact</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small text-uppercase d-block mb-1">Account Category</label>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($expense['expense_account_name'] ?? ''); ?></span>
                        <div class="small text-muted mt-1">This will be debited as an expense.</div>
                    </div>
                    <div class="mb-0">
                        <label class="text-muted small text-uppercase d-block mb-1">Payment Source</label>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($expense['bank_account_name'] ?? ''); ?></span>
                        <div class="small text-muted mt-1">Funds will be credited from your <?php echo strtolower(htmlspecialchars($expense['bank_account_name'] ?? '')); ?> account.</div>
                    </div>
                </div>
            </div>

            <!-- System Info -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">System Metadata</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Internal ID</span>
                            <span class="font-monospace fw-bold">#EXP-<?php echo $expense['expense_id']; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Database Record</span>
                            <span class="text-dark">Row #<?php echo $expense['expense_id']; ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateStatus(newStatus) {
    if (!confirm('Are you sure you want to change the status to ' + newStatus + '?')) return;
    
    const formData = new FormData();
    formData.append('expense_id', '<?php echo $expense_id; ?>');
    formData.append('status', newStatus);

    fetch('/api/update_expense_status.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating status.');
    });
}

function deleteExpense() {
    if (!confirm('Are you sure you want to delete this expense? This action cannot be undone.')) return;
    
    const formData = new FormData();
    formData.append('expense_id', '<?php echo $expense_id; ?>');

    fetch('/api/delete_expense.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '/accounts/expenses?success=Expense deleted successfully';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting the expense.');
    });
}

function editExpense(expenseId) {
    // Redirect to expenses.php and trigger edit modal
    window.location.href = '/accounts/expenses?edit=' + expenseId;
}
</script>

<style>
    .card { border-radius: 12px; }
    .card-header:first-child { border-radius: 12px 12px 0 0; }
    .btn { border-radius: 8px; font-weight: 500; }
    .table thead th { font-size: 0.75rem; letter-spacing: 0.5px; }
    .italic { font-style: italic; }
    @media print {
        .col-lg-4, .breadcrumb, .btn-outline-secondary, .btn-outline-primary, .Quick-Actions { display: none !important; }
        .col-lg-8 { width: 100% !important; }
        .card { box-shadow: none !important; border: 1px solid #eee !important; }
        body { background: white !important; }
    }
</style>

<?php includeFooter(); ?>
