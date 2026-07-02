<?php
// includes/meeting_helpers.php
//
// Pure helpers for the Meetings module — no DB, so they can be unit tested.

if (!function_exists('vk_meeting_types')) {
    function vk_meeting_types(): array { return ['regular', 'special', 'agm']; }
}
if (!function_exists('vk_meeting_statuses')) {
    function vk_meeting_statuses(): array { return ['scheduled', 'held', 'cancelled']; }
}

if (!function_exists('vk_normalize_meeting_type')) {
    /** Return a valid meeting type, defaulting to 'regular'. */
    function vk_normalize_meeting_type($t): string {
        $t = strtolower(trim((string) $t));
        return in_array($t, vk_meeting_types(), true) ? $t : 'regular';
    }
}
if (!function_exists('vk_normalize_meeting_status')) {
    /** Return a valid meeting status, defaulting to 'scheduled'. */
    function vk_normalize_meeting_status($s): string {
        $s = strtolower(trim((string) $s));
        return in_array($s, vk_meeting_statuses(), true) ? $s : 'scheduled';
    }
}

if (!function_exists('vk_meeting_input_errors')) {
    /**
     * Validate a meeting submission. Returns a list of error messages (empty when
     * valid). Title and a valid date are required; type/status are normalised by
     * the caller, so only obviously-bad values are flagged here.
     *
     * @param array $post request data (title, meeting_date, ...)
     */
    function vk_meeting_input_errors(array $post, bool $sw = false): array {
        $errors = [];
        $title = trim((string) ($post['title'] ?? ''));
        $date  = trim((string) ($post['meeting_date'] ?? ''));

        if ($title === '') {
            $errors[] = $sw ? 'Kichwa cha mkutano kinahitajika.' : 'Meeting title is required.';
        }
        if ($date === '') {
            $errors[] = $sw ? 'Tarehe ya mkutano inahitajika.' : 'Meeting date is required.';
        } elseif (!vk_is_valid_date($date)) {
            $errors[] = $sw ? 'Tarehe ya mkutano si sahihi.' : 'Meeting date is not a valid date.';
        }
        return $errors;
    }
}

if (!function_exists('vk_is_valid_date')) {
    /** True when $d is a real calendar date in Y-m-d form. */
    function vk_is_valid_date(string $d): bool {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}

if (!function_exists('vk_attendance_summary')) {
    /**
     * Summarise attendance rows into present/absent/total counts.
     * Anything not explicitly 'present' counts as absent.
     *
     * @param array $rows each row has a 'status' key
     */
    function vk_attendance_summary(array $rows): array {
        $present = 0;
        foreach ($rows as $r) {
            if (strtolower((string) ($r['status'] ?? '')) === 'present') {
                $present++;
            }
        }
        $total = count($rows);
        return ['present' => $present, 'absent' => $total - $present, 'total' => $total];
    }
}
