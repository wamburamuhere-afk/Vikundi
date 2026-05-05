<?php
// File: actions/delete_death_expense.php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$id = $_POST['id'] ?? 0;

try {
    if (!$id) throw new Exception("Invalid ID provided.");

    // Check if it's already approved
    $stmt = $pdo->prepare("SELECT status FROM death_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();

    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

    if ($status === 'approved') {
        $err = $is_sw ? "Huwezi kufuta gharama iliyokwisha idhinishwa." : "You cannot delete an expense that has already been approved.";
        throw new Exception($err);
    }

    $stmt = $pdo->prepare("DELETE FROM death_expenses WHERE id = ?");
    $stmt->execute([$id]);

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw ? "Gharama ya msiba #$id imefutwa" : "Death expense #$id deleted";
    logDelete('Death Expenses', "DEATH#$id", "DEATH#$id");
    // ─────────────────────────────────────────────────────────────────────────

    $msg = $is_sw ? 'Kumbukumbu imefutwa kikamilifu.' : 'Record deleted successfully.';
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
