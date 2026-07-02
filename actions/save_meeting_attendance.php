<?php
// actions/save_meeting_attendance.php — record present/absent per member.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'meetings'); // audit H3

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$meeting_id = isset($_POST['meeting_id']) && ctype_digit((string) $_POST['meeting_id']) ? (int) $_POST['meeting_id'] : 0;

if ($meeting_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
    exit;
}

// Full roster submitted, plus the subset marked present.
$roster  = array_values(array_filter(array_map('intval', (array) ($_POST['member_ids'] ?? []))));
$present = array_flip(array_map('intval', (array) ($_POST['present'] ?? [])));

try {
    $meeting = $pdo->prepare("SELECT id FROM meetings WHERE id = ?");
    $meeting->execute([$meeting_id]);
    if (!$meeting->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
        exit;
    }

    $upsert = $pdo->prepare("
        INSERT INTO meeting_attendance (meeting_id, member_id, status, marked_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)
    ");

    $count = 0;
    foreach ($roster as $mid) {
        $status = isset($present[$mid]) ? 'present' : 'absent';
        $upsert->execute([$meeting_id, $mid, $status, $user_id]);
        $count++;
    }

    logUpdate('Meetings', "Attendance ($count)", "MEETING#$meeting_id");

    echo json_encode([
        'success' => true,
        'message' => $is_sw ? 'Mahudhurio yamehifadhiwa.' : 'Attendance saved.',
        'present' => count($present),
        'total'   => $count,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
