<?php
// actions/delete_meeting.php — delete a meeting and its attendance rows.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('delete', 'meetings'); // audit H3

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$meeting_id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;

if ($meeting_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
    exit;
}

try {
    $row = $pdo->prepare("SELECT title FROM meetings WHERE id = ?");
    $row->execute([$meeting_id]);
    $title = $row->fetchColumn();
    if ($title === false) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
        exit;
    }

    $pdo->prepare("DELETE FROM meeting_attendance WHERE meeting_id = ?")->execute([$meeting_id]);
    $pdo->prepare("DELETE FROM meetings WHERE id = ?")->execute([$meeting_id]);

    logDelete('Meetings', $title, "MEETING#$meeting_id");

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mkutano umefutwa.' : 'Meeting deleted.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
