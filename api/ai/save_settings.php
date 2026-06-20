<?php
/**
 * api/ai/save_settings.php — admin saves AI provider/model/key/cap.
 * The API key is encrypted (core/ai_crypto.php) before storage and never returned.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
require_once ROOT_DIR . '/core/ai_crypto.php';

try {
    if (!isAuthenticated()) { http_response_code(401); throw new Exception('Unauthorized'); }
    if (!canEdit('ai_settings') && !isAdmin()) { http_response_code(403); throw new Exception('Only administrators can change AI settings.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }

    $providers = aiProviderModels();
    $provider  = trim($_POST['ai_provider'] ?? '');
    if (!isset($providers[$provider])) throw new Exception('Please choose a valid provider.');

    $model = trim($_POST['ai_model'] ?? '');
    if ($model === '') throw new Exception('Please choose a model.');

    $enabled = !empty($_POST['ai_enabled']) ? '1' : '0';
    $temp    = (string)max(0, min(1, (float)($_POST['ai_temperature'] ?? 0.6)));
    $cap     = (string)max(0, (float)($_POST['ai_monthly_cost_cap'] ?? 0));
    $baseUrl = trim($_POST['ai_base_url'] ?? '');

    aiSaveSetting('ai_provider', $provider);
    aiSaveSetting('ai_model', $model);
    aiSaveSetting('ai_enabled', $enabled);
    aiSaveSetting('ai_temperature', $temp);
    aiSaveSetting('ai_monthly_cost_cap', $cap);
    aiSaveSetting('ai_base_url', $baseUrl);

    // Only overwrite the key if a new non-empty one was supplied.
    $newKey = trim($_POST['ai_api_key'] ?? '');
    if ($newKey !== '' && strpos($newKey, '•') === false) {
        aiSaveSetting('ai_api_key_enc', aiEncryptSecret($newKey));
    }

    if (function_exists('logActivity')) {
        logActivity('update', 'AI Settings', 'Updated AI provider/model settings', 'AI');
    }

    echo json_encode(['success' => true, 'message' => 'AI settings saved successfully.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
