<?php
/**
 * database/ai_assistant_setup.php
 * --------------------------------
 * Idempotent setup for the AI Assistant feature. Safe to run multiple times.
 *
 *   - Creates `ai_prompts`     (per-module/field prompt templates)
 *   - Creates `ai_usage_log`   (token + cost tracking, rate-limit source)
 *   - Seeds prompt templates   (general + communication: message/subject/sms/email/improve/translate)
 *   - Inserts permissions rows so AI access is managed in user_roles.php:
 *        page_key 'ai_assistant' (can_view = see AI buttons, can_create = generate)
 *        page_key 'ai_settings'  (can_edit  = configure provider/key — admins)
 *
 * Run from CLI:  php database/ai_assistant_setup.php
 */

require_once __DIR__ . '/../includes/config.php';

$report = [];

/* ── 1. ai_prompts table ──────────────────────────────────────────────────── */
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_prompts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) DEFAULT NULL,
    submodule VARCHAR(50) DEFAULT NULL,
    field_type VARCHAR(50) NOT NULL,
    prompt_template TEXT NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lookup (module, submodule, field_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$report[] = "ai_prompts table ready";

/* ── 2. ai_usage_log table ────────────────────────────────────────────────── */
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    feature VARCHAR(50) DEFAULT NULL,
    provider VARCHAR(30) DEFAULT NULL,
    model VARCHAR(80) DEFAULT NULL,
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    est_cost DECIMAL(12,6) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'ok',
    error VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, created_at),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$report[] = "ai_usage_log table ready";

/* ── 3. Seed prompt templates ─────────────────────────────────────────────── */
$prompts = [
    // General fallbacks (used by any module/field when no specific prompt exists)
    ['general', null, 'message',
        'Write a clear, professional message for a community savings group (VICOBA / village bank). Keep it warm, respectful and easy to understand for ordinary members.',
        'Generic member message'],
    ['general', null, 'improve',
        'Improve and polish the following text. Fix grammar and spelling, make it clearer and more professional, but keep the original meaning and language. Return only the improved text.',
        'Improve / polish existing text'],
    ['general', null, 'translate',
        'Translate the following text accurately. Keep the tone natural and professional. Return only the translated text.',
        'Translate existing text'],
    ['general', null, 'shorten',
        'Rewrite the following text to be shorter and more concise while keeping the key message. Return only the shortened text.',
        'Shorten existing text'],

    // Communication module
    ['communication', 'message', 'message',
        'Write a clear and friendly message from the leadership of a community savings group (VICOBA) to its members. It should be respectful, motivating and easy to understand. Cover the topic the user describes.',
        'Internal member message body'],
    ['communication', 'message', 'subject',
        'Generate a short, clear subject line for a message sent to members of a community savings group. Keep it under 80 characters.',
        'Message subject line'],
    ['communication', 'sms', 'message',
        'Write a very short SMS (ideally under 160 characters) to members of a community savings group. Be clear, polite and direct. No greetings longer than one short line.',
        'SMS alert text'],
    ['communication', 'email', 'body',
        'Write a professional email to members of a community savings group. Include a short greeting, a clear body covering the topic, and a polite closing.',
        'Email body'],
];

$check = $pdo->prepare("SELECT id FROM ai_prompts WHERE module <=> ? AND submodule <=> ? AND field_type = ?");
$ins   = $pdo->prepare("INSERT INTO ai_prompts (module, submodule, field_type, prompt_template, description) VALUES (?,?,?,?,?)");
$seeded = 0;
foreach ($prompts as $p) {
    $check->execute([$p[0], $p[1], $p[2]]);
    if (!$check->fetchColumn()) {
        $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
        $seeded++;
    }
}
$report[] = "prompt templates seeded: $seeded new (" . count($prompts) . " defined)";

/* ── 4. Permissions rows (appear in user_roles.php matrix) ─────────────────── */
$perms = [
    ['ai_assistant', 'AI Assistant', 'Use the AI Assistant to draft, improve and translate text in forms', 'AI Assistant'],
    ['ai_settings',  'AI Settings',  'Configure the AI provider, model and API key (admin)',             'AI Assistant'],
    ['ai_ask_data',  'Ask Vikundi (Data)', 'Ask the AI questions answered from real group data (read-only, no changes)', 'AI Assistant'],
];
$pcheck = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$pins   = $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)");
$permAdded = 0;
foreach ($perms as $pp) {
    $pcheck->execute([$pp[0]]);
    if (!$pcheck->fetchColumn()) {
        $pins->execute(['', $pp[0], $pp[1], $pp[2], $pp[3]]);
        $permAdded++;
    }
}
$report[] = "permissions added: $permAdded (ai_assistant, ai_settings)";

/* ── 5. Grant to admin-type roles by default (so leaders can use it day one) ── */
$adminRoleNames = ['admin','administrator','mwenyekiti','chairman','secretary','sekretari','treasurer','mhazini','mweka hazina'];
$in = implode(',', array_fill(0, count($adminRoleNames), '?'));
$roleRows = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleRows->execute(array_map('strtolower', $adminRoleNames));
$roleIds = $roleRows->fetchAll(PDO::FETCH_COLUMN);

$permIds = $pdo->query("SELECT permission_id FROM permissions WHERE page_key IN ('ai_assistant','ai_settings','ai_ask_data')")->fetchAll(PDO::FETCH_COLUMN);

$grantCheck = $pdo->prepare("SELECT id FROM role_permissions WHERE role_id = ? AND permission_id = ?");
$grantIns   = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?,?,1,1,1,0)");
$grants = 0;
foreach ($roleIds as $rid) {
    foreach ($permIds as $pid) {
        $grantCheck->execute([$rid, $pid]);
        if (!$grantCheck->fetchColumn()) {
            $grantIns->execute([$rid, $pid]);
            $grants++;
        }
    }
}
$report[] = "default grants to admin/leader roles: $grants";

/* ── Done ─────────────────────────────────────────────────────────────────── */
echo "AI Assistant setup complete:\n";
foreach ($report as $line) echo "  - $line\n";
