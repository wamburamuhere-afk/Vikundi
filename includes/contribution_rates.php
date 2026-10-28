<?php
/**
 * includes/contribution_rates.php — the monthly contribution as it changed over time.
 *
 * Most groups have one monthly amount (group_settings.monthly_contribution) and
 * nothing here changes for them. A group whose amount changed (UKUU: 5,000 a month
 * from the start, 10,000 from June 2026) keeps a rate history in
 * group_settings.contribution_rates as JSON:
 *
 *     [{"from":null,"amount":5000},{"from":"2026-06","amount":10000}]
 *
 * The first row has no month: it applies "from the start". Each later row applies
 * from its month onward. monthly_contribution is kept equal to the rate in force
 * today (cr_sync_current()), so every screen that only shows "the monthly amount"
 * stays right without knowing about the history.
 *
 * A "monthly rule" is what the standing module takes: a float (one flat amount,
 * the old behaviour, unchanged) or a plan (the list above, normalised).
 */

if (!function_exists('cr_normalise')) {
    /**
     * Clean a list of rows into a plan: amounts >= 0, months as 'Y-m', sorted, the
     * first row "from the start" (from = null). Later rows without a valid month
     * are dropped. Returns [] when there is nothing usable.
     *
     * @param array<int,array{from:?string,amount:mixed}> $rows
     */
    function cr_normalise(array $rows): array
    {
        $base = null;
        $dated = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['amount']) || !is_numeric($r['amount'])) {
                continue;
            }
            $amount = max(0.0, (float) $r['amount']);
            $from = trim((string) ($r['from'] ?? ''));
            if ($from === '') {
                $base = $amount;
                continue;
            }
            if (!preg_match('/^(\d{4})-(\d{2})$/', $from, $mm) || (int) $mm[2] < 1 || (int) $mm[2] > 12) {
                continue;
            }
            $dated[$from] = $amount; // a later row for the same month wins
        }
        ksort($dated);
        if ($base === null && !$dated) {
            return [];
        }
        if ($base === null) {
            // No "from the start" row: the earliest change also covers earlier months.
            $base = reset($dated);
        }
        $plan = [['from' => null, 'amount' => $base]];
        foreach ($dated as $from => $amount) {
            $plan[] = ['from' => $from, 'amount' => $amount];
        }
        return $plan;
    }
}

if (!function_exists('cr_monthly_rule')) {
    /**
     * The group's monthly rule from its settings: a plan when a rate history is
     * saved, otherwise the flat monthly_contribution (0 = no fixed target).
     *
     * @return float|array
     */
    function cr_monthly_rule(array $settings)
    {
        $json = $settings['contribution_rates'] ?? '';
        if (is_string($json) && trim($json) !== '') {
            $plan = cr_normalise((array) json_decode($json, true));
            if (count($plan) > 1) {
                return $plan;
            }
            if (count($plan) === 1) {
                return (float) $plan[0]['amount'];
            }
        }
        return (float) ($settings['monthly_contribution'] ?? 0);
    }
}

if (!function_exists('cr_rate_for')) {
    /** The monthly amount due for month $y-$m under a rule (float or plan). */
    function cr_rate_for($rule, int $y, int $m): float
    {
        if (!is_array($rule)) {
            return (float) $rule;
        }
        $ym = sprintf('%04d-%02d', $y, $m);
        $rate = 0.0;
        foreach ($rule as $row) {
            if ($row['from'] === null || $row['from'] <= $ym) {
                $rate = (float) $row['amount'];
            } else {
                break;
            }
        }
        return $rate;
    }
}

if (!function_exists('cr_rate_at')) {
    /** cr_rate_for() for the month of a date (today when null). */
    function cr_rate_at($rule, ?DateTimeInterface $when = null): float
    {
        $when = $when ?: new DateTime('today');
        return cr_rate_for($rule, (int) $when->format('Y'), (int) $when->format('n'));
    }
}

if (!function_exists('cr_sum')) {
    /**
     * Total due for the months from $fromYm to $toYm inclusive ('Y-m' or any date
     * strtotime reads). 0 when the range is empty.
     */
    function cr_sum($rule, string $fromYm, string $toYm): float
    {
        $a = strtotime(substr($fromYm, 0, 7) . '-01');
        $b = strtotime(substr($toYm, 0, 7) . '-01');
        if ($a === false || $b === false || $a > $b) {
            return 0.0;
        }
        $y = (int) date('Y', $a);
        $m = (int) date('n', $a);
        $endY = (int) date('Y', $b);
        $endM = (int) date('n', $b);
        $total = 0.0;
        while ($y < $endY || ($y === $endY && $m <= $endM)) {
            $total += cr_rate_for($rule, $y, $m);
            if (++$m > 12) { $m = 1; $y++; }
        }
        return $total;
    }
}

if (!function_exists('cr_validate')) {
    /**
     * Check rows typed into Group Settings before saving. Returns a list of error
     * messages (empty = fine) in the requested language.
     */
    function cr_validate(array $rows, bool $sw = false): array
    {
        $errors = [];
        $seen = [];
        $hasBase = false;
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $from = trim((string) ($r['from'] ?? ''));
            $amount = $r['amount'] ?? '';
            if (!is_numeric($amount) || (float) $amount < 0) {
                $errors[] = $sw ? "Mstari $n: weka kiasi sahihi." : "Row $n: enter a valid amount.";
            }
            if ($from === '') {
                if ($hasBase) {
                    $errors[] = $sw ? "Mstari $n: mstari mmoja tu unaweza kuwa \"tangu mwanzo\"." : "Row $n: only one row can be \"from the start\".";
                }
                $hasBase = true;
                continue;
            }
            if (!preg_match('/^(\d{4})-(\d{2})$/', $from, $mm) || (int) $mm[2] < 1 || (int) $mm[2] > 12) {
                $errors[] = $sw ? "Mstari $n: mwezi si sahihi." : "Row $n: the month is not valid.";
                continue;
            }
            if (isset($seen[$from])) {
                $errors[] = $sw ? "Mstari $n: mwezi $from umerudiwa." : "Row $n: $from appears twice.";
            }
            $seen[$from] = true;
        }
        return $errors;
    }
}

if (!function_exists('cr_sync_current')) {
    /**
     * Keep monthly_contribution equal to the rate in force today, so screens that
     * only show "the monthly amount" follow the history. No-op without a history.
     * Returns the current rate, or null when the group has no history.
     */
    function cr_sync_current(PDO $pdo, ?DateTimeInterface $today = null): ?float
    {
        $settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
        $rule = cr_monthly_rule($settings);
        if (!is_array($rule)) {
            return null;
        }
        $rate = cr_rate_at($rule, $today);
        if ((string) ($settings['monthly_contribution'] ?? '') !== (string) $rate) {
            $pdo->prepare("INSERT INTO group_settings (setting_key, setting_value) VALUES ('monthly_contribution', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([(string) $rate]);
        }
        return $rate;
    }
}
