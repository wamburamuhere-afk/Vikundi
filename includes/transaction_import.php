<?php
/**
 * includes/transaction_import.php
 * -------------------------------
 * Pure, testable parsing for the bulk transaction imports (Finance > Transactions):
 *   - M-Koba statement rows (their column names, Excel quirks).
 *   - Our own template rows.
 *
 * No DB / no globals — the importer (actions/import_contributions.php) uses these
 * to normalise each CSV row, then matches the member and inserts.
 */

/** Allowed contribution types / accounts (kept in step with the record form). */
function txn_allowed_types(): array    { return ['entrance', 'monthly', 'agm', 'fine', 'other']; }
function txn_allowed_accounts(): array { return ['M-Koba', 'Bank', 'Cash', 'Mobile Money']; }

/**
 * Normalise a phone/member-id to its last 9 digits for matching.
 * Handles the M-Koba Excel quirks: "255767276015.00" (decimal suffix) and a
 * 255… country prefix both reduce to "767276015".
 */
function mkoba_normalize_phone(string $raw): string
{
    $s = trim($raw);
    if ($s === '') return '';
    // Drop an Excel decimal suffix (".00") before stripping separators, otherwise
    // the trailing zeros would corrupt the last-9 match.
    $dot = strpos($s, '.');
    if ($dot !== false) $s = substr($s, 0, $dot);
    $d = preg_replace('/[^0-9]/', '', $s);
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

/** Parse an amount that may carry thousands commas / quotes ("5,000.00" -> 5000.0). */
function mkoba_parse_amount(string $raw): float
{
    return (float) preg_replace('/[^0-9.]/', '', trim($raw));
}

/** Parse an M-Koba date ("28/02/2026 23:50" -> "2026-02-28"); null if unparseable. */
function mkoba_parse_date(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') return null;
    $datePart = explode(' ', $s)[0]; // drop the time
    $dt = \DateTime::createFromFormat('d/m/Y', $datePart);
    return ($dt && $dt->format('d/m/Y') === $datePart) ? $dt->format('Y-m-d') : null;
}

/** Is this M-Koba TRANS TYPE a contribution we should import? */
function mkoba_is_contribution(string $transType): bool
{
    $t = strtolower(trim($transType));
    if ($t === '') return false; // blank rows are balance/failed entries
    $skip = ['opening an account on cbs', 'group transfer'];
    return !in_array($t, $skip, true);
}

/**
 * Normalise one M-Koba statement row (assoc keyed by lowercased header) into the
 * fields we store, or null to skip (non-contribution / missing phone or amount).
 */
function mkoba_parse_row(array $assoc): ?array
{
    $transType = (string) ($assoc['trans type'] ?? '');
    if (!mkoba_is_contribution($transType)) return null;

    $phone  = mkoba_normalize_phone((string) ($assoc['member id'] ?? ''));
    $amount = mkoba_parse_amount((string) ($assoc['amount'] ?? ''));
    if ($phone === '' || $amount <= 0) return null;

    return [
        'phone'       => $phone,
        'amount'      => $amount,
        'date'        => mkoba_parse_date((string) ($assoc['date'] ?? '')),
        'receipt'     => trim((string) ($assoc['receipt'] ?? '')),
        'name'        => trim((string) ($assoc['member name'] ?? '')),
        'trans_type'  => trim($transType),
        'source'      => trim((string) ($assoc['source'] ?? '')),
        'destination' => trim((string) ($assoc['destination'] ?? '')),
        'trans_id'    => trim((string) ($assoc['trans_id'] ?? '')),
        'sno'         => trim((string) ($assoc['no'] ?? '')),
        'type'        => 'monthly', // M-Koba contributions map to the monthly type
        'account'     => 'M-Koba',
        'description' => 'M-Koba: ' . trim($transType),
    ];
}

/**
 * Normalise one row of OUR template (headers: receipt_number, date, member_phone,
 * member_name, amount, type, account, description). Null to skip.
 */
function txn_template_parse_row(array $assoc): ?array
{
    $phone  = mkoba_normalize_phone((string) ($assoc['member_phone'] ?? $assoc['phone'] ?? ''));
    $amount = mkoba_parse_amount((string) ($assoc['amount'] ?? ''));
    if ($phone === '' || $amount <= 0) return null;

    // Our template uses Y-m-d; fall back to the M-Koba parser, else null (today).
    $rawDate = trim((string) ($assoc['date'] ?? ''));
    $date = null;
    if ($rawDate !== '') {
        $dt = \DateTime::createFromFormat('Y-m-d', $rawDate);
        $date = ($dt && $dt->format('Y-m-d') === $rawDate) ? $rawDate : mkoba_parse_date($rawDate);
    }

    $type = strtolower(trim((string) ($assoc['type'] ?? '')));
    if (!in_array($type, txn_allowed_types(), true)) $type = 'monthly';

    $account = trim((string) ($assoc['account'] ?? ''));
    if (!in_array($account, txn_allowed_accounts(), true)) $account = null;

    return [
        'phone'       => $phone,
        'amount'      => $amount,
        'date'        => $date,
        'receipt'     => trim((string) ($assoc['receipt_number'] ?? '')),
        'name'        => trim((string) ($assoc['member_name'] ?? '')),
        'trans_type'  => '',
        'source'      => '',
        'destination' => '',
        'trans_id'    => '',
        'sno'         => '',
        'type'        => $type,
        'account'     => $account,
        'description' => trim((string) ($assoc['description'] ?? '')),
    ];
}
