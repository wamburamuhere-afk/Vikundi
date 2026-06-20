<?php
/**
 * Email Templates — list endpoint (comms > Email Templates)
 * Returns { success, data[], stats } for the client-side DataTable on
 * email_templates.php and the Email Center compose "Use template" picker.
 * Session + RBAC gated (shares the message_center permission key).
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/email_helper.php';

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.', 'data' => [], 'stats' => []]);
    exit;
}
if (!canView('message_center')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Huna ruhusa.' : 'Permission denied.', 'data' => [], 'stats' => []]);
    exit;
}

try {
    email_ensure_templates_table($pdo);

    // Optional filter: active_only=1 (used by the compose picker).
    $active_only = !empty($_GET['active_only']);
    $where = $active_only ? 'WHERE is_active = 1' : '';

    $rows = $pdo->query("
        SELECT id, template_name, template_type, subject, content, is_active, created_at, updated_at
        FROM email_templates
        $where
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats = $pdo->query("
        SELECT COUNT(*) AS total, SUM(is_active = 1) AS active
        FROM email_templates
    ")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0];

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'stats'   => [
            'totalTemplates'  => (int)$stats['total'],
            'activeTemplates' => (int)$stats['active'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('get_email_templates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => ($is_sw ? 'Hitilafu: ' : 'Error: ') . $e->getMessage(), 'data' => [], 'stats' => []]);
}
