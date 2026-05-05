<?php
// File: product_view.php
ob_start();
require_once 'header.php';

// Check if product ID is provided
if (!isset($_GET['id'])) {
    header("Location: products.php?error=Invalid Product ID");
    exit();
}

$product_id = intval($_GET['id']);

// Check user role for product viewing permissions
requireViewPermission('products');

$can_edit_products = canEdit('products');
$can_delete_products = canDelete('products');
$can_adjust_stock = hasPermission('adjust_stock') || isAdmin();

// Get product details with comprehensive information
try {
    $query = "
        SELECT 
            p.*,
            c.category_name,
            c.parent_id as category_parent_id,
            b.brand_name,
            s.supplier_name,
            t.rate_name AS tax_name,
            t.rate_percentage as tax_rate_percentage,
            u.username as created_by_name,
            u2.username as updated_by_name,
            
            -- Stock information
            COALESCE(SUM(ps.stock_quantity), 0) as total_stock,
            COALESCE(SUM(ps.reserved_quantity), 0) as total_reserved,
            COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) as available_stock,
            
            -- Sales statistics (last 90 days)
            COALESCE((
                SELECT SUM(psi.quantity) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
                AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ), 0) as sales_last_90_days,
            
            -- Total sales (all time)
            COALESCE((
                SELECT SUM(psi.quantity) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ), 0) as total_sales_quantity,
            
            -- Total revenue (all time)
            COALESCE((
                SELECT SUM(psi.line_total) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ), 0) as total_revenue,
            
            -- Last sale date
            (
                SELECT MAX(ps.sale_date) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ) as last_sale_date,
            
            -- Last purchase date
            (
                SELECT MAX(po.order_date) 
                FROM purchase_order_items poi 
                JOIN purchase_orders po ON poi.purchase_order_id  = po.purchase_order_id  
                WHERE poi.product_id = p.product_id 
                AND po.status = 'received'
            ) as last_purchase_date,
            
            -- Average monthly sales
            COALESCE((
                SELECT AVG(monthly_sales) 
                FROM (
                    SELECT MONTH(ps.sale_date) as month, YEAR(ps.sale_date) as year, SUM(psi.quantity) as monthly_sales
                    FROM pos_sale_items psi 
                    JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                    WHERE psi.product_id = p.product_id 
                    AND ps.sale_status = 'completed'
                    AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                    GROUP BY YEAR(ps.sale_date), MONTH(ps.sale_date)
                ) monthly_data
            ), 0) as avg_monthly_sales,
            
            -- Stock value
            COALESCE(SUM(ps.stock_quantity * p.cost_price), 0) as stock_value,
            
            -- Profit margin calculation
            CASE 
                WHEN p.selling_price > 0 AND p.cost_price > 0 
                THEN ROUND(((p.selling_price - p.cost_price) / p.selling_price) * 100, 2)
                ELSE 0 
            END as profit_margin_percentage,
            
            -- Stock status
            CASE 
                WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= 0 THEN 'out_of_stock'
                WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level THEN 'low_stock'
                ELSE 'in_stock'
            END as stock_status
            
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN brands b ON p.brand_id = b.brand_id
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
        LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN users u2 ON p.updated_by = u2.user_id
        LEFT JOIN product_stocks ps ON p.product_id = ps.product_id
        WHERE p.product_id = ?
        GROUP BY p.product_id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header("Location: products.php?error=Product not found");
        exit();
    }
    
} catch (PDOException $e) {
    header("Location: products.php?error=Database error");
    exit();
} 

// Get stock by warehouse
$warehouse_stock = [];
try {
    $query = "
        SELECT 
            ps.*,
            w.warehouse_name,
            w.warehouse_code,
            loc.location_name,
            loc.location_code
        FROM product_stocks ps
        LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id
        LEFT JOIN locations loc ON ps.location_id = loc.location_id
        WHERE ps.product_id = ?
        ORDER BY w.warehouse_name
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $warehouse_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouse_stock = [];
}

// Get recent sales (last 10)
$recent_sales = [];
try {
    $query = "
        SELECT 
            psi.*,
            ps.receipt_number,
            ps.sale_date,
            ps.grand_total as sale_total,
            ps.payment_method,
            c.customer_name
        FROM pos_sale_items psi
        JOIN pos_sales ps ON psi.sale_id = ps.sale_id
        LEFT JOIN customers c ON ps.customer_id = c.customer_id
        WHERE psi.product_id = ?
        AND ps.sale_status = 'completed'
        ORDER BY ps.sale_date DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_sales = [];
}

// Get recent stock movements
$recent_movements = [];
try {
    $query = "
        SELECT 
            sm.*,
            w.warehouse_name,
            u.username as adjusted_by_name
        FROM stock_movements sm
        LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        WHERE sm.product_id = ?
        ORDER BY sm.created_at DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_movements = [];
}

// Get sales trend (last 6 months)
$sales_trend = [];
try {
    $query = "
        SELECT 
            DATE_FORMAT(ps.sale_date, '%Y-%m') as month,
            SUM(psi.quantity) as quantity_sold,
            SUM(psi.line_total) as revenue
        FROM pos_sale_items psi
        JOIN pos_sales ps ON psi.sale_id = ps.sale_id
        WHERE psi.product_id = ?
        AND ps.sale_status = 'completed'
        AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(ps.sale_date, '%Y-%m')
        ORDER BY month
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $sales_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sales_trend = [];
}

// Get purchase orders for this product
$purchase_orders = [];
try {
    $query = "
        SELECT 
            po.*,
            s.supplier_name,
            COUNT(poi.item_id) as item_count
        FROM purchase_orders po
        JOIN purchase_order_items poi ON po.purchase_order_id  = poi.purchase_order_id 
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE poi.product_id = ?
        GROUP BY po.purchase_order_id
        ORDER BY po.order_date DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $purchase_orders = [];
}

// Get stock transfers for this product
$stock_transfers = [];
try {
    $query = "
        SELECT 
            st.*,
            w1.warehouse_name as from_warehouse,
            w2.warehouse_name as to_warehouse,
            u.username as transferred_by
        FROM stock_transfers st
        JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
        LEFT JOIN warehouses w1 ON st.from_warehouse_id = w1.warehouse_id
        LEFT JOIN warehouses w2 ON st.to_warehouse_id = w2.warehouse_id
        LEFT JOIN users u ON st.created_by = u.user_id
        WHERE sti.product_id = ?
        ORDER BY st.transfer_date DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $stock_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stock_transfers = [];
}

// Get warehouses for adjustment form
$warehouses = [];
try {
    $warehouses = $pdo->query("SELECT * FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll();
} catch (PDOException $e) {
    $warehouses = [];
}

// Helper functions removed, now in helpers.php
function get_movement_type_badge($type) {
    $badges = [
        'purchase_in' => 'success',
        'sale_out' => 'danger',
        'adjustment_in' => 'info',
        'adjustment_out' => 'warning',
        'correction' => 'warning',
        'damaged' => 'dark',
        'expired' => 'secondary',
        'found' => 'info',
        'theft' => 'danger'
    ];
    
    $labels = [
        'purchase_in' => 'Purchase',
        'sale_out' => 'Sale',
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

function get_progress_color($percentage) {
    if ($percentage >= 80) return 'success';
    if ($percentage >= 50) return 'warning';
    return 'danger';
}

// Calculate days since last sale
$days_since_last_sale = 'N/A';
if (!empty($product['last_sale_date']) && $product['last_sale_date'] != '0000-00-00 00:00:00') {
    $last_sale = strtotime($product['last_sale_date']);
    $now = time();
    $days_since_last_sale = floor(($now - $last_sale) / (60 * 60 * 24));
}

// Calculate performance metrics
$turnover_ratio = 0;
if ($product['avg_monthly_sales'] > 0 && $product['available_stock'] > 0) {
    $turnover_ratio = $product['avg_monthly_sales'] / $product['available_stock'];
}

$stock_coverage = 0;
if ($product['avg_monthly_sales'] > 0) {
    $stock_coverage = $product['available_stock'] / $product['avg_monthly_sales'];
}

$days_since_first_sale = 1;
if (!empty($product['last_sale_date']) && !empty($product['created_at'])) {
    $first_sale = strtotime($product['created_at']);
    $last_sale = strtotime($product['last_sale_date']);
    $days_since_first_sale = max(1, floor(($last_sale - $first_sale) / (60 * 60 * 24)));
}
$sales_velocity = $product['total_sales_quantity'] / $days_since_first_sale;

// Parse product attributes
$attributes = [];
if (!empty($product['attributes'])) {
    $attributes = json_decode($product['attributes'], true);
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-box"></i> Product Details</h2>
                    <p class="text-muted mb-0">View comprehensive information about this product</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('products') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Products
                    </a>
                    <?php if ($can_edit_products): ?>
                    <a href="product_edit.php?id=<?= $product_id ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit Product
                    </a>
                    <?php endif; ?>
                    <?php if ($can_adjust_stock): ?>
                    <button type="button" class="btn btn-primary" onclick="adjustStock(<?= $product_id ?>)">
                        <i class="bi bi-arrow-left-right"></i> Adjust Stock
                    </button>
                    <?php endif; ?>
                    <?php if ($can_delete_products && $product['status'] == 'inactive'): ?>
                    <button type="button" class="btn btn-primary" onclick="deleteProduct(<?= $product_id ?>)">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Overview -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-image"></i> Product Image</h5>
                </div>
                <div class="card-body text-center">
                    <?php if (!empty($product['image_url'])): ?>
                    <img src="<?= safe_output($product['image_url']) ?>" 
                         class="img-fluid rounded mb-3" 
                         style="max-height: 300px; object-fit: contain;"
                         alt="<?= safe_output($product['product_name']) ?>">
                    <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center" 
                         style="height: 300px; background: #f8f9fa; border-radius: 0.375rem;">
                        <div class="text-center">
                            <i class="bi bi-image" style="font-size: 4rem; color: #6c757d;"></i>
                            <p class="text-muted mt-2">No image available</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <?= get_status_badge($product['status']) ?>
                        <?= get_stock_badge($product['stock_status'], $product['available_stock']) ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-info-circle"></i> Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h3 class="text-primary"><?= safe_output($product['product_name']) ?></h3>
                            
                            <div class="mb-3">
                                <strong>SKU:</strong> 
                                <span class="badge bg-info"><?= safe_output($product['sku']) ?></span>
                            </div>
                            
                            <?php if (!empty($product['barcode'])): ?>
                            <div class="mb-3">
                                <strong>Barcode:</strong> 
                                <span class="badge bg-secondary"><?= safe_output($product['barcode']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <strong>Category:</strong> 
                                <?php if (!empty($product['category_name'])): ?>
                                <span class="badge bg-primary"><?= safe_output($product['category_name']) ?></span>
                                <?php else: ?>
                                <span class="text-muted">Uncategorized</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($product['brand_name'])): ?>
                            <div class="mb-3">
                                <strong>Brand:</strong> 
                                <span class="badge bg-success"><?= safe_output($product['brand_name']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($product['supplier_name'])): ?>
                            <div class="mb-3">
                                <strong>Supplier:</strong> 
                                <span class="badge bg-warning text-dark"><?= safe_output($product['supplier_name']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <strong>Unit of Measure:</strong> 
                                <span class="badge bg-dark"><?= safe_output($product['unit']) ?></span>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title">Pricing Information</h6>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted">Cost Price:</small>
                                        <h4 class="text-danger"><?= format_currency($product['cost_price']) ?></h4>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted">Selling Price:</small>
                                        <h4 class="text-success"><?= format_currency($product['selling_price']) ?></h4>
                                    </div>
                                    
                                    <?php if ($product['min_selling_price'] > 0): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Min Selling Price:</small>
                                        <h5 class="text-warning"><?= format_currency($product['min_selling_price']) ?></h5>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['wholesale_price'] > 0): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Wholesale Price:</small>
                                        <h5 class="text-info"><?= format_currency($product['wholesale_price']) ?></h5>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted">Profit Margin:</small>
                                        <h5 class="text-<?= get_progress_color($product['profit_margin_percentage']) ?>">
                                            <?= format_number($product['profit_margin_percentage'], 1) ?>%
                                            <small class="text-muted">
                                                (<?= format_currency($product['selling_price'] - $product['cost_price']) ?>)
                                            </small>
                                        </h5>
                                    </div>
                                    
                                    <?php if ($product['tax_rate_percentage'] > 0): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Tax Rate:</small>
                                        <span class="badge bg-secondary">
                                            <?= safe_output($product['tax_name']) ?> (<?= $product['tax_rate_percentage'] ?>%)
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($product['description'])): ?>
                    <div class="mt-3">
                        <strong>Description:</strong>
                        <p class="mt-1"><?= nl2br(safe_output($product['description'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p><?= format_date($product['created_at'], true) ?> by <?= safe_output($product['created_by_name']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p><?= format_date($product['updated_at'], true) ?> by <?= safe_output($product['updated_by_name']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs for Detailed Information -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="productTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="stock-tab" data-bs-toggle="tab" 
                                    data-bs-target="#stock" type="button" role="tab">
                                <i class="bi bi-boxes"></i> Stock Information
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="sales-tab" data-bs-toggle="tab" 
                                    data-bs-target="#sales" type="button" role="tab">
                                <i class="bi bi-graph-up"></i> Sales Performance
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="movements-tab" data-bs-toggle="tab" 
                                    data-bs-target="#movements" type="button" role="tab">
                                <i class="bi bi-arrow-left-right"></i> Stock Movements
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="details-tab" data-bs-toggle="tab" 
                                    data-bs-target="#details" type="button" role="tab">
                                <i class="bi bi-list-check"></i> Additional Details
                            </button>
                        </li>
                        <?php if ($can_adjust_stock): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="actions-tab" data-bs-toggle="tab" 
                                    data-bs-target="#actions" type="button" role="tab">
                                <i class="bi bi-gear"></i> Quick Actions
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="productTabsContent">
                        
                        <!-- Stock Information Tab -->
                        <div class="tab-pane fade show active" id="stock" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Stock Summary</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row text-center">
                                                <div class="col-6">
                                                    <h2 class="text-primary"><?= format_number($product['total_stock'], 3) ?></h2>
                                                    <small class="text-muted">Total Stock</small>
                                                </div>
                                                <div class="col-6">
                                                    <h2 class="text-success"><?= format_number($product['available_stock'], 3) ?></h2>
                                                    <small class="text-muted">Available Stock</small>
                                                </div>
                                            </div>
                                            
                                            <div class="row text-center mt-3">
                                                <div class="col-6">
                                                    <h4 class="text-danger"><?= format_number($product['total_reserved'], 3) ?></h4>
                                                    <small class="text-muted">Reserved Stock</small>
                                                </div>
                                                <div class="col-6">
                                                    <h4 class="text-warning"><?= format_currency($product['stock_value']) ?></h4>
                                                    <small class="text-muted">Stock Value</small>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <div class="progress" style="height: 20px;">
                                                    <?php 
                                                    $available_percentage = ($product['total_stock'] > 0) ? 
                                                        ($product['available_stock'] / $product['total_stock']) * 100 : 0;
                                                    $reserved_percentage = ($product['total_stock'] > 0) ? 
                                                        ($product['total_reserved'] / $product['total_stock']) * 100 : 0;
                                                    ?>
                                                    <div class="progress-bar bg-success" style="width: <?= $available_percentage ?>%">
                                                        Available: <?= format_number($available_percentage, 1) ?>%
                                                    </div>
                                                    <div class="progress-bar bg-danger" style="width: <?= $reserved_percentage ?>%">
                                                        Reserved: <?= format_number($reserved_percentage, 1) ?>%
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Stock Alerts</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($product['stock_status'] == 'out_of_stock'): ?>
                                            <div class="alert alert-danger">
                                                <i class="bi bi-x-circle"></i>
                                                <strong>Out of Stock!</strong> This product has no available stock.
                                            </div>
                                            <?php elseif ($product['stock_status'] == 'low_stock'): ?>
                                            <div class="alert alert-warning">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <strong>Low Stock Alert!</strong> 
                                                Stock (<?= $product['available_stock'] ?>) is below reorder level (<?= $product['min_stock_level'] ?>).
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-success">
                                                <i class="bi bi-check-circle"></i>
                                                <strong>Stock Level OK</strong> 
                                                Current stock is above reorder level.
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Reorder Level:</small>
                                                    <p><strong><?= format_number($product['min_stock_level'], 3) ?></strong></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Min Stock Level:</small>
                                                    <p><strong><?= format_number($product['min_stock_level'], 3) ?></strong></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Max Stock Level:</small>
                                                    <p><strong><?= format_number($product['max_stock_level'], 3) ?></strong></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card h-100">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="mb-0"><i class="bi bi-house-door"></i> Stock by Warehouse</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($warehouse_stock)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Warehouse</th>
                                                            <th>Location</th>
                                                            <th>Total Stock</th>
                                                            <th>Available</th>
                                                            <th>Reserved</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($warehouse_stock as $stock): ?>
                                                        <tr>
                                                            <td><?= safe_output($stock['warehouse_name']) ?></td>
                                                            <td><?= safe_output($stock['location_name'] ?? 'N/A') ?></td>
                                                            <td><?= format_number($stock['stock_quantity'], 3) ?></td>
                                                            <td>
                                                                <?= format_number($stock['stock_quantity'] - $stock['reserved_quantity'], 3) ?>
                                                            </td>
                                                            <td><?= format_number($stock['reserved_quantity'], 3) ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-box" style="font-size: 3rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No stock information available</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sales Performance Tab -->
                        <div class="tab-pane fade" id="sales" role="tabpanel">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="card mb-4">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Sales Trend (Last 6 Months)</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($sales_trend)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Month</th>
                                                            <th>Quantity Sold</th>
                                                            <th>Revenue</th>
                                                            <th>Avg Price</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($sales_trend as $trend): ?>
                                                        <tr>
                                                            <td><?= date('M Y', strtotime($trend['month'] . '-01')) ?></td>
                                                            <td><?= format_number($trend['quantity_sold'], 3) ?></td>
                                                            <td><?= format_currency($trend['revenue']) ?></td>
                                                            <td>
                                                                <?= $trend['quantity_sold'] > 0 ? 
                                                                    format_currency($trend['revenue'] / $trend['quantity_sold']) : 
                                                                    'N/A' ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-graph-up" style="font-size: 3rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No sales data available for the last 6 months</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="card mb-4">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="mb-0"><i class="bi bi-cash-stack"></i> Sales Statistics</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <small class="text-muted">Total Sold (All Time):</small>
                                                <h4><?= format_number($product['total_sales_quantity'], 3) ?> units</h4>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Total Revenue:</small>
                                                <h4><?= format_currency($product['total_revenue']) ?></h4>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Last 90 Days:</small>
                                                <h5><?= format_number($product['sales_last_90_days'], 3) ?> units</h5>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Average Monthly Sales:</small>
                                                <h5><?= format_number($product['avg_monthly_sales'], 3) ?> units</h5>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Last Sale:</small>
                                                <p>
                                                    <?= !empty($product['last_sale_date']) ? 
                                                        format_date($product['last_sale_date'], true) : 'No sales yet' ?>
                                                    <?php if (is_numeric($days_since_last_sale)): ?>
                                                    <br><small class="text-muted">
                                                        <?= $days_since_last_sale ?> days ago
                                                    </small>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent Sales -->
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-receipt"></i> Recent Sales (Last 10)</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($recent_sales)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Receipt #</th>
                                                    <th>Customer</th>
                                                    <th>Quantity</th>
                                                    <th>Unit Price</th>
                                                    <th>Total</th>
                                                    <th>Payment</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_sales as $sale): ?>
                                                <tr>
                                                    <td><?= format_date($sale['sale_date'], true) ?></td>
                                                    <td>
                                                        <a href="sale_view.php?id=<?= $sale['sale_id'] ?>" class="text-decoration-none">
                                                            <?= safe_output($sale['receipt_number']) ?>
                                                        </a>
                                                    </td>
                                                    <td><?= safe_output($sale['customer_name'] ?? 'Walk-in') ?></td>
                                                    <td><?= format_number($sale['quantity'], 3) ?></td>
                                                    <td><?= format_currency($sale['unit_price']) ?></td>
                                                    <td><?= format_currency($sale['line_total']) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $sale['payment_method'] == 'cash' ? 'success' : 'primary' ?>">
                                                            <?= ucfirst($sale['payment_method']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-cart" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="text-muted mt-2">No recent sales found</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Stock Movements Tab -->
                        <div class="tab-pane fade" id="movements" role="tabpanel">
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="mb-0"><i class="bi bi-arrow-left-right"></i> Recent Stock Movements</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($recent_movements)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Type</th>
                                                    <th>Warehouse</th>
                                                    <th>Reference</th>
                                                    <th>Quantity</th>
                                                    <th>Previous</th>
                                                    <th>New</th>
                                                    <th>Adjusted By</th>
                                                    <th>Reason</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_movements as $movement): ?>
                                                <tr>
                                                    <td><?= format_date($movement['created_at'], true) ?></td>
                                                    <td><?= get_movement_type_badge($movement['movement_type']) ?></td>
                                                    <td><?= safe_output($movement['warehouse_name']) ?></td>
                                                    <td>
                                                        <?php if (!empty($movement['reference_number'])): ?>
                                                        <small class="text-muted"><?= safe_output($movement['reference_number']) ?></small>
                                                        <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="<?= $movement['quantity_change'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                        <?= $movement['quantity_change'] >= 0 ? '+' : '' ?><?= format_number($movement['quantity_change'], 3) ?>
                                                    </td>
                                                    <td><?= format_number($movement['previous_quantity'], 3) ?></td>
                                                    <td><?= format_number($movement['new_quantity'], 3) ?></td>
                                                    <td><?= safe_output($movement['adjusted_by_name']) ?></td>
                                                    <td>
                                                        <?php if (!empty($movement['reason'])): ?>
                                                        <small><?= safe_output($movement['reason']) ?></small>
                                                        <?php else: ?>
                                                        <span class="text-muted">No reason provided</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center mt-2">
                                        <a href="stock_movements.php?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                            View All Movements
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-arrow-left-right" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="text-muted mt-2">No stock movements recorded</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Details Tab -->
                        <div class="tab-pane fade" id="details" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bi bi-tags"></i> Product Attributes</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($attributes) && is_array($attributes)): ?>
                                            <div class="row">
                                                <?php foreach ($attributes as $key => $value): ?>
                                                <div class="col-md-6 mb-2">
                                                    <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?>:</strong>
                                                    <br>
                                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($value) ?></span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-3">
                                                <i class="bi bi-tag" style="font-size: 2rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No additional attributes defined</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="card mb-4">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="mb-0"><i class="bi bi-calendar"></i> Date Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Created:</small>
                                                    <p><?= format_date($product['created_at'], true) ?></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Last Updated:</small>
                                                    <p><?= format_date($product['updated_at'], true) ?></p>
                                                </div>
                                            </div>
                                            <?php if (!empty($product['last_purchase_date'])): ?>
                                            <div class="row">
                                                <div class="col-12">
                                                    <small class="text-muted">Last Purchase:</small>
                                                    <p><?= format_date($product['last_purchase_date']) ?></p>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="bi bi-shield-check"></i> Inventory Settings</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6 mb-3">
                                                    <strong>Track Inventory:</strong><br>
                                                    <span class="badge bg-<?= $product['track_inventory'] ? 'success' : 'secondary' ?>">
                                                        <?= $product['track_inventory'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Allow Backorders:</strong><br>
                                                    <span class="badge bg-<?= $product['allow_backorders'] ? 'warning' : 'secondary' ?>">
                                                        <?= $product['allow_backorders'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Allow Negative Stock:</strong><br>
                                                    <span class="badge bg-<?= $product['allow_negative_stock'] ? 'danger' : 'secondary' ?>">
                                                        <?= $product['allow_negative_stock'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Require Serial Numbers:</strong><br>
                                                    <span class="badge bg-<?= $product['requires_serial'] ? 'info' : 'secondary' ?>">
                                                        <?= $product['requires_serial'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Require Batch Numbers:</strong><br>
                                                    <span class="badge bg-<?= $product['requires_batch'] ? 'info' : 'secondary' ?>">
                                                        <?= $product['requires_batch'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Is Expirable:</strong><br>
                                                    <span class="badge bg-<?= $product['is_expirable'] ? 'danger' : 'secondary' ?>">
                                                        <?= $product['is_expirable'] ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <?php if ($product['is_expirable'] && !empty($product['shelf_life_days'])): ?>
                                            <div class="alert alert-info mt-3">
                                                <i class="bi bi-clock"></i>
                                                <strong>Shelf Life:</strong> <?= $product['shelf_life_days'] ?> days
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="mb-0"><i class="bi bi-people"></i> User Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Created By:</small>
                                                    <p><?= safe_output($product['created_by_name']) ?></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Updated By:</small>
                                                    <p><?= safe_output($product['updated_by_name']) ?></p>
                                                </div>
                                            </div>
                                            <?php if (!empty($product['notes'])): ?>
                                            <div class="mt-3">
                                                <strong>Internal Notes:</strong>
                                                <div class="border rounded p-2 mt-1 bg-light">
                                                    <?= nl2br(safe_output($product['notes'])) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($can_adjust_stock): ?>
                        <!-- Quick Actions Tab -->
                        <div class="tab-pane fade" id="actions" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-danger text-white">
                                            <h6 class="mb-0"><i class="bi bi-plus-slash-minus"></i> Stock Adjustment</h6>
                                        </div>
                                        <div class="card-body">
                                            <form id="adjustStockForm" action="process_stock_adjustment.php" method="POST">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="adjustment_type" class="form-label">Adjustment Type</label>
                                                    <select class="form-select" id="adjustment_type" name="adjustment_type" required>
                                                        <option value="adjustment_in">Stock In (Increase)</option>
                                                        <option value="adjustment_out">Stock Out (Decrease)</option>
                                                        <option value="correction">Correction</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="expired">Expired</option>
                                                        <option value="found">Found Stock</option>
                                                        <option value="theft">Theft/Loss</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label for="warehouse_id" class="form-label">Warehouse</label>
                                                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                                        <option value="">Select Warehouse</option>
                                                        <?php foreach ($warehouses as $warehouse): ?>
                                                        <option value="<?= $warehouse['warehouse_id'] ?>">
                                                            <?= htmlspecialchars($warehouse['warehouse_name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label for="quantity" class="form-label">Quantity</label>
                                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                                           step="0.001" min="0.001" required placeholder="Enter quantity">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="reason" class="form-label">Reason/Notes</label>
                                                    <textarea class="form-control" id="reason" name="reason" rows="3" 
                                                              placeholder="Enter reason for adjustment"></textarea>
                                                </div>

                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-check-circle"></i> Submit Adjustment
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-grid gap-2">
                                                <?php if ($product['status'] == 'active'): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="toggleProductStatus(<?= $product_id ?>, 'inactive')">
                                                    <i class="bi bi-pause"></i> Deactivate Product
                                                </button>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-outline-success" onclick="toggleProductStatus(<?= $product_id ?>, 'active')">
                                                    <i class="bi bi-play"></i> Activate Product
                                                </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-outline-info" onclick="printBarcode(<?= $product_id ?>)">
                                                    <i class="bi bi-upc-scan"></i> Print Barcode
                                                </button>

                                                <button type="button" class="btn btn-outline-primary" onclick="duplicateProduct(<?= $product_id ?>)">
                                                    <i class="bi bi-copy"></i> Duplicate Product
                                                </button>

                                                <?php if ($product['track_inventory']): ?>
                                                <button type="button" class="btn btn-outline-dark" onclick="transferStock(<?= $product_id ?>)">
                                                    <i class="bi bi-truck"></i> Transfer Stock
                                                </button>
                                                <?php endif; ?>

                                                <a href="reports.php?report=product_movement&product_id=<?= $product_id ?>" 
                                                   class="btn btn-outline-info">
                                                    <i class="bi bi-file-earmark-text"></i> Generate Movement Report
                                                </a>

                                                <a href="reports.php?report=product_sales&product_id=<?= $product_id ?>" 
                                                   class="btn btn-outline-success">
                                                    <i class="bi bi-graph-up"></i> Generate Sales Report
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bi bi-bell"></i> Stock Alerts Setup</h6>
                                        </div>
                                        <div class="card-body">
                                            <form id="alertSettingsForm" action="update_product_alerts.php" method="POST">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="reorder_level" class="form-label">Reorder Level</label>
                                                    <input type="number" class="form-control" id="reorder_level" name="reorder_level"
                                                           value="<?= $product['min_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="min_stock_level" class="form-label">Minimum Stock Level</label>
                                                    <input type="number" class="form-control" id="min_stock_level" name="min_stock_level"
                                                           value="<?= $product['min_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="max_stock_level" class="form-label">Maximum Stock Level</label>
                                                    <input type="number" class="form-control" id="max_stock_level" name="max_stock_level"
                                                           value="<?= $product['max_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" id="email_alerts" name="email_alerts"
                                                           <?= $product['email_alerts'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="email_alerts">
                                                        Send email alerts for stock issues
                                                    </label>
                                                </div>

                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save"></i> Update Alert Settings
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Related Products Section -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-link"></i> Related Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6><i class="bi bi-cart-plus"></i> Purchase Orders</h6>
                            <?php if (!empty($purchase_orders)): ?>
                            <div class="list-group">
                                <?php foreach ($purchase_orders as $order): ?>
                                <a href="purchase_order_view.php?id=<?= $order['purchase_order_id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">PO#<?= $order['order_number'] ?></h6>
                                        <small><?= format_date($order['order_date']) ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <small>Supplier: <?= safe_output($order['supplier_name']) ?></small>
                                    </p>
                                    <small>Status: 
                                        <span class="badge bg-<?= 
                                            $order['status'] == 'received' ? 'success' : 
                                            ($order['status'] == 'pending' ? 'warning' : 
                                            ($order['status'] == 'cancelled' ? 'danger' : 'secondary')) ?>">
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </small>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2">
                                <a href="purchase_orders.php?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                    View All Purchase Orders
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No purchase orders found for this product.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <h6><i class="bi bi-arrow-left-right"></i> Stock Transfers</h6>
                            <?php if (!empty($stock_transfers)): ?>
                            <div class="list-group">
                                <?php foreach ($stock_transfers as $transfer): ?>
                                <a href="stock_transfer_view.php?id=<?= $transfer['transfer_id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">TF#<?= $transfer['transfer_number'] ?></h6>
                                        <small><?= format_date($transfer['transfer_date']) ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <small>
                                            From: <?= safe_output($transfer['from_warehouse']) ?><br>
                                            To: <?= safe_output($transfer['to_warehouse']) ?>
                                        </small>
                                    </p>
                                    <small>Status: 
                                        <span class="badge bg-<?= 
                                            $transfer['status'] == 'completed' ? 'success' : 
                                            ($transfer['status'] == 'in_transit' ? 'warning' : 
                                            ($transfer['status'] == 'cancelled' ? 'danger' : 'secondary')) ?>">
                                            <?= ucfirst($transfer['status']) ?>
                                        </span>
                                    </small>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2">
                                <a href="stock_transfers.php?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                    View All Transfers
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No stock transfers found for this product.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <h6><i class="bi bi-graph-up"></i> Performance Metrics</h6>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <small class="text-muted">Stock Turnover Ratio:</small>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                <div class="progress-bar bg-<?= get_progress_color(min($turnover_ratio * 10, 100)) ?>" 
                                                     style="width: <?= min($turnover_ratio * 100, 100) ?>%">
                                                    <?= format_number($turnover_ratio, 2) ?>
                                                </div>
                                            </div>
                                            <small><?= format_number($turnover_ratio, 2) ?>x</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Stock Coverage (months):</small>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                <div class="progress-bar bg-<?= 
                                                    $stock_coverage >= 3 ? 'danger' : 
                                                    ($stock_coverage >= 2 ? 'warning' : 'success') 
                                                    ?>" 
                                                     style="width: <?= min($stock_coverage * 33.33, 100) ?>%">
                                                    <?= format_number($stock_coverage, 1) ?>
                                                </div>
                                            </div>
                                            <small><?= format_number($stock_coverage, 1) ?>m</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Gross Profit Contribution:</small>
                                        <h4 class="text-success">
                                            <?= format_currency($product['total_revenue'] - ($product['total_sales_quantity'] * $product['cost_price'])) ?>
                                        </h4>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Sales Velocity (units/day):</small>
                                        <h5 class="text-primary">
                                            <?= format_number($sales_velocity, 3) ?>
                                            <small class="text-muted">units/day</small>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript Functions -->
<script>
function adjustStock(productId) {
    $('#actions-tab').tab('show');
    $('#adjustStockForm')[0].scrollIntoView({ behavior: 'smooth' });
}

function deleteProduct(productId) {
    if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
        $.ajax({
            url: 'ajax_delete_product.php',
            type: 'POST',
            data: { product_id: productId },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Product deleted successfully!');
                    window.location.href = 'products.php';
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error deleting product. Please try again.');
            }
        });
    }
}

function toggleProductStatus(productId, newStatus) {
    const action = newStatus === 'active' ? 'activate' : 'deactivate';
    if (confirm(`Are you sure you want to ${action} this product?`)) {
        $.ajax({
            url: 'ajax_toggle_product_status.php',
            type: 'POST',
            data: { product_id: productId, status: newStatus },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert(`Product ${action}d successfully!`);
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error updating product status. Please try again.');
            }
        });
    }
}

function printBarcode(productId) {
    window.open(`print_barcode.php?product_id=${productId}&quantity=10`, '_blank');
}

function duplicateProduct(productId) {
    if (confirm('Create a copy of this product?')) {
        $.ajax({
            url: 'ajax_duplicate_product.php',
            type: 'POST',
            data: { product_id: productId },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Product duplicated successfully!');
                    window.location.href = `product_view.php?id=${result.new_product_id}`;
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error duplicating product. Please try again.');
            }
        });
    }
}

function transferStock(productId) {
    window.location.href = `stock_transfer_create.php?product_id=${productId}`;
}

// Form submission handling
$(document).ready(function() {
    $('#adjustStockForm').submit(function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        
        $.ajax({
            url: 'process_stock_adjustment.php',
            type: 'POST',
            data: formData,
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Stock adjusted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error processing adjustment. Please try again.');
            }
        });
    });

    $('#alertSettingsForm').submit(function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        
        $.ajax({
            url: 'update_product_alerts.php',
            type: 'POST',
            data: formData,
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Alert settings updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error updating settings. Please try again.');
            }
        });
    });

    // Initialize tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();
    
    // Tab persistence
    $('button[data-bs-toggle="tab"]').on('click', function(e) {
        localStorage.setItem('activeProductTab', $(e.target).attr('data-bs-target'));
    });
    
    // Restore active tab
    const activeTab = localStorage.getItem('activeProductTab');
    if (activeTab) {
        const tabElement = document.querySelector(`button[data-bs-target="${activeTab}"]`);
        if (tabElement) {
            new bootstrap.Tab(tabElement).show();
        }
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    background-color: #d1e7dd !important;
    border: 1px solid #badbcc !important;
}

.card-header.bg-light, .card-header.bg-white, .card-header.bg-primary {
    background-color: #f8f9fa !important;
    border-bottom: 1px solid #dee2e6 !important;
    color: #212529 !important;
}

.card-header h5, .card-header h6 {
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

.nav-tabs .nav-link.active {
    background-color: #f8f9fa !important;
    border-color: #dee2e6 #dee2e6 #f8f9fa !important;
    color: black !important;
}

.card-header-tabs {
    margin-bottom: -1px;
}

.badge.bg-primary { background-color: #0d6efd !important; }
.badge.bg-success { background-color: #198754 !important; }
.badge.bg-info { background-color: #0dcaf0 !important; color: #000 !important; }
.badge.bg-warning { background-color: #ffc107 !important; color: #000 !important; }
.badge.bg-danger { background-color: #dc3545 !important; }

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
require_once 'footer.php';
ob_end_flush();
?>