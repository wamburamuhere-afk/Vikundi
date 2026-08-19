<?php
/**
 * database/demo_seed_guard.php
 * ----------------------------
 * Refuses to let a demo seeder run against a database that holds real member
 * records.
 *
 * WHY THIS EXISTS. The demo site and production live in two directories on the
 * same server, running identical code. The seeders support --fresh, which
 * TRUNCATEs the contribution, loan, fine and member tables. One mistaken `cd`
 * and a real savings group loses its financial history — the group's own
 * ledger, which for a VICOBA is the only record that money ever changed hands.
 * The seeders' original guard ("customers already has rows") is bypassed by
 * exactly the flag that does the damage.
 *
 * Two independent signals, because a name check alone is one typo from useless:
 *
 *   1. The database name, matched against known production databases.
 *   2. The group's own name in group_settings, which travels with the DATA
 *      rather than the connection. A restored production dump under a new
 *      database name is still production, and this is what catches it.
 *
 * Neither of those two is overridable. The third check — "this database name
 * does not look like a demo" — is a softer backstop and can be overridden with
 * --allow-nondemo-db, which is what you use on a local dev box.
 */

/** Databases known to hold live member data. Exact match, lowercased. */
const VK_DEMO_SEED_PRODUCTION_DATABASES = [
    'bjptechn_vikundi',
];

/**
 * Group names that identify a real client. Matched case-insensitively as a
 * substring of group_settings.group_name, so "UKUU Msakuzi VICOBA" is caught
 * just as well as "UKUU MSAKUZI".
 */
const VK_DEMO_SEED_PRODUCTION_GROUPS = [
    'ukuu',
    'msakuzi',
];

/** Database names that read as non-production. */
const VK_DEMO_SEED_SAFE_NAME_PATTERN = '/(demo|test|staging|sandbox|scratch)/i';

/**
 * The whole decision, as a pure function so it can be tested without a
 * database. Returns null when seeding is allowed, or the reason to refuse.
 *
 * @param string $dbName       Connected database name (may be empty).
 * @param string $groupName    group_settings.group_name (may be empty).
 * @param bool   $allowNonDemo Caller passed --allow-nondemo-db.
 */
function vk_demo_seed_block_reason(string $dbName, string $groupName, bool $allowNonDemo): ?string
{
    $db = strtolower(trim($dbName));

    if ($db === '') {
        return 'No database is selected on this connection, so the target cannot be verified.';
    }

    if (in_array($db, VK_DEMO_SEED_PRODUCTION_DATABASES, true)) {
        return "`{$dbName}` is a PRODUCTION database. This seeder will not touch it under any flag.";
    }

    $group = strtolower(trim($groupName));
    if ($group !== '') {
        foreach (VK_DEMO_SEED_PRODUCTION_GROUPS as $needle) {
            if (str_contains($group, $needle)) {
                return "This database belongs to a real group (group_name = \"{$groupName}\"). "
                     . 'Refusing under any flag — a renamed or restored copy of production is still production.';
            }
        }
    }

    // Softer backstop, overridable: the name carries no demo marker.
    if (!$allowNonDemo && !preg_match(VK_DEMO_SEED_SAFE_NAME_PATTERN, $db)) {
        return "`{$dbName}` does not look like a demo database (no demo/test/staging/sandbox in the name).\n"
             . '  If you are sure, re-run with --allow-nondemo-db.';
    }

    return null;
}

/**
 * CLI wrapper: gather both signals from the live connection and stop the
 * process if the target is not safe to seed.
 */
function vk_demo_seed_guard(PDO $pdo, array $argv): void
{
    $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');

    $groupName = '';
    try {
        $groupName = (string) ($pdo
            ->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_name' LIMIT 1")
            ->fetchColumn() ?: '');
    } catch (Throwable $e) {
        // A database with no group_settings table cannot be a live Vikundi
        // install, so there is nothing to protect. Fall through on the DB-name
        // checks alone.
    }

    $reason = vk_demo_seed_block_reason($dbName, $groupName, in_array('--allow-nondemo-db', $argv, true));

    if ($reason !== null) {
        fwrite(STDERR, "\nREFUSING TO SEED.\n  " . $reason . "\n\n");
        exit(1);
    }

    echo "Target database: {$dbName}" . ($groupName !== '' ? " (group: {$groupName})" : '') . "\n";
}
