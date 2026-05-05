<?php
// File: purchase_order_create.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('purchase_orders');

// Check if supplier is provided (optional deep link)
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;

// Dependencies from PDO
global $pdo;

// Get warehouse locations
$warehouses = $pdo->query("SELECT * FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for dropdown (initial load, though search is preferred for large lists)
$suppliers = $pdo->query("SELECT supplier_id, supplier_name, company_name, currency, payment_terms FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Get tax rates
$tax_rates = $pdo->query("SELECT * FROM tax_rates WHERE status = 'active' ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

// Get shipping methods
$shipping_methods = $pdo->query("SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY method_name")->fetchAll(PDO::FETCH_ASSOC);

$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling'
];
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs & Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchase_orders') ?>">Purchase Orders</a></li>
            <li class="breadcrumb-item active">New Order</li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-cart-plus text-primary"></i> Create Purchase Order</h2>
                    <p class="text-muted">Issue a new purchase request to a supplier</p>
                </div>
                <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>

    <!-- Main Creation Form -->
    <form id="purchaseOrderForm">
        <div class="row">
            <!-- Left Column: Order Details -->
            <div class="col-lg-12">
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i> Basic Information</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                                <select class="form-select select2-supplier" id="supplier_id" name="supplier_id" required>
                                    <option value="">Select a supplier</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= $s['supplier_id'] ?>" 
                                            <?= $supplier_id == $s['supplier_id'] ? 'selected' : '' ?>
                                            data-currency="<?= $s['currency'] ?>"
                                            data-terms="<?= $s['payment_terms'] ?>">
                                            <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['company_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Expected Delivery</label>
                                <input type="date" class="form-control" name="expected_delivery_date">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Warehouse / Delivery Point <span class="text-danger">*</span></label>
                                <select class="form-select" name="warehouse_id" required>
                                    <option value="">Select location</option>
                                    <?php foreach ($warehouses as $w): ?>
                                        <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Currency <span class="text-danger">*</span></label>
                                <select class="form-select" id="currency" name="currency" required>
                                    <?php foreach ($currencies as $code => $name): ?>
                                        <option value="<?= $code ?>"><?= $code ?> - <?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Payment Terms</label>
                                <select class="form-select" id="payment_terms" name="payment_terms">
                                    <option value="immediate">Immediate</option>
                                    <option value="net_15">Net 15 Days</option>
                                    <option value="net_30" selected>Net 30 Days</option>
                                    <option value="net_60">Net 60 Days</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items Section -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light border-bottom py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i> Order Items</h5>
                            <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="itemsTable">
                                <thead class="bg-light text-uppercase small fw-bold">
                                    <tr>
                                        <th style="min-width: 300px;">Product / Service</th>
                                        <th style="width: 150px;">Quantity</th>
                                        <th style="width: 200px;">Unit Price</th>
                                        <th style="width: 200px;">Tax Rate</th>
                                        <th class="text-end" style="width: 150px;">Total</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <!-- Dynamic rows added here -->
                                </tbody>
                                <tfoot>
                                    <tr class="bg-light">
                                        <td colspan="4" class="text-end fw-bold">Subtotal:</td>
                                        <td class="text-end fw-bold pt-3" id="subtotal_display">TSh 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Shipping & Notes Section -->
                <div class="row">
                    <div class="col-md-7">
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-light py-3">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2"></i> Notes & Terms</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Internal Notes</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Notes for internal team..."></textarea>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label fw-semibold">Terms & Conditions</label>
                                    <textarea class="form-control" name="terms_conditions" rows="3" placeholder="Standard PO terms..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="card shadow-sm border-primary">
                            <div class="card-header bg-primary text-white py-3">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2"></i> Order Summary</h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between mb-3 text-muted">
                                    <span>Items Subtotal</span>
                                    <span id="summary-subtotal">0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-3 text-muted">
                                    <span>Total Tax</span>
                                    <span id="summary-tax">0.00</span>
                                </div>
                                <div class="row mb-3 align-items-center">
                                    <div class="col-6 text-muted">Shipping Cost</div>
                                    <div class="col-6">
                                        <input type="number" step="0.01" class="form-control form-control-sm text-end" 
                                               id="shipping_cost" name="shipping_cost" value="0.00" oninput="calculateGrandTotal()">
                                    </div>
                                </div>
                                <hr class="my-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0 fw-bold text-dark">Total Value</h4>
                                    <h4 class="mb-0 fw-bold text-primary" id="summary-grand-total">TSh 0.00</h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-success btn-lg shadow-sm">
                                <i class="bi bi-check2-all me-2"></i> Create Purchase Order
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="window.saveDraft()">
                                <i class="bi bi-save me-2"></i> Save as Draft
                            </button>
                            <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-link text-decoration-none text-muted">
                                Cancel and return
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Scripts Section -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let productsList = [];

$(document).ready(function() {
    // Initial load
    fetchProducts();
    addItemRow();

    // Auto-update currency when supplier changes
    $('#supplier_id').on('change', function() {
        const option = $(this).find('option:selected');
        if (option.val()) {
            $('#currency').val(option.data('currency') || 'TZS');
            $('#payment_terms').val(option.data('terms') || 'net_30');
            updateCurSymbols();
        }
    });

    $('#currency').on('change', updateCurSymbols);

    // Form submission
    $('#purchaseOrderForm').on('submit', function(e) {
        e.preventDefault();
        saveOrder('pending');
    });
});

async function fetchProducts() {
    try {
        const response = await fetch('<?= buildUrl('api/account/get_products.php') ?>?limit=1000');
        const result = await response.json();
        if (result.success) {
            productsList = result.data;
        }
    } catch (error) {
        console.error('Failed to fetch products:', error);
    }
}

function updateCurSymbols() {
    const sym = $('#currency').val();
    $('.cur-symbol').text(sym + ' ');
    calculateGrandTotal();
}

function addItemRow() {
    const rowId = 'row_' + Date.now();
    const html = `
        <tr id="${rowId}">
            <td>
                <select class="form-select product-selector" name="productId" required onchange="onProductSelect('${rowId}')">
                    <option value="">Search/Select product...</option>
                    ${productsList.map(p => `<option value="${p.product_id}" data-price="${p.cost_price || p.purchase_price || 0}">${p.product_name} (${p.sku || 'No SKU'})</option>`).join('')}
                </select>
            </td>
            <td>
                <input type="number" class="form-control qty-input" name="qty" value="1" min="1" step="0.001" oninput="calculateRowTotal('${rowId}')" required>
            </td>
            <td>
                <div class="input-group">
                    <span class="input-group-text bg-light cur-symbol">TSh </span>
                    <input type="number" class="form-control price-input" name="price" value="0.00" min="0" step="0.01" oninput="calculateRowTotal('${rowId}')" required>
                </div>
            </td>
            <td>
                <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('${rowId}')">
                    <option value="0" data-rate="0">No Tax (0%)</option>
                    <?php foreach ($tax_rates as $tr): ?>
                        <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>">
                            <?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="text-end fw-bold">
                <span class="row-total">0.00</span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="$('#${rowId}').remove(); calculateGrandTotal();">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    $('#itemsBody').append(html);
    
    // Initialize search on the new select if needed
    $('#' + rowId + ' .product-selector').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#' + rowId).closest('.table-responsive')
    });
}

function onProductSelect(rowId) {
    const row = $('#' + rowId);
    const selected = row.find('.product-selector option:selected');
    const price = selected.data('price') || 0;
    row.find('.price-input').val(parseFloat(price).toFixed(2));
    calculateRowTotal(rowId);
}

function calculateRowTotal(rowId) {
    const row = $('#' + rowId);
    const qty = parseFloat(row.find('.qty-input').val()) || 0;
    const price = parseFloat(row.find('.price-input').val()) || 0;
    const taxRate = parseFloat(row.find('.tax-selector option:selected').data('rate')) || 0;
    
    const subtotal = qty * price;
    const total = subtotal + (subtotal * (taxRate / 100));
    
    row.find('.row-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let subtotal = 0;
    let taxTotal = 0;
    
    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        const price = parseFloat($(this).find('.price-input').val()) || 0;
        const taxRate = parseFloat($(this).find('.tax-selector option:selected').data('rate')) || 0;
        
        const lineSubtotal = qty * price;
        const lineTax = lineSubtotal * (taxRate / 100);
        
        subtotal += lineSubtotal;
        taxTotal += lineTax;
    });
    
    const shipping = parseFloat($('#shipping_cost').val()) || 0;
    const grand = subtotal + taxTotal + shipping;
    const cur = $('#currency').val();
    
    $('#subtotal_display').text(cur + ' ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-subtotal').text(subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-tax').text(taxTotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-grand-total').text(cur + ' ' + grand.toLocaleString(undefined, {minimumFractionDigits: 2}));
}

window.saveDraft = function() {
    saveOrder('draft');
}

function saveOrder(status) {
    const form = $('#purchaseOrderForm');
    
    // Simple validation
    if (!form[0].checkValidity()) {
        form[0].reportValidity();
        return;
    }

    const items = [];
    $('#itemsBody tr').each(function() {
        items.push({
            product_id: $(this).find('.product-selector').val(),
            quantity: $(this).find('.qty-input').val(),
            unit_price: $(this).find('.price-input').val(),
            tax_rate_id: $(this).find('.tax-selector').val()
        });
    });

    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }

    const formData = new FormData(form[0]);
    formData.append('status', status);
    formData.append('items', JSON.stringify(items));

    Swal.fire({
        title: 'Saving Purchase Order...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: '<?= buildUrl('api/account/save_purchase_order.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: response.message,
                    timer: 2000
                }).then(() => {
                    window.location.href = '<?= getUrl('purchase_orders') ?>';
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Communication failed with server', 'error');
        }
    });
}
</script>

<style>
.select2-container--bootstrap-5 .select2-selection { border-radius: 0.375rem; }
.card { border-radius: 0.75rem; overflow: hidden; }
.form-label { font-size: 0.875rem; color: #4b5563; }
.table thead th { background-color: #f9fafb; letter-spacing: 0.025em; color: #6b7280; border-top: 0; }
.breadcrumb-item a { color: #6b7280; text-decoration: none; }
.breadcrumb-item.active { color: #111827; font-weight: 500; }

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
includeFooter(); 
ob_end_flush();
?>
