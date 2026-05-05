<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php'; // For permissions

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!canCreate('customers')) {
     echo json_encode(['success' => false, 'message' => 'Permission denied']);
     exit;
}

try {
    // Validate required fields
    if (empty($_POST['customer_name'])) {
        throw new Exception('Customer name is required');
    }
    
    // Check if phone already exists
    $phone = $_POST['phone'] ?? '';
    if (!empty($phone)) {
        $stmt_check = $pdo->prepare("SELECT customer_id FROM customers WHERE phone = ?");
        $stmt_check->execute([$phone]);
        if ($stmt_check->rowCount() > 0) {
            $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
            throw new Exception($is_sw ? 'Mwanachama mwenye namba hii ya simu tayari yupo.' : 'User with this phone number already exists.');
        }
    }

    // Generate Customer Code (if not provided or auto-generated)
    $stmt = $pdo->query("SELECT MAX(customer_id) FROM customers");
    $nextId = $stmt->fetchColumn() + 1;
    $customerCode = 'CUST-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

    // Prepare data
    $data = [
        'customer_code' => $customerCode,
        'customer_name' => $_POST['customer_name'],
        'company_name' => $_POST['company_name'] ?? null,
        'category_id' => !empty($_POST['category_id']) ? $_POST['category_id'] : 1, // Default or required
        'customer_type' => $_POST['customer_type'] ?? 'business',
        'status' => $_POST['status'] ?? 'active',
        'credit_limit' => !empty($_POST['credit_limit']) ? $_POST['credit_limit'] : 0,
        'notes' => $_POST['description'] ?? null, // Map description to notes
        
        'contact_person' => $_POST['contact_person'] ?? null,
        'contact_title' => $_POST['contact_title'] ?? null,
        'email' => $_POST['email'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'mobile' => $_POST['mobile'] ?? null,
        'fax' => $_POST['fax'] ?? null,
        'website' => $_POST['website'] ?? null,
        
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'state' => $_POST['state'] ?? null,
        'country' => $_POST['country'] ?? 'Tanzania',
        'postal_code' => $_POST['postal_code'] ?? null,
        
        'tax_id' => $_POST['tax_id'] ?? null,
        'vat_number' => $_POST['vat_number'] ?? null,
        'payment_terms' => $_POST['payment_terms'] ?? null,
        'currency' => $_POST['currency'] ?? 'TZS',
        'bank_name' => $_POST['bank_name'] ?? null,
        'bank_account' => $_POST['bank_account'] ?? null,
        'bank_address' => $_POST['bank_address'] ?? null,
        
        'created_by' => $_SESSION['user_id']
    ];

    $fields = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    
    $sql = "INSERT INTO customers ($fields) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    
    $customerId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true, 
        'message' => 'Customer added successfully',
        'customer_id' => $customerId
    ]);

} catch (Exception $e) {
    error_log("Add Customer Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
