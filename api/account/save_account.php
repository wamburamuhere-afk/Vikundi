<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $account_id = $_POST['account_id'] ?? '';
    $account_code = $_POST['account_code'] ?? '';
    $account_name = $_POST['account_name'] ?? '';
    $account_type_name = $_POST['account_type'] ?? '';
    $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
    $description = $_POST['description'] ?? '';
    $opening_balance = !empty($_POST['opening_balance']) ? $_POST['opening_balance'] : 0;
    $status = $_POST['status'] ?? 'active';
    
    // Handle Sub-Account
    $is_sub_account = isset($_POST['is_sub_account']);
    $parent_account_id = $is_sub_account && !empty($_POST['parent_account_id']) ? $_POST['parent_account_id'] : null;

    if (empty($account_code) || empty($account_name) || empty($account_type_name)) {
        throw new Exception('Required fields missing');
    }

    // Resolve Account Type ID
    $stmt = $pdo->prepare("SELECT type_id FROM account_types WHERE type_name = ?");
    $stmt->execute([$account_type_name]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$type) {
        // Fallback: Try to map display name or assume type_name matches
        // Or if types are static, maybe handle that. For now assume type_name matches DB.
        throw new Exception("Invalid account type: " . $account_type_name);
    }
    $account_type_id = $type['type_id'];

    if (!empty($account_id)) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE accounts SET 
                account_code = ?, 
                account_name = ?, 
                account_type_id = ?, 
                category_id = ?, 
                description = ?, 
                opening_balance = ?,
                parent_account_id = ?,
                status = ?,
                updated_at = NOW()
            WHERE account_id = ?
        ");
        $stmt->execute([
            $account_code, 
            $account_name, 
            $account_type_id, 
            $category_id, 
            $description, 
            $opening_balance,
            $parent_account_id,
            $status,
            $account_id
        ]);
        $message = 'Account updated successfully';
    } else {
        // Insert
        // Check for duplicate code
        $check = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE account_code = ?");
        $check->execute([$account_code]);
        if ($check->fetchColumn() > 0) {
            throw new Exception("Account code '$account_code' already exists.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO accounts (
                account_code, 
                account_name, 
                account_type_id, 
                category_id, 
                description, 
                opening_balance, 
                current_balance,
                parent_account_id, 
                status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $account_code, 
            $account_name, 
            $account_type_id, 
            $category_id, 
            $description, 
            $opening_balance, 
            $opening_balance, // Initial current balance = opening balance
            $parent_account_id, 
            $status
        ]);
        $message = 'Account created successfully';
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
