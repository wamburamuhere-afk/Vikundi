<?php
/**
 * Automated Contribution Reminders
 * Triggers: 3 days, 2 days, 1 day, and 1 hour before due date.
 * Respects member's preferred language.
 */

// Use absolute paths for CLI compatibility
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/sms_helper.php';

// 1. Load Group Settings
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'group_settings'");
$group_settings = json_decode($stmt->fetchColumn() ?: '{}', true);

$due_day = (int)($group_settings['due_day'] ?? 5);
$due_time = $group_settings['due_time'] ?? '17:00';
$monthly_rate = (float)($group_settings['monthly_rate'] ?? 0);

if ($monthly_rate <= 0) {
    die("Monthly contribution rate is not set. Skipping reminders.\n");
}

// 2. Calculate Due Date for Current Month
$now = new DateTime();
$current_month = $now->format('Y-m');
$due_date = new DateTime($current_month . '-' . sprintf('%02d', $due_day) . ' ' . $due_time);

// If today is past the due date, look for NEXT month? 
// No, typically reminders are for the upcoming deadline in the current month.
// But if it's the 31st and due day is 5th, it's next month.
if ($now > $due_date) {
    $due_date->add(new DateInterval('P1M'));
    $current_month = $due_date->format('Y-m');
}

$due_timestamp = $due_date->getTimestamp();
$now_timestamp = $now->getTimestamp();
$diff_seconds = $due_timestamp - $now_timestamp;
$diff_hours = $diff_seconds / 3600;
$diff_days = round($diff_hours / 24);

// 3. Determine Trigger Type
$trigger = null;
if ($diff_hours > 71 && $diff_hours <= 73) $trigger = '3_days';
elseif ($diff_hours > 47 && $diff_hours <= 49) $trigger = '2_days';
elseif ($diff_hours > 23 && $diff_hours <= 25) $trigger = '1_day';
elseif ($diff_hours > 0.5 && $diff_hours <= 1.5) $trigger = '1_hour';

if (!$trigger) {
    echo "No trigger active at this time (" . round($diff_hours, 2) . " hours remaining). Skipping.\n";
    exit();
}

echo "Active Trigger: $trigger\n";

// 4. Create tracking table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS auto_reminder_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    reminder_type VARCHAR(20) NOT NULL,
    for_month VARCHAR(7) NOT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 5. Get members who haven't paid for the relevant month
// We join with users to get preferred_language
$query = "
    SELECT c.customer_id, c.first_name, c.phone, u.preferred_language, u.user_id
    FROM customers c
    JOIN users u ON c.user_id = u.user_id
    WHERE c.status = 'active'
    AND NOT EXISTS (
        SELECT 1 FROM contributions con 
        WHERE con.member_id = c.customer_id 
        AND DATE_FORMAT(con.contribution_date, '%Y-%m') = :month
        AND con.status = 'approved'
    )
    AND NOT EXISTS (
        SELECT 1 FROM auto_reminder_logs l
        WHERE l.customer_id = c.customer_id
        AND l.reminder_type = :trigger
        AND l.for_month = :month
    )
";

$stmt = $pdo->prepare($query);
$stmt->execute(['month' => $current_month, 'trigger' => $trigger]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($members as $member) {
    $lang = $member['preferred_language'] ?: 'sw';
    $name = $member['first_name'];
    $phone = $member['phone'];
    
    // 6. Build Message
    if ($lang === 'sw') {
        if ($trigger === '1_hour') {
            $msg = "Habari $name, mda wa mchango wa mwezi huu unaisha mda wa LISAA LIMOJA kuanzia sasa. Tafadhali lipia mchango wako sasa kuepuka faini.";
        } else {
            $days = ($trigger === '3_days' ? '3' : ($trigger === '2_days' ? '2' : '1'));
            $msg = "Habari $name, mda wa kulipa michango ya mwezi huu unakaribia kuisha (Umebakia siku $days). Tafadhali lipia mchango wako mapema kuepuka usumbufu.";
        }
    } else {
        if ($trigger === '1_hour') {
            $msg = "Hello $name, the deadline for this month's contribution is in ONE HOUR. Please make your payment now to avoid late fees.";
        } else {
            $days = ($trigger === '3_days' ? '3' : ($trigger === '2_days' ? '2' : '1'));
            $msg = "Hello $name, the deadline for this month's contributions is approaching (only $days days left). Please pay early to avoid inconvenience.";
        }
    }

    // 7. Send SMS
    $result = send_sms($phone, $msg);
    
    if ($result['success']) {
        // 8. Log the reminder
        $log_stmt = $pdo->prepare("INSERT INTO auto_reminder_logs (customer_id, reminder_type, for_month) VALUES (?, ?, ?)");
        $log_stmt->execute([$member['customer_id'], $trigger, $current_month]);
        echo "Sent $trigger reminder to $name ($phone) in $lang.\n";
    } else {
        echo "Failed to send to $name: " . $result['message'] . "\n";
    }
}

echo "Reminder task completed.\n";
