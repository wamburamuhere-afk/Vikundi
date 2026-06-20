<?php
/**
 * api/ai/ask.php — "Ask Vikundi": answer a question from the group's OWN data,
 * using ONLY the curated read-only insight functions (core/ai_insights.php).
 *
 * The model either replies with a JSON object {"function":"name","args":{...}}
 * to fetch a figure, or with a plain-language answer. We run the chosen insight
 * and feed the small result back, up to a few hops. The model never sees raw
 * rows and can never write anything.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
require_once ROOT_DIR . '/core/ai_insights.php';

try {
    if (!isAuthenticated()) { http_response_code(401); throw new Exception('Unauthorized'); }
    if (!canView('ai_ask_data')) { http_response_code(403); throw new Exception('You do not have access to Ask Vikundi.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new Exception('Method not allowed'); }

    if (!aiConfigured()) { echo json_encode(['success' => false, 'message' => 'AI is not set up yet. Ask an admin to configure it in AI Settings.']); exit; }
    if (aiRateLimited()) { echo json_encode(['success' => false, 'message' => 'You are asking too fast — please wait a moment.']); exit; }

    $question = trim($_POST['question'] ?? '');
    if ($question === '') { echo json_encode(['success' => false, 'message' => 'Please type a question.']); exit; }
    if (mb_strlen($question) > 500) $question = mb_substr($question, 0, 500);

    // Group context for nicer phrasing.
    $gname = ''; $currency = 'TZS';
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
    $lang = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Swahili' : 'English';

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

    $messages = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $question]];
    $used = [];
    $maxHops = 4;

    for ($hop = 0; $hop < $maxHops; $hop++) {
        $res = aiComplete($messages, ['feature' => 'ask', 'max_tokens' => 700, 'temperature' => 0.2]);
        if (!$res['ok']) { echo json_encode(['success' => false, 'message' => $res['error'] ?: 'AI request failed.']); exit; }
        $reply = trim($res['text']);

        $call = _ai_extract_call($reply);
        if ($call === null) {
            if (function_exists('logActivity')) logActivity('view', 'Ask Vikundi', 'Asked: ' . mb_substr($question, 0, 120), 'AI');
            echo json_encode(['success' => true, 'answer' => $reply, 'used' => array_values(array_unique($used))]);
            exit;
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
        echo json_encode(['success' => true, 'answer' => trim($res['text']), 'used' => array_values(array_unique($used))]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not complete the answer.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/** Extract a {"function":...,"args":...} object from a model reply, or null. */
function _ai_extract_call(string $text): ?array
{
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    if (strpos($t, '"function"') === false) return null;
    $start = strpos($t, '{'); $end = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $obj = json_decode(substr($t, $start, $end - $start + 1), true);
    if (is_array($obj) && !empty($obj['function']) && is_string($obj['function'])) return $obj;
    return null;
}
