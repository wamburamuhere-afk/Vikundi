<?php
/**
 * core/ai_service.php
 * -------------------
 * Provider-agnostic AI text layer for Vikundi. One internal message format —
 * [['role'=>'system|user|assistant','content'=>'…'], …] — mapped to each
 * provider's chat API. Supports OpenAI, Anthropic (Claude) and Google Gemini;
 * the admin picks provider/model/key in AI Settings (stored in system_settings;
 * the key is encrypted via core/ai_crypto.php and decrypted only here).
 *
 * Design rules:
 *   - NEVER throws to the caller. Returns ['ok'=>bool,'text'/'results','usage','error'].
 *   - Logs every call to ai_usage_log (tokens + estimated cost).
 *   - Enforces a monthly cost cap and a per-user rate limit BEFORE calling out.
 *   - If unconfigured/disabled, aiConfigured() is false and callers degrade gracefully.
 *
 * Public API:
 *   aiGetSetting(string $key, string $default=''): string
 *   aiSaveSetting(string $key, string $value): void
 *   aiSettings(): array
 *   aiConfigured(): bool
 *   aiProviderModels(): array
 *   aiComplete(array $messages, array $opts=[]): array       — single completion
 *   aiGenerate(array $messages, int $n=1, array $opts=[]): array — N variations
 *   aiMonthSpend(): float
 *   aiCapInfo(): array
 *   aiRateLimited(int $perMinute=15): bool
 */

require_once __DIR__ . '/ai_crypto.php';

if (!function_exists('aiGetSetting')) {
    function aiGetSetting(string $key, string $default = ''): string
    {
        global $pdo;
        try {
            $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null) ? $default : (string)$v;
        } catch (Throwable $e) { return $default; }
    }
}

if (!function_exists('aiSaveSetting')) {
    function aiSaveSetting(string $key, string $value): void
    {
        global $pdo;
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group)
                       VALUES (?, ?, 'ai')
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $value]);
    }
}

if (!function_exists('aiProviderModels')) {
    /** Supported providers and a few good models each (admin picks in settings). */
    function aiProviderModels(): array
    {
        return [
            'openai' => [
                'label'  => 'OpenAI',
                'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'],
            ],
            'anthropic' => [
                'label'  => 'Anthropic (Claude)',
                'models' => ['claude-haiku-4-5', 'claude-sonnet-4-5', 'claude-3-5-haiku-latest'],
            ],
            'google' => [
                'label'  => 'Google (Gemini)',
                'models' => ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-pro'],
            ],
        ];
    }
}

if (!function_exists('aiSettings')) {
    /** Decoded AI config (key decrypted). */
    function aiSettings(): array
    {
        $enc = aiGetSetting('ai_api_key_enc', '');
        return [
            'enabled'     => aiGetSetting('ai_enabled', '0') === '1',
            'provider'    => aiGetSetting('ai_provider', 'openai'),
            'model'       => trim(aiGetSetting('ai_model', '')),
            'api_key'     => $enc !== '' ? (aiDecryptSecret($enc) ?? '') : '',
            'base_url'    => trim(aiGetSetting('ai_base_url', '')),
            'cost_cap'    => (float)aiGetSetting('ai_monthly_cost_cap', '0'),
            'temperature' => (float)aiGetSetting('ai_temperature', '0.6'),
            'has_key'     => $enc !== '',
        ];
    }
}

if (!function_exists('aiConfigured')) {
    /** True when enabled AND a model + decryptable key are present. */
    function aiConfigured(): bool
    {
        $s = aiSettings();
        return $s['enabled'] && $s['model'] !== '' && $s['api_key'] !== '';
    }
}

if (!function_exists('aiMonthSpend')) {
    function aiMonthSpend(): float
    {
        global $pdo;
        try {
            $q = $pdo->query("SELECT COALESCE(SUM(est_cost),0) FROM ai_usage_log
                               WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            return (float)$q->fetchColumn();
        } catch (Throwable $e) { return 0.0; }
    }
}

if (!function_exists('aiCapInfo')) {
    /** ['cap'=>float,'spent'=>float,'exceeded'=>bool]. cap 0 = unlimited. */
    function aiCapInfo(): array
    {
        $cap = (float)aiGetSetting('ai_monthly_cost_cap', '0');
        $spent = aiMonthSpend();
        return ['cap' => $cap, 'spent' => $spent, 'exceeded' => ($cap > 0 && $spent >= $cap)];
    }
}

if (!function_exists('aiRateLimited')) {
    function aiRateLimited(int $perMinute = 15): bool
    {
        global $pdo;
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid === null) return false;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM ai_usage_log WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn() >= $perMinute;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('aiLogUsage')) {
    function aiLogUsage(string $feature, string $provider, string $model, int $pt, int $ct, float $cost, string $status, ?string $error = null): void
    {
        global $pdo;
        try {
            $uid = $_SESSION['user_id'] ?? null;
            $pdo->prepare("INSERT INTO ai_usage_log (user_id, feature, provider, model, prompt_tokens, completion_tokens, est_cost, status, error)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$uid, $feature, $provider, $model, $pt, $ct, $cost, $status, $error ? substr($error, 0, 255) : null]);
        } catch (Throwable $e) { /* logging must never break the call */ }
    }
}

if (!function_exists('aiEstimateCost')) {
    /** Rough blended USD estimate per 1K tokens. Unknown model → 0. */
    function aiEstimateCost(string $model, int $pt, int $ct): float
    {
        $m = strtolower($model);
        $rate = 0.0;
        if (strpos($m, 'gpt-4o-mini') !== false)      $rate = 0.0004;
        elseif (strpos($m, 'gpt-4o') !== false)        $rate = 0.005;
        elseif (strpos($m, 'gpt-4') !== false)         $rate = 0.01;
        elseif (strpos($m, 'gpt-3.5') !== false)       $rate = 0.0008;
        elseif (strpos($m, 'haiku') !== false)         $rate = 0.0008;
        elseif (strpos($m, 'sonnet') !== false)        $rate = 0.006;
        elseif (strpos($m, 'opus') !== false)          $rate = 0.02;
        elseif (strpos($m, 'flash') !== false)         $rate = 0.0003;
        elseif (strpos($m, 'gemini') !== false)        $rate = 0.002;
        return round((($pt + $ct) / 1000.0) * $rate, 6);
    }
}

if (!function_exists('aiComplete')) {
    /**
     * Run one chat completion. $opts: temperature, max_tokens, feature (logging).
     * Returns ['ok'=>bool,'text'=>string,'usage'=>['prompt'=>int,'completion'=>int,'cost'=>float],'error'=>?string].
     */
    function aiComplete(array $messages, array $opts = []): array
    {
        $feature = $opts['feature'] ?? 'generate';
        $s = aiSettings();

        if (!$s['enabled'] || $s['model'] === '' || $s['api_key'] === '') {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI is not configured. Ask an admin to set it up in AI Settings.'];
        }

        $cap = aiCapInfo();
        if ($cap['exceeded']) {
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'blocked', 'monthly cost cap reached');
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'Monthly AI cost cap reached. Ask an admin to raise it in AI Settings.'];
        }

        $temperature = $opts['temperature'] ?? $s['temperature'];
        $maxTokens   = (int)($opts['max_tokens'] ?? 800);

        try {
            switch ($s['provider']) {
                case 'anthropic': $res = _aiCallAnthropic($s, $messages, $temperature, $maxTokens); break;
                case 'google':    $res = _aiCallGemini($s, $messages, $temperature, $maxTokens);    break;
                case 'openai':
                default:          $res = _aiCallOpenAI($s, $messages, $temperature, $maxTokens);     break;
            }
        } catch (Throwable $e) {
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $e->getMessage());
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'AI request failed.'];
        }

        if (!$res['ok']) {
            aiLogUsage($feature, $s['provider'], $s['model'], 0, 0, 0, 'error', $res['error'] ?? 'unknown');
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => $res['error'] ?? 'AI request failed.'];
        }

        $pt = (int)($res['usage']['prompt'] ?? 0);
        $ct = (int)($res['usage']['completion'] ?? 0);
        $cost = aiEstimateCost($s['model'], $pt, $ct);
        aiLogUsage($feature, $s['provider'], $s['model'], $pt, $ct, $cost, 'ok');

        return ['ok' => true, 'text' => $res['text'], 'usage' => ['prompt' => $pt, 'completion' => $ct, 'cost' => $cost], 'error' => null];
    }
}

if (!function_exists('aiGenerate')) {
    /**
     * Produce N variations for the same prompt. Returns
     * ['ok'=>bool,'results'=>string[],'error'=>?string].
     */
    function aiGenerate(array $messages, int $n = 1, array $opts = []): array
    {
        $n = max(1, min(3, $n));
        $results = [];
        $lastError = null;
        for ($i = 0; $i < $n; $i++) {
            // Nudge temperature up slightly per variation for variety.
            $o = $opts;
            $o['temperature'] = ($opts['temperature'] ?? 0.6) + ($i * 0.15);
            $r = aiComplete($messages, $o);
            if ($r['ok']) {
                $txt = trim($r['text']);
                if ($txt !== '') $results[] = $txt;
            } else {
                $lastError = $r['error'];
                break; // don't keep paying if the first call already failed
            }
        }
        if (!$results) return ['ok' => false, 'results' => [], 'error' => $lastError ?: 'No content generated.'];
        return ['ok' => true, 'results' => $results, 'error' => null];
    }
}

// ── HTTP helper ──────────────────────────────────────────────────────────────
if (!function_exists('_aiHttp')) {
    function _aiHttp(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        $caBundle = __DIR__ . '/../includes/cacert.pem';
        if (is_file($caBundle)) $opts[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            if (in_array($code, [429, 503], true)) {
                return ['ok' => false, 'error' => 'The AI service is busy or rate-limited right now. Please wait a moment and try again.'];
            }
            $msg = $json['error']['message'] ?? $json['error'] ?? ('HTTP ' . $code);
            return ['ok' => false, 'error' => is_string($msg) ? $msg : ('HTTP ' . $code)];
        }
        return ['ok' => true, 'json' => $json];
    }
}

// ── Provider adapters ────────────────────────────────────────────────────────
if (!function_exists('_aiCallOpenAI')) {
    function _aiCallOpenAI(array $s, array $messages, float $temp, int $max): array
    {
        $base = $s['base_url'] !== '' ? rtrim($s['base_url'], '/') : 'https://api.openai.com/v1';
        $body = ['model' => $s['model'], 'messages' => $messages, 'temperature' => $temp, 'max_tokens' => $max];
        $r = _aiHttp($base . '/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $s['api_key']], $body);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        return ['ok' => true,
                'text' => (string)($j['choices'][0]['message']['content'] ?? ''),
                'usage' => ['prompt' => $j['usage']['prompt_tokens'] ?? 0, 'completion' => $j['usage']['completion_tokens'] ?? 0]];
    }
}

if (!function_exists('_aiCallAnthropic')) {
    function _aiCallAnthropic(array $s, array $messages, float $temp, int $max): array
    {
        $system = '';
        $msgs = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $system .= ($system ? "\n" : '') . $m['content']; continue; }
            $msgs[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
        }
        $body = ['model' => $s['model'], 'max_tokens' => $max, 'temperature' => $temp, 'messages' => $msgs];
        if ($system !== '') $body['system'] = $system;
        $r = _aiHttp('https://api.anthropic.com/v1/messages',
            ['Content-Type: application/json', 'x-api-key: ' . $s['api_key'], 'anthropic-version: 2023-06-01'], $body);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        $text = '';
        foreach (($j['content'] ?? []) as $blk) { if (($blk['type'] ?? '') === 'text') $text .= $blk['text']; }
        return ['ok' => true, 'text' => $text,
                'usage' => ['prompt' => $j['usage']['input_tokens'] ?? 0, 'completion' => $j['usage']['output_tokens'] ?? 0]];
    }
}

if (!function_exists('_aiCallGemini')) {
    function _aiCallGemini(array $s, array $messages, float $temp, int $max): array
    {
        $contents = [];
        $sys = '';
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $sys .= ($sys ? "\n" : '') . $m['content']; continue; }
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        }
        $body = ['contents' => $contents, 'generationConfig' => [
            'temperature' => $temp,
            'maxOutputTokens' => max(2000, $max),
            'thinkingConfig' => ['thinkingBudget' => 0],
        ]];
        if ($sys !== '') $body['systemInstruction'] = ['parts' => [['text' => $sys]]];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($s['model']) . ':generateContent?key=' . rawurlencode($s['api_key']);
        $r = _aiHttp($url, ['Content-Type: application/json'], $body);
        if (!$r['ok']) return $r;
        $j = $r['json'];
        $text = '';
        foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) { $text .= $p['text'] ?? ''; }
        $um = $j['usageMetadata'] ?? [];
        return ['ok' => true, 'text' => $text,
                'usage' => ['prompt' => $um['promptTokenCount'] ?? 0, 'completion' => $um['candidatesTokenCount'] ?? 0]];
    }
}
