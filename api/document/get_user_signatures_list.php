<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Returns the current user's active signatures as a flat JSON array for the picker grids.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    $stmt = $pdo->prepare("
        SELECT id, signature_type, file_path, thumbnail_path
        FROM user_signatures
        WHERE user_id = ? AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $signatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure the picker always has an image source even if no thumbnail was generated.
    foreach ($signatures as &$sig) {
        if (empty($sig['thumbnail_path']) && !empty($sig['file_path'])) {
            $sig['thumbnail_path'] = $sig['file_path'];
        }
    }
    unset($sig);

    echo json_encode($signatures);

} catch (Exception $e) {
    echo json_encode([]);
}
