<?php
/**
 * api/email/save_settings.php — admin saves email/SMTP delivery settings.
 * The SMTP password is encrypted (core/ai_crypto.php) before storage and is
 * never returned to the browser. Mirrors api/ai/save_settings.php.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/core/permissions.php';
require_once ROOT_DIR . '/core/ai_crypto.php';
require_once ROOT_DIR . '/includes/activity_logger.php';
require_once ROOT_DIR . '/includes/email_helper.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.');
    }
    if (!isAdmin() && !canEdit('system_settings')) {
        http_response_code(403);
        throw new Exception($is_sw ? 'Ni wasimamizi pekee wanaoweza kubadilisha mipangilio ya barua pepe.' : 'Only administrators can change email settings.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception($is_sw ? 'Njia si sahihi.' : 'Method not allowed.');
    }

    $providers = email_smtp_providers();
    $provider  = trim($_POST['email_provider'] ?? 'custom');
    if (!isset($providers[$provider])) {
        throw new Exception($is_sw ? 'Chagua mtoa huduma sahihi.' : 'Please choose a valid provider.');
    }

    // A named preset fills host/port/encryption; "custom" takes them from the form.
    if ($provider !== 'custom') {
        $host = $providers[$provider]['host'];
        $port = (string)$providers[$provider]['port'];
        $enc  = $providers[$provider]['encryption'];
    } else {
        $host = trim($_POST['smtp_host'] ?? '');
        $port = (string)(int)($_POST['smtp_port'] ?? 587);
        $enc  = in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
    }

    $username  = trim($_POST['smtp_username'] ?? '');
    $fromEmail = trim($_POST['mail_from_email'] ?? '') ?: $username;
    $fromName  = trim($_POST['mail_from_name'] ?? '') ?: 'Vikundi';
    $enabled   = !empty($_POST['email_enabled']) ? '1' : '0';

    if ($username !== '' && !email_is_valid($username) && !email_is_valid($fromEmail)) {
        throw new Exception($is_sw ? 'Weka barua pepe sahihi.' : 'Please enter a valid email address.');
    }

    email_save_setting($pdo, 'email_provider', $provider);
    email_save_setting($pdo, 'smtp_host', $host);
    email_save_setting($pdo, 'smtp_port', $port);
    email_save_setting($pdo, 'smtp_encryption', $enc);
    email_save_setting($pdo, 'smtp_username', $username);
    email_save_setting($pdo, 'mail_from_email', $fromEmail);
    email_save_setting($pdo, 'mail_from_name', $fromName);
    email_save_setting($pdo, 'email_enabled', $enabled);

    // Only overwrite the stored password when a new one is supplied (the field
    // shows a masked placeholder when a password already exists).
    $newPass = (string)($_POST['smtp_password'] ?? '');
    if ($newPass !== '' && strpos($newPass, '•') === false) {
        email_save_setting($pdo, 'smtp_password_enc', aiEncryptSecret($newPass));
    }

    logActivity('Updated', 'Email Settings', 'Updated email/SMTP delivery settings', 'EMAILCFG', (int)$_SESSION['user_id']);

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mipangilio ya barua pepe imehifadhiwa.' : 'Email settings saved.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
