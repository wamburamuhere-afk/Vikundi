<?php
/**
 * api/ai/generate.php — draft / improve / translate text for a form field.
 * Returns N variations the user can insert. Never reads or writes business data.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
require_once ROOT_DIR . '/core/ai_prompt_builder.php';

try {
    if (!isAuthenticated()) { http_response_code(401); throw new Exception('Unauthorized'); }
    if (!canCreate('ai_assistant')) { http_response_code(403); throw new Exception('You do not have permission to use the AI Assistant.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }

    if (!aiConfigured()) {
        echo json_encode(['success' => false, 'message' => 'AI is not set up yet. Ask an admin to configure it in AI Settings.']);
        exit;
    }
    if (aiRateLimited()) {
        echo json_encode(['success' => false, 'message' => 'You are generating too fast — please wait a few seconds and try again.']);
        exit;
    }

    // Context may arrive as a JSON string or an array.
    $context = $_POST['context'] ?? [];
    if (is_string($context)) {
        $decoded = json_decode($context, true);
        $context = is_array($decoded) ? $decoded : [];
    }

    $instruction = trim($_POST['instruction'] ?? '');
    $currentText = trim($_POST['current_text'] ?? '');
    if ($instruction === '' && $currentText === '') {
        echo json_encode(['success' => false, 'message' => 'Please describe what you want the AI to write.']);
        exit;
    }

    $req = [
        'module'       => $_POST['module'] ?? 'general',
        'submodule'    => $_POST['submodule'] ?? '',
        'field_type'   => $_POST['field_type'] ?? 'message',
        'instruction'  => $instruction,
        'current_text' => mb_substr($currentText, 0, 4000),
        'tone'         => $_POST['tone'] ?? '',
        'length'       => $_POST['length'] ?? 'medium',
        'language'     => $_POST['language'] ?? ($_SESSION['preferred_language'] ?? 'en'),
        'context'      => $context,
    ];

    $messages = aiBuildMessages($req);
    $n = (int)($_POST['result_count'] ?? 2);

    $res = aiGenerate($messages, $n, ['feature' => 'generate', 'max_tokens' => 700]);

    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?: 'Could not generate content.']);
        exit;
    }

    if (function_exists('logActivity')) {
        logActivity('generate', 'AI Assistant',
            'Generated text for ' . $req['module'] . '/' . $req['field_type'], 'AI');
    }

    echo json_encode(['success' => true, 'results' => $res['results']]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
