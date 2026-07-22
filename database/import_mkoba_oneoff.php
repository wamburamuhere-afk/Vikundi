<?php
/**
 * database/import_mkoba_oneoff.php
 * --------------------------------
 * ONE-OFF onboarding of an M-Koba statement export:
 *   1. Create the distinct members (beneficiary name + phone) as PENDING users +
 *      customers — auto username (first-initial + surname) + password
 *      "<username>@123", like ajax/process_member_import.php (middle name
 *      optional). Pending = they await committee approval ("New Requests").
 *   2. Import their contributions from the same file, matched by phone and
 *      de-duped by receipt. NOTE: because the members are pending, we match by
 *      phone REGARDLESS of status (the app's live importer requires 'active';
 *      this one-off attaches the historical rows to the pending records so the
 *      full picture is ready the moment a member is approved). Contributions are
 *      themselves inserted 'pending' for treasurer review.
 *
 * Reuses the tested pure parsers (includes/member_import.php,
 * includes/transaction_import.php).
 *
 * ⚠️ The `users` table is MyISAM (non-transactional), so a DB transaction CANNOT
 * roll back member creation. Therefore:
 *   • The dry-run (default) performs **zero writes** — it only SELECTs and counts.
 *   • The committed run (--commit) is **idempotent**: members already present
 *     (by phone) and contributions already present (by receipt) are skipped, so
 *     it is safe to re-run after an interruption. If a customer insert fails
 *     after its user was created, that orphan user is deleted immediately.
 *
 * Usage:
 *   php database/import_mkoba_oneoff.php "M-Koba - Ukuu Msakuzi.csv"           # DRY-RUN (no writes)
 *   php database/import_mkoba_oneoff.php "M-Koba - Ukuu Msakuzi.csv" --commit  # persist
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/../includes/config.php';            // $pdo
require __DIR__ . '/../includes/member_import.php';     // member_import_parse_row, member_import_clean_digits
require __DIR__ . '/../includes/transaction_import.php'; // mkoba_parse_row, mkoba_mirror_row
require __DIR__ . '/../includes/mkoba_mirror.php';       // mkoba_populate_mirror (shared with the web importer)

$csvPath    = $argv[1] ?? null;
$commit     = in_array('--commit', $argv, true);
$mirrorOnly = in_array('--mirror-only', $argv, true); // (re)build the reconciliation mirror only — no members/contributions
if (!$csvPath || !is_file($csvPath)) { fwrite(STDERR, "Usage: php database/import_mkoba_oneoff.php <file.csv> [--commit | --mirror-only]\n"); exit(1); }

$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$modeLabel = $mirrorOnly ? 'MIRROR-ONLY (reconciliation, no members/contributions)' : ($commit ? 'COMMIT (persist)' : 'DRY-RUN (no writes)');
fwrite(STDERR, "── M-Koba onboarding ──\nTarget DB : $dbName\nMode      : $modeLabel\n\n");

/** last9 of a phone/member-id (drops Excel ".00", country code, separators). */
function last9(string $raw): string
{
    $d = preg_replace('/[^0-9]/', '', preg_replace('/\.0+$/', '', trim($raw)));
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

// ── Read file once — .xlsx read directly (numeric TRANS_IDs keep their full
//    value), otherwise CSV. Both yield positional rows. ──
if (preg_match('/\.xlsx$/i', $csvPath)) {
    require __DIR__ . '/../includes/xlsx_reader.php';
    $allRows = xlsx_read_rows($csvPath);
} else {
    $allRows = [];
    $fh = fopen($csvPath, 'r');
    while (($r = fgetcsv($fh)) !== false) { $allRows[] = $r; }
    fclose($fh);
}
$headers = array_map(fn($x) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $x))), array_shift($allRows) ?: []);
$rows = [];
foreach ($allRows as $r) {
    if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0) continue;
    $a = [];
    foreach ($headers as $i => $k) if ($k !== '') $a[$k] = $r[$i] ?? '';
    $rows[] = $a;
}

$batch = basename($csvPath);

// ── MIRROR-ONLY: (re)build the reconciliation mirror from the statement and stop. ──
// Used to backfill a statement that was already imported, so the reconciliation
// view can tie out every row without re-creating members/contributions.
if ($mirrorOnly) {
    $ms = mkoba_populate_mirror($pdo, $rows, $batch);
    echo "Reconciliation mirror rebuilt for batch: $batch\n";
    echo "  rows total : " . array_sum($ms) . "\n";
    echo "  → imported (savings)      : {$ms['imported']}\n";
    echo "  → excluded (transfer/etc) : {$ms['excluded']}\n";
    echo "  → missing (not in ledger) : {$ms['missing']}\n";
    echo "\n✅ Mirror ready in $dbName\n";
    exit(0);
}

// ── Distinct members from the beneficiary columns ──
$members = []; // last9 => ['first','middle','last','phone']
foreach ($rows as $a) {
    $full = trim((string) ($a['member name'] ?? ''));
    $l9 = last9((string) ($a['member id'] ?? ''));
    if ($full === '' || strlen($l9) < 9 || isset($members[$l9])) continue;
    $words = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY);
    $tc = fn($s) => mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    $members[$l9] = [
        'first'  => $tc($words[0]),
        'middle' => count($words) > 2 ? $tc(implode(' ', array_slice($words, 1, -1))) : '',
        'last'   => $tc($words[count($words) - 1]),
        'phone'  => '0' . $l9,
    ];
}

// ── Existing members already in the DB (by last9) ──
$existing = [];
foreach ($pdo->query("SELECT phone FROM customers")->fetchAll(PDO::FETCH_COLUMN) as $ph) {
    $l9 = last9((string) $ph);
    if ($l9 !== '') $existing[$l9] = true;
}
$willExist = $existing + array_fill_keys(array_keys($members), true); // union

$member_role_id = $pdo->query("SELECT role_id FROM roles WHERE LOWER(role_name) LIKE '%member%' OR LOWER(role_name) LIKE '%mwanachama%' LIMIT 1")->fetchColumn() ?: null;
$created_by = $pdo->query("SELECT user_id FROM users WHERE role_id IN (1,12) OR LOWER(user_role) LIKE '%admin%' ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1;

$new = array_filter(array_keys($members), fn($l9) => !isset($existing[$l9]));
$stats = ['members_new' => count($new), 'members_existing' => count($members) - count($new),
          'contribs_match' => 0, 'contribs_skip' => 0, 'contribs_unmatched' => 0, 'contribs_inserted' => 0, 'contribs_dupe' => 0];
$unmatched = [];

if (!$commit) {
    // ── DRY-RUN: no writes. Project match/skip/unmatched from the will-exist set. ──
    foreach ($rows as $a) {
        $p = mkoba_parse_row($a);
        if ($p === null) { $stats['contribs_skip']++; continue; }
        if (isset($willExist[$p['phone']])) { $stats['contribs_match']++; }
        else { $stats['contribs_unmatched']++; $unmatched[] = ($p['name'] ?: 'Unknown') . ' (' . $p['phone'] . ')'; }
    }
    echo "Distinct members in file : " . count($members) . "\n";
    echo "  → new (would create)   : {$stats['members_new']}\n";
    echo "  → already in system    : {$stats['members_existing']}\n";
    echo "Contributions\n";
    echo "  → would match a member : {$stats['contribs_match']}\n";
    echo "  → non-contrib (skipped): {$stats['contribs_skip']}\n";
    echo "  → unmatched (no member): {$stats['contribs_unmatched']}\n";
    if ($unmatched) echo "    e.g. " . implode(', ', array_slice($unmatched, 0, 8)) . (count($unmatched) > 8 ? ' …' : '') . "\n";
    echo "\n↩️  DRY-RUN — no changes made to $dbName. Re-run with --commit to persist.\n";
    exit(0);
}

// ── COMMIT: idempotent, self-healing ──
$existsByPhone = $pdo->prepare("SELECT customer_id FROM customers WHERE RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''),9) = ? LIMIT 1");
$uCheck  = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$uInsert = $pdo->prepare("INSERT INTO users (username, email, password, first_name, middle_name, last_name, phone, user_role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Member', ?, 'pending', NOW())");
$uDelete = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
$cInsert = $pdo->prepare("INSERT INTO customers (first_name, middle_name, last_name, customer_name, email, phone, status, initial_savings, user_id, country, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, 'Tanzania', NOW())");
// Match by phone regardless of status: the members we just made are 'pending',
// so requiring 'active' (as the live importer does) would leave every row unmatched.
$findMember   = $pdo->prepare("SELECT c.customer_id FROM customers c WHERE c.is_deceased = 0 AND c.phone LIKE ? LIMIT 1");
$dupByReceipt = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE mkoba_receipt = ? AND member_id = ?");
$cInsertTxn   = $pdo->prepare("INSERT INTO contributions (member_id, amount, contribution_type, contribution_date, description, status, receipt_number, account, mkoba_receipt, mkoba_trans_type, mkoba_source, mkoba_destination, mkoba_member_id_str, mkoba_member_name, mkoba_trans_id, mkoba_sno, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");

$used = [];
$uniq = function (string $first, string $last) use ($pdo, $uCheck, &$used): string {
    $base = strtolower(substr($first, 0, 1) . preg_replace('/\s+/', '', $last)) ?: 'member';
    $u = $base; $n = 1;
    while (isset($used[$u]) || ($uCheck->execute([$u]) && (int) $uCheck->fetchColumn() > 0)) { $u = $base . $n; $n++; }
    $used[$u] = true;
    return $u;
};

$made = 0;
foreach ($members as $l9 => $m) {
    $existsByPhone->execute([$l9]);
    if ($existsByPhone->fetchColumn()) continue; // already onboarded — idempotent skip
    $username = $uniq($m['first'], $m['last']);
    $uInsert->execute([$username, null, password_hash($username . '@123', PASSWORD_BCRYPT), $m['first'], $m['middle'], $m['last'], $m['phone'], $member_role_id]);
    $user_id = $pdo->lastInsertId();
    try {
        $full = preg_replace('/\s+/', ' ', trim($m['first'] . ' ' . $m['middle'] . ' ' . $m['last']));
        $cInsert->execute([$m['first'], $m['middle'], $m['last'], $full, null, $m['phone'], $user_id]);
        $made++;
    } catch (Throwable $e) {
        $uDelete->execute([$user_id]); // compensate: users is MyISAM, no rollback
        fwrite(STDERR, "  ! member {$m['phone']} failed, orphan user removed: " . $e->getMessage() . "\n");
    }
}
$stats['members_new'] = $made;

foreach ($rows as $a) {
    $p = mkoba_parse_row($a);
    if ($p === null) { $stats['contribs_skip']++; continue; }
    $findMember->execute(['%' . $p['phone']]);
    $member_id = $findMember->fetchColumn();
    if (!$member_id) { $stats['contribs_unmatched']++; $unmatched[] = ($p['name'] ?: 'Unknown') . ' (' . $p['phone'] . ')'; continue; }
    if ($p['receipt'] !== '') { $dupByReceipt->execute([$p['receipt'], $member_id]); if ((int) $dupByReceipt->fetchColumn() > 0) { $stats['contribs_dupe']++; continue; } }
    $cInsertTxn->execute([
        $member_id, $p['amount'], $p['type'], $p['date'] ?? date('Y-m-d'), $p['description'],
        $p['receipt'] ?: null, $p['account'], $p['receipt'] ?: null, $p['trans_type'] ?: null,
        $p['source'] ?: null, $p['destination'] ?: null, $p['phone'], $p['name'] ?: null,
        $p['trans_id'] ?: null, $p['sno'] ?: null, $created_by,
    ]);
    $stats['contribs_inserted']++;
}

// Build the reconciliation mirror now that the contributions exist to link to.
$ms = mkoba_populate_mirror($pdo, $rows, $batch);

echo "Members created         : {$stats['members_new']}\n";
echo "Contributions inserted  : {$stats['contribs_inserted']}\n";
echo "  duplicate (skipped)   : {$stats['contribs_dupe']}\n";
echo "  non-contrib (skipped) : {$stats['contribs_skip']}\n";
echo "  unmatched (no member) : {$stats['contribs_unmatched']}\n";
if ($unmatched) echo "    e.g. " . implode(', ', array_slice($unmatched, 0, 8)) . (count($unmatched) > 8 ? ' …' : '') . "\n";
echo "Reconciliation mirror   : {$ms['imported']} imported · {$ms['excluded']} excluded · {$ms['missing']} missing\n";
echo "\n✅ COMMITTED to $dbName\n";
