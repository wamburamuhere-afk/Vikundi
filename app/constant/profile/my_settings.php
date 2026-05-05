<?php
require_once __DIR__ . '/../../../roots.php';

// Fetch current user data
$stmt = $pdo->prepare("SELECT u.*, c.customer_id FROM users u LEFT JOIN customers c ON LOWER(u.email) = LOWER(c.email) WHERE u.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

$username = $user_data['username'] ?? '';
$first_name = $user_data['first_name'] ?? '';
$middle_name = $user_data['middle_name'] ?? '';
$last_name = $user_data['last_name'] ?? '';
$email = $user_data['email'] ?? '';
$phone = $user_data['phone'] ?? '';
$profile_image = $user_data['avatar'] ?? '';

// Handle Profile Update
if (isset($_POST['save_profile'])) {
    try {
        $new_first_name = $_POST['first_name'];
        $new_middle_name = $_POST['middle_name'];
        $new_last_name = $_POST['last_name'];
        $new_email = $_POST['email'];
        $new_phone = $_POST['phone'];

        // Basic validation
        if (empty($new_first_name) || empty($new_last_name) || empty($new_email)) {
            throw new Exception("All required fields must be filled");
        }

        // Handle Image Upload
        $final_image = $profile_image;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
            $allowed = ['jpg', 'jpeg', 'png'];
            $filename = $_FILES['profile_image']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                $upload_path = ROOT_DIR . '/uploads/avatars/' . $new_filename;
                
                // Ensure directory exists
                if (!is_dir(dirname($upload_path))) {
                    mkdir(dirname($upload_path), 0777, true);
                }

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                    $final_image = $new_filename;
                }
            }
        }

        // Update Users table
        $stmt_u = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, avatar = ? WHERE user_id = ?");
        $stmt_u->execute([$new_first_name, $new_middle_name, $new_last_name, $new_email, $new_phone, $final_image, $_SESSION['user_id']]);

        // Update Customers table if exists
        $stmt_c = $pdo->prepare("UPDATE customers SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ? WHERE LOWER(email) = LOWER(?)");
        $stmt_c->execute([$new_first_name, $new_middle_name, $new_last_name, $new_email, $new_phone, $email]);

        $_SESSION['success_msg'] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Taarifa zimesasishwa kikamilifu" : "Profile updated successfully";
        header("Location: " . getUrl('my_settings'));
        exit();

    } catch (Exception $e) {
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// Handle Password Change
if (isset($_POST['change_password'])) {
    try {
        $curr_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $conf_pass = $_POST['confirm_password'] ?? '';

        if (empty($curr_pass) || empty($new_pass) || empty($conf_pass)) {
            throw new Exception("All password fields are required");
        }

        if (!password_verify($curr_pass, $user_data['password'])) {
            throw new Exception($_SESSION['preferred_language'] === 'sw' ? "Neno la siri la sasa si sahihi" : "Current password is incorrect");
        }

        if ($new_pass !== $conf_pass) {
            throw new Exception($_SESSION['preferred_language'] === 'sw' ? "Neno la siri jipya halilingani" : "New passwords do not match");
        }

        if (strlen($new_pass) < 6) {
            throw new Exception($_SESSION['preferred_language'] === 'sw' ? "Neno la siri lazima liwe na herufi angalau 6" : "Password must be at least 6 characters");
        }

        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt_up = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?");
        $stmt_up->execute([$hashed, $_SESSION['user_id']]);

        $_SESSION['success_msg'] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Neno la siri limebadilishwa kikamilifu" : "Password changed successfully";
        header("Location: " . getUrl('my_settings'));
        exit();

    } catch (Exception $e) {
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// Handle Preferences Update
if (isset($_POST['save_preferences'])) {
    try {
        $theme = $_POST['theme'] ?? 'light';
        $language = $_POST['language'] ?? 'en';
        $timezone = $_POST['timezone'] ?? 'Africa/Dar_es_Salaam';
        $date_format = $_POST['date_format'] ?? 'DD/MM/YYYY';
        
        $email_notif = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notif = isset($_POST['sms_notifications']) ? 1 : 0;

        // Update preferences in Users table
        $prefs = [
            'theme' => $theme,
            'timezone' => $timezone,
            'date_format' => $date_format
        ];
        
        $notif_prefs = [
            'email' => $email_notif,
            'sms' => $sms_notif
        ];

        $stmt_pref = $pdo->prepare("UPDATE users SET preferred_language = ?, preferences = ?, notification_preferences = ? WHERE user_id = ?");
        $stmt_pref->execute([$language, json_encode($prefs), json_encode($notif_prefs), $_SESSION['user_id']]);

        // Update session immediately for UI reflection
        $_SESSION['preferred_language'] = $language;
        $_SESSION['theme'] = $theme;

        $_SESSION['success_msg'] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mapendeleo yamehifadhiwa" : "Preferences saved successfully";
        header("Location: " . getUrl('my_settings'));
        exit();

    } catch (Exception $e) {
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// Prepare Preference Data for View
$prefs_db = json_decode($user_data['preferences'] ?? '{}', true);
$notif_db = json_decode($user_data['notification_preferences'] ?? '{}', true);

$current_theme = $prefs_db['theme'] ?? 'light';
$current_lang = $user_data['preferred_language'] ?? 'en';
$current_tz = $prefs_db['timezone'] ?? 'Africa/Dar_es_Salaam';
$current_df = $prefs_db['date_format'] ?? 'DD/MM/YYYY';

$email_enabled = $notif_db['email'] ?? 1;
$sms_enabled = $notif_db['sms'] ?? 1;

require_once HEADER_FILE;
?>

<div class="container-fluid px-4 py-4">
    <!-- Header Section -->
    <div class="mb-4">
        <h4 class="fw-bold text-dark mb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio Yangu' : 'My Settings' ?></h4>
        <p class="text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Simamia akaunti yako, neno la siri, na mapendeleo' : 'Manage your account, password, and preferences' ?></p>
    </div>

    <!-- Messages Area -->
    <div class="row mb-3">
        <div class="col-12 text-start">
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-success border-4" role="alert">
                    <i class="bi bi-check-circle me-2"></i> <?= $_SESSION['success_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-danger border-4" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i> <?= $_SESSION['error_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-2">
                    <div class="nav flex-column nav-pills" id="settingsTabs" role="tablist">
                        <button class="nav-link active text-start py-3 px-3 rounded-3 mb-1" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button" role="tab" aria-selected="true">
                            <i class="bi bi-person-circle me-3 fs-5"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasifu (Profile)' : 'Profile' ?>
                        </button>
                        <button class="nav-link text-start py-3 px-3 rounded-3 mb-1" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab" aria-selected="false">
                            <i class="bi bi-shield-lock me-3 fs-5"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usalama (Security)' : 'Security' ?>
                        </button>
                        <button class="nav-link text-start py-3 px-3 rounded-3" id="preferences-tab" data-bs-toggle="pill" data-bs-target="#preferences" type="button" role="tab" aria-selected="false">
                            <i class="bi bi-sliders me-3 fs-5"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendeleo (Preferences)' : 'Preferences' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-lg-9">
            <div class="tab-content border-0 shadow-sm rounded-4 bg-white p-4" id="settingsTabsContent">
                
                <!-- PROFILE TAB -->
                <div class="tab-pane fade show active" id="profile" role="tabpanel">
                    <h5 class="fw-bold mb-4 pb-2 border-bottom"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa Binafsi' : 'Personal Information' ?></h5>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row g-4 align-items-center mb-5 text-start">
                            <div class="col-auto">
                                <div class="position-relative">
                                    <?php if (!empty($profile_image)): ?>
                                        <img src="/uploads/avatars/<?= htmlspecialchars($profile_image) ?>" id="avatar-preview" class="rounded-circle border shadow-sm" style="width: 120px; height: 120px; object-fit: cover;">
                                    <?php else: ?>
                                        <div id="avatar-placeholder" class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center text-primary border shadow-sm shadow" style="width: 120px; height: 120px; font-size: 3rem;">
                                            <i class="bi bi-person"></i>
                                        </div>
                                        <img src="" id="avatar-preview" class="rounded-circle border shadow-sm d-none" style="width: 120px; height: 120px; object-fit: cover;">
                                    <?php endif; ?>
                                    
                                    <label for="profile_image" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle" title="Change Photo">
                                        <i class="bi bi-camera"></i>
                                    </label>
                                    <input type="file" name="profile_image" id="profile_image" class="d-none" accept="image/*" onchange="previewImage(this)">
                                </div>
                            </div>
                            <div class="col">
                                <h6 class="mb-1 fw-bold"><?= htmlspecialchars($username) ?></h6>
                                <p class="text-muted small mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Picha ya utambulisho' : 'Profile passport photo' ?></p>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mtumiaji (Username)' : 'Username' ?></label>
                                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($username) ?>" readonly>
                                    <div class="form-text x-small text-danger italic"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '* Jina la mtumiaji haliwezi kubadilishwa' : '* Username cannot be changed' ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kwanza' : 'First Name' ?> *</label>
                                    <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kati' : 'Middle Name' ?></label>
                                    <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($middle_name) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mwisho' : 'Last Name' ?> *</label>
                                    <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Barua Pepe (Email)' : 'Email Address' ?> *</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone Number' ?></label>
                                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_profile" class="btn btn-primary px-4 py-2 rounded-2 fw-bold">
                                <i class="bi bi-save me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Marekebisho' : 'Save Changes' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SECURITY TAB -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-7 text-start">
                            <h5 class="fw-bold mb-4 pb-2 border-bottom"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Neno la Siri' : 'Change Password' ?></h5>
                            
                            <form method="POST">
                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la Siri la Sasa' : 'Current Password' ?> *</label>
                                    <div class="input-group">
                                        <input type="password" name="current_password" class="form-control" id="curr_pass" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePass('curr_pass', this)"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la Siri Jipya' : 'New Password' ?> *</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" class="form-control" id="new_pass" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePass('new_pass', this)"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Thibitisha Neno la Siri Jipya' : 'Confirm New Password' ?> *</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" class="form-control" id="conf_pass" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePass('conf_pass', this)"><i class="bi bi-eye"></i></button>
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-top">
                                    <button type="submit" name="change_password" class="btn btn-primary px-4 py-2 rounded-2 fw-bold">
                                        <i class="bi bi-shield-check me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sasisha Neno la Siri' : 'Update Password' ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-5 text-start">
                            <div class="alert alert-light border-0 shadow-sm rounded-4 p-4 mb-4">
                                <h6 class="fw-bold mb-3 d-flex align-items-center"><i class="bi bi-lightbulb text-warning me-2 fs-5"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Vidokezo vya Usalama' : 'Security Tips' ?></h6>
                                <ul class="small text-muted ps-3 mb-0">
                                    <li class="mb-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tumia neno la siri imara na la kipekee' : 'Use a strong, unique password' ?></li>
                                    <li class="mb-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usishiriki taarifa zako za siri na mtu yeyote' : "Don't share your credentials" ?></li>
                                    <li class="mb-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badilisha neno la siri mara kwa mara' : 'Change password regularly' ?></li>
                                    <li class="mb-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Toka nje (Log out) baada ya kila matumizi' : 'Log out after each session' ?></li>
                                    <li><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tumia mchanganyiko wa herufi, namba, na alama' : 'Use a mix of letters, numbers, and symbols' ?></li>
                                </ul>
                            </div>

                            <div class="card border-0 bg-light rounded-4">
                                <div class="card-body p-4 text-start">
                                    <h6 class="fw-bold mb-2 small text-uppercase text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Shughuli za Hivi Karibuni' : 'Recent Activity' ?></h6>
                                    <div class="d-flex align-items-center mt-3">
                                        <i class="bi bi-clock-history me-3 text-primary fs-4"></i>
                                        <div>
                                            <p class="mb-0 small fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mara yako ya mwisho kuingia ilikuwa:' : 'Your last login was:' ?></p>
                                            <p class="mb-0 small text-muted"><?= $user_data['last_login'] ? date('d M Y, h:i A', strtotime($user_data['last_login'])) : 'Never' ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PREFERENCES TAB -->
                <div class="tab-pane fade" id="preferences" role="tabpanel">
                    <h5 class="fw-bold mb-4 pb-2 border-bottom"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muonekano na Arifa' : 'Display & Notifications' ?></h5>
                    
                    <form method="POST">
                        <div class="row g-4">
                            <!-- Theme Selection -->
                            <div class="col-md-6 text-start">
                                <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mandhari (Theme)' : 'System Theme' ?></label>
                                <select name="theme" class="form-select border-0 bg-light py-3 rounded-3">
                                    <option value="light" <?= $current_theme === 'light' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyeupe (Light)' : 'Light Mode' ?></option>
                                    <option value="dark" <?= $current_theme === 'dark' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Giza (Dark)' : 'Dark Mode' ?></option>
                                </select>
                                <div class="form-text small opacity-75 mt-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua muonekano wa mfumo unaoupenda.' : 'Choose how the system looks for you.' ?></div>
                            </div>

                            <!-- Language -->
                            <div class="col-md-6 text-start">
                                <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lugha ya Mfumo' : 'System Language' ?></label>
                                <select name="language" class="form-select border-0 bg-light py-3 rounded-3">
                                    <option value="en" <?= $current_lang === 'en' ? 'selected' : '' ?>>English</option>
                                    <option value="sw" <?= $current_lang === 'sw' ? 'selected' : '' ?>>Kiswahili</option>
                                </select>
                            </div>

                            <!-- Timezone -->
                            <div class="col-md-6 text-start">
                                <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Eneo la Muda (Timezone)' : 'Timezone' ?></label>
                                <select name="timezone" class="form-select border-0 bg-light py-3 rounded-3 select2-basic">
                                    <option value="Africa/Dar_es_Salaam" <?= $current_tz === 'Africa/Dar_es_Salaam' ? 'selected' : '' ?>>Africa/Dar_es_Salaam (Tanzania)</option>
                                    <option value="Africa/Nairobi" <?= $current_tz === 'Africa/Nairobi' ? 'selected' : '' ?>>Africa/Nairobi</option>
                                    <option value="Africa/Kampala" <?= $current_tz === 'Africa/Kampala' ? 'selected' : '' ?>>Africa/Kampala</option>
                                    <option value="UTC" <?= $current_tz === 'UTC' ? 'selected' : '' ?>>UTC</option>
                                    <!-- Add more as needed -->
                                </select>
                            </div>

                            <!-- Date Format -->
                            <div class="col-md-6 text-start">
                                <label class="form-label small fw-bold text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mpangilio wa Tarehe' : 'Date Format' ?></label>
                                <select name="date_format" class="form-select border-0 bg-light py-3 rounded-3">
                                    <option value="DD/MM/YYYY" <?= $current_df === 'DD/MM/YYYY' ? 'selected' : '' ?>>DD/MM/YYYY (31/12/2023)</option>
                                    <option value="MM/DD/YYYY" <?= $current_df === 'MM/DD/YYYY' ? 'selected' : '' ?>>MM/DD/YYYY (12/31/2023)</option>
                                    <option value="YYYY/MM/DD" <?= $current_df === 'YYYY/MM/DD' ? 'selected' : '' ?>>YYYY/MM/DD (2023/12/31)</option>
                                    <option value="YYYY-MM-DD" <?= $current_df === 'YYYY-MM-DD' ? 'selected' : '' ?>>YYYY-MM-DD (2023-12-31)</option>
                                    <option value="DD Mon YYYY" <?= $current_df === 'DD Mon YYYY' ? 'selected' : '' ?>>DD Mon YYYY (31 Dec 2023)</option>
                                </select>
                            </div>

                            <!-- Notifications -->
                            <div class="col-12 text-start mt-4">
                                <h6 class="fw-bold mb-3"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendeleo ya Arifa' : 'Notification Preferences' ?></h6>
                                
                                <div class="card border-0 bg-light rounded-4 mb-3">
                                    <div class="card-body p-3">
                                        <div class="form-check form-switch ps-5">
                                            <input class="form-check-input" type="checkbox" name="email_notifications" id="emailNotif" <?= $email_enabled ? 'checked' : '' ?> style="width: 3rem; height: 1.5rem; margin-left: -3.5rem;">
                                            <label class="form-check-label ms-2" for="emailNotif">
                                                <span class="d-block fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Arifa za Email' : 'Email Notifications' ?></span>
                                                <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pokea barua pepe kuhusu matukio na sasisho za mfumo.' : 'Receive email about system events and updates.' ?></small>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-0 bg-light rounded-4">
                                    <div class="card-body p-3">
                                        <div class="form-check form-switch ps-5">
                                            <input class="form-check-input" type="checkbox" name="sms_notifications" id="smsNotif" <?= $sms_enabled ? 'checked' : '' ?> style="width: 3rem; height: 1.5rem; margin-left: -3.5rem;">
                                            <label class="form-check-label ms-2" for="smsNotif">
                                                <span class="d-block fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Arifa za SMS' : 'SMS Notifications' ?></span>
                                                <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pokea ujumbe wa SMS kwa matukio muhimu.' : 'Receive SMS alerts for critical events.' ?></small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_preferences" class="btn btn-primary px-4 py-2 rounded-2 fw-bold">
                                <i class="bi bi-check2-all me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mapendeleo' : 'Save Preferences' ?>
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('avatar-preview');
            const placeholder = document.getElementById('avatar-placeholder');
            
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (placeholder) placeholder.classList.add('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>

<?php require_once FOOTER_FILE; ?>
