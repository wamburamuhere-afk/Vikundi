<?php
/**
 * POST /api/v1/ai/ask — "Ask Vikundi": answer a question from the group's
 * OWN data, using ONLY the curated read-only insight functions
 * (core/ai_insights.php). The model never sees raw rows and can never write
 * anything.
 *
 * Token-authenticated mirror of api/ai/ask.php. aiRateLimited()/aiLogUsage()
 * (core/ai_service.php) both read $_SESSION['user_id'] directly and have no
 * token-aware equivalent — the same shape as logCreate()/logUpdate() every
 * other write endpoint in this API works around, so the fix is the same one
 * already used throughout api/v1/: set $_SESSION['user_id'] from the verified
 * token before calling them.
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../../includes/activity_logger.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../../../core/ai_insights.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'ai_ask_data');

$_SESSION['user_id'] = (int) $auth['user_id']; // aiRateLimited()/aiLogUsage() read the session

if (!aiConfigured()) {
    vk_api_error(503, 'ai_not_configured', 'AI is not set up yet. Ask an admin to configure it in AI Settings.');
}
if (aiRateLimited()) {
    vk_api_error(429, 'rate_limited', 'You are asking too fast — please wait a moment.');
}

$body = vk_api_body();
$question = trim((string) ($body['question'] ?? ''));
if ($question === '') {
    vk_api_error(422, 'question_required', 'question is required.');
}
if (mb_strlen($question) > 500) {
    $question = mb_substr($question, 0, 500);
}

$gname = '';
$currency = 'TZS';
try {
    $s = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings WHERE setting_key IN ('group_name','currency')");
    $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['setting_key'] === 'group_name') $gname = $r['setting_value'];
        if ($r['setting_key'] === 'currency')  $currency = $r['setting_value'] ?: 'TZS';
    }
} catch (Throwable $e) { /* ignore */ }

$group = $gname !== '' ? $gname : 'this savings group';
$today = date('Y-m-d');
$catalog = json_encode(aiInsightCatalog(), JSON_PRETTY_PRINT);
$lang = ($auth['user']['preferred_language'] ?? 'en') === 'sw' ? 'Swahili' : 'English';

$sys = "You are the data assistant for {$group}, a community savings group (VICOBA). "
     . "Today is {$today}. The group's currency is {$currency}.\n"
     . "Answer the user's question USING ONLY these read-only functions:\n{$catalog}\n\n"
     . "RULES:\n"
     . "- To use a function, reply with ONLY a JSON object: {\"function\":\"<name>\",\"args\":{...}} and nothing else.\n"
     . "- You may call functions one at a time; you'll receive each result, then call another or give the final answer.\n"
     . "- When you have what you need, reply in clear plain language. Show money amounts with the currency ({$currency}).\n"
     . "- NEVER invent numbers, names or facts. If the functions cannot answer, say you don't have that information.\n"
     . "- Do not output SQL or mention database tables/columns.\n"
     . "- Always reply in the SAME language the user asks in — Swahili if they ask in Swahili, English if in English. If unclear, use {$lang}.";

if (!function_exists('vk_api_ai_extract_call')) {
    /** Extract a {"function":...,"args":...} object from a model reply, or null. */
    function vk_api_ai_extract_call(string $text): ?array
    {
        $t = trim($text);
        $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
        if (strpos($t, '"function"') === false) return null;
        $start = strpos($t, '{'); $end = strrpos($t, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $obj = json_decode(substr($t, $start, $end - $start + 1), true);
        return (is_array($obj) && !empty($obj['function']) && is_string($obj['function'])) ? $obj : null;
    }
}

$messages = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $question]];
$used = [];
$maxHops = 4;

for ($hop = 0; $hop < $maxHops; $hop++) {
    $res = aiComplete($messages, ['feature' => 'ask', 'max_tokens' => 700, 'temperature' => 0.2]);
    if (!$res['ok']) {
        vk_api_error(502, 'ai_request_failed', $res['error'] ?: 'AI request failed.');
    }
    $reply = trim($res['text']);

    $call = vk_api_ai_extract_call($reply);
    if ($call === null) {
        logActivity('view', 'Ask Vikundi', 'Asked: ' . mb_substr($question, 0, 120), 'AI', (int) $auth['user_id']);
        vk_api_ok(['answer' => $reply, 'used' => array_values(array_unique($used))]);
    }

    $out = aiRunInsight($call['function'], is_array($call['args'] ?? null) ? $call['args'] : []);
    $used[] = $call['function'];
    $messages[] = ['role' => 'assistant', 'content' => $reply];
    $messages[] = ['role' => 'user', 'content' => 'FUNCTION RESULT (' . $call['function'] . '): ' . json_encode($out['ok'] ? $out['data'] : ['error' => $out['error']])];
}

// Hop budget exhausted — one final answer attempt, no more calls.
$messages[] = ['role' => 'user', 'content' => 'Now answer in plain language using the results above. Do not call any more functions.'];
$res = aiComplete($messages, ['feature' => 'ask', 'max_tokens' => 500, 'temperature' => 0.2]);
if ($res['ok']) {
    vk_api_ok(['answer' => trim($res['text']), 'used' => array_values(array_unique($used))]);
}
vk_api_error(502, 'ai_incomplete', 'Could not complete the answer.');
