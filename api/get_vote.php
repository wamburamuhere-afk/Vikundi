<?php
// api/get_vote.php — one vote + its options (for the edit modal). Leadership only.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

header('Content-Type: application/json');

if (!isAdmin() && !canView('manage_voting')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

try {
    $v = $pdo->prepare("SELECT * FROM votes WHERE id = ?");
    $v->execute([$id]);
    $vote = $v->fetch(PDO::FETCH_ASSOC);
    if (!$vote) { echo json_encode(['success' => false, 'message' => 'Vote not found.']); exit; }

    $o = $pdo->prepare("SELECT id, label, member_id, position FROM vote_options WHERE vote_id = ? ORDER BY position, id");
    $o->execute([$id]);
    $vote['options'] = $o->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'vote' => $vote]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
