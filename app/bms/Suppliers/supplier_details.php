<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for supplier permissions
$can_view_suppliers = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Purchasing']);
if (!$can_view_suppliers) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get supplier ID
$supplier_id = $_GET['id'] ?? '';
if (empty($supplier_id)) {
    header("Location: suppliers.php?error=Supplier ID required");
    exit();
}

// Get supplier details
$stmt = $pdo->prepare("
    SELECT s.*,
           sc.category_name,
           u1.username as created_by_name,
           u2.username as updated_by_name
    FROM suppliers s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE s.supplier_id = ? AND s.status != 'deleted'
");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo "<div class='alert alert-danger'>Supplier not found</div>";
    include("footer.php");
    exit();
}

// Get purchase orders
$orders_stmt = $pdo->prepare("
    SELECT po.*, u.username as created_by_name
    FROM purchase_orders po
    LEFT JOIN users u ON po.created_by = u.user_id
    WHERE po.supplier_id = ?
    ORDER BY po.order_date DESC
    LIMIT 20
");
$orders_stmt->execute([$supplier_id]);
$purchase_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$payments_stmt = $pdo->prepare("
    SELECT sp.*, u.username as created_by_name
    FROM supplier_payments sp
    LEFT JOIN users u ON sp.created_by = u.user_id
    WHERE sp.supplier_id = ?
    ORDER BY sp.payment_date DESC
    LIMIT 20
");
$payments_stmt->execute([$supplier_id]);
$payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_orders = count($purchase_orders);
$total_spent = array_sum(array_column($purchase_orders, 'total_amount'));
$pending_orders = array_filter($purchase_orders, function($order) {
    return in_array($order['status'], ['pending', 'ordered']);
});
$pending_amount = array_sum(array_column($pending_orders, 'total_amount'));

// Helper functions removed, now in helpers.php
?>

<div class="container mt-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="suppliers.php">Suppliers</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($supplier['supplier_name']) ?></li>
        </ol>
    </nav>

    <!-- Supplier Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-truck"></i> <?= htmlspecialchars($supplier['supplier_name']) ?></h2>
                    <p class="text-muted mb-0">
                        <?php if (!empty($supplier['company_name'])): ?>
                        Company: <?= htmlspecialchars($supplier['company_name']) ?> •
                        <?php endif; ?>
                        Supplier Code: <code><?= htmlspecialchars($supplier['supplier_code']) ?></code>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="suppliers.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Suppliers
                    </a>
                    <?php if (in_array($user_role, ['Admin', 'Manager', 'Purchasing'])): ?>
                    <button class="btn btn-primary" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Info Cards -->
    <div class="row mb-4">
        <!-- Basic Info Card -->
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Basic Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                    <?= ucfirst($supplier['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($supplier['category_name'])): ?>
                        <tr>
                            <td><strong>Category:</strong></td>
                            <td><?= htmlspecialchars($supplier['category_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['contact_person'])): ?>
                        <tr>
                            <td><strong>Contact Person:</strong></td>
                            <td><?= htmlspecialchars($supplier['contact_person']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['contact_title'])): ?>
                        <tr>
                            <td><strong>Contact Title:</strong></td>
                            <td><?= htmlspecialchars($supplier['contact_title']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['tax_id'])): ?>
                        <tr>
                            <td><strong>Tax ID:</strong></td>
                            <td><code><?= htmlspecialchars($supplier['tax_id']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['payment_terms'])): ?>
                        <tr>
                            <td><strong>Payment Terms:</strong></td>
                            <td><?= ucfirst(str_replace('_', ' ', $supplier['payment_terms'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['currency'])): ?>
                        <tr>
                            <td><strong>Currency:</strong></td>
                            <td><?= htmlspecialchars($supplier['currency']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Created:</strong></td>
                            <td>
                                <?= htmlspecialchars($supplier['created_by_name']) ?>
                                <br>
                                <small class="text-muted"><?= format_date($supplier['created_at']) ?></small>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Contact Info Card -->
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-telephone"></i> Contact Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <?php if (!empty($supplier['email'])): ?>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td>
                                <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>">
                                    <?= htmlspecialchars($supplier['email']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['phone'])): ?>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td>
                                <a href="tel:<?= htmlspecialchars($supplier['phone']) ?>">
                                    <?= htmlspecialchars($supplier['phone']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['mobile'])): ?>
                        <tr>
                            <td><strong>Mobile:</strong></td>
                            <td>
                                <a href="tel:<?= htmlspecialchars($supplier['mobile']) ?>">
                                    <?= htmlspecialchars($supplier['mobile']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['fax'])): ?>
                        <tr>
                            <td><strong>Fax:</strong></td>
                            <td><?= htmlspecialchars($supplier['fax']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['website'])): ?>
                        <tr>
                            <td><strong>Website:</strong></td>
                            <td>
                                <a href="<?= htmlspecialchars($supplier['website']) ?>" target="_blank">
                                    <?= htmlspecialchars($supplier['website']) ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Address Card -->
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-geo-alt"></i> Address Information</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($supplier['address'])): ?>
                    <p><strong>Address:</strong><br>
                    <?= nl2br(htmlspecialchars($supplier['address'])) ?></p>
                    <?php endif; ?>
                    
                    <table class="table table-sm">
                        <?php if (!empty($supplier['city'])): ?>
                        <tr>
                            <td><strong>City:</strong></td>
                            <td><?= htmlspecialchars($supplier['city']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['state'])): ?>
                        <tr>
                            <td><strong>State/Region:</strong></td>
                            <td><?= htmlspecialchars($supplier['state']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['country'])): ?>
                        <tr>
                            <td><strong>Country:</strong></td>
                            <td><?= htmlspecialchars($supplier['country']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($supplier['postal_code'])): ?>
                        <tr>
                            <td><strong>Postal Code:</strong></td>
                            <td><?= htmlspecialchars($supplier['postal_code']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Information -->
    <?php if (!empty($supplier['bank_name']) || !empty($supplier['bank_account'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><i class="bi bi-bank"></i> Bank Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (!empty($supplier['bank_name'])): ?>
                        <div class="col-md-4">
                            <p><strong>Bank Name:</strong><br>
                            <?= htmlspecialchars($supplier['bank_name']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($supplier['bank_account'])): ?>
                        <div class="col-md-4">
                            <p><strong>Account Number:</strong><br>
                            <code><?= htmlspecialchars($supplier['bank_account']) ?></code></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($supplier['bank_address'])): ?>
                        <div class="col-md-4">
                            <p><strong>Bank Address:</strong><br>
                            <?= nl2br(htmlspecialchars($supplier['bank_address'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Description -->
    <?php if (!empty($supplier['description'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-chat-text"></i> Description</h6>
                </div>
                <div class="card-body">
                    <?= nl2br(htmlspecialchars($supplier['description'])) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= $total_orders ?></h4>
                    <p class="mb-0">Total Orders</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= format_currency($total_spent) ?></h4>
                    <p class="mb-0">Total Spent</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-dark"><div class="card-body text-center">
                    <h4 class="mb-0"><?= count($pending_orders) ?></h4>
                    <p class="mb-0">Pending Orders</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= format_currency($pending_amount) ?></h4>
                    <p class="mb-0">Pending Amount</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase Orders -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-cart"></i> Recent Purchase Orders</h6>
                        <a href="purchase_orders.php?supplier=<?= $supplier_id ?>" class="btn btn-light btn-sm">
                            View All
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($purchase_orders) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($purchase_orders as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="purchase_order_details.php?id=<?= $order['purchase_order_id'] ?>">
                                                <?= htmlspecialchars($order['order_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= format_date($order['order_date']) ?></td>
                                        <td><?= format_currency($order['total_amount']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= get_order_status_badge($order['status']) ?>">
                                                <?= ucfirst($order['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center mb-0">No purchase orders found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-cash"></i> Recent Payments</h6>
                        <a href="supplier_payments.php?id=<?= $supplier_id ?>" class="btn btn-light btn-sm">
                            View All
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($payments) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= format_date($payment['payment_date']) ?></td>
                                        <td><?= htmlspecialchars($payment['reference_number']) ?></td>
                                        <td><?= format_currency($payment['amount']) ?></td>
                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center mb-0">No payment history found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function editSupplier(supplierId) {
    // Load supplier data and open edit modal
    $.ajax({
        url: 'api/get_supplier.php',
        type: 'GET',
        data: { id: supplierId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Redirect to edit page or open modal
                // For now, redirect to suppliers.php with edit parameter
                window.location.href = 'suppliers.php?edit=' + supplierId;
            } else {
                alert('Error loading supplier data: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading supplier data. Please try again.');
        }
    });
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}

.badge {
    font-size: 0.75em;
}

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

<?php
include("footer.php");
ob_end_flush();
?>