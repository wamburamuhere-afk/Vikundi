<?php
/**
 * bin/import_members.php — load a group's member list from a prepared CSV
 * (format: includes/member_list_import.php). Command line only.
 *
 *   Dry run (changes nothing, prints what it would do):
 *     php bin/import_members.php --tenant=ukuu --file=/path/members.import.csv --source=ukuu-2026-08
 *   Import:
 *     php bin/import_members.php --tenant=ukuu --file=... --source=ukuu-2026-08 --commit
 *
 * Every member becomes a login (users) plus a member record (customers), as the
 * Add Member form does. Leaders (role Chairperson / Secretary / Treasurer) get a
 * random password printed once at the end. Everyone else gets a random password
 * nobody is shown: they cannot sign in until a leader resets it.
 *
 * Safe to run again: each row is logged by --source + source_ref in
 * member_import_log, and logged rows are skipped. A person who already has a
 * login (same phone, first and last name, e.g. the Chairperson the panel
 * created) is not created twice: their member record is filled in instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php'; // $pdo: --tenant=<group> on the shared server
require_once __DIR__ . '/../includes/member_list_import.php';
require_once __DIR__ . '/../includes/member_identity.php';
require_once __DIR__ . '/../includes/provisioning.php'; // vk_prov_parse_args, vk_prov_secret

$args = vk_prov_parse_args($argv);
$commit = isset($args['commit']);
$file = (string) ($args['file'] ?? '');
$source = (string) ($args['source'] ?? '');

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "Error: $s\n"); exit(1); }

if (!is_file($file) || !is_readable($file)) {
    fail('--file must be a readable CSV.');
}
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $source)) {
    fail('--source is required: a label for this list, e.g. ukuu-2026-08 (3-60 letters, digits, dot, dash).');
}

// ── read and check the whole file before touching the database ──────────────
$fh = fopen($file, 'r');
$header = fgetcsv($fh, 0, ',', '"', '');
if (!$header || ($why = vk_import_check_header($header))) {
    fail($why ?? 'The CSV is empty.');
}
$records = [];
$bad = [];
$line = 1;
while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $line++;
    if ($cells === [null] || implode('', $cells) === '') {
        continue;
    }
    [$r, $errors] = vk_import_row($header, $cells);
    if ($errors) {
        $bad[] = "line $line: " . implode('; ', $errors);
    }
    $records[] = $r;
}
fclose($fh);
$bad = array_merge($bad, vk_import_duplicates($records));
if ($bad) {
    out('The file has problems; nothing was imported:');
    foreach ($bad as $b) {
        out('  - ' . $b);
    }
    exit(1);
}

// ── what is already in this group ───────────────────────────────────────────
$roleIds = [];
foreach (vk_import_roles() as $role) {
    $st = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
    $st->execute([$role]);
    $roleIds[$role] = (int) $st->fetchColumn();
    if ($roleIds[$role] === 0) {
        fail("the role '$role' does not exist in this group. Run the migrations first.");
    }
}
$hasLog = (bool) $pdo->query("SHOW TABLES LIKE 'member_import_log'")->fetchColumn();
$done = [];
if ($hasLog) {
    $st = $pdo->prepare('SELECT source_ref FROM member_import_log WHERE source = ?');
    $st->execute([$source]);
    $done = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}
$existing = $pdo->query("SELECT user_id, first_name, last_name, phone, username, user_role FROM users WHERE status <> 'deleted'")->fetchAll();
$numbersInUse = array_flip(array_filter($pdo->query('SELECT registration_number FROM customers WHERE registration_number IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));

// Field sizes, from this group's own tables, so a value that would not fit is
// reported now instead of failing halfway through the import.
$limitOf = static function (string $table, string $column) use ($pdo): ?int {
    $st = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    $n = $st->fetchColumn();
    return $n === false || $n === null ? null : (int) $n;
};
$limits = [];
foreach (['first_name', 'middle_name', 'last_name', 'phone'] as $c) {
    $limits[$c] = min(array_filter([$limitOf('users', $c), $limitOf('customers', $c)]) ?: [PHP_INT_MAX]);
}
$limits['member_number'] = $limitOf('customers', 'registration_number') ?? PHP_INT_MAX;
foreach (['father_name', 'mother_name'] as $c) {
    $limits[$c] = $limitOf('customers', $c) ?? PHP_INT_MAX;
}
foreach (['spouse', 'father', 'mother'] as $who) {
    foreach (['first', 'middle', 'last'] as $part) {
        $limits["{$who}_{$part}_name"] = $limitOf('customers', "{$who}_{$part}_name") ?? PHP_INT_MAX;
    }
}
$tooLong = [];
foreach ($records as $r) {
    foreach (vk_import_too_long($r, $limits) as $p) {
        $tooLong[] = "{$r['source_ref']} {$r['first_name']} {$r['last_name']}: $p";
    }
}
if ($tooLong) {
    out('Some values do not fit the database; nothing was imported. Shorten them in the CSV:');
    foreach ($tooLong as $t) {
        out('  - ' . $t);
    }
    exit(1);
}

// ── decide what happens to each row ─────────────────────────────────────────
$plan = ['skip' => [], 'link' => [], 'create' => []];
$notes = [];
foreach ($records as $r) {
    if (isset($done[$r['source_ref']])) {
        $plan['skip'][] = $r;
        continue;
    }
    $match = null;
    foreach ($existing as $u) {
        if (vk_import_same_person($r, $u)) {
            $match = $u;
            break;
        }
    }
    if ($match) {
        $r['_user'] = $match;
        $plan['link'][] = $r;
    } else {
        if ($r['member_number'] !== '' && isset($numbersInUse[$r['member_number']])) {
            $notes[] = "{$r['source_ref']} {$r['first_name']} {$r['last_name']}: member number {$r['member_number']} is already used in this group; imported without it";
            $r['member_number'] = '';
        }
        $plan['create'][] = $r;
    }
    if ($r['note'] !== '') {
        $notes[] = "{$r['source_ref']} {$r['first_name']} {$r['last_name']}: {$r['note']}";
    }
}
$domain = (string) ($args['email-domain'] ?? '') ?: vk_member_email_domain($pdo);
if ($domain === 'localhost' || $domain === '') {
    fail("this group has no member email domain yet. Add --email-domain=<group>.<base domain>, e.g. --email-domain=ukuu.vikundi.bjptechnologies.co.tz");
}
$leaders = array_values(array_filter(array_merge($plan['create'], $plan['link']), static fn($r) => $r['role'] !== 'Member'));

out(($commit ? 'IMPORT' : 'DRY RUN (nothing is changed; add --commit to import)') . " — source '$source', " . count($records) . ' rows');
out('  create:         ' . count($plan['create']) . ' members');
out('  fill in:        ' . count($plan['link']) . ' already have a login (' . implode(', ', array_map(static fn($r) => "{$r['first_name']} {$r['last_name']} ({$r['_user']['username']})", $plan['link'])) . ')');
out('  already done:   ' . count($plan['skip']));
out('  with a number:  ' . count(array_filter($plan['create'], static fn($r) => $r['member_number'] !== '')) . ' of ' . count($plan['create']) . ' new');
out('  leaders:        ' . implode(', ', array_map(static fn($r) => "{$r['first_name']} {$r['last_name']} = {$r['role']}" . ($r['title'] ? " ({$r['title']})" : ''), $leaders)));
out('  member emails:  <username>@' . $domain);
out('  rows to check later: ' . count($notes));
foreach ($notes as $n) {
    out('    - ' . $n);
}
if (!$commit) {
    exit(0);
}

// ── import ──────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS member_import_log (
    source       VARCHAR(60)  NOT NULL,
    source_ref   VARCHAR(40)  NOT NULL,
    user_id      INT          NOT NULL,
    customer_id  INT          NULL,
    action       VARCHAR(10)  NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, source_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$family = static function (array $r): array {
    [$sf, $sm, $sl] = vk_import_split_name($r['spouse']);
    [$ff, $fm, $fl] = vk_import_split_name($r['father']);
    [$mf, $mm, $ml] = vk_import_split_name($r['mother']);
    return [
        'gender' => $r['gender'] !== '' ? $r['gender'] : null,
        'registration_number' => $r['member_number'] !== '' ? $r['member_number'] : null,
        'marital_status' => $r['spouse'] !== '' ? 'Married' : 'Single',
        'spouse_first_name' => $sf, 'spouse_middle_name' => $sm, 'spouse_last_name' => $sl,
        'father_name' => $r['father'], 'father_first_name' => $ff, 'father_middle_name' => $fm, 'father_last_name' => $fl,
        'mother_name' => $r['mother'], 'mother_first_name' => $mf, 'mother_middle_name' => $mm, 'mother_last_name' => $ml,
        'children_data' => vk_import_children_json($r['children']),
    ];
};
$logRow = $pdo->prepare('INSERT INTO member_import_log (source, source_ref, user_id, customer_id, action) VALUES (?, ?, ?, ?, ?)');
$credentials = [];
$failed = 0;

foreach ($plan['link'] as $r) {
    $uid = (int) $r['_user']['user_id'];
    $f = $family($r);
    $st = $pdo->prepare('SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $cid = (int) $st->fetchColumn();
    if ($cid === 0) {
        out("  ! {$r['source_ref']} {$r['first_name']} {$r['last_name']}: has a login but no member record; skipped");
        $failed++;
        continue;
    }
    $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($f)));
    $pdo->prepare("UPDATE customers SET $sets, middle_name = COALESCE(NULLIF(middle_name, ''), ?) WHERE customer_id = ?")
        ->execute(array_merge(array_values($f), [$r['middle_name'], $cid]));
    $logRow->execute([$source, $r['source_ref'], $uid, $cid, 'filled']);
    out("  filled in {$r['first_name']} {$r['last_name']} ({$r['_user']['username']})");
}

foreach ($plan['create'] as $r) {
    $username = vk_unique_username($pdo, vk_build_username($r['first_name'], $r['last_name']));
    $email = vk_build_member_email($username, $domain);
    $leader = $r['role'] !== 'Member';
    $password = $leader ? vk_prov_secret(9) : vk_prov_secret(32);
    $uid = 0;
    try {
        // users is MyISAM (no rollback): if anything after this fails, the row is deleted again.
        $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, phone, username, password, role, user_role, role_id,
                                          status, is_active, is_admin, language, preferred_language, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, 0, 'en', 'en', NOW(), NOW())")
            ->execute([$r['first_name'], $r['middle_name'], $r['last_name'], $email, $r['phone'] ?: null, $username,
                       password_hash($password, PASSWORD_DEFAULT), $r['role'], $r['role'], $roleIds[$r['role']]]);
        $uid = (int) $pdo->lastInsertId();

        $pdo->beginTransaction();
        $f = $family($r);
        $cols = array_merge(['user_id', 'first_name', 'middle_name', 'last_name', 'customer_name', 'email', 'phone', 'mobile', 'customer_type', 'status'], array_keys($f));
        $vals = array_merge([$uid, $r['first_name'], $r['middle_name'], $r['last_name'],
                             trim("{$r['first_name']} {$r['middle_name']} {$r['last_name']}"), $email, $r['phone'] ?: null, $r['phone'] ?: null, 'individual', 'active'], array_values($f));
        $pdo->prepare('INSERT INTO customers (' . implode(', ', $cols) . ', created_at) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW())')
            ->execute($vals);
        $cid = (int) $pdo->lastInsertId();
        $logRow->execute([$source, $r['source_ref'], $uid, $cid, 'created']);
        $pdo->commit();
        if ($leader) {
            $credentials[] = [$r['first_name'] . ' ' . $r['last_name'], $r['title'] ?: $r['role'], $username, $password];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($uid > 0) {
            $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$uid]);
        }
        $failed++;
        out("  ! {$r['source_ref']} {$r['first_name']} {$r['last_name']}: " . $e->getMessage());
    }
}

out('');
out('Done: ' . (count($plan['create']) - $failed + count($plan['link'])) . ' imported or filled in, ' . $failed . ' failed' . ($failed ? ' (run the same command again after fixing; finished rows are skipped)' : '') . '.');
if ($credentials) {
    out('');
    out('Leader logins (shown once; give each leader their own and ask them to change it under My Settings):');
    foreach ($credentials as [$name, $title, $user, $pw]) {
        out(sprintf('  %-22s %-18s username %-16s password %s', $name, $title, $user, $pw));
    }
}
exit($failed ? 1 : 0);
