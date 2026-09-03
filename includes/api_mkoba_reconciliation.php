<?php
/**
 * includes/api_mkoba_reconciliation.php — shared queries for the M-Koba
 * Reconciliation module (Module 8): group-wide statement tie-out and the
 * per-member ("my") mirror.
 *
 * Mirrors app/constant/accounts/mkoba_reconciliation.php (group) and
 * app/constant/reports/member_mkoba_reconciliation.php (per-member) — same
 * `mkoba_statement_rows` table, joined the same way to `contributions`, same
 * tie-out math. Neither web page had this math extracted into a shared file,
 * so it is reimplemented here rather than reused — same discipline as
 * includes/api_financial_ledger.php.
 */
require_once __DIR__ . '/api_auth.php';

if (!function_exists('vk_api_mkoba_is_leader')) {
    /** Same gate the group-wide web page uses: requireViewPermission('mkoba_reconciliation'). */
    function vk_api_mkoba_is_leader(array $auth): bool
    {
        return vk_api_is_admin((int) ($auth['role_id'] ?? 0))
            || vk_api_can($auth, 'view', 'mkoba_reconciliation');
    }
}

if (!function_exists('vk_api_mkoba_require_leader')) {
    function vk_api_mkoba_require_leader(array $auth): void
    {
        if (!vk_api_mkoba_is_leader($auth)) {
            vk_api_error(403, 'forbidden', 'Only leadership can view the group M-Koba reconciliation. '
                . 'Your own is at /api/v1/my/mkoba-reconciliation.');
        }
    }
}

if (!function_exists('vk_api_mkoba_may_override')) {
    /**
     * May the caller ask for a DIFFERENT member's own reconciliation via
     * ?member_id=? Mirrors member_mkoba_reconciliation.php's inline check
     * exactly: isAdmin() || canCreate('manage_contributions') — deliberately
     * NOT the `mkoba_reconciliation` key, which answers a different question
     * (can this account see the imported statement at all).
     */
    function vk_api_mkoba_may_override(array $auth): bool
    {
        return vk_api_is_admin((int) ($auth['role_id'] ?? 0))
            || vk_api_can($auth, 'create', 'manage_contributions');
    }
}

if (!function_exists('vk_api_mkoba_ref')) {
    /** A statement reference, or null when Excel mangled it into scientific notation. */
    function vk_api_mkoba_ref($value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || preg_match('/^\d+(\.\d+)?[eE][+\-]?\d+$/', $v)) {
            return null;
        }
        return $v;
    }
}

// ── group-wide ───────────────────────────────────────────────────────────────

if (!function_exists('vk_api_mkoba_batches')) {
    function vk_api_mkoba_batches(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT batch, COUNT(*) c FROM mkoba_statement_rows GROUP BY batch ORDER BY MAX(id) DESC')
                    ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(
            static fn(array $r): array => ['batch' => (string) $r['batch'], 'row_count' => (int) $r['c']],
            $rows
        );
    }
}

if (!function_exists('vk_api_mkoba_empty_summary')) {
    function vk_api_mkoba_empty_summary(): array
    {
        return [
            'all'           => ['count' => 0, 'amount' => 0.0],
            'imported'      => ['count' => 0, 'amount' => 0.0],
            'excluded'      => ['count' => 0, 'amount' => 0.0],
            'missing'       => ['count' => 0, 'amount' => 0.0],
            'ledger_amount' => 0.0,
            'reconciled'    => false,
        ];
    }
}

if (!function_exists('vk_api_mkoba_summary')) {
    /** Mirrors mkoba_reconciliation.php lines 32-53: the tie-out cards. */
    function vk_api_mkoba_summary(PDO $pdo, string $batch): array
    {
        $st = $pdo->prepare('SELECT outcome, COUNT(*) n, COALESCE(SUM(amount),0) amt
                               FROM mkoba_statement_rows WHERE batch = ? GROUP BY outcome');
        $st->execute([$batch]);

        $summary = vk_api_mkoba_empty_summary();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n   = (int) $r['n'];
            $amt = (float) $r['amt'];
            $summary['all']['count']  += $n;
            $summary['all']['amount'] += $amt;
            $key = in_array($r['outcome'], ['imported', 'missing'], true) ? $r['outcome'] : 'excluded';
            $summary[$key]['count']  += $n;
            $summary[$key]['amount'] += $amt;
        }

        // Books cross-check: total actually sitting in `contributions` for this statement.
        $lc = $pdo->prepare('SELECT COALESCE(SUM(c.amount),0) FROM contributions c
                             JOIN mkoba_statement_rows m ON m.contribution_id = c.contribution_id
                             WHERE m.batch = ?');
        $lc->execute([$batch]);
        $ledgerAmt = (float) $lc->fetchColumn();

        $summary['ledger_amount'] = $ledgerAmt;
        $summary['reconciled'] = ($summary['missing']['count'] === 0)
            && (round($ledgerAmt, 2) === round($summary['imported']['amount'], 2));

        return $summary;
    }
}

if (!function_exists('vk_api_mkoba_rows')) {
    /** @return array{0: array, 1: int} [rows, total] */
    function vk_api_mkoba_rows(PDO $pdo, string $batch, int $page, int $perPage): array
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM mkoba_statement_rows WHERE batch = ?');
        $st->execute([$batch]);
        $total = (int) $st->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $st = $pdo->prepare("SELECT * FROM mkoba_statement_rows WHERE batch = ?
                              ORDER BY (sno+0), id LIMIT {$perPage} OFFSET {$offset}");
        $st->execute([$batch]);

        return [$st->fetchAll(PDO::FETCH_ASSOC), $total];
    }
}

if (!function_exists('vk_api_mkoba_row')) {
    function vk_api_mkoba_row(array $r): array
    {
        return [
            'sno'         => (string) ($r['sno'] ?? ''),
            'receipt'     => vk_api_mkoba_ref($r['receipt'] ?? null),
            'trans_date'  => !empty($r['trans_date']) ? date('Y-m-d', strtotime((string) $r['trans_date'])) : null,
            'member_name' => (string) ($r['member_name'] ?? ''),
            'member_id'   => (string) ($r['member_id'] ?? ''),
            'amount'      => (float) $r['amount'],
            'trans_type'  => $r['trans_type'] !== null && $r['trans_type'] !== '' ? (string) $r['trans_type'] : null,
            'outcome'     => (string) $r['outcome'],
            'reason'      => $r['reason'] !== null && $r['reason'] !== '' ? (string) $r['reason'] : null,
        ];
    }
}

// ── per-member ("my") ────────────────────────────────────────────────────────

if (!function_exists('vk_api_mkoba_member_rows')) {
    /** Every imported M-Koba row that became one of this member's contributions. */
    function vk_api_mkoba_member_rows(PDO $pdo, int $memberId): array
    {
        $st = $pdo->prepare('
            SELECT m.trans_date, m.trans_id, m.receipt, m.trans_type, m.batch,
                   m.amount AS mkoba_amount,
                   c.contribution_id, c.amount AS book_amount, c.contribution_date,
                   c.status AS book_status
              FROM mkoba_statement_rows m
              JOIN contributions c ON c.contribution_id = m.contribution_id
             WHERE c.member_id = ?
             ORDER BY m.trans_date ASC, m.id ASC');
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vk_api_mkoba_member_summary')) {
    /** Pure — no DB. Mirrors member_mkoba_reconciliation.php lines 62-76. */
    function vk_api_mkoba_member_summary(array $rows): array
    {
        $mkobaTotal = 0.0;
        $bookTotal  = 0.0;
        $mismatches = 0;
        $pending    = 0;
        foreach ($rows as $r) {
            $mkobaTotal += (float) $r['mkoba_amount'];
            $bookTotal  += (float) $r['book_amount'];
            if ((float) $r['mkoba_amount'] !== (float) $r['book_amount']) {
                $mismatches++;
            }
            if (!in_array($r['book_status'], ['approved', 'confirmed', ''], true)) {
                $pending++;
            }
        }

        return [
            'transactions' => count($rows),
            'mkoba_total'  => $mkobaTotal,
            'book_total'   => $bookTotal,
            'difference'   => $mkobaTotal - $bookTotal,
            'mismatches'   => $mismatches,
            'pending'      => $pending,
        ];
    }
}

if (!function_exists('vk_api_mkoba_member_row')) {
    function vk_api_mkoba_member_row(array $r): array
    {
        return [
            'trans_date'   => !empty($r['trans_date']) ? date('Y-m-d', strtotime((string) $r['trans_date'])) : null,
            'trans_id'     => (string) ($r['trans_id'] ?? ''),
            'receipt'      => vk_api_mkoba_ref($r['receipt'] ?? null),
            'mkoba_amount' => (float) $r['mkoba_amount'],
            'book_amount'  => (float) $r['book_amount'],
            'book_status'  => (string) $r['book_status'],
            'matched'      => ((float) $r['mkoba_amount']) === ((float) $r['book_amount']),
            'ok'           => in_array($r['book_status'], ['approved', 'confirmed', ''], true),
        ];
    }
}
