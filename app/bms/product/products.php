<?php
// File: products.php
ob_start();
require_once 'header.php';

// Check user role for product permissions
requireViewPermission('products');

$can_create_products = canCreate('products');
$can_edit_products = canEdit('products');
$can_delete_products = canDelete('products');
$can_adjust_stock = hasPermission('adjust_stock') || isAdmin(); // Assuming adjust_stock key exists or fallback to Admin

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category']) ? intval($_GET['category']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$brand_id = isset($_GET['brand']) ? intval($_GET['brand']) : 0;
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$low_stock = isset($_GET['low_stock']) ? $_GET['low_stock'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'product_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Calculate offset
$offset = ($page - 1) * $per_page;

// Build query with filters
$query = "
    SELECT 
        p.*,
        c.category_name,
        b.brand_name,
        s.supplier_name,
        t.rate_name AS tax_name,
        t.rate_percentage as tax_rate_percentage,
        
        -- Stock information
        COALESCE(SUM(ps.stock_quantity), 0) as total_stock,
        COALESCE(SUM(ps.reserved_quantity), 0) as total_reserved,
        COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) as available_stock,
        
        -- Warehouse information
        GROUP_CONCAT(DISTINCT w.warehouse_name SEPARATOR ', ') as warehouses,
        GROUP_CONCAT(DISTINCT loc.location_name SEPARATOR ', ') as locations,
        
        -- Sales statistics
        COALESCE((
            SELECT SUM(quantity) 
            FROM pos_sale_items psi 
            JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
            WHERE psi.product_id = p.product_id 
            AND ps.sale_status = 'completed'
            AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ), 0) as sales_last_30_days,
        
        -- Average cost and margin
        COALESCE(p.cost_price, 0) as cost_price,
        p.selling_price,
        CASE 
            WHEN p.cost_price > 0 
            THEN ROUND(((p.selling_price - p.cost_price) / p.cost_price) * 100, 2)
            ELSE 0 
        END as markup_percentage,
        
        -- Stock status
        CASE 
            WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= 0 THEN 'out_of_stock'
            WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level THEN 'low_stock'
            ELSE 'in_stock'
        END as stock_status,
        
        -- Last restock date
        (
            SELECT MAX(sm.created_at) 
            FROM stock_movements sm 
            WHERE sm.product_id = p.product_id 
            AND sm.movement_type = 'purchase_in'
        ) as last_restock_date,
        
        -- Last sale date
        (
            SELECT MAX(ps.sale_date) 
            FROM pos_sale_items psi 
            JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
            WHERE psi.product_id = p.product_id 
            AND ps.sale_status = 'completed'
        ) as last_sale_date
        
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
    LEFT JOIN product_stocks ps ON p.product_id = ps.product_id
    LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id
    LEFT JOIN locations loc ON ps.location_id = loc.location_id
    WHERE 1=1
";

$params = [];
$conditions = [];

// Apply filters
if ($status_filter != 'all') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $conditions[] = "(
        p.product_name LIKE :search OR 
        p.sku LIKE :search OR 
        p.barcode LIKE :search OR 
        p.description LIKE :search OR 
        c.category_name LIKE :search OR 
        b.brand_name LIKE :search
    )";
    $params[':search'] = "%$search%";
}

if ($category_id > 0) {
    // Include subcategories
    $subcategories = get_subcategories($pdo, $category_id);
    $category_ids = array_merge([$category_id], $subcategories);
    $placeholders = implode(',', array_fill(0, count($category_ids), '?'));
    $conditions[] = "p.category_id IN ($placeholders)";
    $params = array_merge($params, $category_ids);
}

if ($supplier_id > 0) {
    $conditions[] = "p.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_id;
}

if ($brand_id > 0) {
    $conditions[] = "p.brand_id = :brand_id";
    $params[':brand_id'] = $brand_id;
}

if ($min_price > 0) {
    $conditions[] = "p.selling_price >= :min_price";
    $params[':min_price'] = $min_price;
}

if ($max_price > 0) {
    $conditions[] = "p.selling_price <= :max_price";
    $params[':max_price'] = $max_price;
}

if ($low_stock === 'yes') {
    $conditions[] = "COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level";
    $conditions[] = "p.min_stock_level > 0";
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

// Group by product
$query .= " GROUP BY p.product_id";

// Apply sorting
$valid_sort_columns = [
    'product_name', 'sku', 'selling_price', 'cost_price', 
    'total_stock', 'available_stock', 'sales_last_30_days',
    'created_at', 'updated_at', 'markup_percentage'
];

if (in_array($sort_by, $valid_sort_columns)) {
    $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
    $query .= " ORDER BY $sort_by $sort_order";
} else {
    $query .= " ORDER BY p.product_name ASC";
}

// Add pagination
$query .= " LIMIT :limit OFFSET :offset";

// Get total count for pagination
$count_query = "SELECT COUNT(DISTINCT p.product_id) as total FROM products p";
if ($category_id > 0 || !empty($search)) {
    $count_query .= " LEFT JOIN categories c ON p.category_id = c.category_id";
}
if ($brand_id > 0 || !empty($search)) {
    $count_query .= " LEFT JOIN brands b ON p.brand_id = b.brand_id";
}
$count_query .= " WHERE 1=1";
if (!empty($conditions)) {
    $count_query .= " AND " . implode(" AND ", $conditions);
}

// Execute count query
$count_stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $count_stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $count_stmt->bindValue($key, $value);
    }
}
$count_stmt->execute();
$total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_count / $per_page);

// Execute main query
$stmt = $pdo->prepare($query);

// Bind parameters for main query
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get data for filter dropdowns
$categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$brands = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status = 'active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
$tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_products = $total_count;
$total_value = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
$inactive_count = 0;

foreach ($products as $product) {
    $total_value += $product['cost_price'] * $product['total_stock'];
    
    if ($product['stock_status'] == 'low_stock') $low_stock_count++;
    if ($product['stock_status'] == 'out_of_stock') $out_of_stock_count++;
    if ($product['status'] == 'inactive') $inactive_count++;
}

// Helper functions
function get_subcategories($pdo, $parent_id) {
    $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE parent_id = ? AND status = 'active'");
    $stmt->execute([$parent_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $all_subcategories = [];
    foreach ($subcategories as $subcat) {
        $all_subcategories[] = $subcat;
        $all_subcategories = array_merge($all_subcategories, get_subcategories($pdo, $subcat));
    }
    
    return $all_subcategories;
}
// Helper functions removed, now in helpers.php
function get_stock_badge($stock_status, $available_stock) {
    $color = 'secondary';
    $label = 'Unknown';
    
    switch ($stock_status) {
        case 'out_of_stock':
            $color = 'danger';
            $label = 'Out of Stock';
            break;
        case 'low_stock':
            $color = 'warning';
            $label = 'Low Stock';
            break;
        case 'in_stock':
            $color = 'success';
            $label = 'In Stock (' . $available_stock . ')';
            break;
    }
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

function get_markup_color($percentage) {
    if ($percentage >= 50) return 'text-success';
    if ($percentage >= 20) return 'text-warning';
    return 'text-danger';
}

// safe_output removed, now in helpers.php

// format_date removed, now in helpers.php

function get_quick_actions($product) {
    global $can_edit_products, $can_delete_products, $can_adjust_stock;
    
    $actions = [];
    
    if ($can_edit_products) {
        $actions[] = '<a href="product_edit.php?id=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-pencil"></i> Edit Product
                      </a>';
    }
    
    if ($can_adjust_stock) {
        $actions[] = '<a href="#" class="dropdown-item" onclick="adjustStock(' . $product['product_id'] . ')">
                        <i class="bi bi-box-arrow-in-down"></i> Adjust Stock
                      </a>';
    }
    
    if ($can_edit_products) {
        $actions[] = '<a href="#" class="dropdown-item" onclick="duplicateProduct(' . $product['product_id'] . ')">
                        <i class="bi bi-copy"></i> Duplicate Product
                      </a>';
    }
    
    if ($can_adjust_stock) {
        $actions[] = '<a href="stock_transfer.php?product=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-arrow-left-right"></i> Transfer Stock
                      </a>';
    }
    
    if ($can_edit_products) {
        $actions[] = '<a href="purchase_order_create.php?product=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-truck"></i> Order More
                      </a>';
    }
    
    if ($can_delete_products && $product['status'] != 'active') {
        $actions[] = '<div class="dropdown-divider"></div>';
        $actions[] = '<a href="#" class="dropdown-item text-danger" onclick="deleteProduct(' . $product['product_id'] . ')">
                        <i class="bi bi-trash"></i> Delete Product
                      </a>';
    }
    
    return implode('', $actions);
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-box"></i> Products</h2>
                    <p class="text-muted mb-0">Manage your product inventory and stock levels</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_create_products): ?>
                    <a href="<?= getUrl('product_create') ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> New Product
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="exportProducts()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <a href="<?= getUrl('reports') ?>?report=inventory" class="btn btn-primary">
                        <i class="bi bi-graph-up"></i> Inventory Report
                    </a>
                    <?php if ($can_adjust_stock): ?>
                    <a href="<?= getUrl('stock_adjustments') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left-right"></i> Stock Adjustments
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_products ?></h4>
                            <p class="mb-0 small">Total Products</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($total_value) ?></h4>
                            <p class="mb-0 small">Inventory Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $low_stock_count ?></h4>
                            <p class="mb-0 small">Low Stock</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $out_of_stock_count ?></h4>
                            <p class="mb-0 small">Out of Stock</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-x-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-8 col-sm-12 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <div class="d-flex justify-content-between border-end pe-2">
                                <div>
                                    <h4 class="mb-0"><?= $total_products - $inactive_count ?></h4>
                                    <p class="mb-0 small">Active</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex justify-content-between ps-2">
                                <div>
                                    <h4 class="mb-0"><?= $inactive_count ?></h4>
                                    <p class="mb-0 small">Inactive</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="bi bi-slash-circle" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
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
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" value="<?= safe_output($search) ?>" 
                               placeholder="Name, SKU, or Barcode">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['category_id'] ?>" 
                                    <?= $category_id == $category['category_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="discontinued" <?= $status_filter == 'discontinued' ? 'selected' : '' ?>>Discontinued</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Stock Status</label>
                        <select class="form-select" name="low_stock">
                            <option value="">All Stock</option>
                            <option value="yes" <?= $low_stock == 'yes' ? 'selected' : '' ?>>Low Stock Only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['supplier_id'] ?>" 
                                    <?= $supplier_id == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Brand</label>
                        <select class="form-select" name="brand">
                            <option value="">All Brands</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= $brand['brand_id'] ?>" 
                                    <?= $brand_id == $brand['brand_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($brand['brand_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Min Price</label>
                        <input type="number" class="form-control" name="min_price" value="<?= $min_price ?>" 
                               min="0" step="0.01" placeholder="Min">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Max Price</label>
                        <input type="number" class="form-control" name="max_price" value="<?= $max_price ?>" 
                               min="0" step="0.01" placeholder="Max">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sort By</label>
                        <select class="form-select" name="sort_by">
                            <option value="product_name" <?= $sort_by == 'product_name' ? 'selected' : '' ?>>Name</option>
                            <option value="sku" <?= $sort_by == 'sku' ? 'selected' : '' ?>>SKU</option>
                            <option value="selling_price" <?= $sort_by == 'selling_price' ? 'selected' : '' ?>>Price</option>
                            <option value="available_stock" <?= $sort_by == 'available_stock' ? 'selected' : '' ?>>Stock</option>
                            <option value="sales_last_30_days" <?= $sort_by == 'sales_last_30_days' ? 'selected' : '' ?>>Sales (30d)</option>
                            <option value="markup_percentage" <?= $sort_by == 'markup_percentage' ? 'selected' : '' ?>>Margin %</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Order</label>
                        <select class="form-select" name="sort_order">
                            <option value="ASC" <?= $sort_order == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $sort_order == 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Per Page</label>
                        <select class="form-select" name="per_page">
                            <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                        <a href="products.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                        <div class="ms-3">
                            <span class="text-muted">Showing <?= count($products) ?> of <?= $total_count ?> products</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-dark"><i class="bi bi-box"></i> Products List</h5>
            <div class="d-flex gap-2">
                <span class="badge bg-soft-green text-dark border">
                    <?= $total_count ?> products
                </span>
                <span class="badge bg-soft-green text-dark border">
                    Value: <?= format_currency($total_value) ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($products) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="productsTable">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="20%">Product</th>
                                <th width="10%">SKU/Barcode</th>
                                <th width="15%">Category/Supplier</th>
                                <th width="10%">Stock</th>
                                <th width="10%">Cost/Price</th>
                                <th width="10%">Margin</th>
                                <th width="10%">Sales (30d)</th>
                                <th width="10%">Status</th>
                                <th width="10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $index => $product): 
                                $row_number = $offset + $index + 1;
                                $available_stock = $product['available_stock'];
                                $markup_percentage = $product['markup_percentage'];
                            ?>
                            <tr class="<?= $product['stock_status'] == 'out_of_stock' ? 'table-danger' : 
                                        ($product['stock_status'] == 'low_stock' ? 'table-warning' : '') ?>">
                                <td><?= $row_number ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($product['image_url'])): ?>
                                        <img src="<?= safe_output($product['image_url']) ?>" 
                                             class="rounded me-2" 
                                             style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                        <div class="bg-light rounded me-2 d-flex align-items-center justify-content-center" 
                                             style="width: 40px; height: 40px;">
                                            <i class="bi bi-box text-muted"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= safe_output($product['product_name']) ?></strong>
                                            <?php if (!empty($product['description'])): ?>
                                            <br><small class="text-muted" title="<?= safe_output($product['description']) ?>">
                                                <?= substr(safe_output($product['description']), 0, 50) ?>...
                                            </small>
                                            <?php endif; ?>
                                            <?php if (!empty($product['brand_name'])): ?>
                                            <br><small class="text-muted">
                                                <i class="bi bi-tag"></i> <span class="custom-code"><?= safe_output($product['brand_name']) ?></span>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <strong class="custom-code"><?= safe_output($product['sku']) ?></strong>
                                        <?php if (!empty($product['barcode'])): ?>
                                        <br><small class="text-muted">
                                            <i class="bi bi-upc"></i> <span class="custom-code"><?= safe_output($product['barcode']) ?></span>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($product['category_name'])): ?>
                                    <div class="badge bg-info mb-1"><?= safe_output($product['category_name']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($product['supplier_name'])): ?>
                                    <br><small class="text-muted">
                                        <i class="bi bi-truck"></i> <?= safe_output($product['supplier_name']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= get_stock_badge($product['stock_status'], $available_stock) ?>
                                    <?php if (!empty($product['warehouses'])): ?>
                                    <br><small class="text-muted">
                                        <i class="bi bi-house-door"></i> <?= safe_output($product['warehouses']) ?>
                                    </small>
                                    <?php endif; ?>
                                    <?php if ($product['min_stock_level'] > 0): ?>
                                    <br><small class="text-muted">
                                        Reorder: <?= $product['min_stock_level'] ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <small class="text-muted">Cost:</small>
                                        <strong><?= format_currency($product['cost_price']) ?></strong>
                                        <br><small class="text-muted">Sell:</small>
                                        <strong class="text-success"><?= format_currency($product['selling_price']) ?></strong>
                                        <?php if ($product['tax_rate_percentage'] > 0): ?>
                                        <br><small class="text-muted">
                                            Tax: <?= $product['tax_rate_percentage'] ?>%
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?= get_markup_color($markup_percentage) ?>">
                                        <strong><?= number_format($markup_percentage, 1) ?>%</strong>
                                    </span>
                                    <br><small class="text-muted">
                                        <?= format_currency($product['selling_price'] - $product['cost_price']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($product['sales_last_30_days'] > 0): ?>
                                    <strong><?= number_format($product['sales_last_30_days'], 1) ?></strong> units
                                    <br><small class="text-muted">
                                        Last: <?= format_date($product['last_sale_date']) ?>
                                    </small>
                                    <?php else: ?>
                                    <span class="text-muted">No sales</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($product['status']) ?>">
    <?= ucfirst($product['status']) ?>
</span>
                                    <br>
                                    <?php if (!empty($product['last_restock_date'])): ?>
                                    <small class="text-muted">
                                        Restock: <?= format_date($product['last_restock_date']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" 
                                                data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="product_view.php?id=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_products): ?>
                                            <li>
                                                <a class="dropdown-item" href="product_edit.php?id=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-pencil"></i> Edit Product
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_adjust_stock): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="adjustStock(<?= $product['product_id'] ?>)">
                                                    <i class="bi bi-box-arrow-in-down"></i> Adjust Stock
                                                </a>
                                            </li>
                                            
                                            <li>
                                                <a class="dropdown-item" href="stock_transfer.php?product=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-arrow-left-right"></i> Transfer Stock
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_products): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="duplicateProduct(<?= $product['product_id'] ?>)">
                                                    <i class="bi bi-copy"></i> Duplicate Product
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_products): ?>
                                            <li>
                                                <a class="dropdown-item" href="purchase_order_create.php?product=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-truck"></i> Order More
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_products && $product['status'] == 'active'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" 
                                                   onclick="changeStatus(<?= $product['product_id'] ?>, 'inactive')">
                                                    <i class="bi bi-slash-circle"></i> Deactivate
                                                </a>
                                            </li>
                                            <?php elseif ($can_edit_products): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" 
                                                   onclick="changeStatus(<?= $product['product_id'] ?>, 'active')">
                                                    <i class="bi bi-check-circle"></i> Activate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_products && $product['status'] == 'inactive'): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   onclick="deleteProduct(<?= $product['product_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete Product
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Product pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url(1) ?>">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url($page - 1) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url($i) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url($page + 1) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url($total_pages) ?>">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                
                <!-- Summary Statistics -->
                <div class="row mt-4">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Inventory Summary</h6>
                                <div class="d-flex justify-content-between">
                                    <span>Total Products:</span>
                                    <strong><?= $total_count ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Total Value:</span>
                                    <strong><?= format_currency($total_value) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Active Products:</span>
                                    <strong class="text-success"><?= $total_count - $inactive_count ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Inactive Products:</span>
                                    <strong class="text-secondary"><?= $inactive_count ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Stock Status</h6>
                                <div class="d-flex justify-content-between">
                                    <span>In Stock:</span>
                                    <strong class="text-success"><?= $total_count - $low_stock_count - $out_of_stock_count ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Low Stock:</span>
                                    <strong class="text-warning"><?= $low_stock_count ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Out of Stock:</span>
                                    <strong class="text-danger"><?= $out_of_stock_count ?></strong>
                                </div>
                                <div class="mt-2">
                                    <div class="progress" style="height: 20px;">
                                        <?php 
                                        $in_stock_percentage = ($total_count > 0) ? 
                                            (($total_count - $low_stock_count - $out_of_stock_count) / $total_count) * 100 : 0;
                                        $low_stock_percentage = ($total_count > 0) ? ($low_stock_count / $total_count) * 100 : 0;
                                        $out_of_stock_percentage = ($total_count > 0) ? ($out_of_stock_count / $total_count) * 100 : 0;
                                        ?>
                                        <div class="progress-bar bg-success" style="width: <?= $in_stock_percentage ?>%"></div>
                                        <div class="progress-bar bg-warning" style="width: <?= $low_stock_percentage ?>%"></div>
                                        <div class="progress-bar bg-danger" style="width: <?= $out_of_stock_percentage ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Quick Actions</h6>
                                <div class="d-grid gap-2">
                                    <?php if ($can_create_products): ?>
                                    <a href="product_create" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Add New Product
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($can_adjust_stock): ?>
                                    <a href="stock_adjustments.php" class="btn btn-warning">
                                        <i class="bi bi-arrow-left-right"></i> Bulk Stock Adjustments
                                    </a>
                                    <?php endif; ?>
                                    <a href="reports.php?report=inventory_valuation" class="btn btn-info">
                                        <i class="bi bi-graph-up"></i> View Inventory Report
                                    </a>
                                    <button type="button" class="btn btn-success" onclick="exportProducts()">
                                        <i class="bi bi-download"></i> Export Products
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-box" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Products Found</h4>
                    <p class="text-muted">No products match your filter criteria or no products have been added yet.</p>
                    <?php if ($can_create_products): ?>
                    <a href="product_create" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-circle"></i> Add Your First Product
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="stockAdjustmentModal" tabindex="-1" aria-labelledby="stockAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="stockAdjustmentModalLabel">
                    <i class="bi bi-box-arrow-in-down"></i> Adjust Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="stockAdjustmentForm">
                    <input type="hidden" id="adjust_product_id" name="product_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="adjust_product_name" readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control" id="current_stock" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Available Stock</label>
                            <input type="text" class="form-control" id="available_stock" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" id="adjustment_type" name="adjustment_type" required>
                            <option value="">Select Type</option>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                            <option value="set">Set Stock Level</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="adjustment_quantity" 
                               name="quantity" min="0.001" step="0.001" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Warehouse</label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <!-- Warehouses will be loaded via AJAX -->
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select class="form-select" id="adjustment_reason" name="reason" required>
                            <option value="">Select Reason</option>
                            <option value="damaged">Damaged Goods</option>
                            <option value="expired">Expired Products</option>
                            <option value="found">Found Stock</option>
                            <option value="theft">Theft/Loss</option>
                            <option value="correction">Stock Correction</option>
                            <option value="purchase_return">Purchase Return</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="adjustment_notes" name="notes" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info" id="new_stock_info" style="display: none;">
                        <strong>New Stock Level: <span id="new_stock_level">0</span></strong>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="submitStockAdjustment()">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<?php if ($can_create_products): ?>
<div class="fixed-bottom d-flex justify-content-end p-3" style="z-index: 1030;">
    <div class="btn-group shadow">
        <a href="product_create" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> New Product
        </a>
        <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" 
                data-bs-toggle="dropdown" aria-expanded="false">
            <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="product_create"><i class="bi bi-box"></i> Manual Entry</a></li>
            <li><a class="dropdown-item" href="product_import"><i class="bi bi-upload"></i> Import Products</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="categories"><i class="bi bi-tags"></i> Manage Categories</a></li>
            <li><a class="dropdown-item" href="brands"><i class="bi bi-tag"></i> Manage Brands</a></li>
            <li><a class="dropdown-item" href="suppliers"><i class="bi bi-truck"></i> Manage Suppliers</a></li>
        </ul>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#productsTable').DataTable({
        language: {
            search: "Search products:",
            lengthMenu: "Show _MENU_ products per page",
            info: "Showing _START_ to _END_ of _TOTAL_ products",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        pageLength: <?= $per_page ?>,
        order: [], // Disable initial sorting to maintain our custom sort
        dom: '<"top"f>rt<"bottom"lip><"clear">'
    });
    
    // Real-time stock update simulation
    setInterval(function() {
        updateStockCounts();
    }, 30000); // Update every 30 seconds
});

function adjustStock(productId) {
    $.ajax({
        url: 'api/get_product_stock.php',
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const product = response.data;
                $('#adjust_product_id').val(product.product_id);
                $('#adjust_product_name').val(product.product_name);
                $('#current_stock').val(product.total_stock);
                $('#available_stock').val(product.available_stock);
                
                // Load warehouses
                loadWarehouses(productId);
                
                $('#stockAdjustmentModal').modal('show');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
            }
        }
    });
}

function loadWarehouses(productId) {
    $.ajax({
        url: 'api/get_product_warehouses.php',
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            const select = $('#warehouse_id');
            select.empty();
            select.append('<option value="">Select Warehouse</option>');
            
            if (response.success) {
                response.data.forEach(warehouse => {
                    select.append(`<option value="${warehouse.warehouse_id}">
                        ${warehouse.warehouse_name} (Stock: ${warehouse.stock_quantity})
                    </option>`);
                });
            }
        }
    });
}

// Calculate new stock level
$('#adjustment_type, #adjustment_quantity').on('change keyup', function() {
    const type = $('#adjustment_type').val();
    const quantity = parseFloat($('#adjustment_quantity').val()) || 0;
    const current = parseFloat($('#current_stock').val()) || 0;
    
    let newStock = current;
    
    switch (type) {
        case 'add':
            newStock = current + quantity;
            break;
        case 'remove':
            newStock = current - quantity;
            break;
        case 'set':
            newStock = quantity;
            break;
    }
    
    if (type) {
        $('#new_stock_level').text(newStock.toFixed(3));
        $('#new_stock_info').show();
    } else {
        $('#new_stock_info').hide();
    }
});

function submitStockAdjustment() {
    const formData = $('#stockAdjustmentForm').serialize();
    
    if (!$('#warehouse_id').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            text: 'Please select a warehouse.'
        });
        return;
    }
    
    $.ajax({
        url: 'api/adjust_stock.php',
        type: 'POST',
        data: formData,
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
        }
    });
}

function changeStatus(productId, newStatus) {
    const action = newStatus === 'active' ? 'activate' : 'deactivate';
    const actionText = newStatus === 'active' ? 'Activate' : 'Deactivate';
    
    Swal.fire({
        title: `${actionText} Product?`,
        text: `Are you sure you want to ${action} this product?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${actionText}`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/update_product_status.php',
                type: 'POST',
                data: { 
                    product_id: productId,
                    status: newStatus
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
                }
            });
        }
    });
}

function deleteProduct(productId) {
    Swal.fire({
        title: 'Delete Product',
        text: 'Are you sure you want to delete this product? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_product.php',
                type: 'POST',
                data: { product_id: productId },
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
                }
            });
        }
    });
}

function duplicateProduct(productId) {
    Swal.fire({
        title: 'Duplicate Product',
        text: 'Create a copy of this product?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Duplicate',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/duplicate_product.php',
                type: 'POST',
                data: { product_id: productId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Duplicated!',
                            text: 'Product duplicated successfully.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'product_edit.php?id=' + response.new_product_id;
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                }
            });
        }
    });
}

function exportProducts() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    
    window.location.href = 'api/export_products.php?' + params.toString();
}

function updateStockCounts() {
    // Update low stock and out of stock badges
    $.ajax({
        url: 'api/get_stock_counts.php',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#lowStockCount').text(response.data.low_stock);
                $('#outOfStockCount').text(response.data.out_of_stock);
                
                // Highlight updates
                $('#lowStockCount').parent().addClass('highlight-update');
                $('#outOfStockCount').parent().addClass('highlight-update');
                
                setTimeout(() => {
                    $('#lowStockCount').parent().removeClass('highlight-update');
                    $('#outOfStockCount').parent().removeClass('highlight-update');
                }, 2000);
            }
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new product (when not in input field)
    if (e.ctrlKey && e.key === 'n' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        <?php if ($can_create_products): ?>
        window.location.href = 'product_create.php';
        <?php endif; ?>
    }
    
    // Ctrl + F to focus search
    if (e.ctrlKey && e.key === 'f' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        $('input[name="search"]').focus().select();
    }
    
    // F5 to refresh
    if (e.key === 'F5') {
        e.preventDefault();
        location.reload();
    }
});

// Auto-refresh data every 60 seconds
setTimeout(function() {
    if (document.hasFocus()) {
        location.reload();
    }
}, 60000);
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border: 1px solid #badbcc !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card h6,
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
    font-weight: 500;
}
.bg-soft-green {
    background-color: #d1e7dd !important;
}
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

.table thead th {
    background-color: #f8f9fa !important;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    padding: 1rem 0.5rem;
    color: #495057 !important;
}
.badge {
    padding: 0.5em 0.8em;
}

.dropdown-menu {
    font-size: 0.875rem;
    min-width: 220px;
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
.table-danger {
    background-color: rgba(220, 53, 69, 0.05);
}

.table-warning {
    background-color: rgba(255, 193, 7, 0.05);
}

/* Stock status badges */
.badge.bg-success { background-color: #198754 !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #212529; }
.badge.bg-danger { background-color: #dc3545 !important; }
.badge.bg-info { background-color: #0dcaf0 !important; color: #212529; }

/* Hover effects */
.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

/* DataTables customization */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    padding: 1rem 0;
}

/* Progress bar */
.progress-bar {
    transition: width 0.6s ease;
}

/* Highlight animation */
@keyframes highlight {
    0% { background-color: #fff3cd; }
    100% { background-color: transparent; }
}

.highlight-update {
    animation: highlight 2s;
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
    
    /* Hide some columns on mobile */
    .table td:nth-child(3),
    .table th:nth-child(3),
    .table td:nth-child(7),
    .table th:nth-child(7),
    .table td:nth-child(8),
    .table th:nth-child(8) {
        display: none;
    }
    
    .fixed-bottom {
        right: 10px;
        bottom: 10px;
    }
}

@media (max-width: 576px) {
    .col-xl-2, .col-md-4, .col-sm-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .dropdown-menu {
        position: fixed !important;
        top: auto !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        bottom: 60px !important;
    }
    
    /* Hide more columns on very small screens */
    .table td:nth-child(4),
    .table th:nth-child(4),
    .table td:nth-child(6),
    .table th:nth-child(6) {
        display: none;
    }
}
</style>

<?php
// Function to generate pagination URLs
function get_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'products.php?' . http_build_query($params);
}

// Include the footer
include("footer.php");
ob_end_flush();
?>