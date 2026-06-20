<?php
/**
 * Email Templates — delete endpoint (comms > Email Templates)
 * Session + RBAC gated, prepared statements, audit logging.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/email_helper.php';

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

function tpl_del_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    tpl_del_fail($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tpl_del_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
}
if (!canDelete('message_center')) {
    tpl_del_fail($is_sw ? 'Huna ruhusa ya kufuta.' : 'You do not have permission to delete.', 403);
}

$user_id = (int)$_SESSION['user_id'];
$id      = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    tpl_del_fail($is_sw ? 'Kitambulisho si sahihi.' : 'Invalid id.');
}

try {
    email_ensure_templates_table($pdo);

    $stmt = $pdo->prepare("SELECT template_name FROM email_templates WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        tpl_del_fail($is_sw ? 'Kiolezo hakipatikani.' : 'Template not found.', 404);
    }

    $pdo->prepare("DELETE FROM email_templates WHERE id = ?")->execute([$id]);
    logDelete('Email Templates', $row['template_name'], 'EMAILTPL#' . $id, $user_id);

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Kiolezo kimefutwa.' : 'Template deleted.']);
} catch (Throwable $e) {
    error_log('delete_email_template: ' . $e->getMessage());
    tpl_del_fail(($is_sw ? 'Hitilafu: ' : 'Error: ') . $e->getMessage(), 500);
}
