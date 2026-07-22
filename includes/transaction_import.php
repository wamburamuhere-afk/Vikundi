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

/**
 * Repair a TRANS_ID that Excel mangled into scientific notation (e.g. "3.83E+15")
 * when the M-Koba CSV was opened/saved in a spreadsheet. The true digits are
 * unrecoverable from the rounded string, so fall back to the RECEIPT — a real,
 * unique transaction reference (for most M-Koba rows TRANS_ID == RECEIPT anyway).
 * A clean TRANS_ID (or one with no receipt fallback) is returned as-is.
 */
function mkoba_repair_trans_id(string $transId, string $receipt): string
{
    $t = trim($transId);
    $r = trim($receipt);
    if ($t !== '' && $r !== '' && preg_match('/^\d+(\.\d+)?[eE][+\-]?\d+$/', $t)) {
        return $r; // Excel-corrupted numeric -> use the receipt
    }
    return $t;
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
        'trans_id'    => mkoba_repair_trans_id((string) ($assoc['trans_id'] ?? ''), (string) ($assoc['receipt'] ?? '')),
        'sno'         => trim((string) ($assoc['no'] ?? '')),
        'type'        => 'monthly', // M-Koba contributions map to the monthly type
        'account'     => 'M-Koba',
        'description' => 'M-Koba: ' . trim($transType),
    ];
}

/**
 * Build a CSV (as a string) of the rows that didn't match a member during an
 * import, so the user can download, onboard those members, and re-import. Each
 * $rows entry is an assoc with name/phone/amount/date/receipt/trans_type/reason
 * (as collected by actions/import_contributions.php). Pure — no DB / no globals.
 */
function unmatched_rows_to_csv(array $rows): string
{
    // Explicit empty $escape: RFC-4180 output (no backslash escaping) and avoids
    // PHP 8.4's deprecation for the omitted parameter.
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['member_name', 'phone', 'amount', 'date', 'receipt', 'trans_type', 'reason'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['name']       ?? ''),
            (string) ($r['phone']      ?? ''),
            (string) ($r['amount']     ?? ''),
            (string) ($r['date']       ?? ''),
            (string) ($r['receipt']    ?? ''),
            (string) ($r['trans_type'] ?? ''),
            (string) ($r['reason']     ?? 'No matching member'),
        ], ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

/**
 * Why an M-Koba row is NOT a member contribution (so it's kept for the tie-out
 * but never counted in the books). Returns '' for rows that ARE contributions.
 */
function mkoba_exclusion_reason(string $transType, string $phone, float $amount): string
{
    $t = strtolower(trim($transType));
    if ($t === '')                             return 'No transaction type (balance / non-payment row)';
    if ($t === 'group transfer')               return 'Group transfer (not a member contribution)';
    if ($t === 'opening an account on cbs')    return 'Account opening (no money moved)';
    if ($phone === '')                         return 'No member phone on the row';
    if ($amount <= 0)                          return 'Zero amount';
    return ''; // it is a contribution
}

/**
 * Flatten one raw M-Koba statement row into the shape stored in
 * `mkoba_statement_rows` — EVERY row, contribution or not, exactly as printed
 * (phone/account numbers cleaned of Excel's ".00"). Pure — no DB. The caller
 * resolves the final `outcome` (imported vs missing) against the books; here we
 * only mark whether the row is a contribution and, if not, why it's excluded.
 */
function mkoba_mirror_row(array $assoc): array
{
    $clean = fn($v) => preg_replace('/[^0-9]/', '', preg_replace('/\.0+$/', '', trim((string) $v)));
    $transType = trim((string) ($assoc['trans type'] ?? ''));
    $phone  = mkoba_normalize_phone((string) ($assoc['member id'] ?? ''));
    $amount = mkoba_parse_amount((string) ($assoc['amount'] ?? ''));
    $reason = mkoba_exclusion_reason($transType, $phone, $amount);
    return [
        'sno'             => trim((string) ($assoc['no'] ?? '')),
        'trans_id'        => mkoba_repair_trans_id((string) ($assoc['trans_id'] ?? ''), (string) ($assoc['receipt'] ?? '')),
        'receipt'         => trim((string) ($assoc['receipt'] ?? '')),
        'trans_date'      => mkoba_parse_date((string) ($assoc['date'] ?? '')),
        'member_name'     => trim((string) ($assoc['member name'] ?? '')),
        'member_id'       => $clean($assoc['member id'] ?? ''),
        'source'          => $clean($assoc['source'] ?? ''),
        'destination'     => $clean($assoc['destination'] ?? ''),
        'amount'          => $amount,
        'trans_type'      => $transType,
        'match_phone'     => $phone,               // last-9, for linking to a contribution
        'is_contribution' => ($reason === ''),
        'reason'          => $reason,
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
