<?php
// File: purchase_orders.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('purchase_orders');

// Get filter defaults
$supplier_id = $_GET['supplier'] ?? '';
$status = $_GET['status'] ?? '';

// Get suppliers for filter dropdown
global $pdo;
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Purchase Orders</li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-cart-check text-success"></i> Purchase Orders</h2>
                    <p class="text-muted">Procurement and stock replenishment management</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('purchase_order_create') ?>" class="btn btn-success shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> Create Order
                    </a>
                    <button type="button" class="btn btn-outline-success shadow-sm" onclick="exportOrders()">
                        <i class="bi bi-download"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="row mb-4 g-3">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card custom-stat-card-light border-0"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-success-dark small fw-bold mb-1 text-uppercase">Total Orders</p>
                            <h3 class="mb-0 fw-bold" id="stat-total-orders">0</h3>
                        </div>
                        <div class="stat-icon bg-success text-white">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card custom-stat-card-light border-0"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-primary-dark small fw-bold mb-1 text-uppercase">Total Value</p>
                            <h3 class="mb-0 fw-bold" id="stat-total-amount">TSh 0.00</h3>
                        </div>
                        <div class="stat-icon bg-primary text-white">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card custom-stat-card-light border-0"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-warning-dark small fw-bold mb-1 text-uppercase">Pending Orders</p>
                            <h3 class="mb-0 fw-bold" id="stat-pending-orders">0</h3>
                        </div>
                        <div class="stat-icon bg-warning text-white">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card custom-stat-card-light border-0"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-info-dark small fw-bold mb-1 text-uppercase">Approved Value</p>
                            <h3 class="mb-0 fw-bold" id="stat-approved-amount">TSh 0.00</h3>
                        </div>
                        <div class="stat-icon bg-info text-white">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <form id="filterForm" class="row g-2">
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="status">
                        <option value="">All Statuses</option>
                        <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="pending" <?= $status == 'pending' || !$status ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="ordered" <?= $status == 'ordered' ? 'selected' : '' ?>>Ordered</option>
                        <option value="received" <?= $status == 'received' ? 'selected' : '' ?>>Received</option>
                        <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select form-select-sm" name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>" <?= $supplier_id == $s['supplier_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" name="date_from" placeholder="From">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" name="date_to" placeholder="To">
                </div>
                <div class="col-md-2">
                    <div class="d-grid gap-2 d-md-flex">
                        <button type="submit" class="btn btn-success btn-sm flex-grow-1">Filter</button>
                        <button type="button" class="btn btn-light btn-sm" onclick="clearFilters()"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="purchaseOrdersTable">
                    <thead class="bg-light text-uppercase small fw-bold">
                        <tr>
                            <th class="ps-4">Order #</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th class="text-end">Total Amount</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <!-- DataTables content -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    const table = $('#purchaseOrdersTable').DataTable({
        dom: 'rtip',
        serverSide: false, // Set to true if dataset grows very large
        ajax: {
            url: '<?= buildUrl('api/account/get_purchase_orders.php') ?>',
            data: function(d) {
                d.status = $('select[name="status"]').val();
                d.supplier = $('select[name="supplier"]').val();
                d.date_from = $('input[name="date_from"]').val();
                d.date_to = $('input[name="date_to"]').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#stat-total-orders').text(json.stats.total_orders);
                    $('#stat-total-amount').text(formatCurrency(json.stats.total_amount));
                    $('#stat-pending-orders').text(json.stats.pending_count);
                    $('#stat-approved-amount').text(formatCurrency(json.stats.approved_amount || 0));
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: 'order_number',
                className: 'ps-4',
                render: (data, t, row) => `<span class="fw-bold text-dark">${data}</span>`
            },
            { data: 'supplier_name' },
            { data: 'order_date' },
            { 
                data: 'grand_total',
                className: 'text-end',
                render: (data, t, row) => `<strong>${formatCurrency(data)}</strong> <small class="text-muted">${row.currency}</small>`
            },
            { 
                data: 'status',
                render: data => {
                    const badges = {
                        'draft': 'bg-secondary',
                        'pending': 'bg-warning text-dark',
                        'approved': 'bg-success',
                        'ordered': 'bg-info text-dark',
                        'received': 'bg-primary'
                    };
                    return `<span class="badge rounded-pill ${badges[data] || 'bg-light text-dark'} text-uppercase">${data}</span>`;
                }
            },
            { 
                data: null,
                className: 'text-end pe-4',
                render: function(data, type, row) {
                    return `
                        <div class="btn-group">
                            <a href="<?= getUrl('purchase_order_details') ?>?id=${row.purchase_order_id}" class="btn btn-sm btn-light border" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown"></button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_order_create') ?>?edit=${row.purchase_order_id}"><i class="bi bi-pencil me-2 text-primary"></i> Edit</a></li>
                                <li><a class="dropdown-item" href="#" onclick="printOrder(${row.purchase_order_id})"><i class="bi bi-printer me-2 text-dark"></i> Print</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="cancelOrder(${row.purchase_order_id})"><i class="bi bi-x-circle me-2"></i> Cancel</a></li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        order: [[0, 'desc']]
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });
});

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(v);
}

function clearFilters() {
    $('#filterForm')[0].reset();
    $('#purchaseOrdersTable').DataTable().ajax.reload();
}

function printOrder(id) {
    window.open('<?= getUrl('print_purchase_order') ?>?id=' + id, '_blank');
}
</script>

<style>
:root {
    --bs-success-light: #e8f5e9;
    --bs-success-dark: #2e7d32;
    --bs-primary-light: #e3f2fd;
    --bs-primary-dark: #1565c0;
    --bs-warning-light: #fff8e1;
    --bs-warning-dark: #f57f17;
    --bs-info-light: #e0f7fa;
    --bs-info-dark: #00838f;
}
.bg-success-light { background-color: var(--bs-success-light); }
.bg-primary-light { background-color: var(--bs-primary-light); }
.bg-warning-light { background-color: var(--bs-warning-light); }
.bg-info-light { background-color: var(--bs-info-light); }
.text-success-dark { color: var(--bs-success-dark); }
.text-primary-dark { color: var(--bs-primary-dark); }
.text-warning-dark { color: var(--bs-warning-dark); }
.text-info-dark { color: var(--bs-info-dark); }

.stat-card { border-radius: 1rem; transition: transform 0.2s ease; }
.stat-card:hover { transform: translateY(-5px); }
.stat-icon { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }

.table thead th { border-bottom: 0; padding: 1rem 0.5rem; }
.card { border-radius: 0.75rem; }

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
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
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php includeFooter(); ?>

