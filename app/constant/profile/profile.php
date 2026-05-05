<?php
require_once __DIR__ . '/../../../roots.php';
date_default_timezone_set('Africa/Nairobi');

// Get target user ID (allow viewing others if Admin/Secretary)
$view_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$ref = $_GET['ref'] ?? 'list';
$is_own_profile = ($view_user_id == $_SESSION['user_id']);
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == 1;

// Fetch current user's role before access check
$role_stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$user_role = $role_stmt->fetchColumn() ?: 'user';

// Only admins/leaders can view other profiles
$viongozi_roles = ['Admin', 'Secretary', 'Katibu'];
if (!$is_own_profile && !in_array($user_role, $viongozi_roles)) {
    header("Location: " . getUrl('dashboard') . "?error=Ufikiaji Umekataliwa");
    exit();
}

$stmt = $pdo->prepare("
    SELECT u.*, r.role_name, d.department_name,
           c.nida_number, c.country, c.state, c.district, c.ward, c.street, c.house_number, 
           c.gender as cust_gender, c.dob as cust_dob, c.religion, c.birth_region as cust_birth_region,
           c.marital_status, c.spouse_first_name, c.spouse_middle_name, c.spouse_last_name, c.spouse_email, 
           c.spouse_phone, c.spouse_gender, c.spouse_dob, c.spouse_nida, c.spouse_religion, c.spouse_birth_region,
           c.children_data,
           c.father_name, c.father_location, c.father_sub_location, c.father_phone,
           c.mother_name, c.mother_location, c.mother_sub_location, c.mother_phone,
           c.guarantor_name, c.guarantor_phone, c.guarantor_rel, c.guarantor_location,
           c.initial_savings
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    LEFT JOIN departments d ON u.department_id = d.department_id 
    LEFT JOIN customers c ON LOWER(u.email) = LOWER(c.email)
    WHERE u.user_id = ?
");
$stmt->execute([$view_user_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

/** 
 * CONTRIBUTION ANALYSIS LOGIC (FROM MEMBER STATEMENT)
 */
$contribution_data = null;
if ($member) {
    // 1. Fetch Customer ID and Initial Savings explicitly
    $cust_lookup = $pdo->prepare("SELECT customer_id, initial_savings FROM customers WHERE LOWER(email) = LOWER(?)");
    $cust_lookup->execute([$member['email']]);
    $cust_rec = $cust_lookup->fetch(PDO::FETCH_ASSOC);
    $member_id = $cust_rec['customer_id'] ?? 0;

    if ($member_id) {
        // 2. Fetch Group Settings
        $settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $monthly_amt = floatval($settings_raw['monthly_contribution'] ?? 10000);
        $entrance_amt = floatval($settings_raw['entrance_fee'] ?? 20000);
        $contribution_start_date = $settings_raw['contribution_start_date'] ?? ($settings_raw['group_founded_date'] ?? date('Y') . '-01-01');

        // 3. Fetch Confirmed Contributions
        $stmt_c = $pdo->prepare("SELECT SUM(amount) FROM contributions WHERE member_id = ? AND status = 'confirmed' AND (contribution_type = 'monthly' OR contribution_type = 'entrance' OR contribution_type = 'other')");
        $stmt_c->execute([$member_id]);
        $contributions_total = floatval($stmt_c->fetchColumn());

        $total_paid = floatval($cust_rec['initial_savings'] ?? 0) + $contributions_total;
        
        // 4. Distribution Logic
        $remaining_pot = $total_paid;
        $entrance_paid_amt = min($remaining_pot, $entrance_amt);
        $remaining_pot -= $entrance_paid_amt;

        $current_month_idx = (intval(date('Y')) - intval(date('Y', strtotime($contribution_start_date)))) * 12 + (intval(date('m')) - intval(date('m', strtotime($contribution_start_date))));
        $total_paid_for_monthly = max(0, $total_paid - $entrance_amt);
        $total_months_covered = floor($total_paid_for_monthly / $monthly_amt);
        $columns_count = max(12, $total_months_covered, $current_month_idx + 1);

        $distribution = [];
        $temp_pot = $total_paid - $entrance_paid_amt;
        for ($i = 0; $i < $columns_count; $i++) {
            $month_ts = strtotime($contribution_start_date . " +$i months");
            $month_label = date('M Y', $month_ts);
            $paid_for_this_month = min($temp_pot, $monthly_amt);
            $temp_pot -= $paid_for_this_month;
            $status = ($paid_for_this_month >= $monthly_amt) ? 'paid' : ($paid_for_this_month > 0 ? 'partial' : 'unpaid');
            $distribution[] = [
                'label' => $month_label,
                'amount' => $paid_for_this_month,
                'status' => $status,
                'target' => $monthly_amt
            ];
        }
        if ($temp_pot > 0) {
            $distribution[] = [
                'label' => ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'ZIADA (ADVANCE)' : 'ADVANCE / CREDIT',
                'amount' => $temp_pot,
                'status' => 'paid',
                'target' => 0
            ];
        }
        $contribution_data = [
            'total_paid' => $total_paid,
            'entrance_amt' => $entrance_amt,
            'entrance_paid' => $entrance_paid_amt,
            'monthly_amt' => $monthly_amt,
            'distribution' => $distribution,
            'start_date' => $contribution_start_date
        ];
    }
}

if (!$member) {
    if ($is_own_profile) {
        header('Location: logout.php');
    } else {
        header("Location: " . getUrl('customers') . "?error=Mtumiaji Hajapatikana");
    }
    exit;
}

$success_messages = [];
$error_messages = [];

if (isset($_SESSION['success_msg'])) {
    $success_messages[] = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

if (isset($_SESSION['error_msg'])) {
    $error_messages[] = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Handle form submissions
if ($_POST) {
    
    // Update Profile
    if (isset($_POST['update_profile'])) {
        try {
            $first_name = trim($_POST['first_name']);
            $middle_name = trim($_POST['middle_name'] ?? '');
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            
            // Validate required fields
            if (empty($first_name) || empty($last_name) || empty($email)) {
                throw new Exception("First name, last name, and email are required");
            }
            
            // Check if email is already taken by another user
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $view_user_id]);
            if ($stmt->fetch()) {
                throw new Exception("Email address is already taken by another user");
            }
            
            $old_email = $member['email'];
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $middle_name, $last_name, $email, $phone, $view_user_id]);

            $full_cust_name = trim("$first_name $middle_name $last_name");

            // Update Customers table for extended info
            if (isset($_POST['nida_number'])) {
                // Check if customer record exists with old email
                $checkStmt = $pdo->prepare("SELECT email FROM customers WHERE email = ?");
                $checkStmt->execute([$old_email]);
                $exists = $checkStmt->fetch();

                if ($exists) {
                    $stmt = $pdo->prepare("
                        UPDATE customers 
                        SET first_name = ?, middle_name = ?, last_name = ?, customer_name = ?, phone = ?, email = ?,
                            nida_number = ?, gender = ?, dob = ?, religion = ?, birth_region = ?,
                            country = ?, state = ?, district = ?, ward = ?, street = ?, house_number = ?,
                            marital_status = ?,
                            spouse_first_name = ?, spouse_middle_name = ?, spouse_last_name = ?, spouse_email = ?, 
                            spouse_phone = ?, spouse_gender = ?, spouse_dob = ?, spouse_nida = ?,
                            spouse_religion = ?, spouse_birth_region = ?,
                            children_data = ?,
                            father_name = ?, father_phone = ?, mother_name = ?, mother_phone = ?,
                            guarantor_name = ?, guarantor_phone = ?,
                            next_of_kin_name = ?, nok_gender = ?, next_of_kin_relationship = ?, next_of_kin_phone = ?,
                            nok_nationality = ?, nok_age = ?,
                            nok_country = ?, nok_state = ?, nok_district = ?, nok_ward = ?, nok_street = ?, nok_house_number = ?
                        WHERE email = ?
                    ");

                    // Process children data
                    $child_names = $_POST['child_name'] ?? [];
                    $child_ages = $_POST['child_age'] ?? [];
                    $child_genders = $_POST['child_gender'] ?? [];
                    $children_array = [];
                    foreach ($child_names as $idx => $cn) {
                        if (!empty($cn)) {
                            $children_array[] = [
                                'name' => $cn,
                                'age' => $child_ages[$idx] ?? '',
                                'gender' => $child_genders[$idx] ?? 'Mwanaume'
                            ];
                        }
                    }
                    $children_json = json_encode($children_array);

                    $stmt->execute([
                        $first_name, $middle_name, $last_name, $full_cust_name, $phone, $email, 
                        $_POST['nida_number'] ?? '', $_POST['gender'] ?? '', $_POST['dob'] ?? null, $_POST['religion'] ?? '', $_POST['birth_region'] ?? '',
                        $_POST['country'] ?? '', $_POST['state'] ?? '', $_POST['district'] ?? '', $_POST['ward'] ?? '', $_POST['street'] ?? '', $_POST['house_number'] ?? '',
                        $_POST['marital_status'] ?? 'Single',
                        $_POST['spouse_first_name'] ?? '', $_POST['spouse_middle_name'] ?? '', $_POST['spouse_last_name'] ?? '', $_POST['spouse_email'] ?? '',
                        $_POST['spouse_phone'] ?? '', $_POST['spouse_gender'] ?? '', $_POST['spouse_dob'] ?? null, $_POST['spouse_nida'] ?? '',
                        $_POST['spouse_religion'] ?? '', $_POST['spouse_birth_region'] ?? '',
                        $children_json,
                        $_POST['father_name'] ?? '', $_POST['father_phone'] ?? '',
                        $_POST['mother_name'] ?? '', $_POST['mother_phone'] ?? '',
                        $_POST['guarantor_name'] ?? '', $_POST['guarantor_phone'] ?? '',
                        $_POST['next_of_kin_name'] ?? '', $_POST['nok_gender'] ?? '', $_POST['next_of_kin_relationship'] ?? '', $_POST['next_of_kin_phone'] ?? '',
                        $_POST['nok_nationality'] ?? '', $_POST['nok_age'] ?? null,
                        $_POST['nok_country'] ?? '', $_POST['nok_state'] ?? '', $_POST['nok_district'] ?? '', $_POST['nok_ward'] ?? '', $_POST['nok_street'] ?? '', $_POST['nok_house_number'] ?? '',
                        $old_email
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO customers (
                            first_name, middle_name, last_name, customer_name, email, phone, 
                            nida_number, gender, dob, religion, birth_region,
                            country, state, district, ward, street, house_number,
                            marital_status, spouse_first_name, spouse_middle_name, spouse_last_name, 
                            spouse_email, spouse_phone, spouse_gender, spouse_dob, spouse_nida,
                            spouse_religion, spouse_birth_region, children_data,
                            father_name, father_phone, mother_name, mother_phone,
                            guarantor_name, guarantor_phone,
                            next_of_kin_name, nok_gender, next_of_kin_relationship, next_of_kin_phone,
                            nok_country, nok_state, nok_district, nok_ward, nok_street, nok_house_number,
                            nok_nationality, nok_age, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                    ");
                    $stmt->execute([
                        $first_name, $middle_name, $last_name, $full_cust_name, $email, $phone, 
                        $_POST['nida_number'] ?? '', $_POST['gender'] ?? '', $_POST['dob'] ?? null, $_POST['religion'] ?? '', $_POST['birth_region'] ?? '',
                        $_POST['country'] ?? '', $_POST['state'] ?? '', $_POST['district'] ?? '', $_POST['ward'] ?? '', $_POST['street'] ?? '', $_POST['house_number'] ?? '',
                        $_POST['marital_status'] ?? 'Single',
                        $_POST['spouse_first_name'] ?? '', $_POST['spouse_middle_name'] ?? '', $_POST['spouse_last_name'] ?? '', $_POST['spouse_email'] ?? '',
                        $_POST['spouse_phone'] ?? '', $_POST['spouse_gender'] ?? '', $_POST['spouse_dob'] ?? null, $_POST['spouse_nida'] ?? '',
                        $_POST['spouse_religion'] ?? '', $_POST['spouse_birth_region'] ?? '',
                        '[]', // children placeholder
                        $_POST['father_name'] ?? '', $_POST['father_phone'] ?? '',
                        $_POST['mother_name'] ?? '', $_POST['mother_phone'] ?? '',
                        $_POST['guarantor_name'] ?? '', $_POST['guarantor_phone'] ?? '',
                        $_POST['next_of_kin_name'] ?? '', $_POST['nok_gender'] ?? '', $_POST['next_of_kin_relationship'] ?? '', $_POST['next_of_kin_phone'] ?? '',
                        $_POST['nok_country'] ?? '', $_POST['nok_state'] ?? '', $_POST['nok_district'] ?? '', $_POST['nok_ward'] ?? '', $_POST['nok_street'] ?? '', $_POST['nok_house_number'] ?? '',
                        $_POST['nok_nationality'] ?? '', $_POST['nok_age'] ?? null
                    ]);
                }
            }
            
            // Update session data if own profile
            if ($is_own_profile) {
                $_SESSION['user_name'] = trim("$first_name $middle_name $last_name");
                $_SESSION['user_email'] = $email;
            }
            
            $_SESSION['success_msg'] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Wasifu umesasishwa kikamilifu" : "Profile updated successfully";
            
            // Redirect to view mode
            header("Location: ?id=" . $view_user_id . "&ref=" . $ref);
            exit();
            
        } catch (Exception $e) {
            $error_messages[] = "Error updating profile: " . $e->getMessage();
        }
    }
    
    // Change Password
    if (isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validate current password ONLY if updating own profile
            if ($is_own_profile) {
                if (empty($current_password)) {
                    throw new Exception("Current password is required");
                }
                if (!password_verify($current_password, $member['password'])) {
                    throw new Exception("Current password is incorrect");
                }
            }
            
            // Validate new password
            if (empty($new_password)) {
                throw new Exception("New password is required");
            }
            
            if (strlen($new_password) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            // Check if new password is same as current
            if (password_verify($new_password, $member['password'])) {
                throw new Exception("New password cannot be the same as current password");
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt_p = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?");
            $stmt_p->execute([$hashed_password, $view_user_id]);
            
            $_SESSION['success_msg'] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Neno la siri limebadilishwa kikamilifu" : "Password changed successfully";
            
            // Redirect to view mode
            header("Location: ?id=" . $view_user_id . "&ref=" . $ref);
            exit();
            
        } catch (Exception $e) {
            $error_messages[] = "Error changing password: " . $e->getMessage();
        }
    }
    
    // Update Preferences
    if (isset($_POST['update_preferences'])) {
        try {
            $theme = $_POST['theme'];
            $language = $_POST['language'];
            $notifications_email = isset($_POST['notifications_email']) ? 1 : 0;
            $notifications_sms = isset($_POST['notifications_sms']) ? 1 : 0;
            $results_per_page = $_POST['results_per_page'];
            
            // Save preferences to database
            $preferences = [
                'theme' => $theme,
                'language' => $language,
                'notifications_email' => $notifications_email,
                'notifications_sms' => $notifications_sms,
                'results_per_page' => $results_per_page
            ];
            
            $stmt = $pdo->prepare("UPDATE users SET preferences = ?, preferred_language = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([json_encode($preferences), $language, $view_user_id]);
            
            // Update session if own profile
            if ($is_own_profile) {
                $_SESSION['user_preferences'] = $preferences;
                $_SESSION['preferred_language'] = $language;
            }
            
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mapendeleo yamesasishwa kikamilifu" : "Preferences updated successfully";
            
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu ya kusasisha mapendeleo: " : "Error updating preferences: ") . $e->getMessage();
        }
    }
    
    // Upload Avatar
    if (isset($_POST['upload_avatar'])) {
        try {
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatar = $_FILES['avatar'];
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (!in_array($avatar['type'], $allowed_types)) {
                    throw new Exception("Only JPG, PNG, and GIF images are allowed");
                }
                
                // Validate file size (max 2MB)
                if ($avatar['size'] > 2 * 1024 * 1024) {
                    throw new Exception("Image size must be less than 2MB");
                }
                
                // Generate unique filename
                $extension = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $view_user_id . '_' . time() . '.' . $extension;
                $upload_path = 'uploads/avatars/' . $filename;
                
                // Create directory if it doesn't exist
                if (!is_dir('uploads/avatars')) {
                    mkdir('uploads/avatars', 0755, true);
                }
                
                // Move uploaded file
                if (move_uploaded_file($avatar['tmp_name'], $upload_path)) {
                    // Update user record with avatar path
                    $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$filename, $view_user_id]);
                    
                    // Update session if it's own profile
                    if ($is_own_profile) {
                        $_SESSION['user_avatar'] = $filename;
                    }
                    $user['avatar'] = $filename;
                    
                    $success_messages[] = "Avatar updated successfully";
                } else {
                    throw new Exception("Failed to upload avatar");
                }
            } else {
                throw new Exception("Please select a valid image file");
            }
        } catch (Exception $e) {
            $error_messages[] = "Error uploading avatar: " . $e->getMessage();
        }
    }
}

// Get user preferences
$preferences = [];
if (!empty($member['preferences'])) {
    $preferences = json_decode($member['preferences'], true);
} else {
    // Default preferences
    $preferences = [
        'theme' => 'light',
        'language' => 'en',
        'notifications_email' => true,
        'notifications_sms' => false,
        'results_per_page' => 25
    ];
}
// Override language from designated column if it exists
if (isset($member['preferred_language'])) {
    $preferences['language'] = $member['preferred_language'];
}

// Get user activity stats
$activity_stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM loans WHERE created_by = ?) as loans_created,
        (SELECT COUNT(*) FROM loans WHERE loan_officer_id = ?) as loans_assigned,
        (SELECT COUNT(*) FROM access_log WHERE user_id = ? AND DATE(timestamp) = CURDATE()) as today_activities
");
$activity_stmt->execute([$view_user_id, $view_user_id, $view_user_id]);
$activity_stats = $activity_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activity
$recent_activity_stmt = $pdo->prepare("
    SELECT action, resource, timestamp 
    FROM access_log 
    WHERE user_id = ? 
    ORDER BY timestamp DESC 
    LIMIT 10
");
$recent_activity_stmt->execute([$view_user_id]);
$recent_activities = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
require_once 'header.php';
?>

<!-- 1. PRINT HEADER (Visible only during print) -->
<div class="d-none d-print-block">
    <div class="text-center mb-4">
        <img src="/assets/images/<?= htmlspecialchars($group_logo ?? 'logo1.png') ?>" alt="Logo" style="height: 80px; width: auto; margin-bottom: 10px; object-fit: contain;">
        <h2 class="fw-bold mb-1 text-uppercase" style="color: #0d6efd !important;"><?= htmlspecialchars($group_name ?? 'KIKUNDI') ?></h2>
        <h4 class="fw-bold text-dark text-uppercase border-top border-bottom py-2 mt-2">
            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'WASIFU WA MWANACHAMA' : 'MEMBER PROFILE' ?>
        </h4>
        <div class="d-flex justify-content-center gap-4 mt-2 small text-muted">
            <span><strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama:' : 'Member:' ?></strong> <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></span>
            <span><strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Uanachama:' : 'Membership ID:' ?></strong> #<?= $member['customer_id'] ?? 'N/A' ?></span>
            <span><strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idara:' : 'Department:' ?></strong> <?= htmlspecialchars($member['department_name'] ?? 'N/A') ?></span>
        </div>
    </div>
</div>

<div class="container-fluid mt-4 d-print-m-0">
    <style>
        body {
            overflow-x: hidden !important;
            width: 100%;
        }
        .registration-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
            background: #fff;
        }
        .registration-header {
            background: #0d6efd;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            padding-top: 20px;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            color: #6c757d;
            transition: all 0.3s;
        }
        .step.active {
            background: #0d6efd;
            color: white;
        }
        .form-control, .form-select {
            padding: 10px 15px;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
            background-color: #fff;
        }
        .btn-next, .btn-submit {
            padding: 10px 25px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer !important;
            z-index: 10;
            position: relative;
        }
        @media (max-width: 768px) {
            .small-mobile-text { font-size: 0.9rem !important; }
            .x-small { font-size: 0.75rem !important; }
        }

        /* PRINT STYLES */
        @media print {
            .header-wrapper, .navbar, .top-header, .bottom-header, .d-print-none, .no-print, .btn, footer, .modal, .step-indicator, .back-to-list, .alert, .breadcrumb {
                display: none !important;
            }
            body { padding-top: 0 !important; margin: 0 !important; background: white !important; font-size: 16px !important; color: black !important; line-height: 1.4 !important; }
            .container-fluid, .container { width: 100% !important; max-width: none !important; padding: 0 !important; margin: 0 !important; border: none !important; }
            
            /* Remove all top gaps for print */
            .container-fluid.mt-4, .d-print-m-0 { margin-top: 0 !important; padding-top: 0 !important; }
            .mb-4, .my-4, .mt-4 { margin-top: 4px !important; margin-bottom: 4px !important; }
            
            /* ULTRA-STABLE GRID FOR PRINT (Portrait & Landscape) */
            .row { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; align-items: flex-start !important; gap: 15px !important; }
            .col-lg-4, .col-md-5 { width: 32% !important; flex: 0 0 32% !important; max-width: 32% !important; }
            .col-lg-8, .col-md-7 { width: 68% !important; flex: 0 0 68% !important; max-width: 68% !important; }
            
            /* SAFETY ZONE FOR FOOTER - Prevents data overlap */
            .container-fluid { padding-bottom: 2.2cm !important; } 
            
            /* ALLOW MAIN CARDS TO SPLIT - Prevents the whole profile jumping to Page 2 */
            .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 12px !important; border-radius: 8px !important; background: transparent !important; page-break-inside: auto !important; }
            .card-header { background: #eee !important; -webkit-print-color-adjust: exact; font-weight: bold !important; border-bottom: 1px solid #ddd !important; }
            
            .table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 8px !important; page-break-inside: auto !important; }
            .table tr { page-break-inside: avoid !important; page-break-after: auto !important; }
            .table th, .table td { padding: 6px 8px !important; border: 1px solid #ddd !important; font-size: 14px !important; }
            
            /* Protection for block headers to not be isolated */
            h6, h5, .text-uppercase { page-break-after: avoid !important; page-break-inside: avoid !important; }
            
            /* PROTECT SUB-SECTIONS ONLY - These will break to next page if they don't fit */
            .col-12[style*="page-break-inside: avoid;"], .contribution-print-chunk, .mt-4.pt-3[style*="page-break-inside: avoid;"] { 
                page-break-inside: avoid !important; 
                display: block !important;
            }
            
            /* Footer Positioning */
            .print-footer {
                position: fixed; bottom: 0.5cm; left: 0; right: 0; width: 100%;
                background: white !important; font-size: 11px; z-index: 9999;
                text-align: center; padding-top: 10px; border-top: 1px solid #aaa;
                display: block !important;
            }
            @page { 
                margin: 1.5cm 1.5cm 2.5cm 1.5cm;
                size: auto;
            }
            
            /* Ensure tabs content is visible */
            .tab-content > .tab-pane { display: block !important; opacity: 1 !important; visibility: visible !important; }
            .nav-tabs { display: none !important; }

            /* Fix 'hanging'/empty spaces on Page 1 */
            .registration-card { margin: 0 !important; border: 1px solid #eee !important; }
            .mt-5 { margin-top: 15px !important; }
            .pt-5 { padding-top: 15px !important; }
            
            /* Section management - force break if needed to keep segments together */
            .col-12.border-top { page-break-before: auto; margin-top: 10px; }
            .contribution-print-chunk { page-break-inside: avoid; margin-bottom: 20px; }
        }
    </style>

    <!-- WARNING ALERT FOR PENDING MEMBERS -->
    <?php if (isset($member['status']) && $member['status'] === 'pending'): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4 d-flex flex-column flex-md-row align-items-center p-3 p-md-4 d-print-none">
            <div class="d-flex align-items-center w-100 mb-3 mb-md-0">
                <div class="flex-shrink-0">
                    <i class="bi bi-exclamation-octagon-fill fs-3 fs-md-1 me-3 text-warning"></i>
                </div>
                <div class="flex-grow-1">
                    <h5 class="alert-heading fw-bold mb-1 small-mobile-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ombi Linasubiri Uhakiki' : 'Application Pending Verification' ?></h5>
                    <p class="mb-0 small text-dark opacity-75 d-none d-md-block">
                        <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama huyu bado hajahakikiwa na uongozi. Tafadhali kagua taarifa zake kisha chukua hatua.' : 'This member has not yet been verified. Please review the details below then choose an action.' ?>
                    </p>
                    <p class="mb-0 x-small text-dark opacity-75 d-block d-md-none">
                        <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kagua taarifa kisha chagua hatua.' : 'Please review and choose an action.' ?>
                    </p>
                </div>
            </div>

            <!-- DESKTOP BUTTONS (Visible on MD and up) -->
            <div class="ms-md-auto d-none d-md-flex gap-2">
                <button onclick="approveMember(<?= $view_user_id ?>)" class="btn btn-success shadow-sm px-4 fw-bold">
                    <i class="bi bi-check-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'IDHINISHA (APPROVE)' : 'APPROVE' ?>
                </button>
                <button onclick="rejectMember(<?= $view_user_id ?>)" class="btn btn-danger shadow-sm px-4 fw-bold">
                    <i class="bi bi-x-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'KATAA (REJECT)' : 'REJECT' ?>
                </button>
            </div>

            <!-- MOBILE BUTTONS DROPDOWN (Visible on mobile ONLY) -->
            <div class="d-block d-md-none w-100">
                <div class="dropdown">
                    <button class="btn btn-primary w-100 dropdown-toggle shadow-sm d-flex align-items-center justify-content-center" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-lightning-auto me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'CHAGUA HATUA' : 'CHOOSE ACTION' ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow w-100">
                        <li>
                            <a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="approveMember(<?= $view_user_id ?>)">
                                <i class="bi bi-check-circle me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'IDHINISHA (APPROVE)' : 'APPROVE' ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item py-2 text-danger fw-bold" href="javascript:void(0)" onclick="rejectMember(<?= $view_user_id ?>)">
                                <i class="bi bi-x-circle me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'KATAA (REJECT)' : 'REJECT' ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Page Header (Only if NOT editing) -->
    <?php if (!$edit_mode): ?>
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-7">
            <h2><i class="bi bi-person-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasifu wa Mwanachama' : 'Member Profile' ?></h2>
            <p class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Angalia taarifa kamili za mwanachama' : 'View detailed member account information' ?></p>
        </div>
        <div class="col-md-5 text-md-end">
            <?php 
            // Dynamic Back Button Logic
            $back_url = ($ref === 'approvals') ? getUrl('member_approvals') : getUrl('customers');
            ?>
            <?php if ($is_own_profile || in_array($user_role, $viongozi_roles)): ?>
                <a href="?id=<?= $view_user_id ?>&edit=1&ref=<?= htmlspecialchars($ref) ?>" class="btn btn-primary shadow-sm d-print-none">
                    <i class="bi bi-pencil-square"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'HARIRI TAARIFA' : 'EDIT PROFILE' ?>
                </a>
            <?php endif; ?>
            <button class="btn btn-info text-white ms-2 shadow-sm d-print-none" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chapisha' : 'Print' ?>
            </button>
            <a href="<?= $back_url ?>" class="btn btn-outline-primary ms-2 shadow-sm d-print-none">
                <i class="bi bi-arrow-left"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudi' : 'Back' ?>
            </a>
        </div>
    </div>
    <?php endif; ?>


    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-success border-4 d-print-none" role="alert">
                <i class="bi bi-check-circle me-2"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
 
    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-danger border-4 d-print-none" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="row">
        <!-- Left Sidebar - Profile Summary -->
        <div class="col-lg-4 col-md-5">
            <!-- Profile Card -->
            <div class="card shadow-sm border-0 mb-4 overflow-hidden">
                <div class="card-body text-center py-4">
                    <!-- Avatar -->
                    <div class="mb-3 position-relative d-inline-block">
                        <?php if (!empty($member['avatar'])): ?>
                            <img src="uploads/avatars/<?= htmlspecialchars($member['avatar']) ?>" 
                                 class="rounded-circle avatar-lg shadow" alt="Avatar"
                                 style="width: 120px; height: 120px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center text-primary shadow-sm"
                                 style="width: 120px; height: 120px; font-size: 3rem;">
                                <?= strtoupper(substr($member['first_name'] ?? 'M', 0, 1) . substr($member['last_name'] ?? 'W', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h5 class="mb-0 fw-bold"><?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['middle_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?></h5>
                    <p class="text-muted small mb-3"><?= htmlspecialchars($member['role_name'] ?? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member')) ?></p>
                    
                    <?php if ($edit_mode): ?>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#avatarModal">
                            <i class="bi bi-camera me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Picha' : 'Update Photo' ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header border-0 bg-transparent pt-3 px-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muhtasari wa Shughuli' : 'Activity Summary' ?></h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-12 mb-3">
                            <div class="text-xs font-weight-bold text-warning text-uppercase small">
                                <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Shughuli za Leo' : 'Today Activities' ?>
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($activity_stats['today_activities']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header border-0 bg-transparent pt-3 px-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle text-info me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Akaunti' : 'Account Information' ?></h6>
                </div>
                <div class="card-body">
                    <div class="mb-3 border-bottom pb-2">
                        <small class="text-muted d-block"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mtumiaji (Username):' : 'Username:' ?></small>
                        <div class="fw-bold"><?= htmlspecialchars($member['username'] ?? 'N/A') ?></div>
                    </div>
                    <div class="mb-3 border-bottom pb-2">
                        <small class="text-muted d-block"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama tangu:' : 'Member Since:' ?></small>
                        <div class="fw-bold"><?= !empty($member['created_at']) ? date('j M, Y', strtotime($member['created_at'])) : 'N/A' ?></div>
                    </div>
                    <div class="mb-3 border-bottom pb-2">
                        <small class="text-muted d-block"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mara ya mwisho:' : 'Last Activity:' ?></small>
                        <div class="fw-bold small"><?= !empty($member['last_login']) ? date('j M, Y | g:i A', strtotime($member['last_login'])) : ($_SESSION['preferred_language'] === 'sw' ? 'Ndiyo sasa' : 'Just now') ?></div>
                    </div>
                    <div class="mb-3 border-bottom pb-2">
                        <small class="text-muted d-block"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali ya Akaunti:' : 'Account Status:' ?></small>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-<?= ($member['is_active'] ?? 1) == 1 ? 'success' : 'secondary' ?> rounded-pill me-2">
                                <?= ($member['is_active'] ?? 1) == 1 ? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hai (Active)' : 'Active') : (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Haitumiki' : 'Inactive') ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($member['password_changed_at'])): ?>
                        <div class="mb-0">
                            <small class="text-muted d-block"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la siri lilibadilishwa:' : 'Password Last Changed:' ?></small>
                            <div class="fw-bold small"><?= date('j M, Y | g:i A', strtotime($member['password_changed_at'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-8 col-md-7">
            <!-- Profile Tabs -->
            <div class="card shadow-sm border-0 h-100">
                <!-- Navigation Tabs (Dynamic) -->
                <div class="card-header bg-transparent border-0 pt-4 px-4">
                    <ul class="nav nav-tabs card-header-tabs border-bottom" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold border-0 border-bottom border-primary border-3" id="details-tab" data-bs-toggle="tab" 
                                    data-bs-target="#details" type="button" role="tab">
                                <i class="bi bi-person-lines-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa Kamili (Profile)' : 'Account Details' ?>
                            </button>
                        </li>
                        <?php if ($edit_mode || $is_own_profile): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold border-0" id="security-tab" data-bs-toggle="tab" 
                                    data-bs-target="#security" type="button" role="tab">
                                <i class="bi bi-shield-lock me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ulinzi' : 'Security' ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold border-0" id="preferences-tab" data-bs-toggle="tab" 
                                    data-bs-target="#preferences" type="button" role="tab">
                                <i class="bi bi-gear me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendeleo' : 'Preferences' ?>
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="card-body p-4">
                    <div class="tab-content" id="profileTabsContent">
                        <!-- PHASE 1: TAARIFA BINAFSI -->
                        <div class="tab-pane fade show active" id="details" role="tabpanel">
                            <?php if ($edit_mode): ?>
                                <div class="registration-card mx-auto">
                                    <div class="registration-header">
                                        <h4 class="fw-bold mb-0">Update Member Information</h4>
                                        <p class="mb-0 small opacity-75">Modify information for: <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></p>
                                    </div>
                                    <div class="p-4">
                                        <form id="publicRegisterForm" method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="update_profile" value="1">
                                            
                                            <!-- Step Indicators -->
                                            <div class="step-indicator">
                                                <div class="step active" id="step1-mark">1</div>
                                                <div class="step" id="step2-mark">2</div>
                                            </div>

                                            <div class="tab-content">
                                                <!-- STEP 1: PERSONAL -->
                                                <div class="tab-pane fade show active" id="step1-content">
                                                    <h5 class="mb-4 text-primary fw-bold">Step 1: Personal & Residence Information</h5>
                                                    <div class="row g-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-bold small">First Name *</label>
                                                            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($member['first_name']) ?>" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-bold small">Middle Name</label>
                                                            <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($member['middle_name']) ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label fw-bold small">Last Name *</label>
                                                            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($member['last_name']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Email *</label>
                                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($member['email']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Phone *</label>
                                                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($member['phone']) ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Gender *</label>
                                                            <select name="gender" class="form-select" required>
                                                                <option value="Mwanaume" <?= ($member['cust_gender']??'') == 'Mwanaume' ? 'selected' : '' ?>>Male</option>
                                                                <option value="Mwanamke" <?= ($member['cust_gender']??'') == 'Mwanamke' ? 'selected' : '' ?>>Female</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Date of Birth</label>
                                                            <input type="date" name="dob" class="form-control" value="<?= $member['cust_dob'] ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">NIDA Number</label>
                                                            <input type="text" name="nida_number" class="form-control" value="<?= $member['nida_number'] ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dini' : 'Religion' ?></label>
                                                            <select name="religion" class="form-select" required>
                                                                <option value="Ukristo" <?= ($member['religion']??'') == 'Ukristo' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukristo' : 'Christianity' ?></option>
                                                                <option value="Uislamu" <?= ($member['religion']??'') == 'Uislamu' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uislamu' : 'Islam' ?></option>
                                                                <option value="Nyingine" <?= (!in_array($member['religion']??'', ['Ukristo','Uislamu',''])) ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyingine' : 'Other' ?></option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label fw-bold small">Marital Status</label>
                                                            <select name="marital_status" id="marital_status" class="form-select" onchange="toggleFamilyFields(this.value)">
                                                                <option value="Single" <?= ($member['marital_status']??'') == 'Single' ? 'selected' : '' ?>>Single</option>
                                                                <option value="Married" <?= ($member['marital_status']??'') == 'Married' ? 'selected' : '' ?>>Married</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="col-12 mt-4">
                                                            <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold">Current Residence</h6>
                                                            <div class="row g-3">
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Country</label>
                                                                    <input type="text" name="country" class="form-control" value="<?= htmlspecialchars($member['country']??'Tanzania') ?>">
                                                                </div>
                                                                 <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Region</label>
                                                                    <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($member['state'] ?? '') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">District</label>
                                                                    <input type="text" name="district" class="form-control" value="<?= htmlspecialchars($member['district'] ?? '') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Ward</label>
                                                                    <input type="text" name="ward" class="form-control" value="<?= htmlspecialchars($member['ward'] ?? '') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Street</label>
                                                                    <input type="text" name="street" class="form-control" value="<?= htmlspecialchars($member['street'] ?? '') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">House Number</label>
                                                                    <input type="text" name="house_number" class="form-control" value="<?= htmlspecialchars($member['house_number'] ?? '') ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex justify-content-end mt-4">
                                                        <button type="button" class="btn btn-primary btn-next px-4" onclick="switchToStep(2)">
                                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Fuata: Maelezo ya Familia' : 'Next: Family Info' ?> <i class="bi bi-arrow-right ms-1"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- STEP 2: FAMILY & BENEFICIARIES -->
                                                <div class="tab-pane fade" id="step2-content">
                                                    <h5 class="mb-4 text-primary fw-bold">Step 2: Family & Beneficiaries</h5>
                                                    
                                                    <!-- BENEFICIARIES SECTION -->
                                                    <h5 class="mt-4 mb-3 text-dark fw-bold border-bottom pb-2"><i class="bi bi-people-fill me-2"></i>BENEFICIARIES</h5>

                                                    <!-- 1: PARENTS -->
                                                    <div class="mb-4">
                                                        <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-person-heart me-2"></i>1. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WAZAZI WA MWANACHAMA' : 'MEMBER\'S PARENTS INFORMATION' ?></h6>
                                                        <div class="row g-4">
                                                            <!-- Father -->
                                                            <div class="col-md-6 border-end">
                                                                <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA BABA' : 'FATHER\'S DETAILS' ?></p>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">FATHER'S NAME</label>
                                                                    <input type="text" name="father_name" class="form-control form-control-sm" placeholder="Full Name" value="<?= htmlspecialchars($member['father_name']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">REGION/DISTRICT WHERE LIVING</label>
                                                                    <input type="text" name="father_location" class="form-control form-control-sm" placeholder="Location" value="<?= htmlspecialchars($member['father_location']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">WARD/VILLAGE/STREET</label>
                                                                    <input type="text" name="father_sub_location" class="form-control form-control-sm" placeholder="Sub-location" value="<?= htmlspecialchars($member['father_sub_location']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">PHONE NUMBER</label>
                                                                    <input type="tel" name="father_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx" value="<?= htmlspecialchars($member['father_phone']??'') ?>">
                                                                </div>
                                                            </div>
                                                            <!-- Mother -->
                                                            <div class="col-md-6">
                                                                <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA MAMA' : 'MOTHER\'S DETAILS' ?></p>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">MOTHER'S NAME</label>
                                                                    <input type="text" name="mother_name" class="form-control form-control-sm" placeholder="Full Name" value="<?= htmlspecialchars($member['mother_name']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">REGION/DISTRICT WHERE LIVING</label>
                                                                    <input type="text" name="mother_location" class="form-control form-control-sm" placeholder="Location" value="<?= htmlspecialchars($member['mother_location']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">WARD/VILLAGE/STREET</label>
                                                                    <input type="text" name="mother_sub_location" class="form-control form-control-sm" placeholder="Sub-location" value="<?= htmlspecialchars($member['mother_sub_location']??'') ?>">
                                                                </div>
                                                                <div class="mb-2">
                                                                    <label class="form-label small mb-1 fw-bold">PHONE NUMBER</label>
                                                                    <input type="tel" name="mother_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx" value="<?= htmlspecialchars($member['mother_phone']??'') ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- 2: SPOUSE -->
                                                    <div id="familyFields" style="<?= ($member['marital_status']??'') == 'Married' ? '' : 'display: none;' ?>">
                                                        <div class="mb-4">
                                                            <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-heart-fill me-2"></i>2. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA MWENZI' : 'WIFE/HUSBAND INFORMATION' ?></h6>
                                                            <div class="row g-3">
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">First Name</label>
                                                                    <input type="text" name="spouse_first_name" class="form-control" placeholder="First Name" value="<?= htmlspecialchars($member['spouse_first_name']??'') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Middle Name</label>
                                                                    <input type="text" name="spouse_middle_name" class="form-control" placeholder="Middle Name" value="<?= htmlspecialchars($member['spouse_middle_name']??'') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Last Name</label>
                                                                    <input type="text" name="spouse_last_name" class="form-control" placeholder="Last Name" value="<?= htmlspecialchars($member['spouse_last_name']??'') ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small">Email</label>
                                                                    <input type="email" name="spouse_email" class="form-control" placeholder="spouse@example.com" value="<?= htmlspecialchars($member['spouse_email']??'') ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small">Phone</label>
                                                                    <input type="tel" name="spouse_phone" class="form-control" placeholder="0xxxxxxxxx" value="<?= htmlspecialchars($member['spouse_phone']??'') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">Gender</label>
                                                                    <select name="spouse_gender" class="form-select">
                                                                        <option value="">Select...</option>
                                                                        <option value="Mwanaume" <?= ($member['spouse_gender']??'') == 'Mwanaume' ? 'selected' : '' ?>>Male</option>
                                                                        <option value="Mwanamke" <?= ($member['spouse_gender']??'') == 'Mwanamke' ? 'selected' : '' ?>>Female</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small">DOB</label>
                                                                    <input type="date" name="spouse_dob" class="form-control" value="<?= htmlspecialchars($member['spouse_dob']??'') ?>">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dini' : 'Religion' ?></label>
                                                                    <div id="spouse_religion_wrapper">
                                                                        <select name="spouse_religion" class="form-select" onchange="handleSpouseReligionChange(this)">
                                                                            <option value="Ukristo" <?= ($member['spouse_religion']??'') == 'Ukristo' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukristo' : 'Christianity' ?></option>
                                                                            <option value="Uislamu" <?= ($member['spouse_religion']??'') == 'Uislamu' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uislamu' : 'Islam' ?></option>
                                                                            <option value="Nyingine" <?= (!in_array($member['spouse_religion']??'', ['Ukristo','Uislamu',''])) ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyingine' : 'Other' ?></option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small">NIDA Number</label>
                                                                    <input type="text" name="spouse_nida" class="form-control" placeholder="NIDA Number" value="<?= htmlspecialchars($member['spouse_nida']??'') ?>">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label fw-bold small">Region of Birth</label>
                                                                    <input type="text" name="spouse_birth_region" class="form-control" placeholder="Birth Region" value="<?= htmlspecialchars($member['spouse_birth_region']??'') ?>">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- 3: CHILDREN -->
                                                    <div class="mb-4">
                                                        <h6 class="text-danger border-bottom pb-2 mb-3 fw-bold"><span class="me-2 badge bg-danger">3</span><i class="bi bi-people-fill me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WATOTO' : 'MEMBER\'S CHILDREN INFORMATION' ?></h6>
                                                        <div class="table-responsive">
                                                            <table class="table table-bordered table-sm align-middle" id="childrenTable">
                                                                <thead class="bg-light small">
                                                                    <tr>
                                                                         <th class="text-center" style="width: 50px;">S/NO</th>
                                                                         <th>CHILD NAME</th>
                                                                         <th style="width: 100px;">AGE</th>
                                                                        <th style="width: 100px;">GENDER</th>
                                                                        <th class="text-center" style="width: 40px;">#</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody id="childrenList">
                                                                    <?php 
                                                                    $children = json_decode($member['children_data'] ?? '[]', true);
                                                                    if (empty($children)) $children = [['name'=>'','age'=>'','gender'=>'']];
                                                                    foreach ($children as $idx => $child):
                                                                    ?>
                                                                    <tr class="child-row">
                                                                        <td class="text-center fw-bold row-idx"><?= $idx+1 ?></td>
                                                                        <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" value="<?= $child['name'] ?>"></td>
                                                                        <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" value="<?= $child['age'] ?>"></td>
                                                                        <td>
                                                                            <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                                                                                <option value="Mwanaume" <?= ($child['gender']??'') == 'Mwanaume' ? 'selected' : '' ?>>Male</option>
                                                                                <option value="Mwanamke" <?= ($child['gender']??'') == 'Mwanamke' ? 'selected' : '' ?>>Female</option>
                                                                            </select>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
                                                                        </td>
                                                                    </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                         <button type="button" class="btn btn-sm btn-outline-primary rounded-pill mt-2" onclick="addChildRow()">
                                                             <i class="bi bi-plus-circle me-1"></i> Add Child
                                                         </button>
                                                     </div>

                                                     <!-- GUARANTOR INFORMATION -->
                                                     <div class="mb-4">
                                                         <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-shield-check me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MDHAMINI WA MWANACHAMA' : 'MEMBER\'S GUARANTOR' ?></h6>
                                                         <div class="row g-3">
                                                             <div class="col-md-6">
                                                                 <label class="form-label fw-bold small">GUARANTOR'S NAME</label>
                                                                 <input type="text" name="guarantor_name" class="form-control" placeholder="Full Name" value="<?= htmlspecialchars($member['guarantor_name']??'') ?>">
                                                             </div>
                                                             <div class="col-md-6">
                                                                 <label class="form-label fw-bold small">PHONE NUMBER</label>
                                                                 <input type="tel" name="guarantor_phone" class="form-control" placeholder="0xxxxxxxxx" value="<?= htmlspecialchars($member['guarantor_phone']??'') ?>">
                                                             </div>
                                                             <div class="col-md-6">
                                                                 <label class="form-label fw-bold small">RELATIONSHIP WITH MEMBER</label>
                                                                 <input type="text" name="guarantor_rel" class="form-control" placeholder="Relationship" value="<?= htmlspecialchars($member['guarantor_rel']??'') ?>">
                                                             </div>
                                                             <div class="col-md-6">
                                                                 <label class="form-label fw-bold small">REGION WHERE LIVING</label>
                                                                 <input type="text" name="guarantor_location" class="form-control" placeholder="Location" value="<?= htmlspecialchars($member['guarantor_location']??'') ?>">
                                                             </div>
                                                         </div>
                                                     </div>

                                                    <div class="d-flex justify-content-between mt-5 pt-3 border-top">
                                                        <button type="button" class="btn btn-outline-secondary btn-next px-4" onclick="switchToStep(1)">
                                                            <i class="bi bi-arrow-left me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudi Nyuma' : 'Back' ?>
                                                        </button>
                                                        <button type="submit" class="btn btn-success btn-submit px-5 shadow">
                                                            <i class="bi bi-save me-1"></i> SAVE CHANGES
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- VIEW MODE CONTENT (Already exists, leaving it as it is or slightly polishing) -->
                                <div class="row g-4">
                                    <?php $isSw_b = ($_SESSION['preferred_language'] ?? 'en') === 'sw'; ?>
                                    <!-- Basic Information -->
                                    <div class="col-12" style="page-break-inside: avoid;">
                                        <h6 class="text-uppercase text-muted fw-bold small mb-3 border-bottom pb-2">
                                            <i class="bi bi-person-fill me-1 text-primary"></i>
                                            <?= $isSw_b ? 'Taarifa za Msingi' : 'Basic Information' ?>
                                        </h6>
                                        <div class="row g-0">
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm" style="font-size: 0.85rem;">
                                                    <tr>
                                                        <td class="text-muted" style="width:160px;"><?= $isSw_b ? 'Jina Kamili:' : 'Full Name:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['middle_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Barua Pepe:' : 'Email:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['email'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Simu:' : 'Phone:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['phone'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Jinsia:' : 'Gender:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['cust_gender'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Tarehe ya Kuzaliwa:' : 'Date of Birth:' ?></td>
                                                        <td class="fw-bold"><?= !empty($member['cust_dob']) ? date('j M, Y', strtotime($member['cust_dob'])) : 'N/A' ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm" style="font-size: 0.85rem;">
                                                    <tr>
                                                        <td class="text-muted" style="width:160px;"><?= $isSw_b ? 'Namba ya NIDA:' : 'NIDA Number:' ?></td>
                                                        <td class="fw-bold text-primary"><?= htmlspecialchars($member['nida_number'] ?: 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Dini:' : 'Religion:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['religion'] ?: 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Mkoa wa Kuzaliwa:' : 'Birth Region:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['cust_birth_region'] ?: 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Hali ya Ndoa:' : 'Marital Status:' ?></td>
                                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($member['marital_status'] ?: 'N/A') ?></span></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Residence Information -->
                                    <div class="col-12 border-top pt-3" style="page-break-inside: avoid;">
                                        <h6 class="text-uppercase text-muted fw-bold small mb-3">
                                            <i class="bi bi-geo-alt-fill me-1 text-success"></i>
                                            <?= $isSw_b ? 'Taarifa za Makazi' : 'Residence Information' ?>
                                        </h6>
                                        <div class="row g-0">
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm" style="font-size: 0.85rem;">
                                                    <tr>
                                                        <td class="text-muted" style="width:160px;"><?= $isSw_b ? 'Nchi:' : 'Country:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['country'] ?? 'Tanzania') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Mkoa:' : 'Region:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['state'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Wilaya:' : 'District:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['district'] ?? 'N/A') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm" style="font-size: 0.85rem;">
                                                    <tr>
                                                        <td class="text-muted" style="width:160px;"><?= $isSw_b ? 'Kata:' : 'Ward:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['ward'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Mtaa:' : 'Street:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['street'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_b ? 'Namba ya Nyumba:' : 'House No:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['house_number'] ?? 'N/A') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>


                                <?php
                                // Parse children data for view mode
                                $view_children = json_decode($member['children_data'] ?? '[]', true);
                                if (!is_array($view_children)) $view_children = [];
                                $isSw_p = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
                                ?>


                                <!-- BENEFICIARIES SECTION -->
                                <div class="mt-4 pt-3 border-top">
                                    <h6 class="text-uppercase fw-bold mb-4" style="color:#d35400; font-size: 0.85rem; letter-spacing: 1px;">
                                        <i class="bi bi-people-fill me-2"></i>
                                        <?= $isSw_p ? 'WANUFAIKA (BENEFICIARIES)' : 'BENEFICIARIES' ?>
                                    </h6>

                                    <!-- 1. Wazazi / Parents -->
                                    <div class="mb-4">
                                        <p class="text-muted small fw-bold text-uppercase mb-2" style="font-size: 0.7rem;"><i class="bi bi-person-heart text-warning me-1"></i> 1. <?= $isSw_p ? 'TAARIFA ZA WAZAZI' : 'MEMBER\'S PARENTS INFORMATION' ?></p>
                                        <div class="row g-3">
                                            <div class="col-md-6 border-end">
                                                <p class="fw-bold small text-muted mb-1" style="font-size: 0.65rem;"><?= $isSw_p ? 'TAARIFA ZA BABA' : "FATHER'S DETAILS" ?></p>
                                                <table class="table table-borderless table-sm mb-0" style="font-size: 0.82rem;">
                                                    <tr><td class="text-muted" style="width:80px;"><?= $isSw_p ? 'Jina:' : 'Name:' ?></td><td class="fw-bold text-primary"><?= htmlspecialchars($member['father_name'] ?? 'N/A') ?></td></tr>
                                                    <tr><td class="text-muted"><?= $isSw_p ? 'Simu:' : 'Phone:' ?></td><td class="fw-bold"><?= htmlspecialchars($member['father_phone'] ?? 'N/A') ?></td></tr>
                                                    <tr><td class="text-muted"><?= $isSw_p ? 'Mahali:' : 'Location:' ?></td><td><?= htmlspecialchars($member['father_location'] ?? 'N/A') ?></td></tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="fw-bold small text-muted mb-1" style="font-size: 0.65rem;"><?= $isSw_p ? 'TAARIFA ZA MAMA' : "MOTHER'S DETAILS" ?></p>
                                                <table class="table table-borderless table-sm mb-0" style="font-size: 0.82rem;">
                                                    <tr><td class="text-muted" style="width:80px;"><?= $isSw_p ? 'Jina:' : 'Name:' ?></td><td class="fw-bold text-primary"><?= htmlspecialchars($member['mother_name'] ?? 'N/A') ?></td></tr>
                                                    <tr><td class="text-muted"><?= $isSw_b ? 'Simu:' : 'Phone:' ?></td><td class="fw-bold"><?= htmlspecialchars($member['mother_phone'] ?? 'N/A') ?></td></tr>
                                                    <tr><td class="text-muted"><?= $isSw_p ? 'Mahali:' : 'Location:' ?></td><td><?= htmlspecialchars($member['mother_location'] ?? 'N/A') ?></td></tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (($member['marital_status'] ?? 'Single') === 'Married'): ?>
                                    <!-- 2. Mwenzi / Spouse -->
                                    <div class="mb-4 pt-2">
                                        <p class="text-muted small fw-bold text-uppercase mb-2" style="font-size: 0.7rem;"><i class="bi bi-heart-fill text-danger me-1"></i> 2. <?= $isSw_p ? 'TAARIFA ZA MWENZI' : 'WIFE/HUSBAND INFORMATION' ?></p>
                                        <div class="row g-2" style="font-size: 0.82rem;">
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm mb-0">
                                                    <tr>
                                                        <td class="text-muted" style="width:130px;"><?= $isSw_p ? 'Jina Kamili:' : 'Full Name:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars(trim(($member['spouse_first_name']??'').' '.($member['spouse_middle_name']??'').' '.($member['spouse_last_name']??''))) ?: 'N/A' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Barua Pepe:' : 'Email:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_email'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Simu:' : 'Phone:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_phone'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Jinsia:' : 'Gender:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_gender'] ?? 'N/A') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="col-md-6">
                                                <table class="table table-borderless table-sm mb-0">
                                                    <tr>
                                                        <td class="text-muted" style="width:150px;"><?= $isSw_p ? 'Tarehe ya Kuzaliwa:' : 'Date of Birth:' ?></td>
                                                        <td class="fw-bold"><?= !empty($member['spouse_dob']) ? date('j M, Y', strtotime($member['spouse_dob'])) : 'N/A' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Dini:' : 'Religion:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_religion'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Namba ya NIDA:' : 'NIDA No:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_nida'] ?? 'N/A') ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted"><?= $isSw_p ? 'Mkoa wa Kuzaliwa:' : 'Birth Region:' ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($member['spouse_birth_region'] ?? 'N/A') ?></td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- 3. Watoto / Children -->
                                    <div class="mb-4 pt-2">
                                        <p class="text-muted small fw-bold text-uppercase mb-2" style="font-size: 0.7rem;"><i class="bi bi-person-check-fill text-primary me-1"></i> 3. <?= $isSw_p ? 'TAARIFA ZA WATOTO' : 'MEMBER\'S CHILDREN INFORMATION' ?> (<?= count($view_children) ?>)</p>
                                        <?php if (!empty($view_children)): ?>
                                        <table class="table table-sm table-bordered mb-0" style="font-size: 0.82rem;">
                                            <thead class="bg-light"><tr><th><?= $isSw_p ? 'Jina la Mtoto' : 'Child Name' ?></th><th style="width:80px;" class="text-center"><?= $isSw_p ? 'Umri' : 'Age' ?></th><th style="width:100px;"><?= $isSw_p ? 'Jinsia' : 'Gender' ?></th></tr></thead>
                                            <tbody>
                                                <?php foreach ($view_children as $vc): ?>
                                                <tr><td class="fw-semibold"><?= htmlspecialchars($vc['name'] ?? '') ?></td><td class="text-center"><?= htmlspecialchars($vc['age'] ?? '') ?></td><td><?= htmlspecialchars($vc['gender'] ?? '') ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php else: ?>
                                        <p class="text-muted small"><i class="bi bi-info-circle me-1"></i><?= $isSw_p ? 'Hakuna taarifa za watoto zilizosajiliwa.' : 'No children information recorded.' ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>


                                <!-- Mdhamini + Kiingilio -->
                                <div class="mt-3 pt-3 border-top" style="page-break-inside: avoid;">
                                    <div class="row g-4">
                                        <!-- Mdhamini -->
                                        <div class="col-md-6">
                                            <p class="text-uppercase fw-bold small mb-2" style="font-size: 0.7rem; color: #0dcaf0;"><i class="bi bi-shield-lock-fill me-1"></i> <?= $isSw_p ? 'MDHAMINI WA MWANACHAMA' : "MEMBER'S GUARANTOR" ?></p>
                                            <table class="table table-borderless table-sm mb-0" style="font-size: 0.82rem;">
                                                <tr><td class="text-muted" style="width:90px;"><?= $isSw_p ? 'Jina:' : 'Name:' ?></td><td class="fw-bold"><?= htmlspecialchars($member['guarantor_name'] ?? 'N/A') ?></td></tr>
                                                <tr><td class="text-muted"><?= $isSw_p ? 'Simu:' : 'Phone:' ?></td><td class="fw-bold"><?= htmlspecialchars($member['guarantor_phone'] ?? 'N/A') ?></td></tr>
                                                <tr><td class="text-muted"><?= $isSw_p ? 'Uhusiano:' : 'Relationship:' ?></td><td><?= htmlspecialchars($member['guarantor_rel'] ?? 'N/A') ?></td></tr>
                                                <tr><td class="text-muted"><?= $isSw_p ? 'Mahali:' : 'Location:' ?></td><td><?= htmlspecialchars($member['guarantor_location'] ?? 'N/A') ?></td></tr>
                                            </table>
                                        </div>
                                        <!-- Kiingilio / Entrance Fee -->
                                        <div class="col-md-6 border-start">
                                            <p class="text-uppercase fw-bold small mb-2" style="font-size: 0.7rem; color: #198754;"><i class="bi bi-cash-coin me-1"></i> <?= $isSw_p ? 'KIINGILIO (ADA YA USAJILI)' : 'ENTRANCE / REGISTRATION FEE' ?></p>
                                            <div class="p-3 bg-light rounded text-center mt-1">
                                                <p class="text-muted small mb-1"><?= $isSw_p ? 'Kiasi kilicholipwa wakati wa usajili:' : 'Amount paid at registration:' ?></p>
                                                <h3 class="fw-bold text-success mb-0"><?= number_format($member['initial_savings'] ?? 0, 0) ?> <span class="fs-6 text-muted"><?= $isSw_p ? 'Tsh' : 'TZS' ?></span></h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>


                                <!-- CONTRIBUTION ANALYSIS SECTION (NEWLY ADDED) -->
                                <?php if ($contribution_data): ?>
                                <div class="mt-5 pt-4 border-top">
                                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                                        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchanganuo wa Michango ya Kila Mwezi' : 'Monthly Contribution Analysis' ?></h6>
                                            <span class="badge bg-light text-dark border"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchango: ' : 'Monthly: ' ?> <?= number_format($contribution_data['monthly_amt'], 0) ?> TZS</span>
                                        </div>
                                        <div class="card-body p-0">
                                            <!-- SCREEN VERSION (Hidden in Print) -->
                                            <div class="table-responsive w-100 d-print-none">
                                                <table id="monthly-analysis-table" class="table table-bordered align-middle mb-0 text-center w-100" style="table-layout: auto;">
                                                    <thead class="bg-light small fw-bold text-uppercase">
                                                        <tr>
                                                            <th class="py-3 bg-light analysis-header-col" style="min-width: 150px; position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'PERIOD / MWEZI' : 'PERIOD / MONTH' ?></th>
                                                            <?php foreach($contribution_data['distribution'] as $d): ?>
                                                                <th style="min-width: 100px;"><?= $d['label'] ?></th>
                                                            <?php endforeach; ?>
                                                            <th class="bg-dark text-white" style="min-width: 120px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'JUMLA (TOTAL)' : 'TOTAL' ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td class="fw-bold bg-light text-start ps-3 analysis-header-col" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiwango Inatakiwa' : 'Amount Target' ?></td>
                                                            <?php foreach($contribution_data['distribution'] as $d): ?>
                                                                <td class="bg-light"><?= number_format($d['target'], 0) ?></td>
                                                            <?php endforeach; ?>
                                                            <?php 
                                                            $total_required = 0;
                                                            foreach($contribution_data['distribution'] as $d) { $total_required += $d['target']; }
                                                            ?>
                                                            <td class="bg-light fw-bold"><?= number_format($total_required, 0) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-3 bg-white analysis-header-col" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi Kilicholipwa' : 'Actual Paid' ?></td>
                                                            <?php foreach($contribution_data['distribution'] as $d): ?>
                                                                <td class="<?= $d['status'] === 'paid' ? 'bg-success text-white' : ($d['status'] === 'partial' ? 'bg-warning text-dark' : 'bg-danger text-white border-danger border-opacity-25') ?>">
                                                                    <?= number_format($d['amount'], 0) ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                            <td class="fw-bold fs-5 bg-dark text-white border-0"><?= number_format($contribution_data['total_paid'] - $contribution_data['entrance_paid'], 0) ?></td>
                                                        </tr>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-3 bg-white analysis-header-col" style="position: sticky; left: 0; z-index: 10;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Baki (Balance)' : 'Balance' ?></td>
                                                            <?php foreach($contribution_data['distribution'] as $d): ?>
                                                                <td class="small text-muted font-monospace">
                                                                    <?= number_format(max(0, $d['target'] - $d['amount']), 0) ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                            <td class="small text-muted bg-light">—</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- PRINT VERSION (Broken into 6-month chunks) -->
                                            <div class="d-none d-print-block p-3">
                                                <?php 
                                                $chunks = array_chunk($contribution_data['distribution'], 6);
                                                foreach($chunks as $index => $chunk): 
                                                ?>
                                                <div class="contribution-print-chunk mb-4">
                                                    <h6 class="fw-bold small mb-2 text-muted border-bottom pb-1">
                                                        <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sehemu ya' : 'Section' ?> <?= $index + 1 ?>: 
                                                        <?= $chunk[0]['label'] ?> - <?= end($chunk)['label'] ?>
                                                    </h6>
                                                    <table class="table table-bordered text-center w-100" style="table-layout: fixed;">
                                                        <thead class="bg-light x-small fw-bold">
                                                            <tr>
                                                                <th style="width: 20%;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali' : 'Record' ?></th>
                                                                <?php foreach($chunk as $d): ?>
                                                                    <th><?= $d['label'] ?></th>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="x-small">
                                                            <tr>
                                                                <td class="fw-bold text-start ps-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lengo' : 'Target' ?></td>
                                                                <?php foreach($chunk as $d): ?>
                                                                    <td><?= number_format($d['target'], 0) ?></td>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                            <tr>
                                                                <td class="fw-bold text-start ps-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imelipwa' : 'Paid' ?></td>
                                                                <?php foreach($chunk as $d): ?>
                                                                    <td class="<?= $d['status'] === 'paid' ? 'bg-success' : ($d['status'] === 'partial' ? 'bg-warning' : 'bg-danger') ?>">
                                                                        <?= number_format($d['amount'], 0) ?>
                                                                    </td>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-white py-3 small d-flex flex-wrap align-items-center gap-3">
                                            <div class="d-flex align-items-center"><span class="badge bg-success me-1">&nbsp;</span> Fully Paid</div>
                                            <div class="d-flex align-items-center"><span class="badge bg-warning me-1">&nbsp;</span> Partial Payment</div>
                                            <div class="d-flex align-items-center"><span class="badge bg-danger me-1">&nbsp;</span> Not Paid</div>
                                            <div class="ms-auto text-muted font-italic">Starting from: <?= date('d M Y', strtotime($contribution_data['start_date'])) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>

                        <!-- Security Tab -->
                        <?php if ($edit_mode || $is_own_profile): ?>
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <h6 class="mb-4 text-primary border-bottom pb-2 fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Neno la Siri' : 'Security & Password' ?></h6>
                            
                            <form method="POST">
                                <?php if ($is_own_profile): ?>
                                <div class="mb-4">
                                    <label for="current_password" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la siri la sasa *' : 'Current Password *' ?></label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <?php endif; ?>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno jipya la siri *' : 'New Password *' ?></label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                                            <div class="form-text small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Isipungue herufi 8' : 'Minimum 8 characters' ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Thibitisha neno jipya *' : 'Confirm New Password *' ?></label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="password-strength mb-3">
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar" id="passwordStrengthBar" style="width: 0%"></div>
                                    </div>
                                    <small class="text-muted small" id="passwordStrengthText"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ubora wa neno la siri' : 'Password Strength' ?></small>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="change_password" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                                        <i class="bi bi-key-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sasisha Neno la Siri' : 'Update Password' ?>
                                    </button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <h6 class="mb-3 fw-bold small text-muted text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uthibitishaji wa Hatua Mbili (2FA)' : 'Two-Factor Authentication (2FA)' ?></h6>
                            <div class="alert alert-warning border-0 shadow-sm rounded-3">
                                <i class="bi bi-shield-exclamation me-2"></i> 
                                <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uthibitishaji wa hatua mbili haujawezeshwa kwenye akaunti yako.' : 'Two-factor authentication is not yet enabled on your account.' ?>
                                <a href="#" class="alert-link ms-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wezesha 2FA' : 'Enable 2FA' ?></a>
                            </div>

                            <h6 class="mb-3 fw-bold small text-muted text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Vipindi (Sessions)' : 'Active Sessions' ?></h6>
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-danger btn-sm rounded-pill" id="logoutOtherSessions">
                                    <i class="bi bi-box-arrow-right me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ondoa Vipindi Vingine Vyote' : 'Logout Other Sessions' ?>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($edit_mode || $is_own_profile): ?>
                        <!-- Preferences Tab -->
                        <div class="tab-pane fade" id="preferences" role="tabpanel">
                            <form method="POST">
                                <h6 class="mb-4 text-primary border-bottom pb-2 fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendeleo ya Mfumo' : 'System Preferences' ?></h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="theme" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mandhari (Theme)' : 'Display Theme' ?></label>
                                            <select class="form-select" id="theme" name="theme">
                                                <option value="light" <?= ($preferences['theme']??'light') == 'light' ? 'selected' : '' ?>>Light Mode</option>
                                                <option value="dark" <?= ($preferences['theme']??'') == 'dark' ? 'selected' : '' ?>>Dark Mode</option>
                                                <option value="auto" <?= ($preferences['theme']??'') == 'auto' ? 'selected' : '' ?>>Auto (System)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="language" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lugha ya Mfumo' : 'System Language' ?></label>
                                            <select class="form-select" id="language" name="language">
                                                <option value="en" <?= ($preferences['language']??'en') == 'en' ? 'selected' : '' ?>>English</option>
                                                <option value="sw" <?= ($preferences['language']??'') == 'sw' ? 'selected' : '' ?>>Kiswahili</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="results_per_page" class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Matokeo kwa kila Ukurasa' : 'Table Results Per Page' ?></label>
                                    <select class="form-select" id="results_per_page" name="results_per_page">
                                        <option value="10" <?= ($preferences['results_per_page']??10) == 10 ? 'selected' : '' ?>>10</option>
                                        <option value="25" <?= ($preferences['results_per_page']??25) == 25 ? 'selected' : '' ?>>25</option>
                                        <option value="50" <?= ($preferences['results_per_page']??50) == 50 ? 'selected' : '' ?>>50</option>
                                        <option value="100" <?= ($preferences['results_per_page']??100) == 100 ? 'selected' : '' ?>>100</option>
                                    </select>
                                </div>

                                <h6 class="mt-4 mb-3 fw-bold small text-muted text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendeleo ya Taarifa (Notifications)' : 'Notification Preferences' ?></h6>
                                
                                <div class="card bg-light border-0 rounded-3 p-3 mb-4">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="notifications_email" 
                                               name="notifications_email" value="1" 
                                               <?= ($preferences['notifications_email']??true) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold" for="notifications_email">
                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa kwa Barua Pepe' : 'Email Alerts' ?>
                                        </label>
                                        <div class="form-text small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pokea taarifa kupitia barua pepe' : 'Receive important system notifications via email' ?></div>
                                    </div>
                                    
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifications_sms" 
                                               name="notifications_sms" value="1" 
                                               <?= ($preferences['notifications_sms']??false) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold" for="notifications_sms">
                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa kwa SMS' : 'SMS Alerts' ?>
                                        </label>
                                        <div class="form-text small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pokea taarifa kupitia SMS (kama namba imewekwa)' : 'Receive instant SMS alerts for critical actions' ?></div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 d-flex align-items-center">
                                    <button type="submit" name="update_preferences" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                                        <i class="bi bi-save2-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mapendeleo' : 'Save Preferences' ?>
                                    </button>
                                    <button type="button" class="btn btn-link text-muted text-decoration-none ms-3 small" id="resetPreferences">
                                        <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudisha ya Awali' : 'Reset to Default' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- Activity Tab (Only in Edit Mode) -->
                        <?php if ($edit_mode): ?>
                        <div class="tab-pane fade" id="activity" role="tabpanel">
                            <h6 class="mb-4 text-primary border-bottom pb-2 fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Shughuli za Hivi Karibuni' : 'Recent System Activity' ?></h6>
                            
                            <?php if (!empty($recent_activities)): ?>
                                <div class="list-group list-group-flush shadow-sm rounded-3">
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="list-group-item px-3 py-3 border-light">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1 small fw-bold text-dark"><?= htmlspecialchars($activity['action']) ?></h6>
                                                    <p class="mb-0 text-muted small" style="font-size: 0.75rem;"><?= htmlspecialchars($activity['resource']) ?></p>
                                                </div>
                                                <small class="text-muted" style="font-size: 0.7rem;">
                                                    <i class="bi bi-clock me-1"></i> <?= date('j M Y, g:i A', strtotime($activity['timestamp'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 bg-light rounded-3">
                                    <i class="bi bi-clock-history text-muted opacity-25" style="font-size: 3rem;"></i>
                                    <p class="text-muted mt-3 mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna shughuli iliyopatikana.' : 'No recent activities found.' ?></p>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4 text-center">
                                <button class="btn btn-outline-primary btn-sm rounded-pill px-4" id="loadMoreActivity">
                                    <i class="bi bi-arrow-clockwise me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ongeza Nyingine' : 'Load More' ?>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Avatar Upload Modal -->
<div class="modal fade" id="avatarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Picha ya Wasifu' : 'Update Profile Photo' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label for="avatar" class="form-label small fw-bold text-muted text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua Picha Mpya' : 'Select New Photo' ?></label>
                        <input type="file" class="form-control rounded-pill" id="avatar" name="avatar" accept="image/*">
                        <div class="form-text small mt-2">
                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Picha zinazokubalika: JPG, PNG, GIF. Ukubwa usizidi 2MB.' : 'Allowed formats: JPG, PNG, GIF. Max file size: 2MB.' ?>
                        </div>
                    </div>
                    
                    <div class="text-center p-3 bg-light rounded-4">
                        <div id="avatarPreview" class="mb-0">
                            <?php if (!empty($member['avatar'])): ?>
                                <img src="uploads/avatars/<?= htmlspecialchars($member['avatar']) ?>" 
                                     class="rounded-circle shadow avatar-preview" alt="Current Avatar"
                                     style="width: 140px; height: 140px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center text-primary shadow-sm avatar-preview"
                                     style="width: 140px; height: 140px; font-size: 3.5rem;">
                                    <?= strtoupper(substr($member['first_name'] ?? 'M', 0, 1) . substr($member['last_name'] ?? 'W', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-muted small mt-3 mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muonekano wa Sasa' : 'Current Preview' ?></p>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" name="upload_avatar" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                        <i class="bi bi-cloud-upload me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Picha' : 'Upload Photo' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<style>
.card {
    border: none;
    border-radius: 0.5rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 500;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 3px solid #0d6efd;
    background: transparent;
}

.avatar-lg {
    width: 120px;
    height: 120px;
    object-fit: cover;
}

.avatar-preview {
    width: 150px;
    height: 150px;
    object-fit: cover;
}

.text-xs {
    font-size: 0.7rem;
}

.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
}

.password-strength .progress {
    height: 5px;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #e9ecef;
}

.list-group-item:last-child {
    border-bottom: none;
}

@media (max-width: 768px) {
    .analysis-header-col {
        min-width: 100px !important;
        font-size: 0.65rem !important;
        white-space: nowrap !important;
        padding-left: 8px !important;
        padding-right: 8px !important;
    }
}
</style>

<script>
$(document).ready(function() {
    // Password strength indicator
    $('#new_password').on('input', function() {
        const password = $(this).val();
        const strength = calculatePasswordStrength(password);
        
        $('#passwordStrengthBar')
            .css('width', strength.percentage + '%')
            .removeClass('bg-danger bg-warning bg-success')
            .addClass(strength.class);
        
        $('#passwordStrengthText')
            .text(strength.text)
            .removeClass('text-danger text-warning text-success')
            .addClass(strength.class.replace('bg-', 'text-'));
    });

    function calculatePasswordStrength(password) {
        let score = 0;
        
        if (password.length >= 8) score += 25;
        if (password.length >= 12) score += 25;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score += 25;
        if (/\d/.test(password)) score += 15;
        if (/[^a-zA-Z0-9]/.test(password)) score += 10;
        
        if (score < 25) return { percentage: 25, class: 'bg-danger', text: 'Dhaifu' };
        if (score < 50) return { percentage: 50, class: 'bg-warning', text: 'Ya Kawaida' };
        if (score < 75) return { percentage: 75, class: 'bg-info', text: 'Nzuri' };
        return { percentage: 100, class: 'bg-success', text: 'Imara Sana' };
    }

    // Avatar preview
    $('#avatar').change(function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#avatarPreview').html(`<img src="${e.target.result}" class="rounded-circle avatar-preview" alt="Preview">`);
            }
            reader.readAsDataURL(file);
        }
    });

    // Reset preferences
    $('#resetPreferences').click(function() {
        if (confirm('Are you sure you want to reset all preferences to default values?')) {
            $('#theme').val('light');
            $('#language').val('en');
            $('#results_per_page').val(25);
            $('#notifications_email').prop('checked', true);
            $('#notifications_sms').prop('checked', false);
            showToast('info', 'Preferences reset to defaults. Click "Save Preferences" to apply.');
        }
    });

    // Load more activity
    $('#loadMoreActivity').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Loading...');
        
        // Simulate loading more activity
        setTimeout(() => {
            showToast('info', 'More activity loaded');
            btn.prop('disabled', false).html(originalText);
        }, 1000);
    });

    // Export activity
    $('#exportActivity').click(function() {
        showToast('info', 'Preparing activity export...');
        setTimeout(() => {
            window.open('api/export_activity.php', '_blank');
            showToast('success', 'Activity export completed');
        }, 1500);
    });

    // Logout other sessions
    $('#logoutOtherSessions').click(function() {
        if (confirm('Are you sure you want to log out of all other sessions? This will log you out from all other devices.')) {
            const btn = $(this);
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Logging out...');
            
            $.ajax({
                url: 'api/logout_other_sessions.php',
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('success', 'All other sessions have been logged out');
                    } else {
                        showToast('error', 'Error logging out other sessions');
                    }
                },
                error: function() {
                    showToast('error', 'Error logging out other sessions');
                },
                complete: function() {
                    btn.prop('disabled', false).html(originalText);
                }
            });
        }
    });

    // Form validation
    $('form').on('submit', function(e) {
        const form = $(this);
        
        // Password confirmation validation
        if (form.find('#new_password').length && form.find('#confirm_password').length) {
            const newPassword = $('#new_password').val();
            const confirmPassword = $('#confirm_password').val();
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                showToast('error', 'New passwords do not match');
                $('#confirm_password').focus();
            }
        }
    });

    // Toast notification function
    function showToast(type, message) {
        var toast = '<div class="toast align-items-center text-white bg-' + type + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        toast += '<div class="d-flex">';
        toast += '<div class="toast-body">' + message + '</div>';
        toast += '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
        toast += '</div></div>';
        
        var $toast = $(toast);
        $('.toast-container').append($toast);
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
        
        $toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }
});
</script>

<script>
    function switchToStep(step) {
        // Find step contents
        const s1 = document.getElementById('step1-content');
        const s2 = document.getElementById('step2-content');
        // Find indicators
        const m1 = document.getElementById('step1-mark');
        const m2 = document.getElementById('step2-mark');

        if (step === 1) {
            // Show Step 1, Hide Step 2
            if(s1) { s1.classList.add('active', 'show'); }
            if(s2) { s2.classList.remove('active', 'show'); }
            
            // Update Indicators
            if(m1) { m1.classList.add('active'); m1.classList.remove('completed'); }
            if(m2) { m2.classList.remove('active', 'completed'); }
        } else {
            // Hide Step 1, Show Step 2
            if(s1) { s1.classList.remove('active', 'show'); }
            if(s2) { s2.classList.add('active', 'show'); }
            
            // Update Indicators
            if(m1) { m1.classList.add('active', 'completed'); }
            if(m2) { m2.classList.add('active'); }
        }
        
        // Scroll to the registration card header for better flow
        const header = document.querySelector('.registration-header');
        if (header) {
            header.scrollIntoView({ behavior: 'smooth' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    function toggleFamilyFields(status) {
        const familyDiv = document.getElementById('familyFields');
        if (familyDiv) {
            familyDiv.style.display = (status === 'Married') ? 'block' : 'none';
        }
    }

    function addChildRow() {
        const tbody = document.getElementById('childrenList');
        if (!tbody) return;
        const rowCount = tbody.getElementsByClassName('child-row').length + 1;
        const newRow = document.createElement('tr');
        newRow.className = 'child-row';
        newRow.innerHTML = `
            <td class="text-center fw-bold row-idx">${rowCount}</td>
            <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Name"></td>
            <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Age"></td>
            <td>
                <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                    <option value="Mwanaume">Male</option>
                    <option value="Mwanamke">Female</option>
                </select>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
            </td>
        `;
        tbody.appendChild(newRow);
        updateRowNumbers();
    }

    function removeRow(btn) {
        if (document.getElementsByClassName('child-row').length > 1) {
            btn.closest('tr').remove();
            updateRowNumbers();
        }
    }

    function updateRowNumbers() {
        const rows = document.getElementsByClassName('row-idx');
        for (let i = 0; i < rows.length; i++) {
            rows[i].innerText = i + 1;
        }
    }

    // --- APPROVAL ACTIONS ---
    function approveMember(userId) {
        Swal.fire({
            title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Je, Una Uhakika?" : "Are you sure?" ?>',
            text: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mwanachama huyu ataruhusiwa kutumia mfumo kikamilifu." : "This member will be granted full access to the system." ?>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Ndio, Idhinisha!" : "Yes, Approve!" ?>',
            cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Ghairi" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Inasindika..." : "Processing..." ?>',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'actions/approve_member.php',
                    type: 'POST',
                    data: { user_id: userId, action: 'approve' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Ameidhinishwa!" : "Approved!" ?>',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload(); // Reload to update UI and hide buttons
                            });
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Something went wrong. Please try again.', 'error');
                    }
                });
            }
        });
    }

    function rejectMember(userId) {
        Swal.fire({
            title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Kufuta/Kukataa?" : "Reject Member?" ?>',
            text: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Je, una uhakika unataka kukataa ombi la mwanachama huyu? Kitendo hiki hakiwezi kurejeshwa." : "Are you sure you want to reject this member? This action cannot be undone." ?>',
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Ndio, Kataa!" : "Yes, Reject!" ?>',
            cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Ghairi" : "Cancel" ?>'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Inasindika..." : "Processing..." ?>',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'actions/approve_member.php',
                    type: 'POST',
                    data: { user_id: userId, action: 'reject' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Imekataliwa!" : "Rejected!" ?>',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.href = '<?= getUrl('customers') ?>';
                            });
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Something went wrong. Please try again.', 'error');
                    }
                });
            }
        });
    }
</script>

<!-- 4. PRINT FOOTER (Visible only during print) -->
<div class="d-none d-print-block print-footer">
    <div class="row pt-2 text-center">
        <div class="col-12">
            <p class="mb-1 text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyaraka hii imechapishwa na' : 'This document was printed by' ?> <strong><?= htmlspecialchars($username ?? $_SESSION['username']) ?></strong> - <strong><?= htmlspecialchars($user_role ?? 'Member') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'mnamo' : 'on' ?> <strong><?= date('d M, Y') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'saa' : 'at' ?> <strong><?= date('H:i:s') ?></strong></p>
            <h6 class="mb-0 fw-bold" style="color: #0d6efd !important;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</h6>
        </div>
    </div>
</div>
