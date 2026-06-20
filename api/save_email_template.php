<?php
/**
 * Email Templates — create/update endpoint (comms > Email Templates)
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

function tpl_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    tpl_fail($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tpl_fail($is_sw ? 'Njia si sahihi.' : 'POST required.', 405);
}

$user_id = (int)$_SESSION['user_id'];
$id      = (int)($_POST['id'] ?? 0);

// Create vs edit permission, both under the message_center key.
if ($id > 0 && !canEdit('message_center')) {
    tpl_fail($is_sw ? 'Huna ruhusa ya kuhariri.' : 'You do not have permission to edit.', 403);
}
if ($id === 0 && !canCreate('message_center')) {
    tpl_fail($is_sw ? 'Huna ruhusa ya kuunda.' : 'You do not have permission to create.', 403);
}

$name    = trim($_POST['template_name'] ?? '');
$type    = $_POST['template_type'] ?? 'general';
$subject = trim($_POST['subject'] ?? '');
$content = trim($_POST['content'] ?? '');
$active  = !empty($_POST['is_active']) ? 1 : 0;

if ($name === '')    tpl_fail($is_sw ? 'Jina la kiolezo linahitajika.' : 'Template name is required.');
if ($subject === '') tpl_fail($is_sw ? 'Mada inahitajika.' : 'Subject is required.');
if ($content === '') tpl_fail($is_sw ? 'Maudhui yanahitajika.' : 'Content is required.');
if (!array_key_exists($type, email_template_types())) {
    $type = 'general';
}

try {
    email_ensure_templates_table($pdo);

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE email_templates
            SET template_name = ?, template_type = ?, subject = ?, content = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $subject, $content, $active, $id]);
        logUpdate('Email Templates', $name, 'EMAILTPL#' . $id, $user_id);
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Kiolezo kimesasishwa.' : 'Template updated.', 'id' => $id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO email_templates (template_name, template_type, subject, content, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $type, $subject, $content, $active, $user_id]);
        $new_id = (int)$pdo->lastInsertId();
        logCreate('Email Templates', $name, 'EMAILTPL#' . $new_id, $user_id);
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Kiolezo kimehifadhiwa.' : 'Template saved.', 'id' => $new_id]);
    }
} catch (Throwable $e) {
    error_log('save_email_template: ' . $e->getMessage());
    tpl_fail(($is_sw ? 'Hitilafu: ' : 'Error: ') . $e->getMessage(), 500);
}
