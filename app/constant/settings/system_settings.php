<?php
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/header.php';

// Check permissions
/*if (!has_permission('manage_settings')) {
    header('Location: unauthorized.php');
    exit;
}*/

// Handle form submissions
if ($_POST) {
    $success_messages = [];
    $error_messages = [];
    
    // General Settings
    if (isset($_POST['save_general'])) {
        try {
            $settings = [
                'company_name' => $_POST['company_name'],
                'company_address' => $_POST['company_address'],
                'company_phone' => $_POST['company_phone'],
                'company_email' => $_POST['company_email'],
                'company_website' => $_POST['company_website'],
                'currency' => $_POST['currency'],
                'timezone' => $_POST['timezone'],
                'date_format' => $_POST['date_format'],
                'items_per_page' => $_POST['items_per_page']
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mipangilio ya mkuu imesasishwa kikamilifu" : "General settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu ya kusasisha mipangilio ya mkuu: " : "Error updating general settings: ") . $e->getMessage();
        }
    }
    

    
    // Email Settings
    if (isset($_POST['save_email'])) {
        try {
            $settings = [
                'smtp_host' => $_POST['smtp_host'],
                'smtp_port' => $_POST['smtp_port'],
                'smtp_username' => $_POST['smtp_username'],
                'smtp_password' => $_POST['smtp_password'],
                'smtp_encryption' => $_POST['smtp_encryption'],
                'from_email' => $_POST['from_email'],
                'from_name' => $_POST['from_name'],
                'enable_email_notifications' => $_POST['enable_email_notifications'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mipangilio ya barua pepe imesasishwa kikamilifu" : "Email settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu ya kusasisha mipangilio ya barua pepe: " : "Error updating email settings: ") . $e->getMessage();
        }
    }

    // SMS Settings
    if (isset($_POST['save_sms'])) {
        try {
            $settings = [
                'sms_gateway_type' => $_POST['sms_gateway_type'],
                'sms_api_key' => $_POST['sms_api_key'],
                'sms_api_secret' => $_POST['sms_api_secret'],
                'sms_sender_id' => $_POST['sms_sender_id'],
                'enable_sms_notifications' => $_POST['enable_sms_notifications'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mipangilio ya SMS imesasishwa kikamilifu" : "SMS settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu ya kusasisha mipangilio ya SMS: " : "Error updating SMS settings: ") . $e->getMessage();
        }
    }
    

    
    // Security Settings
    if (isset($_POST['save_security'])) {
        try {
            $settings = [
                'session_timeout' => $_POST['session_timeout'],
                'max_login_attempts' => $_POST['max_login_attempts'],
                'password_expiry_days' => $_POST['password_expiry_days'],
                'require_strong_password' => $_POST['require_strong_password'] ?? 0,
                'enable_2fa' => $_POST['enable_2fa'] ?? 0,
                'enable_audit_log' => $_POST['enable_audit_log'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mipangilio ya usalama imesasishwa kikamilifu" : "Security settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu ya kusasisha mipangilio ya usalama: " : "Error updating security settings: ") . $e->getMessage();
        }
    }

    // Group Settings (Contribution Rates & Schedules)
    if (isset($_POST['save_group'])) {
        try {
            $group_data = $_POST['group_settings_data'] ?? [];
            save_setting('group_settings', json_encode($group_data));
            $success_messages[] = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Mipangilio ya kikundi imesasishwa" : "Group settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? "Hitilafu: " : "Error: ") . $e->getMessage();
        }
    }
}

// Load all system settings
$settings = load_all_settings();
$group_settings = json_decode(get_setting('group_settings', '{}'), true);

// Helper function to save settings
function save_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

// Helper function to load all settings
function load_all_settings() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

// Helper function to get setting with default
function get_setting($key, $default = '') {
    global $settings;
    return $settings[$key] ?? $default;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-gear"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio ya Mfumo' : 'App Settings' ?></h2>
            <p class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Simamia mipangilio na mapendekezo ya mfumo kwa ujumla' : 'Manage system settings and general preferences' ?></p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card shadow rounded-4 border-0">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="tab" 
                            data-bs-target="#general" type="button" role="tab">
                        <i class="bi bi-building"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Mkuu (General)' : 'General Settings' ?>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="email-tab" data-bs-toggle="tab" 
                            data-bs-target="#email" type="button" role="tab">
                        <i class="bi bi-envelope"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Barua Pepe' : 'Email' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sms-tab" data-bs-toggle="tab" 
                            data-bs-target="#sms" type="button" role="tab">
                        <i class="bi bi-chat-dots"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'SMS' : 'SMS' ?>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" 
                            data-bs-target="#security" type="button" role="tab">
                        <i class="bi bi-shield-lock"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Usalama' : 'Security' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="backup-tab" data-bs-toggle="tab" 
                            data-bs-target="#backup" type="button" role="tab">
                        <i class="bi bi-cloud-arrow-down"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Backup-Restore' : 'Backup & Restore' ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="group-tab" data-bs-toggle="tab" 
                            data-bs-target="#group" type="button" role="tab">
                        <i class="bi bi-gear-wide-connected"></i> <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Viwango' : 'Contribution Rates' ?>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content" id="settingsTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Msingi' : 'Company Information' ?></h5>
                                
                                <div class="mb-3">
                                    <label for="company_name" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Programu / Kikundi' : 'App / Company Name' ?> *</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" 
                                           value="<?= get_setting('company_name', 'VIKUNDI LEDGER') ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="company_address" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Anwani ya Kikundi' : 'Company Address' ?></label>
                                    <textarea class="form-control" id="company_address" name="company_address" 
                                              rows="3"><?= get_setting('company_address') ?></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="company_phone" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone Number' ?></label>
                                            <input type="text" class="form-control" id="company_phone" name="company_phone" 
                                                   value="<?= get_setting('company_phone') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="company_email" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Barua Pepe' : 'Email Address' ?></label>
                                            <input type="email" class="form-control" id="company_email" name="company_email" 
                                                   value="<?= get_setting('company_email') ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="company_website" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tovuti (Website)' : 'Website URL' ?></label>
                                    <input type="url" class="form-control" id="company_website" name="company_website" 
                                           value="<?= get_setting('company_website') ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendekezo ya Mfumo' : 'System Preferences' ?></h5>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="currency" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sarafu' : 'Currency' ?> *</label>
                                            <select class="form-control" id="currency" name="currency" required>
                                                <option value="TZS" <?= get_setting('currency') == 'TZS' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Shilingi ya Tanzania (TSh)' : 'Tanzanian Shilling (TSh)' ?></option>
                                                <option value="USD" <?= get_setting('currency') == 'USD' ? 'selected' : '' ?>>US Dollar ($)</option>
                                                <option value="KES" <?= get_setting('currency') == 'KES' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Shilingi ya Kenya (KSh)' : 'Kenyan Shilling (KSh)' ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="timezone" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Saa za Eneo' : 'Timezone' ?> *</label>
                                            <select class="form-control" id="timezone" name="timezone" required>
                                                <option value="Africa/Nairobi" <?= get_setting('timezone') == 'Africa/Nairobi' ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Saa za Afrika Mashariki (Nairobi/Dar)' : 'East Africa Time (Nairobi/Dar)' ?></option>
                                                <option value="UTC" <?= get_setting('timezone') == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="date_format" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mpangilio wa Tarehe' : 'Date Format' ?> *</label>
                                            <select class="form-control" id="date_format" name="date_format" required>
                                                <option value="Y-m-d" <?= get_setting('date_format') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                                <option value="d/m/Y" <?= get_setting('date_format') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="items_per_page" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idadi kwa Ukurasa' : 'Items Per Page' ?> *</label>
                                            <select class="form-control" id="items_per_page" name="items_per_page" required>
                                                <option value="10" <?= get_setting('items_per_page') == '10' ? 'selected' : '' ?>>10</option>
                                                <option value="25" <?= get_setting('items_per_page') == '25' ? 'selected' : '' ?>>25</option>
                                                <option value="50" <?= get_setting('items_per_page') == '50' ? 'selected' : '' ?>>50</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3 mt-2">
                                    <label class="form-label text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Toleo la Mfumo' : 'System Version' ?></label>
                                    <input type="text" class="form-control form-control-sm bg-light" value="v2.1.0" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_general" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <i class="bi bi-save me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio' : 'Save Profile Settings' ?>
                            </button>
                        </div>
                    </form>
                </div>



                 <!-- Email Settings Tab -->
                <div class="tab-pane fade" id="email" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Urambazaji wa SMTP' : 'SMTP Configuration' ?></h5>
                                <div class="mb-3">
                                    <label for="smtp_host" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Host ya SMTP' : 'SMTP Host' ?> *</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                            value="<?= get_setting('smtp_host', 'smtp.gmail.com') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="smtp_username" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mtumiaji (SMTP)' : 'SMTP Username' ?> *</label>
                                    <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                            value="<?= get_setting('smtp_username') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="smtp_password" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Neno la Siri (SMTP)' : 'SMTP Password' ?> *</label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                            value="<?= get_setting('smtp_password') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa na Arifa' : 'Notifications' ?></h5>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="enable_email_notifications" 
                                            name="enable_email_notifications" value="1" 
                                            <?= get_setting('enable_email_notifications') ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="enable_email_notifications"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa Arifa za Barua Pepe' : 'Enable Email Alerts' ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" name="save_email" class="btn btn-primary px-4 fw-bold">
                                <i class="bi bi-check-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio ya Barua Pepe' : 'Save Email Settings' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SMS Settings Tab -->
                <div class="tab-pane fade" id="sms" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lango la SMS (Gateway)' : 'SMS Gateway' ?></h5>
                                <div class="mb-3">
                                    <label for="sms_api_key" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'SMS API Key' : 'SMS API Key' ?> *</label>
                                    <input type="text" class="form-control" id="sms_api_key" name="sms_api_key" value="<?= get_setting('sms_api_key') ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="sms_sender_id" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'ID ya Mtumaji (Sender ID)' : 'Sender ID' ?></label>
                                    <input type="text" class="form-control" id="sms_sender_id" name="sms_sender_id" value="<?= get_setting('sms_sender_id') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Arifa za Kiotomatiki' : 'Auto-Notifications' ?></h5>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="enable_sms_notifications" 
                                            name="enable_sms_notifications" value="1" 
                                            <?= get_setting('enable_sms_notifications') ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="enable_sms_notifications"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa Arifa za SMS' : 'Enable SMS Alerts' ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" name="save_sms" class="btn btn-primary px-4 fw-bold">
                                <i class="bi bi-check-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio ya SMS' : 'Save SMS Settings' ?>
                            </button>
                        </div>
                    </form>
                </div>



                <!-- Security Settings Tab -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usalama wa Mfumo' : 'System Security' ?></h5>
                                <div class="mb-3">
                                    <label for="session_timeout" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda wa Kikao (dakika)' : 'Session Timeout (min)' ?></label>
                                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" value="<?= get_setting('session_timeout', '30') ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="max_login_attempts" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Upeo wa Jaribio la Kuingia' : 'Max Login Attempts' ?> *</label>
                                    <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                           value="<?= get_setting('max_login_attempts', '5') ?>" required>
                                    <div class="form-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Funga akaunti baada ya majaribio yaliyofeli' : 'Account lockout after failed login attempts' ?></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password_expiry_days" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda wa Neno la Siri (siku)' : 'Password Expiry (days)' ?></label>
                                    <input type="number" class="form-control" id="password_expiry_days" name="password_expiry_days" 
                                           value="<?= get_setting('password_expiry_days', '90') ?>">
                                    <div class="form-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lazimisha kubadili neno la siri baada ya siku (0 kuzima)' : 'Force password change after specified days (0 to disable)' ?></div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Vipengele vya Usalama' : 'Security Features' ?></h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_strong_password" 
                                               name="require_strong_password" value="1" 
                                               <?= get_setting('require_strong_password') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="require_strong_password">
                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lazimisha Neno la Siri Imara' : 'Require Strong Passwords' ?>
                                        </label>
                                    </div>
                                    <div class="form-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Angalau herufi 8 zenye mchanganyiko (uppercase, lowercase, namba, na alama)' : 'Minimum 8 characters with uppercase, lowercase, number, and special character' ?></div>
                                </div>
                                                       <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_2fa" 
                                               name="enable_2fa" value="1" 
                                               <?= get_setting('enable_2fa') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_2fa">
                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa Uhakiki wa Hatua Mbili (2FA)' : 'Enable Two-Factor Authentication (2FA)' ?>
                                        </label>
                                    </div>
                                    <div class="form-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inahitaji kodi ya uhakiki kupitia barua pepe wakati wa kuingia' : 'Requires email verification code during login' ?></div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="enable_audit_log" 
                                               name="enable_audit_log" value="1" 
                                               <?= get_setting('enable_audit_log', '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="enable_audit_log">
                                            <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa Kumbukumbu za Shughuli (Audit Logs)' : 'Enable System Audit Logs' ?>
                                        </label>
                                    </div>
                                    <div class="form-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rekodi shughuli zote za watumiaji na mabadiliko ya mfumo' : 'Record all user activities and system changes' ?></div>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="bi bi-shield-exclamation"></i> 
                                    <strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mapendekezo ya Usalama:' : 'Security Recommendations:' ?></strong><br>
                                    • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tumia neno la siri imara' : 'Use strong passwords' ?><br>
                                    • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa 2FA kwa akaunti za admin' : 'Enable 2FA for admin accounts' ?><br>
                                    • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili neno la siri mara kwa mara' : 'Regular password rotation' ?><br>
                                    • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Fuatilia kumbukumbu (audit logs) mara kwa mara' : 'Monitor audit logs regularly' ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_security" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio ya Usalama' : 'Save Security Settings' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Backup Settings Tab -->
                <div class="tab-pane fade" id="backup" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nakala ya Database (Backup)' : 'Database Backup' ?></h5>
                            
                            <div class="card mb-4">
                                <div class="card-body">
                                    <h6 class="card-title"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Backup ya Mwongozo' : 'Manual Backup' ?></h6>
                                    <p class="card-text"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tengeneza nakala ya database sasa hivi hapa.' : 'Create an immediate backup of the database.' ?></p>
                                    <button type="button" class="btn btn-primary" id="createBackup">
                                        <i class="bi bi-download"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tengeneza Backup Sasa' : 'Create Backup Now' ?>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ratiba ya Backup Kiotomatiki' : 'Auto Backup Schedule' ?></h6>
                                    <div class="mb-3">
                                        <label class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mzunguko wa Backup' : 'Backup Frequency' ?></label>
                                        <select class="form-control" id="backup_frequency">
                                            <option value="daily"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kila Siku' : 'Daily' ?></option>
                                            <option value="weekly" selected><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kila Wiki' : 'Weekly' ?></option>
                                            <option value="monthly"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kila Mwezi' : 'Monthly' ?></option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tunza Backup Kwa' : 'Keep Backups For' ?></label>
                                        <select class="form-control" id="backup_retention">
                                            <option value="7"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku 7' : '7 days' ?></option>
                                            <option value="30" selected><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku 30' : '30 days' ?></option>
                                            <option value="90"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku 90' : '90 days' ?></option>
                                            <option value="365"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwaka 1' : '1 year' ?></option>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary" id="saveBackupSettings">
                                        <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio ya Backup' : 'Save Backup Settings' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Backup za Karibuni' : 'Recent Backups' ?></h5>
                            
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Faili la Backup' : 'Backup File' ?></th>
                                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tarehe' : 'Date' ?></th>
                                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukubwa' : 'Size' ?></th>
                                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua' : 'Action' ?></th>
                                        </tr>
                                    </thead>
                                     <tbody id="backupList">
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">
                                                <i class="bi bi-hourglass-split"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inapakia orodha...' : 'Loading backup list...' ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                <strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Backup:' : 'Backup Information:' ?></strong><br>
                                • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Backup inajumuisha database na faili muhimu' : 'Backups include database and important files' ?><br>
                                • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi backup sehemu salama nje ya server' : 'Store backups in secure offsite location' ?><br>
                                • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jaribu kurudisha (restore) backup mara kwa mara' : 'Test backup restoration regularly' ?><br>
                                • <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Backup ya mwisho:' : 'Last backup:' ?> <span id="lastBackupDate"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Bado' : 'Never' ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Group Settings Tab -->
                <div class="tab-pane fade" id="group" role="tabpanel">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3 text-primary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Viwango vya Michango' : 'Contribution Rates' ?></h5>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchango wa Kila Mwezi' : 'Monthly Contribution Rate' ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?= get_setting('currency', 'TZS') ?></span>
                                        <input type="number" name="group_settings_data[monthly_rate]" class="form-control" value="<?= $group_settings['monthly_rate'] ?? 0 ?>" required>
                                    </div>
                                    <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi ambacho kila mwanachama anapaswa kuchanga kila mwezi.' : 'Amount each member is expected to contribute monthly.' ?></small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ada ya Kiingilio' : 'Entrance Fee' ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?= get_setting('currency', 'TZS') ?></span>
                                        <input type="number" name="group_settings_data[entrance_fee]" class="form-control" value="<?= $group_settings['entrance_fee'] ?? 0 ?>" required>
                                    </div>
                                    <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ada ya kujiunga kwa mwanachama mpya.' : 'One-time registration fee for new members.' ?></small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ada ya Uchakavu / AGM' : 'Uchakavu / AGM Fee' ?></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?= get_setting('currency', 'TZS') ?></span>
                                        <input type="number" name="group_settings_data[agm_fee]" class="form-control" value="<?= $group_settings['agm_fee'] ?? 0 ?>" required>
                                    </div>
                                    <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi kinachokatwa kwa ajili ya uchakavu au shughuli za AGM.' : 'Amount deducted for operational costs or AGM activities.' ?></small>
                                </div>

                                <h5 class="mb-3 text-warning mt-5"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ratiba ya Michango' : 'Contribution Schedule' ?></h5>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku ya Mwisho ya Malipo' : 'Monthly Due Day' ?></label>
                                            <select name="group_settings_data[due_day]" class="form-select">
                                                <?php for($i=1; $i<=31; $i++): ?>
                                                    <option value="<?= $i ?>" <?= ($group_settings['due_day'] ?? 5) == $i ? 'selected' : '' ?>><?= $i ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku ya kila mwezi ambayo michango inatakiwa kuwa imelipwa.' : 'The day of each month when contributions are due.' ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-4">
                                            <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda wa Mwisho (Saa)' : 'Due Time (Hour)' ?></label>
                                            <input type="time" name="group_settings_data[due_time]" class="form-control" value="<?= $group_settings['due_time'] ?? '17:00' ?>">
                                            <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda wa mwisho wa siku hiyo.' : 'The cutoff time on the due day.' ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3 text-info"><i class="bi bi-info-circle me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maelezo ya Matumizi' : 'Usage Information' ?></h5>
                                <div class="card bg-light border-0 shadow-sm">
                                    <div class="card-body">
                                        <p><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Viwango hivi vitatumika kiotomatiki kwenye <strong>Ledger ya Fedha</strong> ili kuwahesabia wanachama kiasi wanachotakiwa kuwa wamechangia mpaka sasa.' : 'These rates will be automatically applied in the <strong>Financial Ledger</strong> to calculate members expected contributions to date.' ?></p>
                                        <ul class="list-group list-group-flush bg-transparent">
                                            <li class="list-group-item bg-transparent px-0">
                                                <strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi Lengwa (Target):' : 'Target Amount:' ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Itapigwa hesabu kama' : 'Calculated as' ?> <code>(<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rate ya Mwezi' : 'Monthly Rate' ?> × <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idadi ya Miezi' : 'Months Active' ?>)</code>.
                                            </li>
                                            <li class="list-group-item bg-transparent px-0">
                                                <strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ziada/Pungufu:' : 'Surplus/Deficit:' ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Italinganisha michango halisi na Target Amount.' : 'Compares actual contributions against the Target Amount.' ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" name="save_group" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <i class="bi bi-check-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Mipangilio ya Kikundi' : 'Save Group Settings' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include(ROOT_DIR . "/footer.php"); ?>

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

.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
}

.alert pre {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    padding: 0.75rem;
    font-size: 0.875rem;
}
</style>

<script>
$(document).ready(function() {
    const _sw = <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'true' : 'false') ?>;
    const msg = {
        testing: _sw ? 'Inajaribu...' : 'Testing...',
        testSuccess: _sw ? 'Uhakiki wa barua pepe umefanikiwa!' : 'Email configuration test successful!',
        testFail: _sw ? 'Uhakiki wa barua pepe umefeli: ' : 'Email test failed: ',
        testErr: _sw ? 'Hitilafu katika kuhakiki barua pepe' : 'Error testing email configuration',
        backupConfirm: _sw ? 'Una uhakika unataka kutengeneza backup ya database? Hii inaweza kuchukua muda kidogo.' : 'Are you sure you want to create a database backup? This may take a few moments.',
        creatingBackup: _sw ? 'Inatengeneza Backup...' : 'Creating Backup...',
        backupSuccess: _sw ? 'Backup imetengenezwa kwa mafanikio!' : 'Backup created successfully!',
        backupFail: _sw ? 'Backup imeshindikana: ' : 'Backup failed: ',
        backupErr: _sw ? 'Hitilafu katika kutengeneza backup' : 'Error creating backup',
        settingsSaved: _sw ? 'Mipangilio imehifadhiwa kikamilifu!' : 'Settings saved successfully!',
        saving: _sw ? 'Inahifadhi...' : 'Saving...',
        noBackups: _sw ? 'Hakuna backup zilizopatikana' : 'No backups found',
        loadingBackups: _sw ? 'Inapakia orodha ya backup...' : 'Loading backup list...',
        errorLoading: _sw ? 'Hitilafu katika kupakia orodha ya backup' : 'Error loading backup list',
        deleteConfirm: _sw ? 'Una uhakika unataka kufuta backup hii? ' : 'Are you sure you want to delete this backup? ',
        deleteSuccess: _sw ? 'Backup imefutwa kwa mafanikio!' : 'Backup deleted successfully!',
        deleteFail: _sw ? 'Ufutaji umeshindikana: ' : 'Delete failed: ',
        deleteErr: _sw ? 'Hitilafu katika kufuta backup' : 'Error deleting backup'
    };

    // Create Backup
    $('#createBackup').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        Swal.fire({
            title: _sw ? 'Uhakiki wa Backup' : 'Backup Confirmation',
            text: msg.backupConfirm,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: _sw ? 'Ndiyo, Tengeneza' : 'Yes, Create',
            cancelButtonText: _sw ? 'Ghairi' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> ' + msg.creatingBackup);
                
                $.ajax({
                    url: 'api/create_backup.php',
                    type: 'POST',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: _sw ? 'Imefanikiwa' : 'Success',
                                text: msg.backupSuccess
                            });
                            loadBackupList();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: _sw ? 'Hitilafu' : 'Error',
                                text: msg.backupFail + (response.message || '')
                            });
                        }
                    },
                    error: function(xhr) {
                        let errMsg = msg.backupErr;
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.message) errMsg += " (" + res.message + ")";
                            if (res.debug) errMsg += " [DEBUG: " + res.debug + "]";
                        } catch(e) {}
                        Swal.fire({
                            icon: 'error',
                            title: _sw ? 'Hitilafu' : 'Error',
                            text: errMsg
                        });
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            }
        });
    });

    // Save Backup Settings
    $('#saveBackupSettings').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> ' + msg.saving);
        
        // Simulate API call
        setTimeout(() => {
            Swal.fire({
                icon: 'success',
                title: _sw ? 'Mipangilio Imehifadhiwa' : 'Settings Saved',
                text: msg.settingsSaved,
                timer: 2000,
                showConfirmButton: false
            });
            btn.prop('disabled', false).html(originalText);
        }, 1000);
    });

    // Load backup list
    function loadBackupList() {
        $.ajax({
            url: 'api/get_backup_list.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let html = '';
                    if (response.backups.length > 0) {
                        response.backups.forEach(backup => {
                            html += `
                                <tr>
                                    <td>${backup.filename}</td>
                                    <td>${backup.date}</td>
                                    <td>${backup.size}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary download-backup" data-file="${backup.filename}">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-backup" data-file="${backup.filename}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        $('#lastBackupDate').text(response.backups[0].date);
                    } else {
                        html = `<tr><td colspan="4" class="text-center text-muted">${msg.noBackups}</td></tr>`;
                    }
                    $('#backupList').html(html);
                }
            },
            error: function() {
                $('#backupList').html(`<tr><td colspan="4" class="text-center text-danger">${msg.errorLoading}</td></tr>`);
            }
        });
    }

    // Download backup
    $(document).on('click', '.download-backup', function() {
        const filename = $(this).data('file');
        window.open('api/download_backup.php?file=' + encodeURIComponent(filename), '_blank');
    });

    // Delete backup
    $(document).on('click', '.delete-backup', function() {
        const filename = $(this).data('file');
        
        Swal.fire({
            title: _sw ? 'Una Uhakika?' : 'Are you sure?',
            text: msg.deleteConfirm + filename,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: _sw ? 'Ndiyo, Futa' : 'Yes, Delete',
            cancelButtonText: _sw ? 'Ghairi' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'api/delete_backup.php',
                    type: 'POST',
                    data: { filename: filename },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: _sw ? 'Imefutwa' : 'Deleted',
                                text: msg.deleteSuccess,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            loadBackupList();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: _sw ? 'Ikeshindikana' : 'Failed',
                                text: msg.deleteFail + (response.message || '')
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: _sw ? 'Hitilafu' : 'Error',
                            text: msg.deleteErr
                        });
                    }
                });
            }
        });
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

    // Load initial backup list
    loadBackupList();
});
</script>