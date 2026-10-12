<?php
/**
 * includes/fund_buckets.php
 * -------------------------
 * One authoritative place for WHICH POT a contribution belongs to.
 *
 * A VICOBA does not keep one pile of money. A member's payments split into:
 *
 *   shares       share capital — the member's own savings, and the only thing
 *                a year-end dividend is calculated on
 *   social_fund  Mfuko wa Jamii — the group's welfare pot. Not the member's
 *                savings, never part of a dividend
 *   deposit      membership deposit — held against the membership itself, kept
 *                apart from both of the above
 *
 * The pot is decided by `contributions.contribution_type`. Every screen that
 * needs to know "is this row savings?" asks here, so two screens cannot disagree
 * about a member's money (the defect includes/contribution_standing.php was
 * written to end).
 *
 * UNSET MEANS OFF. A group that has never saved a share price has no share
 * count — fb_share_count() returns 0 rather than dividing by an invented price.
 * Same rule as monthly_contribution in the standing module: a default that is
 * not the group's own number fabricates figures on their statements.
 */

if (!function_exists('fb_types')) {
    /** The contribution_type enum, as the column declares it. */
    function fb_types(): array
    {
        return ['entrance', 'monthly', 'agm', 'fine', 'other', 'share', 'social_fund', 'deposit'];
    }
}

if (!function_exists('fb_recordable_types')) {
    /**
     * Types a person may pick when recording a contribution.
     *
     * 'fine' is absent on purpose: a fine is recorded on the Fines page, in the
     * fines table. A contributions row typed 'fine' is money My Fines never sees.
     */
    function fb_recordable_types(): array
    {
        return ['monthly', 'share', 'social_fund', 'deposit', 'entrance', 'agm', 'other'];
    }
}

if (!function_exists('fb_ring_fenced_types')) {
    /** Money that is never a member's savings and never enters a dividend. */
    function fb_ring_fenced_types(): array
    {
        return ['social_fund', 'deposit'];
    }
}

if (!function_exists('fb_is_ring_fenced')) {
    function fb_is_ring_fenced(?string $type): bool
    {
        return in_array(strtolower(trim((string) $type)), fb_ring_fenced_types(), true);
    }
}

if (!function_exists('fb_savings_types')) {
    /** Types that ARE a member's savings (the statement / member-home definition). */
    function fb_savings_types(): array
    {
        return ['monthly', 'entrance', 'other', 'share'];
    }
}

if (!function_exists('fb_not_savings_types')) {
    /**
     * Types excluded from the group savings total and from member standing.
     *
     * This is the exclusion-style twin of fb_savings_types(), for the two group
     * queries that have always filtered by what to leave OUT (they admit 'agm',
     * a pre-existing divergence documented in contribution_standing.php and
     * deliberately not changed here).
     */
    function fb_not_savings_types(): array
    {
        return array_merge(['fine'], fb_ring_fenced_types());
    }
}

if (!function_exists('fb_sql_list')) {
    /**
     * A quoted SQL list — 'a','b' — from one of the type lists above.
     *
     * Only ever fed the fixed arrays in this file. Anything that is not a plain
     * lowercase identifier is dropped rather than quoted, so request data cannot
     * reach a query through here even by mistake.
     */
    function fb_sql_list(array $types): string
    {
        $safe = array_filter($types, static fn($t) => is_string($t) && preg_match('/^[a-z_]+$/', $t));
        return "'" . implode("','", $safe) . "'";
    }
}

if (!function_exists('fb_settings')) {
    /**
     * The fund-bucket rules out of a raw group_settings key/value map.
     *
     * Money keys keep 0.0 for "not set" — every consumer treats 0 as "this rule
     * is off", never as "the amount is nothing".
     *
     * @return array{share_price:float,shares_from_monthly:bool,social_fund_amount:float,membership_deposit_amount:float}
     */
    function fb_settings(array $raw): array
    {
        $money = static fn(string $k): float => is_numeric($raw[$k] ?? null) ? max(0.0, (float) $raw[$k]) : 0.0;
        return [
            'share_price'               => $money('share_price'),
            'shares_from_monthly'       => ($raw['shares_from_monthly'] ?? 'off') === 'on',
            'social_fund_amount'        => $money('social_fund_amount'),
            'membership_deposit_amount' => $money('membership_deposit_amount'),
        ];
    }
}

if (!function_exists('fb_share_types')) {
    /**
     * Which types are share capital.
     *
     * Many groups do not sell shares separately: the regular cycle contribution
     * IS the share purchase. With that switched on, 'monthly' rows count as
     * share capital alongside explicit 'share' rows.
     */
    function fb_share_types(bool $sharesFromMonthly): array
    {
        return $sharesFromMonthly ? ['share', 'monthly'] : ['share'];
    }
}

if (!function_exists('fb_bucket_for_type')) {
    /** The pot a contribution type lands in, or null when it belongs to none. */
    function fb_bucket_for_type(?string $type, bool $sharesFromMonthly): ?string
    {
        $t = strtolower(trim((string) $type));
        if (in_array($t, fb_share_types($sharesFromMonthly), true)) return 'shares';
        if ($t === 'social_fund') return 'social_fund';
        if ($t === 'deposit')     return 'deposit';
        return null;
    }
}

if (!function_exists('fb_share_count')) {
    /** Shares held for a given share capital. No price set => no count. */
    function fb_share_count(float $capital, float $sharePrice): float
    {
        if ($sharePrice <= 0 || $capital <= 0) return 0.0;
        return round($capital / $sharePrice, 2);
    }
}

if (!function_exists('fb_balances_from_totals')) {
    /**
     * Per-pot balances from a contribution_type => total map. Pure — no DB.
     *
     * @param array<string,float|string> $typeTotals
     * @return array{shares:float,share_count:float,social_fund:float,deposit:float}
     */
    function fb_balances_from_totals(array $typeTotals, array $settings): array
    {
        $out = ['shares' => 0.0, 'social_fund' => 0.0, 'deposit' => 0.0];
        foreach ($typeTotals as $type => $total) {
            $bucket = fb_bucket_for_type((string) $type, (bool) $settings['shares_from_monthly']);
            if ($bucket !== null) {
                $out[$bucket] += (float) $total;
            }
        }
        return [
            'shares'      => $out['shares'],
            'share_count' => fb_share_count($out['shares'], (float) $settings['share_price']),
            'social_fund' => $out['social_fund'],
            'deposit'     => $out['deposit'],
        ];
    }
}

if (!function_exists('fb_load_settings')) {
    /** fb_settings() straight from the database. */
    function fb_load_settings(PDO $pdo): array
    {
        $raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        return fb_settings($raw ?: []);
    }
}

if (!function_exists('fb_member_balances')) {
    /** One member's balance in each pot (approved money only). */
    function fb_member_balances(PDO $pdo, int $customerId, ?array $settings = null): array
    {
        $settings = $settings ?? fb_load_settings($pdo);
        if ($customerId <= 0) {
            return fb_balances_from_totals([], $settings);
        }
        $st = $pdo->prepare(
            "SELECT contribution_type, COALESCE(SUM(amount), 0)
               FROM contributions
              WHERE member_id = ?
                AND status IN ('confirmed', 'approved', '')
              GROUP BY contribution_type"
        );
        $st->execute([$customerId]);
        return fb_balances_from_totals($st->fetchAll(PDO::FETCH_KEY_PAIR), $settings);
    }
}

if (!function_exists('fb_group_balances')) {
    /**
     * The whole group's balance in each pot (approved money only).
     *
     * Member-scoped with the same join as cs_group_savings_total(), so the share
     * capital here is the sum of every member's shares exactly.
     */
    function fb_group_balances(PDO $pdo, ?array $settings = null): array
    {
        $settings = $settings ?? fb_load_settings($pdo);
        $rows = $pdo->query(
            "SELECT co.contribution_type, COALESCE(SUM(co.amount), 0)
               FROM contributions co
               JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'
              WHERE co.status IN ('confirmed', 'approved', '')
              GROUP BY co.contribution_type"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return fb_balances_from_totals($rows, $settings);
    }
}

if (!function_exists('fb_type_labels')) {
    /**
     * Display names for the recordable types.
     *
     * @return array<string,string> type => label
     */
    function fb_type_labels(bool $isSw): array
    {
        return [
            'monthly'     => $isSw ? 'Mchango wa Mwezi' : 'Monthly',
            'share'       => $isSw ? 'Hisa' : 'Shares',
            'social_fund' => $isSw ? 'Mfuko wa Jamii' : 'Social Fund',
            'deposit'     => $isSw ? 'Amana ya Uanachama' : 'Membership Deposit',
            'entrance'    => $isSw ? 'Ada ya Kujiunga' : 'Entrance',
            'agm'         => $isSw ? 'Mkutano Mkuu (AGM)' : 'AGM',
            'other'       => $isSw ? 'Nyingine' : 'Other',
        ];
    }
}
