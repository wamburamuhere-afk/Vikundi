<?php
// actions/send_meeting_reminder.php — SMS active members a meeting reminder.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/meeting_helpers.php';
require_once __DIR__ . '/../includes/sms_helper.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'meetings'); // audit H3 — leadership only

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$meeting_id = isset($_POST['meeting_id']) && ctype_digit((string) $_POST['meeting_id']) ? (int) $_POST['meeting_id'] : 0;

if ($meeting_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
    exit;
}

try {
    $mstmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $mstmt->execute([$meeting_id]);
    $meeting = $mstmt->fetch(PDO::FETCH_ASSOC);
    if (!$meeting) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
        exit;
    }

    $rows = $pdo->query("
        SELECT phone FROM customers
         WHERE (status IS NULL OR status <> 'deleted') AND COALESCE(is_deceased, 0) = 0
           AND phone IS NOT NULL AND phone <> ''
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!$rows) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Hakuna wanachama wenye namba za simu.' : 'No members with phone numbers.']);
        exit;
    }

    $message = vk_meeting_reminder_message($meeting, $is_sw);
    $sent = 0; $failed = 0;
    foreach ($rows as $phone) {
        $r = send_sms($phone, $message);
        if (!empty($r['success'])) { $sent++; } else { $failed++; }
    }

    logCreate('Meetings', "SMS reminder: $sent sent", "MEETING#$meeting_id");

    if ($sent === 0) {
        echo json_encode(['success' => false, 'message' => $is_sw
            ? "Hakuna SMS iliyotumwa (angalia mipangilio ya SMS)."
            : "No messages were sent (check the SMS gateway settings)."]);
        exit;
    }

    $msg = $is_sw ? "SMS zimetumwa kwa wanachama $sent." : "Reminder sent to $sent member(s).";
    if ($failed > 0) $msg .= $is_sw ? " ($failed hazikutumwa.)" : " ($failed failed.)";

    echo json_encode(['success' => true, 'message' => $msg, 'sent' => $sent, 'failed' => $failed]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
