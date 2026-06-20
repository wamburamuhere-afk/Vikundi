<?php
/**
 * api/ai/chat.php — free-form conversation with the AI Assistant.
 * General writing/translation/advice help. It has NO access to business data
 * and can perform NO actions — it only replies with text.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';

try {
    if (!isAuthenticated()) { http_response_code(401); throw new Exception('Unauthorized'); }
    if (!canView('ai_assistant')) { http_response_code(403); throw new Exception('You do not have access to the AI Assistant.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }

    if (!aiConfigured()) {
        echo json_encode(['success' => false, 'message' => 'AI is not set up yet. Ask an admin to configure it in AI Settings.']);
        exit;
    }
    if (aiRateLimited()) {
        echo json_encode(['success' => false, 'message' => 'You are sending messages too fast — please wait a few seconds.']);
        exit;
    }

    $message = trim($_POST['message'] ?? '');
    if ($message === '') { echo json_encode(['success' => false, 'message' => 'Please type a message.']); exit; }
    if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

    // Prior turns (client sends a trimmed history as JSON).
    $history = [];
    $raw = $_POST['history'] ?? '';
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $turn) {
                $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
                $content = trim((string)($turn['content'] ?? ''));
                if ($content !== '') $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
            }
        }
    }
    // Keep only the last 10 turns to control token cost.
    if (count($history) > 10) $history = array_slice($history, -10);

    $lang = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Swahili (Kiswahili)' : 'English';
    $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'a group member';

    $sys = "You are the AI Assistant inside Vikundi, a management system for community savings groups "
         . "(VICOBA) in East Africa. You are talking with {$name}. "
         . "Help with general tasks: drafting and improving messages, translating between English and Swahili, "
         . "explaining ideas, and giving clear, practical advice for running a savings group. "
         . "You DO NOT have access to this group's live data (members, contributions, balances) and you CANNOT "
         . "perform any action in the system. If asked for specific figures or to do something, say you don't have "
         . "access to live data or actions, and point the user to the right page instead. "
         . "Reply by default in {$lang} unless the user writes in another language. Be friendly, concise and respectful.";

    $messages = [['role' => 'system', 'content' => $sys]];
    foreach ($history as $h) $messages[] = $h;
    $messages[] = ['role' => 'user', 'content' => $message];

    $res = aiComplete($messages, ['feature' => 'chat', 'max_tokens' => 800]);

    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?: 'The assistant could not reply.']);
        exit;
    }

    echo json_encode(['success' => true, 'reply' => trim($res['text'])]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
