<?php
// actions/import_contributions.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../roots.php'; 
}

require_once __DIR__ . '/../includes/config.php';

// Access Control
if (!isset($_SESSION['user_id'])) {
    // Attempt fallback if routing is different
    if (!isset($_SESSION['id'])) {
        die("Unauthorized access. Please login first.");
    } else {
        $_SESSION['user_id'] = $_SESSION['id'];
    }
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $upload_type = $_POST['upload_type'] ?? 'existing_report';
    $file = $_FILES['upload_file']['tmp_name'];
    
    if (!is_uploaded_file($file)) {
        $response['message'] = "No file uploaded.";
        echo json_encode($response);
        exit;
    }

    $handle = fopen($file, "r");
    if (!$handle) {
        $response['message'] = "Unable to read file.";
        echo json_encode($response);
        exit;
    }

    $imported = 0;
    $errors = [];
    $row_count = 0;

    // Headers
    $headers = fgetcsv($handle);
    $phone_idx = -1;
    $amount_idx = -1;

    // Mapping logic
    foreach ($headers as $idx => $header) {
        $header = strtolower(trim($header));
        if (in_array($header, ['phone', 'simu', 'namba', 'namba ya simu', 'id', 'member id', 'member_id'])) {
            $phone_idx = $idx;
        }
        if (in_array($header, ['amount', 'kiasi', 'balance', 'total'])) {
            $amount_idx = $idx;
        }
    }

    // Default mapping if headers not perfectly matched
    if ($phone_idx === -1) $phone_idx = 0; // Assume col 1
    if ($amount_idx === -1) $amount_idx = 1; // Assume col 2

    try {
        $pdo->beginTransaction();
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            $row_count++;
            $identifier = trim($data[$phone_idx] ?? '');
            $amount = floatval(preg_replace('/[^0-9.]/', '', $data[$amount_idx] ?? '0'));

            // Robust Identification logic (Phone or ID)
            $clean_id = preg_replace('/[^0-9]/', '', $identifier); // e.g. 255712345678 or 07123...
            $short_phone = substr($clean_id, -9); // Last 9 digits are common (712345678)

            $stmt = $pdo->prepare("
                SELECT customer_id FROM customers 
                WHERE (
                    phone = ? OR phone LIKE ? OR customer_id = ?
                ) AND status = 'active' LIMIT 1
            ");
            $stmt->execute([$identifier, "%$short_phone", $identifier]);
            $member_id = $stmt->fetchColumn();

            if ($member_id) {
                // Insert contribution
                $stmt_ins = $pdo->prepare("INSERT INTO contributions (member_id, amount, contribution_date, description, status, contribution_type, created_at) VALUES (?, ?, CURRENT_DATE, ?, 'confirmed', 'bulk', NOW())");
                $desc = ($upload_type === 'mkoba_statement') ? 'Imported from M-Koba' : 'Bulk Import from Report';
                $stmt_ins->execute([$member_id, $amount, $desc]);
                $imported++;
            } else {
                $errors[] = "Row $row_count: Member not found or inactive ($identifier)";
            }
        }
        
        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Successfully imported $imported records. " . (count($errors) > 0 ? count($errors) . " records failed." : "");
        if (count($errors) > 0) {
            $response['errors'] = array_slice($errors, 0, 10); // Return first 10 errors
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = "Database error: " . $e->getMessage();
    }
    
    fclose($handle);
} else {
    $response['message'] = "Invalid request.";
}

// Redirect back with message
$redirect_url = $_SERVER['HTTP_REFERER'] ?? '../app/bms/customer/manage_contributions.php';
$_SESSION['import_response'] = $response;
header("Location: $redirect_url");
exit;
