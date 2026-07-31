<?php
require_once __DIR__ . '/../../../../roots.php';
// Audit SEC-019: this endpoint returns the full role + role_permissions grid, which is
// both the group's authorisation model and the literal role_name string that isAdmin()
// matches against (core/permissions.php:263-274). roots.php yields $pdo and the session
// but NOT authentication, so the gate has to be explicit.
require_once __DIR__ . '/../../../../includes/require_auth.php';

header('Content-Type: application/json');

// user_roles is admin-only in the seeded grid (includes/role_grants.php:20).
requirePermissionJson('view', 'user_roles');

if (!isset($_GET['role_id'])) {
    echo json_encode(['success' => false, 'message' => 'Role ID missing']);
    exit();
}

$role_id = (int) $_GET['role_id'];

try {
    // 1. Get base role info
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Role not found']);
        exit();
    }

    // 2. Get permissions with CRUD flags
    $stmt = $pdo->prepare("
        SELECT permission_id, can_view, can_create, can_edit, can_delete 
        FROM role_permissions 
        WHERE role_id = ?
    ");
    $stmt->execute([$role_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'role' => $role,
        'permissions' => $permissions
    ]);

} catch (Exception $e) {
    // Audit SEC-018: never return raw PDO text — it leaks table and column names.
    error_log('get_role.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load role details.']);
}
