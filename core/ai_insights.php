<?php
/**
 * core/ai_insights.php
 * --------------------
 * The ONLY way "Ask Vikundi" can read group data. A fixed registry of read-only,
 * parameterised aggregate functions. The model may choose a function + args; we
 * run it and return a small result for the model to phrase. The model never sees
 * raw rows, never writes SQL, and can never modify anything.
 *
 * Confirmed contributions use the same status filter as the rest of the app:
 *   status IN ('confirmed','approved','')   (historical rows have empty status).
 *
 * Public API:
 *   aiInsightCatalog(): array            — [{name, description, params}] for the prompt
 *   aiRunInsight(string, array): array   — ['ok'=>bool,'data'=>mixed,'error'=>?string]
 */

if (!function_exists('_aiviPeriod')) {
    /** Resolve a period word OR explicit from/to into [from,to] (Y-m-d), or [null,null] for all-time. */
    function _aiviPeriod(array $a): array
    {
        $today = date('Y-m-d');
        if (!empty($a['from']) && !empty($a['to'])) return [$a['from'], $a['to']];
        switch ($a['period'] ?? '') {
            case 'today':        return [$today, $today];
            case 'yesterday':    return [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))];
            case 'last_7_days':  return [date('Y-m-d', strtotime('-6 days')), $today];
            case 'last_30_days': return [date('Y-m-d', strtotime('-29 days')), $today];
            case 'this_month':   return [date('Y-m-01'), $today];
            case 'last_month':   return [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))];
            case 'this_year':    return [date('Y-01-01'), $today];
            case 'last_year':    return [date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year'))];
            default:             return [null, null]; // all-time
        }
    }
}

if (!function_exists('aiInsightRegistry')) {
    /** name => ['description','params'=>[name=>hint],'run'=>fn(array $args, PDO):array] */
    function aiInsightRegistry(): array
    {
        $CONF = "status IN ('confirmed','approved','')"; // confirmed contributions

        return [
            'total_savings' => [
                'description' => 'Total member savings/contributions collected (sum of confirmed contributions). All-time unless a period is given.',
                'params' => ['period' => 'this_month|last_month|this_year|last_30_days (optional)', 'from' => 'YYYY-MM-DD (optional)', 'to' => 'YYYY-MM-DD (optional)'],
                'run' => function (array $a, PDO $pdo) use ($CONF) {
                    [$f, $t] = _aiviPeriod($a);
                    if ($f && $t) {
                        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM contributions WHERE $CONF AND contribution_date BETWEEN ? AND ?");
                        $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC);
                        return ['period' => "$f to $t", 'total_savings' => (float)$r['total'], 'contributions_count' => (int)$r['n']];
                    }
                    $r = $pdo->query("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM contributions WHERE $CONF")->fetch(PDO::FETCH_ASSOC);
                    return ['period' => 'all time', 'total_savings' => (float)$r['total'], 'contributions_count' => (int)$r['n']];
                },
            ],

            'contributions_by_status' => [
                'description' => 'How much money is in each contribution status (pending, reviewed, approved, cancelled), with counts.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiviPeriod($a);
                    if ($f && $t) {
                        $s = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM contributions WHERE contribution_date BETWEEN ? AND ? GROUP BY status");
                        $s->execute([$f, $t]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $rows = $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM contributions GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
                    }
                    return ['by_status' => array_map(fn($r) => ['status' => ($r['status'] === '' ? 'unmarked' : $r['status']), 'count' => (int)$r['n'], 'amount' => (float)$r['total']], $rows)];
                },
            ],

            'top_contributors' => [
                'description' => 'Members who have contributed the most (by total confirmed contributions).',
                'params' => ['limit' => 'how many (default 5)', 'period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(20, (int)($a['limit'] ?? 5)));
                    [$f, $t] = _aiviPeriod($a);
                    $where = "c.status IN ('confirmed','approved','')"; $params = [];
                    if ($f && $t) { $where .= " AND c.contribution_date BETWEEN ? AND ?"; $params = [$f, $t]; }
                    $sql = "SELECT cu.customer_name, cu.first_name, cu.last_name, COALESCE(SUM(c.amount),0) total
                            FROM contributions c JOIN customers cu ON cu.customer_id = c.member_id
                            WHERE $where GROUP BY c.member_id ORDER BY total DESC LIMIT $n";
                    $s = $pdo->prepare($sql); $s->execute($params);
                    return ['top_contributors' => array_map(function ($r) {
                        $name = trim($r['customer_name']) ?: trim($r['first_name'] . ' ' . $r['last_name']);
                        return ['member' => $name, 'total' => (float)$r['total']];
                    }, $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],

            'monthly_contribution_trend' => [
                'description' => 'Total confirmed contributions per month for the last N months (default 6).',
                'params' => ['months' => 'default 6'],
                'run' => function (array $a, PDO $pdo) use ($CONF) {
                    $m = max(2, min(24, (int)($a['months'] ?? 6)));
                    $s = $pdo->prepare("SELECT DATE_FORMAT(contribution_date,'%Y-%m') ym, COALESCE(SUM(amount),0) total
                                        FROM contributions WHERE $CONF AND contribution_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL $m MONTH)
                                        GROUP BY ym ORDER BY ym");
                    $s->execute();
                    return ['monthly_totals' => $s->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],

            'members_summary' => [
                'description' => 'Membership numbers: total members, how many are active, and how many are deceased.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $total = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_deceased = 0")->fetchColumn();
                    $deceased = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE is_deceased = 1")->fetchColumn();
                    $byStatus = $pdo->query("SELECT status, COUNT(*) n FROM customers WHERE is_deceased = 0 GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
                    return ['total_members' => $total, 'deceased' => $deceased,
                            'by_status' => array_map(fn($r) => ['status' => $r['status'], 'count' => (int)$r['n']], $byStatus)];
                },
            ],

            'fines_summary' => [
                'description' => 'Member fines: total amount and how much is paid vs still pending.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $rows = $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM fines GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
                    $out = ['total_fines' => 0.0, 'by_status' => []];
                    foreach ($rows as $r) {
                        $out['total_fines'] += (float)$r['amt'];
                        $out['by_status'][] = ['status' => $r['status'], 'count' => (int)$r['n'], 'amount' => (float)$r['amt']];
                    }
                    return $out;
                },
            ],

            'expenses_summary' => [
                'description' => 'Group expenses (general expenses): total approved spending for a period and how many are pending approval.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiviPeriod($a);
                    if ($f && $t) {
                        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM general_expenses WHERE status IN ('approved','paid') AND expense_date BETWEEN ? AND ?");
                        $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC); $period = "$f to $t";
                    } else {
                        $r = $pdo->query("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM general_expenses WHERE status IN ('approved','paid')")->fetch(PDO::FETCH_ASSOC); $period = 'all time';
                    }
                    $pending = (int)$pdo->query("SELECT COUNT(*) FROM general_expenses WHERE status IN ('pending','reviewed')")->fetchColumn();
                    return ['period' => $period, 'approved_expenses' => (float)$r['total'], 'approved_count' => (int)$r['n'], 'pending_approval' => $pending];
                },
            ],

            'death_benefits_summary' => [
                'description' => 'Death/bereavement benefit payouts: total approved amount and count for a period.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiviPeriod($a);
                    if ($f && $t) {
                        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM death_expenses WHERE status IN ('approved','paid') AND expense_date BETWEEN ? AND ?");
                        $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC); $period = "$f to $t";
                    } else {
                        $r = $pdo->query("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM death_expenses WHERE status IN ('approved','paid')")->fetch(PDO::FETCH_ASSOC); $period = 'all time';
                    }
                    return ['period' => $period, 'approved_payouts' => (float)$r['total'], 'payout_count' => (int)$r['n']];
                },
            ],

            'pending_approvals' => [
                'description' => 'Everything awaiting approval right now: contributions, general expenses, death benefit claims and unpaid fines.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $cs = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt FROM contributions WHERE status IN ('pending','reviewed')")->fetch(PDO::FETCH_ASSOC);
                    $ge = (int)$pdo->query("SELECT COUNT(*) FROM general_expenses WHERE status IN ('pending','reviewed')")->fetchColumn();
                    $de = (int)$pdo->query("SELECT COUNT(*) FROM death_expenses WHERE status IN ('pending','reviewed')")->fetchColumn();
                    $fn = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status='pending'")->fetchColumn();
                    return [
                        'contributions_awaiting' => ['count' => (int)$cs['n'], 'amount' => (float)$cs['amt']],
                        'general_expenses_awaiting' => $ge,
                        'death_claims_awaiting' => $de,
                        'unpaid_fines' => $fn,
                    ];
                },
            ],

            'group_info' => [
                'description' => 'Group settings and rules: group name, currency, monthly contribution amount, entrance fee, AGM fee, meeting day, recorded group balance.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $keys = ['group_name','currency','monthly_contribution','entrance_fee','agm_fee','meeting_day','cycle_type','group_balance','contribution_start_date','group_registration_number'];
                    $in = implode(',', array_fill(0, count($keys), '?'));
                    $s = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings WHERE setting_key IN ($in)");
                    $s->execute($keys);
                    $out = [];
                    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['setting_key']] = $r['setting_value'];
                    return $out;
                },
            ],
        ];
    }
}

if (!function_exists('aiInsightCatalog')) {
    /** Lightweight catalog (no closures) to put in the model prompt. */
    function aiInsightCatalog(): array
    {
        $out = [];
        foreach (aiInsightRegistry() as $name => $def) {
            $out[] = ['name' => $name, 'description' => $def['description'], 'params' => $def['params']];
        }
        return $out;
    }
}

if (!function_exists('aiRunInsight')) {
    function aiRunInsight(string $name, array $args = []): array
    {
        global $pdo;
        $reg = aiInsightRegistry();
        if (!isset($reg[$name])) return ['ok' => false, 'error' => "Unknown insight: $name"];
        try {
            return ['ok' => true, 'data' => $reg[$name]['run']($args, $pdo)];
        } catch (Throwable $e) {
            error_log("aiRunInsight[$name]: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not compute that figure.'];
        }
    }
}
