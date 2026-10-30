<?php
/**
 * bin/import_entrance_fees.php — give each member their own entrance fee
 * (customers.entrance_fee) from a leader's list. Command line only. Two steps:
 *
 * 1. match (no database): the leader's list, saved as CSV, against the member list
 *    the group was imported from. Writes a review sheet and the prepared file.
 *      php bin/import_entrance_fees.php match --fees=fees.csv --members=ukuu_members.import.csv \
 *          --review=review.csv --prepared=prepared.csv [--overrides=overrides.csv]
 *    The fee list: a header row with "Kiingilio"; columns S/Na, real name, M-Koba
 *    account name, fee, note ("New member as of dd/mm/yyyy").
 *    Only sure matches go into the prepared file. Close spellings and clashes go to
 *    the review sheet; once a person confirms them, list them in --overrides
 *    (columns: no,source_ref — a blank source_ref leaves that row out).
 *
 * 2. apply (on the group): the prepared file into the database. Dry run by default.
 *      php bin/import_entrance_fees.php apply --tenant=ukuu --file=prepared.csv --source=ukuu-2026-08 \
 *          [--others-joined=YYYY-MM-DD] [--commit]
 *    Each row's source_ref is found through member_import_log (the member import).
 *    Sets the fee, and the join date from the note. --others-joined also sets the join
 *    date of every listed member WITHOUT a note (members from before), so their months
 *    start at the group's start instead of the day they were imported.
 *    Writes an undo file (the old values) next to --file before changing anything.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/entrance_fee_import.php';
require_once __DIR__ . '/../includes/provisioning.php'; // vk_prov_parse_args

$mode = $argv[1] ?? '';
$args = vk_prov_parse_args(array_merge([$argv[0]], array_slice($argv, 2)));

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "Error: $s\n"); exit(1); }

/** Read a CSV file into arrays of cells. */
function read_csv(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        fail("cannot read $file");
    }
    $fh = fopen($file, 'r');
    $rows = [];
    while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $rows[] = array_map(static fn($c) => trim(str_replace("\xEF\xBB\xBF", '', (string) $c)), $cells);
    }
    fclose($fh);
    return $rows;
}

function write_csv(string $file, array $header, array $rows): void
{
    $fh = fopen($file, 'w') ?: fail("cannot write $file");
    fputcsv($fh, $header, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ',', '"', '');
    }
    fclose($fh);
}

if ($mode === 'match') {
    foreach (['fees', 'members', 'review', 'prepared'] as $k) {
        if (empty($args[$k])) {
            fail("--$k is required");
        }
    }

    // The leader's list: rows after the header that has "Kiingilio", numbered in column 1.
    $rows = [];
    $seenHeader = false;
    foreach (read_csv($args['fees']) as $c) {
        if (!$seenHeader) {
            $seenHeader = (bool) preg_grep('/kiingilio|entrance/i', $c);
            continue;
        }
        if (!ctype_digit($c[0] ?? '')) {
            continue; // blank lines and the total
        }
        $rows[] = ['no' => $c[0], 'name' => $c[1] ?? '', 'mkoba_name' => $c[2] ?? '', 'fee' => efi_fee($c[3] ?? ''), 'note' => $c[4] ?? ''];
    }
    if (!$rows) {
        fail('no fee rows found (is there a header row with "Kiingilio"?)');
    }
    $badFee = array_filter($rows, static fn($r) => $r['fee'] === null);
    if ($badFee) {
        fail('rows without a valid fee: ' . implode(', ', array_column($badFee, 'no')));
    }

    // The member list the group was imported from.
    $m = read_csv($args['members']);
    $head = array_shift($m);
    $members = [];
    foreach ($m as $c) {
        $r = array_combine($head, array_pad($c, count($head), ''));
        $full = trim(preg_replace('/\s+/', ' ', "{$r['first_name']} {$r['middle_name']} {$r['last_name']}"));
        $members[] = ['ref' => $r['source_ref'], 'names' => array_values(array_unique([$full, "{$r['first_name']} {$r['last_name']}"])),
                      'phones' => array_filter([$r['phone'] ?? '']), 'number' => $r['member_number'] ?? ''];
    }
    $numberOf = array_column($members, 'number', 'ref');

    $result = efi_match($rows, $members);

    // Confirmed by a person: row number => source_ref (blank = leave out).
    if (!empty($args['overrides'])) {
        $ov = read_csv($args['overrides']);
        array_shift($ov);
        $map = [];
        foreach ($ov as $c) {
            $map[$c[0]] = $c[1] ?? '';
        }
        $label = array_column(array_map(static fn($x) => ['ref' => $x['ref'], 'n' => $x['names'][0]], $members), 'n', 'ref');
        foreach ($result as &$r) {
            if (array_key_exists($r['no'], $map)) {
                $ref = $map[$r['no']];
                if ($ref !== '' && !isset($label[$ref])) {
                    fail("override for row {$r['no']}: no member with source_ref $ref");
                }
                $r['status'] = $ref === '' ? 'none' : 'matched';
                $r['ref'] = $ref === '' ? null : $ref;
                $r['member'] = $ref === '' ? null : $label[$ref];
                $r['how'] = 'confirmed by a leader';
            }
        }
        unset($r);
        $taken = array_count_values(array_filter(array_column(array_filter($result, static fn($r) => $r['status'] === 'matched'), 'ref')));
        $twice = array_keys(array_filter($taken, static fn($n) => $n > 1));
        if ($twice) {
            fail('these members would get two fees: source_ref ' . implode(', ', $twice));
        }
    }

    $word = ['matched' => 'Imelingana / Matched', 'check' => 'Thibitisha / Please confirm', 'none' => 'Hajapatikana / Not found'];
    $review = [];
    $prepared = [];
    foreach ($result as $r) {
        $review[] = [$r['no'], $r['name'], $r['mkoba_name'], number_format((float) $r['fee'], 0, '.', ''), $r['note'],
                     $word[$r['status']], $r['member'] ?? '', $r['ref'] !== null ? ($numberOf[$r['ref']] ?? '') : '', $r['ref'] ?? '', $r['how']];
        if ($r['status'] === 'matched') {
            $prepared[] = [$r['ref'], $r['member'], number_format((float) $r['fee'], 2, '.', ''), $r['join_date'] ?? ''];
        }
    }
    write_csv($args['review'], ['S/Na', 'Jina halisi', 'Jina la M-Koba', 'Kiingilio', 'Maelezo', 'Matokeo / Result',
                                'Mwanachama kwenye mfumo / Member in the system', 'Namba ya mwanachama', 'source_ref', 'Jinsi / How'], $review);
    write_csv($args['prepared'], ['source_ref', 'member', 'entrance_fee', 'join_date'], $prepared);

    $count = array_count_values(array_column($result, 'status'));
    out(sprintf('%d rows: %d matched, %d to confirm, %d not found.', count($result),
        $count['matched'] ?? 0, $count['check'] ?? 0, $count['none'] ?? 0));
    out('Review sheet: ' . $args['review']);
    out('Prepared file (matched rows only): ' . $args['prepared']);
    exit(0);
}

if ($mode !== 'apply') {
    fail('usage: import_entrance_fees.php match|apply ... (see the top of this file)');
}

// ── apply ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php'; // $pdo: --tenant=<group> on the shared server

$commit = isset($args['commit']);
$file   = (string) ($args['file'] ?? '');
$source = (string) ($args['source'] ?? '');
$others = (string) ($args['others-joined'] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $source)) {
    fail('--source is required: the label the members were imported with, e.g. ukuu-2026-08.');
}
if ($others !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $others, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1]))) {
    fail('--others-joined must be a date, YYYY-MM-DD.');
}

$lines = read_csv($file);
$head = array_shift($lines);
if ($head !== ['source_ref', 'member', 'entrance_fee', 'join_date']) {
    fail('the file must be a prepared file from the match step (source_ref,member,entrance_fee,join_date).');
}

$find = $pdo->prepare('SELECT c.customer_id, c.first_name, c.middle_name, c.last_name, c.entrance_fee, c.created_at
                         FROM member_import_log l JOIN customers c ON c.customer_id = l.customer_id
                        WHERE l.source = ? AND l.source_ref = ? LIMIT 1');
$plan = [];
$missing = [];
$warn = [];
foreach ($lines as $i => $c) {
    if (count(array_filter($c, static fn($x) => $x !== '')) === 0) {
        continue;
    }
    [$ref, $name, $fee, $join] = array_pad($c, 4, '');
    $fee = efi_fee($fee);
    if ($fee === null || ($join !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $join))) {
        fail('line ' . ($i + 2) . ': bad fee or join date.');
    }
    $find->execute([$source, $ref]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $missing[] = "$ref $name";
        continue;
    }
    $dbName = trim(preg_replace('/\s+/', ' ', "{$row['first_name']} {$row['middle_name']} {$row['last_name']}"));
    if (!array_intersect(efi_tokens($dbName), efi_tokens($name))) {
        $warn[] = "$ref: file says \"$name\", the group has \"$dbName\" (skipped)";
        continue; // renamed since the import: a person checks it
    }
    $newJoin = $join !== '' ? $join : ($others !== '' ? $others : null);
    $plan[] = ['id' => (int) $row['customer_id'], 'name' => $dbName, 'fee' => $fee, 'join' => $newJoin,
               'old_fee' => $row['entrance_fee'], 'old_join' => $row['created_at']];
}

$withJoin = count(array_filter($plan, static fn($p) => $p['join'] !== null));
out(($commit ? 'Applying' : 'Dry run') . " for {$source}: " . count($plan) . ' members get their entrance fee'
    . ($withJoin ? ", $withJoin a join date" : '') . '.');
$byFee = array_count_values(array_map(static fn($p) => number_format($p['fee'], 0), $plan));
ksort($byFee);
foreach ($byFee as $f => $n) {
    out("  TSh $f: $n members");
}
out('  Total: TSh ' . number_format(array_sum(array_column($plan, 'fee')), 0));
if ($missing) {
    out(count($missing) . ' not found in this group (left out):');
    foreach ($missing as $x) {
        out("  - $x");
    }
}
foreach ($warn as $x) {
    out("  ! $x");
}
if (!$commit) {
    out('Nothing changed. Add --commit to apply.');
    exit(0);
}
if (!$plan) {
    fail('nothing to apply.');
}

// The old values first, so this can be undone.
$undo = dirname(realpath($file)) . '/entrance_fee_undo_' . date('Ymd_His') . '.csv';
write_csv($undo, ['customer_id', 'entrance_fee', 'created_at'],
    array_map(static fn($p) => [$p['id'], $p['old_fee'] ?? '', $p['old_join']], $plan));
out("Old values saved to $undo");

$setFee  = $pdo->prepare('UPDATE customers SET entrance_fee = ? WHERE customer_id = ?');
$setJoin = $pdo->prepare('UPDATE customers SET created_at = ? WHERE customer_id = ?');
$pdo->beginTransaction();
try {
    foreach ($plan as $p) {
        $setFee->execute([$p['fee'], $p['id']]);
        if ($p['join'] !== null) {
            $setJoin->execute([$p['join'] . ' 00:00:00', $p['id']]);
        }
    }
    $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (0, ?, 'Members', NOW())")
        ->execute(['Entrance fees set for ' . count($plan) . " members from the leaders' list"
                   . ($withJoin ? " ($withJoin join dates)" : '') . " (bin/import_entrance_fees.php, $source)."]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('nothing was changed: ' . $e->getMessage());
}
out('Done.');
