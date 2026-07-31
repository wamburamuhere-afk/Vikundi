<?php
// File: api/get_member_death_history.php
require_once __DIR__ . '/../includes/config.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('view', 'death_expenses');
global $pdo;

header('Content-Type: application/json');

$member_id = $_GET['member_id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT deceased_relationship FROM death_expenses WHERE member_id = ? AND status IN ('approved','paid')");
    $stmt->execute([$member_id]);
    $history = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'history' => $history]);
} catch (Exception $e) {
    error_log('get_member_death_history.php: ' . $e->getMessage()); // SEC-018
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
?>
