<?php
// actions/process_registration.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../roots.php'; 
}
// Ensure PDO is available
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Phase 1 Fields
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = $_POST['phone'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['dob'] ?? null;
    $nida_number = $_POST['nida_number'] ?? '';
    
    $religion = $_POST['religion'] ?? '';
    if ($religion === 'Nyingine' && !empty($_POST['religion_other'])) {
        $religion = $_POST['religion_other'];
    }
    $birth_region   = $_POST['birth_region'] ?? '';
    $marital_status = in_array($_POST['marital_status'] ?? 'Single', ['Single','Married'])
                      ? $_POST['marital_status'] : 'Single';

    $password = $_POST['password'] ?? '';
    
    // Phase 2 Fields (Residence)
    $country = $_POST['country'] ?? 'Tanzania';
    $state = $_POST['state'] ?? '';
    $district = $_POST['district'] ?? '';
    $ward = $_POST['ward'] ?? '';
    $street = $_POST['street'] ?? '';
    $house_number = $_POST['house_number'] ?? '';
    
    // Phase 2 Fields (Next of Kin)
    $nok_name = $_POST['next_of_kin_name'] ?? '';
    $nok_relationship = $_POST['next_of_kin_relationship'] ?? '';
    $nok_phone = $_POST['next_of_kin_phone'] ?? '';
    $nok_age = $_POST['nok_age'] ?? null;
    $nok_country = $_POST['nok_country'] ?? 'Tanzania';
    $nok_state = $_POST['nok_state'] ?? '';
    $nok_district = $_POST['nok_district'] ?? '';
    $nok_ward = $_POST['nok_ward'] ?? '';
    $nok_street = $_POST['nok_street'] ?? '';
    $nok_house_number = $_POST['nok_house_number'] ?? '';

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

    // Phase 3 Fields (Finance)
    $entrance_fee = $_POST['entrance_fee'] ?? 0;
    
    // Defaults
    $user_role = 'Member';
    $status = 'pending';
    $preferred_language = in_array($_POST['preferred_language'] ?? 'en', ['en', 'sw']) ? $_POST['preferred_language'] : 'en';

    // Generate username: first letter of first name + full last name (lowercase, no spaces)
    $first_initial = strtolower(substr(trim($first_name), 0, 1));
    $last_name_slug = strtolower(preg_replace('/\s+/', '', trim($last_name)));
    $username = $first_initial . $last_name_slug;
    $base_username = $username;

    if (empty($email) || empty($password) || empty($first_name) || empty($last_name)) {
        $response['message'] = 'Please fill in all required fields in Step 1 and Step 3.';
        echo json_encode($response);
        exit;
    }

    $full_name = trim("$first_name $middle_name $last_name");

    try {
        // Check if email already exists
        $stmtEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmtEmail->execute([$email]);
        
        // Check if phone already exists
        $stmtPhone = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
        $stmtPhone->execute([$phone]);
        
        if ($stmtEmail->rowCount() > 0) {
            $response['message'] = ($preferred_language === 'sw') ? 'Barua pepe hii tayari imesajiliwa.' : 'This email address is already registered.';
        } elseif ($stmtPhone->rowCount() > 0) {
            $response['message'] = ($preferred_language === 'sw') ? 'Namba hii ya simu tayari imesajiliwa.' : 'A user with this phone number already exists.';
        } else {
            // Ensure username is unique (append number if duplicate exists)
            $stmt_un = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt_un->execute([$username]);
            $un_count = (int)$stmt_un->fetchColumn();
            if ($un_count > 0) {
                $username = $base_username . ($un_count + 1);
            }

            // Get Member role_id
            $role_stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) LIKE '%member%' OR LOWER(role_name) LIKE '%mwanachama%' LIMIT 1");
            $role_stmt->execute();
            $member_role_id = $role_stmt->fetchColumn() ?: null;

            $pdo->beginTransaction();

            // 1. Handle Passport Photo Upload
            $avatar = null;
            if (isset($_FILES['passport_photo']) && $_FILES['passport_photo']['error'] === 0) {
                $upload_dir = ROOT_DIR . '/uploads/avatars/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $file_ext = pathinfo($_FILES['passport_photo']['name'], PATHINFO_EXTENSION);
                $file_name = 'user_' . time() . '_' . uniqid() . '.' . $file_ext;
                if (move_uploaded_file($_FILES['passport_photo']['tmp_name'], $upload_dir . $file_name)) {
                    $avatar = $file_name;
                }
            }

            // 2. Insert into users table
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password, first_name, middle_name, last_name, phone, user_role, role_id, status, avatar, preferred_language, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$username, $email, $hashed_password, $first_name, $middle_name, $last_name, $phone, $user_role, $member_role_id, $status, $avatar, $preferred_language]);
            $user_id = $pdo->lastInsertId();

            // 3. Insert into customers table
            $stmt = $pdo->prepare("
                INSERT INTO customers (
                    first_name, middle_name, last_name, customer_name, email, phone, gender, religion, birth_region, dob, nida_number,
                    country, state, district, ward, street, house_number,
                    marital_status,
                    spouse_first_name, spouse_middle_name, spouse_last_name, spouse_email, spouse_phone, spouse_gender, spouse_dob, spouse_nida,
                    spouse_religion, spouse_birth_region,
                    children_data,
                    father_name, father_location, father_sub_location, father_phone,
                    mother_name, mother_location, mother_sub_location, mother_phone,
                    guarantor_name, guarantor_phone, guarantor_rel, guarantor_location,
                    status, initial_savings, user_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $first_name, $middle_name, $last_name, $full_name, $email, $phone, $gender, $religion, $birth_region, $dob, $nida_number,
                $country, $state, $district, $ward, $street, $house_number,
                $marital_status,
                $spouse_first_name, $spouse_middle_name, $spouse_last_name, $spouse_email, $spouse_phone, $spouse_gender, $spouse_dob, $spouse_nida,
                $spouse_religion, $spouse_birth_region,
                $children_data,
                $father_name, $father_location, $father_sub_location, $father_phone,
                $mother_name, $mother_location, $mother_sub_location, $mother_phone,
                $guarantor_name, $guarantor_phone, $guarantor_rel, $guarantor_location,
                $status, $entrance_fee, $user_id
            ]);
            $customer_id = $pdo->lastInsertId();

            // 4. Handle Entrance Fee Slip (Kianzio)
            $evidence_path = null;
            if (isset($_FILES['kianzio_slip']) && $_FILES['kianzio_slip']['error'] === 0) {
                $contrib_dir = ROOT_DIR . '/uploads/contributions/';
                if (!is_dir($contrib_dir)) mkdir($contrib_dir, 0777, true);
                
                $file_ext = pathinfo($_FILES['kianzio_slip']['name'], PATHINFO_EXTENSION);
                $file_name = 'kianzio_' . time() . '_' . uniqid() . '.' . $file_ext;
                if (move_uploaded_file($_FILES['kianzio_slip']['tmp_name'], $contrib_dir . $file_name)) {
                    $evidence_path = 'uploads/contributions/' . $file_name;
                }
            }

            // Create contribution record if fee is paid
            if ($entrance_fee > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO contributions (member_id, amount, contribution_date, description, status, evidence_path, created_at)
                    VALUES (?, ?, CURRENT_DATE, 'Group registration entrance fee', 'pending', ?, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$customer_id, $entrance_fee, $evidence_path]);
            }
            
            $pdo->commit();
            $response['success'] = true;
            $response['message'] = 'Registration received! Please wait for the Admin to approve your account before you can start using the system.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>
