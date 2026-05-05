<?php
// File: grn.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for GRN permissions
$can_view_grn = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Purchasing', 'Storekeeper']);
$can_create_grn = in_array($user_role, ['Admin', 'Manager', 'Purchasing', 'Storekeeper']);
$can_approve_grn = in_array($user_role, ['Admin', 'Manager']);
$can_delete_grn = in_array($user_role, ['Admin']);

if (!$can_view_grn) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$po_filter = isset($_GET['po']) ? intval($_GET['po']) : 0;

// Build query with filters
$query = "
    SELECT 
        pr.*,
        s.supplier_name,
        s.company_name,
        w.warehouse_name,
        po.order_number,
        u1.username as received_by_name,
        u2.username as created_by_name,
        COUNT(ri.receipt_item_id) as total_items,
        SUM(ri.quantity_received * ri.unit_price) as total_value,
        GROUP_CONCAT(DISTINCT p.product_name SEPARATOR ', ') as product_names
    FROM purchase_receipts pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
    LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
    LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
    LEFT JOIN receipt_items ri ON pr.receipt_id = ri.receipt_id
    LEFT JOIN products p ON ri.product_id = p.product_id
    LEFT JOIN users u1 ON pr.received_by = u1.user_id
    LEFT JOIN users u2 ON pr.created_by = u2.user_id
    WHERE 1=1
";

$params = [];

// Apply filters
if (!empty($status_filter)) {
    $query .= " AND pr.status = ?";
    $params[] = $status_filter;
}

if ($supplier_filter > 0) {
    $query .= " AND pr.supplier_id = ?";
    $params[] = $supplier_filter;
}

if ($warehouse_filter > 0) {
    $query .= " AND pr.warehouse_id = ?";
    $params[] = $warehouse_filter;
}

if ($po_filter > 0) {
    $query .= " AND pr.purchase_order_id = ?";
    $params[] = $po_filter;
}

if (!empty($date_from)) {
    $query .= " AND pr.receipt_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND pr.receipt_date <= ?";
    $params[] = $date_to;
}

$query .= " GROUP BY pr.receipt_id ORDER BY pr.receipt_date DESC, pr.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get data for filter dropdowns
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
$purchase_orders = $pdo->query("SELECT purchase_order_id, order_number FROM purchase_orders WHERE status IN ('ordered', 'partially_received') ORDER BY order_date DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_grns = count($grns);
$draft_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'draft';
});
$completed_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'completed';
});
$cancelled_grns = array_filter($grns, function($grn) {
    return $grn['status'] == 'cancelled';
});

// Helper functions removed, now in helpers.php
// Generate GRN number (for new GRN)
function generate_grn_number() {
    $prefix = 'GRN';
    $year = date('Y');
    $month = date('m');
    $random = mt_rand(1000, 9999);
    return $prefix . '-' . $year . $month . '-' . $random;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-clipboard-check"></i> Goods Received Notes (GRN)</h2>
                    <p class="text-muted mb-0">Manage receipt of goods from suppliers</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_create_grn): ?>
                    <a href="grn_create.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> New GRN
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportGRNs()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <a href="reports.php?report=grn_summary" class="btn btn-info">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_grns ?></h4>
                            <p class="mb-0">Total GRNs</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clipboard-data" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($draft_grns) ?></h4>
                            <p class="mb-0">Draft</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-file-earmark" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($completed_grns) ?></h4>
                            <p class="mb-0">Completed</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check2-all" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($cancelled_grns) ?></h4>
                            <p class="mb-0">Cancelled</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-x-octagon" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-8 col-sm-12 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency(array_sum(array_column($grns, 'total_value'))) ?></h4>
                            <p class="mb-0">Total Received Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
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
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['supplier_id'] ?>" <?= $supplier_filter == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Warehouse</label>
                        <select class="form-select" name="warehouse">
                            <option value="">All Warehouses</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= $warehouse['warehouse_id'] ?>" <?= $warehouse_filter == $warehouse['warehouse_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($warehouse['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Purchase Order</label>
                        <select class="form-select" name="po">
                            <option value="">All POs</option>
                            <?php foreach ($purchase_orders as $po): ?>
                                <option value="<?= $po['purchase_order_id'] ?>" <?= $po_filter == $po['purchase_order_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($po['order_number']) ?>
                                </option>
                            <?php endforeach; ?>
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
                        <a href="grn.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- GRN List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Goods Received Notes List</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $total_grns ?> GRNs
                </span>
                <span class="badge bg-light text-dark">
                    Total Value: <?= format_currency(array_sum(array_column($grns, 'total_value'))) ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($grns) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>GRN #</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>PO #</th>
                                <th>Warehouse</th>
                                <th>Items</th>
                                <th>Total Value</th>
                                <th>Received By</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grns as $grn): ?>
                            <tr>
                                <td>
                                    <code><?= safe_output($grn['receipt_number']) ?></code>
                                    <?php if (!empty($grn['notes'])): ?>
                                    <br><small class="text-muted" title="<?= safe_output($grn['notes']) ?>">
                                        <i class="bi bi-chat-left-text"></i>
                                        <?= substr(safe_output($grn['notes']), 0, 30) ?>...
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= format_date($grn['receipt_date']) ?></td>
                                <td>
                                    <strong><?= safe_output($grn['supplier_name']) ?></strong>
                                    <?php if (!empty($grn['company_name'])): ?>
                                    <br><small class="text-muted"><?= safe_output($grn['company_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($grn['order_number'])): ?>
                                    <a href="purchase_order_view.php?id=<?= $grn['purchase_order_id'] ?>" class="text-decoration-none">
                                        <?= safe_output($grn['order_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= safe_output($grn['warehouse_name']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= $grn['total_items'] ?></span> items
                                    <?php if (!empty($grn['product_names'])): ?>
                                    <br><small class="text-muted" title="<?= safe_output($grn['product_names']) ?>">
                                        <?= substr(safe_output($grn['product_names']), 0, 50) ?>...
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= format_currency($grn['total_value']) ?></strong>
                                </td>
                                <td>
                                    <?= safe_output($grn['received_by_name']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($grn['status']) ?>">
                                        <?= ucfirst($grn['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="grn_view.php?id=<?= $grn['receipt_id'] ?>">
                                                    <i class="bi bi-eye"></i> View GRN
                                                </a>
                                            </li>
                                            
                                            <?php if ($can_create_grn && $grn['status'] == 'draft'): ?>
                                            <li>
                                                <a class="dropdown-item" href="grn_edit.php?id=<?= $grn['receipt_id'] ?>">
                                                    <i class="bi bi-pencil"></i> Edit GRN
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_approve_grn && in_array($grn['status'], ['draft', 'pending'])): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="updateGRNStatus(<?= $grn['receipt_id'] ?>, 'completed')">
                                                    <i class="bi bi-check-circle"></i> Mark as Completed
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_create_grn && $grn['status'] == 'draft'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="updateGRNStatus(<?= $grn['receipt_id'] ?>, 'cancelled')">
                                                    <i class="bi bi-x-octagon"></i> Cancel GRN
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="printGRN(<?= $grn['receipt_id'] ?>)">
                                                    <i class="bi bi-printer"></i> Print GRN
                                                </a>
                                            </li>
                                            
                                            <?php if ($can_delete_grn && $grn['status'] == 'draft'): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteGRN(<?= $grn['receipt_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete GRN
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination (if needed) -->
                <?php if (count($grns) > 50): ?>
                <nav aria-label="GRN pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" tabindex="-1">Previous</a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item">
                            <a class="page-link" href="#">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-clipboard-check" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Goods Received Notes Found</h4>
                    <p class="text-muted">No GRNs match your filter criteria or no GRNs have been created yet.</p>
                    <?php if ($can_create_grn): ?>
                    <a href="grn_create.php" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-circle"></i> Create Your First GRN
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Action Buttons (Fixed at bottom) -->
<?php if ($can_create_grn): ?>
<div class="fixed-bottom d-flex justify-content-end p-3" style="z-index: 1030;">
    <div class="btn-group shadow">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quickGRNModal">
            <i class="bi bi-lightning-charge"></i> Quick GRN
        </button>
        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="grn_create.php"><i class="bi bi-plus-circle"></i> New GRN</a></li>
            <li><a class="dropdown-item" href="purchase_orders.php?status=ordered"><i class="bi bi-cart-check"></i> From Purchase Orders</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="grn_import.php"><i class="bi bi-upload"></i> Import GRNs</a></li>
        </ul>
    </div>
</div>

<!-- Quick GRN Modal -->
<div class="modal fade" id="quickGRNModal" tabindex="-1" aria-labelledby="quickGRNModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickGRNModalLabel">
                    <i class="bi bi-lightning-charge"></i> Quick GRN
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickGRNForm">
                <div class="modal-body">
                    <div id="quick-grn-message" class="mb-3"></div>
                    
                    <div class="mb-3">
                        <label for="quick_supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select" id="quick_supplier_id" name="supplier_id" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>">
                                <?= safe_output($supplier['supplier_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select" id="quick_warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= $warehouse['warehouse_id'] ?>">
                                <?= safe_output($warehouse['warehouse_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_receipt_date" class="form-label">Receipt Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="quick_receipt_date" name="receipt_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-right"></i> Continue to Add Items
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include necessary scripts -->
<!--script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script-->

<script>
$(document).ready(function() {
    // Quick GRN form submission
    $('#quickGRNForm').on('submit', function(e) {
        e.preventDefault();
        
        const supplierId = $('#quick_supplier_id').val();
        const warehouseId = $('#quick_warehouse_id').val();
        
        if (supplierId && warehouseId) {
            window.location.href = `grn_create.php?supplier=${supplierId}&warehouse=${warehouseId}`;
        }
    });
});

function updateGRNStatus(receiptId, status) {
    const actionMap = {
        'completed': 'complete',
        'cancelled': 'cancel'
    };
    
    const action = actionMap[status] || 'update';
    const actionText = status.charAt(0).toUpperCase() + status.slice(1);
    const icon = status === 'completed' ? 'success' : 'warning';
    
    Swal.fire({
        title: `Are you sure?`,
        text: `Do you want to ${action} this GRN?`,
        icon: icon,
        showCancelButton: true,
        confirmButtonText: `Yes, ${actionText}`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/update_grn_status.php',
                type: 'POST',
                data: { 
                    receipt_id: receiptId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.'
                    });
                }
            });
        }
    });
}

function confirmDeleteGRN(receiptId) {
    Swal.fire({
        title: 'Delete GRN',
        text: 'Are you sure you want to delete this GRN? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_grn.php',
                type: 'POST',
                data: { receipt_id: receiptId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.'
                    });
                }
            });
        }
    });
}

function printGRN(receiptId) {
    // Open GRN in new window for printing
    const printWindow = window.open(`grn_print.php?id=${receiptId}`, '_blank');
    if (printWindow) {
        printWindow.focus();
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Pop-up Blocked',
            text: 'Please allow pop-ups for this site to print the GRN.'
        });
    }
}

function exportGRNs() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    
    window.location.href = 'api/export_grns.php?' + params.toString();
}

// Quick search functionality
function quickSearchGRN() {
    const searchValue = $('#searchGRN').val().toLowerCase();
    $('table tbody tr').each(function() {
        const rowText = $(this).text().toLowerCase();
        if (rowText.includes(searchValue)) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

// Bind search input
$('#searchGRN').on('keyup', function() {
    quickSearchGRN();
});

// Auto-refresh data every 60 seconds (optional)
setTimeout(function() {
    if (document.hasFocus()) {
        location.reload();
    }
}, 60000);
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
}

.badge {
    font-size: 0.75em;
}

.dropdown-menu {
    font-size: 0.875rem;
    min-width: 200px;
}

.dropdown-item {
    padding: 0.25rem 1rem;
}

.dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.fixed-bottom {
    right: 20px;
    bottom: 20px;
}

/* Status badges */
.badge.bg-secondary { background-color: #6c757d !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #212529; }
.badge.bg-success { background-color: #198754 !important; }
.badge.bg-danger { background-color: #dc3545 !important; }
.badge.bg-info { background-color: #0dcaf0 !important; color: #212529; }

/* Hover effects */
.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
    cursor: pointer;
}

/* Print styles */
@media print {
    .navbar, .card-header .btn, .dropdown, .fixed-bottom,
    .dataTables_length, .dataTables_filter, .dataTables_info,
    .dataTables_paginate, .dt-buttons {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .card-body {
        padding: 0;
    }
    
    table {
        width: 100% !important;
        font-size: 12px !important;
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
    
    .table td, .table th {
        padding: 0.5rem;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .fixed-bottom {
        right: 10px;
        bottom: 10px;
    }
    
    .fixed-bottom .btn-group {
        flex-direction: column;
    }
}

@media (max-width: 576px) {
    .col-xl-2, .col-md-4, .col-sm-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .dropdown-menu {
        position: fixed !important;
        top: auto !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        bottom: 60px !important;
    }
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
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>