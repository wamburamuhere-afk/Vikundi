<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$member_id = $_POST['member_id'] ?? 0;
$deceased_info = $_POST['deceased_info'] ?? ''; // Format: type|id|name|relationship
$amount = $_POST['amount'] ?? 0;
$description = $_POST['description'] ?? '';
$expense_date = $_POST['expense_date'] ?? date('Y-m-d');

try {
    if (empty($member_id) || empty($deceased_info) || empty($amount)) {
        throw new Exception("Tafadhali jaza taarifa zote zinazohitajika.");
    }

    $parts = explode('|', $deceased_info);
    if (count($parts) < 3) {
        throw new Exception("Taarifa za mfiwa hazijakamilika.");
    }

    $deceased_type = $parts[0];
    $deceased_id = $parts[1];
    $deceased_name = $parts[2];
    $deceased_relationship = $parts[3] ?? 'Mtegemezi';

    // GUARANTEE: If ID is member, type MUST be mwanachama
    if ($deceased_id === 'member' && empty($deceased_type)) {
        $deceased_type = 'mwanachama';
    }

    // Get member phone for record keeping
    $stmt = $pdo->prepare("SELECT phone FROM customers WHERE customer_id = ?");
    $stmt->execute([$member_id]);
    $phone = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO death_expenses (member_id, phone_number, deceased_type, deceased_id, deceased_name, deceased_relationship, amount, description, expense_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    
    $user_id = $_SESSION['user_id'] ?? 0;
    
    $stmt->execute([
        $member_id,
        $phone,
        $deceased_type,
        $deceased_id,
        $deceased_name,
        $deceased_relationship,
        $amount,
        $description,
        $expense_date,
        $user_id
    ]);

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
                    $custom_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : "Death Assistance Doc - " . $deceased_name;
                    $new_filename = uniqid('doc_') . '.' . $file_ext;
                    $target_file = $upload_dir . $new_filename;
                    $db_path = 'uploads/document_library/' . $new_filename;

                    if (move_uploaded_file($tmp_name, $target_file)) {
                        // 1. Find or create the "Death Assistance" category
                        $cat_name = "Death Assistance";
                        $cat_name_sw = "Misaada ya Misiba";
                        $stmt_cat = $pdo->prepare("SELECT id FROM document_categories WHERE category_name = ?");
                        $stmt_cat->execute([$cat_name]);
                        $category_id = $stmt_cat->fetchColumn();

                        if (!$category_id) {
                            $stmt_new_cat = $pdo->prepare("INSERT INTO document_categories (category_name, category_name_sw, color) VALUES (?, ?, ?)");
                            $stmt_new_cat->execute([$cat_name, $cat_name_sw, '#dc3545']);
                            $category_id = $pdo->lastInsertId();
                        }

                        // 2. Insert into documents table for Library
                        $stmt_doc = $pdo->prepare("INSERT INTO documents (
                            document_name, description, file_path, original_filename, 
                            file_size, file_type, category_id, access_level, uploaded_by, tags
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'private', ?, ?)");
                        
                        $stmt_doc->execute([
                            $custom_name,
                            "Attached to death assistance record for $deceased_name (Member ID: $member_id)",
                            $db_path,
                            $filename,
                            $file_size,
                            $file_ext,
                            $category_id,
                            $user_id,
                            "Death, Expense, $deceased_name"
                        ]);
                    }
                }
            }
        }
    }

    // ── AUTO-MOVE MEMBER TO DORMANT ON SUBMISSION (backup — main logic is on approval) ──
    // deceased_type is 'mwanachama' when the main member is the deceased
    if (strtolower($deceased_type) === 'mwanachama') {
        // Mark as deceased immediately on submission
        $stmt_cust = $pdo->prepare("UPDATE customers SET is_deceased = 1 WHERE customer_id = ?");
        $stmt_cust->execute([$member_id]);
        // Note: Full dormant status is applied on approval via approve_death_expense.php
    }

    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $msg = $is_sw ? 'Taarifa za msiba zimehifadhiwa na zinasubiri idhini.' : 'Death assistance recorded and awaiting approval.';

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $log_desc = $is_sw
        ? "Gharama ya msiba imewasilishwa kwa: $deceased_name (TZS " . number_format($amount, 2) . ")"
        : "Death expense submitted for: $deceased_name (TZS " . number_format($amount, 2) . ")";
    logCreate('Death Expenses', $deceased_name, "DEATH#$new_id");
    // ─────────────────────────────────────────────────────────────────────────

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
