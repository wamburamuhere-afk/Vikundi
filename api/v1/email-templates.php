<?php
/**
 * GET /api/v1/email-templates — the group's reusable email templates
 *
 * Mirrors api/get_email_templates.php exactly, including its permission key:
 * `view` on `message_center` (Email Templates deliberately shares that key —
 * api/get_email_templates.php's own comment confirms this, unlike the
 * message_center/sms_center reuse this module found no comment for).
 *
 * Read-only, matching todo.md's plan; create/edit/delete of templates was
 * never in scope here.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/email_helper.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'message_center');

email_ensure_templates_table($pdo);

$activeOnly = !empty($_GET['active_only']);
$where = $activeOnly ? 'WHERE is_active = 1' : '';

$rows = $pdo->query("
    SELECT id, template_name, template_type, subject, content, is_active, created_at, updated_at
      FROM email_templates
      {$where}
     ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("SELECT COUNT(*) AS total, SUM(is_active = 1) AS active FROM email_templates")
    ->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0];

vk_api_ok([
    'templates' => array_map(static function (array $r): array {
        return [
            'id'            => (int) $r['id'],
            'template_name' => (string) $r['template_name'],
            'template_type' => (string) $r['template_type'],
            'subject'       => (string) $r['subject'],
            'content'       => (string) $r['content'],
            'is_active'     => (bool) $r['is_active'],
            'created_at'    => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'updated_at'    => !empty($r['updated_at']) ? date(DATE_ATOM, strtotime((string) $r['updated_at'])) : null,
        ];
    }, $rows),
    'stats' => [
        'total_templates'  => (int) $stats['total'],
        'active_templates' => (int) $stats['active'],
    ],
]);
