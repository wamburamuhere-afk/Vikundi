<?php
// File: api/get_member_death_history.php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$member_id = $_GET['member_id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT deceased_relationship FROM death_expenses WHERE member_id = ? AND status = 'approved'");
    $stmt->execute([$member_id]);
    $history = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'history' => $history]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
