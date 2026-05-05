<?php
// api/delete_document.php
// Suppress ALL PHP notices/warnings to prevent JSON corruption
error_reporting(0);
ini_set('display_errors', 0);

// Clean any previous output buffers
while (ob_get_level()) ob_end_clean();
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Haujaingia. Please login.']);
        exit;
    }

    require_once __DIR__ . '/../includes/config.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
    if ($document_id <= 0) {
        throw new Exception("Missing document ID");
    }

    // Management roles that can delete any document
    $can_manage_roles = ['Admin', 'Chairman', 'Secretary', 'Katibu', 'Treasurer'];
    $can_manage = in_array($_SESSION['user_role'] ?? '', $can_manage_roles);

    // Fetch document info
    $stmt = $pdo->prepare("SELECT file_path, uploaded_by FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        throw new Exception("Document not found");
    }

    // Allow management roles or the original uploader to delete
    if (!$can_manage && $document['uploaded_by'] != $_SESSION['user_id']) {
        throw new Exception("Ruhusa imekataliwa. Permission denied.");
    }

    // Delete physical file — try multiple path resolutions
    $paths_to_try = [
        $document['file_path'],                              // absolute stored path
        __DIR__ . '/../' . $document['file_path'],           // relative from api dir
        $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($document['file_path'], '/'), // from docroot
    ];
    foreach ($paths_to_try as $p) {
        if (file_exists($p)) { @unlink($p); break; }
    }

    // Remove download logs then the document record
    $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$document_id]);
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

    // Log activity if helper exists
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], 'Delete Document', "Deleted document ID: $document_id", 'Documents');
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);

} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
