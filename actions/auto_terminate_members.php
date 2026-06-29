<?php
// actions/auto_terminate_members.php
//
// Sweeps active members who have missed their contribution deadlines into the
// 'dormant' status.
//
// This is heavy DB work (an aggregate over customers/contributions plus a write
// per late member), so it must NOT run on every page load (audit M4). header.php
// includes this file, but the sweep is throttled to run at most once per
// calendar day — only the first page hit of the day triggers it; every other
// request just does a single primary-key lookup. The sweep is idempotent (it
// only touches status='active' members), so the throttle is a pure performance
// win and re-running is harmless.
//
// A real cron job can run the sweep unconditionally from the CLI:
//     php actions/auto_terminate_members.php

if (!function_exists('vk_required_contribution_total')) {
    /**
     * Pure deadline math: the total each active member must have paid by now
     * (entrance fee + monthly dues for every deadline that has already passed).
     * Returns 0.0 when no deadline has completed yet.
     *
     * @param array    $settings group_settings as key => value
     * @param DateTime $now      current time (injectable for testing)
     */
    function vk_required_contribution_total(array $settings, DateTime $now): float {
        $deadline_day  = intval($settings['deadline_day'] ?? 15);
        $deadline_time = $settings['deadline_time'] ?? '23:59';
        $monthly_amt   = floatval($settings['monthly_contribution'] ?? 0);
        $entrance_amt  = floatval($settings['entrance_fee'] ?? 0);
        $start_date    = $settings['contribution_start_date'] ?? $now->format('Y-01-01');
        $grace_days    = intval($settings['contribution_grace_days'] ?? 0);

        $current_day  = (int) $now->format('d');
        $current_time = $now->format('H:i');
        $effective_deadline = $deadline_day + $grace_days;

        $start_dt = new DateTime($start_date);
        $diff = $start_dt->diff($now);
        $total_months_now = ($diff->y * 12) + $diff->m + 1;

        // If this month's deadline has passed, demand this month too; else only
        // the previous months.
        $required_months = $total_months_now - 1;
        if ($current_day > $effective_deadline
            || ($current_day === $effective_deadline && $current_time > $deadline_time)) {
            $required_months = $total_months_now;
        }

        if ($required_months <= 0) {
            return 0.0;
        }
        return $entrance_amt + ($required_months * $monthly_amt);
    }
}

if (!function_exists('vk_run_auto_termination')) {
    /**
     * Move every active, non-deceased member whose confirmed contributions fall
     * short of the required total into 'dormant'. Returns the number moved.
     * Idempotent — only status='active' members are affected.
     */
    function vk_run_auto_termination(PDO $pdo): int {
        $settings = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);

        $required_total = vk_required_contribution_total($settings, new DateTime());
        if ($required_total <= 0) {
            return 0;
        }

        // "Counts as paid" must use the same status set as the rest of the app
        // (finance.php, apply_for_loan.php, get_contribution_ledger.php,
        // manage_contributions.php): confirmed / approved / '' (legacy blank).
        // The live workflow is pending -> reviewed -> approved, so counting only
        // 'confirmed' here previously zeroed every real contribution and swept
        // fully paid-up members into dormant.
        $members_query = "
            SELECT c.customer_id, c.user_id, c.first_name, c.last_name,
                   (COALESCE(c.initial_savings, 0) + COALESCE(SUM(CASE WHEN con.status IN ('confirmed', 'approved', '') AND (con.contribution_type = 'monthly' OR con.contribution_type = 'entrance') THEN con.amount ELSE 0 END), 0)) as total_paid
            FROM customers c
            JOIN users u ON c.user_id = u.user_id
            LEFT JOIN contributions con ON c.customer_id = con.member_id
            WHERE c.status = 'active' AND c.is_deceased = 0
            GROUP BY c.customer_id, c.initial_savings
            HAVING total_paid < :required_total
        ";

        $stmt = $pdo->prepare($members_query);
        $stmt->execute(['required_total' => $required_total]);
        $late_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moved = 0;
        foreach ($late_members as $m) {
            $member_id = $m['customer_id'];
            $user_id   = $m['user_id'];

            $pdo->prepare("UPDATE customers SET status = 'dormant' WHERE customer_id = ?")->execute([$member_id]);
            if ($user_id) {
                $pdo->prepare("UPDATE users SET status = 'dormant' WHERE user_id = ?")->execute([$user_id]);
            }

            $log_msg = "Mwanachama #{$member_id} amehamishiwa Dormant kwa kuchelewa michango (Kiasi alicholipa: TZS "
                . number_format($m['total_paid'], 0) . " ikihitajika TZS " . number_format($required_total, 0) . ").";
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (0, ?, 'System', NOW())")
                ->execute([$log_msg]);
            $moved++;
        }
        return $moved;
    }
}

if (!function_exists('vk_auto_termination_due')) {
    /** True when the daily sweep has not yet run on $today ('Y-m-d'). */
    function vk_auto_termination_due(PDO $pdo, string $today): bool {
        $stmt = $pdo->prepare("SELECT setting_value FROM group_settings WHERE setting_key = 'auto_termination_last_run'");
        $stmt->execute();
        return $stmt->fetchColumn() !== $today;
    }
}

if (!function_exists('vk_mark_auto_termination_ran')) {
    /** Record that the sweep ran on $today (upsert on the group_settings PK). */
    function vk_mark_auto_termination_ran(PDO $pdo, string $today): void {
        $stmt = $pdo->prepare(
            "INSERT INTO group_settings (setting_key, setting_value)
             VALUES ('auto_termination_last_run', :today)
             ON DUPLICATE KEY UPDATE setting_value = :today2"
        );
        $stmt->execute([':today' => $today, ':today2' => $today]);
    }
}

// --- Entry points -------------------------------------------------------------
// Run only when invoked as a CLI script directly (cron), or when included by a
// web page that already has $pdo (header.php). When PHPUnit require()s this file
// neither applies, so the functions above stay testable without a database.

$__vk_cli_direct = (PHP_SAPI === 'cli')
    && isset($argv[0])
    && realpath($argv[0]) === __FILE__;

if ($__vk_cli_direct) {
    // cron / manual run: do the sweep unconditionally and record the date.
    require_once __DIR__ . '/../includes/config.php';
    try {
        $moved = vk_run_auto_termination($pdo);
        vk_mark_auto_termination_ran($pdo, date('Y-m-d'));
        fwrite(STDOUT, "Auto-termination sweep complete. Members moved to dormant: {$moved}\n");
    } catch (Exception $e) {
        fwrite(STDERR, "Auto Termination Error: " . $e->getMessage() . "\n");
        exit(1);
    }
} elseif (isset($pdo) && $pdo instanceof PDO) {
    // page-load include: throttle to once per calendar day.
    try {
        $today = date('Y-m-d');
        if (vk_auto_termination_due($pdo, $today)) {
            vk_mark_auto_termination_ran($pdo, $today); // mark first; sweep is idempotent
            vk_run_auto_termination($pdo);
        }
    } catch (Exception $e) {
        // Fail silently in the header to avoid breaking the UI.
        error_log("Auto Termination Error: " . $e->getMessage());
    }
}
