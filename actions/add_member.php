<?php
// actions/add_member.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Language for every message shown to the admin filling this form = their UI language,
// so the front-end popup title and this body never mix English and Swahili.
$ui_lang = (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'sw' : 'en';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php'; // vk_age_from_dob() (child age derived from DOB)
require_once __DIR__ . '/../includes/member_identity.php'; // username + auto-email helpers

// Check if user is logged in and is a leader
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => ($ui_lang === 'sw')
        ? 'Hujaingia kwenye mfumo. Tafadhali ingia kisha ujaribu tena.'
        : 'You are not logged in. Please log in and try again.']);
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
        echo json_encode(['success' => false, 'message' => ($ui_lang === 'sw')
            ? "Huna mamlaka ya kusajili mwanachama. Wadhifa wako ni: $current_role"
            : "You do not have permission to register a member. Your role is: $current_role"]);
        exit();
    }
}

// Get data
$first_name = $_POST['first_name'] ?? '';
$middle_name = $_POST['middle_name'] ?? '';
$last_name  = $_POST['last_name'] ?? '';
$email      = $_POST['email'] ?? '';
$phone      = $_POST['phone'] ?? '';
// Password is set automatically to username@123 once the username is generated
// (see below). The admin no longer types a password.
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
$child_dobs = $_POST['child_dob'] ?? [];
$child_files = $_FILES['child_photo'] ?? null; // PR-D: optional per-child photo

for ($i = 0; $i < count($child_names); $i++) {
    if (!empty($child_names[$i])) {
        $dob = $child_dobs[$i] ?? '';
        // Age is derived from DOB server-side; fall back to any posted age.
        $age = $dob !== '' ? vk_age_from_dob($dob) : ($child_ages[$i] ?? '');
        $children[] = [
            'name' => $child_names[$i],
            'dob' => $dob,
            'age' => $age,
            'gender' => $child_genders[$i] ?? '',
            'photo' => vk_save_child_photo($child_files, $i, __DIR__ . '/../uploads/avatars'),
        ];
    }
}
$children_data = json_encode($children);

// Parent Fields (PR-B: structured name + six-field location + optional photo)
$father_first_name   = $_POST['father_first_name'] ?? '';
$father_middle_name  = $_POST['father_middle_name'] ?? '';
$father_last_name    = $_POST['father_last_name'] ?? '';
$father_phone        = $_POST['father_phone'] ?? '';
$father_country      = $_POST['father_country'] ?? '';
$father_state        = $_POST['father_state'] ?? '';
$father_district     = $_POST['father_district'] ?? '';
$father_ward         = $_POST['father_ward'] ?? '';
$father_street       = $_POST['father_street'] ?? '';
$father_house_number = $_POST['father_house_number'] ?? '';
$mother_first_name   = $_POST['mother_first_name'] ?? '';
$mother_middle_name  = $_POST['mother_middle_name'] ?? '';
$mother_last_name    = $_POST['mother_last_name'] ?? '';
$mother_phone        = $_POST['mother_phone'] ?? '';
$mother_country      = $_POST['mother_country'] ?? '';
$mother_state        = $_POST['mother_state'] ?? '';
$mother_district     = $_POST['mother_district'] ?? '';
$mother_ward         = $_POST['mother_ward'] ?? '';
$mother_street       = $_POST['mother_street'] ?? '';
$mother_house_number = $_POST['mother_house_number'] ?? '';
// Keep the legacy single-column fields populated for existing reports/views.
$father_name = vk_full_name($father_first_name, $father_middle_name, $father_last_name);
$mother_name = vk_full_name($mother_first_name, $mother_middle_name, $mother_last_name);
$father_location = $father_state;
$father_sub_location = $father_ward;
$mother_location = $mother_state;
$mother_sub_location = $mother_ward;

// Guarantor Fields
$guarantor_name = $_POST['guarantor_name'] ?? '';
$guarantor_phone = $_POST['guarantor_phone'] ?? '';
$guarantor_rel = $_POST['guarantor_rel'] ?? '';
// PR-C: optional link to an existing member (admin picker) + six-field location.
$guarantor_member_id    = !empty($_POST['guarantor_member_id']) ? (int) $_POST['guarantor_member_id'] : null;
$guarantor_country      = $_POST['guarantor_country'] ?? '';
$guarantor_state        = $_POST['guarantor_state'] ?? '';
$guarantor_district     = $_POST['guarantor_district'] ?? '';
$guarantor_ward         = $_POST['guarantor_ward'] ?? '';
$guarantor_street       = $_POST['guarantor_street'] ?? '';
$guarantor_house_number = $_POST['guarantor_house_number'] ?? '';
$guarantor_location = $guarantor_state; // keep legacy column populated

$initial_savings = (float)($_POST['initial_savings'] ?? 0);
$preferred_lang  = $_POST['preferred_language'] ?? 'en';
$kianzio_slip   = null;

// ---- CSRF + authoritative format validation (same rules as public register) ----
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/registration_validator.php';

// Messages shown to the admin (CSRF, validation, dedup) follow the admin's UI language,
// NOT the new member's chosen account language ($preferred_lang, used only for storage).
$val_lang = $ui_lang;

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => ($val_lang === 'sw')
        ? 'Kipindi chako kimeisha au ombi si salama. Tafadhali onyesha upya ukurasa kisha ujaribu tena.'
        : 'Your session has expired or the request was not secure. Please refresh the page and try again.']);
    exit();
}

// The admin form's fee field is "initial_savings"; map it to the validator's key.
$val_post = $_POST;
$val_post['entrance_fee'] = $_POST['initial_savings'] ?? '';
// Admin form has no terms checkbox -> require_terms = false. The password is
// auto-generated (username@123, see below), so the admin never types one ->
// require_password = false. Slip is still required (validated above + here).
$validation_errors = validate_registration_input($val_post, $_FILES, $val_lang, false, true, false, false);
if (!empty($validation_errors)) {
    echo json_encode(['success' => false, 'message' => implode("\n", $validation_errors)]);
    exit();
}

// Canonicalise email & phone so duplicate detection and storage stay consistent.
$email = strtolower(trim($email));
$phone = reg_normalize_phone($phone);

// Handle Payment Slip Upload (MANDATORY)
if (!isset($_FILES['kianzio_slip']) || $_FILES['kianzio_slip']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => ($val_lang === 'sw')
        ? 'Tafadhali pakia risiti ya malipo (Payment Slip) mwanzo ili kukamilisha usajili huu.'
        : 'Please upload the payment slip first to complete this registration.']);
    exit();
}

$slip_dir = __DIR__ . '/../uploads/contributions';
if (!is_dir($slip_dir)) mkdir($slip_dir, 0755, true);
$file_ext = pathinfo($_FILES['kianzio_slip']['name'], PATHINFO_EXTENSION);
$kianzio_slip = 'kianzio_' . time() . '_' . uniqid() . '.' . $file_ext;
move_uploaded_file($_FILES['kianzio_slip']['tmp_name'], $slip_dir . '/' . $kianzio_slip);

// Email is not required: admin-created members get an auto-generated identity
// email (username@domain) below, so only name + phone are mandatory here.
if (empty($first_name) || empty($last_name) || empty($phone)) {
    echo json_encode(['success' => false, 'message' => ($val_lang === 'sw')
        ? 'Tafadhali jaza taarifa zote za lazima (*).'
        : 'Please fill in all required fields (*).']);
    exit();
}

try {
    // Check if Phone number already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => ($val_lang === 'sw')
            ? 'Hii namba ya simu tayari imesajiliwa na mwanachama mwingine.'
            : 'This phone number is already registered to another member.']);
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

    // Optional parent passport photos (PR-B). Same store as the member avatar.
    $vk_save_photo = function ($field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $dir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
            $name = $field . '_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) return $name;
        }
        return null;
    };
    $father_photo = $vk_save_photo('father_photo');
    $mother_photo = $vk_save_photo('mother_photo');
    $spouse_photo = $vk_save_photo('spouse_photo');

    // Username: first initial + last name, made unique. Admin-created members
    // always get an auto-generated identity email (username@<site-domain>);
    // any value typed into the admin form is ignored on purpose.
    $username = vk_unique_username($pdo, vk_build_username($first_name, $last_name));
    $email    = vk_build_member_email($username, vk_member_email_domain($pdo));

    $pdo->beginTransaction();

    // 1. Insert into users table
    // Admin-created members get a deterministic initial password: username@123
    // (the admin does not type one). The member changes it after first login.
    $password = $username . '@123';
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
            spouse_religion, spouse_birth_region, spouse_photo,
            children_data,
            father_name, father_first_name, father_middle_name, father_last_name, father_phone,
            father_location, father_sub_location,
            father_country, father_state, father_district, father_ward, father_street, father_house_number, father_photo,
            mother_name, mother_first_name, mother_middle_name, mother_last_name, mother_phone,
            mother_location, mother_sub_location,
            mother_country, mother_state, mother_district, mother_ward, mother_street, mother_house_number, mother_photo,
            guarantor_member_id, guarantor_name, guarantor_phone, guarantor_rel, guarantor_location,
            guarantor_country, guarantor_state, guarantor_district, guarantor_ward, guarantor_street, guarantor_house_number,
            status, initial_savings, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?,
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $new_user_id, $first_name, $middle_name, $last_name, $customer_full_name, $email, $phone, $gender, $religion, $birth_region, $dob, $nida_number,
        $country, $state, $district, $ward, $street, $house_number,
        $_POST['marital_status'] ?? 'Single',
        $spouse_first_name, $spouse_middle_name, $spouse_last_name, $spouse_email, $spouse_phone, $spouse_gender, $spouse_dob, $spouse_nida,
        $spouse_religion, $spouse_birth_region, $spouse_photo,
        $children_data,
        $father_name, $father_first_name, $father_middle_name, $father_last_name, $father_phone,
        $father_location, $father_sub_location,
        $father_country, $father_state, $father_district, $father_ward, $father_street, $father_house_number, $father_photo,
        $mother_name, $mother_first_name, $mother_middle_name, $mother_last_name, $mother_phone,
        $mother_location, $mother_sub_location,
        $mother_country, $mother_state, $mother_district, $mother_ward, $mother_street, $mother_house_number, $mother_photo,
        $guarantor_member_id, $guarantor_name, $guarantor_phone, $guarantor_rel, $guarantor_location,
        $guarantor_country, $guarantor_state, $guarantor_district, $guarantor_ward, $guarantor_street, $guarantor_house_number,
        $status, $initial_savings
    ]);
    $new_customer_id = $pdo->lastInsertId();

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Mwanachama amesajiliwa kikamilifu.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $db_err_prefix = ($ui_lang === 'sw') ? 'Hitilafu ya Database: ' : 'Database error: ';
    echo json_encode(['success' => false, 'message' => $db_err_prefix . $e->getMessage()]);
}
?>
