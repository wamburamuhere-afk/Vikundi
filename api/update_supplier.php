<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check user role (Admin, Manager, Purchasing can update suppliers)
$role_stmt = $pdo->prepare("SELECT role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$user_role = $role_stmt->fetchColumn();

if (!in_array($user_role, ['Admin', 'Manager', 'Purchasing'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';
$supplier_name = trim($_POST['supplier_name'] ?? '');
$company_name = trim($_POST['company_name'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$contact_title = trim($_POST['contact_title'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$fax = trim($_POST['fax'] ?? '');
$website = trim($_POST['website'] ?? '');
$address = trim($_POST['address'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$country = trim($_POST['country'] ?? 'Tanzania');
$postal_code = trim($_POST['postal_code'] ?? '');
$tax_id = trim($_POST['tax_id'] ?? '');
$vat_number = trim($_POST['vat_number'] ?? '');
$payment_terms = trim($_POST['payment_terms'] ?? '');
$currency = trim($_POST['currency'] ?? 'TZS');
$bank_name = trim($_POST['bank_name'] ?? '');
$bank_account = trim($_POST['bank_account'] ?? '');
$bank_address = trim($_POST['bank_address'] ?? '');
$category_id = $_POST['category_id'] ?? null;
$description = trim($_POST['description'] ?? '');
$status = $_POST['status'] ?? 'active';

// Validate required fields
if (empty($supplier_id) || empty($supplier_name)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID and name are required']);
    exit();
}

// Get existing supplier
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$existing_supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing_supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Validate email if provided
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

// Validate URL if provided
if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid website URL']);
    exit();
}

// Check for duplicate supplier name (excluding current supplier)
$check_stmt = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id != ? AND supplier_name = ? AND status != 'deleted'");
$check_stmt->execute([$supplier_id, $supplier_name]);
if ($check_stmt->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier name already exists']);
    exit();
}

// Validate category if provided
if (!empty($category_id)) {
    $category_stmt = $pdo->prepare("SELECT category_id FROM supplier_categories WHERE category_id = ? AND status = 'active'");
    $category_stmt->execute([$category_id]);
    if (!$category_stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid category']);
        exit();
    }
}

// Clean phone numbers
if (!function_exists('clean_phone')) {
    function clean_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            return '255' . $phone;
        }
        return $phone;
    }
}

$phone = clean_phone($phone);
$mobile = clean_phone($mobile);

// Update supplier
$update_stmt = $pdo->prepare("
    UPDATE suppliers SET
        supplier_name = ?,
        company_name = ?,
        contact_person = ?,
        contact_title = ?,
        email = ?,
        phone = ?,
        mobile = ?,
        fax = ?,
        website = ?,
        address = ?,
        city = ?,
        state = ?,
        country = ?,
        postal_code = ?,
        tax_id = ?,
        vat_number = ?,
        payment_terms = ?,
        currency = ?,
        bank_name = ?,
        bank_account = ?,
        bank_address = ?,
        category_id = ?,
        description = ?,
        status = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE supplier_id = ?
");

try {
    $update_stmt->execute([
        $supplier_name, $company_name, $contact_person, $contact_title,
        $email, $phone, $mobile, $fax, $website, $address, $city, $state,
        $country, $postal_code, $tax_id, $vat_number, $payment_terms,
        $currency, $bank_name, $bank_account, $bank_address, $category_id,
        $description, $status, $_SESSION['user_id'], $supplier_id
    ]);
    
    // Log the action
    $log_stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
        VALUES (?, 'update_supplier', ?, ?, ?)
    ");
    $log_stmt->execute([
        $_SESSION['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        "Updated supplier: $supplier_name"
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Supplier updated successfully'
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}