<?php
// actions/add_member.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';

// Check if user is logged in and is a leader
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Hujalogin.']);
    exit();
}

// Check role using the same logic as header.php
$stmt = $pdo->prepare("
    SELECT r.role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.role_id 
    WHERE u.user_id = ?
");
$stmt->execute([$user_id]);
$current_role = strtolower($stmt->fetchColumn() ?: '');

$viongozi_roles = ['admin', 'super admin', 'secretary', 'katibu', 'treasurer', 'mhasibu'];

if (!in_array($current_role, $viongozi_roles)) {
    // Fallback: Check if there's an is_admin column or role column in users table
    $stmt = $pdo->prepare("SELECT user_role, role, is_admin FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $alt_role = strtolower($u['user_role'] ?? ($u['role'] ?? ''));
    $is_admin = (int)($u['is_admin'] ?? 0);
    
    if (!in_array($alt_role, $viongozi_roles) && $is_admin !== 1) {
        echo json_encode(['success' => false, 'message' => "Huna mamlaka ya kusajili mwanachama. Role yako ni: $current_role"]);
        exit();
    }
}

// Get data
$first_name = $_POST['first_name'] ?? '';
$middle_name = $_POST['middle_name'] ?? '';
$last_name  = $_POST['last_name'] ?? '';
$email      = $_POST['email'] ?? '';
$phone      = $_POST['phone'] ?? '';
$password   = $_POST['password'] ?? 'Vikundi2024';
$user_role  = $_POST['user_role'] ?? 'Member';
$status     = $_POST['status'] ?? 'active';

// Additional fields
$gender     = $_POST['gender'] ?? '';
$dob        = $_POST['dob'] ?? null;
$nida_number = $_POST['nida_number'] ?? '';
$country    = $_POST['country'] ?? 'Tanzania';
$state      = $_POST['state'] ?? '';
$district   = $_POST['district'] ?? '';

$religion     = $_POST['religion'] ?? '';
$birth_region = $_POST['birth_region'] ?? '';

if ($religion === 'Nyingine' && !empty($_POST['religion_other'])) {
    $religion = $_POST['religion_other'];
}

$ward       = $_POST['ward'] ?? '';
$street     = $_POST['street'] ?? '';
$house_number = $_POST['house_number'] ?? '';

// NEW: Phase 2 Family Fields
$spouse_first_name = trim($_POST['spouse_first_name'] ?? '');
$spouse_middle_name = trim($_POST['spouse_middle_name'] ?? '');
$spouse_last_name = trim($_POST['spouse_last_name'] ?? '');
$spouse_email = trim($_POST['spouse_email'] ?? '');
$spouse_phone = $_POST['spouse_phone'] ?? '';
$spouse_gender = $_POST['spouse_gender'] ?? '';
$spouse_dob = $_POST['spouse_dob'] ?? null;
$spouse_nida = $_POST['spouse_nida'] ?? '';
$spouse_religion = $_POST['spouse_religion'] ?? '';
$spouse_birth_region = $_POST['spouse_birth_region'] ?? '';

// Handle Children as JSON
$children = [];
$child_names = $_POST['child_name'] ?? [];
$child_ages = $_POST['child_age'] ?? [];
$child_genders = $_POST['child_gender'] ?? [];

for ($i = 0; $i < count($child_names); $i++) {
    if (!empty($child_names[$i])) {
        $children[] = [
            'name' => $child_names[$i],
            'age' => $child_ages[$i] ?? '',
            'gender' => $child_genders[$i] ?? ''
        ];
    }
}
$children_data = json_encode($children);

// Parent Fields
$father_name = $_POST['father_name'] ?? '';
$father_location = $_POST['father_location'] ?? '';
$father_sub_location = $_POST['father_sub_location'] ?? '';
$father_phone = $_POST['father_phone'] ?? '';
$mother_name = $_POST['mother_name'] ?? '';
$mother_location = $_POST['mother_location'] ?? '';
$mother_sub_location = $_POST['mother_sub_location'] ?? '';
$mother_phone = $_POST['mother_phone'] ?? '';

// Guarantor Fields
$guarantor_name = $_POST['guarantor_name'] ?? '';
$guarantor_phone = $_POST['guarantor_phone'] ?? '';
$guarantor_rel = $_POST['guarantor_rel'] ?? '';
$guarantor_location = $_POST['guarantor_location'] ?? '';

$initial_savings = (float)($_POST['initial_savings'] ?? 0);
$preferred_lang  = $_POST['preferred_language'] ?? 'en';
$kianzio_slip   = null;

// Handle Payment Slip Upload (MANDATORY)
if (!isset($_FILES['kianzio_slip']) || $_FILES['kianzio_slip']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Tafadhali pakia risiti ya malipo (Payment Slip) mwanzo ili kukamilisha usajili huu.']);
    exit();
}

$slip_dir = __DIR__ . '/../uploads/contributions';
if (!is_dir($slip_dir)) mkdir($slip_dir, 0755, true);
$file_ext = pathinfo($_FILES['kianzio_slip']['name'], PATHINFO_EXTENSION);
$kianzio_slip = 'kianzio_' . time() . '_' . uniqid() . '.' . $file_ext;
move_uploaded_file($_FILES['kianzio_slip']['tmp_name'], $slip_dir . '/' . $kianzio_slip);

if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Tafadhali jaza taarifa zote za lazima (*).']);
    exit();
}

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Barua pepe hii tayari inatumiwa na mwanachama mwingine.']);
        exit();
    }

    // Check if Phone number already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Hii namba ya simu tayari imesajiliwa na mwanachama mwingine.']);
        exit();
    }

    // Handle Passport Photo Upload
    $avatar = null;
    if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/avatars';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $ext = pathinfo($_FILES['passport_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . time() . '_' . uniqid() . '.' . $ext;
        $upload_path = $upload_dir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $upload_path)) {
            $avatar = $filename;
        }
    }

    // Generate username: first letter of first name + full last name (lowercase, no spaces)
    $first_initial = strtolower(substr(trim($first_name), 0, 1));
    $last_name_slug = strtolower(preg_replace('/\s+/', '', trim($last_name)));
    $username = $first_initial . $last_name_slug;
    $base_username = $username;

    // Ensure username is unique (append number if duplicate exists)
    $stmt_un = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt_un->execute([$username]);
    $un_count = (int)$stmt_un->fetchColumn();
    if ($un_count > 0) {
        // Try to find a unique one
        $i = 1;
        while ($un_count > 0) {
            $username = $base_username . $i;
            $stmt_un->execute([$username]);
            $un_count = (int)$stmt_un->fetchColumn();
            $i++;
        }
    }

    $pdo->beginTransaction();

    // 1. Insert into users table
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (first_name, middle_name, last_name, email, phone, username, password, user_role, status, avatar, preferred_language, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$first_name, $middle_name, $last_name, $email, $phone, $username, $hashed_password, $user_role, $status, $avatar, $preferred_lang]);
    $new_user_id = $pdo->lastInsertId();

    // 2. Insert into customers table
    $customer_full_name = trim("$first_name $middle_name $last_name");
    $stmt = $pdo->prepare("
        INSERT INTO customers (
            user_id, first_name, middle_name, last_name, customer_name, email, phone, gender, religion, birth_region, dob, nida_number,
            country, state, district, ward, street, house_number,
            marital_status,
            spouse_first_name, spouse_middle_name, spouse_last_name, spouse_email, spouse_phone, spouse_gender, spouse_dob, spouse_nida,
            spouse_religion, spouse_birth_region,
            children_data,
            father_name, father_location, father_sub_location, father_phone,
            mother_name, mother_location, mother_sub_location, mother_phone,
            guarantor_name, guarantor_phone, guarantor_rel, guarantor_location,
            status, initial_savings, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $new_user_id, $first_name, $middle_name, $last_name, $customer_full_name, $email, $phone, $gender, $religion, $birth_region, $dob, $nida_number,
        $country, $state, $district, $ward, $street, $house_number,
        $_POST['marital_status'] ?? 'Single',
        $spouse_first_name, $spouse_middle_name, $spouse_last_name, $spouse_email, $spouse_phone, $spouse_gender, $spouse_dob, $spouse_nida,
        $spouse_religion, $spouse_birth_region,
        $children_data,
        $father_name, $father_location, $father_sub_location, $father_phone,
        $mother_name, $mother_location, $mother_sub_location, $mother_phone,
        $guarantor_name, $guarantor_phone, $guarantor_rel, $guarantor_location,
        $status, $initial_savings
    ]);
    $new_customer_id = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Mwanachama amesajiliwa kikamilifu.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Hitilafu ya Database: ' . $e->getMessage()]);
}
?>
