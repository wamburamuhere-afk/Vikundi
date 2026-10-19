<?php
/**
 * includes/dividends.php
 * ----------------------
 * Year-end dividends: what the group earned in a period, shared out to members
 * in proportion to their share capital.
 *
 *   SURPLUS  = what the group EARNED       loan interest + loan penalties + fines paid
 *            - what it SPENT running itself  general expenses + petty cash
 *
 * Never part of a dividend:
 *   - share capital and loan principal — that is members' own money moving, not income
 *   - the Social Fund and membership deposits — ring-fenced (includes/fund_buckets.php)
 *   - death expenses and member payouts — welfare, charged to the Social Fund, so
 *     one member's bereavement does not cut everyone's dividend
 *
 * Each member's part is  distributable x (their share capital / all share capital),
 * taken on the last day of the period.
 *
 * A run is CONFIRMED first (figures frozen, nothing moves) and DISTRIBUTED second
 * (paid out in cash, or added to each member's shares). Two runs may not cover
 * the same day, so the same income cannot be shared out twice.
 */

require_once __DIR__ . '/fund_buckets.php';

if (!function_exists('dv_methods')) {
    /** cash = paid out to the member; shares = added to their share capital. */
    function dv_methods(): array { return ['cash', 'shares']; }
}

if (!function_exists('dv_money')) {
    function dv_money(float $v): float { return round($v, 2); }
}

if (!function_exists('dv_settings')) {
    /**
     * @return array{distribute_pct:float,year_start_month:int}
     *
     * Unset => 100% of the surplus is shared out and the year starts in January.
     * These are neutral ("no reserve held back", "calendar year"), not invented
     * amounts of money.
     */
    function dv_settings(array $raw): array
    {
        $pct   = is_numeric($raw['dividend_distribute_pct'] ?? null) ? (float) $raw['dividend_distribute_pct'] : 100.0;
        $month = (int) ($raw['financial_year_start_month'] ?? 1);
        return [
            'distribute_pct'   => min(100.0, max(0.0, $pct)),
            'year_start_month' => ($month >= 1 && $month <= 12) ? $month : 1,
        ];
    }
}

if (!function_exists('dv_current_year')) {
    /**
     * The financial year a date falls in.
     *
     * @return array{0:string,1:string} [from, to] as Y-m-d
     */
    function dv_current_year(int $startMonth, string $today): array
    {
        $d    = new DateTimeImmutable($today);
        $year = (int) $d->format('Y');
        if ((int) $d->format('n') < $startMonth) {
            $year--;
        }
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
        return [$from->format('Y-m-d'), $from->modify('+1 year')->modify('-1 day')->format('Y-m-d')];
    }
}

if (!function_exists('dv_surplus')) {
    /**
     * @param array{interest:float,penalties:float,fines:float,expenses:float} $totals
     * @return array{income:float,expenses:float,surplus:float,distributable:float,reserve:float}
     *
     * A loss is not shared out: with no surplus there is nothing to distribute.
     */
    function dv_surplus(array $totals, float $distributePct): array
    {
        $income   = dv_money((float) $totals['interest'] + (float) $totals['penalties'] + (float) $totals['fines']);
        $expenses = dv_money((float) $totals['expenses']);
        $surplus  = dv_money($income - $expenses);
        $distributable = $surplus > 0 ? dv_money($surplus * min(100.0, max(0.0, $distributePct)) / 100) : 0.0;
        return [
            'income' => $income, 'expenses' => $expenses, 'surplus' => $surplus,
            'distributable' => $distributable, 'reserve' => dv_money(max(0.0, $surplus) - $distributable),
        ];
    }
}

if (!function_exists('dv_split')) {
    /**
     * Share an amount out in proportion to share capital.
     *
     * Each part is rounded DOWN to the cent and the cents left over go, one at a
     * time, to the largest remainders — so the parts always add up to the amount
     * exactly and no member is rounded up at another's expense arbitrarily.
     *
     * @param array<int,float> $sharesByMember customer_id => share capital
     * @return array<int,float> customer_id => dividend (members with no shares are left out)
     */
    function dv_split(array $sharesByMember, float $amount): array
    {
        $shares = array_filter(array_map('floatval', $sharesByMember), static fn($s) => $s > 0);
        $total  = array_sum($shares);
        $cents  = (int) round($amount * 100);
        if ($total <= 0 || $cents <= 0) {
            return [];
        }

        $parts = $remainders = [];
        foreach ($shares as $id => $s) {
            $exact = $cents * $s / $total;
            $parts[$id]      = (int) floor($exact + 1e-9);
            $remainders[$id] = $exact - $parts[$id];
        }
        $left = $cents - array_sum($parts);
        // Largest remainder first; ties go to the lower member id, so the result
        // is the same every time it is worked out.
        uksort($remainders, static fn($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        foreach (array_keys($remainders) as $id) {
            if ($left <= 0) break;
            $parts[$id]++;
            $left--;
        }
        return array_map(static fn($c) => round($c / 100.0, 2), $parts); // always a float, even for whole shillings
    }
}

if (!function_exists('dv_valid_period')) {
    function dv_valid_period(string $from, string $to): bool
    {
        $a = DateTime::createFromFormat('Y-m-d', $from);
        $b = DateTime::createFromFormat('Y-m-d', $to);
        return $a && $b && $a->format('Y-m-d') === $from && $b->format('Y-m-d') === $to && $from <= $to;
    }
}

// ── Reads ────────────────────────────────────────────────────────────────────

if (!function_exists('dv_totals')) {
    /**
     * What the group earned and spent in a period (cash basis).
     *
     * Fines carry no payment date, so a paid fine is placed by when its row was
     * last changed — which is when it was marked paid.
     *
     * @return array{interest:float,penalties:float,fines:float,expenses:float}
     */
    function dv_totals(PDO $pdo, string $from, string $to): array
    {
        $sum = static function (string $sql) use ($pdo, $from, $to): float {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$from, $to]);
                return (float) $st->fetchColumn();
            } catch (Throwable $e) {
                return 0.0; // table absent on this environment -> nothing earned there
            }
        };
        return [
            'interest'  => $sum("SELECT COALESCE(SUM(interest_part), 0) FROM vicoba_loan_payments WHERE payment_date BETWEEN ? AND ?"),
            'penalties' => $sum("SELECT COALESCE(SUM(penalty_part), 0) FROM vicoba_loan_payments WHERE payment_date BETWEEN ? AND ?"),
            'fines'     => $sum("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'paid' AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ?"),
            'expenses'  => $sum("SELECT COALESCE(SUM(amount), 0) FROM general_expenses WHERE status = 'paid' AND DATE(COALESCE(paid_at, expense_date)) BETWEEN ? AND ?")
                         + $sum("SELECT COALESCE(SUM(amount), 0) FROM petty_cash_vouchers WHERE status = 'paid' AND DATE(COALESCE(paid_at, transaction_date)) BETWEEN ? AND ?"),
        ];
    }
}

if (!function_exists('dv_share_capital_as_at')) {
    /**
     * Every member's share capital on a given day, with their name.
     *
     * @return array<int,array{name:string,shares:float}> customer_id => row
     */
    function dv_share_capital_as_at(PDO $pdo, string $asAt, ?array $fundRules = null): array
    {
        $fundRules = $fundRules ?? fb_load_settings($pdo);
        $st = $pdo->prepare(
            "SELECT c.customer_id,
                    TRIM(CONCAT_WS(' ', NULLIF(TRIM(c.first_name), ''), NULLIF(TRIM(c.middle_name), ''), NULLIF(TRIM(c.last_name), ''))) AS name,
                    COALESCE(SUM(co.amount), 0) AS shares
               FROM customers c
               JOIN contributions co ON co.member_id = c.customer_id
              WHERE c.status <> 'deleted'
                AND co.status IN ('confirmed', 'approved', '')
                AND co.contribution_type IN (" . fb_sql_list(fb_share_types((bool) $fundRules['shares_from_monthly'])) . ")
                AND co.contribution_date <= ?
              GROUP BY c.customer_id, c.first_name, c.middle_name, c.last_name
             HAVING shares > 0
              ORDER BY c.first_name, c.last_name"
        );
        $st->execute([$asAt]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['customer_id']] = ['name' => (string) $r['name'], 'shares' => (float) $r['shares']];
        }
        return $out;
    }
}

if (!function_exists('dv_preview')) {
    /**
     * The whole calculation for a period, saving nothing.
     *
     * @return array{from:string,to:string,totals:array,figures:array,distribute_pct:float,total_shares:float,lines:array}
     */
    function dv_preview(PDO $pdo, string $from, string $to): array
    {
        $raw      = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $settings = dv_settings($raw);
        $totals   = dv_totals($pdo, $from, $to);
        $figures  = dv_surplus($totals, $settings['distribute_pct']);
        $members  = dv_share_capital_as_at($pdo, $to, fb_settings($raw));
        $split    = dv_split(array_map(static fn($m) => $m['shares'], $members), $figures['distributable']);

        $lines = [];
        foreach ($members as $id => $m) {
            $lines[] = ['customer_id' => $id, 'name' => $m['name'], 'shares' => $m['shares'], 'amount' => $split[$id] ?? 0.0];
        }
        return [
            'from' => $from, 'to' => $to, 'totals' => $totals, 'figures' => $figures,
            'distribute_pct' => $settings['distribute_pct'],
            'total_shares' => dv_money(array_sum(array_column($lines, 'shares'))), 'lines' => $lines,
        ];
    }
}

if (!function_exists('dv_runs')) {
    function dv_runs(PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM vicoba_dividend_runs ORDER BY period_to DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('dv_run')) {
    function dv_run(PDO $pdo, int $runId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM vicoba_dividend_runs WHERE id = ?");
        $st->execute([$runId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('dv_run_lines')) {
    function dv_run_lines(PDO $pdo, int $runId): array
    {
        $st = $pdo->prepare(
            "SELECT l.*, TRIM(CONCAT_WS(' ', NULLIF(TRIM(c.first_name), ''), NULLIF(TRIM(c.middle_name), ''), NULLIF(TRIM(c.last_name), ''))) AS name
               FROM vicoba_dividend_lines l JOIN customers c ON c.customer_id = l.customer_id
              WHERE l.run_id = ? ORDER BY c.first_name, c.last_name"
        );
        $st->execute([$runId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('dv_member_latest')) {
    /** A member's most recent distributed dividend, or null. */
    function dv_member_latest(PDO $pdo, int $customerId): ?array
    {
        $st = $pdo->prepare(
            "SELECT l.amount, l.share_capital, r.reference, r.period_from, r.period_to, r.method, r.distributed_at
               FROM vicoba_dividend_lines l JOIN vicoba_dividend_runs r ON r.id = l.run_id
              WHERE l.customer_id = ? AND r.status = 'distributed'
              ORDER BY r.period_to DESC, r.id DESC LIMIT 1"
        );
        $st->execute([$customerId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// ── Writes ───────────────────────────────────────────────────────────────────

if (!function_exists('dv_confirm')) {
    /**
     * Freeze a period's figures as a dividend run. Nothing moves yet.
     */
    function dv_confirm(PDO $pdo, string $from, string $to, string $method, int $userId, bool $isSw = false): array
    {
        $no = static fn(string $en, string $sw): array => ['ok' => false, 'message' => $isSw ? $sw : $en];

        if (!dv_valid_period($from, $to)) {
            return $no('Choose a valid period.', 'Chagua kipindi sahihi.');
        }
        if ($to > date('Y-m-d')) {
            return $no('The period cannot end in the future: its income is not all in yet.', 'Kipindi hakiwezi kuisha siku zijazo: mapato yake bado hayajakamilika.');
        }
        if (!in_array($method, dv_methods(), true)) {
            return $no('Choose how the dividend is paid: cash or shares.', 'Chagua jinsi gawio litakavyolipwa: taslimu au hisa.');
        }

        $pdo->beginTransaction();
        try {
            // Two runs may not cover the same day — that income would be shared twice.
            $clash = $pdo->prepare("SELECT reference FROM vicoba_dividend_runs WHERE status <> 'cancelled' AND period_from <= ? AND period_to >= ? LIMIT 1 FOR UPDATE");
            $clash->execute([$to, $from]);
            if ($ref = $clash->fetchColumn()) {
                $pdo->rollBack();
                return $no("This period overlaps dividend run {$ref}.", "Kipindi hiki kinaingiliana na gawio {$ref}.");
            }

            $p = dv_preview($pdo, $from, $to);
            if ($p['figures']['distributable'] <= 0) {
                $pdo->rollBack();
                return $no('There is no surplus to share out for this period.', 'Hakuna ziada ya kugawa kwa kipindi hiki.');
            }
            if (!$p['lines']) {
                $pdo->rollBack();
                return $no('No member held shares at the end of this period.', 'Hakuna mwanachama mwenye hisa mwishoni mwa kipindi hiki.');
            }

            $pdo->prepare(
                "INSERT INTO vicoba_dividend_runs
                    (reference, period_from, period_to, interest_income, penalty_income, fines_income, expenses, surplus,
                     distribute_pct, distributable, total_share_capital, method, status, confirmed_by, confirmed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, NOW())"
            )->execute([
                'TMP-' . bin2hex(random_bytes(8)), $from, $to, $p['totals']['interest'], $p['totals']['penalties'], $p['totals']['fines'],
                $p['figures']['expenses'], $p['figures']['surplus'], $p['distribute_pct'], $p['figures']['distributable'],
                $p['total_shares'], $method, $userId,
            ]);
            $runId = (int) $pdo->lastInsertId();
            $reference = 'DV-' . substr($to, 0, 4) . '-' . str_pad((string) $runId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE vicoba_dividend_runs SET reference = ? WHERE id = ?")->execute([$reference, $runId]);

            $ins = $pdo->prepare("INSERT INTO vicoba_dividend_lines (run_id, customer_id, share_capital, amount) VALUES (?, ?, ?, ?)");
            foreach ($p['lines'] as $l) {
                $ins->execute([$runId, $l['customer_id'], $l['shares'], $l['amount']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'run_id' => $runId, 'reference' => $reference,
                'message' => $isSw ? "Gawio {$reference} limethibitishwa." : "Dividend run {$reference} confirmed."];
    }
}

if (!function_exists('dv_distribute')) {
    /**
     * Carry a confirmed run out. 'shares' adds each member's dividend to their
     * share capital as an approved contribution; 'cash' records it as paid out.
     * Either way the amount leaves the group's undistributed surplus (finance.php).
     */
    function dv_distribute(PDO $pdo, int $runId, int $userId, bool $isSw = false): array
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM vicoba_dividend_runs WHERE id = ? AND status = 'confirmed' FOR UPDATE");
            $st->execute([$runId]);
            $run = $st->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $isSw ? 'Gawio hili halisubiri kugawanywa.' : 'This run is not waiting to be distributed.'];
            }

            if ($run['method'] === 'shares') {
                $ins = $pdo->prepare(
                    "INSERT INTO contributions (member_id, amount, contribution_type, contribution_date, description, status, created_by, approved_by, approved_at)
                     VALUES (?, ?, 'share', CURDATE(), ?, 'approved', ?, ?, NOW())"
                );
                $link = $pdo->prepare("UPDATE vicoba_dividend_lines SET contribution_id = ? WHERE id = ?");
                foreach (dv_run_lines($pdo, $runId) as $l) {
                    if ((float) $l['amount'] <= 0) continue;
                    $ins->execute([$l['customer_id'], $l['amount'], 'Dividend ' . $run['reference'], $userId, $userId]);
                    $link->execute([(int) $pdo->lastInsertId(), $l['id']]);
                }
            }

            $pdo->prepare("UPDATE vicoba_dividend_runs SET status = 'distributed', distributed_by = ?, distributed_at = NOW() WHERE id = ? AND status = 'confirmed'")
                ->execute([$userId, $runId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => $isSw ? 'Gawio limegawanywa.' : 'Dividend distributed.'];
    }
}

if (!function_exists('dv_cancel')) {
    /** Drop a confirmed run that has not been distributed. A distributed run is final. */
    function dv_cancel(PDO $pdo, int $runId, bool $isSw = false): array
    {
        $up = $pdo->prepare("UPDATE vicoba_dividend_runs SET status = 'cancelled' WHERE id = ? AND status = 'confirmed'");
        $up->execute([$runId]);
        if ($up->rowCount() !== 1) {
            return ['ok' => false, 'message' => $isSw ? 'Gawio hili haliwezi kughairiwa.' : 'This run can no longer be cancelled.'];
        }
        return ['ok' => true, 'message' => $isSw ? 'Gawio limeghairiwa.' : 'Dividend run cancelled.'];
    }
}
