<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Deletes one of the current user's signatures (DB record + stored files).
require_once __DIR__ . '/../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    $signature_id = $_POST['id'] ?? 0;
    
    if (!$signature_id) {
        throw new Exception('Signature ID is required');
    }
    
    // Get signature details first
    $stmt = $pdo->prepare("SELECT file_path, thumbnail_path FROM user_signatures WHERE id = ? AND user_id = ?");
    $stmt->execute([$signature_id, $_SESSION['user_id']]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$signature) {
        throw new Exception("Signature not found or you don't have permission to delete it");
    }

    // Stored paths are web paths (e.g. /uploads/...). Resolve to the filesystem before unlinking.
    foreach (['file_path', 'thumbnail_path'] as $pathKey) {
        if (!empty($signature[$pathKey])) {
            $abs = ROOT_DIR . '/' . ltrim($signature[$pathKey], '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    // Delete database record
    $stmt = $pdo->prepare("DELETE FROM user_signatures WHERE id = ?");
    $stmt->execute([$signature_id]);

    if (function_exists('logActivity')) {
        logActivity('Deleted', 'E-Signatures', 'Deleted an electronic signature', 'SIG#' . (int) $signature_id, (int) $_SESSION['user_id']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Signature deleted successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
