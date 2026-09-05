<?php
/**
 * includes/api_meetings.php — the shared rules for the Meetings module.
 *
 * Deliberately requires only config-free files, so it is testable in CI.
 *
 * NO WORKFLOW, same shape as Payouts. `status` (scheduled/held/cancelled) is a
 * plain field set directly by whoever creates or edits the meeting — there is
 * no reviewer/approver, and `role_permissions` for `meetings` has
 * can_review/can_approve at 0 for every role.
 *
 * PERMISSION KEY: `meetings` — already existed with correct grants before this
 * module (full leadership CRUD, Member view-only) via
 * database/create_meetings_tables.php + grant_meetings_to_leadership.php, so
 * no new migration was needed here, unlike every module since Contributions.
 *
 * ATTENDANCE. `POST /meetings/{id}/attendance` accepts an explicit
 * `[{member_id, status}]` array and upserts exactly those rows — unlike the
 * web's own actions/save_meeting_attendance.php, which resubmits the WHOLE
 * active roster every time and treats "in the roster but not checked
 * present" as an explicit absence. The API does not carry that "everyone
 * else becomes absent" side effect: a member left out of the request simply
 * keeps whatever attendance status they already had. This is a deliberate,
 * safer shape for a mobile client that may want to update one person's
 * status without resubmitting the whole group.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/meeting_helpers.php';    // vk_normalize_meeting_type/status, vk_meeting_input_errors, vk_attendance_summary, vk_meeting_fine_reason

if (!function_exists('vk_api_meetings_row')) {
    /** One meeting, as the app renders it. */
    function vk_api_meetings_row(array $r): array
    {
        $row = [
            'id'           => (int) $r['id'],
            'title'        => (string) $r['title'],
            'meeting_date' => (string) $r['meeting_date'],
            'meeting_time' => $r['meeting_time'] !== null && $r['meeting_time'] !== ''
                ? substr((string) $r['meeting_time'], 0, 5) : null,
            'location'     => trim((string) ($r['location'] ?? '')) ?: null,
            'meeting_type' => (string) $r['meeting_type'],
            'agenda'       => trim((string) ($r['agenda'] ?? '')) ?: null,
            'minutes'      => trim((string) ($r['minutes'] ?? '')) ?: null,
            'status'       => (string) $r['status'],
            'created_at'   => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        ];
        if (array_key_exists('creator_name', $r)) {
            $row['creator_name'] = trim((string) ($r['creator_name'] ?? '')) ?: null;
        }
        if (array_key_exists('present_count', $r)) {
            $row['present_count'] = (int) $r['present_count'];
        }
        return $row;
    }
}

if (!function_exists('vk_api_meetings_actions')) {
    /** What THIS caller may do. No status-dependent gating — the web has never had one. */
    function vk_api_meetings_actions(array $auth): array
    {
        return [
            'edit'   => vk_api_can($auth, 'edit', 'meetings'),
            'delete' => vk_api_can($auth, 'delete', 'meetings'),
        ];
    }
}

if (!function_exists('vk_api_meetings_load')) {
    /** One meeting by id, or a 404. */
    function vk_api_meetings_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A meeting id is required.');
        }
        $st = $pdo->prepare(
            "SELECT m.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS creator_name
               FROM meetings m
               LEFT JOIN users u ON m.created_by = u.user_id
              WHERE m.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No meeting was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_meetings_roster')) {
    /**
     * The full active roster with current attendance for one meeting, exactly
     * matching app/constant/meetings/meeting_view.php's own query: every
     * active, non-deceased member appears, defaulting to 'absent' when no
     * attendance row exists yet — this is a DISPLAY default only. See this
     * file's own note on why `POST .../attendance` does not treat an
     * unlisted member as an implicit absence, and why
     * `POST .../fine-absentees` only fines members with a real 'absent' row.
     */
    function vk_api_meetings_roster(PDO $pdo, int $meetingId): array
    {
        $st = $pdo->prepare("
            SELECT c.customer_id,
                   TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS name,
                   COALESCE(a.status, 'absent') AS att_status
              FROM customers c
              LEFT JOIN meeting_attendance a ON a.member_id = c.customer_id AND a.meeting_id = ?
             WHERE (c.status IS NULL OR c.status <> 'deleted') AND COALESCE(c.is_deceased, 0) = 0
             ORDER BY c.first_name, c.last_name
        ");
        $st->execute([$meetingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_api_meetings_roster_row')) {
    function vk_api_meetings_roster_row(array $r): array
    {
        return [
            'member_id' => (int) $r['customer_id'],
            'name'      => (string) $r['name'],
            'status'    => (string) $r['att_status'],
        ];
    }
}

if (!function_exists('vk_api_meetings_filters')) {
    /**
     * The query filters the list endpoint accepts, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_meetings_filters(array $q, string $alias = 'm'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_meeting_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_meeting_statuses()) . '.');
            }
            $where[]  = "{$a}status = ?";
            $params[] = $status;
        }

        $type = trim((string) ($q['type'] ?? ''));
        if ($type !== '') {
            if (!in_array($type, vk_meeting_types(), true)) {
                vk_api_error(422, 'invalid_type', 'type must be one of: '
                    . implode(', ', vk_meeting_types()) . '.');
            }
            $where[]  = "{$a}meeting_type = ?";
            $params[] = $type;
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $raw = trim((string) ($q[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (!vk_is_valid_date($raw)) {
                vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
            }
            $where[]  = "{$a}meeting_date {$op} ?";
            $params[] = $raw;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[]  = "{$a}title LIKE ?";
            $params[] = '%' . $search . '%';
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_meetings_parse_attendance')) {
    /**
     * Validate a submitted attendance array into [(member_id, status), ...].
     * Every member_id must belong to a real, non-deleted member — an unknown
     * id refuses the whole batch rather than silently writing an orphan row
     * (stricter than the web's own action, which never checks).
     */
    function vk_api_meetings_parse_attendance(PDO $pdo, $raw): array
    {
        if (!is_array($raw) || !$raw) {
            vk_api_error(422, 'attendance_required', 'attendance must be a non-empty array of {member_id, status}.');
        }

        $parsed = [];
        $ids = [];
        foreach ($raw as $i => $entry) {
            if (!is_array($entry)) {
                vk_api_error(422, 'invalid_attendance', "attendance[{$i}] must be an object.");
            }
            $memberId = (int) ($entry['member_id'] ?? 0);
            if ($memberId <= 0) {
                vk_api_error(422, 'invalid_attendance', "attendance[{$i}].member_id is required.");
            }
            $status = strtolower(trim((string) ($entry['status'] ?? '')));
            if (!in_array($status, ['present', 'absent'], true)) {
                vk_api_error(422, 'invalid_attendance', "attendance[{$i}].status must be present or absent.");
            }
            $parsed[] = ['member_id' => $memberId, 'status' => $status];
            $ids[] = $memberId;
        }

        $ids = array_unique($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id IN ($in) AND (status IS NULL OR status <> 'deleted')");
        $st->execute($ids);
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $unknown = array_diff($ids, $valid);
        if ($unknown) {
            vk_api_error(404, 'member_not_found', 'No member was found with id(s): ' . implode(', ', $unknown) . '.');
        }

        return $parsed;
    }
}

if (!function_exists('vk_api_meetings_save_attendance')) {
    /** Upsert exactly the submitted (member_id, status) pairs. Returns the count written. */
    function vk_api_meetings_save_attendance(PDO $pdo, int $meetingId, array $entries, int $userId): int
    {
        $upsert = $pdo->prepare(
            'INSERT INTO meeting_attendance (meeting_id, member_id, status, marked_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)'
        );
        foreach ($entries as $e) {
            $upsert->execute([$meetingId, $e['member_id'], $e['status'], $userId]);
        }
        return count($entries);
    }
}
