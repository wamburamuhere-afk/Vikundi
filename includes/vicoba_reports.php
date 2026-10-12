<?php
/**
 * includes/vicoba_reports.php
 * ---------------------------
 * The figures behind the loan, share and Social Fund reports
 * (app/constant/reports/loan_portfolio_report.php and its three siblings).
 *
 * Nothing is calculated here that the rest of the system calculates
 * differently: loan balances and penalties come from includes/loan_rules.php,
 * share capital from includes/dividends.php, the Social Fund from
 * includes/fund_buckets.php. A report that disagreed with the screen it
 * summarises would be worse than no report.
 */

require_once __DIR__ . '/loans.php';
require_once __DIR__ . '/dividends.php';

if (!function_exists('vr_can_view')) {
    /**
     * May this session read the loan, share and Social Fund reports?
     *
     * Leadership only. Deliberately NOT the 'vicoba_reports' key the older group
     * reports use: that one is granted to every member, and these reports list
     * each member's loans, what they owe and who is behind.
     */
    function vr_can_view(): bool
    {
        return canView('approve_loan') || canView('disburse_loan') || canView('dividends');
    }
}

if (!function_exists('vr_period')) {
    /**
     * The report period from the request, defaulting to the current financial
     * year up to today.
     *
     * @return array{0:string,1:string} [from, to]
     */
    function vr_period(PDO $pdo, array $get): array
    {
        $raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        [$from, $to] = dv_current_year(dv_settings($raw)['year_start_month'], date('Y-m-d'));
        $to = min($to, date('Y-m-d'));

        $gFrom = (string) ($get['from'] ?? '');
        $gTo   = (string) ($get['to'] ?? '');
        return dv_valid_period($gFrom, $gTo) ? [$gFrom, $gTo] : [$from, $to];
    }
}

if (!function_exists('vr_days_late')) {
    /** Whole days an unpaid instalment is past its due date (0 if not yet due). */
    function vr_days_late(string $dueDate, string $asOf): int
    {
        $due = new DateTimeImmutable($dueDate);
        $now = new DateTimeImmutable($asOf);
        return $now > $due ? (int) $due->diff($now)->days : 0;
    }
}

if (!function_exists('vr_loan_portfolio')) {
    /**
     * Every loan with where it stands today, plus the instalments that are
     * overdue. $status '' = all loans.
     *
     * @return array{loans:array,totals:array,overdue:array,by_status:array}
     */
    function vr_loan_portfolio(PDO $pdo, string $status = '', ?string $asOf = null): array
    {
        $asOf   = $asOf ?? date('Y-m-d');
        $loans  = vl_loans($pdo, null, $status);
        $totals = ['amount' => 0.0, 'paid' => 0.0, 'penalty_paid' => 0.0, 'balance' => 0.0, 'penalty_due' => 0.0];
        $byStatus = [];
        $overdue  = [];
        $out      = [];

        foreach ($loans as $l) {
            $rules = vl_rules($l);
            $paid = $penaltyPaid = $penaltyDue = $balance = 0.0;
            foreach (vl_installments($pdo, (int) $l['loan_id']) as $i) {
                $paid        += (float) $i['principal_paid'] + (float) $i['interest_paid'];
                $penaltyPaid += (float) $i['penalty_paid'];
                if ($l['status'] !== 'disbursed') {
                    continue;
                }
                $unpaid = lr_installment_unpaid($i);
                $pen    = lr_installment_penalty_due($i, $asOf, $rules);
                $balance    += $unpaid;
                $penaltyDue += $pen;
                if ($unpaid > 0 && $i['due_date'] < $asOf) {
                    $overdue[] = [
                        'loan_id' => (int) $l['loan_id'], 'reference' => $l['reference'], 'member_name' => $l['member_name'],
                        'type_name' => $l['type_name'], 'seq' => (int) $i['seq'], 'due_date' => $i['due_date'],
                        'days_late' => vr_days_late($i['due_date'], $asOf), 'unpaid' => $unpaid, 'penalty' => $pen,
                    ];
                }
            }
            $row = $l + ['paid' => lr_money($paid), 'penalty_paid' => lr_money($penaltyPaid),
                         'owed' => lr_money($balance), 'penalty_due' => lr_money($penaltyDue)];
            $out[] = $row;

            // Money totals count loans that were actually paid out.
            if (in_array($l['status'], ['disbursed', 'repaid'], true)) {
                $totals['amount']       += (float) $l['amount'];
                $totals['paid']         += $paid;
                $totals['penalty_paid'] += $penaltyPaid;
                $totals['balance']      += $balance;
                $totals['penalty_due']  += $penaltyDue;
            }
            $byStatus[$l['status']] = ($byStatus[$l['status']] ?? 0) + 1;
        }

        usort($overdue, static fn($a, $b) => $b['days_late'] <=> $a['days_late']);
        return ['loans' => $out, 'totals' => array_map('lr_money', $totals), 'overdue' => $overdue, 'by_status' => $byStatus];
    }
}

if (!function_exists('vr_loan_repayments')) {
    /**
     * Repayments received in a period, each split the way it was applied.
     * Interest and penalties are the group's income from lending.
     *
     * @return array{rows:array,totals:array{amount:float,principal:float,interest:float,penalty:float,income:float}}
     */
    function vr_loan_repayments(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare(
            "SELECT p.*, l.reference, l.type_name, " . vl_member_name_sql('c') . " AS member_name
               FROM vicoba_loan_payments p
               JOIN vicoba_loans l ON l.loan_id = p.loan_id
               JOIN customers c ON c.customer_id = l.customer_id
              WHERE p.payment_date BETWEEN ? AND ?
              ORDER BY p.payment_date, p.id"
        );
        $st->execute([$from, $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $t = ['amount' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'penalty' => 0.0];
        foreach ($rows as $r) {
            $t['amount']    += (float) $r['amount'];
            $t['principal'] += (float) $r['principal_part'];
            $t['interest']  += (float) $r['interest_part'];
            $t['penalty']   += (float) $r['penalty_part'];
        }
        $t = array_map('lr_money', $t);
        $t['income'] = lr_money($t['interest'] + $t['penalty']);
        return ['rows' => $rows, 'totals' => $t];
    }
}

if (!function_exists('vr_shares_register')) {
    /**
     * Who holds what share of the group, on a given day.
     *
     * @return array{rows:array,total:float,share_price:float,total_shares:float}
     */
    function vr_shares_register(PDO $pdo, string $asAt): array
    {
        $rules   = fb_load_settings($pdo);
        $members = dv_share_capital_as_at($pdo, $asAt, $rules);
        $total   = array_sum(array_map(static fn($m) => $m['shares'], $members));

        $rows = [];
        foreach ($members as $id => $m) {
            $rows[] = [
                'customer_id' => $id, 'name' => $m['name'], 'capital' => $m['shares'],
                'shares' => fb_share_count($m['shares'], (float) $rules['share_price']),
                'pct' => $total > 0 ? round($m['shares'] / $total * 100, 2) : 0.0,
            ];
        }
        usort($rows, static fn($a, $b) => ($b['capital'] <=> $a['capital']) ?: strcmp($a['name'], $b['name']));
        return ['rows' => $rows, 'total' => lr_money($total), 'share_price' => (float) $rules['share_price'],
                'total_shares' => fb_share_count($total, (float) $rules['share_price'])];
    }
}

if (!function_exists('vr_social_fund')) {
    /**
     * The Social Fund over a period: balance brought forward, what each member
     * paid in, each welfare payment out, and the closing balance.
     *
     * @return array{opening:float,paid_in:float,paid_out:float,closing:float,members:array,welfare:array}
     */
    function vr_social_fund(PDO $pdo, string $from, string $to): array
    {
        $one = static function (string $sql, array $args) use ($pdo): float {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($args);
                return (float) $st->fetchColumn();
            } catch (Throwable $e) {
                return 0.0;
            }
        };
        $in  = "SELECT COALESCE(SUM(co.amount), 0) FROM contributions co
                  JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'
                 WHERE co.contribution_type = 'social_fund' AND co.status IN ('confirmed', 'approved', '')";
        $death  = "SELECT COALESCE(SUM(amount), 0) FROM death_expenses WHERE status = 'paid' AND DATE(COALESCE(paid_at, expense_date))";
        $payout = "SELECT COALESCE(SUM(amount), 0) FROM member_payouts WHERE status = 'paid' AND payout_date";

        $opening = $one("$in AND co.contribution_date < ?", [$from]) - $one("$death < ?", [$from]) - $one("$payout < ?", [$from]);
        $paidIn  = $one("$in AND co.contribution_date BETWEEN ? AND ?", [$from, $to]);

        $st = $pdo->prepare(
            "SELECT c.customer_id, " . vl_member_name_sql('c') . " AS name, COALESCE(SUM(co.amount), 0) AS paid, COUNT(*) AS payments
               FROM contributions co JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'
              WHERE co.contribution_type = 'social_fund' AND co.status IN ('confirmed', 'approved', '')
                AND co.contribution_date BETWEEN ? AND ?
              GROUP BY c.customer_id, c.first_name, c.middle_name, c.last_name
              ORDER BY c.first_name, c.last_name"
        );
        $st->execute([$from, $to]);
        $members = $st->fetchAll(PDO::FETCH_ASSOC);

        $welfare = [];
        try {
            $w = $pdo->prepare(
                "SELECT DATE(COALESCE(d.paid_at, d.expense_date)) AS paid_on, " . vl_member_name_sql('c') . " AS name,
                        COALESCE(NULLIF(d.description, ''), 'Bereavement assistance') AS what, d.amount
                   FROM death_expenses d LEFT JOIN customers c ON c.customer_id = d.member_id
                  WHERE d.status = 'paid' AND DATE(COALESCE(d.paid_at, d.expense_date)) BETWEEN ? AND ?"
            );
            $w->execute([$from, $to]);
            $welfare = $w->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
        }
        try {
            $w = $pdo->prepare(
                "SELECT m.payout_date AS paid_on, " . vl_member_name_sql('c') . " AS name,
                        COALESCE(NULLIF(m.description, ''), 'Member assistance') AS what, m.amount
                   FROM member_payouts m LEFT JOIN customers c ON c.customer_id = m.member_id
                  WHERE m.status = 'paid' AND m.payout_date BETWEEN ? AND ?"
            );
            $w->execute([$from, $to]);
            $welfare = array_merge($welfare, $w->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
        }
        usort($welfare, static fn($a, $b) => strcmp((string) $a['paid_on'], (string) $b['paid_on']));
        $paidOut = array_sum(array_map(static fn($r) => (float) $r['amount'], $welfare));

        return [
            'opening' => lr_money($opening), 'paid_in' => lr_money($paidIn), 'paid_out' => lr_money($paidOut),
            'closing' => lr_money($opening + $paidIn - $paidOut), 'members' => $members, 'welfare' => $welfare,
        ];
    }
}

// ── Page furniture shared by the four reports ────────────────────────────────

if (!function_exists('vr_reports')) {
    /** route => [English title, Swahili title, icon] — also the tab strip. */
    function vr_reports(): array
    {
        return [
            'reports/loans'           => ['Loan Portfolio', 'Mikopo Yote', 'bi-bank'],
            'reports/loan_repayments' => ['Loan Income & Repayments', 'Mapato na Marejesho ya Mikopo', 'bi-cash-coin'],
            'reports/shares'          => ['Shares Register', 'Daftari la Hisa', 'bi-pie-chart'],
            'reports/social_fund'     => ['Social Fund Statement', 'Taarifa ya Mfuko wa Jamii', 'bi-heart'],
        ];
    }
}

if (!function_exists('vr_open')) {
    /**
     * The top of a report page: letterhead for paper, title bar, the tab strip
     * across the four reports, and the filter form.
     *
     * @param string $filters HTML of the filter inputs (already escaped by the caller)
     */
    function vr_open(PDO $pdo, string $route, string $subtitle, string $printRef, string $filters, bool $isSw): void
    {
        [$en, $sw, $icon] = vr_reports()[$route];
        $title = $isSw ? $sw : $en;
        ?>
<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block"><?php PrintHeader::render($pdo, mb_strtoupper($title), $printRef); ?></div>

    <div class="card border-0 shadow-sm mb-3 d-print-none" style="border-left:5px solid #0f4c81 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-primary"><i class="bi <?= $icon ?> me-2"></i><?= htmlspecialchars($title) ?></h3>
                <p class="text-muted mb-0 small"><?= htmlspecialchars($subtitle) ?></p>
            </div>
            <button type="button" class="btn btn-outline-primary rounded-pill px-4" onclick="window.print()"><i class="bi bi-printer me-2"></i><?= $isSw ? 'Chapisha' : 'Print' ?></button>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <?php foreach (vr_reports() as $r => [$e, $s, $ic]): ?>
        <a href="<?= getUrl($r) ?>" class="btn btn-sm rounded-pill <?= $r === $route ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="bi <?= $ic ?> me-1"></i><?= htmlspecialchars($isSw ? $s : $e) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($filters !== ''): ?>
    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="get" action="<?= getUrl($route) ?>" class="row g-3 align-items-end">
                <?= $filters ?>
                <div class="col-auto"><button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-funnel me-1"></i><?= $isSw ? 'Onyesha' : 'Show' ?></button></div>
            </form>
        </div>
    </div>
    <?php endif;
    }
}

if (!function_exists('vr_close')) {
    function vr_close(): void
    {
        ?>
</div>
<?php include PRINT_FOOTER_CSS_FILE; ?>
<div class="d-none d-print-block"><?php include PRINT_FOOTER_FILE; ?></div>
        <?php
    }
}

if (!function_exists('vr_kpis')) {
    /** A row of headline figures. @param array<int,array{0:string,1:string,2:string}> $items [label, value, colour] */
    function vr_kpis(array $items): void
    {
        echo '<div class="row g-2 mb-3">';
        foreach ($items as [$label, $value, $color]) {
            echo '<div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">'
               . '<div class="fs-5 fw-bold text-' . $color . '">' . htmlspecialchars($value) . '</div>'
               . '<div class="small text-muted">' . htmlspecialchars($label) . '</div></div></div></div>';
        }
        echo '</div>';
    }
}

if (!function_exists('vr_period_filter')) {
    function vr_period_filter(string $from, string $to, bool $isSw): string
    {
        return '<div class="col-md-3"><label class="form-label fw-bold">' . ($isSw ? 'Kuanzia' : 'From') . '</label>'
             . '<input type="date" name="from" class="form-control" value="' . htmlspecialchars($from) . '" required></div>'
             . '<div class="col-md-3"><label class="form-label fw-bold">' . ($isSw ? 'Hadi' : 'To') . '</label>'
             . '<input type="date" name="to" class="form-control" value="' . htmlspecialchars($to) . '" required></div>';
    }
}
