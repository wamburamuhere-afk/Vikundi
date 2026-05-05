<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check permission
if (!canEdit('customers')) {
     echo json_encode(['success' => false, 'message' => 'Permission denied']);
     exit;
}

try {
    $customerId = $_POST['customer_id'] ?? null;
    if (!$customerId) {
        throw new Exception('Customer ID is required');
    }

    // Validate required fields
    if (empty($_POST['first_name']) || empty($_POST['last_name'])) {
        throw new Exception('First and last names are required');
    }

    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'] ?? '';
    $last_name = $_POST['last_name'];
    $customer_name = trim("$first_name $middle_name $last_name");

    // Prepare update data
    $data = [
        'customer_name' => $customer_name,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name,
        'company_name' => $_POST['company_name'] ?? null,
        'customer_type' => $_POST['customer_type'] ?? 'individual',
        'email' => $_POST['email'] ?? null,
        'phone' => $_POST['phone'] ?? null,
        'mobile' => $_POST['mobile'] ?? null,
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'state' => $_POST['state'] ?? null,
        'district' => $_POST['district'] ?? null,
        'ward' => $_POST['ward'] ?? null,
        'street' => $_POST['street'] ?? null,
        'house_number' => $_POST['house_number'] ?? null,
        'country' => $_POST['country'] ?? 'Tanzania',
        'postal_code' => $_POST['postal_code'] ?? null,
        'tax_id' => $_POST['tax_id'] ?? null,
        'vat_number' => $_POST['vat_number'] ?? null,
        'mpesa_name' => $_POST['mpesa_name'] ?? null,
        'mpesa_number' => $_POST['mpesa_number'] ?? null,
        'next_of_kin_name' => $_POST['next_of_kin_name'] ?? null,
        'next_of_kin_relationship' => $_POST['next_of_kin_relationship'] ?? null,
        'next_of_kin_phone' => $_POST['next_of_kin_phone'] ?? null,
        'nok_age' => $_POST['nok_age'] ?? null,
        'nok_country' => $_POST['nok_country'] ?? 'Tanzania',
        'nok_state' => $_POST['nok_state'] ?? null,
        'nok_district' => $_POST['nok_district'] ?? null,
        'nok_ward' => $_POST['nok_ward'] ?? null,
        'nok_street' => $_POST['nok_street'] ?? null,
        'nok_house_number' => $_POST['nok_house_number'] ?? null,
        'updated_by' => $_SESSION['user_id']
    ];

    // Handle File Uploads
    $upload_dir = __DIR__ . '/../uploads/customers/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Handle Photo
    if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'photo_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $target_path)) {
            $data['photo_path'] = 'uploads/customers/' . $filename;
        }
    } elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
        $data['photo_path'] = null;
    }

    // Handle ID Attachment
    if (isset($_FILES['id_attachment']) && $_FILES['id_attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['id_attachment']['name'], PATHINFO_EXTENSION);
        $filename = 'id_' . $customerId . '_' . time() . '.' . $ext;
        $target_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['id_attachment']['tmp_name'], $target_path)) {
            $data['id_attachment_path'] = 'uploads/customers/' . $filename;
        }
    } elseif (isset($_POST['remove_id_attachment']) && $_POST['remove_id_attachment'] == '1') {
        $data['id_attachment_path'] = null;
    }

    // Build SQL
    $update_parts = [];
    $params = [];
    foreach ($data as $key => $value) {
        $update_parts[] = "`$key` = ?";
        $params[] = $value;
    }
    $params[] = $customerId;

    $sql = "UPDATE customers SET " . implode(', ', $update_parts) . " WHERE customer_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $lang = $_SESSION['preferred_language'] ?? 'en';
    $log_desc = $lang === 'sw'
        ? "Taarifa za mwanachama zimebadilishwa: $customer_name"
        : "Member profile updated: $customer_name";
    logUpdate('Members', $customer_name, "MEMBER#$customerId");
    // ─────────────────────────────────────────────────────────────────────────

    echo json_encode([
        'success' => true,
        'message' => 'Customer updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
