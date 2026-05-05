<?php
require_once __DIR__ . '/../../../../roots.php';

header('Content-Type: application/json');

if (!isset($_GET['role_id'])) {
    echo json_encode(['success' => false, 'message' => 'Role ID missing']);
    exit();
}

$role_id = $_GET['role_id'];

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
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
