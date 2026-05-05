

<script>
$(document).ready(function() {
    // Form submission
    $('#productForm').on('submit', function(e) {
        e.preventDefault();
        createProduct('active');
    });
    
    // Calculate initial markup
    calculateMarkup();
    
    // Update unit in initial stock section when unit changes
    $('#unit').change(function() {
        $('[name^="initial_stock"]').next('.input-group-text').text($(this).val());
    });
    
    // Auto-focus on product name
    $('#product_name').focus();
});

function generateNewSKU() {
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 900) + 100;
    $('#sku').val('PROD' + timestamp + random);
}

function generateNewBarcode() {
    $('#barcodeScannerModal').modal('show');
}

function startBarcodeScanner() {
    // This is a placeholder for actual barcode scanner integration
    // In a real implementation, you would use a barcode scanner library
    
    $('#scannerContainer').html(`
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading scanner...</span>
            </div>
            <p class="mt-2">Simulating barcode scan...</p>
        </div>
    `);
    
    // Simulate barcode scanning after 2 seconds
    setTimeout(() => {
        // Generate a random barcode
        const country = '00';
        const company = Math.floor(Math.random() * 90000) + 10000;
        const product = Math.floor(Math.random() * 90000) + 10000;
        let barcode = country + company + product;
        
        // Calculate check digit
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            const digit = parseInt(barcode[i]);
            sum += (i % 2 === 0) ? digit : digit * 3;
        }
        const checkDigit = (10 - (sum % 10)) % 10;
        
        $('#barcode').val(barcode + checkDigit);
        $('#barcodeScannerModal').modal('hide');
        
        Swal.fire({
            icon: 'success',
            title: 'Barcode Generated',
            text: 'New barcode has been generated.',
            timer: 1500,
            showConfirmButton: false
        });
    }, 2000);
}

function useManualBarcode() {
    const manualBarcode = $('#manualBarcodeInput').val().trim();
    if (manualBarcode) {
        $('#barcode').val(manualBarcode);
        $('#barcodeScannerModal').modal('hide');
        $('#manualBarcodeInput').val('');
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Empty Barcode',
            text: 'Please enter a barcode.',
            timer: 1500
        });
    }
}

function previewImage(event) {
    const input = event.target;
    const preview = document.getElementById('imagePreview');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: 'Maximum file size is 2MB.'
            });
            input.value = '';
            return;
        }
        
        // Validate file type
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                text: 'Only JPG, PNG, GIF, and WebP images are allowed.'
            });
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <img src="${e.target.result}" class="img-fluid" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeImage()">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    }
}

function removeImage() {
    $('#product_image').val('');
    $('#imagePreview').html(`
        <div class="text-center">
            <i class="bi bi-image" style="font-size: 3rem; color: #6c757d;"></i>
            <p class="text-muted mt-2">No image selected</p>
        </div>
    `);
}

function calculateMarkup() {
    const cost = parseFloat($('#cost_price').val()) || 0;
    const selling = parseFloat($('#selling_price').val()) || 0;
    
    let markupPercentage = 0;
    let profit = 0;
    
    if (cost > 0) {
        markupPercentage = ((selling - cost) / cost) * 100;
        profit = selling - cost;
    }
    
    $('#markup_percentage').val(markupPercentage.toFixed(2));
    $('#profit_margin').val(profit.toFixed(2));
    
    // Color code based on markup
    const markupElement = $('#markup_percentage');
    markupElement.removeClass('text-success text-warning text-danger');
    
    if (markupPercentage >= 50) {
        markupElement.addClass('text-success');
    } else if (markupPercentage >= 20) {
        markupElement.addClass('text-warning');
    } else if (markupPercentage > 0) {
        markupElement.addClass('text-danger');
    }
}

function showQuickCategoryModal() {
    $('#quickCategoryModal').modal('show');
}

function saveQuickCategory() {
    const name = $('#quickCategoryName').val().trim();
    const parentId = $('#quickCategoryParent').val();
    
    if (!name) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            text: 'Please enter a category name.'
        });
        return;
    }
    
    $.ajax({
        url: '<?= getUrl('/api/create_category.php') ?>',
        type: 'POST',
        data: {
            category_name: name,
            parent_id: parentId,
            type: 'product'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Add new option to category dropdown
                const option = new Option(name, response.category_id);
                $('#category_id').append(option);
                $('#category_id').val(response.category_id);
                
                $('#quickCategoryModal').modal('hide');
                $('#quickCategoryName').val('');
                
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Category added successfully.',
                    timer: 1500,
                    showConfirmButton: false
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

function createProduct(status = 'active') {
    // Validate form
    if (!validateForm()) {
        return;
    }
    
    // Show loading state
    const submitBtn = $('button[type="submit"]');
    const originalText = submitBtn.html();
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    // Create FormData object to handle file upload
    const formData = new FormData($('#productForm')[0]);
    formData.append('status', status);
    
    // Add initial stock data
    const initialStock = {};
    $('[name^="initial_stock"]').each(function() {
        const warehouseId = $(this).attr('name').match(/\[(\d+)\]/)[1];
        const quantity = parseFloat($(this).val()) || 0;
        if (quantity > 0) {
            initialStock[warehouseId] = quantity;
        }
    });
    
    if (Object.keys(initialStock).length > 0) {
        formData.append('initial_stock_data', JSON.stringify(initialStock));
    }
    
    $.ajax({
        url: '<?= getUrl('/api/create_product.php') ?>',
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
                    window.location.href = 'products';
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
    createProduct('inactive');
}

function saveAndAddAnother() {
    if (!validateForm()) {
        return;
    }
    
    const formData = new FormData($('#productForm')[0]);
    formData.append('status', 'active');
    
    $.ajax({
        url: '<?= getUrl('/api/create_product.php') ?>',
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
                    text: 'Product created successfully. Adding another...',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    // Reset form
                    $('#productForm')[0].reset();
                    $('#sku').val(generateNewSKU());
                    $('#barcode').val(generateNewBarcode());
                    removeImage();
                    calculateMarkup();
                    $('#product_name').focus();
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

function validateForm(isDraft = false) {
    // Check required fields
    const requiredFields = ['product_name', 'cost_price', 'selling_price', 'unit'];
    
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
    
    // Validate prices
    const cost = parseFloat($('#cost_price').val()) || 0;
    const selling = parseFloat($('#selling_price').val()) || 0;
    const minSelling = parseFloat($('#min_selling_price').val()) || 0;
    
    if (cost < 0 || selling < 0 || minSelling < 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Price',
            text: 'Prices cannot be negative.'
        });
        $('#cost_price').focus();
        return false;
    }
    
    if (selling < cost && !isDraft) {
        Swal.fire({
            icon: 'warning',
            title: 'Price Warning',
            text: 'Selling price is lower than cost price. Are you sure?',
            showCancelButton: true,
            confirmButtonText: 'Yes, Continue',
            cancelButtonText: 'No, Adjust'
        }).then((result) => {
            if (!result.isConfirmed) {
                $('#selling_price').focus().select();
                return false;
            }
        });
    }
    
    if (minSelling > 0 && selling < minSelling) {
        Swal.fire({
            icon: 'warning',
            title: 'Price Warning',
            text: 'Selling price is lower than minimum selling price.'
        });
        $('#selling_price').focus();
        return false;
    }
    
    // Validate stock levels
    const reorder = parseFloat($('#reorder_level').val()) || 0;
    const minStock = parseFloat($('#min_stock_level').val()) || 0;
    const maxStock = parseFloat($('#max_stock_level').val()) || 0;
    
    if (maxStock > 0 && minStock > maxStock) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock Level Error',
            text: 'Minimum stock level cannot be greater than maximum stock level.'
        });
        $('#min_stock_level').focus();
        return false;
    }
    
    if (reorder > 0 && maxStock > 0 && reorder > maxStock) {
        Swal.fire({
            icon: 'warning',
            title: 'Stock Level Error',
            text: 'Reorder level cannot be greater than maximum stock level.'
        });
        $('#reorder_level').focus();
        return false;
    }
    
    return true;
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.key === 's' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        createProduct('active');
    }
    
    // Ctrl + D to save as draft
    if (e.ctrlKey && e.key === 'd' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        saveAsDraft();
    }
    
    // Ctrl + A to save and add another
    if (e.ctrlKey && e.key === 'a' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        saveAndAddAnother();
    }
    
    // F1 for help
    if (e.key === 'F1') {
        e.preventDefault();
        Swal.fire({
            title: 'Keyboard Shortcuts',
            html: `
                <div class="text-start">
                    <p><strong>Ctrl + S:</strong> Save Product</p>
                    <p><strong>Ctrl + D:</strong> Save as Draft</p>
                    <p><strong>Ctrl + A:</strong> Save & Add Another</p>
                    <p><strong>F1:</strong> Show this help</p>
                    <p><strong>Esc:</strong> Cancel/Go Back</p>
                </div>
            `,
            icon: 'info'
        });
    }
    
    // Esc to go back
    if (e.key === 'Escape') {
        e.preventDefault();
        window.history.back();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    background-color: white !important;
    border: 1px solid #dee2e6 !important;
}

.card-header.bg-light {
    background-color: #f8f9fa !important;
    border-bottom: 1px solid #dee2e6 !important;
}

.form-label {
    font-weight: 500;
    font-size: 0.9rem;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

#imagePreview {
    background: linear-gradient(45deg, #f8f9fa 25%, #e9ecef 25%, #e9ecef 50%, #f8f9fa 50%, #f8f9fa 75%, #e9ecef 75%, #e9ecef);
    background-size: 20px 20px;
}

.input-group-text {
    background-color: #f8f9fa;
    font-size: 0.9rem;
}

/* Price validation colors */
.text-success { color: #198754 !important; }
.text-warning { color: #ffc107 !important; }
.text-danger { color: #dc3545 !important; }

/* Responsive adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding: 0.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 0.5rem;
    }
}

/* Animation for price changes */
@keyframes priceUpdate {
    0% { background-color: #fff3cd; }
    100% { background-color: transparent; }
}

.price-updated {
    animation: priceUpdate 1s;
}

/* Custom switch styling */
.form-switch .form-check-input:checked {
    background-color: #198754;
    border-color: #198754;
}

.form-switch .form-check-input:focus {
    border-color: #198754;
    box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.25);
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
ob_end_flush();
?>