<?php
// File: invoice_create.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('invoices');

// Get parameters
$customer_id = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$order_id = isset($_GET['order']) ? intval($_GET['order']) : 0;

// Get current user info
$user_id = $_SESSION['user_id'];

// Get customer details if provided
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'inactive'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get order details if provided
$order = null;
$order_items = [];
if ($order_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE order_id = ? AND status IN ('approved', 'processing', 'delivered', 'partially_delivered', 'completed')");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        $stmt = $pdo->prepare("
            SELECT 
                soi.*,
                p.product_name,
                p.sku,
                p.unit,
                soi.quantity - IFNULL(SUM(ii.quantity), 0) as available_quantity
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.product_id
            LEFT JOIN invoice_items ii ON soi.order_item_id = ii.order_item_id
            WHERE soi.order_id = ?
            GROUP BY soi.order_item_id
            HAVING available_quantity > 0
        ");
        $stmt->execute([$order_id]);
        $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$customer && $order['customer_id']) {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $stmt->execute([$order['customer_id']]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            $customer_id = $customer['customer_id'];
        }
    }
}

// Get customers for dropdown
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Get payment terms
$payment_terms = [
    'immediate' => 'Immediate Payment',
    '7_days' => '7 Days',
    '15_days' => '15 Days',
    '30_days' => '30 Days',
    '60_days' => '60 Days',
    '90_days' => '90 Days',
    'cod' => 'Cash on Delivery'
];

// Get currency options
$currencies = ['TZS' => 'Tanzanian Shilling', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'KES' => 'Kenyan Shilling'];

function generate_invoice_number() {
    return 'INV-' . date('Ymd') . '-' . mt_rand(100, 999);
}
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('invoices') ?>">Invoices</a></li>
            <li class="breadcrumb-item active">Create Invoice</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-receipt text-success"></i> Create Invoice</h2>
                    <p class="text-muted mb-0">Generate a new customer invoice</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('invoices') ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="card shadow-sm border-0">
        <div class="card-header custom-header text-white">
            <h5 class="mb-0"><i class="bi bi-file-text"></i> Invoice Details</h5>
        </div>
        <div class="card-body">
            <form id="invoiceForm">
                <!-- Basic Information -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice #</label>
                        <input type="text" class="form-control" name="invoice_number" value="<?= generate_invoice_number() ?>" required readonly>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Invoice Date</label>
                        <input type="date" class="form-control" id="invoice_date" name="invoice_date" value="<?= date('Y-m-d') ?>" required onchange="updateDueDate()">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small fw-bold">Due Date</label>
                        <input type="date" class="form-control" id="due_date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                    </div>
                </div>
                
                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Customer <span class="text-danger">*</span></label>
                        <select class="form-select select2" id="customer_id" name="customer_id" required onchange="loadCustomerInfo()">
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= $cust['customer_id'] ?>" <?= ($customer_id > 0 && $cust['customer_id'] == $customer_id) ? 'selected' : '' ?>>
                                    <?= safe_output($cust['customer_name']) ?> <?= !empty($cust['company_name']) ? '('.safe_output($cust['company_name']).')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label small fw-bold">Sales Order (Optional)</label>
                        <select class="form-select select2" id="order_id" name="order_id" onchange="loadOrderItems()">
                            <option value="">Select Sales Order</option>
                            <?php if ($order): ?>
                                <option value="<?= $order['order_id'] ?>" selected><?= safe_output($order['order_number']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Invoice Items -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold">Invoice Items</h6>
                        <button type="button" class="btn btn-sm btn-success" onclick="addItemRow()">
                            <i class="bi bi-plus-circle"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="itemsTable">
                                <thead class="bg-light small fw-bold text-muted">
                                    <tr>
                                        <th width="35%" class="ps-3">Product/Item</th>
                                        <th width="15%">Quantity</th>
                                        <th width="10%">Unit</th>
                                        <th width="15%">Unit Price</th>
                                        <th width="10%">Tax</th>
                                        <th width="10%" class="text-end">Total</th>
                                        <th width="5%" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <!-- Items added via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Section -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Additional information..."></textarea>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span id="subtotal" class="fw-bold">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tax:</span>
                                    <span id="tax-total" class="fw-bold">0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between mb-0 fs-5">
                                    <span class="fw-bold">Grand Total:</span>
                                    <span id="grand-total" class="fw-bold text-success">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-check-circle me-1"></i> Create Invoice
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Product Search Modal -->
    <div class="modal fade" id="productSearchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-search"></i> Search Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <input type="text" id="productSearch" class="form-control" placeholder="Type product name, SKU or barcode...">
                        <button class="btn btn-outline-success" onclick="searchProducts()"><i class="bi bi-search"></i></button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="productsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let itemCount = 0;
$(document).ready(function() {
    addItemRow();
    
    $('#invoiceForm').on('submit', function(e) {
        e.preventDefault();
        saveInvoice('pending');
    });
});

function addItemRow(item = null) {
    const idx = itemCount++;
    const html = `
        <tr id="item-row-${idx}">
            <td class="ps-3">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control item-name" name="items[${idx}][product_name]" 
                           value="${item ? item.product_name : ''}" placeholder="Item name" required>
                    <input type="hidden" class="item-product-id" name="items[${idx}][product_id]" value="${item ? item.product_id : ''}">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch(${idx})">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-qty" name="items[${idx}][quantity]" 
                       value="${item ? item.quantity : 1}" step="0.01" required onchange="calculateTotals()">
            </td>
            <td>
                <input type="text" class="form-control form-control-sm item-unit" name="items[${idx}][unit]" 
                       value="${item ? item.unit : 'pcs'}" readonly>
            </td>
            <td>
                <input type="number" class="form-control form-control-sm item-price" name="items[${idx}][unit_price]" 
                       value="${item ? item.unit_price : 0}" step="0.01" required onchange="calculateTotals()">
            </td>
            <td>
                <select class="form-select form-select-sm item-tax" name="items[${idx}][tax_rate]" onchange="calculateTotals()">
                    <option value="0" ${item && item.tax_rate == 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${item && item.tax_rate == 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td class="text-end fw-bold item-total">0.00</td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-link text-danger" onclick="removeItemRow(${idx})"><i class="bi bi-trash"></i></button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    calculateTotals();
}

function removeItemRow(idx) {
    if ($('#itemsBody tr').length > 1) {
        $(`#item-row-${idx}`).remove();
        calculateTotals();
    }
}

function calculateTotals() {
    let subtotal = 0;
    let taxTotal = 0;
    
    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.item-qty').val()) || 0;
        const price = parseFloat($(this).find('.item-price').val()) || 0;
        const taxRate = parseFloat($(this).find('.item-tax').val()) || 0;
        
        const lineTotal = qty * price;
        const lineTax = lineTotal * (taxRate / 100);
        
        subtotal += lineTotal;
        taxTotal += lineTax;
        
        $(this).find('.item-total').text(lineTotal.toFixed(2));
    });
    
    $('#subtotal').text(subtotal.toFixed(2));
    $('#tax-total').text(taxTotal.toFixed(2));
    $('#grand-total').text((subtotal + taxTotal).toFixed(2));
}

function saveInvoice(status) {
    const data = $('#invoiceForm').serialize() + '&status=' + status;
    $.post('<?= buildUrl('/api/account/save_invoice.php') ?>', data, function(res) {
        if (res.success) {
            Swal.fire('Success', res.message, 'success').then(() => {
                window.location.href = '<?= getUrl('invoices.php') ?>';
            });
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

let productsCache = [];
let currentItemIndex = null;

function loadProductsCache() {
    $.get('<?= getUrl('/api/account/get_products.php') ?>', { active_only: true }, function(res) {
        if (res.success) {
            productsCache = res.data;
        }
    }, 'json');
}

function openProductSearch(index) {
    currentItemIndex = index;
    if (productsCache.length === 0) {
        loadProductsCache();
    }
    $('#productSearchModal').modal('show');
    setTimeout(() => $('#productSearch').focus(), 500);
}

function searchProducts() {
    const term = $('#productSearch').val().toLowerCase();
    const tbody = $('#productsBody');
    tbody.empty();
    
    if (term.length < 2) {
        tbody.append('<tr><td colspan="5" class="text-center">Enter at least 2 characters</td></tr>');
        return;
    }
    
    const results = productsCache.filter(p => 
        (p.product_name && p.product_name.toLowerCase().includes(term)) || 
        (p.sku && p.sku.toLowerCase().includes(term)) || 
        (p.barcode && p.barcode.toLowerCase().includes(term))
    );
    
    if (results.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center">No products found</td></tr>');
    } else {
        results.slice(0, 10).forEach(p => {
            tbody.append(`
                <tr onclick="selectProduct(${p.product_id})" style="cursor:pointer">
                    <td>${p.product_name}</td>
                    <td>${p.sku || ''}</td>
                    <td>${p.current_stock || 0}</td>
                    <td>${p.selling_price || 0}</td>
                    <td><button class="btn btn-sm btn-primary">Select</button></td>
                </tr>
            `);
        });
    }
}

function selectProduct(id) {
    const p = productsCache.find(x => x.product_id == id);
    if (p) {
        const row = $(`#item-row-${currentItemIndex}`);
        row.find('.item-name').val(p.product_name);
        row.find('.item-product-id').val(p.product_id);
        row.find('.item-unit').val(p.unit || 'pcs');
        row.find('.item-price').val(p.selling_price || 0);
        row.find('.item-tax').val(parseInt(p.tax_rate) || 0);
        
        $('#productSearchModal').modal('hide');
        $('#productSearch').val('');
        $('#productsBody').empty();
        calculateTotals();
    }
}

$(document).ready(function() {
    loadProductsCache();
    
    $('#productSearch').on('keyup', function(e) {
        if (e.key === 'Enter') searchProducts();
        else searchProducts();
    });
});

function loadCustomerInfo() {
    const customerId = $('#customer_id').val();
    if (!customerId) {
        $('#order_id').html('<option value="">Select Sales Order</option>');
        return;
    }
    
    // Fetch sales orders for this customer
    $.get('<?= getUrl('/api/account/get_sales_orders.php') ?>', { customer: customerId, status: 'approved' }, function(res) {
        if (res.success) {
            let options = '<option value="">Select Sales Order</option>';
            res.data.forEach(order => {
                options += `<option value="${order.sales_order_id}">${order.order_number} (${order.order_date})</option>`;
            });
            $('#order_id').html(options);
        }
    }, 'json');
}

function loadOrderItems() {
    const orderId = $('#order_id').val();
    if (!orderId) return;
    
    // Fetch items for this order
    // Since we don't have get_sales_order_details, let's assume we might need to add it or use a separate API
    $.get('<?= getUrl('/api/account/get_sales_order_items.php') ?>', { order_id: orderId }, function(res) {
        if (res.success) {
            $('#itemsBody').empty();
            itemCount = 0;
            res.data.forEach(item => {
                addItemRow(item);
            });
        }
    }, 'json');
}

function updateDueDate() {
    const invoiceDate = $('#invoice_date').val();
    if (!invoiceDate) return;
    
    const date = new Date(invoiceDate);
    date.setDate(date.getDate() + 30); // Default 30 days
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    $('#due_date').val(`${year}-${month}-${day}`);
}
</script>

<style>
.custom-header { background-color: #0f5132 !important; border-radius: 0.75rem 0.75rem 0 0; }
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 1rem;
}
.table thead th { 
    background-color: #f8f9fa; 
    border-bottom: 2px solid #dee2e6; 
    padding: 1rem 0.5rem;
}
.card { border-radius: 0.75rem; }
</style>

<?php includeFooter(); ?>