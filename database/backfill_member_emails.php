<?php
// database/backfill_member_emails.php
//
// One-off: give every existing member who has no email an auto-generated
// identity email (username@<domain>), written to both users.email and
// customers.email. Existing real emails are left untouched.
//
// Usage:
//     php database/backfill_member_emails.php [domain]
//
// If [domain] is omitted, the domain is resolved the same way the app resolves
// it (the `member_email_domain` override, else the last web-detected host). On a
// fresh server that has had no web traffic yet, pass the domain explicitly, e.g.
//     php database/backfill_member_emails.php vikundi.co.tz

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/member_identity.php';

$forced = vk_normalize_email_domain($argv[1] ?? '');
$domain = $forced !== '' ? $forced : vk_member_email_domain($pdo);

// Members (= users linked to a customers row) with no email yet.
$rows = $pdo->query("
    SELECT u.user_id, c.customer_id, u.username, u.first_name, u.last_name
    FROM users u
    JOIN customers c ON c.user_id = u.user_id
    WHERE (u.email IS NULL OR u.email = '')
")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $r) {
    $username = trim((string) $r['username']);
    if ($username === '') {
        // Defensive: a member with no username — build and persist one.
        $username = vk_unique_username($pdo, vk_build_username($r['first_name'], $r['last_name']));
        $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?")
            ->execute([$username, $r['user_id']]);
    }

    $email = vk_build_member_email($username, $domain);
    $pdo->prepare("UPDATE users SET email = ? WHERE user_id = ?")
        ->execute([$email, $r['user_id']]);
    $pdo->prepare("UPDATE customers SET email = ? WHERE customer_id = ?")
        ->execute([$email, $r['customer_id']]);
    $updated++;
}

fwrite(STDOUT, "Backfilled emails for {$updated} member(s) using domain '{$domain}'.\n");
