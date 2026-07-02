<?php
// actions/save_meeting.php — create or update a meeting (+ optional documents).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3: must be logged in
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6: valid CSRF token
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/meeting_helpers.php';

header('Content-Type: application/json');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$meeting_id = isset($_POST['meeting_id']) && ctype_digit((string) $_POST['meeting_id']) ? (int) $_POST['meeting_id'] : 0;

// audit H3: authorization — create vs edit.
requirePermissionJson($meeting_id > 0 ? 'edit' : 'create', 'meetings');

// If an upload exceeds the server's post_max_size, PHP silently discards the
// ENTIRE $_POST and $_FILES (the request still runs). Detect that and report the
// real problem instead of a misleading "field required".
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $limit = ini_get('post_max_size') ?: '?';
    echo json_encode(['success' => false, 'message' => $is_sw
        ? "Nyaraka ulizoambatisha ni kubwa mno (ukomo wa seva ni $limit). Tafadhali tumia faili ndogo zaidi."
        : "The attached document(s) are too large (server limit is $limit). Please attach smaller files."]);
    exit;
}

$errors = vk_meeting_input_errors($_POST, $is_sw);
if ($errors) {
    echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
    exit;
}

$title    = trim($_POST['title']);
$date     = trim($_POST['meeting_date']);
$time     = trim($_POST['meeting_time'] ?? '') ?: null;
$location = trim($_POST['location'] ?? '') ?: null;
$type     = vk_normalize_meeting_type($_POST['meeting_type'] ?? 'regular');
$agenda   = trim($_POST['agenda'] ?? '') ?: null;
$minutes  = trim($_POST['minutes'] ?? '') ?: null;
$status   = vk_normalize_meeting_status($_POST['status'] ?? 'scheduled');

try {
    if ($meeting_id > 0) {
        $pdo->prepare("UPDATE meetings SET title=?, meeting_date=?, meeting_time=?, location=?, meeting_type=?, agenda=?, minutes=?, status=? WHERE id=?")
            ->execute([$title, $date, $time, $location, $type, $agenda, $minutes, $status, $meeting_id]);
        $id = $meeting_id;
        logUpdate('Meetings', $title, "MEETING#$id");
    } else {
        $pdo->prepare("INSERT INTO meetings (title, meeting_date, meeting_time, location, meeting_type, agenda, minutes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$title, $date, $time, $location, $type, $agenda, $minutes, $status, $user_id]);
        $id = (int) $pdo->lastInsertId();
        logCreate('Meetings', $title, "MEETING#$id");
    }

    // ── Supporting documents → Document Library, linked to this meeting ──
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/document_library/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
        $names = $_POST['attachment_names'] ?? [];

        // Find or create the "Meeting" document category.
        $cat = $pdo->prepare("SELECT id FROM document_categories WHERE category_name = ?");
        $cat->execute(['Meeting']);
        $category_id = $cat->fetchColumn();
        if (!$category_id) {
            $pdo->prepare("INSERT INTO document_categories (category_name, category_name_sw, color) VALUES (?,?,?)")
                ->execute(['Meeting', 'Mkutano', '#0d6efd']);
            $category_id = $pdo->lastInsertId();
        }

        foreach ($_FILES['attachments']['name'] as $i => $filename) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $size = (int) $_FILES['attachments']['size'][$i];
            if (!in_array($ext, $allowed, true) || $size > 10 * 1024 * 1024) continue;

            $stored = uniqid('mtg_') . '.' . $ext;
            if (!move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $upload_dir . $stored)) continue;

            $custom = !empty($names[$i]) ? $names[$i] : ("Meeting Doc - " . $title);
            $pdo->prepare("INSERT INTO documents (
                document_name, description, file_path, original_filename,
                file_size, file_type, category_id, access_level, uploaded_by, tags,
                related_type, related_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'private', ?, ?, 'meeting', ?)")
            ->execute([
                $custom,
                "Document for meeting: $title (Meeting ID: $id)",
                'uploads/document_library/' . $stored,
                $filename, $size, $ext, $category_id, $user_id,
                "Meeting, $title", $id,
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'id' => $id,
        'message' => $is_sw ? 'Mkutano umehifadhiwa.' : 'Meeting saved.',
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
