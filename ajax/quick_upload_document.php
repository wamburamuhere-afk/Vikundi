<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Quick-upload a document from the e-sign wizard (Sign Document → Upload New Document tab).
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!canCreate('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to upload documents');
    }

    if (empty($_FILES['document_file'])) {
        throw new Exception('No file selected');
    }

    // Use __DIR__-relative path so the save always lands in uploads/document_library/
    // regardless of which working directory the front controller uses.
    // Vikundi stores all documents in uploads/document_library/ (same as document_library.php).
    $upload_dir = __DIR__ . '/../uploads/document_library/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    $file      = $_FILES['document_file'];
    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $max_size  = 50 * 1024 * 1024; // 50 MB

    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception('Invalid file type. Allowed: PDF, Word, Excel, PNG, JPG');
    }

    if ($file['size'] > $max_size) {
        throw new Exception('File size exceeds 50 MB limit');
    }

    $filename    = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $target_path = $upload_dir . $filename;
    $db_path     = 'uploads/document_library/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception('Failed to save uploaded file');
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents
            (document_name, description, file_path, original_filename, file_size,
             file_type, category_id, version, uploaded_by, access_level)
        VALUES (?, ?, ?, ?, ?, ?, ?, '1.0', ?, 'private')
    ");
    $stmt->execute([
        $_POST['document_name']                                     ?? $file['name'],
        $_POST['description']                                       ?? '',
        $db_path,
        $file['name'],
        $file['size'],
        $ext,
        !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        (int) $_SESSION['user_id'],
    ]);

    $docId = (int) $pdo->lastInsertId();

    if (function_exists('logActivity')) {
        logActivity(
            'Created', 'Documents',
            'Quick-uploaded document: ' . ($_POST['document_name'] ?? $file['name']),
            'DOC#' . $docId,
            (int) $_SESSION['user_id']
        );
    }

    echo json_encode([
        'success'       => true,
        'document_id'   => $docId,
        'document_name' => $_POST['document_name'] ?? $file['name'],
        'file_path'     => $db_path,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
