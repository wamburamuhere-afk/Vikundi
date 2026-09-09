<?php
/**
 * GET /api/v1/roles — the group's roles — ADMIN/CHAIRPERSON ONLY
 *
 * Mirrors app/constant/settings/user_roles.php's list. Read-only: role
 * create/rename/delete (user_roles.php's own save_role/delete_role, a second,
 * less-refined permission editor than manage_permissions.php) was never in
 * todo.md's plan and stays out of scope — see includes/api_settings.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);

$rows = $pdo->query('
    SELECT r.role_id, r.role_name, r.description, COUNT(u.user_id) AS user_count
      FROM roles r
      LEFT JOIN users u ON u.role_id = r.role_id
     GROUP BY r.role_id, r.role_name, r.description
     ORDER BY r.role_name
')->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok(['roles' => array_map('vk_api_role_row', $rows)]);
