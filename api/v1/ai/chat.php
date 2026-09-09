<?php
/**
 * POST /api/v1/ai/chat — free-form conversation with the AI Assistant.
 * General writing/translation/advice help. It has NO access to business data
 * and can perform NO actions — it only replies with text.
 *
 * Token-authenticated mirror of api/ai/chat.php. Stateless: the client sends
 * a trimmed history (last 10 turns) with every request, same as the web.
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'ai_assistant');

$_SESSION['user_id'] = (int) $auth['user_id']; // aiRateLimited()/aiLogUsage() read the session

if (!aiConfigured()) {
    vk_api_error(503, 'ai_not_configured', 'AI is not set up yet. Ask an admin to configure it in AI Settings.');
}
if (aiRateLimited()) {
    vk_api_error(429, 'rate_limited', 'You are sending messages too fast — please wait a few seconds.');
}

$body = vk_api_body();
$message = trim((string) ($body['message'] ?? ''));
if ($message === '') {
    vk_api_error(422, 'message_required', 'message is required.');
}
if (mb_strlen($message) > 2000) {
    $message = mb_substr($message, 0, 2000);
}

$history = [];
$rawHistory = $body['history'] ?? [];
if (is_array($rawHistory)) {
    foreach ($rawHistory as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content !== '') {
            $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
        }
    }
}
if (count($history) > 10) {
    $history = array_slice($history, -10);
}

$lang = ($auth['user']['preferred_language'] ?? 'en') === 'sw' ? 'Swahili (Kiswahili)' : 'English';
$name = trim(($auth['user']['first_name'] ?? '') . ' ' . ($auth['user']['last_name'] ?? '')) ?: 'a group member';

$sys = "You are the AI Assistant inside Vikundi, a management system for community savings groups "
     . "(VICOBA) in East Africa. You are talking with {$name}. "
     . "Help with general tasks: drafting and improving messages, translating between English and Swahili, "
     . "explaining ideas, and giving clear, practical advice for running a savings group. "
     . "You DO NOT have access to this group's live data (members, contributions, balances) and you CANNOT "
     . "perform any action in the system. If asked for specific figures or to do something, say you don't have "
     . "access to live data or actions, and point the user to the right page instead. "
     . "Always reply in the SAME language the user writes their message in — if they write in Swahili, answer in Swahili; if in English, answer in English. If the language is unclear, use {$lang}. Be friendly, concise and respectful.";

$messages = [['role' => 'system', 'content' => $sys]];
foreach ($history as $h) {
    $messages[] = $h;
}
$messages[] = ['role' => 'user', 'content' => $message];

$res = aiComplete($messages, ['feature' => 'chat', 'max_tokens' => 800]);

if (!$res['ok']) {
    vk_api_error(502, 'ai_request_failed', $res['error'] ?: 'The assistant could not reply.');
}

vk_api_ok(['reply' => trim($res['text'])]);
