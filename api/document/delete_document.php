<?php
require_once __DIR__ . '/../../roots.php';
// Converted to the central guard: the old `throw new Exception('Unauthorized')`
// inside the try was caught below and echoed with HTTP 200, so an anonymous caller
// was refused but told "success" at the protocol level. require_auth emits a real
// 401 and exits. The admin-or-owner authorisation further down is unchanged — only
// the authentication step moved.
require_once __DIR__ . '/../../includes/require_auth.php';
global $pdo;

header('Content-Type: application/json');

try {
    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;

    if ($document_id <= 0) {
        throw new Exception('Invalid document ID');
    }

    // Fetch document details
    $stmt = $pdo->prepare("SELECT file_path, uploaded_by FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        throw new Exception('Document not found');
    }

    // Check permissions - Admin can delete any file, others can only delete their own
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin';
    $isOwner = $document['uploaded_by'] == $_SESSION['user_id'];

    if (!$isAdmin && !$isOwner) {
        throw new Exception('Permission denied. You can only delete your own documents.');
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Delete related records first
        $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$document_id]);
        
        // Delete the document record
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

        // Commit transaction
        $pdo->commit();

        // Delete physical file after successful database deletion
        if (!empty($document['file_path']) && file_exists($document['file_path'])) {
            @unlink($document['file_path']); // Use @ to suppress warnings if file doesn't exist
        }

        echo json_encode([
            'success' => true,
            'message' => 'Document deleted successfully'
        ]);

    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
