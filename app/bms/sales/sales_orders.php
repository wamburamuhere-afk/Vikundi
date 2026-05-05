<?php
// File: sales_orders.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for sales order permissions
$can_view_sales_orders = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Sales']);
$can_create_sales_orders = in_array($user_role, ['Admin', 'Manager', 'Sales']);
$can_edit_sales_orders = in_array($user_role, ['Admin', 'Manager', 'Sales']);
$can_delete_sales_orders = in_array($user_role, ['Admin']);
$can_approve_sales_orders = in_array($user_role, ['Admin', 'Manager']);

if (!$can_view_sales_orders) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$salesperson_filter = isset($_GET['salesperson']) ? intval($_GET['salesperson']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$payment_filter = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';

// Build query with filters
$query = "
    SELECT 
        so.*,
        c.customer_name,
        c.company_name,
        c.phone as customer_phone,
        c.email as customer_email,
        u1.username as created_by_name,
        u2.username as salesperson_name,
        u3.username as updated_by_name,
        COUNT(soi.order_item_id) as total_items,
        SUM(soi.quantity * soi.unit_price) as subtotal,
        SUM(soi.quantity * soi.unit_price * soi.tax_rate / 100) as tax_amount,
        SUM(soi.quantity * soi.unit_price * (1 + soi.tax_rate / 100)) as grand_total,
        SUM(soi.quantity_delivered) as total_delivered,
        SUM(soi.quantity_invoiced) as total_invoiced,
        COUNT(DISTINCT i.invoice_id) as invoice_count,
        COALESCE(SUM(p.amount), 0) as total_paid,
        CASE 
            WHEN so.status = 'cancelled' THEN 'cancelled'
            WHEN so.status = 'completed' THEN 'completed'
            WHEN so.status = 'delivered' THEN 'delivered'
            WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 'partially_delivered'
            WHEN so.status = 'approved' THEN 'approved'
            WHEN so.status = 'pending' THEN 'pending'
            ELSE 'draft'
        END as display_status
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN users u1 ON so.created_by = u1.user_id
    LEFT JOIN users u2 ON so.salesperson_id = u2.user_id
    LEFT JOIN users u3 ON so.updated_by = u3.user_id
    LEFT JOIN sales_order_items soi ON so.sales_order_id  = soi.order_id
    LEFT JOIN invoices i ON so.sales_order_id  = i.order_id AND i.status != 'cancelled'
    LEFT JOIN payments p ON i.invoice_id = p.invoice_id AND p.status = 'completed'
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($status_filter)) {
    $query .= " AND so.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter > 0) {
    $query .= " AND so.customer_id = ?";
    $params[] = $customer_filter;
}

if ($salesperson_filter > 0) {
    $query .= " AND so.salesperson_id = ?";
    $params[] = $salesperson_filter;
}

if (!empty($date_from)) {
    $query .= " AND so.order_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND so.order_date <= ?";
    $params[] = $date_to;
}

$query .= " GROUP BY so.sales_order_id ORDER BY so.order_date DESC, so.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get data for filter dropdowns
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
$salespeople = $pdo->query("SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = '1' AND role IN ('Admin', 'Manager', 'Sales') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_orders = count($orders);
$total_value = array_sum(array_column($orders, 'grand_total'));

// Group by status for statistics
$status_counts = [
    'draft' => 0,
    'pending' => 0,
    'approved' => 0,
    'processing' => 0,
    'partially_delivered' => 0,
    'delivered' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($orders as $order) {
    $status = $order['display_status'] ?? $order['status'];
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// Helper functions removed, now in helpers.php
function get_payment_status($paid, $total) {
    if ($total == 0) return 'secondary';
    if ($paid >= $total) return 'success';
    if ($paid > 0) return 'warning';
    return 'danger';
}

function get_payment_status_text($paid, $total) {
    if ($total == 0) return 'No Payment';
    if ($paid >= $total) return 'Paid';
    if ($paid > 0) return 'Partial';
    return 'Unpaid';
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-cart-check"></i> Sales Orders</h2>
                    <p class="text-muted mb-0">Manage customer sales orders and deliveries</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_create_sales_orders): ?>
                    <a href="<?= getUrl('sales_order_create') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> New Sales Order
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="exportOrders()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <a href="<?= getUrl('reports') ?>?report=sales_summary" class="btn btn-primary">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-dark bg-opacity-10 p-3 rounded">
                                <i class="bi bi-cart shadow-sm text-dark fs-3"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Total Orders</h6>
                            <h4 class="mb-0 fw-bold" id="stat-total-orders">0</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-dark bg-opacity-10 p-3 rounded">
                                <i class="bi bi-clock-history shadow-sm text-dark fs-3"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Pending Orders</h6>
                            <h4 class="mb-0 fw-bold" id="stat-pending-orders">0</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-dark bg-opacity-10 p-3 rounded">
                                <i class="bi bi-gear shadow-sm text-dark fs-3"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Processing</h6>
                            <h4 class="mb-0 fw-bold" id="stat-processing-orders">0</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <div class="bg-dark bg-opacity-10 p-3 rounded">
                                <i class="bi bi-cash-stack shadow-sm text-dark fs-3"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">Total Sales Value</h6>
                            <h4 class="mb-0 fw-bold" id="stat-total-value">0.00</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="filterForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                            <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="processing" <?= $status_filter == 'processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="partially_delivered" <?= $status_filter == 'partially_delivered' ? 'selected' : '' ?>>Partially Delivered</option>
                            <option value="delivered" <?= $status_filter == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select class="form-select" name="customer">
                            <option value="">All Customers</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['customer_id'] ?>" <?= $customer_filter == $customer['customer_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($customer['customer_name']) ?>
                                    <?php if (!empty($customer['company_name'])): ?>
                                        (<?= safe_output($customer['company_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Salesperson</label>
                        <select class="form-select" name="salesperson">
                            <option value="">All Salespeople</option>
                            <?php foreach ($salespeople as $salesperson): ?>
                                <option value="<?= $salesperson['user_id'] ?>" <?= $salesperson_filter == $salesperson['user_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($salesperson['username']) ?>
                                    <?php if (!empty($salesperson['full_name'])): ?>
                                        (<?= safe_output($salesperson['full_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Status</label>
                        <select class="form-select" name="payment_status">
                            <option value="">All Payment Status</option>
                            <option value="unpaid" <?= $payment_filter == 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="partial" <?= $payment_filter == 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                            <option value="paid" <?= $payment_filter == 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                        <a href="sales_orders.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sales Orders Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="bi bi-list-ul"></i> Sales Orders List</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary" onclick="loadOrders()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <?php if ($can_create_sales_orders): ?>
                <a href="sales_order_create" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i> New Order
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="salesOrdersTable" style="width:100%">
                    <thead class="bg-light">
                        <tr>
                            <th class="py-3">Order #</th>
                            <th class="py-3">Date</th>
                            <th class="py-3">Customer</th>
                            <th class="py-3">Salesperson</th>
                            <th class="py-3 text-center">Items</th>
                            <th class="py-3 text-end">Total Amount</th>
                            <th class="py-3 text-center">Status</th>
                            <th class="py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<?php if ($can_create_sales_orders): ?>
<div class="fixed-bottom d-flex justify-content-end p-3" style="z-index: 1030;">
    <div class="btn-group shadow">
        <a href="sales_order_create" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> New Order
        </a>
        <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="sales_order_create"><i class="bi bi-cart-plus"></i> Sales Order</a></li>
            <li><a class="dropdown-item" href="quick_sale"><i class="bi bi-lightning"></i> Quick Sale</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="customers"><i class="bi bi-people"></i> Manage Customers</a></li>
            <li><a class="dropdown-item" href="products"><i class="bi bi-box"></i> View Products</a></li>
        </ul>
    </div>
</div>
<?php endif; ?>



<script>
let ordersTable;
const canEdit = <?= json_encode($can_edit_sales_orders) ?>;
const canDelete = <?= json_encode($can_delete_sales_orders) ?>;
const canApprove = <?= json_encode($can_approve_sales_orders) ?>;

$(document).ready(function() {
    initTable();
    
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadOrders();
    });
});

function initTable() {
    ordersTable = $('#salesOrdersTable').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: buildUrl('api/account/get_sales_orders.php'),
            data: function(d) {
                return $.extend({}, d, {
                    status: $('select[name="status"]').val(),
                    customer: $('select[name="customer"]').val(),
                    salesperson: $('select[name="salesperson"]').val(),
                    payment_status: $('select[name="payment_status"]').val(),
                    date_from: $('input[name="date_from"]').val(),
                    date_to: $('input[name="date_to"]').val()
                });
            },
            dataSrc: function(json) {
                if (json.success) {
                    updateStats(json.stats);
                    return json.data;
                }
                return [];
            }
        },
        columns: [
            { 
                data: 'order_number',
                render: function(data, type, row) {
                    return `<strong class="custom-code"><code>${data}</code></strong>${row.reference ? `<br><small class="text-muted">Ref: ${row.reference}</small>` : ''}`;
                }
            },
            { 
                data: 'order_date',
                render: function(data) { return data ? data : ''; }
            },
            { 
                data: 'customer_name',
                render: function(data, type, row) {
                    return `<strong>${data}</strong>${row.company_name ? `<br><small class="text-muted">${row.company_name}</small>` : ''}`;
                }
            },
            { 
                data: 'salesperson_name',
                render: function(data, type, row) {
                    return `<span>${data || 'N/A'}</span>${row.created_by_name ? `<br><small class="text-muted">By: ${row.created_by_name}</small>` : ''}`;
                }
            },
            { 
                data: 'total_items',
                className: 'text-center',
                render: function(data) {
                    return `<span class="badge bg-secondary rounded-pill">${data}</span>`;
                }
            },
            { 
                data: 'grand_total',
                className: 'text-end',
                render: function(data, type, row) {
                    return `<strong>${formatCurrency(data, row.currency)}</strong>`;
                }
            },
            { 
                data: 'display_status',
                className: 'text-center',
                render: function(data) {
                    const badgeClass = getStatusBadgeClass(data);
                    return `<span class="badge bg-${badgeClass}">${data.charAt(0).toUpperCase() + data.slice(1).replace('_', ' ')}</span>`;
                }
            },
            {
                data: null,
                className: 'text-center',
                orderable: false,
                render: function(data, type, row) {
                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu shadow">
                                <li><a class="dropdown-item" href="sales_order_view?id=${row.sales_order_id}"><i class="bi bi-eye text-primary"></i> View Details</a></li>
                    `;

                    if (canEdit && (row.status === 'draft' || row.status === 'pending')) {
                        actions += `<li><a class="dropdown-item" href="sales_order_edit?id=${row.sales_order_id}"><i class="bi bi-pencil text-info"></i> Edit Order</a></li>`;
                    }

                    if (canApprove && row.status === 'pending') {
                        actions += `<li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="updateOrderStatus(${row.sales_order_id}, 'approved')"><i class="bi bi-check-circle"></i> Approve Order</a></li>`;
                    }

                    if (canEdit && (row.status === 'approved' || row.status === 'processing' || row.status === 'partially_delivered')) {
                        actions += `<li><a class="dropdown-item" href="invoice_create?id=${row.sales_order_id}"><i class="bi bi-receipt text-success"></i> Create Invoice</a></li>`;
                    }

                    if (canEdit && ['draft', 'pending', 'approved', 'processing'].includes(row.status)) {
                        actions += `<li><a class="dropdown-item text-warning" href="javascript:void(0)" onclick="updateOrderStatus(${row.sales_order_id}, 'cancelled')"><i class="bi bi-x-octagon"></i> Cancel Order</a></li>`;
                    }

                    if (canDelete && row.status === 'draft') {
                        actions += `<li><hr class="dropdown-divider"></li>`;
                        actions += `<li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="confirmDeleteOrder(${row.sales_order_id})"><i class="bi bi-trash"></i> Delete Order</a></li>`;
                    }

                    actions += `</ul></div>`;
                    return actions;
                }
            }
        ],
        order: [[1, 'desc']],
        pageLength: 25,
        language: {
            processing: '<div class="spinner-border text-primary" role="status"><span></span></div>'
        }
    });
}

function loadOrders() {
    ordersTable.ajax.reload();
}

function updateStats(stats) {
    if (!stats) return;
    $('#stat-total-orders').text(stats.total_orders || 0);
    $('#stat-pending-orders').text(stats.pending_count || 0);
    $('#stat-processing-orders').text((stats.approved_count || 0) + (stats.processing_count || 0));
    $('#stat-total-value').text(formatCurrency(stats.total_value || 0));
}

function getStatusBadgeClass(status) {
    switch(status) {
        case 'completed': return 'success';
        case 'delivered': return 'success';
        case 'approved': return 'info';
        case 'processing': return 'primary';
        case 'pending': return 'warning';
        case 'partially_delivered': return 'warning';
        case 'cancelled': return 'danger';
        case 'draft': return 'secondary';
        default: return 'secondary';
    }
}

function updateOrderStatus(orderId, status) {
    Swal.fire({
        title: 'Update Status?',
        text: `Are you sure you want to change order status to ${status}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Update'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: buildUrl('api/account/update_sales_order_status.php'),
                type: 'POST',
                data: { order_id: orderId, status: status },
                success: function(response) {
                    if (response.success) {
                        toast('Success', response.message, 'success');
                        loadOrders();
                    } else {
                        toast('Error', response.message, 'error');
                    }
                }
            });
        }
    });
}

function confirmDeleteOrder(orderId) {
    Swal.fire({
        title: 'Delete Order?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: buildUrl('api/account/delete_sales_order.php'),
                type: 'POST',
                data: { order_id: orderId },
                success: function(response) {
                    if (response.success) {
                        toast('Deleted!', 'Order has been deleted.', 'success');
                        loadOrders();
                    } else {
                        toast('Error', response.message, 'error');
                    }
                }
            });
        }
    });
}

function formatCurrency(amount, currency = 'TZS') {
    return currency + ' ' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function toast(title, message, icon) {
    Swal.fire({
        title: title,
        text: message,
        icon: icon,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000
    });
}

function exportOrders() {
    const params = $.param({
        status: $('select[name="status"]').val(),
        customer: $('select[name="customer"]').val(),
        salesperson: $('select[name="salesperson"]').val(),
        payment_status: $('select[name="payment_status"]').val(),
        date_from: $('input[name="date_from"]').val(),
        date_to: $('input[name="date_to"]').val()
    });
    window.location.href = buildUrl('api/account/export_sales_orders.php?' + params);
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
.custom-stat-card h6,
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
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
}
.badge {
    padding: 0.5em 0.8em;
}
.dropdown-item {
    padding: 0.5rem 1rem;
}
.dropdown-item i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
}
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>