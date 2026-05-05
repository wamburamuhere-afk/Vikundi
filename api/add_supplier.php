<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check user role (Admin, Manager, Purchasing can add suppliers)
$role_stmt = $pdo->prepare("SELECT role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$user_role = $role_stmt->fetchColumn();

if (!in_array($user_role, ['Admin', 'Manager', 'Purchasing'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get POST data
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
if (empty($supplier_name)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier name is required']);
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

// Check if supplier already exists (by name)
$check_query = "SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE supplier_name = ? AND status != 'deleted'";
$check_params = [$supplier_name];

// Also check company name if provided
if (!empty($company_name)) {
    $check_query = "SELECT supplier_id, supplier_name, company_name FROM suppliers WHERE (supplier_name = ? OR company_name = ?) AND status != 'deleted'";
    $check_params = [$supplier_name, $company_name];
}

$check_stmt = $pdo->prepare($check_query);
$check_stmt->execute($check_params);
$existing_supplier = $check_stmt->fetch();

if ($existing_supplier) {
    $matched_field = '';
    // Use case-insensitive comparison to identify what matched
    if (strtolower($existing_supplier['supplier_name']) === strtolower($supplier_name)) {
        $matched_field = 'name';
    } elseif (!empty($company_name) && strtolower($existing_supplier['company_name']) === strtolower($company_name)) {
        $matched_field = 'company name';
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => "Supplier already exists with this $matched_field",
        'debug_info' => [
            'matched_field' => $matched_field,
            'existing_id' => $existing_supplier['supplier_id'],
            'existing_name' => $existing_supplier['supplier_name'],
            'existing_company' => $existing_supplier['company_name']
        ]
    ]);
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

// Generate supplier code
$supplier_code = 'SUP' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT) . date('ym');

// Insert new supplier
$insert_stmt = $pdo->prepare("
    INSERT INTO suppliers (
        supplier_name, company_name, contact_person, contact_title,
        email, phone, mobile, fax, website, address, city, state,
        country, postal_code, tax_id, vat_number, payment_terms,
        currency, bank_name, bank_account, bank_address, category_id,
        description, status, supplier_code, created_by, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

try {
    $insert_stmt->execute([
        $supplier_name, $company_name, $contact_person, $contact_title,
        $email, $phone, $mobile, $fax, $website, $address, $city, $state,
        $country, $postal_code, $tax_id, $vat_number, $payment_terms,
        $currency, $bank_name, $bank_account, $bank_address, $category_id,
        $description, $status, $supplier_code, $_SESSION['user_id']
    ]);
    
    $supplier_id = $pdo->lastInsertId();
    
    // Log the action
    $log_stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
        VALUES (?, 'create_supplier', ?, ?, ?)
    ");
    $log_stmt->execute([
        $_SESSION['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        "Created supplier: $supplier_name" . ($company_name ? " ($company_name)" : "")
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Supplier added successfully',
        'supplier_id' => $supplier_id,
        'supplier_code' => $supplier_code
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}