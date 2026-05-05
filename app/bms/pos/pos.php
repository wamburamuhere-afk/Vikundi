<?php
// File: pos.php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for POS permissions
$can_use_pos = in_array($user_role, ['Admin', 'Manager', 'Sales', 'Cashier']);
if (!$can_use_pos) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get current user info
$user_id = $_SESSION['user_id'];
$shift_id = isset($_SESSION['shift_id']) ? $_SESSION['shift_id'] : null;

// Check if shift is active
$shift_active = false;
if ($shift_id) {
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$shift_id, $user_id]);
    $shift_active = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get active shift data or start new shift
if (!$shift_active && $user_role != 'Admin') {
    // Check if there's an active shift for this user
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE user_id = ? AND status = 'active' ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $shift_active = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($shift_active) {
        $_SESSION['shift_id'] = $shift_active['shift_id'];
    } else {
        // Redirect to cash register to start shift
        header("Location: cash_register.php?action=start_shift&redirect=pos");
        exit();
    }
}

// Get cash register balance if shift active
$cash_balance = 0;
$starting_cash = 0;
if ($shift_active) {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) as cash_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) as cash_refunds
        FROM cash_register_transactions 
        WHERE shift_id = ?
    ");
    $stmt->execute([$shift_active['shift_id']]);
    $cash_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $starting_cash = $shift_active['starting_cash'];
    $cash_balance = $starting_cash + 
                   $cash_data['cash_in'] - 
                   $cash_data['cash_out'] + 
                   $cash_data['cash_sales'] - 
                   $cash_data['cash_refunds'];
}

// Get tax rates
$tax_rates = $pdo->query("SELECT * FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods
$payment_methods = [
    'cash' => 'Cash',
    'card' => 'Credit/Debit Card',
    'mobile_money' => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'credit' => 'Customer Credit'
];

// Get currency
$currency = 'TZS';

// Helper functions removed, now in helpers.php
?>
?>

<div class="container-fluid px-0" id="pos-container">
    <!-- POS Header -->
    <div class="bg-primary text-white py-2 px-3 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0"><i class="bi bi-cash-register"></i> Point of Sale</h4>
            <small class="opacity-75">
                <?php if ($shift_active): ?>
                Shift: <?= $shift_active['shift_code'] ?> | 
                Started: <?= date('H:i', strtotime($shift_active['start_time'])) ?> | 
                Cashier: <?= htmlspecialchars($_SESSION['username']) ?>
                <?php else: ?>
                No active shift
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="text-center">
                <div class="fs-6">Cash Balance</div>
                <div class="fs-4 fw-bold"><?= format_currency($cash_balance) ?></div>
                <small>Starting: <?= format_currency($starting_cash) ?></small>
            </div>
            <div class="vr text-white opacity-50"></div>
            <div>
                <button class="btn btn-light btn-sm me-2" onclick="openCashDrawer()">
                    <i class="bi bi-cash"></i> Open Drawer
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="endShift()">
                    <i class="bi bi-power"></i> End Shift
                </button>
            </div>
        </div>
    </div>

    <!-- Main POS Layout -->
    <div class="row g-0" style="height: calc(100vh - 80px);">
        <!-- Left Column: Product Selection -->
        <div class="col-md-8 d-flex flex-column" style="border-right: 1px solid #dee2e6;">
            <!-- Product Search & Categories -->
            <div class="bg-light p-3 border-bottom">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="text" class="form-control" id="productSearch" 
                                   placeholder="Search product by name, SKU or barcode" autofocus>
                            <button class="btn btn-outline-secondary" type="button" onclick="searchProducts()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="d-flex gap-2" id="categoryButtons">
                            <!-- Categories will be loaded here -->
                            <button type="button" class="btn btn-outline-primary active" onclick="loadProductsByCategory('all')">
                                All Products
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="flex-grow-1 p-3" style="overflow-y: auto;">
                <div class="row g-3" id="productGrid">
                    <!-- Products will be loaded here -->
                </div>
                <div id="loadingProducts" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading products...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading products...</p>
                </div>
            </div>
        </div>

        <!-- Right Column: Cart & Checkout -->
        <div class="col-md-4 d-flex flex-column bg-light">
            <!-- Current Sale -->
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Current Sale</h5>
                    <div>
                        <button class="btn btn-sm btn-outline-danger me-2" onclick="clearCart()">
                            <i class="bi bi-trash"></i> Clear
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="holdSale()">
                            <i class="bi bi-pause"></i> Hold
                        </button>
                    </div>
                </div>
                <div class="d-flex justify-content-between">
                    <div>
                        <span class="text-muted">Receipt #:</span>
                        <strong id="receiptNumber"><?= generate_receipt_number() ?></strong>
                    </div>
                    <div>
                        <span class="text-muted">Items:</span>
                        <strong id="cartItemCount">0</strong>
                    </div>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="flex-grow-1 p-3" style="overflow-y: auto;">
                <table class="table table-sm" id="cartTable">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="45%">Product</th>
                            <th width="15%">Qty</th>
                            <th width="20%">Price</th>
                            <th width="15%">Total</th>
                        </tr>
                    </thead>
                    <tbody id="cartBody">
                        <!-- Cart items will be added here -->
                    </tbody>
                </table>
                <div id="emptyCart" class="text-center py-5">
                    <i class="bi bi-cart-x" style="font-size: 3rem; color: #6c757d;"></i>
                    <p class="text-muted mt-2">Cart is empty</p>
                    <p class="text-muted small">Search or browse products to add items</p>
                </div>
            </div>

            <!-- Cart Summary -->
            <div class="p-3 border-top bg-white">
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <span class="text-muted">Subtotal:</span>
                    </div>
                    <div class="col-6 text-end">
                        <span id="cartSubtotal">0.00</span>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <span class="text-muted">Tax:</span>
                    </div>
                    <div class="col-6 text-end">
                        <span id="cartTax">0.00</span>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Discount</span>
                            <input type="number" class="form-control" id="cartDiscount" 
                                   value="0" min="0" step="0.01" onchange="calculateCartTotal()">
                            <select class="form-select" id="discountType" onchange="calculateCartTotal()">
                                <option value="amount">TZS</option>
                                <option value="percent">%</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-6 text-end">
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="cartShipping" 
                                   value="0" min="0" step="0.01" onchange="calculateCartTotal()">
                            <span class="input-group-text">Shipping</span>
                        </div>
                    </div>
                </div>
                <div class="row g-2 border-top pt-2">
                    <div class="col-6">
                        <h5 class="mb-0">Total:</h5>
                    </div>
                    <div class="col-6 text-end">
                        <h4 class="mb-0 text-success" id="cartTotal">0.00</h4>
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="p-3 border-top bg-white">
                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <select class="form-select" id="customerSelect">
                        <option value="">Walk-in Customer</option>
                        <?php
                        $customers = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($customers as $customer) {
                            echo "<option value='{$customer['customer_id']}'>{$customer['customer_name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <div class="btn-group w-100" role="group" id="paymentMethodGroup">
                        <?php foreach ($payment_methods as $value => $label): ?>
                            <input type="radio" class="btn-check" name="paymentMethod" 
                                   id="payment<?= ucfirst($value) ?>" value="<?= $value ?>" 
                                   <?= $value == 'cash' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="payment<?= ucfirst($value) ?>">
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cash Payment Specific -->
                <div id="cashPaymentSection">
                    <div class="mb-3">
                        <label class="form-label">Amount Tendered</label>
                        <div class="input-group">
                            <span class="input-group-text">TZS</span>
                            <input type="number" class="form-control" id="amountTendered" 
                                   min="0" step="0.01" value="0" oninput="calculateChange()">
                        </div>
                    </div>
                    <div class="alert alert-info" id="changeAlert" style="display: none;">
                        <div class="d-flex justify-content-between">
                            <span>Change Due:</span>
                            <strong id="changeAmount">0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- Credit Payment Specific -->
                <div id="creditPaymentSection" style="display: none;">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Customer credit will be charged for this sale.
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2">
                    <button class="btn btn-success btn-lg" onclick="processPayment()" id="processPaymentBtn">
                        <i class="bi bi-check-circle"></i> PROCESS PAYMENT
                    </button>
                    <button class="btn btn-outline-secondary" onclick="saveAsInvoice()">
                        <i class="bi bi-receipt"></i> Save as Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Product Quick View Modal -->
<div class="modal fade" id="productQuickView" tabindex="-1" aria-labelledby="productQuickViewLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="productQuickViewLabel">Add to Cart</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="quickViewContent">
                    <!-- Product details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Held Sales Modal -->
<div class="modal fade" id="heldSalesModal" tabindex="-1" aria-labelledby="heldSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="heldSalesModalLabel">
                    <i class="bi bi-pause"></i> Held Sales
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Hold #</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Held At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="heldSalesBody">
                        <!-- Held sales will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- End Shift Modal -->
<div class="modal fade" id="endShiftModal" tabindex="-1" aria-labelledby="endShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="endShiftModalLabel">
                    <i class="bi bi-power"></i> End Shift
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Ending Cash Count</label>
                    <input type="number" class="form-control" id="endingCash" 
                           min="0" step="0.01" value="<?= $cash_balance ?>">
                </div>
                <div class="alert alert-info">
                    <div class="d-flex justify-content-between">
                        <span>Starting Cash:</span>
                        <strong><?= format_currency($starting_cash) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Calculated Balance:</span>
                        <strong><?= format_currency($cash_balance) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Difference:</span>
                        <strong id="cashDifference">0.00</strong>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes (optional)</label>
                    <textarea class="form-control" id="shiftNotes" rows="2" 
                              placeholder="Any notes about the shift..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="confirmEndShift()">End Shift</button>
            </div>
        </div>
    </div>
</div>

<script>
let cart = [];
let currentProduct = null;
let categories = [];
let currentReceiptNumber = '<?= generate_receipt_number() ?>';

$(document).ready(function() {
    // Load initial data
    loadCategories();
    loadProducts();
    
    // Setup event listeners
    $('#productSearch').on('keyup', function(e) {
        if (e.key === 'Enter') {
            searchProducts();
        }
    });
    
    // Payment method change
    $('input[name="paymentMethod"]').change(function() {
        const method = $(this).val();
        $('#cashPaymentSection').toggle(method === 'cash');
        $('#creditPaymentSection').toggle(method === 'credit');
        if (method === 'cash') {
            calculateChange();
        }
    });
    
    // Calculate difference when ending cash changes
    $('#endingCash').on('input', function() {
        const ending = parseFloat($(this).val()) || 0;
        const calculated = <?= $cash_balance ?>;
        const difference = ending - calculated;
        $('#cashDifference').text(difference.toFixed(2));
        $('#cashDifference').removeClass('text-success text-danger');
        if (difference > 0) {
            $('#cashDifference').addClass('text-success');
        } else if (difference < 0) {
            $('#cashDifference').addClass('text-danger');
        }
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // F1 - Focus search
        if (e.key === 'F1') {
            e.preventDefault();
            $('#productSearch').focus();
        }
        // F2 - Clear cart
        else if (e.key === 'F2') {
            e.preventDefault();
            clearCart();
        }
        // F3 - Process payment
        else if (e.key === 'F3') {
            e.preventDefault();
            processPayment();
        }
        // F4 - Open cash drawer
        else if (e.key === 'F4') {
            e.preventDefault();
            openCashDrawer();
        }
        // F5 - Refresh
        else if (e.key === 'F5') {
            e.preventDefault();
            location.reload();
        }
        // F9 - Held sales
        else if (e.key === 'F9') {
            e.preventDefault();
            showHeldSales();
        }
        // Esc - Clear search
        else if (e.key === 'Escape') {
            e.preventDefault();
            $('#productSearch').val('');
            loadProducts();
        }
    });
});

function loadCategories() {
    $.ajax({
        url: 'api/get_categories.php',
        type: 'GET',
        data: { type: 'product' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                categories = response.data;
                const container = $('#categoryButtons');
                container.empty();
                
                // Add "All Products" button
                container.append(`
                    <button type="button" class="btn btn-outline-primary active" onclick="loadProductsByCategory('all')">
                        All Products
                    </button>
                `);
                
                // Add category buttons
                categories.slice(0, 10).forEach(category => {
                    container.append(`
                        <button type="button" class="btn btn-outline-secondary" onclick="loadProductsByCategory(${category.category_id})">
                            ${category.category_name}
                        </button>
                    `);
                });
            }
        }
    });
}

function loadProducts(categoryId = 'all', searchTerm = '') {
    $('#loadingProducts').show();
    $('#productGrid').empty();
    
    $.ajax({
        url: 'api/get_products.php',
        type: 'GET',
        data: {
            category_id: categoryId !== 'all' ? categoryId : '',
            search: searchTerm,
            in_stock: true,
            limit: 100
        },
        dataType: 'json',
        success: function(response) {
            $('#loadingProducts').hide();
            if (response.success && response.data.length > 0) {
                const grid = $('#productGrid');
                grid.empty();
                
                response.data.forEach(product => {
                    const card = `
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="card product-card h-100" onclick="showProductQuickView(${product.product_id})">
                                <div class="card-body text-center p-2">
                                    ${product.image_url ? 
                                        `<img src="${product.image_url}" class="img-fluid mb-2" style="height: 100px; object-fit: cover;">` : 
                                        `<div class="mb-2" style="height: 100px; background: #f8f9fa; display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-image text-muted" style="font-size: 2rem;"></i>
                                        </div>`
                                    }
                                    <h6 class="card-title mb-1">${product.product_name}</h6>
                                    <p class="card-text text-muted small mb-1">${product.sku || 'No SKU'}</p>
                                    <p class="card-text fw-bold text-success mb-1"><?= $currency ?> ${parseFloat(product.selling_price).toFixed(2)}</p>
                                    <p class="card-text small ${product.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
                                        Stock: ${product.stock_quantity} ${product.unit || 'pcs'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    `;
                    grid.append(card);
                });
            } else {
                $('#productGrid').html(`
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-search" style="font-size: 3rem; color: #6c757d;"></i>
                        <h5 class="mt-3 text-muted">No products found</h5>
                        <p class="text-muted">Try a different search or category</p>
                    </div>
                `);
            }
        },
        error: function() {
            $('#loadingProducts').hide();
            $('#productGrid').html(`
                <div class="col-12 text-center py-5">
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                    <h5 class="mt-3 text-danger">Error loading products</h5>
                    <p class="text-muted">Please try again</p>
                </div>
            `);
        }
    });
}

function loadProductsByCategory(categoryId) {
    // Update active button
    $('#categoryButtons button').removeClass('active btn-primary').addClass('btn-outline-secondary');
    $(`#categoryButtons button:contains(${categoryId === 'all' ? 'All Products' : getCategoryName(categoryId)})`)
        .removeClass('btn-outline-secondary').addClass('active btn-primary');
    
    loadProducts(categoryId);
}

function getCategoryName(categoryId) {
    const category = categories.find(c => c.category_id == categoryId);
    return category ? category.category_name : '';
}

function searchProducts() {
    const searchTerm = $('#productSearch').val().trim();
    loadProducts('all', searchTerm);
}

function showProductQuickView(productId) {
    $.ajax({
        url: 'api/get_product.php',
        type: 'GET',
        data: { id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                currentProduct = response.data;
                
                const html = `
                    <h6>${currentProduct.product_name}</h6>
                    <p class="text-muted small mb-2">${currentProduct.sku || 'No SKU'}</p>
                    <p class="text-success fw-bold"><?= $currency ?> ${parseFloat(currentProduct.selling_price).toFixed(2)}</p>
                    <p class="small ${currentProduct.stock_quantity <= 10 ? 'text-danger' : 'text-muted'}">
                        Stock: ${currentProduct.stock_quantity} ${currentProduct.unit || 'pcs'}
                    </p>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <div class="input-group">
                            <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(-1)">-</button>
                            <input type="number" class="form-control text-center" id="quickViewQty" 
                                   value="1" min="1" max="${currentProduct.stock_quantity}" step="1">
                            <button class="btn btn-outline-secondary" type="button" onclick="adjustQuantity(1)">+</button>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="addToCart()">
                            <i class="bi bi-cart-plus"></i> Add to Cart
                        </button>
                        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                    </div>
                `;
                
                $('#quickViewContent').html(html);
                $('#productQuickView').modal('show');
                $('#quickViewQty').focus().select();
            }
        }
    });
}

function adjustQuantity(amount) {
    const input = $('#quickViewQty');
    let current = parseInt(input.val()) || 1;
    const max = parseInt(input.attr('max')) || 9999;
    const newValue = Math.max(1, Math.min(max, current + amount));
    input.val(newValue);
}

function addToCart() {
    if (!currentProduct) return;
    
    const quantity = parseInt($('#quickViewQty').val()) || 1;
    
    // Check if product already in cart
    const existingItem = cart.find(item => item.product_id == currentProduct.product_id);
    
    if (existingItem) {
        // Update quantity
        const newQuantity = existingItem.quantity + quantity;
        if (newQuantity <= currentProduct.stock_quantity) {
            existingItem.quantity = newQuantity;
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'Stock Limit',
                text: `Only ${currentProduct.stock_quantity} units available in stock.`,
                timer: 2000
            });
            return;
        }
    } else {
        // Add new item
        cart.push({
            product_id: currentProduct.product_id,
            product_name: currentProduct.product_name,
            sku: currentProduct.sku,
            unit: currentProduct.unit,
            price: parseFloat(currentProduct.selling_price),
            quantity: quantity,
            tax_rate: currentProduct.tax_rate || 0
        });
    }
    
    updateCartDisplay();
    $('#productQuickView').modal('hide');
    
    // Play success sound
    playSound('success');
}

function updateCartDisplay() {
    const cartBody = $('#cartBody');
    const emptyCart = $('#emptyCart');
    const cartTable = $('#cartTable');
    
    if (cart.length === 0) {
        cartBody.empty();
        cartTable.hide();
        emptyCart.show();
        $('#cartItemCount').text('0');
    } else {
        emptyCart.hide();
        cartTable.show();
        cartBody.empty();
        
        cart.forEach((item, index) => {
            const taxAmount = item.price * item.quantity * (item.tax_rate / 100);
            const itemTotal = (item.price * item.quantity) + taxAmount;
            
            const row = `
                <tr>
                    <td>${index + 1}</td>
                    <td>
                        <strong>${item.product_name}</strong>
                        <br><small class="text-muted">${item.sku || 'No SKU'}</small>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <button class="btn btn-outline-secondary btn-sm" onclick="updateCartQuantity(${index}, -1)">
                                -
                            </button>
                            <input type="number" class="form-control text-center" 
                                   value="${item.quantity}" min="1" 
                                   onchange="updateCartQuantityInput(${index}, this.value)">
                            <button class="btn btn-outline-secondary btn-sm" onclick="updateCartQuantity(${index}, 1)">
                                +
                            </button>
                        </div>
                    </td>
                    <td><?= $currency ?> ${item.price.toFixed(2)}</td>
                    <td>
                        <?= $currency ?> ${itemTotal.toFixed(2)}
                        <button class="btn btn-sm btn-link text-danger ms-2" onclick="removeFromCart(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            cartBody.append(row);
        });
        
        $('#cartItemCount').text(cart.length);
    }
    
    calculateCartTotal();
}

function updateCartQuantity(index, change) {
    if (cart[index]) {
        const newQuantity = cart[index].quantity + change;
        if (newQuantity >= 1) {
            // Check stock availability
            $.ajax({
                url: 'api/check_stock.php',
                type: 'GET',
                data: { product_id: cart[index].product_id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (newQuantity <= response.data.stock_quantity) {
                            cart[index].quantity = newQuantity;
                            updateCartDisplay();
                        } else {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Stock Limit',
                                text: `Only ${response.data.stock_quantity} units available.`,
                                timer: 2000
                            });
                        }
                    }
                }
            });
        }
    }
}

function updateCartQuantityInput(index, value) {
    const quantity = parseInt(value) || 1;
    if (quantity >= 1 && cart[index]) {
        cart[index].quantity = quantity;
        updateCartDisplay();
    }
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
}

function clearCart() {
    if (cart.length === 0) return;
    
    Swal.fire({
        title: 'Clear Cart?',
        text: 'Are you sure you want to remove all items from the cart?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Clear',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            cart = [];
            updateCartDisplay();
            playSound('clear');
        }
    });
}

function holdSale() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Add items to cart before holding sale.',
            timer: 2000
        });
        return;
    }
    
    const customerId = $('#customerSelect').val();
    const customerName = $('#customerSelect option:selected').text();
    
    Swal.fire({
        title: 'Hold Sale',
        input: 'text',
        inputLabel: 'Hold Reference (optional)',
        inputPlaceholder: 'e.g., Customer name or phone',
        showCancelButton: true,
        confirmButtonText: 'Hold Sale',
        cancelButtonText: 'Cancel',
        inputValue: customerName !== 'Walk-in Customer' ? customerName : ''
    }).then((result) => {
        if (result.isConfirmed) {
            const holdData = {
                reference: result.value,
                customer_id: customerId || null,
                items: cart,
                subtotal: calculateSubtotal(),
                tax: calculateTax(),
                total: calculateTotal(),
                shift_id: <?= $shift_active ? $shift_active['shift_id'] : 'null' ?>,
                user_id: <?= $user_id ?>
            };
            
            $.ajax({
                url: 'api/hold_sale.php',
                type: 'POST',
                data: JSON.stringify(holdData),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Sale Held',
                            text: 'Sale has been held successfully.',
                            timer: 1500
                        });
                        cart = [];
                        updateCartDisplay();
                        generateNewReceipt();
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

function showHeldSales() {
    $.ajax({
        url: 'api/get_held_sales.php',
        type: 'GET',
        data: { shift_id: <?= $shift_active ? $shift_active['shift_id'] : 'null' ?> },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const tbody = $('#heldSalesBody');
                tbody.empty();
                
                if (response.data.length === 0) {
                    tbody.html(`
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                No held sales found
                            </td>
                        </tr>
                    `);
                } else {
                    response.data.forEach(sale => {
                        const row = `
                            <tr>
                                <td>${sale.hold_reference || 'HOLD-' + sale.hold_id}</td>
                                <td>${sale.customer_name || 'Walk-in'}</td>
                                <td>${sale.item_count}</td>
                                <td><?= $currency ?> ${parseFloat(sale.total_amount).toFixed(2)}</td>
                                <td>${new Date(sale.held_at).toLocaleTimeString()}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="loadHeldSale(${sale.hold_id})">
                                        <i class="bi bi-arrow-clockwise"></i> Load
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteHeldSale(${sale.hold_id})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                }
                
                $('#heldSalesModal').modal('show');
            }
        }
    });
}

function loadHeldSale(holdId) {
    $.ajax({
        url: 'api/load_held_sale.php',
        type: 'GET',
        data: { hold_id: holdId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                cart = response.data.items;
                updateCartDisplay();
                
                if (response.data.customer_id) {
                    $('#customerSelect').val(response.data.customer_id);
                }
                
                $('#heldSalesModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Sale Loaded',
                    text: 'Held sale has been loaded to cart.',
                    timer: 1500
                });
            }
        }
    });
}

function deleteHeldSale(holdId) {
    Swal.fire({
        title: 'Delete Held Sale?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_held_sale.php',
                type: 'POST',
                data: { hold_id: holdId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showHeldSales();
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted',
                            text: 'Held sale has been deleted.',
                            timer: 1500
                        });
                    }
                }
            });
        }
    });
}

function calculateSubtotal() {
    return cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
}

function calculateTax() {
    return cart.reduce((sum, item) => {
        return sum + (item.price * item.quantity * (item.tax_rate / 100));
    }, 0);
}

function calculateCartTotal() {
    const subtotal = calculateSubtotal();
    const tax = calculateTax();
    const discountInput = parseFloat($('#cartDiscount').val()) || 0;
    const discountType = $('#discountType').val();
    const shipping = parseFloat($('#cartShipping').val()) || 0;
    
    let discount = 0;
    if (discountType === 'percent') {
        discount = subtotal * (discountInput / 100);
    } else {
        discount = discountInput;
    }
    
    const total = subtotal + tax - discount + shipping;
    
    $('#cartSubtotal').text(subtotal.toFixed(2));
    $('#cartTax').text(tax.toFixed(2));
    $('#cartTotal').text(total.toFixed(2));
    
    return total;
}

function calculateChange() {
    const total = calculateTotal();
    const tendered = parseFloat($('#amountTendered').val()) || 0;
    const change = tendered - total;
    
    if (change > 0) {
        $('#changeAlert').show();
        $('#changeAmount').text(change.toFixed(2));
    } else {
        $('#changeAlert').hide();
    }
}

function calculateTotal() {
    return parseFloat($('#cartTotal').text()) || 0;
}

function processPayment() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Add items to cart before processing payment.',
            timer: 2000
        });
        return;
    }
    
    const paymentMethod = $('input[name="paymentMethod"]:checked').val();
    const customerId = $('#customerSelect').val();
    const total = calculateTotal();
    
    // Validate cash payment
    if (paymentMethod === 'cash') {
        const tendered = parseFloat($('#amountTendered').val()) || 0;
        if (tendered < total) {
            Swal.fire({
                icon: 'error',
                title: 'Insufficient Payment',
                text: 'Amount tendered is less than total amount.',
                timer: 2000
            });
            return;
        }
    }
    
    // Validate credit limit for credit sales
    if (paymentMethod === 'credit' && customerId) {
        // Check customer credit limit
        $.ajax({
            url: 'api/check_credit_limit.php',
            type: 'GET',
            data: { customer_id: customerId, amount: total },
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Credit Limit',
                        text: response.message,
                        showCancelButton: true,
                        confirmButtonText: 'Proceed Anyway',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            finalizePayment(paymentMethod, customerId, total);
                        }
                    });
                } else {
                    finalizePayment(paymentMethod, customerId, total);
                }
            }
        });
    } else {
        finalizePayment(paymentMethod, customerId, total);
    }
}

function finalizePayment(paymentMethod, customerId, total) {
    const paymentData = {
        receipt_number: currentReceiptNumber,
        customer_id: customerId || null,
        items: cart,
        subtotal: calculateSubtotal(),
        tax: calculateTax(),
        discount: parseFloat($('#cartDiscount').val()) || 0,
        discount_type: $('#discountType').val(),
        shipping: parseFloat($('#cartShipping').val()) || 0,
        total: total,
        payment_method: paymentMethod,
        amount_tendered: paymentMethod === 'cash' ? parseFloat($('#amountTendered').val()) || total : total,
        change_given: paymentMethod === 'cash' ? (parseFloat($('#amountTendered').val()) || total) - total : 0,
        shift_id: <?= $shift_active ? $shift_active['shift_id'] : 'null' ?>,
        user_id: <?= $user_id ?>
    };
    
    // Disable payment button
    $('#processPaymentBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.ajax({
        url: 'api/process_sale.php',
        type: 'POST',
        data: JSON.stringify(paymentData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Print receipt
                printReceipt(response.data.sale_id);
                
                // Reset cart
                cart = [];
                updateCartDisplay();
                generateNewReceipt();
                
                // Reset form
                $('#amountTendered').val('0');
                $('#cartDiscount').val('0');
                $('#cartShipping').val('0');
                $('#changeAlert').hide();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Successful',
                    text: 'Sale completed successfully.',
                    timer: 1500,
                    showConfirmButton: false
                });
                
                playSound('success');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Payment Failed',
                    text: response.message
                });
            }
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> PROCESS PAYMENT');
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred. Please try again.'
            });
            $('#processPaymentBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> PROCESS PAYMENT');
        }
    });
}

function printReceipt(saleId) {
    const printWindow = window.open(`receipt_print.php?id=${saleId}`, '_blank');
    if (printWindow) {
        printWindow.focus();
    }
}

function saveAsInvoice() {
    if (cart.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Cart',
            text: 'Add items to cart before saving as invoice.',
            timer: 2000
        });
        return;
    }
    
    const customerId = $('#customerSelect').val();
    if (!customerId || customerId === '') {
        Swal.fire({
            icon: 'warning',
            title: 'Customer Required',
            text: 'Select a customer to save as invoice.',
            timer: 2000
        });
        return;
    }
    
    const invoiceData = {
        customer_id: customerId,
        items: cart,
        subtotal: calculateSubtotal(),
        tax: calculateTax(),
        discount: parseFloat($('#cartDiscount').val()) || 0,
        shipping: parseFloat($('#cartShipping').val()) || 0,
        total: calculateTotal(),
        notes: 'Created from POS sale',
        user_id: <?= $user_id ?>
    };
    
    $.ajax({
        url: 'api/create_invoice_from_pos.php',
        type: 'POST',
        data: JSON.stringify(invoiceData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Invoice Created',
                    text: `Invoice #${response.data.invoice_number} has been created.`,
                    timer: 2000
                }).then(() => {
                    window.open(`invoice_view.php?id=${response.data.invoice_id}`, '_blank');
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

function openCashDrawer() {
    $.ajax({
        url: 'api/open_cash_drawer.php',
        type: 'POST',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Cash Drawer',
                    text: 'Cash drawer opened successfully.',
                    timer: 1500
                });
                playSound('drawer');
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

function endShift() {
    $('#endShiftModal').modal('show');
}

function confirmEndShift() {
    const endingCash = parseFloat($('#endingCash').val()) || 0;
    const notes = $('#shiftNotes').val();
    
    $.ajax({
        url: 'api/end_shift.php',
        type: 'POST',
        data: {
            shift_id: <?= $shift_active ? $shift_active['shift_id'] : 'null' ?>,
            ending_cash: endingCash,
            notes: notes
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#endShiftModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Shift Ended',
                    text: 'Shift has been ended successfully.',
                    timer: 1500
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

function generateNewReceipt() {
    currentReceiptNumber = 'POS' + Date.now().toString().slice(-10) + Math.floor(Math.random() * 100);
    $('#receiptNumber').text(currentReceiptNumber);
}

function playSound(type) {
    // Simple sound feedback (could be enhanced with actual sound files)
    const audio = new Audio();
    if (type === 'success') {
        audio.src = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ';
    } else if (type === 'clear') {
        audio.src = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ';
    } else if (type === 'drawer') {
        audio.src = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEAQB8AAEAfAAABAAgAZGF0YQ';
    }
    audio.play().catch(e => console.log('Audio play failed:', e));
}

// Auto-refresh cash balance every 30 seconds
setInterval(function() {
    if ($shift_active) {
        $.ajax({
            url: 'api/get_cash_balance.php',
            type: 'GET',
            data: { shift_id: <?= $shift_active ? $shift_active['shift_id'] : 'null' ?> },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.cash-balance-display').text(format_currency(response.data.balance));
                }
            }
        });
    }
}, 30000);
</script>

<style>
#pos-container {
    height: 100vh;
    overflow: hidden;
}

.product-card {
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    border: 1px solid #dee2e6;
}

.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.product-card:active {
    transform: translateY(0);
}

#productGrid {
    min-height: 400px;
}

#cartTable {
    font-size: 0.9rem;
}

#cartTable tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

#emptyCart {
    color: #6c757d;
}

.btn-outline-primary.active {
    background-color: #0d6efd;
    color: white;
}

#paymentMethodGroup .btn {
    font-size: 0.85rem;
    padding: 0.25rem 0.5rem;
}

#cashDifference.positive {
    color: #198754;
}

#cashDifference.negative {
    color: #dc3545;
}

/* Scrollbar styling */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
}

::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .col-md-8, .col-md-4 {
        height: 50vh !important;
    }
    
    #categoryButtons {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: 10px;
    }
    
    #categoryButtons .btn {
        white-space: nowrap;
        flex-shrink: 0;
    }
}

@media print {
    #pos-container * {
        visibility: hidden;
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