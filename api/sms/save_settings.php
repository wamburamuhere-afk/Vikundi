<?php
/**
 * api/sms/save_settings.php — admin saves SMS gateway settings.
 * API key/secret are encrypted (core/ai_crypto.php) before storage and never
 * returned to the browser. Mirrors api/email/save_settings.php.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/core/permissions.php';
require_once ROOT_DIR . '/core/ai_crypto.php';
require_once ROOT_DIR . '/includes/activity_logger.php';
require_once ROOT_DIR . '/includes/sms_helper.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.');
    }
    if (!isAdmin() && !canEdit('system_settings')) {
        http_response_code(403);
        throw new Exception($is_sw ? 'Ni wasimamizi pekee wanaoweza kubadilisha mipangilio ya SMS.' : 'Only administrators can change SMS settings.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception($is_sw ? 'Njia si sahihi.' : 'Method not allowed.');
    }

    $gateways = sms_gateways();
    $provider = trim($_POST['sms_provider'] ?? '');
    if (!isset($gateways[$provider])) {
        throw new Exception($is_sw ? 'Chagua mtoa huduma sahihi.' : 'Please choose a valid gateway.');
    }

    $username = trim($_POST['sms_username'] ?? '');
    $sender   = trim($_POST['sms_sender_id'] ?? '') ?: 'VIKUNDI';
    $baseUrl  = trim($_POST['sms_base_url'] ?? '');
    $enabled  = !empty($_POST['sms_enabled']) ? '1' : '0';

    sms_save_setting($pdo, 'sms_provider', $provider);
    sms_save_setting($pdo, 'sms_username', $username);
    sms_save_setting($pdo, 'sms_sender_id', $sender);
    sms_save_setting($pdo, 'sms_base_url', $baseUrl);
    sms_save_setting($pdo, 'sms_enabled', $enabled);

    // Secrets: only overwrite when a new (non-masked) value is supplied.
    $apiKey = (string)($_POST['sms_api_key'] ?? '');
    if ($apiKey !== '' && strpos($apiKey, '•') === false) {
        sms_save_setting($pdo, 'sms_api_key_enc', aiEncryptSecret($apiKey));
    }
    $apiSecret = (string)($_POST['sms_api_secret'] ?? '');
    if ($apiSecret !== '' && strpos($apiSecret, '•') === false) {
        sms_save_setting($pdo, 'sms_api_secret_enc', aiEncryptSecret($apiSecret));
    }

    logActivity('Updated', 'SMS Settings', 'Updated SMS gateway settings (' . $provider . ')', 'SMSCFG', (int)$_SESSION['user_id']);

    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mipangilio ya SMS imehifadhiwa.' : 'SMS settings saved.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
