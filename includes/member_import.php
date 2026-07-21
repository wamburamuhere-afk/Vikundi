<?php
/**
 * includes/member_import.php
 * --------------------------
 * Pure, testable parsing for the simple members bulk-upload template.
 *
 * The template is HEADER-NAMED (columns identified by name), so column order
 * doesn't matter and an extra/missing column can't shift the data. Only the core
 * member fields are accepted here — parents/spouse/children/guarantor/photos are
 * added per-member later via the edit screens.
 */

/** Clean a phone/NIDA to digits (drops an Excel ".00" decimal suffix). */
function member_import_clean_digits(string $raw): string
{
    $s = trim($raw);
    if ($s === '') return '';
    $dot = strpos($s, '.');
    if ($dot !== false) $s = substr($s, 0, $dot);
    return preg_replace('/[^0-9+]/', '', $s);
}

/** Normalise gender to Male/Female (accepts m/f, male/female, Swahili). */
function member_import_normalize_gender(string $raw): string
{
    $g = strtolower(trim($raw));
    if ($g === '') return '';
    if (in_array($g, ['m', 'male', 'mwanaume', 'me'], true))   return 'Male';
    if (in_array($g, ['f', 'female', 'mwanamke', 'ke'], true)) return 'Female';
    return trim($raw);
}

/**
 * Normalise one members-template row (assoc keyed by lowercased header).
 * Returns the normalised row, or a short error string when a REQUIRED field
 * (first_name, last_name, phone) is missing. middle_name is OPTIONAL — many
 * rosters (e.g. the M-Koba statement) carry only a first + surname.
 *
 * @return array|string
 */
function member_import_parse_row(array $assoc)
{
    $g = fn(string $k): string => trim((string) ($assoc[$k] ?? ''));

    $first  = $g('first_name');
    $middle = $g('middle_name');
    $last   = $g('last_name');
    $phone  = member_import_clean_digits($g('phone'));

    $missing = [];
    if ($first === '')  $missing[] = 'first_name';
    if ($last === '')   $missing[] = 'last_name';
    if ($phone === '')  $missing[] = 'phone';
    if ($missing) return 'missing required ' . implode(', ', $missing);

    $country = $g('country');

    return [
        'first_name'      => $first,
        'middle_name'     => $middle,
        'last_name'       => $last,
        'phone'           => $phone,
        'email'           => $g('email'),
        'gender'          => member_import_normalize_gender($g('gender')),
        'nida'            => member_import_clean_digits($g('nida')),
        'birth_region'    => $g('birth_region'),
        'marital_status'  => $g('marital_status'),
        'religion'        => $g('religion'),
        'initial_savings' => (float) preg_replace('/[^0-9.]/', '', $g('initial_savings')),
        'country'         => $country !== '' ? $country : 'Tanzania',
        'region'          => $g('region'),
        'district'        => $g('district'),
        'ward'            => $g('ward'),
        'street'          => $g('street'),
        'house_number'    => $g('house_number'),
    ];
}
