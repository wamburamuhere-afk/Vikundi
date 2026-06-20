<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/activity_logger.php';

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $id = intval($_POST['template_id'] ?? 0);

    if ($id > 0 && !canEdit('document_templates')) {
        http_response_code(403);
        throw new Exception('Access denied: cannot edit document templates');
    }
    if ($id === 0 && !canCreate('document_templates')) {
        http_response_code(403);
        throw new Exception('Access denied: cannot create document templates');
    }

    $name        = trim($_POST['template_name'] ?? '');
    $categoryId  = intval($_POST['category_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $userId      = intval($_SESSION['user_id'] ?? 0);

    if ($name === '') throw new Exception('Template name is required');

    $templateType = trim($_POST['template_type'] ?? 'uploaded');
    if (!in_array($templateType, ['uploaded', 'html', 'built_in'])) {
        $templateType = 'uploaded';
    }

    $filePath = null;
    $fileType = $templateType;

    if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = ROOT_DIR . '/uploads/document_templates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $original = basename($_FILES['template_file']['name']);
        $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'odt', 'ods'];
        if (!in_array($ext, $allowed)) {
            throw new Exception('File type not allowed. Allowed: ' . implode(', ', $allowed));
        }
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
        if (move_uploaded_file($_FILES['template_file']['tmp_name'], $uploadDir . $filename)) {
            $filePath = 'uploads/document_templates/' . $filename;
            $fileType = 'uploaded';
        }
    }

    if ($id > 0) {
        if ($filePath) {
            $stmt = $pdo->prepare("UPDATE document_templates
                                      SET template_name=?, category_id=?, file_path=?, file_type=?,
                                          description=?, is_active=?, updated_at=NOW()
                                    WHERE id=?");
            $stmt->execute([$name, $categoryId, $filePath, $fileType, $description, $isActive, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE document_templates
                                      SET template_name=?, category_id=?, description=?, is_active=?, updated_at=NOW()
                                    WHERE id=?");
            $stmt->execute([$name, $categoryId, $description, $isActive, $id]);
        }
        logUpdate('Document Templates', $name, 'TMPL#' . $id);
        echo json_encode(['success' => true, 'message' => 'Template updated successfully']);
    } else {
        $stmt = $pdo->prepare("INSERT INTO document_templates
                                   (template_name, category_id, file_path, file_type, description, is_active, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $categoryId, $filePath, $fileType, $description, $isActive, $userId]);
        $newId = $pdo->lastInsertId();
        logCreate('Document Templates', $name, 'TMPL#' . $newId);
        echo json_encode(['success' => true, 'message' => 'Template saved successfully']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
