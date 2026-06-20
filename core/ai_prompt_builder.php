<?php
/**
 * core/ai_prompt_builder.php
 * --------------------------
 * Turns a (module, submodule, field_type) + user controls into the system/user
 * messages sent to the model. The base instruction comes from the ai_prompts
 * table (with fallback module+submodule → module → general); the user's request,
 * tone, length, language and any existing text are layered on top.
 *
 *   aiGetBasePrompt(module, fieldType, submodule=null): string
 *   aiBuildMessages(array $req): array  — returns [['role'=>..,'content'=>..], …]
 *
 * $req keys (all optional unless noted):
 *   module, submodule, field_type (required)
 *   instruction   — what the user typed they want
 *   current_text  — existing field content (for improve/translate/shorten)
 *   tone          — friendly | formal | urgent | encouraging
 *   length        — short | medium | long
 *   language      — en | sw  (output language)
 *   context       — assoc array of extra hints (recipient role, group name, …)
 */

require_once __DIR__ . '/ai_service.php';

if (!function_exists('aiGetBasePrompt')) {
    function aiGetBasePrompt(string $module, string $fieldType, ?string $submodule = null): string
    {
        global $pdo;
        $fallbackDefault = 'Generate clear, professional content for this field.';
        try {
            // 1) module + submodule + field
            if ($submodule) {
                $s = $pdo->prepare("SELECT prompt_template FROM ai_prompts WHERE module=? AND submodule=? AND field_type=? AND status=1 LIMIT 1");
                $s->execute([$module, $submodule, $fieldType]);
                $t = $s->fetchColumn();
                if ($t) return $t;
            }
            // 2) module + field (no submodule)
            $s = $pdo->prepare("SELECT prompt_template FROM ai_prompts WHERE module=? AND submodule IS NULL AND field_type=? AND status=1 LIMIT 1");
            $s->execute([$module, $fieldType]);
            $t = $s->fetchColumn();
            if ($t) return $t;
            // 3) general + field
            $s = $pdo->prepare("SELECT prompt_template FROM ai_prompts WHERE module='general' AND submodule IS NULL AND field_type=? AND status=1 LIMIT 1");
            $s->execute([$fieldType]);
            $t = $s->fetchColumn();
            if ($t) return $t;
        } catch (Throwable $e) { /* fall through */ }
        return $fallbackDefault;
    }
}

if (!function_exists('aiBuildMessages')) {
    function aiBuildMessages(array $req): array
    {
        $module    = trim($req['module'] ?? 'general');
        $submodule = trim($req['submodule'] ?? '') ?: null;
        $fieldType = trim($req['field_type'] ?? 'message');
        $instruction = trim($req['instruction'] ?? '');
        $currentText = trim($req['current_text'] ?? '');
        $tone      = strtolower(trim($req['tone'] ?? ''));
        $length    = strtolower(trim($req['length'] ?? 'medium'));
        $language  = strtolower(trim($req['language'] ?? 'en'));
        $context   = is_array($req['context'] ?? null) ? $req['context'] : [];

        $base = aiGetBasePrompt($module, $fieldType, $submodule);

        // System prompt: role + hard rules (the model only drafts text).
        $sys = "You are a professional writing assistant inside Vikundi, a management system "
             . "for community savings groups (VICOBA) in East Africa. "
             . "You only draft text for a form field — you never give commands, never invent facts, "
             . "figures, names, dates or amounts, and never include placeholders unless asked. "
             . "Return ONLY the finished text for the field, with no preamble, quotes or explanation.";

        // User prompt: base instruction + the layered controls.
        $parts = [$base];

        if ($instruction !== '') {
            $parts[] = "What the user wants: " . $instruction;
        }

        if ($currentText !== '') {
            $parts[] = "Existing text to work from:\n\"\"\"\n" . $currentText . "\n\"\"\"";
        }

        // Tone
        $toneMap = [
            'friendly'    => 'Use a warm, friendly tone.',
            'formal'      => 'Use a formal, official tone.',
            'urgent'      => 'Use a clear, urgent tone that prompts quick action.',
            'encouraging' => 'Use a positive, encouraging tone.',
        ];
        if (isset($toneMap[$tone])) $parts[] = $toneMap[$tone];

        // Length
        $lengthMap = [
            'short'  => 'Keep it very short and concise (1–2 sentences).',
            'medium' => 'Keep it a moderate length (one short paragraph).',
            'long'   => 'You may use a few short paragraphs if helpful.',
        ];
        $parts[] = $lengthMap[$length] ?? $lengthMap['medium'];

        // Output language
        if ($language === 'sw') {
            $parts[] = 'Write the response in Swahili (Kiswahili).';
        } else {
            $parts[] = 'Write the response in English.';
        }

        // Context hints (skip id-like keys)
        $ctxParts = [];
        foreach ($context as $k => $v) {
            if ($v === '' || $v === null) continue;
            if (str_ends_with((string)$k, '_id') || str_ends_with((string)$k, '_ids') || $k === 'id') continue;
            if (is_array($v)) $v = implode(', ', $v);
            $ctxParts[] = "$k: $v";
        }
        if ($ctxParts) $parts[] = "Context: " . implode('; ', $ctxParts);

        $user = implode("\n\n", $parts);

        return [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ];
    }
}
