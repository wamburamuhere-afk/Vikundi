<?php
/**
 * includes/api_communication.php — shared rules for Module 16 (Communication).
 *
 * Covers Messages, SMS, Email (send-only) and Notifications. AI Ask/Chat need
 * no shared file here — they delegate straight to core/ai_service.php and
 * core/ai_insights.php, exactly as the web pages do.
 *
 * SCOPE, corrected against the actual code before anything was built (see
 * todo.md Module 16 and the confirmation this session ran first):
 *
 *   - sms_alerts and sms_templates are EXCLUDED. Neither has a nav link
 *     anywhere in the app. sms_alerts joins `loans`/`collection_strategies`
 *     — both already-dead BMS tables (see todo.md's Excluded section).
 *     sms_templates' own backing endpoint, api/get_sms_templates.php, is a
 *     permanent stub that returns an empty DataTables response unconditionally
 *     (confirmed: no `sms_templates` table exists at all) — already catalogued
 *     as a known dead endpoint in tests/Unit/EndpointAuthSweepTest.php.
 *
 *   - `message_center` is the ONE permission key shared by message_center.php,
 *     sms_center.php, email_center.php and email_templates.php on the web —
 *     confirmed deliberate for email_templates (its own code comment says so)
 *     and treated as the same design choice for the other three, since none
 *     of them literally check a `sms_center`/`email_templates` key that has
 *     its own catalog row.
 *
 *   - GET /api/v1/messages is scoped to the CALLER (sender or recipient), not
 *     leadership-gated as todo.md's plan text said. `message_center` is not
 *     in vk_member_hidden_keys() — every Member holds view by default — and
 *     the web's own queries are already scoped by sender_id/recipient_id,
 *     i.e. an inbox, not a group-wide list. Mirroring "leadership only" here
 *     would invent a restriction the product has never had.
 *
 *   - Sending a message needs `create` on `message_center`, enforced for the
 *     FIRST TIME by this change. message_center.php's own send handler had
 *     no such check — only requireViewPermission() gates the page at all —
 *     so any signed-in Member (view-only by default) could POST send_message
 *     directly and message any recipient. Fixed in the same change, in both
 *     transports, from the same rule.
 *
 *   - GET /api/v1/sms is leadership-only (a hard 403), tighter than the web's
 *     api/sms_center.php?action=list, which returns the WHOLE group's
 *     outbound SMS log — every recipient's phone number and message text —
 *     to anyone holding `view` on `message_center` (i.e. every Member by
 *     default). Nothing in the code marks that as a deliberate disclosure
 *     (contrast includes/api_reports.php's vicoba_reports, which has an
 *     explicit comment for its wider audience) — narrowed here rather than
 *     mirrored, per this session's confirmed judgment call.
 */

require_once __DIR__ . '/api_auth.php';   // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/sms_helper.php'; // sms_normalize_phone()

if (!function_exists('vk_api_comm_is_leader')) {
    /**
     * The same test message_center.php's own send handler now uses, and the
     * same test api/sms_center.php's `send`/`delete` actions already used.
     * One definition, so "who may compose/send" cannot answer differently
     * across the web, the API's write path, and the API's leadership-only
     * SMS log read.
     */
    function vk_api_comm_is_leader(array $auth): bool
    {
        return vk_api_can($auth, 'create', 'message_center');
    }
}

if (!function_exists('vk_api_comm_require_leader')) {
    function vk_api_comm_require_leader(array $auth, string $detail): void
    {
        if (!vk_api_comm_is_leader($auth)) {
            vk_api_error(403, 'forbidden', $detail);
        }
    }
}

// --- Messages -------------------------------------------------------------

if (!function_exists('vk_api_message_priorities')) {
    function vk_api_message_priorities(): array
    {
        return ['low', 'normal', 'high'];
    }
}

if (!function_exists('vk_api_message_row')) {
    /** One message, from the caller's point of view (folder tells us which side they're on). */
    function vk_api_message_row(array $r, int $callerId): array
    {
        return [
            'message_id'  => (int) $r['message_id'],
            'subject'     => (string) $r['subject'],
            'message'     => (string) $r['message'],
            'priority'    => (string) ($r['priority'] ?? 'normal'),
            'parent_id'   => isset($r['parent_id']) && $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'has_replies' => (bool) ($r['has_replies'] ?? false),
            'sender_id'   => (int) $r['sender_id'],
            'sender_name' => trim((string) ($r['sender_name'] ?? '')) ?: null,
            'is_mine'     => (int) $r['sender_id'] === $callerId,
            'is_read'     => isset($r['is_read']) ? (bool) $r['is_read'] : ((int) $r['sender_id'] === $callerId),
            'is_archived' => (bool) ($r['is_archived'] ?? false),
            'created_at'  => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_message_folders')) {
    function vk_api_message_folders(): array
    {
        return ['inbox', 'sent', 'archived'];
    }
}

if (!function_exists('vk_api_message_recipients_row')) {
    function vk_api_message_recipients_row(array $r): array
    {
        return [
            'recipient_id'   => (int) $r['recipient_id'],
            'recipient_name' => trim((string) ($r['recipient_name'] ?? '')) ?: null,
            'is_read'        => (bool) ($r['is_read'] ?? false),
            'read_at'        => !empty($r['read_at']) ? date(DATE_ATOM, strtotime((string) $r['read_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_messages_validate_recipients')) {
    /**
     * Validate a submitted recipient_ids array into a de-duplicated list of
     * real, active user ids — stricter than message_center.php's own send
     * handler, which never checks this at all (whatever id is posted is
     * inserted into message_recipients as-is).
     */
    function vk_api_messages_validate_recipients(PDO $pdo, $raw, int $callerId): array
    {
        if (!is_array($raw) || !$raw) {
            vk_api_error(422, 'recipients_required', 'recipient_ids must be a non-empty array of user ids.');
        }

        $ids = array_values(array_unique(array_map('intval', $raw)));
        $ids = array_filter($ids, fn($id) => $id > 0 && $id !== $callerId);
        if (!$ids) {
            vk_api_error(422, 'recipients_required', 'recipient_ids must contain at least one other active user.');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT user_id FROM users WHERE user_id IN ($in) AND is_active = 1");
        $st->execute(array_values($ids));
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $unknown = array_diff($ids, $valid);
        if ($unknown) {
            vk_api_error(404, 'recipient_not_found', 'No active user was found with id(s): ' . implode(', ', $unknown) . '.');
        }

        return $valid;
    }
}

// --- SMS / Email send -------------------------------------------------------

if (!function_exists('vk_api_comm_parse_phone_recipients')) {
    /** @param mixed $raw JSON array of phone numbers, or a delimited string (comma/semicolon/space/newline). */
    function vk_api_comm_parse_phone_recipients($raw): array
    {
        $parts = is_array($raw)
            ? $raw
            : (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $out = [];
        foreach ($parts as $p) {
            $n = sms_normalize_phone((string) $p);
            if ($n !== '' && strlen($n) >= 10 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }
}

if (!function_exists('vk_api_sms_row')) {
    function vk_api_sms_row(array $r): array
    {
        return [
            'sms_id'          => (int) $r['sms_id'],
            'recipient_phone' => (string) $r['recipient_phone'],
            'recipient_name'  => trim((string) ($r['recipient_name'] ?? '')) ?: null,
            'message'         => (string) $r['message'],
            'status'          => (string) $r['status'],
            'provider'        => $r['provider'] ?? null,
            'error_message'   => $r['error_message'] ?? null,
            'segments'        => (int) ($r['segments'] ?? 1),
            'sender_name'     => trim((string) ($r['sender_name'] ?? '')) ?: null,
            'sent_at'         => !empty($r['sent_at']) ? date(DATE_ATOM, strtotime((string) $r['sent_at'])) : null,
            'created_at'      => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_sms_filters')) {
    /** @return array{0:string[],1:array} */
    function vk_api_sms_filters(array $q): array
    {
        $where = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, ['sent', 'failed', 'queued'], true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: sent, failed, queued.');
            }
            $where[] = 's.status = ?';
            $params[] = $status;
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $raw = trim((string) ($q[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
            }
            $where[] = "DATE(s.created_at) {$op} ?";
            $params[] = $raw;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(s.recipient_phone LIKE ? OR s.recipient_name LIKE ? OR s.message LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}

// --- Notifications -----------------------------------------------------------

if (!function_exists('vk_api_notification_row')) {
    function vk_api_notification_row(array $r): array
    {
        return [
            'notification_id' => (int) $r['notification_id'],
            'title'           => (string) $r['title'],
            'message'         => (string) $r['message'],
            'type'            => (string) $r['type'],
            'priority'        => (string) ($r['priority'] ?? 'medium'),
            'is_read'         => (bool) $r['is_read'],
            'action_url'      => $r['action_url'] ?? null,
            'created_at'      => !empty($r['created_at']) ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'read_at'         => !empty($r['read_at']) ? date(DATE_ATOM, strtotime((string) $r['read_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_notification_filters')) {
    /** @return array{0:string[],1:array} */
    function vk_api_notification_filters(array $q): array
    {
        $where = [];
        $params = [];

        $type = trim((string) ($q['type'] ?? ''));
        if ($type !== '') {
            if (!in_array($type, ['loan', 'payment', 'system', 'report', 'alert'], true)) {
                vk_api_error(422, 'invalid_type', 'type must be one of: loan, payment, system, report, alert.');
            }
            $where[] = 'n.type = ?';
            $params[] = $type;
        }

        $isRead = $q['is_read'] ?? null;
        if ($isRead !== null && $isRead !== '') {
            $where[] = 'n.is_read = ?';
            $params[] = (int) filter_var($isRead, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        return [$where, $params];
    }
}
