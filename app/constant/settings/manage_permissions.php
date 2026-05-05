<?php
$page_title = "Manage Role Permissions";
require_once 'header.php';
require_once 'core/permissions.php';

// Only admins can manage permissions
if (!isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

// Get all roles
$roles_stmt = $pdo->query("SELECT * FROM roles ORDER BY role_name");
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all permissions grouped by module
$perms_stmt = $pdo->query("SELECT * FROM permissions ORDER BY COALESCE(module_name, 'Other'), page_name");
$all_permissions = $perms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by module
$grouped_permissions = [];
foreach ($all_permissions as $perm) {
    $module = $perm['module_name'] ?? 'Other';
    $grouped_permissions[$module][] = $perm;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    $role_id = $_POST['role_id'];
    
    // Prevent editing Admin role (role_id 1)
    if ($role_id == 1) {
        $error_message = "The Admin role is protected and its permissions cannot be modified.";
    } else {
        try {
        $pdo->beginTransaction();
        
        // Delete existing permissions for this role
        $delete_stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $delete_stmt->execute([$role_id]);
        
        // Insert new permissions
        $insert_stmt = $pdo->prepare("
            INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($_POST['permissions'] ?? [] as $perm_id => $actions) {
            $can_view = isset($actions['view']) ? 1 : 0;
            $can_create = isset($actions['create']) ? 1 : 0;
            $can_edit = isset($actions['edit']) ? 1 : 0;
            $can_delete = isset($actions['delete']) ? 1 : 0;
            
            // Only insert if at least one permission is granted
            if ($can_view || $can_create || $can_edit || $can_delete) {
                $insert_stmt->execute([$role_id, $perm_id, $can_view, $can_create, $can_edit, $can_delete]);
            }
        }
        
        $pdo->commit();
        $success_message = "Permissions updated successfully!";
        
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error updating permissions: " . $e->getMessage();
        }
    }
}

// Get current permissions for selected role
$selected_role_id = $_GET['role_id'] ?? $roles[0]['role_id'] ?? null;
$current_permissions = [];

if ($selected_role_id) {
    $current_stmt = $pdo->prepare("
        SELECT permission_id, can_view, can_create, can_edit, can_delete 
        FROM role_permissions 
        WHERE role_id = ?
    ");
    $current_stmt->execute([$selected_role_id]);
    
    while ($row = $current_stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_permissions[$row['permission_id']] = [
            'view' => $row['can_view'],
            'create' => $row['can_create'],
            'edit' => $row['can_edit'],
            'delete' => $row['can_delete']
        ];
    }
}

$is_admin_role = ($selected_role_id == 1);
?>

<style>
    .admin-role-locked {
        opacity: 0.7;
        pointer-events: none;
        position: relative;
    }
    .admin-role-locked::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-15deg);
        font-size: 3rem;
        font-weight: bold;
        color: rgba(220, 53, 69, 0.2);
        z-index: 1000;
        pointer-events: none;
        white-space: nowrap;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Manage Role Permissions</h4>
                </div>
                <div class="card-body">
                    
                    <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?= $success_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?= $error_message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Role Selection -->
                    <form method="GET" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label"><strong>Select Role:</strong></label>
                                <select name="role_id" class="form-select" onchange="this.form.submit()">
                                    <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['role_id'] ?>" 
                                            <?= $selected_role_id == $role['role_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($role['role_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <div class="alert alert-info mt-4 mb-0">
                                    <i class="bi bi-info-circle"></i> <strong>Tip:</strong> Check the boxes to grant View, Edit, or Delete permissions for each page/module. You can grant any combination of permissions.
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Permissions Form -->
                    <form method="POST" class="<?= $is_admin_role ? 'admin-role-locked' : '' ?>">
                        <input type="hidden" name="role_id" value="<?= $selected_role_id ?>">
                        
                        <?php foreach ($grouped_permissions as $module => $permissions): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bi bi-folder"></i> <?= htmlspecialchars($module) ?></h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 40%;">Page/Feature</th>
                                            <th class="text-center" style="width: 15%;">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input select-all-col" 
                                                           data-type="view" data-module="<?= htmlspecialchars($module) ?>"
                                                           id="select_all_view_<?= md5($module) ?>">
                                                    <label class="form-check-label" for="select_all_view_<?= md5($module) ?>">
                                                        <i class="bi bi-eye"></i> View
                                                    </label>
                                                </div>
                                            </th>
                                            <th class="text-center" style="width: 15%;">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input select-all-col" 
                                                           data-type="create" data-module="<?= htmlspecialchars($module) ?>"
                                                           id="select_all_create_<?= md5($module) ?>">
                                                    <label class="form-check-label" for="select_all_create_<?= md5($module) ?>">
                                                        <i class="bi bi-plus-circle"></i> Create
                                                    </label>
                                                </div>
                                            </th>
                                            <th class="text-center" style="width: 15%;">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input select-all-col" 
                                                           data-type="edit" data-module="<?= htmlspecialchars($module) ?>"
                                                           id="select_all_edit_<?= md5($module) ?>">
                                                    <label class="form-check-label" for="select_all_edit_<?= md5($module) ?>">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </label>
                                                </div>
                                            </th>
                                            <th class="text-center" style="width: 15%;">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input select-all-col" 
                                                           data-type="delete" data-module="<?= htmlspecialchars($module) ?>"
                                                           id="select_all_delete_<?= md5($module) ?>">
                                                    <label class="form-check-label" for="select_all_delete_<?= md5($module) ?>">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </label>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($permissions as $perm): ?>
                                        <?php
                                        $perm_id = $perm['permission_id'];
                                        $current = $current_permissions[$perm_id] ?? ['view' => 0, 'create' => 0, 'edit' => 0, 'delete' => 0];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($perm['page_name']) ?></strong>
                                                <?php if ($perm['description']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($perm['description']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input perm-check-<?= md5($module) ?>-view" 
                                                           id="view_<?= $perm_id ?>" name="permissions[<?= $perm_id ?>][view]" 
                                                           <?= $current['view'] ? 'checked' : '' ?> <?= $is_admin_role ? 'disabled' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input perm-check-<?= md5($module) ?>-create action-checkbox" 
                                                           id="create_<?= $perm_id ?>" name="permissions[<?= $perm_id ?>][create]" 
                                                           <?= $current['create'] ? 'checked' : '' ?> 
                                                           <?= ($is_admin_role || !$current['view']) ? 'disabled' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input perm-check-<?= md5($module) ?>-edit action-checkbox" 
                                                           id="edit_<?= $perm_id ?>" name="permissions[<?= $perm_id ?>][edit]" 
                                                           <?= $current['edit'] ? 'checked' : '' ?> 
                                                           <?= ($is_admin_role || !$current['view']) ? 'disabled' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input type="checkbox" class="form-check-input perm-check-<?= md5($module) ?>-delete action-checkbox" 
                                                           id="delete_<?= $perm_id ?>" name="permissions[<?= $perm_id ?>][delete]" 
                                                           <?= $current['delete'] ? 'checked' : '' ?> 
                                                           <?= ($is_admin_role || !$current['view']) ? 'disabled' : '' ?>>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="text-end">
                            <button type="submit" name="save_permissions" class="btn btn-primary btn-lg" <?= $is_admin_role ? 'disabled' : '' ?>>
                                <i class="bi bi-save"></i> Save Permissions
                            </button>
                        </div>
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Update the state (disabled/enabled) of create, edit, delete based on view
 * IF VIEW IS UNCHECKED, EVERYTHING ELSE MUST BE UNCHECKED AND DISABLED
 */
function updateRowState(permId) {
    const viewCb = document.getElementById('view_' + permId);
    const actions = [
        document.getElementById('create_' + permId),
        document.getElementById('edit_' + permId),
        document.getElementById('delete_' + permId)
    ];
    
    const isViewChecked = viewCb && viewCb.checked;
    
    actions.forEach(cb => {
        if (cb) {
            if (!isViewChecked) {
                cb.checked = false;
                cb.disabled = true;
                cb.closest('.form-check').style.opacity = '0.5';
            } else {
                cb.disabled = false;
                cb.closest('.form-check').style.opacity = '1';
            }
        }
    });
}

// Handle "Select All" for columns
document.querySelectorAll('.select-all-col').forEach(function(headerCheckbox) {
    headerCheckbox.addEventListener('change', function() {
        const type = this.dataset.type;
        const moduleHash = this.id.split('_').pop();
        const checkboxes = document.querySelectorAll(`.perm-check-${moduleHash}-${type}`);
        
        checkboxes.forEach(cb => {
            // Only update if not globally disabled (like Admin role)
            if (!cb.disabled || cb.classList.contains('action-checkbox')) {
                cb.checked = this.checked;
                cb.dispatchEvent(new Event('change'));
            }
        });
    });
});

// Setup event listeners
document.querySelectorAll('input[type="checkbox"]').forEach(function(checkbox) {
    if (checkbox.classList.contains('select-all-col')) return;

    if (checkbox.id.startsWith('view_')) {
        const permId = checkbox.id.replace('view_', '');
        // Initial sync
        updateRowState(permId);
        
        checkbox.addEventListener('change', function() {
            updateRowState(permId);
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
