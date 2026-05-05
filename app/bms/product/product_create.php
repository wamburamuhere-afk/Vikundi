<?php
// File: product_create.php
ob_start();
require_once 'header.php';

// Check user role for product creation permissions
requireCreatePermission('products');

// Get current user info
$user_id = $_SESSION['user_id'];

// Get data for dropdowns
try {
    $categories = $pdo->query("SELECT category_id, category_name, parent_id FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

try {
    $brands = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status = 'active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $brands = [];
}

try {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

try {
    $tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tax_rates = [];
}

try {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

// Get measurement units
$units = [
    'pcs' => 'Pieces',
    'kg' => 'Kilogram',
    'g' => 'Gram',
    'l' => 'Liter',
    'ml' => 'Milliliter',
    'm' => 'Meter',
    'cm' => 'Centimeter',
    'pack' => 'Pack',
    'box' => 'Box',
    'carton' => 'Carton',
    'bottle' => 'Bottle',
    'can' => 'Can',
    'bag' => 'Bag',
    'pair' => 'Pair',
    'set' => 'Set',
    'dozen' => 'Dozen'
];

// Helper functions
function generate_sku() {
    $prefix = 'PROD';
    $timestamp = time();
    $random = rand(100, 999);
    return $prefix . $timestamp . $random;
}

function generate_barcode() {
    // Generate EAN-13 barcode (13 digits)
    $country_code = '00'; // Default country code
    $company_code = rand(10000, 99999);
    $product_code = rand(10000, 99999);
    $barcode = $country_code . $company_code . $product_code;
    
    // Calculate check digit
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$barcode[$i];
        $sum += ($i % 2 == 0) ? $digit : $digit * 3;
    }
    $check_digit = (10 - ($sum % 10)) % 10;
    
    return $barcode . $check_digit;
}

// Helper functions removed, now in helpers.php
function build_category_tree($categories, $parent_id = 0, $depth = 0) {
    $html = '';
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
            $html .= '<option value="' . $category['category_id'] . '">' 
                   . $indent . htmlspecialchars($category['category_name']) . '</option>';
            $html .= build_category_tree($categories, $category['category_id'], $depth + 1);
        }
    }
    return $html;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-box"></i> Create New Product</h2>
                    <p class="text-muted mb-0">Add a new product to your inventory</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('products') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Products
                    </a>
                    <button type="button" class="btn btn-primary" onclick="saveAsDraft()">
                        <i class="bi bi-save"></i> Save as Draft
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h5 class="mb-0 text-dark"><i class="bi bi-file-text"></i> Product Details</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="productForm" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Basic Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="product_name" name="product_name" 
                                               placeholder="Enter product name" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="sku" class="form-label">SKU (Stock Keeping Unit)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="sku" name="sku" 
                                                   value="<?= generate_sku() ?>" placeholder="Auto-generated SKU">
                                            <button type="button" class="btn btn-outline-secondary" onclick="generateNewSKU()">
                                                <i class="bi bi-arrow-repeat"></i> Generate
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="barcode" class="form-label">Barcode</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="barcode" name="barcode" 
                                                   value="<?= generate_barcode() ?>" placeholder="Auto-generated barcode">
                                            <button type="button" class="btn btn-outline-secondary" onclick="generateNewBarcode()">
                                                <i class="bi bi-upc"></i> Generate
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="category_id" class="form-label">Category</label>
                                        <select class="form-select" id="category_id" name="category_id">
                                            <option value="">Select Category</option>
                                            <?= build_category_tree($categories) ?>
                                        </select>
                                        <small class="text-muted">
                                            <a href="categories" target="_blank">Manage Categories</a>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" 
                                              rows="3" placeholder="Product description"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-image"></i> Product Image</h6>
                            </div>
                            <div class="card-body text-center">
                                <div class="mb-3">
                                    <div id="imagePreview" class="border rounded p-3 mb-3" 
                                         style="height: 200px; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
                                        <div class="text-center">
                                            <i class="bi bi-image" style="font-size: 3rem; color: #6c757d;"></i>
                                            <p class="text-muted mt-2">No image selected</p>
                                        </div>
                                    </div>
                                    <input type="file" class="form-control" id="product_image" name="product_image" 
                                           accept="image/*" onchange="previewImage(event)">
                                    <small class="text-muted">Max size: 2MB. Supported formats: JPG, PNG, GIF</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="discontinued">Discontinued</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pricing Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-cash-stack"></i> Pricing Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="cost_price" class="form-label">Cost Price <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input type="number" class="form-control" id="cost_price" name="cost_price" 
                                           min="0" step="0.01" value="0.00" required onchange="calculateMarkup()">
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="selling_price" class="form-label">Selling Price <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input type="number" class="form-control" id="selling_price" name="selling_price" 
                                           min="0" step="0.01" value="0.00" required onchange="calculateMarkup()">
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="min_selling_price" class="form-label">Min Selling Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input type="number" class="form-control" id="min_selling_price" name="min_selling_price" 
                                           min="0" step="0.01" value="0.00">
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="wholesale_price" class="form-label">Wholesale Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">TZS</span>
                                    <input type="number" class="form-control" id="wholesale_price" name="wholesale_price" 
                                           min="0" step="0.01" value="0.00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Markup</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="markup_percentage" readonly>
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Profit Margin</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="profit_margin" readonly>
                                    <span class="input-group-text">TZS</span>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="tax_id" class="form-label">Tax Rate</label>
                                <select class="form-select" id="tax_id" name="tax_id">
                                    <option value="">No Tax</option>
                                    <?php foreach ($tax_rates as $tax): ?>
                                        <option value="<?= $tax['rate_id'] ?>">
                                            <?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="discount_rate" class="form-label">Discount Rate</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="discount_rate" name="discount_rate" 
                                           min="0" max="100" step="0.01" value="0.00">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Inventory Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-boxes"></i> Inventory Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="unit" class="form-label">Unit of Measure <span class="text-danger">*</span></label>
                                <select class="form-select" id="unit" name="unit" required>
                                    <option value="">Select Unit</option>
                                    <?php foreach ($units as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $value == 'pcs' ? 'selected' : '' ?>>
                                            <?= $label ?> (<?= $value ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="weight" class="form-label">Weight</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="weight" name="weight" 
                                           min="0" step="0.001" value="0.000">
                                    <span class="input-group-text">kg</span>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="dimensions" class="form-label">Dimensions (L x W x H)</label>
                                <input type="text" class="form-control" id="dimensions" name="dimensions" 
                                       placeholder="e.g., 10x20x30 cm">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="reorder_level" class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                       min="0" step="0.001" value="0">
                                <small class="text-muted">Alert when stock reaches this level</small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="min_stock_level" class="form-label">Min Stock Level</label>
                                <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" 
                                       min="0" step="0.001" value="0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="max_stock_level" class="form-label">Max Stock Level</label>
                                <input type="number" class="form-control" id="max_stock_level" name="max_stock_level" 
                                       min="0" step="0.001" value="0">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Track Inventory</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="track_inventory" name="track_inventory" checked>
                                    <label class="form-check-label" for="track_inventory">Track stock levels</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Initial Stock -->
                        <div class="mb-3">
                            <label class="form-label">Initial Stock (Optional)</label>
                            <div class="row" id="initialStockSection">
                                <?php if (!empty($warehouses)): ?>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text"><?= htmlspecialchars($warehouse['warehouse_name']) ?></span>
                                            <input type="number" class="form-control" name="initial_stock[<?= $warehouse['warehouse_id'] ?>]" 
                                                   min="0" step="0.001" value="0" placeholder="Quantity">
                                            <span class="input-group-text"><?= isset($_POST['unit']) ? htmlspecialchars($_POST['unit']) : 'pcs' ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> No warehouses found. 
                                            <a href="warehouses" class="alert-link">Add a warehouse first</a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-tags"></i> Additional Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="brand_id" class="form-label">Brand</label>
                                <select class="form-select" id="brand_id" name="brand_id">
                                    <option value="">Select Brand</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?= $brand['brand_id'] ?>">
                                            <?= htmlspecialchars($brand['brand_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <a href="brands" target="_blank">Manage Brands</a>
                                </small>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="supplier_id" class="form-label">Supplier</label>
                                <select class="form-select" id="supplier_id" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['supplier_id'] ?>">
                                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <a href="suppliers" target="_blank">Manage Suppliers</a>
                                </small>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="manufacturer" class="form-label">Manufacturer</label>
                                <input type="text" class="form-control" id="manufacturer" name="manufacturer" 
                                       placeholder="Manufacturer name">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="model" class="form-label">Model</label>
                                <input type="text" class="form-control" id="model" name="model" 
                                       placeholder="Product model">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="serial_number" class="form-label">Serial Number</label>
                                <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                       placeholder="Serial number">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="warranty_period" class="form-label">Warranty Period</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="warranty_period" name="warranty_period" 
                                           min="0" value="0">
                                    <span class="input-group-text">months</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="expiry_days" class="form-label">Shelf Life</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="expiry_days" name="expiry_days" 
                                           min="0" value="0">
                                    <span class="input-group-text">days</span>
                                </div>
                                <small class="text-muted">Number of days before expiry (0 = no expiry)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Product Type</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_service" name="is_service">
                                    <label class="form-check-label" for="is_service">This is a service (not a physical product)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_taxable" name="is_taxable" checked>
                                    <label class="form-check-label" for="is_taxable">Product is taxable</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-primary" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" onclick="saveAsDraft()">
                            <i class="bi bi-save"></i> Save as Draft
                        </button>
                        <button type="button" class="btn btn-primary" onclick="saveAndAddAnother()">
                            <i class="bi bi-plus-circle"></i> Save & Add Another
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Create Product
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="barcodeScannerModalLabel">
                    <i class="bi bi-upc"></i> Scan Barcode
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div id="scannerContainer" class="border rounded p-3" style="height: 300px;">
                        <div class="d-flex align-items-center justify-content-center h-100">
                            <div class="text-center">
                                <i class="bi bi-camera" style="font-size: 3rem; color: #6c757d;"></i>
                                <p class="mt-2 text-muted">Click to start camera</p>
                                <button type="button" class="btn btn-primary mt-2" onclick="startBarcodeScanner()">
                                    <i class="bi bi-camera-video"></i> Start Scanner
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="input-group">
                    <input type="text" class="form-control" id="manualBarcodeInput" placeholder="Or enter barcode manually">
                    <button class="btn btn-outline-secondary" type="button" onclick="useManualBarcode()">
                        <i class="bi bi-check"></i> Use
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Category Modal -->
<div class="modal fade" id="quickCategoryModal" tabindex="-1" aria-labelledby="quickCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickCategoryModalLabel">
                    <i class="bi bi-plus-circle"></i> Quick Add Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Category Name</label>
                    <input type="text" class="form-control" id="quickCategoryName" placeholder="Enter category name">
                </div>
                <div class="mb-3">
                    <label class="form-label">Parent Category</label>
                    <select class="form-select" id="quickCategoryParent">
                        <option value="0">None (Top Level)</option>
                        <?= build_category_tree($categories) ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveQuickCategory()">Add Category</button>
            </div>
        </div>
    </div>
</div>
<?php include 'product_create_footer.php'; ?>