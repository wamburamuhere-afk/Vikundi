<?php
// File: invoices.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('invoices');

// Get filter parameters for initial dropdowns
global $pdo;
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

$status_filter = $_GET['status'] ?? '';
$customer_filter = $_GET['customer'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_filter = $_GET['payment_status'] ?? '';
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Invoices</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-receipt text-success"></i> Invoices</h2>
                    <p class="text-muted mb-0">Manage customer invoices and payments</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if (canCreate('invoices')): ?>
                    <a href="<?= getUrl('invoice_create') ?>" class="btn btn-primary btn-sm shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Invoice
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-success btn-sm shadow-sm" onclick="exportInvoices()">
                        <i class="bi bi-download"></i>
                    </button>
                    <a href="<?= getUrl('reports') ?>?report=invoice_summary" class="btn btn-outline-success btn-sm shadow-sm">
                        <i class="bi bi-graph-up"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="small fw-bold mb-1 text-uppercase">Total Invoices</p>
                            <h3 class="fw-bold mb-0" id="stat-total-invoices">0</h3>
                        </div>
                        <div class="text-secondary opacity-50">
                            <i class="bi bi-receipt" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="small fw-bold mb-1 text-uppercase">Total Paid</p>
                            <h3 class="fw-bold mb-0 text-success" id="stat-paid">0</h3>
                        </div>
                        <div class="text-success opacity-50">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="small fw-bold mb-1 text-uppercase">Pending/Sent</p>
                            <h3 class="fw-bold mb-0 text-warning" id="stat-pending">0</h3>
                        </div>
                        <div class="text-warning opacity-50">
                            <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0" style="background-color: #fdecea !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-danger small fw-bold mb-1 text-uppercase">Overdue</p>
                            <h3 class="text-danger mb-0 fw-bold" id="stat-overdue">0</h3>
                        </div>
                        <div class="text-danger opacity-50">
                            <i class="bi bi-exclamation-octagon" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 mt-3 text-end">
            <p class="text-muted small mb-0 fw-bold">TOTAL OUTSTANDING: <span class="text-danger fs-5 ms-2" id="stat-total-due">TSh 0.00</span></p>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <form id="filterForm" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="sent" <?= $status_filter == 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="partial" <?= $status_filter == 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="paid" <?= $status_filter == 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="overdue" <?= $status_filter == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Customer</label>
                    <select class="form-select form-select-sm" name="customer">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['customer_id'] ?>" <?= $customer_filter == $customer['customer_id'] ? 'selected' : '' ?>>
                                <?= safe_output($customer['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Date To</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoices Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="invoicesTable">
                    <thead class="bg-light text-uppercase small fw-bold text-muted">
                        <tr>
                            <th class="ps-4">Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#invoicesTable').DataTable({
        dom: 'rtip',
        pageLength: 25,
        order: [[1, 'desc']],
        ajax: {
            // Using absolute .php path as fallback for systems without robust routing
            url: '<?= buildUrl('api/account/get_invoices.php') ?>',
            data: function(d) {
                const formData = new FormData($('#filterForm')[0]);
                for (let [key, value] of formData.entries()) {
                    d[key] = value;
                }
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#stat-total-invoices').text(json.stats.total_invoices);
                    $('#stat-paid').text(json.stats.status_counts.paid || 0);
                    $('#stat-pending').text((json.stats.status_counts.pending || 0) + (json.stats.status_counts.sent || 0));
                    $('#stat-overdue').text(json.stats.status_counts.overdue || 0);
                    $('#stat-total-due').text(formatCurrency(json.stats.total_due));
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: 'invoice_number',
                className: 'ps-4',
                render: (data) => `<span class="fw-bold text-dark custom-code">${data}</span>`
            },
            { data: 'invoice_date' },
            { 
                data: 'customer_name',
                render: (data, t, row) => `<strong>${data}</strong>${row.company_name ? '<br><small class="text-muted">' + row.company_name + '</small>' : ''}`
            },
            { 
                data: 'grand_total',
                className: 'text-end',
                render: (data, t, row) => `<strong>${formatCurrency(data)}</strong> <small class="text-muted">${row.currency || ''}</small>`
            },
            { 
                data: 'balance_due',
                className: 'text-end',
                render: (data) => `<span class="${parseFloat(data) > 0 ? 'text-danger fw-bold' : 'text-success'}">${formatCurrency(data)}</span>`
            },
            { 
                data: 'display_status',
                render: (data) => {
                    const badges = {
                        'paid': 'bg-info',
                        'partial': 'bg-primary',
                        'overdue': 'bg-danger',
                        'sent': 'bg-success',
                        'pending': 'bg-warning text-dark',
                        'draft': 'bg-secondary'
                    };
                    return `<span class="badge rounded-pill ${badges[data] || 'bg-light'} text-uppercase">${data}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end pe-4',
                render: function(data, type, row) {
                    return `
                        <div class="btn-group">
                            <a href="<?= getUrl('invoice_view') ?>?id=${row.invoice_id}" class="btn btn-sm btn-light border">
                                <i class="bi bi-eye"></i>
                            </a>
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown"></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                ${['draft', 'pending'].includes(row.status) ? `<li><a class="dropdown-item" href="<?= getUrl('invoice_edit') ?>?id=${row.invoice_id}"><i class="bi bi-pencil me-2 text-primary"></i> Edit</a></li>` : ''}
                                <li><a class="dropdown-item" href="#" onclick="printInvoice(${row.invoice_id})"><i class="bi bi-printer me-2"></i> Print</a></li>
                                ${row.balance_due > 0 ? `<li><a class="dropdown-item text-success" href="<?= getUrl('payment_create') ?>?invoice=${row.invoice_id}"><i class="bi bi-cash-coin me-2"></i> Record Payment</a></li>` : ''}
                                <li><hr class="dropdown-divider"></li>
                                ${row.status === 'draft' ? `<li><a class="dropdown-item text-danger" href="#" onclick="deleteInvoice(${row.invoice_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>` : ''}
                            </ul>
                        </div>
                    `;
                }
            }
        ]
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });
});

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(v);
}

function printInvoice(id) {
    window.open('<?= getUrl('invoice_print') ?>?id=' + id, '_blank');
}

function deleteInvoice(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This draft invoice will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('api/account/delete_invoice.php') ?>', { invoice_id: id }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted!', res.message, 'success');
                    $('#invoicesTable').DataTable().ajax.reload();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
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
</style>

<?php includeFooter(); ?>