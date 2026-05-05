<?php
/**
 * File: app/bms/product/categories.php
 * Product Categories Management
 */
ob_start();
require_once 'header.php';

// Check user role for permissions
requireViewPermission('products');
$can_manage_categories = canEdit('products');

// Get categories tree
try {
    $stmt = $pdo->query("SELECT * FROM categories WHERE type = 'product' ORDER BY category_name ASC");
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_categories = [];
    $error_message = $e->getMessage();
}

// Function to build tree 
function get_category_tree($categories, $parent_id = 0, $depth = 0) {
    $tree = [];
    foreach ($categories as $cat) {
        if ($cat['parent_id'] == $parent_id) {
            $cat['depth'] = $depth;
            $tree[] = $cat;
            $tree = array_merge($tree, get_category_tree($categories, $cat['category_id'], $depth + 1));
        }
    }
    return $tree;
}

$category_tree = get_category_tree($all_categories);
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-tags"></i> Product Categories</h2>
                    <p class="text-muted mb-0">Manage product categories and hierarchy</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">
                        <i class="bi bi-plus-circle"></i> New Category
                    </button>
                    <a href="<?= getUrl('products') ?>" class="btn btn-primary">
                        <i class="bi bi-box"></i> Back to Products
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Categories List -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-list-ul"></i> Category List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($category_tree)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No categories found.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($category_tree as $cat): ?>
                                    <tr>
                                        <td>
                                            <?= str_repeat('<span class="ms-3"></span>', $cat['depth']) ?>
                                            <?php if ($cat['depth'] > 0): ?><i class="bi bi-arrow-return-right text-muted me-1"></i><?php endif; ?>
                                            <strong><?= htmlspecialchars($cat['category_name']) ?></strong>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($cat['description'] ?? 'N/A') ?></small></td>
                                        <td>
                                            <span class="badge bg-<?= ($cat['status'] ?? 'active') == 'active' ? 'success' : 'danger' ?>">
                                                <?= ucfirst($cat['status'] ?? 'active') ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-cat" 
                                                    data-cat='<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-cat" 
                                                    data-id="<?= $cat['category_id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help/Summary -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-info-circle"></i> Quick Tips</h5>
                </div>
                <div class="card-body">
                    <ul class="text-muted small">
                        <li class="mb-2">Organize products into logical groups for easier tracking and reporting.</li>
                        <li class="mb-2">Use sub-categories (Parents) to create hierarchies.</li>
                        <li class="mb-2">Inactive categories will not appear in product creation dropdowns.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">New Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" id="category_id" name="category_id">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category_name" id="modal_category_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select class="form-select" name="parent_id" id="modal_parent_id">
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($category_tree as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>">
                                    <?= str_repeat('&nbsp;&nbsp;', $cat['depth']) ?> <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="modal_description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="modal_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    $('#categoryForm')[0].reset();
    $('#category_id').val('');
    $('#modalTitle').text('New Category');
    $('#categoryModal').modal('show');
}

$(document).on('click', '.btn-edit-cat', function() {
    const cat = $(this).data('cat');
    $('#modalTitle').text('Edit Category');
    $('#category_id').val(cat.category_id);
    $('#modal_category_name').val(cat.category_name);
    $('#modal_parent_id').val(cat.parent_id || 0);
    $('#modal_description').val(cat.description || '');
    $('#modal_status').val(cat.status || 'active');
    $('#categoryModal').modal('show');
});

$('#categoryForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serialize();
    const categoryId = $('#category_id').val();
    const endpoint = categoryId ? '<?= getUrl('/api/update_category.php') ?>' : '<?= getUrl('/api/create_category.php') ?>';

    $.ajax({
        url: endpoint,
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                Swal.fire('Success', res.message || 'Category saved', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Failed to save', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'An error occurred during communication with the server.', 'error');
        }
    });
});

$(document).on('click', '.btn-delete-cat', function() {
    const id = $(this).data('id');
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the category!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('/api/delete_category.php') ?>',
                type: 'POST',
                data: { category_id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Deleted!', 'Category has been deleted.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
});
</script>

<style>
.card {
    background-color: white !important;
    border: 1px solid #dee2e6 !important;
}
.table thead th {
    background-color: #f8f9fa !important;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
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
require_once 'footer.php';
ob_end_flush();
?>
