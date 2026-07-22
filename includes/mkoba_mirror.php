<?php
/**
 * includes/mkoba_mirror.php
 * -------------------------
 * The one DB routine that (re)builds the reconciliation mirror for a single
 * M-Koba statement (identified by $batch): store EVERY row exactly as received,
 * tagged imported / excluded / missing and linked to its contribution by
 * receipt. Idempotent — replaces the batch on each run.
 *
 * Shared by the web importer (actions/import_contributions.php) and the one-off
 * CLI (database/import_mkoba_oneoff.php), so both keep the reconciliation view
 * in step. Row classification comes from the pure mkoba_mirror_row() in
 * includes/transaction_import.php.
 */

require_once __DIR__ . '/transaction_import.php';

if (!function_exists('mkoba_populate_mirror')) {
    /**
     * @param array $rows  raw statement rows (assoc keyed by lowercased header)
     * @return array{imported:int,excluded:int,missing:int}
     */
    function mkoba_populate_mirror(PDO $pdo, array $rows, string $batch): array
    {
        $pdo->prepare("DELETE FROM mkoba_statement_rows WHERE batch = ?")->execute([$batch]);
        $byReceipt = $pdo->prepare("SELECT contribution_id FROM contributions WHERE mkoba_receipt = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO mkoba_statement_rows
            (batch, sno, trans_id, receipt, trans_date, member_name, member_id, source, destination, amount, trans_type, outcome, reason, contribution_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stats = ['imported' => 0, 'excluded' => 0, 'missing' => 0];
        foreach ($rows as $a) {
            $m = mkoba_mirror_row($a);
            $cid = null;
            if ($m['is_contribution']) {
                if ($m['receipt'] !== '') { $byReceipt->execute([$m['receipt']]); $cid = $byReceipt->fetchColumn() ?: null; }
                $outcome = $cid ? 'imported' : 'missing';
                $reason  = $cid ? '' : 'Contribution row not found in the ledger';
            } else {
                $outcome = 'excluded';
                $reason  = $m['reason'];
            }
            $stats[$outcome]++;
            $ins->execute([
                $batch, $m['sno'] ?: null, $m['trans_id'] ?: null, $m['receipt'] ?: null, $m['trans_date'],
                $m['member_name'] ?: null, $m['member_id'] ?: null, $m['source'] ?: null, $m['destination'] ?: null,
                $m['amount'], $m['trans_type'] ?: null, $outcome, $reason ?: null, $cid,
            ]);
        }
        return $stats;
    }
}
