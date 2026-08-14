<?php
/**
 * database/move_fine_contributions_to_fines.php
 * ---------------------------------------------
 * A fine was recordable in two places that never agreed.
 *
 * The Transactions form offered "Fine" in its contribution-type list, which wrote a
 * `contributions` row with contribution_type='fine'. My Fines, the fines register
 * and the fines report all read the `fines` table, so that money was counted as
 * group income and the fine itself was invisible. Meanwhile the only writer of the
 * `fines` table was the meeting-absence sweep.
 *
 * This moves every such row into `fines`, where it belongs, and the form no longer
 * offers the option.
 *
 * TOTAL-PRESERVING BY CONSTRUCTION. includes/finance.php sums group income as
 *   (all approved contributions, no type filter) + (fines WHERE status='paid')
 * so a fine-contribution the books already counted must arrive as 'paid', or the
 * group's income would silently drop by that amount. Rows the books did NOT count
 * (pending / reviewed) arrive as 'pending', which is likewise counted nowhere.
 * Cancelled rows are left untouched: they are already excluded everywhere, and
 * moving a cancelled figure into a live fines register would invent a debt.
 *
 * Idempotent: it deletes what it moves, so a second run finds nothing.
 *
 * Run manually:  php database/move_fine_contributions_to_fines.php
 */

require_once __DIR__ . '/../includes/config.php';

$rows = $pdo->query("
    SELECT contribution_id, member_id, amount, description, contribution_date, status
      FROM contributions
     WHERE contribution_type = 'fine'
       AND status <> 'cancelled'
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "  No fine-typed contributions to move.\n";
    return;
}

/** What the books counted before we touch anything. */
$incomeBefore = (float) $pdo->query("
    SELECT (SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status IN ('approved','confirmed',''))
         + (SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'paid')
")->fetchColumn();

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO fines (customer_id, amount, reason, status, created_at) VALUES (?,?,?,?,?)");
    $del = $pdo->prepare("DELETE FROM contributions WHERE contribution_id = ?");
    $moved = 0;

    foreach ($rows as $r) {
        // Counted by the books already => 'paid'. Not yet counted => 'pending'.
        $countedAsIncome = in_array($r['status'], ['approved', 'confirmed', ''], true);
        $fineStatus = $countedAsIncome ? 'paid' : 'pending';

        $reason = trim((string) $r['description']);
        if ($reason === '') {
            $reason = 'Fine recorded on the Transactions form (migrated)';
        }

        $ins->execute([
            (int) $r['member_id'],
            (float) $r['amount'],
            $reason,
            $fineStatus,
            $r['contribution_date'] ?: date('Y-m-d H:i:s'),
        ]);
        $del->execute([(int) $r['contribution_id']]);
        $moved++;
    }

    $incomeAfter = (float) $pdo->query("
        SELECT (SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status IN ('approved','confirmed',''))
             + (SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = 'paid')
    ")->fetchColumn();

    // If the books moved, something is wrong with the mapping above. Do not ship a
    // silent change to the group's income; roll back and say so.
    if (abs($incomeAfter - $incomeBefore) > 0.01) {
        $pdo->rollBack();
        echo "  ABORTED: group income would change from "
           . number_format($incomeBefore, 2) . " to " . number_format($incomeAfter, 2) . ".\n";
        return;
    }

    $pdo->commit();
    echo "  Moved $moved fine-typed contribution(s) into the fines table; income unchanged at "
       . number_format($incomeAfter, 2) . ".\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "  ERROR: " . $e->getMessage() . "\n";
}
