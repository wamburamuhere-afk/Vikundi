<?php
// actions/save_vote.php — create or edit a DRAFT vote and its options.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/vote_helpers.php';

header('Content-Type: application/json');
requirePermissionJson($_POST['vote_id'] ?? '' ? 'edit' : 'create', 'manage_voting'); // audit H3

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$vote_id = isset($_POST['vote_id']) && ctype_digit((string) $_POST['vote_id']) ? (int) $_POST['vote_id'] : 0;

$type   = vk_normalize_vote_type($_POST['vote_type'] ?? 'candidate');
$labels = array_map('trim', (array) ($_POST['option_labels'] ?? []));
$mids   = (array) ($_POST['option_member_ids'] ?? []);

$errors = vk_vote_input_errors($_POST, $labels, $is_sw);
if ($errors) {
    echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
    exit;
}

$title       = trim($_POST['title']);
$description = trim($_POST['description'] ?? '') ?: null;
$closes_at   = trim($_POST['closes_at'] ?? '');
$closes_at   = $closes_at !== '' ? date('Y-m-d H:i:s', strtotime($closes_at)) : null;
$publish     = !empty($_POST['publish_results']) ? 1 : 0;

// Build the option list: a motion always uses fixed Yes/No/Abstain.
$options = [];
if ($type === 'motion') {
    foreach (vk_default_motion_options() as $l) $options[] = ['label' => $l, 'member_id' => null];
} else {
    foreach ($labels as $i => $l) {
        if ($l === '') continue;
        $mid = (isset($mids[$i]) && ctype_digit((string) $mids[$i]) && (int) $mids[$i] > 0) ? (int) $mids[$i] : null;
        $options[] = ['label' => $l, 'member_id' => $mid];
    }
}

try {
    if ($vote_id > 0) {
        $cur = $pdo->prepare("SELECT status FROM votes WHERE id = ?");
        $cur->execute([$vote_id]);
        $status = $cur->fetchColumn();
        if ($status === false) {
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura haijapatikana.' : 'Vote not found.']);
            exit;
        }
        if ($status !== 'draft') {
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura iliyofunguliwa haiwezi kuhaririwa.' : 'An opened vote can no longer be edited.']);
            exit;
        }
        $pdo->prepare("UPDATE votes SET title=?, description=?, vote_type=?, closes_at=?, publish_results=? WHERE id=?")
            ->execute([$title, $description, $type, $closes_at, $publish, $vote_id]);
        $pdo->prepare("DELETE FROM vote_options WHERE vote_id = ?")->execute([$vote_id]);
        $id = $vote_id;
        logUpdate('Voting', $title, "VOTE#$id");
    } else {
        $pdo->prepare("INSERT INTO votes (title, description, vote_type, status, closes_at, publish_results, created_by) VALUES (?,?,?,'draft',?,?,?)")
            ->execute([$title, $description, $type, $closes_at, $publish, $user_id]);
        $id = (int) $pdo->lastInsertId();
        logCreate('Voting', $title, "VOTE#$id");
    }

    $ins = $pdo->prepare("INSERT INTO vote_options (vote_id, label, member_id, position) VALUES (?,?,?,?)");
    foreach ($options as $pos => $o) {
        $ins->execute([$id, $o['label'], $o['member_id'], $pos]);
    }

    echo json_encode(['success' => true, 'id' => $id, 'message' => $is_sw ? 'Kura imehifadhiwa.' : 'Vote saved.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
