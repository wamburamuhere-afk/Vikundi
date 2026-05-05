<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$expense_date = $_POST['expense_date'] ?? date('Y-m-d');
$description = $_POST['description'] ?? '';
$amount = $_POST['amount'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;

try {
    if (empty($description) || empty($amount)) {
        throw new Exception("Tafadhali jaza maelezo na kiasi.");
    }

    $stmt = $pdo->prepare("INSERT INTO general_expenses (expense_date, description, amount, status, created_by) VALUES (?, ?, ?, 'pending', ?)");
    $stmt->execute([$expense_date, $description, $amount, $user_id]);
    $new_id = $pdo->lastInsertId();

    // ── ATTACHMENTS HANDLING ──
    if (!empty($_FILES['attachments']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/document_library/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
        $attachment_names = $_POST['attachment_names'] ?? [];

        foreach ($_FILES['attachments']['name'] as $i => $filename) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $file_size = $_FILES['attachments']['size'][$i];
                $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (in_array($file_ext, $allowed_types) && $file_size <= 10 * 1024 * 1024) {
                    $custom_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : "General Expense Doc - " . $description;
                    $new_filename = uniqid('exp_') . '.' . $file_ext;
                    $target_file = $upload_dir . $new_filename;
                    $db_path = 'uploads/document_library/' . $new_filename;

                    if (move_uploaded_file($tmp_name, $target_file)) {
                        // 1. Find or create the "General Expense" category
                        $cat_name = "General Expense";
                        $cat_name_sw = "Matumizi ya Kawaida";
                        $stmt_cat = $pdo->prepare("SELECT id FROM document_categories WHERE category_name = ?");
                        $stmt_cat->execute([$cat_name]);
                        $category_id = $stmt_cat->fetchColumn();

                        if (!$category_id) {
                            $stmt_new_cat = $pdo->prepare("INSERT INTO document_categories (category_name, category_name_sw, color) VALUES (?, ?, ?)");
                            $stmt_new_cat->execute([$cat_name, $cat_name_sw, '#0d6efd']);
                            $category_id = $pdo->lastInsertId();
                        }

                        // 2. Insert into documents table for Library
                        $stmt_doc = $pdo->prepare("INSERT INTO documents (
                            document_name, description, file_path, original_filename, 
                            file_size, file_type, category_id, access_level, uploaded_by, tags
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'private', ?, ?)");
                        
                        $stmt_doc->execute([
                            $custom_name,
                            "Receipt for expense: $description (Expense ID: $new_id)",
                            $db_path,
                            $filename,
                            $file_size,
                            $file_ext,
                            $category_id,
                            $user_id,
                            "General, Expense, Receipt"
                        ]);
                    }
                }
            }
        }
    }

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw
        ? "Matumizi ya kawaida yamewasilishwa: $description (TZS " . number_format($amount, 2) . ")"
        : "General expense submitted: $description (TZS " . number_format($amount, 2) . ")";
    logCreate('General Expenses', $description, "EXPENSE#$new_id");
    // ─────────────────────────────────────────────────────────────────────────

    $msg = $is_sw ? 'Matumizi yamehifadhiwa na yanasubiri idhini.' : 'General expense recorded and awaiting approval.';
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
