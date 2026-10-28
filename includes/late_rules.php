<?php
/**
 * includes/late_rules.php — the late-payment rules: an automatic fine, then dormancy.
 *
 * The rule, as UKUU set it (all numbers are Group Settings):
 *   - Month M's contribution must be paid by the 9th of month M+1 (late_fine_day).
 *   - If it is not, the member gets one fine of 5,000 for month M (late_fine_amount).
 *     Never more than one fine per member per month.
 *   - If month M's contribution or its fine is still unpaid after the 15th of
 *     M+1 (late_dormant_day), the member becomes Dormant.
 *   - A member this rule made Dormant becomes Active again by themselves once
 *     every judged month and every late fine is paid. A member a leader made
 *     Dormant is never touched.
 *
 * What counts as paid:
 *   - Monthly contributions (type 'monthly', or a legacy blank type) that are
 *     pending, reviewed, approved or confirmed. A payment waiting for approval
 *     counts, so a slow approval never fines anyone; a cancelled one does not.
 *   - The rule judges only months from the month it was switched on
 *     (late_rules_start_ym). History before that is never fined: arrears from
 *     before are forgiven, and money paid ahead carries into the first judged
 *     month. "Before" is measured from the member's own first payment, at the
 *     rate in force each month, up to the deadline of the month before the start.
 *   - Payments are cumulative: money beyond one month's amount carries forward.
 *   - A fine is settled when a leader marks it Paid or Waived (Finance > Fines).
 *     Fines are paid separately; they are never taken out of contributions.
 *
 * Off by default (late_rules_enabled). Runs once a day from
 * actions/auto_terminate_members.php.
 */

require_once __DIR__ . '/contribution_rates.php';

if (!function_exists('late_settings')) {
    /** The rule's settings, with UKUU's numbers as the defaults. */
    function late_settings(array $s): array
    {
        $start = trim((string) ($s['late_rules_start_ym'] ?? ''));
        return [
            'enabled'     => ($s['late_rules_enabled'] ?? '0') === '1',
            'start_ym'    => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $start) ? $start : null,
            'fine'        => max(0.0, (float) ($s['late_fine_amount'] ?? 5000)),
            'fine_day'    => min(28, max(1, (int) ($s['late_fine_day'] ?? 9))),
            'dormant_day' => min(31, max(1, (int) ($s['late_dormant_day'] ?? 15))),
        ];
    }
}

if (!function_exists('late_add_months')) {
    /** 'Y-m' plus $n months (n may be negative). */
    function late_add_months(string $ym, int $n): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $i = $y * 12 + ($m - 1) + $n;
        return sprintf('%04d-%02d', intdiv($i, 12), $i % 12 + 1);
    }
}

if (!function_exists('late_deadline')) {
    /**
     * The last day to pay for month $ym: day $day of the FOLLOWING month, clamped
     * to that month's length. Returns 'Y-m-d'.
     */
    function late_deadline(string $ym, int $day): string
    {
        $next = late_add_months($ym, 1);
        $len = (int) date('t', strtotime($next . '-01'));
        return sprintf('%s-%02d', $next, min($day, $len));
    }
}

if (!function_exists('late_judge_member')) {
    /**
     * Judge one member. Pure: no database.
     *
     * @param array  $payments [['date' => 'Y-m-d', 'amount' => float], ...] counted payments
     * @param mixed  $rule     monthly rule (flat amount or rate history)
     * @param string $fromYm   first month this member is judged on: the later of the
     *                         rule's start month and the member's join month
     * @param array  $fines    late fines already on record: 'Y-m' => status
     *                         ('pending' | 'paid' | 'waived')
     * @param array  $cfg      late_settings()
     *
     * @return array{fine_months:string[], dormant:bool, reasons:string[], months:array}
     *   fine_months — months that need a fine now (none on record yet)
     *   dormant     — whether the member should be Dormant today
     *   reasons     — the months that make them dormant
     *   months      — per judged month: due (cumulative), paid_by_deadline, paid_now
     */
    function late_judge_member(array $payments, $rule, string $fromYm, array $fines, array $cfg, DateTimeInterface $today): array
    {
        $todayStr = $today->format('Y-m-d');
        // Everything up to the deadline of the month before the first judged month
        // is history: it is not judged, but money paid ahead in it carries forward.
        $historyEnd = late_deadline(late_add_months($fromYm, -1), $cfg['fine_day']);

        $events = [];
        $paidBefore = 0.0;
        $firstYm = null;
        foreach ($payments as $p) {
            $d = substr((string) ($p['date'] ?? ''), 0, 10);
            if ($d === '' || $d > $todayStr) {
                continue;
            }
            $a = (float) ($p['amount'] ?? 0);
            $firstYm = $firstYm === null ? substr($d, 0, 7) : min($firstYm, substr($d, 0, 7));
            if ($d <= $historyEnd) {
                $paidBefore += $a;
            } else {
                $events[] = [$d, $a];
            }
        }
        // Due in the history: from the member's first payment to the month before.
        $lastHistoryYm = late_add_months($fromYm, -1);
        $dueBefore = ($firstYm !== null && $firstYm <= $lastHistoryYm) ? cr_sum($rule, $firstYm, $lastHistoryYm) : 0.0;
        $carry = max(0.0, $paidBefore - $dueBefore);

        $paidUntil = static function (string $date) use ($events, $carry): float {
            $sum = $carry;
            foreach ($events as [$d, $a]) {
                if ($d <= $date) {
                    $sum += $a;
                }
            }
            return $sum;
        };
        $paidNow = $paidUntil($todayStr);

        $fineMonths = [];
        $reasons = [];
        $months = [];
        $due = 0.0;
        for ($ym = $fromYm; ; $ym = late_add_months($ym, 1)) {
            $fineDeadline = late_deadline($ym, $cfg['fine_day']);
            if ($fineDeadline >= $todayStr) {
                break; // this month's deadline has not passed yet (fines start the day after)
            }
            $due += cr_rate_for($rule, (int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
            $paidByDeadline = $paidUntil($fineDeadline);
            $late = $paidByDeadline + 0.005 < $due;

            if ($late && $cfg['fine'] > 0 && !isset($fines[$ym])) {
                $fineMonths[] = $ym;
            }
            // A fine created today is pending until a leader marks it.
            $fineStatus = $fines[$ym] ?? (in_array($ym, $fineMonths, true) ? 'pending' : null);

            $dormantDeadline = late_deadline($ym, $cfg['dormant_day']);
            if ($dormantDeadline < $todayStr) {
                $short = $paidNow + 0.005 < $due;
                if ($short || $fineStatus === 'pending') {
                    $reasons[] = $ym;
                }
            }
            $months[$ym] = ['due' => $due, 'paid_by_deadline' => $paidByDeadline, 'late' => $late, 'fine' => $fineStatus];
        }

        return [
            'fine_months' => $fineMonths,
            'dormant'     => $reasons !== [],
            'reasons'     => $reasons,
            'months'      => $months,
            'paid_now'    => $paidNow,
            'carried_in'  => $carry,
        ];
    }
}

if (!function_exists('late_month_label')) {
    /** 'Y-m' => 'November 2026' / 'Novemba 2026'. */
    function late_month_label(string $ym, bool $sw = false): string
    {
        $en = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $swn = ['Januari','Februari','Machi','Aprili','Mei','Juni','Julai','Agosti','Septemba','Oktoba','Novemba','Desemba'];
        $m = (int) substr($ym, 5, 2) - 1;
        return ($sw ? $swn[$m] : $en[$m]) . ' ' . substr($ym, 0, 4);
    }
}

if (!function_exists('late_run')) {
    /**
     * Apply the rule to every member. Idempotent: safe to run many times a day.
     *
     * @return array{fined:int, dormant:int, reactivated:int, skipped:bool}
     */
    function late_run(PDO $pdo, ?DateTimeInterface $today = null): array
    {
        $today = $today ?: new DateTime('today');
        $out = ['fined' => 0, 'dormant' => 0, 'reactivated' => 0, 'skipped' => true];

        $settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
        $cfg = late_settings($settings);
        if (!$cfg['enabled'] || $cfg['start_ym'] === null) {
            return $out;
        }
        $out['skipped'] = false;
        $rule = cr_monthly_rule($settings);

        // A member a leader reactivated is no longer "made dormant by the rule".
        $pdo->exec("UPDATE customers SET late_rule_dormant = 0 WHERE late_rule_dormant = 1 AND status <> 'dormant'");

        // Active members, plus the dormant ones this rule put there. Members a
        // leader made dormant, and deceased members, are left alone.
        $members = $pdo->query("
            SELECT customer_id, user_id, status, created_at, first_name, last_name
              FROM customers
             WHERE is_deceased = 0
               AND (status = 'active' OR (status = 'dormant' AND late_rule_dormant = 1))
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$members) {
            return $out;
        }

        // Every counted monthly payment: the history before the start month is
        // needed to carry forward money paid ahead.
        $payStmt = $pdo->query("
            SELECT member_id, contribution_date AS date, amount
              FROM contributions
             WHERE (contribution_type = 'monthly' OR contribution_type = '' OR contribution_type IS NULL)
               AND status IN ('pending','reviewed','approved','confirmed','')
        ");
        $payments = [];
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payments[(int) $r['member_id']][] = $r;
        }

        $fines = [];
        foreach ($pdo->query("SELECT customer_id, late_period, status FROM fines WHERE late_period IS NOT NULL")
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fines[(int) $r['customer_id']][$r['late_period']] = (string) $r['status'];
        }

        $insFine = $pdo->prepare("INSERT IGNORE INTO fines (customer_id, amount, reason, status, late_period, created_at)
                                  VALUES (?, ?, ?, 'pending', ?, NOW())");
        $setCust = $pdo->prepare("UPDATE customers SET status = ?, late_rule_dormant = ? WHERE customer_id = ?");
        $setUser = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (0, ?, 'System', NOW())");

        foreach ($members as $m) {
            $cid = (int) $m['customer_id'];
            $joinYm = !empty($m['created_at']) ? substr((string) $m['created_at'], 0, 7) : $cfg['start_ym'];
            $fromYm = max($cfg['start_ym'], $joinYm);
            $name = trim($m['first_name'] . ' ' . $m['last_name']);

            $j = late_judge_member($payments[$cid] ?? [], $rule, $fromYm, $fines[$cid] ?? [], $cfg, $today);

            foreach ($j['fine_months'] as $ym) {
                $reason = 'Late contribution: ' . late_month_label($ym) . ' / Kuchelewa mchango: ' . late_month_label($ym, true);
                $insFine->execute([$cid, $cfg['fine'], $reason, $ym]);
                if ($insFine->rowCount() > 0) {
                    $out['fined']++;
                    $log->execute(["Late fine of TZS " . number_format($cfg['fine'], 0) . " for $name (member #$cid), " . late_month_label($ym) . '.']);
                }
            }

            if ($j['dormant'] && $m['status'] === 'active') {
                $setCust->execute(['dormant', 1, $cid]);
                if (!empty($m['user_id'])) {
                    $setUser->execute(['dormant', (int) $m['user_id']]);
                }
                $out['dormant']++;
                $log->execute(["$name (member #$cid) made Dormant: unpaid " . implode(', ', array_map('late_month_label', $j['reasons'])) . '.']);
            } elseif (!$j['dormant'] && $m['status'] === 'dormant') {
                $setCust->execute(['active', 0, $cid]);
                if (!empty($m['user_id'])) {
                    $setUser->execute(['active', (int) $m['user_id']]);
                }
                $out['reactivated']++;
                $log->execute(["$name (member #$cid) is Active again: contributions and late fines paid."]);
            }
        }
        return $out;
    }
}
