<?php
/**
 * api/ai/test_connection.php — admin "Test Connection": makes one tiny call to
 * verify the saved provider/model/key actually work.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';

try {
    if (!isAuthenticated()) { http_response_code(401); throw new Exception('Unauthorized'); }
    if (!canEdit('ai_settings') && !isAdmin()) { http_response_code(403); throw new Exception('Only administrators can test AI settings.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }

    if (!aiConfigured()) {
        echo json_encode(['success' => false, 'message' => 'AI is not fully configured. Enable it and set provider, model and API key first.']);
        exit;
    }

    $res = aiComplete([
        ['role' => 'system', 'content' => 'You are a connection test. Reply with exactly: OK'],
        ['role' => 'user',   'content' => 'Reply with OK'],
    ], ['feature' => 'test', 'max_tokens' => 5, 'temperature' => 0]);

    if ($res['ok']) {
        echo json_encode(['success' => true, 'message' => 'Connected ✓ — the AI provider responded successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error'] ?: 'Connection failed.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
