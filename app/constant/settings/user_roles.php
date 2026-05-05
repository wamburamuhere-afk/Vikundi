<?php
$page_title = "User Roles & Permissions";
require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';
require_once 'core/permissions.php';

// Role Translation Map
$roleMap = [
    'admin' => ['en' => 'Admin (Super)', 'sw' => 'Msimamizi Mkuu', 'desc' => ['en' => 'Full system access', 'sw' => 'Ufikiaji kamili wa kila kitu']],
    'mwenyekiti' => ['en' => 'Chairman', 'sw' => 'Mwenyekiti', 'desc' => ['en' => 'Group Leader (Chairman)', 'sw' => 'Kiongozi wa Kikundi']],
    'chairman' => ['en' => 'Chairman', 'sw' => 'Mwenyekiti', 'desc' => ['en' => 'Group Leader (Chairman)', 'sw' => 'Kiongozi wa Kikundi']],
    'secretary' => ['en' => 'Secretary', 'sw' => 'Katibu', 'desc' => ['en' => 'General Secretary', 'sw' => 'Katibu Mkuu wa Kikundi']],
    'mhazini' => ['en' => 'Treasurer', 'sw' => 'Mhazini', 'desc' => ['en' => 'Group Treasurer (Funds Custodian)', 'sw' => 'Mtunzaji wa fedha za Kikundi']],
    'treasurer' => ['en' => 'Treasurer', 'sw' => 'Mhazini', 'desc' => ['en' => 'Group Treasurer (Funds Custodian)', 'sw' => 'Mtunzaji wa fedha za Kikundi']],
    'mjumbe' => ['en' => 'Board Member', 'sw' => 'Mjumbe', 'desc' => ['en' => 'Board Member', 'sw' => 'Mjumbe wa Kamati/Bodi']],
    'member' => ['en' => 'Member', 'sw' => 'Mwanachama', 'desc' => ['en' => 'General member access', 'sw' => 'Ufikiaji wa mwanachama wa kawaida']],
    'mwanachama' => ['en' => 'Member', 'sw' => 'Mwanachama', 'desc' => ['en' => 'General member access', 'sw' => 'Ufikiaji wa mwanachama wa kawaida']]
];

$lang = $_SESSION['preferred_language'] ?? 'en';
$isSw = ($lang === 'sw');

// Function to translate role name
function translateRole($name, $map, $lang) {
    if (!$name) return ($lang === 'sw' ? 'Hajapangwa' : 'Unassigned');
    $low = strtolower($name);
    foreach ($map as $key => $vals) {
        if ($low === $key || str_contains($low, $key)) return $vals[$lang];
    }
    return $name;
}

// Function to translate role description
function translateDescription($desc, $name, $map, $lang) {
    if (!$name) return $desc;
    $low = strtolower($name);
    foreach ($map as $key => $vals) {
        if (($low === $key || str_contains($low, $key)) && isset($vals['desc'])) {
            // Check if current desc matches or is empty - or just override if well known
            $currentDesc = strtolower((string)$desc);
            if (empty($desc) || str_contains($currentDesc, 'leader') || str_contains($currentDesc, 'kiongozi') || str_contains($currentDesc, 'fedha') || str_contains($currentDesc, 'treasurer') || str_contains($currentDesc, 'admin') || str_contains($currentDesc, 'member') || str_contains($currentDesc, 'mjumbe')) {
                return $vals['desc'][$lang];
            }
        }
    }
    return $desc;
}

// Only admins can manage roles
if (!canView('user_roles')) {
    header("Location: unauthorized.php");
    exit();
}

// Handle form submissions
if ($_POST) {
    $success_messages = [];
    $error_messages = [];
    
    // Add/Edit Role
    if (isset($_POST['save_role'])) {
        try {
            $role_id = $_POST['role_id'] ?? null;
            $role_name = trim($_POST['role_name']);
            $role_description = trim($_POST['role_description']);
            $permissions = $_POST['permissions'] ?? [];
            
            // Validate role name
            if (empty($role_name)) {
                throw new Exception("Role name is required");
            }
            
            if ($role_id) {
                // Update existing role
                $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, description = ?, updated_at = NOW() WHERE role_id = ?");
                $stmt->execute([$role_name, $role_description, $role_id]);
                
                // Store existing permissions before deleting
                $existingStmt = $pdo->prepare("SELECT permission_id, can_view, can_create, can_edit, can_delete FROM role_permissions WHERE role_id = ?");
                $existingStmt->execute([$role_id]);
                $existingPermissions = [];
                while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existingPermissions[$row['permission_id']] = [
                        'can_view' => $row['can_view'],
                        'can_create' => $row['can_create'],
                        'can_edit' => $row['can_edit'],
                        'can_delete' => $row['can_delete']
                    ];
                }
                
                // Delete existing permissions
                $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $stmt->execute([$role_id]);
                
                $message = "Role updated successfully";
            } else {
                // Create new role
                $stmt = $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$role_name, $role_description]);
                $role_id = $pdo->lastInsertId();
                $existingPermissions = [];
                
                $message = "Role created successfully";
            }
            
            // Add permissions - preserve existing access levels or set defaults
            // We expect $_POST['perm_view'], $_POST['perm_create'], etc. as arrays of permission IDs
            $perm_view = $_POST['perm_view'] ?? [];
            $perm_create = $_POST['perm_create'] ?? [];
            $perm_edit = $_POST['perm_edit'] ?? [];
            $perm_delete = $_POST['perm_delete'] ?? [];

            // Get all unique permission IDs that have at least one checkbox checked
            $all_selected = array_unique(array_merge($perm_view, $perm_create, $perm_edit, $perm_delete));

            foreach ($all_selected as $pid) {
                $v = in_array($pid, $perm_view) ? 1 : 0;
                $c = in_array($pid, $perm_create) ? 1 : 0;
                $e = in_array($pid, $perm_edit) ? 1 : 0;
                $d = in_array($pid, $perm_delete) ? 1 : 0;

                $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$role_id, $pid, $v, $c, $e, $d]);
            }
            
            $success_messages[] = $message;
        } catch (Exception $e) {
            $error_messages[] = "Error saving role: " . $e->getMessage();
        }
    }
    
    // Delete Role
    if (isset($_POST['delete_role'])) {
        try {
            $role_id = $_POST['role_id'];
            
            // Check if role is in use
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $stmt->execute([$role_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception("Cannot delete role. There are $user_count users assigned to this role.");
            }
            
            // Delete role permissions
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            // Delete role
            $stmt = $pdo->prepare("DELETE FROM roles WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            $success_messages[] = "Role deleted successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error deleting role: " . $e->getMessage();
        }
    }
    
    // Update User Role
    if (isset($_POST['update_user_role'])) {
        try {
            $user_id = $_POST['user_id'];
            $role_id = $_POST['role_id'];
            
            $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE user_id = ?");
            $stmt->execute([$role_id, $user_id]);
            
            $success_messages[] = "User role updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating user role: " . $e->getMessage();
        }
    }
}

// Load all roles
$roles_stmt = $pdo->query("
    SELECT r.*, COUNT(u.user_id) as user_count 
    FROM roles r 
    LEFT JOIN users u ON r.role_id = u.role_id 
    GROUP BY r.role_id 
    ORDER BY r.role_name
");
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load all permissions
$permissions_stmt = $pdo->query("
    SELECT permission_id, page_key, page_name, description, module_name
    FROM permissions 
    ORDER BY COALESCE(module_name, 'Other'), page_name
");
$permissions = $permissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by module
$permissions_by_module = [];
foreach ($permissions as $permission) {
    $module_name = $permission['module_name'] ?? 'Other';
    if (!isset($permissions_by_module[$module_name])) {
        $permissions_by_module[$module_name] = [];
    }
    $permissions_by_module[$module_name][] = $permission;
}

// Load all users with their roles
$users_stmt = $pdo->query("
    SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.is_active AS status, 
           r.role_name, r.role_id
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    ORDER BY u.first_name, u.last_name
");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get permission statistics
$stats_stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM roles) as total_roles,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(DISTINCT module_name) FROM permissions) as total_modules
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Helper function for role badge colors
function getRoleBadgeColor($role_name) {
    switch ($role_name) {
        case 'Admin': return 'danger';
        case 'Managing Director': return 'warning';
        case 'Director': return 'warning';
        case 'Loan Officer': return 'primary';
        case 'CFO': return 'info';
        case 'Accountant': return 'info';
        default: return 'secondary';
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-person-badge"></i> <?= $isSw ? 'Nafasi za Watumiaji (Roles)' : 'User Roles & Permissions' ?></h2>
            <p class="text-muted"><?= $isSw ? 'Simamia nafasi, mamlaka, na ufikiaji wa watumiaji kwenye mfumo' : 'Manage user roles, permissions, and access control across the system' ?></p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <!-- Total Roles Card -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm h-100 py-2 border-0" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                <?= $isSw ? 'Jumla ya Nafasi' : 'Total Roles' ?></div>
                            <div class="h4 mb-0 font-weight-bold text-dark">
                                <?= number_format($stats['total_roles']) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-badge fs-2 text-dark opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Permissions Card -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm h-100 py-2 border-0" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                <?= $isSw ? 'Mamlaka' : 'Total Permissions' ?></div>
                            <div class="h4 mb-0 font-weight-bold text-dark">
                                <?= number_format($stats['total_permissions']) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-shield-check fs-2 text-dark opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Users Card -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm h-100 py-2 border-0" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                <?= $isSw ? 'Watumiaji' : 'System Users' ?></div>
                            <div class="h4 mb-0 font-weight-bold text-dark">
                                <?= number_format($stats['total_users']) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fs-2 text-dark opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Modules Card -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm h-100 py-2 border-0" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                <?= $isSw ? 'Moduli za Mfumo' : 'System Modules' ?></div>
                            <div class="h4 mb-0 font-weight-bold text-dark">
                                <?= number_format($stats['total_modules']) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-puzzle fs-2 text-dark opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="card shadow">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="rolesTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="roles-tab" data-bs-toggle="tab" 
                            data-bs-target="#roles" type="button" role="tab">
                        <i class="bi bi-person-badge"></i> <?= $isSw ? 'Usimamizi wa Nafasi' : 'Roles Management' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="users-tab" data-bs-toggle="tab" 
                            data-bs-target="#users" type="button" role="tab">
                        <i class="bi bi-people"></i> <?= $isSw ? 'Mgawanyo wa Nafasi' : 'User Assignments' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="permissions-tab" data-bs-toggle="tab" 
                            data-bs-target="#permissions" type="button" role="tab">
                        <i class="bi bi-shield-check"></i> <?= $isSw ? 'Mamlaka za Ufikiaji' : 'Permissions Matrix' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="audit-tab" data-bs-toggle="tab" 
                            data-bs-target="#audit" type="button" role="tab">
                        <i class="bi bi-clock-history"></i> <?= $isSw ? 'Kumbukumbu (Audit)' : 'Access Audit' ?>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content" id="rolesTabsContent">
                <!-- Roles Management Tab -->
                <div class="tab-pane fade show active" id="roles" role="tabpanel">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><?= $isSw ? 'Nafasi za Mfumo' : 'System Roles' ?></h5>
                                    <button class="btn btn-primary btn-sm" id="addRoleBtn">
                                        <i class="bi bi-plus-circle"></i> <?= $isSw ? 'Ongeza Nafasi' : 'Add Role' ?>
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($roles as $role): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars(translateRole($role['role_name'], $roleMap, $lang)) ?></h6>
                                                    <p class="mb-1 text-muted small"><?= htmlspecialchars(translateDescription($role['description'], $role['role_name'], $roleMap, $lang)) ?></p>
                                                    <small class="text-muted">
                                                        <?= $role['user_count'] ?> <?= $isSw ? 'mtumiaji(wa)' : 'user(s)' ?>
                                                    </small>
                                                </div>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary edit-role" 
                                                            data-role-id="<?= $role['role_id'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($role['user_count'] == 0 && $role['role_name'] != 'Administrator'): ?>
                                                        <button class="btn btn-sm btn-outline-danger delete-role" 
                                                                data-role-id="<?= $role['role_id'] ?>" 
                                                                data-role-name="<?= htmlspecialchars($role['role_name']) ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0" id="roleFormTitle"><?= $isSw ? 'Ongeza Nafasi Mpya' : 'Add New Role' ?></h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="roleForm">
                                        <input type="hidden" id="role_id" name="role_id">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_name" class="form-label"><?= $isSw ? 'Jina la Nafasi *' : 'Role Name *' ?></label>
                                                    <input type="text" class="form-control" id="role_name" name="role_name" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_description" class="form-label"><?= $isSw ? 'Maelezo' : 'Description' ?></label>
                                                    <input type="text" class="form-control" id="role_description" name="role_description">
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="mt-4 mb-3"><?= $isSw ? 'Mamlaka za Nafasi' : 'Role Permissions' ?></h6>
                                        
                                        <div class="permissions-container">
                                            <?php foreach ($permissions_by_module as $module_name => $module_permissions): ?>
                                                <div class="card mb-4 border shadow-sm">
                                                    <div class="card-header bg-light py-2">
                                                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($module_name) ?></h6>
                                                    </div>
                                                    <div class="card-body p-0">
                                                        <div class="table-responsive">
                                                            <table class="table table-hover mb-0 align-middle">
                                                                <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 40%"><?= $isSw ? 'Ukurasa/Mamlaka' : 'Feature' ?></th>
                                                                        <th class="text-center small"><?= $isSw ? 'Ona' : 'View' ?></th>
                                                                        <th class="text-center small"><?= $isSw ? 'Ongeza' : 'Create' ?></th>
                                                                        <th class="text-center small"><?= $isSw ? 'Hariri' : 'Edit' ?></th>
                                                                        <th class="text-center small"><?= $isSw ? 'Futa' : 'Delete' ?></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($module_permissions as $permission): ?>
                                                                        <tr>
                                                                            <td>
                                                                                <div class="fw-bold"><?= htmlspecialchars($permission['page_name']) ?></div>
                                                                                <div class="small text-muted"><?= htmlspecialchars($permission['description']) ?></div>
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input class="form-check-input perm-view" type="checkbox" name="perm_view[]" value="<?= $permission['permission_id'] ?>" id="v_<?= $permission['permission_id'] ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input class="form-check-input perm-create" type="checkbox" name="perm_create[]" value="<?= $permission['permission_id'] ?>" id="c_<?= $permission['permission_id'] ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input class="form-check-input perm-edit" type="checkbox" name="perm_edit[]" value="<?= $permission['permission_id'] ?>" id="e_<?= $permission['permission_id'] ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input class="form-check-input perm-delete" type="checkbox" name="perm_delete[]" value="<?= $permission['permission_id'] ?>" id="d_<?= $permission['permission_id'] ?>">
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="mt-4">
                                            <button type="submit" name="save_role" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> <?= $isSw ? 'Hifadhi Nafasi' : 'Save Role' ?>
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="cancelEdit">
                                                <?= $isSw ? 'Ghairi' : 'Cancel' ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Assignments Tab -->
                <div class="tab-pane fade" id="users" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><?= $isSw ? 'Mgawanyo wa Nafasi' : 'User Role Assignments' ?></h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="usersTable">
                                            <thead>
                                                <tr>
                                                    <th><?= $isSw ? 'Mtumiaji' : 'User' ?></th>
                                                    <th><?= $isSw ? 'Username' : 'Username' ?></th>
                                                    <th><?= $isSw ? 'Idara' : 'Department' ?></th>
                                                    <th><?= $isSw ? 'Nafasi ya Sasa' : 'Current Role' ?></th>
                                                    <th><?= $isSw ? 'Hali' : 'Status' ?></th>
                                                    <th><?= $isSw ? 'Hatua' : 'Actions' ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($users as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                                                            <br><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                                        <td><?= htmlspecialchars($user['department_name'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= getRoleBadgeColor($user['role_name']) ?>">
                                                                <?= htmlspecialchars(translateRole($user['role_name'], $roleMap, $lang)) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>">
                                                                <?= $isSw ? ($user['status'] == 'active' ? 'Hai' : 'Aha') : ucfirst($user['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary assign-role" 
                                                                    data-user-id="<?= $user['user_id'] ?>"
                                                                    data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                                                                    data-current-role="<?= $user['role_id'] ?>">
                                                                <i class="bi bi-person-gear"></i> <?= $isSw ? 'Gawa Nafasi' : 'Assign Role' ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Permissions Matrix Tab -->
                <div class="tab-pane fade" id="permissions" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><?= $isSw ? 'Mamlaka za Ufikiaji' : 'Permissions Matrix' ?></h5>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary" id="exportMatrix">
                                            <i class="bi bi-download"></i> <?= $isSw ? 'Pakua' : 'Export' ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm" id="permissionsMatrix">
                                            <thead class="table-light">
                                                <tr>
                                                    <th><?= $isSw ? 'Mamlaka' : 'Permission' ?></th>
                                                    <th><?= $isSw ? 'Moduli' : 'Module' ?></th>
                                                    <th><?= $isSw ? 'Maelezo' : 'Description' ?></th>
                                                    <?php foreach ($roles as $role): ?>
                                                        <th class="text-center"><?= htmlspecialchars(translateRole($role['role_name'], $roleMap, $lang)) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($permissions as $permission): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($permission['page_name']) ?></strong>
                                                        </td>
                                                        <td><?= htmlspecialchars($permission['module_name']) ?></td>
                                                        <td><?= htmlspecialchars($permission['description']) ?></td>
                                                        <?php foreach ($roles as $role): ?>
                                                            <td class="text-center">
                                                                <?php
                                                                $stmt = $pdo->prepare("
                                                                    SELECT COUNT(*) FROM role_permissions 
                                                                    WHERE role_id = ? AND permission_id = ?
                                                                ");
                                                                $stmt->execute([$role['role_id'], $permission['permission_id']]);
                                                                $has_permission = $stmt->fetchColumn();
                                                                ?>
                                                                <?php if ($has_permission): ?>
                                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                                <?php else: ?>
                                                                    <i class="bi bi-x-circle-fill text-muted"></i>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Access Audit Tab -->
                <div class="tab-pane fade" id="audit" role="tabpanel">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Access Log</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="accessLogTable">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Action</th>
                                                    <th>Resource</th>
                                                    <th>Timestamp</th>
                                                    <th>IP Address</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">
                                                        Loading access log...
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Access Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <div id="accessStats">
                                        <div class="text-center text-muted">
                                            <i class="bi bi-hourglass-split"></i><br>
                                            Loading statistics...
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <button class="btn btn-outline-primary btn-sm w-100 mb-2" id="generateAccessReport">
                                        <i class="bi bi-file-earmark-text"></i> Generate Access Report
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm w-100 mb-2" id="clearOldLogs">
                                        <i class="bi bi-trash"></i> Clear Old Logs
                                    </button>
                                    <button class="btn btn-outline-info btn-sm w-100" id="refreshAudit">
                                        <i class="bi bi-arrow-clockwise"></i> Refresh Data
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Role Modal -->
<div class="modal fade" id="assignRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $isSw ? 'Gawa Nafasi kwa Mtumiaji' : 'Assign Role to User' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" id="assign_user_id" name="user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= $isSw ? 'Mtumiaji' : 'User' ?></label>
                        <input type="text" class="form-control" id="assign_user_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="assign_role_id" class="form-label"><?= $isSw ? 'Chagua Nafasi *' : 'Select Role *' ?></label>
                        <select class="form-control" id="assign_role_id" name="role_id" required>
                            <option value=""><?= $isSw ? '-- Chagua nafasi --' : 'Select a role...' ?></option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars(translateRole($role['role_name'], $roleMap, $lang)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $isSw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" name="update_user_role" class="btn btn-primary"><?= $isSw ? 'Gawa Nafasi' : 'Assign Role' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Role Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the role "<strong id="deleteRoleName"></strong>"?</p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" id="delete_role_id" name="role_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_role" class="btn btn-danger">Delete Role</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<style>
.card {
    border: none;
    border-radius: 0.5rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 500;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 3px solid #0d6efd;
    background: transparent;
}

.border-left-primary { border-left: 0.25rem solid #4e73df !important; }
.border-left-success { border-left: 0.25rem solid #1cc88a !important; }
.border-left-info { border-left: 0.25rem solid #36b9cc !important; }
.border-left-warning { border-left: 0.25rem solid #f6c23e !important; }

.text-xs {
    font-size: 0.7rem;
}

.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
}

.permissions-container .card-header {
    background-color: #f8f9fa;
    padding: 0.5rem 1rem;
}

.module-checkbox {
    margin-right: 0.5rem;
}

#permissionsMatrix th {
    font-size: 0.8rem;
    white-space: nowrap;
}

#permissionsMatrix td {
    font-size: 0.8rem;
    vertical-align: middle;
}
</style>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#usersTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']]
    });

    $('#permissionsMatrix').DataTable({
        pageLength: 50,
        order: [[1, 'asc'], [0, 'asc']],
        scrollX: true
    });

    // Add Role Button
    $('#addRoleBtn').click(function() {
        $('#roleFormTitle').text('Add New Role');
        $('#roleForm')[0].reset();
        $('#role_id').val('');
        $('.permission-checkbox').prop('checked', false);
        $('.module-checkbox').prop('checked', false);
    });

    // Edit Role
    $('.edit-role').click(function() {
        const roleId = $(this).data('role-id');
        
        $.ajax({
            url: 'ajax/get_role.php',
            type: 'GET',
            data: { role_id: roleId },
            success: function(response) {
                if (response.success) {
                    $('#roleFormTitle').text('Edit Role: ' + response.role.role_name);
                    $('#role_id').val(response.role.role_id);
                    $('#role_name').val(response.role.role_name);
                    $('#role_description').val(response.role.description);
                    
                    // Clear all checkboxes
                    $('input[type="checkbox"]').prop('checked', false);
                    
                    // Check appropriate checkboxes
                    response.permissions.forEach(function(p) {
                        if (p.can_view == 1) $('#v_' + p.permission_id).prop('checked', true);
                        if (p.can_create == 1) $('#c_' + p.permission_id).prop('checked', true);
                        if (p.can_edit == 1) $('#e_' + p.permission_id).prop('checked', true);
                        if (p.can_delete == 1) $('#d_' + p.permission_id).prop('checked', true);
                    });
                } else {
                    showToast('error', 'Error loading role details');
                }
            },
            error: function() {
                showToast('error', 'Error loading role details');
            }
        });
    });

    // Delete Role
    $('.delete-role').click(function() {
        const roleId = $(this).data('role-id');
        const roleName = $(this).data('role-name');
        
        $('#deleteRoleName').text(roleName);
        $('#delete_role_id').val(roleId);
        $('#deleteRoleModal').modal('show');
    });

    // Cancel Edit
    $('#cancelEdit').click(function() {
        $('#roleFormTitle').text('Add New Role');
        $('#roleForm')[0].reset();
        $('#role_id').val('');
        $('.permission-checkbox').prop('checked', false);
        $('.module-checkbox').prop('checked', false);
    });

    // Assign Role to User
    $('.assign-role').click(function() {
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        const currentRole = $(this).data('current-role');
        
        $('#assign_user_id').val(userId);
        $('#assign_user_name').val(userName);
        $('#assign_role_id').val(currentRole);
        $('#assignRoleModal').modal('show');
    });

    // Module checkbox functionality
    $('.module-checkbox').change(function() {
        const module = $(this).data('module');
        const isChecked = $(this).is(':checked');
        
        $(`.permission-checkbox[data-module="${module}"]`).prop('checked', isChecked);
    });

    // Update module checkboxes based on permission selections
    function updateModuleCheckboxes() {
        $('.module-checkbox').each(function() {
            const module = $(this).data('module');
            const modulePermissions = $(`.permission-checkbox[data-module="${module}"]`);
            const checkedPermissions = modulePermissions.filter(':checked');
            
            if (checkedPermissions.length === modulePermissions.length) {
                $(this).prop('checked', true);
                $(this).prop('indeterminate', false);
            } else if (checkedPermissions.length > 0) {
                $(this).prop('checked', false);
                $(this).prop('indeterminate', true);
            } else {
                $(this).prop('checked', false);
                $(this).prop('indeterminate', false);
            }
        });
    }

    // Permission checkbox change
    $('.permission-checkbox').change(updateModuleCheckboxes);

    // Export Permissions Matrix
    $('#exportMatrix').click(function() {
        window.open('ajax/export_permissions_matrix.php', '_blank');
        showToast('info', 'Exporting permissions matrix...');
    });

    // Load access log
    function loadAccessLog() {
        $.ajax({
            url: 'ajax/get_access_log.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let html = '';
                    if (response.logs.length > 0) {
                        response.logs.forEach(log => {
                            html += `
                                <tr>
                                    <td>${log.user_name}</td>
                                    <td>${log.action}</td>
                                    <td>${log.resource}</td>
                                    <td>${log.timestamp}</td>
                                    <td>${log.ip_address}</td>
                                </tr>
                            `;
                        });
                    } else {
                        html = '<tr><td colspan="5" class="text-center text-muted">No access logs found</td></tr>';
                    }
                    $('#accessLogTable tbody').html(html);
                    
                    // Update statistics
                    $('#accessStats').html(`
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Logs:</span>
                                <strong>${response.stats.total_logs}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Today's Activities:</span>
                                <strong>${response.stats.today_activities}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Most Active User:</span>
                                <strong>${response.stats.most_active_user}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Last Updated:</span>
                                <strong>${response.stats.last_updated}</strong>
                            </div>
                        </div>
                    `);
                }
            },
            error: function() {
                $('#accessLogTable tbody').html('<tr><td colspan="5" class="text-center text-danger">Error loading access log</td></tr>');
            }
        });
    }

    // Generate Access Report
    $('#generateAccessReport').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Generating...');
        
        setTimeout(() => {
            showToast('success', 'Access report generated successfully!');
            btn.prop('disabled', false).html(originalText);
            window.open('ajax/generate_access_report.php', '_blank');
        }, 2000);
    });

    // Clear Old Logs
    $('#clearOldLogs').click(function() {
        if (!confirm('Are you sure you want to clear logs older than 90 days? This action cannot be undone.')) {
            return;
        }
        
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Clearing...');
        
        $.ajax({
            url: 'ajax/clear_old_logs.php',
            type: 'POST',
            data: { days: 90 },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Old logs cleared successfully!');
                    loadAccessLog();
                } else {
                    showToast('error', 'Error clearing logs: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                showToast('error', 'Error clearing logs');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Refresh Audit Data
    $('#refreshAudit').click(function() {
        loadAccessLog();
        showToast('info', 'Audit data refreshed');
    });

    // Toast notification function
    function showToast(type, message) {
        var toast = '<div class="toast align-items-center text-white bg-' + type + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        toast += '<div class="d-flex">';
        toast += '<div class="toast-body">' + message + '</div>';
        toast += '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
        toast += '</div></div>';
        
        var $toast = $(toast);
        $('.toast-container').append($toast);
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
        
        $toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }

    // Load initial access log
    loadAccessLog();
});
</script>