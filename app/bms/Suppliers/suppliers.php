<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for supplier permissions
$can_view_suppliers = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Purchasing']);
$can_edit_suppliers = in_array($user_role, ['Admin', 'Manager', 'Purchasing']);
$can_delete_suppliers = in_array($user_role, ['Admin']);

if (!$can_view_suppliers) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get company type for conditional features
$settings_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'");
$settings_stmt->execute();
$company_type = $settings_stmt->fetchColumn() ?: 'microfinance';

// Fetch suppliers with additional data
$query = "
    SELECT 
        s.*,
        sc.category_name,
        COUNT(DISTINCT po.purchase_order_id) as total_orders,
        COUNT(DISTINCT pr.purchase_return_id) as total_returns,
        SUM(CASE WHEN po.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN po.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        u1.username as created_by_name,
        u2.username as updated_by_name
    FROM suppliers s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    LEFT JOIN purchase_returns pr ON s.supplier_id = pr.supplier_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE s.status != 'deleted'
    GROUP BY s.supplier_id
    ORDER BY s.supplier_name ASC
";
$stmt = $pdo->query($query);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_suppliers = count($suppliers);
$active_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'active';
});
$inactive_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'inactive';
});
$suspended_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'suspended';
});
$blacklisted_suppliers = array_filter($suppliers, function($supplier) {
    return $supplier['status'] == 'blacklisted';
});

// Get supplier categories
$categories = $pdo->query("SELECT * FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-truck"></i> Supplier Management</h2>
                    <p class="text-muted mb-0">Manage your suppliers and vendor relationships</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_suppliers): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                        <i class="bi bi-plus-circle"></i> Add Supplier
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportSuppliers()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <?php if ($can_edit_suppliers): ?>
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#importSuppliersModal">
                        <i class="bi bi-upload"></i> Import
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_suppliers ?></h4>
                            <p class="mb-0">Total Suppliers</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($active_suppliers) ?></h4>
                            <p class="mb-0">Active</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($suspended_suppliers) ?></h4>
                            <p class="mb-0">Suspended</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= count($blacklisted_suppliers) ?></h4>
                            <p class="mb-0">Blacklisted</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-ban" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                            <option value="blacklisted">Blacklisted</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" id="categoryFilter">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" id="countryFilter" placeholder="Filter by country">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">City</label>
                        <input type="text" class="form-control" id="cityFilter" placeholder="Filter by city">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" id="searchSuppliers" placeholder="Search by name, email, phone, contact person...">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="bi bi-arrow-clockwise"></i> Clear
                        </button>
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Suppliers List</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $total_suppliers ?> suppliers
                </span>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-light btn-sm" onclick="toggleView('table')" title="Table View">
                        <i class="bi bi-table"></i>
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="toggleView('card')" title="Card View">
                        <i class="bi bi-grid"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($suppliers) > 0): ?>
                <!-- Table View -->
                <div id="tableView" class="table-responsive">
                    <table id="suppliersTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Supplier Code</th>
                                <th>Supplier Name</th>
                                <th>Contact Info</th>
                                <th>Address</th>
                                <th>Category</th>
                                <th>Orders</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td>
                                    <span class="custom-code"><?= safe_output($supplier['supplier_code']) ?></span>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($supplier['supplier_name']) ?></strong>
                                        <?php if (!empty($supplier['company_name'])): ?>
                                        <br>
                                        <small class="text-muted"><?= safe_output($supplier['company_name']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['tax_id'])): ?>
                                        <br>
                                        <small><span class="custom-code">TIN: <?= safe_output($supplier['tax_id']) ?></span></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php if (!empty($supplier['contact_person'])): ?>
                                        <strong><?= safe_output($supplier['contact_person']) ?></strong><br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['email'])): ?>
                                        <small><i class="bi bi-envelope"></i> <?= safe_output($supplier['email']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['phone'])): ?>
                                        <small><i class="bi bi-telephone"></i> <?= safe_output($supplier['phone']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['website'])): ?>
                                        <small><i class="bi bi-globe"></i> <?= safe_output($supplier['website']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php if (!empty($supplier['address'])): ?>
                                        <small><?= safe_output(substr($supplier['address'], 0, 50)) ?>...</small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['city'])): ?>
                                        <small><?= safe_output($supplier['city']) ?></small>
                                        <?php if (!empty($supplier['state'])): ?>
                                        , <small><?= safe_output($supplier['state']) ?></small>
                                        <?php endif; ?>
                                        <br>
                                        <?php endif; ?>
                                        <?php if (!empty($supplier['country'])): ?>
                                        <small><?= safe_output($supplier['country']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['category_name'])): ?>
                                    <span class="badge bg-secondary"><?= safe_output($supplier['category_name']) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-light text-dark">No Category</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <div class="d-flex justify-content-around">
                                            <div>
                                                <span class="badge bg-primary"><?= $supplier['total_orders'] ?></span>
                                                <br>
                                                <small>Total</small>
                                            </div>
                                            <div>
                                                <span class="badge bg-warning"><?= $supplier['pending_orders'] ?></span>
                                                <br>
                                                <small>Pending</small>
                                            </div>
                                            <div>
                                                <span class="badge bg-success"><?= $supplier['completed_orders'] ?></span>
                                                <br>
                                                <small>Completed</small>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                        <?= ucfirst($supplier['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('suppliers/details') ?>?id=<?= $supplier['supplier_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_suppliers): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Supplier
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier['supplier_id'] ?>">
                                                    <i class="bi bi-cart"></i> View Orders
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('suppliers/payments') ?>?id=<?= $supplier['supplier_id'] ?>">
                                                    <i class="bi bi-cash"></i> View Payments
                                                </a>
                                            </li>
                                            
                                            <?php if ($company_type != 'microfinance' && $can_edit_suppliers): ?>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('purchase_order_create') ?>?supplier=<?= $supplier['supplier_id'] ?>">
                                                    <i class="bi bi-file-plus"></i> New Order
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_suppliers): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($supplier['status'] == 'active'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'inactive')">
                                                    <i class="bi bi-pause-circle"></i> Deactivate
                                                </a>
                                            </li>
                                            <?php elseif ($supplier['status'] == 'inactive'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'active')">
                                                    <i class="bi bi-play-circle"></i> Activate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($supplier['status'] != 'suspended'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'suspended')">
                                                    <i class="bi bi-exclamation-triangle"></i> Suspend
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($supplier['status'] != 'blacklisted'): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="updateStatus(<?= $supplier['supplier_id'] ?>, 'blacklisted')">
                                                    <i class="bi bi-ban"></i> Blacklist
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_suppliers): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $supplier['supplier_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
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
                
                <!-- Card View (Hidden by default) -->
                <div id="cardView" class="row d-none">
                    <?php foreach ($suppliers as $supplier): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><?= safe_output($supplier['supplier_name']) ?></h6>
                                <span class="badge bg-<?= get_status_badge($supplier['status']) ?>">
                                    <?= ucfirst($supplier['status']) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <small class="text-muted">Code: <?= safe_output($supplier['supplier_code']) ?></small><br>
                                    <?php if (!empty($supplier['company_name'])): ?>
                                    <strong><?= safe_output($supplier['company_name']) ?></strong><br>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['contact_person'])): ?>
                                    <small><i class="bi bi-person"></i> <?= safe_output($supplier['contact_person']) ?></small><br>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($supplier['email'])): ?>
                                    <small><i class="bi bi-envelope"></i> <?= safe_output($supplier['email']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($supplier['phone'])): ?>
                                    <small><i class="bi bi-telephone"></i> <?= safe_output($supplier['phone']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($supplier['city'])): ?>
                                    <small><i class="bi bi-geo-alt"></i> <?= safe_output($supplier['city']) ?></small>
                                    <?php if (!empty($supplier['country'])): ?>
                                    , <small><?= safe_output($supplier['country']) ?></small>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div class="text-center">
                                        <div class="badge bg-primary"><?= $supplier['total_orders'] ?></div>
                                        <br>
                                        <small>Orders</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-success"><?= $supplier['completed_orders'] ?></div>
                                        <br>
                                        <small>Completed</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-warning"><?= $supplier['pending_orders'] ?></div>
                                        <br>
                                        <small>Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="<?= getUrl('suppliers/details') ?>?id=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($can_edit_suppliers): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="editSupplier(<?= $supplier['supplier_id'] ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="<?= getUrl('purchase_orders') ?>?supplier=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-success" title="View Orders">
                                        <i class="bi bi-cart"></i>
                                    </a>
                                    <?php if ($company_type != 'microfinance' && $can_edit_suppliers): ?>
                                    <a href="<?= getUrl('purchase_order_create') ?>?supplier=<?= $supplier['supplier_id'] ?>" class="btn btn-sm btn-outline-info" title="New Order">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-truck" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Suppliers Found</h4>
                    <p class="text-muted">Get started by adding your first supplier.</p>
                    <?php if ($can_edit_suppliers): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Supplier
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Supplier Modal -->
<?php if ($can_edit_suppliers): ?>
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addSupplierModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addSupplierForm">
                <div class="modal-body">
                    <div id="add-supplier-message" class="mb-3"></div>
                    
                    <ul class="nav nav-tabs mb-3" id="supplierTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact" type="button" role="tab">Contact Details</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="address-tab" data-bs-toggle="tab" data-bs-target="#address" type="button" role="tab">Address</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button" role="tab">Financial</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="supplierTabContent">
                        <!-- Basic Information Tab -->
                        <div class="tab-pane fade show active" id="basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_name" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="supplier_name" name="supplier_name" required placeholder="Enter supplier name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Category</label>
                                    <select class="form-select" id="category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" placeholder="Supplier description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Tab -->
                        <div class="tab-pane fade" id="contact" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_person" class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contact_title" class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="supplier@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="mobile" class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="mobile" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="fax" class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="website" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Address Tab -->
                        <div class="tab-pane fade" id="address" role="tabpanel">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" placeholder="Street address"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">City</label>
                                    <input type="text" class="form-control" id="city" name="city" placeholder="City">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="state" class="form-label">State/Region</label>
                                    <input type="text" class="form-control" id="state" name="state" placeholder="State or region">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" placeholder="Country" value="Tanzania">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="postal_code" class="form-label">Postal Code</label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code" placeholder="Postal code">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Financial Tab -->
                        <div class="tab-pane fade" id="financial" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="vat_number" class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <select class="form-select" id="payment_terms" name="payment_terms">
                                        <option value="">Select Terms</option>
                                        <option value="cod">Cash on Delivery</option>
                                        <option value="7_days">7 Days</option>
                                        <option value="15_days">15 Days</option>
                                        <option value="30_days">30 Days</option>
                                        <option value="60_days">60 Days</option>
                                        <option value="90_days">90 Days</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <select class="form-select" id="currency" name="currency">
                                        <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                        <option value="KES">Kenyan Shilling (KES)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Suppliers Modal -->
<div class="modal fade" id="importSuppliersModal" tabindex="-1" aria-labelledby="importSuppliersModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importSuppliersModalLabel">
                    <i class="bi bi-upload"></i> Import Suppliers
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importSuppliersForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the supplier data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_action" class="form-label">Import Action</label>
                        <select class="form-select" id="import_action" name="import_action">
                            <option value="add_new">Add New Suppliers Only</option>
                            <option value="update_existing">Update Existing Suppliers</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_errors" name="skip_errors">
                        <label class="form-check-label" for="skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Suppliers
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Edit Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editSupplierModalLabel">
                    <i class="bi bi-pencil"></i> Quick Edit Supplier
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSupplierForm">
                <div class="modal-body">
                    <div id="edit-supplier-message" class="mb-3"></div>
                    <input type="hidden" id="edit_supplier_id" name="supplier_id">
                    
                    <div class="mb-3">
                        <label for="edit_supplier_name" class="form-label">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_supplier_name" name="supplier_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="edit_contact_person" name="contact_person">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="edit_phone" name="phone">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                            <option value="blacklisted">Blacklisted</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Update Supplier
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
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    let suppliersTable = $('#suppliersTable').DataTable({
        language: {
            search: "Search suppliers:",
            lengthMenu: "Show _MENU_ suppliers per page",
            info: "Showing _START_ to _END_ of _TOTAL_ suppliers",
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
                className: 'btn btn-sm btn-outline-secondary',
                titleAttr: 'Copy to clipboard'
            },
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-excel"></i> Excel',
                className: 'btn btn-sm btn-outline-success',
                titleAttr: 'Export to Excel',
                title: 'Suppliers_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                titleAttr: 'Export to PDF',
                title: 'Suppliers_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-info',
                titleAttr: 'Print table'
            }
        ],
        pageLength: 25,
        order: [[1, 'asc']]
    });

    // Add supplier form submission
    $('#addSupplierForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/add_supplier.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-supplier-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-supplier-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Supplier');
                }
            },
            error: function(xhr, status, error) {
                $('#add-supplier-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Supplier');
                console.error('Error:', error);
            }
        });
    });

    // Import form submission
    $('#importSuppliersForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: 'api/import_suppliers.php',
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
                        resultsHtml += 'Successful: ' + response.results.successful + '<br>';
                        resultsHtml += 'Failed: ' + response.results.failed + '<br>';
                        resultsHtml += 'Skipped: ' + response.results.skipped + '<br>';
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
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
                }
            },
            error: function(xhr, status, error) {
                $('#import-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
                console.error('Error:', error);
            }
        });
    });

    // Edit supplier form submission
    $('#editSupplierForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_supplier.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit-supplier-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#edit-supplier-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
                }
            },
            error: function(xhr, status, error) {
                $('#edit-supplier-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
                console.error('Error:', error);
            }
        });
    });

    // Reset forms when modals are closed
    $('#addSupplierModal').on('hidden.bs.modal', function() {
        $('#addSupplierForm')[0].reset();
        $('#add-supplier-message').html('');
        $('#addSupplierForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Supplier');
        $('#supplierTabs .nav-link:first').tab('show');
    });
    
    $('#importSuppliersModal').on('hidden.bs.modal', function() {
        $('#importSuppliersForm')[0].reset();
        $('#import-message').html('');
        $('#importSuppliersForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Suppliers');
    });
    
    $('#editSupplierModal').on('hidden.bs.modal', function() {
        $('#editSupplierForm')[0].reset();
        $('#edit-supplier-message').html('');
        $('#editSupplierForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Supplier');
    });
});

function applyFilters() {
    const table = $('#suppliersTable').DataTable();
    
    // Status filter
    const status = $('#statusFilter').val();
    if (status) {
        table.column(6).search('^' + status + '$', true, false).draw();
    } else {
        table.column(6).search('').draw();
    }
    
    // Search filter
    const search = $('#searchSuppliers').val();
    table.search(search).draw();
    
    // Category filter (custom since category is not a direct column)
    const category = $('#categoryFilter').val();
    if (category) {
        table.column(4).search(category).draw();
    } else {
        table.column(4).search('').draw();
    }
    
    // Country and city filters
    const country = $('#countryFilter').val();
    const city = $('#cityFilter').val();
    
    if (country || city) {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const addressData = data[3].toLowerCase();
            const countryMatch = country ? addressData.includes(country.toLowerCase()) : true;
            const cityMatch = city ? addressData.includes(city.toLowerCase()) : true;
            return countryMatch && cityMatch;
        });
        table.draw();
        $.fn.dataTable.ext.search.pop();
    }
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#categoryFilter').val('');
    $('#countryFilter').val('');
    $('#cityFilter').val('');
    $('#searchSuppliers').val('');
    
    const table = $('#suppliersTable').DataTable();
    table.search('').columns().search('').draw();
}

function editSupplier(supplierId) {
    // Load supplier data for quick edit
    $.ajax({
        url: 'api/get_supplier.php',
        type: 'GET',
        data: { id: supplierId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_supplier_id').val(response.data.supplier_id);
                $('#edit_supplier_name').val(response.data.supplier_name);
                $('#edit_contact_person').val(response.data.contact_person || '');
                $('#edit_email').val(response.data.email || '');
                $('#edit_phone').val(response.data.phone || '');
                $('#edit_status').val(response.data.status);
                
                // Show edit modal
                $('#editSupplierModal').modal('show');
            } else {
                alert('Error loading supplier data: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading supplier data. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateStatus(supplierId, status) {
    const actionMap = {
        'active': 'activate',
        'inactive': 'deactivate',
        'suspended': 'suspend',
        'blacklisted': 'blacklist'
    };
    
    const action = actionMap[status] || 'update';
    
    if (!confirm('Are you sure you want to ' + action + ' this supplier?')) {
        return;
    }

    $.ajax({
        url: 'api/update_supplier_status.php',
        type: 'POST',
        data: { 
            supplier_id: supplierId,
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

function confirmDelete(supplierId) {
    if (confirm('Are you sure you want to delete this supplier? This action cannot be undone.')) {
        $.ajax({
            url: 'api/delete_supplier.php',
            method: 'POST',
            data: { supplier_id: supplierId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error deleting supplier: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting supplier. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function toggleView(viewType) {
    const tableView = $('#tableView');
    const cardView = $('#cardView');
    const tableBtn = $('button[onclick*="table"]');
    const cardBtn = $('button[onclick*="card"]');
    
    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        tableBtn.removeClass('btn-outline-light').addClass('btn-light');
        cardBtn.removeClass('btn-light').addClass('btn-outline-light');
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        tableBtn.removeClass('btn-light').addClass('btn-outline-light');
        cardBtn.removeClass('btn-outline-light').addClass('btn-light');
    }
    
    // Store preference in localStorage
    localStorage.setItem('suppliersView', viewType);
}

// Load view preference on page load
$(document).ready(function() {
    const savedView = localStorage.getItem('suppliersView') || 'table';
    toggleView(savedView);
});

function exportSuppliers() {
    // Trigger DataTable export
    $('#suppliersTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    // Create a CSV template file
    const headers = [
        'supplier_name', 'company_name', 'contact_person', 'contact_title',
        'email', 'phone', 'mobile', 'fax', 'website', 'address', 'city',
        'state', 'country', 'postal_code', 'tax_id', 'vat_number',
        'payment_terms', 'currency', 'bank_name', 'bank_account',
        'bank_address', 'description', 'status'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\nExample Supplier,Example Corp,John Doe,Manager,john@example.com,+255123456789,+255987654321,,http://example.com,123 Street,Dar es Salaam,Dar es Salaam,Tanzania,12345,TIN123,VAT123,30_days,TZS,Example Bank,123456789,123 Bank Street,Good supplier,active";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "suppliers_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Quick search function for DataTables
function quickSearch() {
    const searchValue = $('#searchSuppliers').val();
    $('#suppliersTable').DataTable().search(searchValue).draw();
}

// Bind enter key to search
$('#searchSuppliers').on('keyup', function(e) {
    if (e.keyCode === 13) {
        quickSearch();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
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

/* Card view styling */
#cardView .card {
    transition: transform 0.2s;
}

#cardView .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* Tab styling */
.nav-tabs .nav-link {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}

.nav-tabs .nav-link.active {
    font-weight: 600;
}

/* DataTables customization */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    padding: 1rem 0;
}

.dt-buttons {
    margin-bottom: 1rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:last-child {
        width: 100%;
    }
    
    #cardView .col-xl-3 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    
    .nav-tabs .nav-item {
        white-space: nowrap;
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
    
    .table-responsive {
        font-size: 0.85rem;
    } 
}

/* Print styles */
@media print {
    .navbar, .card-header, .btn, .dropdown, .dataTables_length, 
    .dataTables_filter, .dataTables_info, .dataTables_paginate, 
    .dt-buttons {
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
    }
}

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
    font-family: inherit;
    font-weight: 600;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>
