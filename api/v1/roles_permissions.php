<?php
/**
 * GET /api/v1/roles/{id}/permissions — one role's full view/create/edit/delete grid
 * PUT /api/v1/roles/{id}/permissions — set it
 *
 * Mirrors app/constant/settings/manage_permissions.php exactly, including its
 * own hardcoded rule: "The Admin role is protected and its permissions cannot
 * be modified" (role_id 1). ADMIN/CHAIRPERSON ONLY.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);
$callerId = (int) $auth['user_id'];

$roleId = (int) ($_GET['id'] ?? 0);
if ($roleId <= 0) {
    vk_api_error(422, 'invalid_id', 'A role id is required.');
}

$roleStmt = $pdo->prepare('SELECT role_id, role_name FROM roles WHERE role_id = ?');
$roleStmt->execute([$roleId]);
$role = $roleStmt->fetch(PDO::FETCH_ASSOC);
if (!$role) {
    vk_api_error(404, 'not_found', 'No role was found with that id.');
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if ($roleId === VK_API_PROTECTED_ROLE_ID) {
        vk_api_error(403, 'protected_role', 'The Admin role is protected and its permissions cannot be modified.');
    }

    $body = vk_api_body();
    $entries = vk_api_role_permissions_validate($pdo, $body['permissions'] ?? null);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $insert = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($entries as $e) {
            $insert->execute([$roleId, $e['permission_id'], (int) $e['view'], (int) $e['create'], (int) $e['edit'], (int) $e['delete']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        vk_api_error(500, 'save_failed', 'The permissions could not be saved.');
    }

    $_SESSION['user_id'] = $callerId; // logUpdate() reads the session
    logUpdate('Roles', $role['role_name'], 'ROLE#' . $roleId, $callerId);
}

$permStmt = $pdo->query('SELECT permission_id, page_key, page_name, module_name, description FROM permissions ORDER BY COALESCE(module_name, "Other"), page_name');
$allPermissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);

$grantStmt = $pdo->prepare('SELECT permission_id, can_view, can_create, can_edit, can_delete FROM role_permissions WHERE role_id = ?');
$grantStmt->execute([$roleId]);
$grants = [];
foreach ($grantStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $grants[(int) $g['permission_id']] = $g;
}

$rows = array_map(
    fn(array $p) => vk_api_role_permission_row($p, $grants[(int) $p['permission_id']] ?? []),
    $allPermissions
);

vk_api_ok([
    'role'         => vk_api_role_row($role),
    'is_protected' => $roleId === VK_API_PROTECTED_ROLE_ID,
    'permissions'  => $rows,
]);
