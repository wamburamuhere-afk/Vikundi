<?php
// actions/update_contribution.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? null;
    
    if (empty($id) || empty($status)) {
        echo json_encode(['success' => false, 'message' => 'Missing ID or Status']);
        exit;
    }
    
    // Check if user is admin
    // For now simple check
    
    try {
        $stmt = $pdo->prepare("UPDATE contributions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE contribution_id = ?");
        $stmt->execute([$status, $id]);

        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $action_label = ucfirst($status); // 'Approved', 'Rejected', etc.
        $desc = $lang === 'sw'
            ? "Mchango #$id umewekwa hali ya: $status"
            : "Contribution #$id status changed to: $status";
        logActivity($action_label, 'Contributions', $desc, "CONTRIB#$id");
        // ─────────────────────────────────────────────────────────────────────

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
