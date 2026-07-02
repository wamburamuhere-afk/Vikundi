<?php
// actions/generate_absence_fines.php — fine members marked absent at a meeting.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/meeting_helpers.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'meetings'); // audit H3 — leadership only

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$meeting_id = isset($_POST['meeting_id']) && ctype_digit((string) $_POST['meeting_id']) ? (int) $_POST['meeting_id'] : 0;
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0.0;

if ($meeting_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Mkutano haujapatikana.' : 'Meeting not found.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Weka kiasi sahihi cha faini.' : 'Enter a valid fine amount.']);
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

    $absentees = $pdo->prepare("SELECT member_id FROM meeting_attendance WHERE meeting_id = ? AND status = 'absent'");
    $absentees->execute([$meeting_id]);
    $ids = $absentees->fetchAll(PDO::FETCH_COLUMN);

    $reason = vk_meeting_fine_reason($meeting, $is_sw);
    $dup = $pdo->prepare("SELECT COUNT(*) FROM fines WHERE customer_id = ? AND meeting_id = ?");
    $ins = $pdo->prepare("INSERT INTO fines (customer_id, amount, reason, status, meeting_id, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())");

    $created = 0; $skipped = 0;
    foreach ($ids as $mid) {
        $dup->execute([$mid, $meeting_id]);
        if ((int) $dup->fetchColumn() > 0) { $skipped++; continue; }
        $ins->execute([$mid, $amount, $reason, $meeting_id]);
        $created++;
    }

    // Remember the amount as the default for next time.
    $pdo->prepare("INSERT INTO group_settings (setting_key, setting_value) VALUES ('meeting_absence_fine', :v)
                   ON DUPLICATE KEY UPDATE setting_value = :v2")
        ->execute([':v' => $amount, ':v2' => $amount]);

    logCreate('Fines', "Absence fines: $created", "MEETING#$meeting_id");

    $msg = $is_sw
        ? "Faini zimetengenezwa kwa wanachama $created."
        : "Created fines for $created member(s).";
    if ($skipped > 0) $msg .= $is_sw ? " ($skipped tayari walikuwa na faini.)" : " ($skipped already fined.)";

    echo json_encode(['success' => true, 'message' => $msg, 'created' => $created, 'skipped' => $skipped]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
