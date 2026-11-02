<?php
/**
 * bin/wisewallet_go_live.php — turn the Wise Wallet demo group into the client's real,
 * empty group at the same address: wipe the sample members and their records, put in
 * the group's real details, and create its first Chairperson. Command line only.
 *
 *   Dry run (changes nothing; lists every table with data, what is wiped and what is kept):
 *     php bin/wisewallet_go_live.php --tenant=wisewallet --name="Wise-Wallet Savings" \
 *         --address="Arusha - Tanzania" --email=… --phone=255… \
 *         --chair-first=… --chair-last=… [--chair-phone=…] [--chair-email=…]
 *   Apply: the same with --commit. Take a backup first (bin/backup_all.php --only=wisewallet).
 *
 * Refuses any database whose group is not the demo seeder's "Wise Wallet M-KOBA"
 * (database/seed_wise_wallet_demo.php), so it cannot be pointed at a real group.
 * Keeps the setup: roles, permissions, templates, reference lists and system settings.
 * The new Chairperson's password is printed once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php';       // $pdo: --tenant=<group> on the shared server
require_once __DIR__ . '/../includes/provisioning.php'; // vk_prov_parse_args, vk_prov_secret, vk_prov_username

const WWGL_DEMO_GROUP = 'Wise Wallet M-KOBA';

/** Member, money and activity records: all sample data in the demo, wiped. */
const WWGL_WIPE = [
    'users', 'customers', 'customer_attachments', 'customer_additional_attachments', 'customer_contacts',
    'contributions', 'mkoba_statement_rows', 'import_logs', 'member_import_log',
    'fines', 'general_expenses', 'death_expenses', 'deleted_expenses', 'petty_cash_vouchers', 'member_payouts',
    'budgets', 'budget_items',
    'vicoba_loan_types', 'vicoba_loans', 'vicoba_loan_guarantors', 'vicoba_loan_installments', 'vicoba_loan_payments',
    'vicoba_dividend_runs', 'vicoba_dividend_lines', 'guarantors',
    'meetings', 'meeting_attendance', 'leadership_applications',
    'votes', 'vote_options', 'vote_ballots', 'vote_eligibility', 'vote_participation',
    'documents', 'authored_documents', 'document_signatories', 'document_downloads',
    'workflow_documents', 'workflow_signatures', 'signature_documents', 'signature_history', 'user_signatures',
    'notifications', 'messages', 'message_recipients', 'sms_logs', 'payment_reminders',
    'activity_logs', 'access_log', 'api_refresh_tokens', 'ai_usage_log',
    'journal_entries', 'journal_entry_items', 'transactions', 'bank_transactions',
];

/** The demo's own rules: removed, so the client enters theirs (nothing is invented for them). */
const WWGL_DEMO_SETTINGS = [
    'group_logo', 'group_registration_number', 'group_founded_date', 'contribution_start_date',
    'max_members', 'monthly_contribution', 'contribution_rates', 'entrance_fee', 'deadline_day',
    'share_price', 'shares_from_monthly', 'social_fund_amount', 'membership_deposit_amount',
    'dividend_distribute_pct', 'group_email', 'group_phone', 'group_physical_address', 'group_postal_address',
    'demo_seed',
];

$args = vk_prov_parse_args($argv);
$commit = isset($args['commit']);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "Error: $s\n"); exit(1); }

$name    = trim((string) ($args['name'] ?? ''));
$address = trim((string) ($args['address'] ?? ''));
$email   = trim((string) ($args['email'] ?? ''));
$phone   = preg_replace('/\D/', '', (string) ($args['phone'] ?? ''));
$first   = trim((string) ($args['chair-first'] ?? ''));
$last    = trim((string) ($args['chair-last'] ?? ''));
$cPhone  = preg_replace('/\D/', '', (string) ($args['chair-phone'] ?? $phone));
$cEmail  = trim((string) ($args['chair-email'] ?? $email));
if ($name === '' || $first === '' || $last === '' || $cPhone === '') {
    fail('--name, --chair-first, --chair-last and a phone (--phone or --chair-phone) are required.');
}
foreach (['--email' => $email, '--chair-email' => $cEmail] as $flag => $e) {
    if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
        fail("$flag is not a valid e-mail address.");
    }
}
if ($cEmail === '') {
    fail('the Chairperson needs an e-mail (--chair-email or --email): the app finds a member by it.');
}

// ── only the demo seeder's group ────────────────────────────────────────────
$group = (string) ($pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_name'")->fetchColumn() ?: '');
if ($group !== WWGL_DEMO_GROUP) {
    fail("this group is \"$group\", not the demo \"" . WWGL_DEMO_GROUP . "\". Nothing was changed.");
}

$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")
              ->fetchAll(PDO::FETCH_COLUMN);
$count = static fn(string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
$wipe = [];
$kept = [];
foreach ($tables as $t) {
    $n = $count($t);
    if ($n === 0) {
        continue;
    }
    if (in_array($t, WWGL_WIPE, true)) {
        $wipe[$t] = $n;
    } else {
        $kept[$t] = $n;
    }
}

// Anyone who is not one of the demo's invented people (e.g. a login made during the meeting).
$others = $pdo->query("SELECT username, email FROM users WHERE email IS NULL OR email NOT LIKE '%.example' ORDER BY username")
              ->fetchAll(PDO::FETCH_ASSOC);

out(($commit ? 'Applying' : 'Dry run') . " for \"$group\" -> \"$name\"");
out('Wiped (sample data):');
foreach ($wipe as $t => $n) {
    out(sprintf('  %-32s %6d rows', $t, $n));
}
out('Kept (setup):');
foreach ($kept as $t => $n) {
    out(sprintf('  %-32s %6d rows', $t, $n));
}
out('Logins that are not sample people (also wiped): ' . ($others ? '' : 'none'));
foreach ($others as $o) {
    out("  - {$o['username']} <" . ($o['email'] ?? '') . '>');
}
$username = (string) ($args['chair-username'] ?? vk_prov_username($first, $last));
out("New group details: $name" . ($address !== '' ? ", $address" : '') . ($email !== '' ? ", $email" : '') . ($phone !== '' ? ", $phone" : ''));
out("New Chairperson: $first $last, username $username, $cEmail, $cPhone");

if (!$commit) {
    out('Nothing changed. Take a backup, then add --commit.');
    exit(0);
}

$roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE role_name = 'Chairperson' LIMIT 1")->fetchColumn();
if ($roleId === 0) {
    fail('the Chairperson role is missing. Nothing was changed.');
}

// TRUNCATE cannot be rolled back, so the checks above come first and the steps below
// cannot fail half-way on bad input.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (array_keys($wipe) as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
out('Wiped ' . count($wipe) . ' tables.');

$del = $pdo->prepare('DELETE FROM group_settings WHERE setting_key = ?');
foreach (WWGL_DEMO_SETTINGS as $k) {
    $del->execute([$k]);
}
$set = $pdo->prepare('REPLACE INTO group_settings (setting_key, setting_value) VALUES (?, ?)');
foreach (['group_name' => $name, 'group_physical_address' => $address, 'group_email' => $email, 'group_phone' => $phone] as $k => $v) {
    if ($v !== '') {
        $set->execute([$k, $v]);
    }
}
out('Group details set; the demo rules removed (the group enters its own in Group Settings).');

// The first Chairperson, exactly as bin/provision_tenant.php --create makes one.
$password = vk_prov_secret(9);
$pdo->prepare(
    "INSERT INTO users (username, password, email, phone, role, user_role, role_id, status, is_active, is_admin,
                        first_name, last_name, language, preferred_language, created_at)
     VALUES (?, ?, ?, ?, 'Chairperson', 'Chairperson', ?, 'active', 1, 0, ?, ?, 'en', 'en', NOW())"
)->execute([$username, password_hash($password, PASSWORD_DEFAULT), $cEmail, $cPhone, $roleId, $first, $last]);
$uid = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO customers (user_id, first_name, last_name, customer_code, customer_name, customer_type,
                            phone, mobile, email, status, created_at, created_by)
     VALUES (?, ?, ?, 'M001', ?, 'individual', ?, ?, ?, 'active', NOW(), ?)"
)->execute([$uid, $first, $last, "$first $last", $cPhone, $cPhone, $cEmail, $uid]);
$pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (0, ?, 'System', NOW())")
    ->execute(["Group started for real: demo data removed, first Chairperson $username created (bin/wisewallet_go_live.php)."]);

out("Done. Chairperson: username $username, password $password");
out('(shown once; give it to the Chairperson and ask them to change it under My Settings)');
