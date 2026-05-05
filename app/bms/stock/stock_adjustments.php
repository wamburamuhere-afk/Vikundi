<?php
// File: stock_adjustments.php
ob_start();
require_once 'header.php';

// Check user role for stock adjustment permissions
$can_adjust_stock = in_array($user_role, ['Admin', 'Manager', 'Inventory']);
if (!$can_adjust_stock) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
$adjustment_type = isset($_GET['adjustment_type']) ? $_GET['adjustment_type'] : '';
$reason = isset($_GET['reason']) ? $_GET['reason'] : '';
$user_id_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;

// Calculate offset
$offset = ($page - 1) * $per_page;

// Build query for stock adjustments
$query = "
    SELECT 
        sm.*,
        p.product_id,
        p.product_name,
        p.sku,
        p.barcode,
        u.username as adjusted_by_name,
        w.warehouse_name,
        loc.location_name,
        
        -- Calculate value
        sm.quantity * sm.unit_cost as total_value,
        
        -- Stock before/after difference
        sm.stock_after - sm.stock_before as stock_change
        
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id = p.product_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN locations loc ON sm.location_id = loc.location_id
    WHERE sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft')
";

$params = [];
$conditions = [];

// Apply filters
if (!empty($search)) {
    $conditions[] = "(
        p.product_name LIKE :search OR 
        p.sku LIKE :search OR 
        p.barcode LIKE :search OR
        sm.reference_number LIKE :search OR
        sm.notes LIKE :search
    )";
    $params[':search'] = "%$search%";
}

if ($product_id > 0) {
    $conditions[] = "sm.product_id = :product_id";
    $params[':product_id'] = $product_id;
}

if ($warehouse_id > 0) {
    $conditions[] = "sm.warehouse_id = :warehouse_id";
    $params[':warehouse_id'] = $warehouse_id;
}

if (!empty($adjustment_type)) {
    $conditions[] = "sm.movement_type = :adjustment_type";
    $params[':adjustment_type'] = $adjustment_type;
}

if (!empty($reason)) {
    $conditions[] = "sm.reason = :reason";
    $params[':reason'] = $reason;
}

if ($user_id_filter > 0) {
    $conditions[] = "sm.created_by = :user_id";
    $params[':user_id'] = $user_id_filter;
}

if (!empty($date_from)) {
    $conditions[] = "DATE(sm.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(sm.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

// Add sorting and pagination
$query .= " ORDER BY sm.created_at DESC LIMIT :limit OFFSET :offset";

try {
    // Execute main query
    $stmt = $pdo->prepare($query);
    
    // Bind parameters for main query
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM stock_movements sm LEFT JOIN products p ON sm.product_id = p.product_id WHERE sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft')";
    if (!empty($conditions)) {
        $count_query .= " AND " . implode(" AND ", $conditions);
    }
    
    $count_stmt = $pdo->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_count / $per_page);
    
} catch (PDOException $e) {
    $adjustments = [];
    $total_count = 0;
    $total_pages = 0;
    $error_message = $e->getMessage();
}

// Get data for filter dropdowns
try {
    $products = $pdo->query("SELECT product_id, product_name, sku FROM products WHERE status = 'active' ORDER BY product_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $products = [];
}

try {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

try {
    $users = $pdo->query("SELECT user_id, username FROM users WHERE status = 'active' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Adjustment types
$adjustment_types = [
    'adjustment_in' => 'Stock In (Add)',
    'adjustment_out' => 'Stock Out (Remove)',
    'correction' => 'Stock Correction',
    'damaged' => 'Damaged Goods',
    'expired' => 'Expired Products',
    'found' => 'Found Stock',
    'theft' => 'Theft/Loss'
];

// Reasons
$reasons = [
    'damaged' => 'Damaged Goods',
    'expired' => 'Expired Products',
    'found' => 'Found Stock',
    'theft' => 'Theft/Loss',
    'correction' => 'Stock Correction',
    'purchase_return' => 'Purchase Return',
    'quality_check' => 'Quality Check',
    'display_sample' => 'Display Sample',
    'demo_unit' => 'Demo Unit',
    'employee_use' => 'Employee Use',
    'other' => 'Other'
];

// Calculate statistics
$total_adjustments = $total_count;
$total_quantity_in = 0;
$total_quantity_out = 0;
$total_value_in = 0;
$total_value_out = 0;

foreach ($adjustments as $adjustment) {
    if (in_array($adjustment['movement_type'], ['adjustment_in', 'found'])) {
        $total_quantity_in += $adjustment['quantity'];
        $total_value_in += ($adjustment['quantity'] * $adjustment['unit_cost']);
    } else {
        $total_quantity_out += $adjustment['quantity'];
        $total_value_out += ($adjustment['quantity'] * $adjustment['unit_cost']);
    }
}

$net_quantity_change = $total_quantity_in - $total_quantity_out;
$net_value_change = $total_value_in - $total_value_out;

// format_currency removed, now in helpers.php

function get_adjustment_type_badge($type) {
    $badges = [
        'adjustment_in' => 'success',
        'adjustment_out' => 'danger',
        'correction' => 'warning',
        'damaged' => 'dark',
        'expired' => 'secondary',
        'found' => 'info',
        'theft' => 'danger'
    ];
    
    $labels = [
        'adjustment_in' => 'Stock In',
        'adjustment_out' => 'Stock Out',
        'correction' => 'Correction',
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'found' => 'Found',
        'theft' => 'Theft'
    ];
    
    $color = $badges[$type] ?? 'secondary';
    $label = $labels[$type] ?? $type;
    
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

function get_quantity_display($quantity, $type, $unit) {
    $class = '';
    $sign = '';
    
    if (in_array($type, ['adjustment_in', 'found'])) {
        $class = 'text-success';
        $sign = '+';
    } else {
        $class = 'text-danger';
        $sign = '-';
    }
    
    return '<span class="' . $class . ' fw-bold">' . $sign . number_format($quantity, 3) . ' ' . $unit . '</span>';
}

// Helper functions removed, now in helpers.php
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-arrow-left-right"></i> Stock Adjustments</h2>
                    <p class="text-muted mb-0">Manage stock adjustments and corrections</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                        <i class="bi bi-plus-circle"></i> New Adjustment
                    </a>
                    <button type="button" class="btn btn-success" onclick="exportAdjustments()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="bi bi-box"></i> View Products
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_adjustments ?></h4>
                            <p class="mb-0">Total Adjustments</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-list-check" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($total_quantity_in, 3) ?></h4>
                            <p class="mb-0">Total Stock In</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-arrow-in-down" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small><?= format_currency($total_value_in) ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($total_quantity_out, 3) ?></h4>
                            <p class="mb-0">Total Stock Out</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-arrow-up" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small><?= format_currency($total_value_out) ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($net_quantity_change, 3) ?></h4>
                            <p class="mb-0">Net Change</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small><?= format_currency($net_value_change) ?></small>
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
                               placeholder="Product, SKU, or Notes">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Product</label>
                        <select class="form-select" name="product_id">
                            <option value="">All Products</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= $product['product_id'] ?>" 
                                    <?= $product_id == $product['product_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($product['product_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Warehouse</label>
                        <select class="form-select" name="warehouse_id">
                            <option value="">All Warehouses</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= $warehouse['warehouse_id'] ?>" 
                                    <?= $warehouse_id == $warehouse['warehouse_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($warehouse['warehouse_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" name="adjustment_type">
                            <option value="">All Types</option>
                            <?php foreach ($adjustment_types as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $adjustment_type == $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Reason</label>
                        <select class="form-select" name="reason">
                            <option value="">All Reasons</option>
                            <?php foreach ($reasons as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $reason == $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Adjusted By</label>
                        <select class="form-select" name="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>" 
                                    <?= $user_id_filter == $user['user_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($user['username']) ?>
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
                        <a href="stock_adjustments.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                        <div class="ms-3">
                            <span class="text-muted">Showing <?= count($adjustments) ?> of <?= $total_count ?> adjustments</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock Adjustments Table -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Adjustments History</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $total_count ?> adjustments
                </span>
                <span class="badge bg-light text-dark">
                    Net: <?= number_format($net_quantity_change, 3) ?> units
                </span>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($adjustments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="adjustmentsTable">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="15%">Date & Time</th>
                                <th width="20%">Product</th>
                                <th width="10%">Warehouse</th>
                                <th width="15%">Adjustment Details</th>
                                <th width="10%">Value</th>
                                <th width="10%">Reason</th>
                                <th width="10%">Adjusted By</th>
                                <th width="5%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustments as $index => $adjustment): 
                                $row_number = $offset + $index + 1;
                                $stock_before = $adjustment['stock_before'];
                                $stock_after = $adjustment['stock_after'];
                                $stock_change = $adjustment['stock_change'];
                                $total_value = $adjustment['total_value'];
                            ?>
                            <tr>
                                <td><?= $row_number ?></td>
                                <td>
                                    <small><?= format_date($adjustment['created_at']) ?></small>
                                    <?php if (!empty($adjustment['reference_number'])): ?>
                                    <br><small class="text-muted">
                                        Ref: <?= safe_output($adjustment['reference_number']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= safe_output($adjustment['product_name']) ?></strong>
                                    <?php if (!empty($adjustment['sku'])): ?>
                                    <br><small class="text-muted">
                                        SKU: <?= safe_output($adjustment['sku']) ?>
                                    </small>
                                    <?php endif; ?>
                                    <?php if (!empty($adjustment['barcode'])): ?>
                                    <br><small class="text-muted">
                                        Barcode: <?= safe_output($adjustment['barcode']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= safe_output($adjustment['warehouse_name']) ?>
                                    <?php if (!empty($adjustment['location_name'])): ?>
                                    <br><small class="text-muted">
                                        Loc: <?= safe_output($adjustment['location_name']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="mb-1">
                                        <?= get_adjustment_type_badge($adjustment['movement_type']) ?>
                                        <?= get_quantity_display($adjustment['quantity'], $adjustment['movement_type'], $adjustment['unit']) ?>
                                    </div>
                                    <div class="small text-muted">
                                        Before: <?= number_format($stock_before, 3) ?> | 
                                        After: <?= number_format($stock_after, 3) ?>
                                    </div>
                                    <?php if (!empty($adjustment['notes'])): ?>
                                    <div class="small mt-1">
                                        <i class="bi bi-chat-left-text"></i> 
                                        <?= substr(safe_output($adjustment['notes']), 0, 50) ?>
                                        <?= strlen($adjustment['notes']) > 50 ? '...' : '' ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= format_currency($total_value) ?></strong>
                                    <br><small class="text-muted">
                                        Unit: <?= format_currency($adjustment['unit_cost']) ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= safe_output($adjustment['reason']) ?></span>
                                </td>
                                <td>
                                    <?= safe_output($adjustment['adjusted_by_name']) ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" 
                                                data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick="viewAdjustmentDetails(<?= $adjustment['movement_id'] ?>)">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" 
                                                   onclick="printAdjustment(<?= $adjustment['movement_id'] ?>)">
                                                    <i class="bi bi-printer"></i> Print
                                                </a>
                                            </li>
                                            <?php if ($user_role === 'Admin'): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   onclick="deleteAdjustment(<?= $adjustment['movement_id'] ?>)">
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Adjustments pagination">
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
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Adjustment Summary</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Count</th>
                                                <th>Quantity</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $type_totals = [];
                                            foreach ($adjustments as $adj) {
                                                $type = $adj['movement_type'];
                                                if (!isset($type_totals[$type])) {
                                                    $type_totals[$type] = [
                                                        'count' => 0,
                                                        'quantity' => 0,
                                                        'value' => 0
                                                    ];
                                                }
                                                $type_totals[$type]['count']++;
                                                $type_totals[$type]['quantity'] += $adj['quantity'];
                                                $type_totals[$type]['value'] += ($adj['quantity'] * $adj['unit_cost']);
                                            }
                                            
                                            foreach ($type_totals as $type => $totals):
                                            ?>
                                            <tr>
                                                <td><?= get_adjustment_type_badge($type) ?></td>
                                                <td><?= $totals['count'] ?></td>
                                                <td class="<?= in_array($type, ['adjustment_in', 'found']) ? 'text-success' : 'text-danger' ?>">
                                                    <?= in_array($type, ['adjustment_in', 'found']) ? '+' : '-' ?><?= number_format($totals['quantity'], 3) ?>
                                                </td>
                                                <td><?= format_currency($totals['value']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Quick Actions</h6>
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                                        <i class="bi bi-plus-circle"></i> New Stock Adjustment
                                    </button>
                                    <button type="button" class="btn btn-success" onclick="exportAdjustments()">
                                        <i class="bi bi-download"></i> Export to Excel
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="bulkAdjustment()">
                                        <i class="bi bi-upload"></i> Bulk Adjustment
                                    </button>
                                    <a href="reports.php?report=stock_adjustments" class="btn btn-warning">
                                        <i class="bi bi-graph-up"></i> Adjustment Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-arrow-left-right" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Stock Adjustments Found</h4>
                    <p class="text-muted">No adjustments match your filter criteria or no adjustments have been made yet.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                        <i class="bi bi-plus-circle"></i> Make Your First Adjustment
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Adjustment Modal -->
<div class="modal fade" id="newAdjustmentModal" tabindex="-1" aria-labelledby="newAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="newAdjustmentModalLabel">
                    <i class="bi bi-plus-circle"></i> New Stock Adjustment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="adjustmentForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_product_id" class="form-label">Product <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_product_id" name="product_id" required onchange="loadProductStock()">
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['product_id'] ?>">
                                        <?= safe_output($product['product_name']) ?> (<?= safe_output($product['sku']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_warehouse_id" name="warehouse_id" required onchange="loadProductStock()">
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?= $warehouse['warehouse_id'] ?>">
                                        <?= safe_output($warehouse['warehouse_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control" id="current_stock_display" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Available Stock</label>
                            <input type="text" class="form-control" id="available_stock_display" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" id="product_unit_display" readonly>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_type" class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_type" name="movement_type" required onchange="updateQuantityPlaceholder()">
                                <option value="">Select Type</option>
                                <option value="adjustment_in">Add Stock (Stock In)</option>
                                <option value="adjustment_out">Remove Stock (Stock Out)</option>
                                <option value="correction">Stock Correction</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="expired">Expired Products</option>
                                <option value="found">Found Stock</option>
                                <option value="theft">Theft/Loss</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="adjustment_quantity" 
                                       name="quantity" min="0.001" step="0.001" required>
                                <span class="input-group-text" id="quantity_unit_display">pcs</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="unit_cost" class="form-label">Unit Cost</label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" class="form-control" id="unit_cost" 
                                       name="unit_cost" min="0" step="0.01" value="0.00">
                            </div>
                            <small class="text-muted">Leave as 0 to use product's cost price</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_reason" name="reason" required>
                                <option value="">Select Reason</option>
                                <?php foreach ($reasons as $value => $label): ?>
                                    <option value="<?= $value ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="adjustment_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="adjustment_notes" name="notes" rows="2" 
                                  placeholder="Additional information about this adjustment"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reference_number" class="form-label">Reference Number (Optional)</label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" 
                               placeholder="e.g., Adjustment-001">
                    </div>
                    
                    <div class="alert alert-info" id="new_stock_calculation" style="display: none;">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Current Stock:</strong> <span id="current_stock_value">0</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Adjustment:</strong> <span id="adjustment_value">0</span>
                            </div>
                            <div class="col-md-4">
                                <strong>New Stock:</strong> <span id="new_stock_value">0</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAdjustment()">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Adjustment Modal -->
<div class="modal fade" id="bulkAdjustmentModal" tabindex="-1" aria-labelledby="bulkAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="bulkAdjustmentModalLabel">
                    <i class="bi bi-upload"></i> Bulk Stock Adjustment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Instructions:</strong> Upload a CSV file with product SKU/barcode, quantity, and adjustment details.
                    <a href="#" class="alert-link" onclick="downloadBulkTemplate()">Download template</a>
                </div>
                
                <div class="mb-3">
                    <label for="bulkFile" class="form-label">Upload CSV File</label>
                    <input type="file" class="form-control" id="bulkFile" accept=".csv">
                </div>
                
                <div class="mb-3">
                    <label for="bulkAdjustmentType" class="form-label">Default Adjustment Type</label>
                    <select class="form-select" id="bulkAdjustmentType">
                        <option value="adjustment_in">Add Stock (Stock In)</option>
                        <option value="adjustment_out">Remove Stock (Stock Out)</option>
                        <option value="correction">Stock Correction</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="bulkReason" class="form-label">Default Reason</label>
                    <select class="form-select" id="bulkReason">
                        <?php foreach ($reasons as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="bulkWarehouse" class="form-label">Default Warehouse</label>
                    <select class="form-select" id="bulkWarehouse">
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= $warehouse['warehouse_id'] ?>">
                                <?= safe_output($warehouse['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="bulkPreview" style="display: none;">
                    <h6>Preview</h6>
                    <div class="table-responsive">
                        <table class="table table-sm" id="bulkPreviewTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Quantity</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Preview rows will be added here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="processBulkAdjustment()">Process Bulk Adjustment</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#adjustmentsTable').DataTable({
        language: {
            search: "Search adjustments:",
            lengthMenu: "Show _MENU_ adjustments per page",
            info: "Showing _START_ to _END_ of _TOTAL_ adjustments",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        pageLength: <?= $per_page ?>,
        order: [[1, 'desc']],
        dom: '<"top"f>rt<"bottom"lip><"clear">'
    });
    
    // Reset form when modal is closed
    $('#newAdjustmentModal').on('hidden.bs.modal', function() {
        $('#adjustmentForm')[0].reset();
        $('#current_stock_display').val('');
        $('#available_stock_display').val('');
        $('#product_unit_display').val('');
        $('#new_stock_calculation').hide();
    });
    
    // Bulk file upload preview
    $('#bulkFile').change(function() {
        const file = this.files[0];
        if (file) {
            previewBulkFile(file);
        }
    });
});

function loadProductStock() {
    const productId = $('#adjustment_product_id').val();
    const warehouseId = $('#adjustment_warehouse_id').val();
    
    if (!productId || !warehouseId) {
        return;
    }
    
    $.ajax({
        url: 'api/get_product_stock.php',
        type: 'GET',
        data: {
            product_id: productId,
            warehouse_id: warehouseId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const product = response.data;
                $('#current_stock_display').val(product.total_stock);
                $('#available_stock_display').val(product.available_stock);
                $('#product_unit_display').val(product.unit);
                $('#quantity_unit_display').text(product.unit);
                $('#unit_cost').val(product.cost_price);
                
                // Update calculation
                updateStockCalculation();
            }
        }
    });
}

function updateQuantityPlaceholder() {
    const type = $('#adjustment_type').val();
    if (type === 'adjustment_out' || type === 'damaged' || type === 'expired' || type === 'theft') {
        $('#adjustment_quantity').attr('placeholder', 'Quantity to remove');
    } else {
        $('#adjustment_quantity').attr('placeholder', 'Quantity to add');
    }
    updateStockCalculation();
}

function updateStockCalculation() {
    const currentStock = parseFloat($('#current_stock_display').val()) || 0;
    const quantity = parseFloat($('#adjustment_quantity').val()) || 0;
    const type = $('#adjustment_type').val();
    
    let newStock = currentStock;
    let adjustmentDisplay = '';
    
    if (type) {
        if (type === 'adjustment_in' || type === 'found') {
            newStock = currentStock + quantity;
            adjustmentDisplay = '+ ' + quantity;
        } else {
            newStock = currentStock - quantity;
            adjustmentDisplay = '- ' + quantity;
        }
        
        $('#current_stock_value').text(currentStock);
        $('#adjustment_value').text(adjustmentDisplay);
        $('#new_stock_value').text(newStock);
        $('#new_stock_calculation').show();
    } else {
        $('#new_stock_calculation').hide();
    }
}

$('#adjustment_quantity').on('input', function() {
    updateStockCalculation();
});

function submitAdjustment() {
    const formData = $('#adjustmentForm').serialize();
    
    // Validate form
    if (!$('#adjustment_product_id').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Product',
            text: 'Please select a product.'
        });
        return;
    }
    
    if (!$('#adjustment_warehouse_id').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Warehouse',
            text: 'Please select a warehouse.'
        });
        return;
    }
    
    if (!$('#adjustment_type').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Type',
            text: 'Please select an adjustment type.'
        });
        return;
    }
    
    const quantity = parseFloat($('#adjustment_quantity').val());
    if (!quantity || quantity <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Quantity',
            text: 'Please enter a valid quantity.'
        });
        return;
    }
    
    // For stock out, check if enough stock is available
    const type = $('#adjustment_type').val();
    const availableStock = parseFloat($('#available_stock_display').val()) || 0;
    
    if ((type === 'adjustment_out' || type === 'damaged' || type === 'expired' || type === 'theft') && quantity > availableStock) {
        Swal.fire({
            icon: 'warning',
            title: 'Insufficient Stock',
            text: 'Cannot remove more stock than available (' + availableStock + ' available).',
            showCancelButton: true,
            confirmButtonText: 'Proceed Anyway',
            cancelButtonText: 'Adjust Quantity'
        }).then((result) => {
            if (result.isConfirmed) {
                saveAdjustment(formData);
            }
        });
        return;
    }
    
    saveAdjustment(formData);
}

function saveAdjustment(formData) {
    $.ajax({
        url: 'api/create_stock_adjustment.php',
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
                    $('#newAdjustmentModal').modal('hide');
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

function viewAdjustmentDetails(adjustmentId) {
    $.ajax({
        url: 'api/get_adjustment_details.php',
        type: 'GET',
        data: { adjustment_id: adjustmentId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const adj = response.data;
                
                const details = `
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Product:</strong> ${adj.product_name}</p>
                            <p><strong>SKU:</strong> ${adj.sku || 'N/A'}</p>
                            <p><strong>Barcode:</strong> ${adj.barcode || 'N/A'}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Warehouse:</strong> ${adj.warehouse_name}</p>
                            <p><strong>Location:</strong> ${adj.location_name || 'N/A'}</p>
                            <p><strong>Date:</strong> ${new Date(adj.created_at).toLocaleString()}</p>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p><strong>Adjustment Type:</strong> ${getAdjustmentTypeLabel(adj.movement_type)}</p>
                            <p><strong>Quantity:</strong> ${adj.quantity} ${adj.unit}</p>
                            <p><strong>Unit Cost:</strong> ${formatCurrency(adj.unit_cost)}</p>
                            <p><strong>Total Value:</strong> ${formatCurrency(adj.quantity * adj.unit_cost)}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Reason:</strong> ${adj.reason}</p>
                            <p><strong>Stock Before:</strong> ${adj.stock_before}</p>
                            <p><strong>Stock After:</strong> ${adj.stock_after}</p>
                            <p><strong>Adjusted By:</strong> ${adj.adjusted_by_name}</p>
                        </div>
                    </div>
                    ${adj.notes ? `<div class="row mt-3">
                        <div class="col-12">
                            <p><strong>Notes:</strong> ${adj.notes}</p>
                        </div>
                    </div>` : ''}
                `;
                
                Swal.fire({
                    title: 'Adjustment Details',
                    html: details,
                    width: 800,
                    showCloseButton: true,
                    showConfirmButton: false
                });
            }
        }
    });
}

function getAdjustmentTypeLabel(type) {
    const labels = {
        'adjustment_in': 'Stock In',
        'adjustment_out': 'Stock Out',
        'correction': 'Correction',
        'damaged': 'Damaged Goods',
        'expired': 'Expired Products',
        'found': 'Found Stock',
        'theft': 'Theft/Loss'
    };
    return labels[type] || type;
}

function formatCurrency(amount) {
    return 'TZS ' + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function printAdjustment(adjustmentId) {
    const printWindow = window.open(`adjustment_print.php?id=${adjustmentId}`, '_blank');
    if (printWindow) {
        printWindow.focus();
    }
}

function deleteAdjustment(adjustmentId) {
    Swal.fire({
        title: 'Delete Adjustment',
        text: 'Are you sure you want to delete this adjustment? This will reverse the stock change.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_adjustment.php',
                type: 'POST',
                data: { adjustment_id: adjustmentId },
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

function exportAdjustments() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    
    window.location.href = 'api/export_adjustments.php?' + params.toString();
}

function bulkAdjustment() {
    $('#bulkAdjustmentModal').modal('show');
}

function downloadBulkTemplate() {
    // Create CSV template
    const headers = ['sku', 'quantity', 'movement_type', 'reason', 'warehouse_id', 'unit_cost', 'notes'];
    const example = ['PROD001', '10', 'adjustment_in', 'found', '1', '1000', 'Found in storage'];
    
    const csvContent = [
        headers.join(','),
        example.join(','),
        '# Fill in the data below',
        '# sku: Product SKU or Barcode',
        '# quantity: Adjustment quantity (positive number)',
        '# movement_type: adjustment_in, adjustment_out, correction, damaged, expired, found, theft',
        '# reason: Reason for adjustment',
        '# warehouse_id: Warehouse ID (optional - uses default if empty)',
        '# unit_cost: Unit cost (optional - uses product cost if empty)',
        '# notes: Additional notes (optional)'
    ].join('\n');
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'bulk_adjustment_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

function previewBulkFile(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const content = e.target.result;
        const lines = content.split('\n').filter(line => line.trim() && !line.startsWith('#'));
        
        if (lines.length < 2) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid File',
                text: 'CSV file must contain data rows.'
            });
            return;
        }
        
        const headers = lines[0].split(',');
        const requiredHeaders = ['sku', 'quantity', 'movement_type'];
        
        for (const header of requiredHeaders) {
            if (!headers.includes(header)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Columns',
                    text: `CSV must contain "${header}" column.`
                });
                return;
            }
        }
        
        // Parse and preview data
        const previewBody = $('#bulkPreviewTable tbody');
        previewBody.empty();
        
        for (let i = 1; i < Math.min(lines.length, 6); i++) {
            const values = lines[i].split(',');
            const row = {
                sku: values[headers.indexOf('sku')] || '',
                quantity: values[headers.indexOf('quantity')] || '',
                movement_type: values[headers.indexOf('movement_type')] || $('#bulkAdjustmentType').val(),
                reason: values[headers.indexOf('reason')] || $('#bulkReason').val(),
                warehouse_id: values[headers.indexOf('warehouse_id')] || $('#bulkWarehouse').val()
            };
            
            const html = `
                <tr>
                    <td>${row.sku}</td>
                    <td>${row.sku}</td>
                    <td>${row.quantity}</td>
                    <td>${getAdjustmentTypeLabel(row.movement_type)}</td>
                    <td>${row.reason}</td>
                </tr>
            `;
            previewBody.append(html);
        }
        
        $('#bulkPreview').show();
    };
    reader.readAsText(file);
}

function processBulkAdjustment() {
    const file = $('#bulkFile')[0].files[0];
    if (!file) {
        Swal.fire({
            icon: 'warning',
            title: 'No File',
            text: 'Please select a CSV file to upload.'
        });
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('default_type', $('#bulkAdjustmentType').val());
    formData.append('default_reason', $('#bulkReason').val());
    formData.append('default_warehouse', $('#bulkWarehouse').val());
    
    Swal.fire({
        title: 'Processing Bulk Adjustment',
        text: 'Please wait while we process your file...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    $.ajax({
        url: 'api/process_bulk_adjustment.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    html: `
                        <p>${response.message}</p>
                        <p><strong>Total Processed:</strong> ${response.processed}</p>
                        <p><strong>Successful:</strong> ${response.success_count}</p>
                        <p><strong>Failed:</strong> ${response.failed_count}</p>
                        ${response.failed_count > 0 ? 
                            `<p><a href="#" onclick="viewFailedAdjustments()">View failed adjustments</a></p>` : 
                            ''}
                    `,
                    showConfirmButton: true,
                    confirmButtonText: 'OK'
                }).then(() => {
                    $('#bulkAdjustmentModal').modal('hide');
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
        error: function() {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to process bulk adjustment.'
            });
        }
    });
}

function viewFailedAdjustments() {
    // This would typically open a modal showing failed adjustments
    Swal.fire({
        title: 'Failed Adjustments',
        text: 'Some adjustments failed. Please check your CSV file and try again.',
        icon: 'warning'
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new adjustment
    if (e.ctrlKey && e.key === 'n' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        $('#newAdjustmentModal').modal('show');
    }
    
    // Ctrl + B for bulk adjustment
    if (e.ctrlKey && e.key === 'b' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        bulkAdjustment();
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

// Auto-refresh adjustments every 60 seconds
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

/* Adjustment type colors */
.badge.bg-success { background-color: #198754 !important; }
.badge.bg-danger { background-color: #dc3545 !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #212529; }
.badge.bg-dark { background-color: #212529 !important; }
.badge.bg-secondary { background-color: #6c757d !important; }
.badge.bg-info { background-color: #0dcaf0 !important; color: #212529; }

/* Stock change colors */
.text-success { color: #198754 !important; }
.text-danger { color: #dc3545 !important; }

/* Hover effects */
.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

/* Modal styling */
.modal-lg {
    max-width: 800px;
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
    .table td:nth-child(5),
    .table th:nth-child(5),
    .table td:nth-child(7),
    .table th:nth-child(7) {
        display: none;
    }
}

@media (max-width: 576px) {
    .col-xl-3, .col-md-6, .col-sm-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .modal-dialog {
        margin: 0.5rem;
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
// Function to generate pagination URLs
function get_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'stock_adjustments.php?' . http_build_query($params);
}

// Include the footer
include("footer.php");
ob_end_flush();
?>