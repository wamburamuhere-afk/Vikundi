<?php
// api/get_meeting_details.php — one meeting record (used to prefill the edit modal).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
global $pdo;

header('Content-Type: application/json');

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meeting) {
        echo json_encode(['success' => false, 'message' => 'Meeting not found.']);
        exit;
    }
    echo json_encode(['success' => true, 'meeting' => $meeting]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
