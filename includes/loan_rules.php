<?php
/**
 * includes/loan_rules.php
 * -----------------------
 * The loan arithmetic, in one place and with no database in it.
 *
 * Every figure a member is shown about a loan — how much they may borrow, what
 * each instalment is, what a late one costs — is worked out here, from the rules
 * the group saved on its loan types (Group Settings > Loan Types). Nothing in
 * this file knows a rate or a limit of its own.
 *
 * Conventions:
 *   - interest is PER MONTH, as a percentage (2 means 2% a month)
 *   - 'flat'     interest is charged on the amount borrowed, every month
 *   - 'reducing' interest is charged on what is still owed that month
 *   - both repay the principal in equal monthly parts
 *   - money is rounded to 2 decimals; a schedule's last line absorbs the
 *     rounding so the lines always add up to the totals exactly
 *
 * A loan stores a SNAPSHOT of its type's rules when it is applied for
 * (lr_snapshot()). Changing a rate in settings afterwards changes future loans
 * only — it must never rewrite what a member already agreed to.
 */

if (!function_exists('lr_interest_types')) {
    function lr_interest_types(): array { return ['flat', 'reducing']; }
}

if (!function_exists('lr_penalty_types')) {
    /** none = no late penalty; percent = % of the overdue amount; fixed = TZS. Both per month late. */
    function lr_penalty_types(): array { return ['none', 'percent', 'fixed']; }
}

if (!function_exists('lr_money')) {
    function lr_money(float $v): float { return round($v, 2); }
}

if (!function_exists('lr_validate_type')) {
    /**
     * A submitted loan type -> [clean row, errors].
     *
     * Blank optional limits are stored as "not set" (0 / null), never as a guess:
     * a share multiple of 0 means "no share-based limit", and no max amount means
     * "no fixed cap".
     *
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    function lr_validate_type(array $in): array
    {
        $errors = [];
        $num = static function ($v): ?float {
            $s = str_replace([',', ' '], '', trim((string) $v));
            return ($s !== '' && is_numeric($s)) ? (float) $s : null;
        };

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '')            $errors[] = 'Give the loan type a name.';
        elseif (mb_strlen($name) > 100) $errors[] = 'The name is too long (100 characters at most).';

        $rate = $num($in['interest_rate'] ?? '');
        if ($rate === null || $rate < 0 || $rate > 100) $errors[] = 'Interest rate must be between 0 and 100 (% per month).';

        $interestType = strtolower(trim((string) ($in['interest_type'] ?? '')));
        if (!in_array($interestType, lr_interest_types(), true)) $errors[] = 'Choose how interest is charged: flat or reducing.';

        $term = $num($in['max_term_months'] ?? '');
        if ($term === null || $term < 1 || $term > 120 || floor($term) != $term) $errors[] = 'Maximum period must be a whole number of months from 1 to 120.';

        $multiple = $num($in['max_share_multiple'] ?? '') ?? 0.0;
        if ($multiple < 0 || $multiple > 100) $errors[] = 'Share multiple must be between 0 and 100.';

        $maxAmount = $num($in['max_amount'] ?? '');
        if ($maxAmount !== null && $maxAmount < 0) $errors[] = 'Maximum amount cannot be negative.';
        if ($maxAmount !== null && $maxAmount == 0.0) $maxAmount = null;

        $guarantors = $num($in['guarantors_required'] ?? '') ?? 0.0;
        if ($guarantors < 0 || $guarantors > 10 || floor($guarantors) != $guarantors) $errors[] = 'Guarantors required must be a whole number from 0 to 10.';

        $penaltyType = strtolower(trim((string) ($in['penalty_type'] ?? 'none')));
        if (!in_array($penaltyType, lr_penalty_types(), true)) $errors[] = 'Choose a late-penalty rule.';

        $penaltyValue = $num($in['penalty_value'] ?? '') ?? 0.0;
        if ($penaltyType === 'none') {
            $penaltyValue = 0.0;
        } elseif ($penaltyValue <= 0) {
            $errors[] = 'Enter the late-penalty amount, or choose "no penalty".';
        } elseif ($penaltyType === 'percent' && $penaltyValue > 100) {
            $errors[] = 'A percentage penalty cannot be more than 100.';
        }

        $grace = $num($in['grace_days'] ?? '') ?? 0.0;
        if ($grace < 0 || $grace > 90 || floor($grace) != $grace) $errors[] = 'Grace days must be a whole number from 0 to 90.';

        return [[
            'name'                => $name,
            'description'         => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255),
            'interest_rate'       => (float) ($rate ?? 0),
            'interest_type'       => $interestType,
            'max_term_months'     => (int) ($term ?? 0),
            'max_share_multiple'  => (float) $multiple,
            'max_amount'          => $maxAmount,
            'guarantors_required' => (int) $guarantors,
            'penalty_type'        => $penaltyType,
            'penalty_value'       => (float) $penaltyValue,
            'grace_days'          => (int) $grace,
        ], $errors];
    }
}

if (!function_exists('lr_snapshot')) {
    /** The rules a loan carries for life, copied off its type at application. */
    function lr_snapshot(array $type): array
    {
        return [
            'type_name'           => (string) $type['name'],
            'interest_rate'       => (float) $type['interest_rate'],
            'interest_type'       => (string) $type['interest_type'],
            'guarantors_required' => (int) $type['guarantors_required'],
            'penalty_type'        => (string) $type['penalty_type'],
            'penalty_value'       => (float) $type['penalty_value'],
            'grace_days'          => (int) $type['grace_days'],
        ];
    }
}

if (!function_exists('lr_max_loan')) {
    /**
     * The most a member may borrow on a loan type right now, or null for no limit.
     *
     * The share limit is on TOTAL borrowing — share capital x multiple, less the
     * principal the member still owes on other loans. The fixed cap is per loan.
     * The lower of the two applies. $shareCapital must be share capital only:
     * the Social Fund and the membership deposit are not the member's to borrow
     * against (see includes/fund_buckets.php).
     */
    function lr_max_loan(float $shareCapital, array $type, float $outstandingPrincipal = 0.0): ?float
    {
        $limits = [];
        $multiple = (float) ($type['max_share_multiple'] ?? 0);
        if ($multiple > 0) {
            $limits[] = max(0.0, $shareCapital * $multiple - max(0.0, $outstandingPrincipal));
        }
        $cap = $type['max_amount'] ?? null;
        if ($cap !== null && (float) $cap > 0) {
            $limits[] = (float) $cap;
        }
        return $limits ? lr_money(min($limits)) : null;
    }
}

if (!function_exists('lr_add_months')) {
    /**
     * A date N months on, keeping the day of the month where the month has it:
     * the 31st + 1 month is the last day of the next month, not a spill into the
     * one after (PHP's "+1 month" turns 31 Jan into 3 Mar).
     */
    function lr_add_months(string $date, int $months): string
    {
        $d   = new DateTimeImmutable($date);
        $day = (int) $d->format('j');
        $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $last  = (int) $first->format('t');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))->format('Y-m-d');
    }
}

if (!function_exists('lr_schedule')) {
    /**
     * The repayment schedule: one line a month, first due a month after the start.
     *
     * @return array<int,array{seq:int,due_date:string,principal:float,interest:float,total:float,balance_after:float}>
     */
    function lr_schedule(float $amount, float $ratePct, string $interestType, int $months, string $startDate): array
    {
        if ($amount <= 0 || $months < 1) {
            return [];
        }
        $rate      = max(0.0, $ratePct) / 100;
        $principal = lr_money($amount / $months);
        $balance   = lr_money($amount);
        $lines     = [];

        for ($i = 1; $i <= $months; $i++) {
            // The last line takes whatever is left, so the parts sum to the loan.
            $p = ($i === $months) ? $balance : $principal;
            $interest = $interestType === 'reducing'
                ? lr_money($balance * $rate)
                : lr_money($amount * $rate);
            $balance = lr_money($balance - $p);
            $lines[] = [
                'seq'           => $i,
                'due_date'      => lr_add_months($startDate, $i),
                'principal'     => $p,
                'interest'      => $interest,
                'total'         => lr_money($p + $interest),
                'balance_after' => $balance,
            ];
        }
        return $lines;
    }
}

if (!function_exists('lr_schedule_totals')) {
    /** @return array{principal:float,interest:float,total:float} */
    function lr_schedule_totals(array $schedule): array
    {
        $p = lr_money(array_sum(array_column($schedule, 'principal')));
        $i = lr_money(array_sum(array_column($schedule, 'interest')));
        return ['principal' => $p, 'interest' => $i, 'total' => lr_money($p + $i)];
    }
}

if (!function_exists('lr_months_overdue')) {
    /**
     * Months an instalment is late, for the penalty. Nothing until the grace days
     * have passed; after that every started month counts as a whole one, counted
     * from the due date (the grace period delays the penalty, it does not move the
     * due date).
     */
    function lr_months_overdue(string $dueDate, string $asOf, int $graceDays = 0): int
    {
        $due = new DateTimeImmutable($dueDate);
        $now = new DateTimeImmutable($asOf);
        if ($now <= $due->modify('+' . max(0, $graceDays) . ' days')) {
            return 0;
        }
        $months = 0;
        while ($months < 600 && new DateTimeImmutable(lr_add_months($dueDate, $months)) < $now) {
            $months++;
        }
        return $months;
    }
}

if (!function_exists('lr_penalty')) {
    /** The late penalty on an overdue amount, for a number of months late. */
    function lr_penalty(float $overdueAmount, string $penaltyType, float $penaltyValue, int $monthsOverdue): float
    {
        if ($overdueAmount <= 0 || $monthsOverdue <= 0 || $penaltyValue <= 0) {
            return 0.0;
        }
        if ($penaltyType === 'percent') {
            return lr_money($overdueAmount * ($penaltyValue / 100) * $monthsOverdue);
        }
        if ($penaltyType === 'fixed') {
            return lr_money($penaltyValue * $monthsOverdue);
        }
        return 0.0;
    }
}
