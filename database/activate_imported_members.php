<?php
/**
 * database/activate_imported_members.php
 * --------------------------------------
 * One-off: promote the imported M-Koba members from "dormant" to "active".
 *
 * WHY: the M-Koba onboarding created ~320 real, contributing members but left
 * them non-active, so they show as "Dormant / Contribution Delay". That makes the
 * member counts disagree across pages (Dashboard 327 vs Members 7 vs /users 8) and
 * hides them from the member list, /users and the savings reports.
 *
 * "Active" is driven by TWO status columns (verified against the code):
 *   - users.status      -> dashboard/analysis "active" counts, dormant list
 *   - customers.status  -> member list, /users (ajax/get_users), vicoba savings
 * so both are set. Deceased members (customers.is_deceased = 1) are ALWAYS skipped.
 *
 * SAFE BY DEFAULT — DRY RUN unless you explicitly pass "commit":
 *     php database/activate_imported_members.php            # report only, ZERO writes
 *     php database/activate_imported_members.php commit     # apply the change
 *
 * The commit path is CLI-only (never web-triggerable) and is idempotent — running
 * it again after everyone is active changes 0 rows. It is NOT wired into
 * migrate.php on purpose, so it never runs automatically on deploy.
 */

require_once __DIR__ . '/../includes/config.php'; // $pdo

$commit = (($argv[1] ?? '') === 'commit');

if ($commit && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Refusing to apply changes over the web — run this from the command line.\n");
}

// Who counts as "an imported dormant member to activate" — this mirrors EXACTLY
// the condition the Dormant Members page uses, so we activate precisely who that
// page shows, no more:
//   - a Member (never Admin)
//   - NOT deceased
//   - status is empty/null or anything outside the intentional set. 'pending' is
//     deliberately EXCLUDED — those are applications awaiting approval, a
//     different workflow (on production the Member Approvals page shows 0 pending).
$usersWhere = "u.user_role <> 'Admin'
    AND COALESCE(c.is_deceased, 0) = 0
    AND (u.status IS NULL OR u.status = '' OR u.status NOT IN ('active','pending','suspended','deleted'))";
// The customer records to activate are derived from the SAME matched users (by
// user_id), not from a guessed customer status — robust to however the import left
// customers.status, and it keeps the two tables in lock-step.
$custCountSql = "SELECT COUNT(*) FROM customers c JOIN users u ON u.user_id = c.user_id
                  WHERE $usersWhere AND c.status <> 'active'";

$rule = static function (string $s = '') { echo $s . "\n" . str_repeat('-', 64) . "\n"; };

echo "\n=== Activate imported M-Koba members — " . ($commit ? "COMMIT (applying)" : "DRY RUN (no writes)") . " ===\n\n";

// ── 1. Current distribution, so we can see the before-picture ────────────────
$rule("Current users.status (excluding Admin)");
foreach ($pdo->query("SELECT COALESCE(NULLIF(u.status,''),'(empty/null)') AS status, COUNT(*) c
                        FROM users u WHERE u.user_role <> 'Admin' GROUP BY u.status ORDER BY c DESC")
              ->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-14s %5d\n", $r['status'], $r['c']);
}
echo "\n";
$rule("Current customers.status");
foreach ($pdo->query("SELECT COALESCE(NULLIF(status,''),'(empty/null)') AS status, COUNT(*) c,
                             SUM(COALESCE(is_deceased,0)) AS deceased
                        FROM customers GROUP BY status ORDER BY c DESC")
              ->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-14s %5d   (deceased: %d)\n", $r['status'], $r['c'], $r['deceased']);
}
echo "\n";

// ── 2. Exactly what WOULD change ─────────────────────────────────────────────
$usersToChange = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u LEFT JOIN customers c ON c.user_id = u.user_id WHERE $usersWhere"
)->fetchColumn();
$custToChange = (int) $pdo->query($custCountSql)->fetchColumn();
$deceasedSkipped = (int) $pdo->query(
    "SELECT COUNT(*) FROM customers WHERE COALESCE(is_deceased,0) = 1 AND status <> 'active'"
)->fetchColumn();

$rule("Impact");
printf("  users rows to set status='active'      : %d\n", $usersToChange);
printf("  customers rows to set status='active'  : %d\n", $custToChange);
printf("  deceased members SKIPPED (kept dormant): %d\n", $deceasedSkipped);
echo "\n";

// ── 3. A sample of who, so the names can be eyeballed ────────────────────────
$rule("Sample of members that would be activated (first 12)");
$sample = $pdo->query(
    "SELECT u.user_id, u.username,
            TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS name,
            COALESCE(NULLIF(u.status,''),'(empty)') AS ustatus,
            COALESCE(NULLIF(c.status,''),'(none)')  AS cstatus
       FROM users u LEFT JOIN customers c ON c.user_id = u.user_id
      WHERE $usersWhere
      ORDER BY u.user_id LIMIT 12"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($sample as $s) {
    printf("  #%-5s %-22s user.status=%-9s cust.status=%s\n",
        $s['user_id'], $s['name'] !== '' ? $s['name'] : $s['username'], $s['ustatus'], $s['cstatus']);
}
echo "\n";

// ── 4. Apply (only when explicitly asked) ────────────────────────────────────
if (!$commit) {
    $rule("DRY RUN — nothing was changed");
    echo "  Review the numbers above, then re-run with:\n";
    echo "      php database/activate_imported_members.php commit\n\n";
    exit(0);
}

$rule("Applying…");
// Capture the target user_ids FIRST — so the customers update isn't affected by
// the users update having already flipped status to 'active'.
$ids = $pdo->query(
    "SELECT u.user_id FROM users u LEFT JOIN customers c ON c.user_id = u.user_id WHERE $usersWhere"
)->fetchAll(PDO::FETCH_COLUMN);

$uDone = $cDone = 0;
if (!empty($ids)) {
    $in = implode(',', array_map('intval', $ids));
    $uDone = $pdo->exec("UPDATE users SET status = 'active' WHERE user_id IN ($in)");
    // Only the linked, non-deceased customers of those same members.
    $cDone = $pdo->exec("UPDATE customers SET status = 'active', is_active = 1
                          WHERE user_id IN ($in) AND COALESCE(is_deceased,0) = 0 AND status <> 'active'");
}

printf("  users updated     : %d\n", $uDone);
printf("  customers updated : %d\n", $cDone);
echo "\n";

// ── 5. After-picture, so we can confirm it lined up ──────────────────────────
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE user_role <> 'Admin' AND status='active'")->fetchColumn();
$activeCust  = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status='active'")->fetchColumn();
$rule("Done — after");
printf("  active members (users)     : %d\n", $activeUsers);
printf("  active members (customers) : %d\n", $activeCust);
echo "\n  Re-running this script now is a no-op (idempotent).\n\n";
exit(0);
