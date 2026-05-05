<?php
// File: grn_create.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for GRN creation permissions
$can_create_grn = in_array($user_role, ['Admin', 'Manager', 'Purchasing', 'Storekeeper']);
if (!$can_create_grn) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get parameters
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$warehouse_id = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
$po_id = isset($_GET['po']) ? intval($_GET['po']) : 0;

// Get current user info
$user_id = $_SESSION['user_id'];

// Get supplier details if provided
$supplier = null;
if ($supplier_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get warehouse details if provided
$warehouse = null;
if ($warehouse_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ? AND status = 'active'");
    $stmt->execute([$warehouse_id]);
    $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get purchase order details if provided
$purchase_order = null;
$po_items = [];
if ($po_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($purchase_order) {
        // Get PO items
        $stmt = $pdo->prepare("
            SELECT poi.*, p.product_name, p.sku, p.unit, p.barcode
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.product_id
            WHERE poi.purchase_order_id = ?
        ");
        $stmt->execute([$po_id]);
        $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If supplier not provided, get from PO
        if (!$supplier && $purchase_order['supplier_id']) {
            $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
            $stmt->execute([$purchase_order['supplier_id']]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            $supplier_id = $supplier['supplier_id'];
        }
        
        // If warehouse not provided, get from PO
        if (!$warehouse && $purchase_order['warehouse_id']) {
            $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE warehouse_id = ?");
            $stmt->execute([$purchase_order['warehouse_id']]);
            $warehouse = $stmt->fetch(PDO::FETCH_ASSOC);
            $warehouse_id = $warehouse['warehouse_id'];
        }
    }
}

// Get suppliers for dropdown
$suppliers = $pdo->query("SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Get warehouses for dropdown
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name, location FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Get pending purchase orders
$pending_pos = $pdo->query("
    SELECT po.purchase_order_id, po.order_number, po.order_date, s.supplier_name,
           COUNT(poi.order_item_id) as total_items,
           SUM(poi.quantity - IFNULL(pri.received_qty, 0)) as pending_qty
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
    LEFT JOIN (
        SELECT purchase_order_item_id, SUM(quantity_received) as received_qty
        FROM receipt_items
        GROUP BY purchase_order_item_id
    ) pri ON poi.order_item_id = pri.purchase_order_item_id
    WHERE po.status IN ('ordered', 'partially_received')
    GROUP BY po.purchase_order_id
    HAVING pending_qty > 0
    ORDER BY po.order_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php
function generate_grn_number() {
    $prefix = 'GRN';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $random = mt_rand(100, 999);
    return $prefix . '-' . $year . $month . $day . '-' . $random;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-clipboard-plus"></i> Create Goods Received Note (GRN)</h2>
                    <p class="text-muted mb-0">Record receipt of goods from suppliers</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="grn.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to GRNs
                    </a>
                    <button type="button" class="btn btn-info" onclick="printGRN()">
                        <i class="bi bi-printer"></i> Print Preview
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> GRN Details</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="grnForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="receipt_number" class="form-label">GRN Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="receipt_number" name="receipt_number" 
                               value="<?= generate_grn_number() ?>" required readonly>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="receipt_date" class="form-label">Receipt Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="receipt_date" name="receipt_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="received_by" class="form-label">Received By <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="received_by" name="received_by" 
                               value="<?= htmlspecialchars($_SESSION['username']) ?>" required>
                    </div>
                </div>
                
                <!-- Supplier and Warehouse -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select" id="supplier_id" name="supplier_id" required onchange="loadSupplierInfo()">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supp): ?>
                                <option value="<?= $supp['supplier_id'] ?>" 
                                    <?= ($supplier_id > 0 && $supp['supplier_id'] == $supplier_id) ? 'selected' : '' ?>>
                                    <?= safe_output($supp['supplier_name']) ?>
                                    <?php if (!empty($supp['company_name'])): ?>
                                        (<?= safe_output($supp['company_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['warehouse_id'] ?>" 
                                    <?= ($warehouse_id > 0 && $wh['warehouse_id'] == $warehouse_id) ? 'selected' : '' ?>>
                                    <?= safe_output($wh['warehouse_name']) ?>
                                    <?php if (!empty($wh['location'])): ?>
                                        - <?= safe_output($wh['location']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Purchase Order Selection -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label for="purchase_order_id" class="form-label">Purchase Order (Optional)</label>
                        <div class="input-group">
                            <select class="form-select" id="purchase_order_id" name="purchase_order_id" onchange="loadPurchaseOrderItems()">
                                <option value="">Select Purchase Order</option>
                                <?php foreach ($pending_pos as $po): ?>
                                    <option value="<?= $po['purchase_order_id'] ?>" 
                                        <?= ($po_id > 0 && $po['purchase_order_id'] == $po_id) ? 'selected' : '' ?>
                                        data-supplier-id="<?= $po['supplier_id'] ?? 0 ?>">
                                        <?= safe_output($po['order_number']) ?> - 
                                        <?= safe_output($po['supplier_name']) ?> 
                                        (<?= $po['pending_qty'] ?> items pending)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearPOSelection()">
                                <i class="bi bi-x"></i> Clear
                            </button>
                        </div>
                        <small class="text-muted">Select a purchase order to auto-populate items</small>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="delivery_note" class="form-label">Delivery Note Number</label>
                        <input type="text" class="form-control" id="delivery_note" name="delivery_note" 
                               placeholder="Supplier's delivery note number">
                    </div>
                </div>
                
                <!-- Supplier Information Card -->
                <div class="card mb-4" id="supplierInfoCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-truck"></i> Supplier Information</h6>
                    </div>
                    <div class="card-body" id="supplierInfoBody">
                        <!-- Supplier info will be loaded here -->
                    </div>
                </div>
                
                <!-- Received Items -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-list-check"></i> Received Items</h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-light" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-light ms-2" onclick="clearAllItems()">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th width="30%">Product/Item <span class="text-danger">*</span></th>
                                        <th width="10%">SKU/Barcode</th>
                                        <th width="10%">Quantity <span class="text-danger">*</span></th>
                                        <th width="10%">Unit</th>
                                        <th width="15%">Unit Price</th>
                                        <th width="10%">Batch No.</th>
                                        <th width="10%">Expiry Date</th>
                                        <th width="5%">Total</th>
                                        <th width="5%"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <!-- Items will be added here -->
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="9">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                                                        <i class="bi bi-plus-circle"></i> Add Item
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="scanBarcode()">
                                                        <i class="bi bi-upc-scan"></i> Scan Barcode
                                                    </button>
                                                </div>
                                                <div class="text-end">
                                                    <strong>Total Items: <span id="totalItems">0</span></strong><br>
                                                    <strong>Total Value: <span id="totalValue">0.00</span> TZS</strong>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Quality Check & Notes -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Quality Check</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Overall Condition</label>
                                    <select class="form-select" name="quality_condition">
                                        <option value="excellent">Excellent</option>
                                        <option value="good" selected>Good</option>
                                        <option value="fair">Fair</option>
                                        <option value="poor">Poor</option>
                                    </select>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="packaging_ok" id="packaging_ok" checked>
                                    <label class="form-check-label" for="packaging_ok">Packaging OK</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="quantity_ok" id="quantity_ok" checked>
                                    <label class="form-check-label" for="quantity_ok">Quantity OK</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="damage_check" id="damage_check">
                                    <label class="form-check-label" for="damage_check">Damage Checked</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="expiry_check" id="expiry_check">
                                    <label class="form-check-label" for="expiry_check">Expiry Dates Checked</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Notes & Remarks</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Any special notes, remarks, or observations about the received goods"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="inspected_by" class="form-label">Inspected By</label>
                                    <input type="text" class="form-control" id="inspected_by" name="inspected_by" 
                                           value="<?= htmlspecialchars($_SESSION['username']) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                <input type="hidden" name="total_received" id="totalReceivedHidden" value="0">
                <input type="hidden" name="status" value="draft">
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="saveAsDraft()">
                            <i class="bi bi-save"></i> Save as Draft
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Create GRN
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Product Search Modal -->
<div class="modal fade" id="productSearchModal" tabindex="-1" aria-labelledby="productSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="productSearchModalLabel">
                    <i class="bi bi-search"></i> Search Product
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" class="form-control" id="productSearch" 
                               placeholder="Search by product name, SKU, or barcode">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchProducts()">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Barcode</th>
                                <th>Unit</th>
                                <th>Current Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productsBody">
                            <!-- Products will be loaded here -->
                        </tbody>
                    </table>
                </div>
                <div id="noProducts" class="text-center d-none">
                    <p class="text-muted">No products found. Try a different search term.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="barcodeScannerModalLabel">
                    <i class="bi bi-upc-scan"></i> Barcode Scanner
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="bi bi-upc" style="font-size: 3rem;"></i>
                    <p class="mt-2">Scan barcode or enter manually</p>
                </div>
                <div class="mb-3">
                    <label for="barcodeInput" class="form-label">Barcode</label>
                    <input type="text" class="form-control" id="barcodeInput" placeholder="Scan or enter barcode" autofocus>
                    <small class="text-muted">Press Enter after scanning or typing</small>
                </div>
                <div id="barcodeResult" class="d-none">
                    <!-- Barcode scan result will be shown here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="addScannedItem()">Add Item</button>
            </div>
        </div>
    </div>
</div>


<script>
let currentItemIndex = null;
let itemCount = 0;
let productsCache = [];

$(document).ready(function() {
    // Add first item row
    addItemRow();
    
    // Load supplier info if supplier is already selected
    if ($('#supplier_id').val()) {
        loadSupplierInfo();
    }
    
    // Load PO items if PO is already selected
    if ($('#purchase_order_id').val()) {
        loadPurchaseOrderItems();
    }
    
    // Form submission
    $('#grnForm').on('submit', function(e) {
        e.preventDefault();
        createGRN('completed');
    });
    
    // Load products cache
    loadProductsCache();
    
    // Auto-focus barcode input when modal opens
    $('#barcodeScannerModal').on('shown.bs.modal', function() {
        $('#barcodeInput').focus();
    });
    
    // Handle barcode input
    $('#barcodeInput').on('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleBarcodeInput($(this).val());
        }
    });
    
    // Calculate totals when quantities or prices change
    $(document).on('input', '.item-quantity, .item-price', function() {
        const index = $(this).closest('tr').data('index');
        calculateItemTotal(index);
        calculateTotals();
    });
});

function loadProductsCache() {
    $.ajax({
        url: 'api/get_products.php',
        type: 'GET',
        data: { active_only: true },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                productsCache = response.data;
            }
        },
        error: function(error) {
            console.error('Error loading products:', error);
        }
    });
}

function addItemRow(product = null) {
    const index = itemCount++;
    const html = `
        <tr id="item-row-${index}" data-index="${index}">
            <td>
                <div class="input-group">
                    <input type="text" class="form-control item-name" 
                           name="items[${index}][product_name]" 
                           placeholder="Product name" required
                           value="${product ? product.product_name : ''}">
                    <button type="button" class="btn btn-outline-secondary" 
                            onclick="openProductSearch(${index})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <input type="hidden" class="item-product-id" 
                       name="items[${index}][product_id]" 
                       value="${product ? product.product_id : ''}">
                <input type="hidden" class="item-po-item-id" 
                       name="items[${index}][purchase_order_item_id]" 
                       value="${product ? product.order_item_id || '' : ''}">
            </td>
            <td>
                <input type="text" class="form-control item-sku" 
                       name="items[${index}][sku]" 
                       placeholder="SKU" 
                       value="${product ? product.sku || '' : ''}">
            </td>
            <td>
                <input type="number" class="form-control item-quantity" 
                       name="items[${index}][quantity_received]" 
                       min="0.001" step="0.001" value="1" required>
            </td>
            <td>
                <select class="form-select item-unit" name="items[${index}][unit]">
                    <option value="pcs" ${product && product.unit == 'pcs' ? 'selected' : ''}>pcs</option>
                    <option value="kg" ${product && product.unit == 'kg' ? 'selected' : ''}>kg</option>
                    <option value="g" ${product && product.unit == 'g' ? 'selected' : ''}>g</option>
                    <option value="l" ${product && product.unit == 'l' ? 'selected' : ''}>l</option>
                    <option value="ml" ${product && product.unit == 'ml' ? 'selected' : ''}>ml</option>
                    <option value="m" ${product && product.unit == 'm' ? 'selected' : ''}>m</option>
                    <option value="box" ${product && product.unit == 'box' ? 'selected' : ''}>box</option>
                    <option value="carton" ${product && product.unit == 'carton' ? 'selected' : ''}>carton</option>
                </select>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text">TZS</span>
                    <input type="number" class="form-control item-price" 
                           name="items[${index}][unit_price]" 
                           min="0" step="0.01" value="${product ? product.unit_price || 0 : 0}">
                </div>
            </td>
            <td>
                <input type="text" class="form-control item-batch" 
                       name="items[${index}][batch_number]" 
                       placeholder="Batch No.">
            </td>
            <td>
                <input type="date" class="form-control item-expiry" 
                       name="items[${index}][expiry_date]">
            </td>
            <td>
                <span class="item-total">0.00</span>
                <span class="ms-1">TZS</span>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeItemRow(${index})">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    $('#itemsBody').append(html);
    
    // Calculate initial total
    calculateItemTotal(index);
    calculateTotals();
    
    return index;
}

function openProductSearch(index) {
    currentItemIndex = index;
    $('#productSearchModal').modal('show');
    $('#productSearch').focus();
}

function searchProducts() {
    const searchTerm = $('#productSearch').val().toLowerCase();
    const tbody = $('#productsBody');
    const noProducts = $('#noProducts');
    
    tbody.empty();
    
    if (searchTerm.length < 2) {
        tbody.html('<tr><td colspan="6" class="text-center">Enter at least 2 characters to search</td></tr>');
        return;
    }
    
    const filteredProducts = productsCache.filter(product => {
        return (product.product_name && product.product_name.toLowerCase().includes(searchTerm)) ||
               (product.sku && product.sku.toLowerCase().includes(searchTerm)) ||
               (product.barcode && product.barcode.toLowerCase().includes(searchTerm));
    });
    
    if (filteredProducts.length === 0) {
        tbody.html('<tr><td colspan="6" class="text-center">No products found</td></tr>');
        return;
    }
    
    filteredProducts.slice(0, 10).forEach(product => {
        const row = `
            <tr>
                <td>
                    <strong>${product.product_name}</strong>
                    ${product.description ? '<br><small class="text-muted">' + product.description.substring(0, 50) + '...</small>' : ''}
                </td>
                <td>${product.sku || 'N/A'}</td>
                <td>${product.barcode || 'N/A'}</td>
                <td>${product.unit || 'pcs'}</td>
                <td>${product.current_stock || 0}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-primary" 
                            onclick="selectProduct(${product.product_id})">
                        <i class="bi bi-plus"></i> Select
                    </button>
                </td>
            </tr>
        `;
        tbody.append(row);
    });
}

function selectProduct(productId) {
    const product = productsCache.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        row.find('.item-sku').val(product.sku || '');
        row.find('.item-unit').val(product.unit || 'pcs');
        row.find('.item-price').val(product.cost_price || 0);
        
        $('#productSearchModal').modal('hide');
        $('#productSearch').val('');
        $('#productsBody').empty();
        
        calculateItemTotal(currentItemIndex);
        calculateTotals();
    }
}

function calculateItemTotal(index) {
    const row = $(`#item-row-${index}`);
    const quantity = parseFloat(row.find('.item-quantity').val()) || 0;
    const price = parseFloat(row.find('.item-price').val()) || 0;
    const total = quantity * price;
    row.find('.item-total').text(total.toFixed(2));
}

function calculateTotals() {
    let totalItems = 0;
    let totalValue = 0;
    
    $('[id^="item-row-"]').each(function() {
        const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        totalItems += quantity;
        totalValue += quantity * price;
    });
    
    $('#totalItems').text(totalItems.toFixed(3));
    $('#totalValue').text(totalValue.toFixed(2));
    $('#totalReceivedHidden').val(totalValue.toFixed(2));
}

function removeItemRow(index) {
    $(`#item-row-${index}`).remove();
    calculateTotals();
}

function clearAllItems() {
    Swal.fire({
        title: 'Clear All Items?',
        text: 'Are you sure you want to remove all items?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Clear All',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#itemsBody').empty();
            itemCount = 0;
            calculateTotals();
            addItemRow();
        }
    });
}

function loadSupplierInfo() {
    const supplierId = $('#supplier_id').val();
    if (!supplierId) {
        $('#supplierInfoCard').hide();
        return;
    }
    
    $.ajax({
        url: 'api/get_supplier.php',
        type: 'GET',
        data: { id: supplierId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const supplier = response.data;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>${supplier.supplier_name}</strong></p>
                            ${supplier.contact_person ? `<p>Contact: ${supplier.contact_person}</p>` : ''}
                            ${supplier.phone ? `<p>Phone: ${supplier.phone}</p>` : ''}
                            ${supplier.email ? `<p>Email: ${supplier.email}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            ${supplier.address ? `<p>Address: ${supplier.address}</p>` : ''}
                            ${supplier.city ? `<p>City: ${supplier.city}</p>` : ''}
                            ${supplier.country ? `<p>Country: ${supplier.country}</p>` : ''}
                        </div>
                    </div>
                `;
                $('#supplierInfoBody').html(html);
                $('#supplierInfoCard').show();
            }
        },
        error: function(error) {
            console.error('Error loading supplier info:', error);
        }
    });
}

function loadPurchaseOrderItems() {
    const poId = $('#purchase_order_id').val();
    if (!poId) {
        return;
    }
    
    // Clear existing items
    $('#itemsBody').empty();
    itemCount = 0;
    
    $.ajax({
        url: 'api/get_po_items.php',
        type: 'GET',
        data: { po_id: poId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Update supplier and warehouse from PO
                if (response.data.supplier_id) {
                    $('#supplier_id').val(response.data.supplier_id);
                    loadSupplierInfo();
                }
                if (response.data.warehouse_id) {
                    $('#warehouse_id').val(response.data.warehouse_id);
                }
                
                // Add PO items
                response.data.items.forEach(item => {
                    addItemRow(item);
                });
                
                Swal.fire({
                    icon: 'success',
                    title: 'Items Loaded',
                    text: `${response.data.items.length} items loaded from purchase order.`,
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        },
        error: function(error) {
            console.error('Error loading PO items:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load purchase order items.'
            });
        }
    });
}

function clearPOSelection() {
    $('#purchase_order_id').val('');
    Swal.fire({
        icon: 'info',
        title: 'PO Cleared',
        text: 'Purchase order selection cleared.',
        timer: 1500,
        showConfirmButton: false
    });
}

function scanBarcode() {
    $('#barcodeScannerModal').modal('show');
}

function handleBarcodeInput(barcode) {
    if (!barcode.trim()) return;
    
    // Search for product by barcode
    const product = productsCache.find(p => p.barcode && p.barcode === barcode);
    
    if (product) {
        // Show product found
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-success">
                <strong>Product Found:</strong> ${product.product_name}<br>
                <small>SKU: ${product.sku || 'N/A'} | Unit: ${product.unit || 'pcs'}</small>
            </div>
        `);
        
        // Add item with this product
        const index = addItemRow(product);
        $('#barcodeScannerModal').modal('hide');
        
        // Focus on quantity field of new item
        setTimeout(() => {
            $(`#item-row-${index} .item-quantity`).focus();
        }, 100);
        
    } else {
        $('#barcodeResult').removeClass('d-none').html(`
            <div class="alert alert-warning">
                <strong>Product Not Found</strong><br>
                <small>Barcode "${barcode}" not found in database.</small>
            </div>
        `);
    }
    
    $('#barcodeInput').val('');
}

function addScannedItem() {
    const barcode = $('#barcodeInput').val();
    if (barcode) {
        handleBarcodeInput(barcode);
    }
}

function createGRN(status = 'completed') {
    // Validate form
    if (!validateForm(status === 'draft')) {
        return;
    }
    
    const formData = new FormData($('#grnForm')[0]);
    formData.append('status', status);
    
    // Get items data
    const items = [];
    $('[id^="item-row-"]').each(function() {
        const item = {
            product_id: $(this).find('.item-product-id').val(),
            purchase_order_item_id: $(this).find('.item-po-item-id').val(),
            product_name: $(this).find('.item-name').val(),
            sku: $(this).find('.item-sku').val(),
            quantity_received: $(this).find('.item-quantity').val(),
            unit: $(this).find('.item-unit').val(),
            unit_price: $(this).find('.item-price').val(),
            batch_number: $(this).find('.item-batch').val(),
            expiry_date: $(this).find('.item-expiry').val()
        };
        
        if (item.product_name && item.quantity_received) {
            items.push(item);
        }
    });
    
    // Add items to form data
    formData.append('items', JSON.stringify(items));
    
    // Show loading state
    const submitBtn = $('#grnForm [type="submit"]');
    const originalText = submitBtn.html();
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.ajax({
        url: 'api/create_grn.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    showConfirmButton: false,
                    timer: 1500
                }).then(() => {
                    if (response.receipt_id) {
                        window.location.href = 'grn_view.php?id=' + response.receipt_id;
                    } else {
                        window.location.href = 'grn.php';
                    }
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
                submitBtn.prop('disabled', false).html(originalText);
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred. Please try again.'
            });
            submitBtn.prop('disabled', false).html(originalText);
            console.error('Error:', error);
        }
    });
}

function saveAsDraft() {
    if (!validateForm(true)) {
        return;
    }
    createGRN('draft');
}

function validateForm(isDraft = false) {
    // Check if at least one item is added
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items',
            text: 'Please add at least one received item.'
        });
        return false;
    }
    
    // Check if all items have valid data
    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = $(this).find('.item-quantity').val();
        
        if (productName && quantity > 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Items',
            text: 'Please ensure all items have a name and quantity.'
        });
        return false;
    }
    
    // Check required fields
    const requiredFields = ['receipt_number', 'receipt_date', 'received_by', 'supplier_id', 'warehouse_id'];
    for (const field of requiredFields) {
        const value = $(`#${field}`).val();
        if (!value && !isDraft) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: `Please fill in the ${field.replace('_', ' ')} field.`
            });
            $(`#${field}`).focus();
            return false;
        }
    }
    
    return true;
}

function printGRN() {
    // Validate form first
    if (!validateForm(true)) {
        return;
    }
    
    // Save as draft and print
    createGRN('draft');
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
}

#itemsTable input, #itemsTable select {
    font-size: 0.85rem;
}

#itemsTable .form-control {
    padding: 0.25rem 0.5rem;
}

.item-total {
    font-weight: bold;
    color: #198754;
}

/* Quality check checkboxes */
.form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}

/* Barcode scanner modal */
#barcodeScannerModal .modal-body {
    min-height: 200px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding: 0.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    
    #itemsTable th, #itemsTable td {
        padding: 0.5rem;
    }
    
    #itemsTable th:nth-child(1),
    #itemsTable td:nth-child(1) {
        min-width: 150px;
    }
    
    #itemsTable th:nth-child(2),
    #itemsTable th:nth-child(6),
    #itemsTable th:nth-child(7),
    #itemsTable td:nth-child(2),
    #itemsTable td:nth-child(6),
    #itemsTable td:nth-child(7) {
        display: none;
    }
}

@media print {
    .navbar, .card-header .btn, .dropdown, 
    .modal, .fixed-bottom, .d-print-none {
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