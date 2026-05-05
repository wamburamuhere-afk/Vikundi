<?php
include 'header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user role and permissions
$user_id = $_SESSION['user_id'];

// Check if user has permission to view warehouses
$can_view_warehouses = in_array($user_role, ['Admin', 'Manager', 'Inventory']);
$can_add_warehouses = in_array($user_role, ['Admin', 'Manager']);
$can_edit_warehouses = in_array($user_role, ['Admin', 'Manager']);
$can_delete_warehouses = in_array($user_role, ['Admin']);
$can_manage_warehouse_settings = in_array($user_role, ['Admin', 'Manager']);

if (!$can_view_warehouses) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid form submission";
        header("Location: warehouses.php");
        exit();
    }

    // Add new warehouse
    if (isset($_POST['add_warehouse']) && $can_add_warehouses) {
        $warehouse_name = trim($_POST['warehouse_name']);
        $warehouse_code = trim($_POST['warehouse_code']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $country = trim($_POST['country']);
        $postal_code = trim($_POST['postal_code']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $manager_name = trim($_POST['manager_name']);
        $manager_phone = trim($_POST['manager_phone']);
        $capacity = $_POST['capacity'] ?: null;
        $status = $_POST['status'];
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $notes = trim($_POST['notes']);

        // Validate input
        $errors = [];
        if (empty($warehouse_name)) {
            $errors[] = "Warehouse name is required";
        }
        if (empty($warehouse_code)) {
            $errors[] = "Warehouse code is required";
        }

        if (empty($errors)) {
            try {
                // If setting as primary, update all others to not primary
                if ($is_primary) {
                    $pdo->query("UPDATE warehouses SET is_primary = 0 WHERE is_primary = 1");
                }

                $query = "INSERT INTO warehouses (
                    warehouse_name, warehouse_code, address, city, state, country, 
                    postal_code, phone, email, manager_name, manager_phone, 
                    capacity, status, is_primary, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_name, $warehouse_code, $address, $city, $state, $country,
                    $postal_code, $phone, $email, $manager_name, $manager_phone,
                    $capacity, $status, $is_primary, $notes, $user_id
                ]);

                $warehouse_id = $pdo->lastInsertId();
                
                // Create default location for the warehouse
                $query = "INSERT INTO locations (
                    warehouse_id, location_name, location_code, location_type, 
                    capacity, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_id,
                    "Main Storage Area",
                    "MAIN",
                    "storage",
                    $capacity,
                    "active",
                    $user_id
                ]);

                $_SESSION['success'] = "Warehouse added successfully!";
                header("Location: warehouses.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }

    // Update warehouse
    if (isset($_POST['update_warehouse']) && $can_edit_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);
        $warehouse_name = trim($_POST['warehouse_name']);
        $warehouse_code = trim($_POST['warehouse_code']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $country = trim($_POST['country']);
        $postal_code = trim($_POST['postal_code']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $manager_name = trim($_POST['manager_name']);
        $manager_phone = trim($_POST['manager_phone']);
        $capacity = $_POST['capacity'] ?: null;
        $status = $_POST['status'];
        $is_primary = isset($_POST['is_primary']) ? 1 : 0;
        $notes = trim($_POST['notes']);

        // Validate input
        $errors = [];
        if (empty($warehouse_name)) {
            $errors[] = "Warehouse name is required";
        }
        if (empty($warehouse_code)) {
            $errors[] = "Warehouse code is required";
        }

        if (empty($errors)) {
            try {
                // If setting as primary, update all others to not primary
                if ($is_primary) {
                    $pdo->query("UPDATE warehouses SET is_primary = 0 WHERE is_primary = 1 AND warehouse_id != ?");
                }

                $query = "UPDATE warehouses SET
                    warehouse_name = ?, warehouse_code = ?, address = ?, city = ?, 
                    state = ?, country = ?, postal_code = ?, phone = ?, email = ?, 
                    manager_name = ?, manager_phone = ?, capacity = ?, status = ?, 
                    is_primary = ?, notes = ?, updated_by = ?, updated_at = NOW()
                    WHERE warehouse_id = ?";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $warehouse_name, $warehouse_code, $address, $city, $state, $country,
                    $postal_code, $phone, $email, $manager_name, $manager_phone,
                    $capacity, $status, $is_primary, $notes, $user_id, $warehouse_id
                ]);

                $_SESSION['success'] = "Warehouse updated successfully!";
                header("Location: warehouses.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
    }

    // Delete warehouse
    if (isset($_POST['delete_warehouse']) && $can_delete_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);
        
        try {
            // Check if warehouse has stock
            $query = "SELECT SUM(stock_quantity) as total_stock FROM product_stocks WHERE warehouse_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$warehouse_id]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stock['total_stock'] > 0) {
                $_SESSION['error'] = "Cannot delete warehouse with existing stock. Transfer stock first.";
                header("Location: warehouses.php");
                exit();
            }

            // Check if warehouse has locations
            $query = "SELECT COUNT(*) as location_count FROM locations WHERE warehouse_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$warehouse_id]);
            $locations = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($locations['location_count'] > 0) {
                $_SESSION['error'] = "Cannot delete warehouse with locations. Delete locations first.";
                header("Location: warehouses.php");
                exit();
            }

            // Soft delete (update status to deleted)
            $query = "UPDATE warehouses SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$user_id, $warehouse_id]);

            $_SESSION['success'] = "Warehouse deleted successfully!";
            header("Location: warehouses.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }

    // Toggle warehouse status
    if (isset($_POST['toggle_status']) && $can_edit_warehouses) {
        $warehouse_id = intval($_POST['warehouse_id']);
        $new_status = $_POST['new_status'];
        
        try {
            $query = "UPDATE warehouses SET status = ?, updated_by = ?, updated_at = NOW() WHERE warehouse_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$new_status, $user_id, $warehouse_id]);

            $_SESSION['success'] = "Warehouse status updated!";
            header("Location: warehouses.php");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(warehouse_name LIKE ? OR warehouse_code LIKE ? OR city LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

// Exclude deleted warehouses
$where_conditions[] = "status != 'deleted'";

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM warehouses $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_count / $limit);

// Get warehouses with pagination
$query = "
    SELECT 
        w.*,
        u.username as created_by_name,
        u2.username as updated_by_name,
        (SELECT COUNT(*) FROM locations WHERE warehouse_id = w.warehouse_id AND status = 'active') as location_count,
        (SELECT SUM(product_stocks.stock_quantity) FROM product_stocks WHERE warehouse_id = w.warehouse_id) as total_stock,
        (SELECT COUNT(DISTINCT product_id) FROM product_stocks WHERE warehouse_id = w.warehouse_id) as product_count,
        (SELECT SUM(ps.stock_quantity * cost_price) FROM product_stocks ps 
         JOIN products p ON ps.product_id = p.product_id 
         WHERE ps.warehouse_id = w.warehouse_id) as stock_value
    FROM warehouses w
    LEFT JOIN users u ON w.created_by = u.user_id
    LEFT JOIN users u2 ON w.updated_by = u2.user_id
    $where_clause
    ORDER BY w.is_primary DESC, w.warehouse_name
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_warehouses,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_warehouses,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_warehouses,
        SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as primary_warehouses,
        (SELECT COUNT(*) FROM locations WHERE status = 'active') as total_locations
    FROM warehouses 
    WHERE status != 'deleted'
";

$stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// format_currency removed, now in helpers.php

// Helper functions removed, now in helpers.php
function get_primary_badge($is_primary) {
    if ($is_primary) {
        return '<span class="badge bg-primary"><i class="bi bi-star-fill"></i> Primary</span>';
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouses  Management</title>
    
   
    <style>
        .warehouse-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .warehouse-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .primary-warehouse {
            border-left: 4px solid #0d6efd;
        }
        .warehouse-stats {
            font-size: 0.9rem;
        }
        .capacity-bar {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .capacity-fill {
            height: 100%;
            border-radius: 4px;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .active-dot { background-color: #198754; }
        .inactive-dot { background-color: #6c757d; }
        .maintenance-dot { background-color: #ffc107; }
    
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
</head>
<body>
    
    <div class="container-fluid mt-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="bi bi-house-door"></i> Warehouse Management</h2>
                        <p class="text-muted mb-0">Manage warehouses and their locations</p>
                    </div>
                    <div>
                        <?php if ($can_add_warehouses): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
                            <i class="bi bi-plus-circle"></i> Add New Warehouse
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#filtersModal">
                            <i class="bi bi-funnel"></i> Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card custom-stat-card text-white"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Warehouses</h6>
                                <h2 class="mb-0"><?= $stats['total_warehouses'] ?></h2>
                            </div>
                            <i class="bi bi-house-door" style="font-size: 2.5rem; opacity: 0.7;"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card custom-stat-card text-white"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Active Warehouses</h6>
                                <h2 class="mb-0"><?= $stats['active_warehouses'] ?></h2>
                            </div>
                            <i class="bi bi-check-circle" style="font-size: 2.5rem; opacity: 0.7;"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card custom-stat-card text-white"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Total Locations</h6>
                                <h2 class="mb-0"><?= $stats['total_locations'] ?></h2>
                            </div>
                            <i class="bi bi-map" style="font-size: 2.5rem; opacity: 0.7;"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card custom-stat-card text-dark"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="card-title">Primary Warehouses</h6>
                                <h2 class="mb-0"><?= $stats['primary_warehouses'] ?></h2>
                            </div>
                            <i class="bi bi-star-fill" style="font-size: 2.5rem; opacity: 0.7;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Warehouses Grid View -->
        <div class="row mb-4">
            <?php if (empty($warehouses)): ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="bi bi-house-door" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3">No warehouses found</h4>
                    <p class="text-muted"><?= $can_add_warehouses ? 'Get started by adding your first warehouse.' : 'No warehouses have been added yet.' ?></p>
                    <?php if ($can_add_warehouses): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
                        <i class="bi bi-plus-circle"></i> Add First Warehouse
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($warehouses as $warehouse): ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="card warehouse-card <?= $warehouse['is_primary'] ? 'primary-warehouse' : '' ?>" 
                         onclick="viewWarehouse(<?= $warehouse['warehouse_id'] ?>)">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($warehouse['warehouse_name']) ?></h5>
                                    <div class="mb-2">
                                        <span class="badge bg-light text-dark"><?= htmlspecialchars($warehouse['warehouse_code']) ?></span>
                                        <?= get_status_badge($warehouse['status']) ?>
                                        <?= get_primary_badge($warehouse['is_primary']) ?>
                                    </div>
                                </div>
                                <?php if ($warehouse['is_primary']): ?>
                                <i class="bi bi-star-fill text-primary" style="font-size: 1.5rem;"></i>
                                <?php endif; ?>
                            </div>
                            
                            <div class="warehouse-stats mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span><i class="bi bi-map"></i> Locations:</span>
                                    <span class="fw-bold"><?= $warehouse['location_count'] ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span><i class="bi bi-box"></i> Products:</span>
                                    <span class="fw-bold"><?= $warehouse['product_count'] ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span><i class="bi bi-layers"></i> Total Stock:</span>
                                    <span class="fw-bold"><?= format_number($warehouse['total_stock'] ?? 0, 3) ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span><i class="bi bi-cash"></i> Stock Value:</span>
                                    <span class="fw-bold"><?= format_currency($warehouse['stock_value'] ?? 0) ?></span>
                                </div>
                            </div>
                            
                            <?php if ($warehouse['capacity']): ?>
                            <div class="mb-3">
                                <small class="text-muted">Capacity Usage</small>
                                    <?php
                                    $capacity = (float)($warehouse['capacity'] ?? 0);
                                    $stock = (float)($warehouse['total_stock'] ?? 0);
                                    $usage_percentage = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                                    $color = $usage_percentage >= 90 ? 'danger' : ($usage_percentage >= 70 ? 'warning' : 'success');
                                    ?>
                                    <div class="capacity-fill bg-<?= $color ?>" style="width: <?= min($usage_percentage, 100) ?>%"></div>
                                </div>
                                <small class="text-muted float-end"><?= format_number($usage_percentage, 1) ?>%</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <i class="bi bi-geo-alt"></i> 
                                    <?= htmlspecialchars($warehouse['city']) ?>, <?= htmlspecialchars($warehouse['country']) ?>
                                </small>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" 
                                            data-bs-toggle="dropdown" onclick="event.stopPropagation()">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="warehouse_view.php?id=<?= $warehouse['warehouse_id'] ?>">
                                                <i class="bi bi-eye"></i> View Details
                                            </a>
                                        </li>
                                        <?php if ($can_edit_warehouses): ?>
                                        <li>
                                            <a class="dropdown-item" href="#" 
                                               data-bs-toggle="modal" 
                                               data-bs-target="#editWarehouseModal"
                                               onclick="loadWarehouseData(<?= $warehouse['warehouse_id'] ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li>
                                            <a class="dropdown-item" href="locations.php?warehouse_id=<?= $warehouse['warehouse_id'] ?>">
                                                <i class="bi bi-map"></i> Manage Locations
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="stock_transfers.php?warehouse_id=<?= $warehouse['warehouse_id'] ?>">
                                                <i class="bi bi-truck"></i> Transfer Stock
                                            </a>
                                        </li>
                                        <?php if ($can_delete_warehouses && $warehouse['status'] != 'active'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item text-danger" href="#" 
                                               onclick="deleteWarehouse(<?= $warehouse['warehouse_id'] ?>)">
                                                <i class="bi bi-trash"></i> Delete
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">
                        Previous
                    </a>
                </li>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">
                        Next
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <!-- Add Warehouse Modal -->
    <div class="modal fade" id="addWarehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_warehouse" value="1">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Warehouse</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="warehouse_name" class="form-label">Warehouse Name *</label>
                                <input type="text" class="form-control" id="warehouse_name" name="warehouse_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="warehouse_code" class="form-label">Warehouse Code *</label>
                                <input type="text" class="form-control" id="warehouse_code" name="warehouse_code" required>
                                <small class="text-muted">Unique code for the warehouse</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">City</label>
                                <input type="text" class="form-control" id="city" name="city">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="state" class="form-label">State/Region</label>
                                <input type="text" class="form-control" id="state" name="state">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="postal_code" class="form-label">Postal Code</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="manager_name" class="form-label">Manager Name</label>
                                <input type="text" class="form-control" id="manager_name" name="manager_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="manager_phone" class="form-label">Manager Phone</label>
                                <input type="tel" class="form-control" id="manager_phone" name="manager_phone">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="capacity" class="form-label">Capacity (units)</label>
                                <input type="number" class="form-control" id="capacity" name="capacity" min="0" step="1">
                                <small class="text-muted">Maximum storage capacity</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" selected>Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary">
                                    <label class="form-check-label" for="is_primary">
                                        Set as Primary Warehouse
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Warehouse Modal -->
    <div class="modal fade" id="editWarehouseModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_warehouse" value="1">
                    <input type="hidden" id="edit_warehouse_id" name="warehouse_id">
                    
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Warehouse</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div id="editFormContent">
                            <!-- Content loaded via JavaScript -->
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Warehouse</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Filters Modal -->
    <div class="modal fade" id="filtersModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="GET" action="">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-funnel"></i> Filters</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Search by name, code, or city">
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="maintenance" <?= $status_filter == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <a href="warehouses.php" class="btn btn-outline-secondary">Clear All</a>
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function viewWarehouse(warehouseId) {
        window.location.href = `warehouse_view.php?id=${warehouseId}`;
    }

    function loadWarehouseData(warehouseId) {
        $.ajax({
            url: 'ajax_get_warehouse.php',
            type: 'GET',
            data: { id: warehouseId },
            success: function(response) {
                $('#edit_warehouse_id').val(warehouseId);
                $('#editFormContent').html(response);
            },
            error: function() {
                alert('Error loading warehouse data');
            }
        });
    }

    function deleteWarehouse(warehouseId) {
        if (confirm('Are you sure you want to delete this warehouse? This action cannot be undone.')) {
            $.ajax({
                url: 'ajax_delete_warehouse.php',
                type: 'POST',
                data: { 
                    warehouse_id: warehouseId,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.success) {
                        alert('Warehouse deleted successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                },
                error: function() {
                    alert('Error deleting warehouse');
                }
            });
        }
    }

    function toggleWarehouseStatus(warehouseId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const action = newStatus === 'active' ? 'activate' : 'deactivate';
        
        if (confirm(`Are you sure you want to ${action} this warehouse?`)) {
            $.ajax({
                url: 'ajax_toggle_warehouse_status.php',
                type: 'POST',
                data: { 
                    warehouse_id: warehouseId,
                    new_status: newStatus,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.success) {
                        alert(`Warehouse ${action}d successfully!`);
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                },
                error: function() {
                    alert('Error updating warehouse status');
                }
            });
        }
    }

    function setPrimaryWarehouse(warehouseId) {
        if (confirm('Set this warehouse as primary? All other warehouses will be set as non-primary.')) {
            $.ajax({
                url: 'ajax_set_primary_warehouse.php',
                type: 'POST',
                data: { 
                    warehouse_id: warehouseId,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.success) {
                        alert('Primary warehouse updated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                },
                error: function() {
                    alert('Error updating primary warehouse');
                }
            });
        }
    }

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
    </script>
</body>
<?php
require_once 'footer.php';
ob_end_flush();
?>