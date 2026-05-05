<?php
// actions/auto_terminate_members.php
// This script runs on header load to check for members missing deadlines.

try {
    // 1. Fetch Group Settings
    $gs_stmt = $pdo->query("SELECT setting_key, setting_value FROM group_settings");
    $settings = $gs_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // EVEN if auto_termination is 'off', we still want to move to 'dormant' as requested.
    $is_enabled = true; 

    $deadline_day = intval($settings['deadline_day'] ?? 15);
    $deadline_time = $settings['deadline_time'] ?? '23:59';
    $monthly_amt = floatval($settings['monthly_contribution'] ?? 0);
    $entrance_amt = floatval($settings['entrance_fee'] ?? 0);
    $start_date = $settings['contribution_start_date'] ?? date('Y-01-01');
    $grace_days = intval($settings['contribution_grace_days'] ?? 0);

    // 2. Determine Current Cycle Timing
    $now = new DateTime();
    $current_day = (int)$now->format('d');
    $current_time = $now->format('H:i');

    // Add grace days to the deadline check
    $effective_deadline = $deadline_day + $grace_days;

    // Calculate months passed since start date
    $start_dt = new DateTime($start_date);
    $diff = $start_dt->diff($now);
    $total_months_now = ($diff->y * 12) + $diff->m + 1; // Months from start until current month

    // Determine how many months have completed their DEADLINES
    // If today is before THIS month's deadline, we only demand payment for PREVIOUS months.
    // If today is after THIS month's deadline, we demand payment including THIS month.
    
    $required_months = $total_months_now - 1; // Default to all previous months
    if ($current_day > $effective_deadline || ($current_day == $effective_deadline && $current_time > $deadline_time)) {
        $required_months = $total_months_now; // Current month's deadline has also passed
    }

    if ($required_months <= 0) return; // No completed deadlines yet

    // 3. Calculate Required Amount
    $required_total = $entrance_amt + ($required_months * $monthly_amt);

    // 4. Find & Move to Dormant
    // Note: We move anyone ACTIVE who hasn't reached the required total for completed months.
    // IMPROVED: Now includes c.initial_savings in the total calculation!
    $members_query = "
        SELECT c.customer_id, c.user_id, c.first_name, c.last_name, 
               (COALESCE(c.initial_savings, 0) + COALESCE(SUM(CASE WHEN con.status = 'confirmed' AND (con.contribution_type = 'monthly' OR con.contribution_type = 'entrance') THEN con.amount ELSE 0 END), 0)) as total_paid
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

    foreach ($late_members as $m) {
        $member_id = $m['customer_id'];
        $user_id = $m['user_id'];
        
        // MOVE TO DORMANT
        $pdo->prepare("UPDATE customers SET status = 'dormant' WHERE customer_id = ?")->execute([$member_id]);
        if ($user_id) {
            $pdo->prepare("UPDATE users SET status = 'dormant' WHERE user_id = ?")->execute([$user_id]);
        }
        
        // Log this action
        $log_msg = "Mwanachama #{$member_id} amehamishiwa Dormant kwa kuchelewa michango (Kiasi alicholipa: TZS " . number_format($m['total_paid'], 0) . " ikihitajika TZS " . number_format($required_total, 0) . ").";
        $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (0, ?, 'System', NOW())")->execute([$log_msg]);
    }

} catch (Exception $e) {
    // Fail silently in header to avoid breaking the UI
    error_log("Auto Termination Error: " . $e->getMessage());
}
