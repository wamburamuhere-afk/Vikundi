<?php
// File: sales_order_create.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for sales order creation permissions
$can_create_sales_orders = in_array($user_role, ['Admin', 'Manager', 'Sales']);
if (!$can_create_sales_orders) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get parameters
$customer_id = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$quote_id = isset($_GET['quote']) ? intval($_GET['quote']) : 0;
$sales_order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get current user info
$user_id = $_SESSION['user_id'];

// Get customer details if provided
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'inactive'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get quote details if provided
$quote = null;
$quote_items = [];
if ($quote_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE sales_order_id = ? AND is_quote = TRUE");
    $stmt->execute([$quote_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($quote) {
        // Get quote items
        $stmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id = ?");
        $stmt->execute([$quote_id]);
        $quote_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If customer not provided, get from quote
        if (!$customer && $quote['customer_id']) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $stmt->execute([$quote['customer_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $customer_id = $customer['customer_id'];
        }
    }
}

// Get customers for dropdown
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Get salespeople for dropdown
$salespeople = $pdo->query("SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = '1' AND role IN ('Admin', 'Manager', 'Sales') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get payment terms
$payment_terms = [
    'cod' => 'Cash on Delivery',
    '7_days' => '7 Days',
    '15_days' => '15 Days',
    '30_days' => '30 Days',
    '60_days' => '60 Days',
    '90_days' => '90 Days',
    'cash' => 'Immediate Payment'
];

// Get currency options
$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling'
];

// Helper functions removed, now in helpers.php

function generate_order_number($is_quote = false) {
    $prefix = $is_quote ? 'QT' : 'SO';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $random = mt_rand(100, 999);
    return $prefix . '-' . $year . $month . $day . '-' . $random;
}

// Check if this should be a quote
$is_quote = isset($_GET['quote']) ? true : false;
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-cart-plus"></i> <?= $is_quote ? 'Create Quotation' : 'Create Sales Order' ?></h2>
                    <p class="text-muted mb-0"><?= $is_quote ? 'Create a quotation for customer approval' : 'Create a new sales order for customer' ?></p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('sales_orders') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Orders
                    </a>
                    <?php if ($is_quote): ?>
                    <a href="<?= getUrl('sales_order_create') ?>" class="btn btn-primary">
                        <i class="bi bi-cart"></i> Switch to Sales Order
                    </a>
                    <?php else: ?>
                    <a href="<?= getUrl('sales_order_create') ?>?quote=1" class="btn btn-primary">
                        <i class="bi bi-file-text"></i> Switch to Quotation
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h5 class="mb-0 text-dark"><i class="bi bi-file-text"></i> <?= $is_quote ? 'Quotation Details' : 'Order Details' ?></h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <form id="salesOrderForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label for="order_number" class="form-label"><?= $is_quote ? 'Quotation #' : 'Order #' ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="order_number" name="order_number" 
                               value="<?= generate_order_number($is_quote) ?>" required readonly>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="order_date" class="form-label"><?= $is_quote ? 'Quotation Date' : 'Order Date' ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="order_date" name="order_date" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="delivery_date" class="form-label">Delivery Date</label>
                        <input type="date" class="form-control" id="delivery_date" name="delivery_date" 
                               min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label for="customer_id" class="form-label">Customer <span class="text-danger">*</span></label>
                        <select class="form-select" id="customer_id" name="customer_id" required onchange="loadCustomerInfo()">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>" 
                                    <?= ($customer_id > 0 && $cust['customer_id'] == $customer_id) ? 'selected' : '' ?>
                                    data-payment-terms="<?= $cust['payment_terms'] ?? '' ?>"
                                    data-currency="<?= $cust['currency'] ?? 'TZS' ?>"
                                    data-credit-limit="<?= $cust['credit_limit'] ?? 0 ?>">
                                    <?= safe_output($cust['customer_name']) ?>
                                    <?php if (!empty($cust['company_name'])): ?>
                                        (<?= safe_output($cust['company_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="salesperson_id" class="form-label">Salesperson</label>
                        <select class="form-select" id="salesperson_id" name="salesperson_id">
                            <option value="">Select Salesperson</option>
                            <?php foreach ($salespeople as $salesperson): ?>
                                <option value="<?= $salesperson['user_id'] ?>" 
                                    <?= ($user_id == $salesperson['user_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($salesperson['username']) ?>
                                    <?php if (!empty($salesperson['full_name'])): ?>
                                        (<?= safe_output($salesperson['full_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Customer Information Card -->
                <div class="card mb-4" id="customerInfoCard" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-person-circle"></i> Customer Information</h6>
                    </div>
                    <div class="card-body" id="customerInfoBody">
                        <!-- Customer info will be loaded here -->
                    </div>
                </div>
                
                <!-- Financial Information -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <label for="currency" class="form-label">Currency <span class="text-danger">*</span></label>
                        <select class="form-select" id="currency" name="currency" required>
                            <?php foreach ($currencies as $code => $name): ?>
                                <option value="<?= $code ?>" 
                                    <?= ($customer && isset($customer['currency']) && $customer['currency'] == $code) ? 'selected' : '' ?>
                                    <?= (!$customer && $code == 'TZS') ? 'selected' : '' ?>>
                                    <?= $code ?> - <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label for="payment_terms" class="form-label">Payment Terms</label>
                        <select class="form-select" id="payment_terms" name="payment_terms">
                            <option value="">Select Terms</option>
                            <?php foreach ($payment_terms as $value => $label): ?>
                                <option value="<?= $value ?>">
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <label for="reference" class="form-label">Customer Reference</label>
                        <input type="text" class="form-control" id="reference" name="reference" 
                               placeholder="Customer PO/Reference number">
                    </div>
                    
                    <?php if ($is_quote): ?>
                    <div class="col-md-3 mb-3">
                        <label for="valid_until" class="form-label">Valid Until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until" 
                               min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Order Items -->
                <div class="card mb-4">
                    <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 text-dark"><i class="bi bi-list-check"></i> Order Items</h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="clearAllItems()">
                                <i class="bi bi-trash"></i> Clear All
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="itemsTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="30%">Product/Item <span class="text-danger">*</span></th>
                                        <th width="10%">SKU</th>
                                        <th width="10%">Quantity <span class="text-danger">*</span></th>
                                        <th width="10%">Unit</th>
                                        <th width="15%">Unit Price <span class="text-danger">*</span></th>
                                        <th width="10%">Tax Rate</th>
                                        <th width="10%">Discount %</th>
                                        <th width="10%">Total</th>
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
                                                    <strong>Total Quantity: <span id="totalQuantity">0.000</span></strong>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Order Summary -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-chat-left-text"></i> Notes & Terms</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Order Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                                              placeholder="Special instructions, delivery notes, or additional information"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="terms_conditions" class="form-label">Terms & Conditions</label>
                                    <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="3" 
                                              placeholder="Payment terms, delivery terms, warranty, etc."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-calculator"></i> Order Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span id="subtotal">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tax:</span>
                                    <span id="tax-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>
                                        Discount 
                                        <small>(<span id="discount-percent">0</span>%)</small>:
                                    </span>
                                    <span id="discount-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Shipping:</span>
                                    <div class="input-group input-group-sm" style="width: 150px;">
                                        <span class="input-group-text currency-symbol">TZS</span>
                                        <input type="number" class="form-control" id="shipping_cost" name="shipping_cost" 
                                               min="0" step="0.01" value="0" onchange="calculateTotals()">
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between fw-bold fs-5">
                                    <span>Grand Total:</span>
                                    <span id="grand-total">0.00</span>
                                    <span class="currency-symbol">TZS</span>
                                </div>
                                <div class="mt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="apply_tax" name="apply_tax" checked onchange="calculateTotals()">
                                        <label class="form-check-label" for="apply_tax">
                                            Apply Tax
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="apply_discount" name="apply_discount" onchange="calculateTotals()">
                                        <label class="form-check-label" for="apply_discount">
                                            Apply Order Discount
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden fields -->
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
                <input type="hidden" name="is_quote" value="<?= $is_quote ? '1' : '0' ?>">
                <input type="hidden" id="subtotal_hidden" name="subtotal" value="0">
                <input type="hidden" id="tax_hidden" name="tax_amount" value="0">
                <input type="hidden" id="discount_hidden" name="discount_amount" value="0">
                <input type="hidden" id="grand_total_hidden" name="grand_total" value="0">
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="saveAsDraft()">
                            <i class="bi bi-save"></i> Save as Draft
                        </button>
                        <?php if ($is_quote): ?>
                        <button type="button" class="btn btn-info" onclick="saveAsQuote()">
                            <i class="bi bi-file-text"></i> Save Quotation
                        </button>
                        <?php else: ?>
                        <?php if ($sales_order_id > 0): ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Create Order
                        </button>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-primary" onclick="createAndApprove()">
                            <i class="bi bi-check2-all"></i> Create & Approve
                        </button>
                        <?php endif; ?>
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
                                <th>Current Stock</th>
                                <th>Unit Price</th>
                                <th>Tax Rate</th>
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
let taxRates = [
    { rate_id: 0, rate_name: 'No Tax', rate_percentage: 0 },
    { rate_id: 1, rate_name: 'VAT 18%', rate_percentage: 18 },
    { rate_id: 2, rate_name: 'Reduced 5%', rate_percentage: 5 }
];

$(document).ready(function() {
    // Add first item row
    addItemRow();
    
    // Load customer info if customer is already selected
    if ($('#customer_id').val()) {
        loadCustomerInfo();
    }
    
    // Load quote items if quote is provided
    <?php if ($quote && count($quote_items) > 0): ?>
    loadQuoteItems(<?= json_encode($quote_items) ?>);
    <?php endif; ?>
    
    // Form submission
    $('#salesOrderForm').on('submit', function(e) {
        e.preventDefault();
        createSalesOrder('pending');
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
    
    // Update currency symbol
    $('#currency').change(function() {
        updateCurrencySymbol();
    });
    
    // Calculate totals when quantities or prices change
    $(document).on('input', '.item-quantity, .item-price, .item-tax, .item-discount', function() {
        const index = $(this).closest('tr').data('index');
        calculateItemTotal(index);
        calculateTotals();
    });
    
    // Initialize with default tax rates (you can load from API)
    loadTaxRates();
});

function loadProductsCache() {
    $.ajax({
        url: '<?= getUrl('/api/account/get_products.php') ?>',
        type: 'GET',
        data: { active_only: true, with_stock: true },
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

function loadTaxRates() {
    // Load tax rates from API or use defaults
    $.ajax({
        url: '<?= getUrl('/api/account/get_tax_rates.php') ?>',
        type: 'GET',
        success: function(response) {
            if (response.success) {
                taxRates = response.data;
            }
        },
        error: function(error) {
            console.error('Error loading tax rates:', error);
            // Use default tax rates
        }
    });
}

function addItemRow(product = null) {
    const index = itemCount++;
    const currency = $('#currency').val();
    
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
            </td>
            <td>
                <input type="text" class="form-control item-sku" 
                       name="items[${index}][sku]" 
                       placeholder="SKU" readonly
                       value="${product ? product.sku || '' : ''}">
            </td>
            <td>
                <input type="number" class="form-control item-quantity" 
                       name="items[${index}][quantity]" 
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
                    <span class="input-group-text currency-symbol">${currency}</span>
                    <input type="number" class="form-control item-price" 
                           name="items[${index}][unit_price]" 
                           min="0" step="0.01" value="${product ? (product.selling_price || 0) : 0}" required>
                </div>
            </td>
            <td>
                <select class="form-select item-tax" name="items[${index}][tax_rate]">
                    ${taxRates.map(rate => `
                        <option value="${rate.rate_percentage}" ${product && product.tax_rate == rate.rate_percentage ? 'selected' : ''}>
                            ${rate.rate_name} (${rate.rate_percentage}%)
                        </option>
                    `).join('')}
                </select>
            </td>
            <td>
                <div class="input-group">
                    <input type="number" class="form-control item-discount" 
                           name="items[${index}][discount_percent]" 
                           min="0" max="100" step="0.01" value="0">
                    <span class="input-group-text">%</span>
                </div>
            </td>
            <td>
                <span class="item-total">0.00</span>
                <span class="ms-1 currency-symbol">${currency}</span>
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
                <td>${product.current_stock || 0}</td>
                <td>${product.selling_price || 0}</td>
                <td>${product.tax_rate || 0}%</td>
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
        row.find('.item-price').val(product.selling_price || 0);
        row.find('.item-tax').val(product.tax_rate || 0);
        
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
    const taxRate = parseFloat(row.find('.item-tax').val()) || 0;
    const discountPercent = parseFloat(row.find('.item-discount').val()) || 0;
    
    const subtotal = quantity * price;
    const discountAmount = subtotal * (discountPercent / 100);
    const taxableAmount = subtotal - discountAmount;
    const taxAmount = taxableAmount * (taxRate / 100);
    const total = taxableAmount + taxAmount;
    
    row.find('.item-total').text(total.toFixed(2));
}

function calculateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    let discountTotal = 0;
    let totalItems = 0;
    let totalQuantity = 0;
    let applyTax = $('#apply_tax').is(':checked');
    let applyDiscount = $('#apply_discount').is(':checked');
    
    $('[id^="item-row-"]').each(function() {
        const quantity = parseFloat($(this).find('.item-quantity').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        const taxRate = applyTax ? parseFloat($(this).find('.item-tax').val()) || 0 : 0;
        const discountPercent = applyDiscount ? parseFloat($(this).find('.item-discount').val()) || 0 : 0;
        
        const itemSubtotal = quantity * price;
        const itemDiscount = itemSubtotal * (discountPercent / 100);
        const taxableAmount = itemSubtotal - itemDiscount;
        const itemTax = taxableAmount * (taxRate / 100);
        
        subtotal += itemSubtotal;
        discountTotal += itemDiscount;
        taxTotal += itemTax;
        totalItems++;
        totalQuantity += quantity;
    });
    
    const shippingCost = parseFloat($('#shipping_cost').val()) || 0;
    const grandTotal = subtotal - discountTotal + taxTotal + shippingCost;
    
    // Update display
    $('#subtotal').text(subtotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#discount-total').text(discountTotal.toFixed(2));
    $('#grand-total').text(grandTotal.toFixed(2));
    $('#totalItems').text(totalItems);
    $('#totalQuantity').text(totalQuantity.toFixed(3));
    
    // Calculate average discount percentage
    const discountPercent = subtotal > 0 ? (discountTotal / subtotal * 100).toFixed(1) : 0;
    $('#discount-percent').text(discountPercent);
    
    // Update hidden fields
    $('#subtotal_hidden').val(subtotal.toFixed(2));
    $('#tax_hidden').val(taxTotal.toFixed(2));
    $('#discount_hidden').val(discountTotal.toFixed(2));
    $('#grand_total_hidden').val(grandTotal.toFixed(2));
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

function updateCurrencySymbol() {
    const currency = $('#currency').val();
    $('.currency-symbol').text(currency);
}

function loadCustomerInfo() {
    const customerId = $('#customer_id').val();
    if (!customerId) {
        $('#customerInfoCard').hide();
        return;
    }
    
    const selectedOption = $('#customer_id option:selected');
    const paymentTerms = selectedOption.data('payment-terms');
    const currency = selectedOption.data('currency');
    const creditLimit = selectedOption.data('credit-limit');
    
    // Update form fields
    if (paymentTerms) {
        $('#payment_terms').val(paymentTerms);
    }
    if (currency) {
        $('#currency').val(currency);
        updateCurrencySymbol();
    }
    
    $.ajax({
        url: '<?= getUrl('/api/account/get_customer.php') ?>',
        type: 'GET',
        data: { id: customerId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const customer = response.data;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>${customer.customer_name}</strong></p>
                            ${customer.company_name ? `<p>Company: ${customer.company_name}</p>` : ''}
                            ${customer.phone ? `<p>Phone: ${customer.phone}</p>` : ''}
                            ${customer.email ? `<p>Email: ${customer.email}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            ${customer.address ? `<p>Address: ${customer.address}</p>` : ''}
                            ${customer.city ? `<p>City: ${customer.city}</p>` : ''}
                            ${customer.country ? `<p>Country: ${customer.country}</p>` : ''}
                            <p>Credit Limit: ${formatCurrency(customer.credit_limit || 0)}</p>
                            <p>Current Balance: ${formatCurrency(customer.current_balance || 0)}</p>
                        </div>
                    </div>
                `;
                $('#customerInfoBody').html(html);
                $('#customerInfoCard').show();
                
                // Check credit limit
                const currentBalance = parseFloat(customer.current_balance) || 0;
                const creditLimit = parseFloat(customer.credit_limit) || 0;
                const grandTotal = parseFloat($('#grand_total_hidden').val()) || 0;
                
                if (creditLimit > 0 && (currentBalance + grandTotal) > creditLimit) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Credit Limit Warning',
                        text: `Order amount exceeds customer's credit limit. Credit Limit: ${formatCurrency(creditLimit)}, Current Balance: ${formatCurrency(currentBalance)}, Order Total: ${formatCurrency(grandTotal)}`,
                        confirmButtonText: 'Continue Anyway',
                        showCancelButton: true
                    });
                }
            }
        },
        error: function(error) {
            console.error('Error loading customer info:', error);
        }
    });
}

function formatCurrency(amount) {
    const currency = $('#currency').val();
    return currency + ' ' + amount.toFixed(2);
}

function loadQuoteItems(quoteItems) {
    // Clear existing items
    $('#itemsBody').empty();
    itemCount = 0;
    
    // Add quote items
    quoteItems.forEach(item => {
        addItemRow(item);
    });
    
    Swal.fire({
        icon: 'success',
        title: 'Items Loaded',
        text: `${quoteItems.length} items loaded from quotation.`,
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
                <small>SKU: ${product.sku || 'N/A'} | Price: ${formatCurrency(product.selling_price || 0)}</small>
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

function createSalesOrder(status = 'pending') {
    // Validate form
    if (!validateForm(status === 'draft')) {
        return;
    }
    
    const formData = new FormData($('#salesOrderForm')[0]);
    formData.append('status', status);
    
    // Get items data
    const items = [];
    $('[id^="item-row-"]').each(function() {
        const item = {
            product_id: $(this).find('.item-product-id').val(),
            product_name: $(this).find('.item-name').val(),
            sku: $(this).find('.item-sku').val(),
            quantity: $(this).find('.item-quantity').val(),
            unit: $(this).find('.item-unit').val(),
            unit_price: $(this).find('.item-price').val(),
            tax_rate: $(this).find('.item-tax').val(),
            discount_percent: $(this).find('.item-discount').val()
        };
        
        if (item.product_name && item.quantity) {
            items.push(item);
        }
    });
    
    // Add items to form data
    formData.append('items', JSON.stringify(items));
    
    // Show loading state
    const submitBtn = $('button[type="submit"]');
    const originalText = submitBtn.html();
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.ajax({
        url: '<?= getUrl('/api/account/save_sales_order.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: response.message
                }).then(() => {
                    window.location.href = '<?= getUrl('sales_orders.php') ?>';
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
    createSalesOrder('draft');
}

function saveAsQuote() {
    if (!validateForm()) {
        return;
    }
    createSalesOrder('draft'); // Quotes are saved as draft
}

function createAndApprove() {
    if (!validateForm()) {
        return;
    }
    createSalesOrder('approved');
}

function validateForm(isDraft = false) {
    // Check if at least one item is added
    if ($('[id^="item-row-"]').length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Items',
            text: 'Please add at least one item to the order.'
        });
        return false;
    }
    
    // Check if all items have valid data
    let hasValidItems = false;
    $('[id^="item-row-"]').each(function() {
        const productName = $(this).find('.item-name').val();
        const quantity = $(this).find('.item-quantity').val();
        const price = $(this).find('.item-price').val();
        
        if (productName && quantity > 0 && price >= 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Items',
            text: 'Please ensure all items have a name, quantity, and price.'
        });
        return false;
    }
    
    // Check required fields
    const requiredFields = ['order_number', 'order_date', 'customer_id'];
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
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    background-color: white !important;
    border: 1px solid #dee2e6 !important;
}

.card-header.bg-light {
    background-color: #f8f9fa !important;
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

.currency-symbol {
    font-weight: bold;
    color: #495057;
}

/* Customer info card */
#customerInfoCard .card-body {
    font-size: 0.9rem;
}

/* Order summary */
#grand-total {
    font-size: 1.5rem;
    color: #198754;
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
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>