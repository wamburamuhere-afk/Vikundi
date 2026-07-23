<?php
/**
 * database/backfill_approved_expenses_paid.php
 * --------------------------------------------
 * One-time cutover for the cash-basis balance (PR 2 of the approved-vs-paid work).
 *
 * PR 1 added a 'paid' state to the three expense workflows but kept the balance
 * neutral. PR 2 flips getGroupFundBalance() to true cash basis — an expense only
 * leaves the balance once it is *paid*. Every expense that was already `approved`
 * at cutover is treated as money that has genuinely gone out, so we mark those
 * `paid` here. That makes the flip balance-neutral at the moment of deploy: the
 * same figures counted before still count after.
 *
 * Idempotent AND future-safe. migrate.php re-runs every script on every deploy,
 * so this must NEVER sweep up expenses approved *after* the cutover (that would
 * silently rob the treasurer of the paid step forever). It only touches rows
 * approved BEFORE the fixed cutover timestamp — after the first run those are all
 * `paid`, so re-runs are no-ops, and anything approved later goes through the
 * real "Mark as Paid" flow.
 *
 * paid_at / paid_by are backfilled from the approval record so history reads true
 * (COALESCE keeps any value already set, and falls back to created_at / now).
 */

// Cutover: everything approved before this instant is historical → auto-paid.
// Anything approved on/after it uses the normal treasurer paid step.
$cutover = '2026-07-24 00:00:00';

// table => approval-timestamp column (petty cash names it differently)
$targets = [
    'death_expenses'      => 'approved_at',
    'general_expenses'    => 'approved_at',
    'petty_cash_vouchers' => 'approval_date',
];

foreach ($targets as $table => $approvedCol) {
    try {
        $sql = "UPDATE `$table`
                   SET status  = 'paid',
                       paid_at = COALESCE(paid_at, `$approvedCol`, created_at, NOW()),
                       paid_by = COALESCE(paid_by, approved_by)
                 WHERE status = 'approved'
                   AND COALESCE(`$approvedCol`, created_at) < :cutover";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cutover' => $cutover]);
        $n = $stmt->rowCount();
        echo "  $table: marked $n approved expense(s) as paid (cutover < $cutover)\n";
    } catch (Throwable $e) {
        // Column/table absent on some environments — skip, don't fail the deploy.
        echo "  $table: skipped ({$e->getMessage()})\n";
    }
}
