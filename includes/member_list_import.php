<?php
/**
 * includes/member_list_import.php — the rules for bin/import_members.php, which loads
 * a group's member list from a prepared CSV. Pure: no database, no files.
 *
 * The CSV (UTF-8, one row per member, header row first):
 *   source_ref     the member's number in the source sheet (unique; the re-run key)
 *   first_name, middle_name, last_name
 *   phone          255XXXXXXXXX or empty
 *   gender         Mwanaume | Mwanamke | empty
 *   member_number  e.g. M/FM/0001/ME, or empty when not confirmed
 *   role           Member | Chairperson | Secretary | Treasurer
 *   title          the group's own title for a leader (e.g. Makamu M/kiti), for the report
 *   spouse, father, mother   full names, or empty
 *   children       names separated by " | "
 *   note           anything a person still has to check (kept in the report)
 */

if (!function_exists('vk_import_columns')) {
    function vk_import_columns(): array
    {
        return ['source_ref', 'first_name', 'middle_name', 'last_name', 'phone', 'gender', 'member_number',
                'role', 'title', 'spouse', 'father', 'mother', 'children', 'note'];
    }
}

if (!function_exists('vk_import_roles')) {
    /** Roles a row may ask for. Anything else is refused, never invented. */
    function vk_import_roles(): array
    {
        return ['Member', 'Chairperson', 'Secretary', 'Treasurer'];
    }
}

if (!function_exists('vk_import_split_name')) {
    /** "Anna Maria Mtey" → ['Anna', 'Maria', 'Mtey']; one word → [word, '', '']. */
    function vk_import_split_name(string $full): array
    {
        $w = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$w) {
            return ['', '', ''];
        }
        if (count($w) === 1) {
            return [$w[0], '', ''];
        }
        return [$w[0], implode(' ', array_slice($w, 1, -1)), $w[count($w) - 1]];
    }
}

if (!function_exists('vk_import_children_json')) {
    /** The customers.children_data shape the member form writes. */
    function vk_import_children_json(string $children): string
    {
        $out = [];
        foreach (preg_split('/\s*\|\s*/', trim($children), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            $out[] = ['name' => $name, 'dob' => null, 'age' => null, 'gender' => '', 'photo' => null];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('vk_import_check_header')) {
    /** Null when the header has every column (any order), else the problem. */
    function vk_import_check_header(array $header): ?string
    {
        $header = array_map(static fn($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);
        $missing = array_diff(vk_import_columns(), $header);
        return $missing ? 'The CSV is missing column(s): ' . implode(', ', $missing) : null;
    }
}

if (!function_exists('vk_import_row')) {
    /**
     * One CSV row as a clean record, plus the reasons it cannot be imported.
     *
     * @return array{0:array,1:string[]}  [record, errors]
     */
    function vk_import_row(array $header, array $cells): array
    {
        $header = array_map(static fn($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);
        $r = [];
        foreach (vk_import_columns() as $col) {
            $i = array_search($col, $header, true);
            $r[$col] = trim(preg_replace('/\s+/u', ' ', (string) ($i === false ? '' : ($cells[$i] ?? ''))));
        }
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $r['source_ref'])) {
            $errors[] = 'source_ref must be 1-40 letters, digits, dot, dash or underscore';
        }
        if ($r['first_name'] === '' || $r['last_name'] === '') {
            $errors[] = 'first and last name are required';
        }
        if ($r['phone'] !== '' && !preg_match('/^255[67]\d{8}$/', $r['phone'])) {
            $errors[] = "phone '{$r['phone']}' is not 255 followed by 9 digits";
        }
        if (!in_array($r['gender'], ['', 'Mwanaume', 'Mwanamke'], true)) {
            $errors[] = "gender '{$r['gender']}' must be Mwanaume, Mwanamke or empty";
        }
        if ($r['role'] === '') {
            $r['role'] = 'Member';
        }
        if (!in_array($r['role'], vk_import_roles(), true)) {
            $errors[] = "role '{$r['role']}' is not one of " . implode(', ', vk_import_roles());
        }
        if (mb_strlen($r['member_number']) > 50) {
            $errors[] = 'member_number is longer than 50 characters';
        }
        return [$r, $errors];
    }
}

if (!function_exists('vk_import_duplicates')) {
    /**
     * Values that must be unique in the file but repeat: source_ref (an error)
     * and member_number (an error: two people cannot share one number).
     *
     * @return string[]
     */
    function vk_import_duplicates(array $records): array
    {
        $problems = [];
        foreach (['source_ref' => 'source_ref', 'member_number' => 'member number'] as $col => $label) {
            $seen = [];
            foreach ($records as $r) {
                $v = $r[$col];
                if ($v === '') {
                    continue;
                }
                $seen[$v] = ($seen[$v] ?? 0) + 1;
            }
            foreach ($seen as $v => $n) {
                if ($n > 1) {
                    $problems[] = "$label '$v' appears $n times";
                }
            }
        }
        return $problems;
    }
}

if (!function_exists('vk_import_same_person')) {
    /**
     * Is an existing login the same person as this row? Phone, first name and
     * last name must all match (phones alone are shared by some spouses).
     */
    function vk_import_same_person(array $row, array $user): bool
    {
        $phone = preg_replace('/\D/', '', (string) ($user['phone'] ?? ''));
        return $row['phone'] !== ''
            && $phone === $row['phone']
            && mb_strtolower(trim((string) $user['first_name'])) === mb_strtolower($row['first_name'])
            && mb_strtolower(trim((string) $user['last_name'])) === mb_strtolower($row['last_name']);
    }
}

if (!function_exists('vk_import_too_long')) {
    /**
     * Values longer than the database columns they go into, found before
     * anything is written. $limits = field => max characters (read from the
     * database: the smaller of users / customers where both are written).
     *
     * @return string[]
     */
    function vk_import_too_long(array $r, array $limits): array
    {
        $values = [
            'first_name' => $r['first_name'], 'middle_name' => $r['middle_name'], 'last_name' => $r['last_name'],
            'phone' => $r['phone'], 'member_number' => $r['member_number'],
            'father_name' => $r['father'], 'mother_name' => $r['mother'],
        ];
        foreach (['spouse', 'father', 'mother'] as $who) {
            foreach (array_combine(['first', 'middle', 'last'], vk_import_split_name($r[$who])) as $part => $v) {
                $values["{$who}_{$part}_name"] = $v;
            }
        }
        $problems = [];
        foreach ($values as $field => $v) {
            if (isset($limits[$field]) && mb_strlen($v) > $limits[$field]) {
                $problems[] = "$field is " . mb_strlen($v) . " characters; the database allows {$limits[$field]}";
            }
        }
        return $problems;
    }
}
