<?php
// helpers.php

function calculateTotalInterest($amount, $rate, $term, $formula) {
    switch ($formula) {
        case 'Flat Rate':
            return $amount * ($rate / 100) * ($term / 12);

        case 'Reducing Balance':
        case 'EMI':
        default:
            $monthlyRate = $rate / 100 / 12;
            if ($monthlyRate == 0) return 0; // no interest case
            $emi = ($amount * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$term));
            $totalPayment = $emi * $term;
            return $totalPayment - $amount;
    }
}


/*function calculateTotalInterest($amount, $rate, $term, $formula) {
    switch ($formula) {
        case 'Flat Rate':
            return $amount * ($rate / 100) * ($term / 12);
        case 'Reducing Balance':
            $monthlyRate = $rate / 100 / 12;
            return ($amount * $monthlyRate * $term) / (1 - pow(1 + $monthlyRate, -$term)) - $amount;
        case 'EMI':
        default:
            $monthlyRate = $rate / 100 / 12;
            return ($amount * $monthlyRate * $term) / (1 - pow(1 + $monthlyRate, -$term)) * $term - $amount;
    }
}
*/
/*function createRepaymentSchedule($pdo, $loan_id, $amount, $rate, $term, 
                               $repayment_cycle_id, $start_date, $formula, $grace_period = 0) {
    // Validate and sanitize inputs
    if (!is_numeric($amount) || $amount <= 0) {
        throw new Exception("Invalid loan amount");
    }

    // Parse interest rate (handle formats like 0.25, 25%, P0.25M)
    $rate = str_replace(['%', 'M', 'P'], '', (string)$rate);
    if (!is_numeric($rate)) {
        throw new Exception("Invalid interest rate format");
    }
    $rate = (float)$rate;
    if ($rate > 1) { // Assume percentage if > 1 (e.g., 25 vs 0.25)
        $rate = $rate / 100;
    }

    if (!is_numeric($term) || $term <= 0) {
        throw new Exception("Invalid loan term");
    }

    // Get repayment cycle details
    $stmt = $pdo->prepare("SELECT cycle FROM repayment_cycles WHERE id = ?");
    $stmt->execute([$repayment_cycle_id]);
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cycle) {
        throw new Exception("Invalid repayment cycle ID");
    }
    $cycle = $cycle['cycle'];

    // Determine payment frequency in months
    $frequency = 1; // Default to monthly
    $cycle_days = 30; // Default days between payments
    switch (strtolower($cycle)) {
        case 'weekly':
            $frequency = 1/4;
            $cycle_days = 7;
            break;
        case 'bi-weekly':
            $frequency = 1/2;
            $cycle_days = 14;
            break;
        case 'quarterly':
            $frequency = 3;
            $cycle_days = 90;
            break;
        case 'semi-annual':
            $frequency = 6;
            $cycle_days = 180;
            break;
        case 'annual':
            $frequency = 12;
            $cycle_days = 365;
            break;
    }

    // Calculate payment schedule
    $payment_date = new DateTime($start_date);
    $balance = (float)$amount;
    $monthlyRate = $rate / 12;
    $periodicRate = $monthlyRate * $frequency;
    $num_payments = ceil($term / $frequency);


    if ($cycle_days > 0) {
            $payment_date->add(new DateInterval("P{$cycle_days}D"));
        } else {
            $payment_date->add(new DateInterval("P{$frequency}M"));
        }

    // Calculate payment amount based on formula
    if ($formula === 'Flat Rate') {
        $principal = $amount / $num_payments;
        $flat_interest = ($amount * $rate * ($term / 12)) / $num_payments;
        $payment_amount = $principal + $flat_interest;
    } else {
        // EMI or Reducing Balance
        $payment_amount = ($amount * $periodicRate) / (1 - pow(1 + $periodicRate, -$num_payments));
    }

    // Apply grace period if specified
    if ($grace_period > 0) {
        $payment_date->add(new DateInterval("P{$grace_period}D"));
    }

    // Prepare the repayment schedule insert statement
    $stmt = $pdo->prepare("
        INSERT INTO loan_repayment_schedule (
            loan_id, payment_number, due_date, principal_amount, 
            interest_amount, total_amount, remaining_balance, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");

    // Generate each payment in the schedule
    for ($i = 1; $i <= $num_payments; $i++) {
        if ($formula === 'Flat Rate') {
            $interest = $flat_interest;
            $principal_payment = $principal;
        } else {
            $interest = $balance * $periodicRate;
            $principal_payment = $payment_amount - $interest;
        }

        // Adjust final payment to clear remaining balance
        if ($i === $num_payments) {
            $principal_payment = $balance;
            $payment_amount = $principal_payment + $interest;
        }

        $remaining_balance = $balance - $principal_payment;

        $stmt->execute([
            $loan_id,
            $i,
            $payment_date->format('Y-m-d'),
            round($principal_payment, 2),
            round($interest, 2),
            round($payment_amount, 2),
            round(max(0, $remaining_balance), 2),
        ]);

        // Move to next payment date
        if ($cycle_days > 0) {
            $payment_date->add(new DateInterval("P{$cycle_days}D"));
        } else {
            $payment_date->add(new DateInterval("P{$frequency}M"));
        }
        $balance = $remaining_balance;
    }

    return true;
}*/

function addMonthsWithAnchor(DateTime $date, int $months, int $anchorDay): DateTime
{
    // Always move from first of month to avoid overflow
    $base = new DateTime($date->format('Y-m-01'));
    $base->add(new DateInterval("P{$months}M"));

    $lastDay = (int)$base->format('t');

    // Use anchor day if possible, otherwise last day of month
    $day = min($anchorDay, $lastDay);

    $base->setDate(
        (int)$base->format('Y'),
        (int)$base->format('m'),
        $day
    );

    return $base;
}

function createRepaymentSchedule(
    $pdo,
    $loan_id,
    $amount,
    $rate,
    $term, // months
    $repayment_cycle_id,
    $start_date,
    $formula,
    $grace_period = 0
) {
    if (!is_numeric($amount) || $amount <= 0) {
        throw new Exception("Invalid loan amount");
    }

    // Normalize rate
    $rate = str_replace(['%', 'M', 'P'], '', (string)$rate);
    if (!is_numeric($rate)) {
        throw new Exception("Invalid interest rate");
    }
    $rate = (float)$rate;
    if ($rate > 1) $rate /= 100;

    if (!is_numeric($term) || $term <= 0) {
        throw new Exception("Invalid loan term");
    }

    // Get repayment cycle
    $stmt = $pdo->prepare("SELECT cycle FROM repayment_cycles WHERE id = ?");
    $stmt->execute([$repayment_cycle_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Invalid repayment cycle");
    }

    $cycle = strtolower($row['cycle']);

    // Anchor date
    $start = new DateTime($start_date);
    $anchorDay = (int)$start->format('d'); // NEVER changes
    $balance = (float)$amount;

    /**
     * SAFE MONTH ADDER WITH ANCHOR DAY
     */
    $addMonthsWithAnchor = function (DateTime $date, int $months) use ($anchorDay) {
        $base = new DateTime($date->format('Y-m-01'));
        $base->add(new DateInterval("P{$months}M"));

        $lastDay = (int)$base->format('t');
        $day = min($anchorDay, $lastDay);

        $base->setDate(
            (int)$base->format('Y'),
            (int)$base->format('m'),
            $day
        );

        return $base;
    };

    /**
     * NUMBER OF PAYMENTS (FINANCIAL LOGIC)
     */
    switch ($cycle) {
        case 'weekly':
            $num_payments = $term * 4;
            $stepDays = 7;
            $stepMonths = 0;
            break;

        case 'bi-weekly':
            $num_payments = $term * 2;
            $stepDays = 14;
            $stepMonths = 0;
            break;

        case 'monthly':
            $num_payments = $term;
            $stepMonths = 1;
            $stepDays = 0;
            break;

        case 'quarterly':
            $num_payments = ceil($term / 3);
            $stepMonths = 3;
            $stepDays = 0;
            break;

        case 'semi-annual':
            $num_payments = ceil($term / 6);
            $stepMonths = 6;
            $stepDays = 0;
            break;

        case 'annual':
            $num_payments = ceil($term / 12);
            $stepMonths = 12;
            $stepDays = 0;
            break;

        default:
            throw new Exception("Unsupported repayment cycle");
    }

    /**
     * INTEREST RATE PER PERIOD
     */
    $monthlyRate = $rate / 12;

    if ($stepDays > 0) {
        // Financial weeks
        $periodicRate = $monthlyRate * ($stepDays / 28); // 4 weeks = 28 days
    } else {
        $periodicRate = $monthlyRate * $stepMonths;
    }

    /**
     * FIRST PAYMENT DATE
     */
    if ($stepDays > 0) {
        $payment_date = clone $start;
        $payment_date->add(new DateInterval("P{$stepDays}D"));
    } else {
        $payment_date = $addMonthsWithAnchor($start, $stepMonths);
    }

    if ($grace_period > 0) {
        $payment_date->add(new DateInterval("P{$grace_period}D"));
    }

    /**
     * PAYMENT AMOUNT
     */
    if ($formula === 'Flat Rate') {
        $principal = $amount / $num_payments;
        $flat_interest = ($amount * $rate * ($term / 12)) / $num_payments;
        $payment_amount = $principal + $flat_interest;
    } else {
        $payment_amount = ($amount * $periodicRate) /
            (1 - pow(1 + $periodicRate, -$num_payments));
    }

    /**
     * INSERT STATEMENT
     */
    $stmt = $pdo->prepare("
        INSERT INTO loan_repayment_schedule (
            loan_id,
            payment_number,
            due_date,
            principal_amount,
            interest_amount,
            total_amount,
            remaining_balance,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");

    /**
     * GENERATE SCHEDULE
     */
    for ($i = 1; $i <= $num_payments; $i++) {

        if ($formula === 'Flat Rate') {
            $interest = $flat_interest;
            $principal_payment = $principal;
        } else {
            $interest = $balance * $periodicRate;
            $principal_payment = $payment_amount - $interest;
        }

        if ($i === $num_payments) {
            $principal_payment = $balance;
            $payment_amount = $principal_payment + $interest;
        }

        $remaining_balance = $balance - $principal_payment;

        $stmt->execute([
            $loan_id,
            $i,
            $payment_date->format('Y-m-d'),
            round($principal_payment, 2),
            round($interest, 2),
            round($payment_amount, 2),
            round($remaining_balance, 2)
        ]);

        // Next due date
        if ($stepDays > 0) {
            $payment_date->add(new DateInterval("P{$stepDays}D"));
        } else {
            $payment_date = $addMonthsWithAnchor($payment_date, $stepMonths);
        }

        $balance = $remaining_balance;
    }

    return true;
}




function generateReceipt($payment_id) {
    // In a real implementation, you would:
    // 1. Generate PDF using a library like TCPDF or Dompdf
    // 2. Save to filesystem or database
    // 3. Return download URL
    
    return "receipts/receipt_$payment_id.pdf";
}

function get_status_badge($status) {
    switch (strtolower($status)) {
        case 'active':
        case 'approved':
        case 'completed':
        case 'success':
        case 'present':
            return 'success';
        case 'pending':
        case 'waiting':
        case 'suspended':
        case 'probation':
            return 'warning';
        case 'inactive':
        case 'draft':
        case 'closed':
        case 'resigned':
        case 'contract':
        case 'holiday':
        case 'other':
            return 'secondary';
        case 'rejected':
        case 'blacklisted':
        case 'cancelled':
        case 'deleted':
        case 'danger':
        case 'failed':
        case 'terminated':
        case 'absent':
        case 'void':
        case 'disputed':
            return 'danger';
        case 'paid':
        case 'info':
        case 'on_leave':
        case 'taken':
        case 'half_day':
        case 'reversed':
            return 'info';
        case 'partial':
        case 'partially_paid':
        case 'partially_delivered':
        case 'processing':
        case 'received':
        case 'leave':
            return 'primary';
        case 'ordered':
            return 'info';
        case 'delivered':
        case 'shipped':
        case 'posted':
        case 'reconciled':
            return 'success';
        case 'weekend':
            return 'dark';
        default:
            return 'secondary';
    }
}

function get_attendance_badge($status) {
    switch ($status) {
        case 'present': return 'success';
        case 'absent': return 'danger';
        case 'late': return 'warning';
        case 'half_day': return 'info';
        case 'leave': return 'primary';
        case 'holiday': return 'secondary';
        case 'weekend': return 'dark';
        default: return 'secondary';
    }
}

function get_type_badge($type) {
    switch (strtolower($type)) {
        case 'annual': return 'primary';
        case 'sick': return 'info';
        case 'maternity': return 'success';
        case 'paternity': return 'primary';
        case 'casual': return 'warning';
        case 'emergency': return 'danger';
        case 'study': return 'dark';
        case 'unpaid': return 'secondary';
        default: return 'secondary';
    }
}

function calculate_leave_days($start_date, $end_date) {
    if (empty($start_date) || empty($end_date)) return 0;
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date
    
    $interval = $start->diff($end);
    return $interval->days;
}

function calculate_age($birth_date) {
    if (empty($birth_date)) return 'N/A';
    $birth = new DateTime($birth_date);
    $today = new DateTime();
    $age = $today->diff($birth);
    return $age->y;
}

function format_phone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 9) {
        return '255' . $phone;
    }
    return $phone;
}

function get_variance_color($variance) {
    if ($variance > 0) return 'success'; // Under budget
    if ($variance < 0) return 'danger';  // Over budget
    return 'info'; // On budget
}

function format_currency($amount, $currency = 'TZS') {
    $symbols = [
        'TZS' => 'TSh ',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'KES' => 'KSh '
    ];
    $symbol = $symbols[$currency] ?? 'TSh ';
    return $symbol . number_format($amount, 2);
}

function safe_output($value, $default = 'N/A') {
    return !empty($value) ? htmlspecialchars($value) : $default;
}

function format_date($date, $format = 'd M Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

if (!function_exists('generate_receipt_number')) {
    function generate_receipt_number() {
        return 'RCP-' . date('Ymd') . '-' . rand(1000, 9999);
    }
}

if (!function_exists('format_number')) {
    function format_number($number, $decimals = 2) {
        return number_format((float)$number, $decimals);
    }
}

/**
 * Generates the standardized Print Header
 */
function getPrintHeader($heading = '', $member_info = '') {
    global $group_logo, $group_name;
    $logo = htmlspecialchars($group_logo ?? 'logo1.png');
    $name = htmlspecialchars($group_name ?? 'KIKUNDI');
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $print_date_label = $is_sw ? 'Tarehe ya Printi:' : 'Print Date:';
    $print_date = date('d m, Y H:i');
    
    return "
    <div class='d-none d-print-block'>
        <div class='text-center mb-4'>
            <img src='/assets/images/$logo' alt='Logo' style='height: 80px; width: auto; margin-bottom: 10px; object-fit: contain;'>
            <h2 class='fw-bold mb-1 text-uppercase' style='color: #0d6efd !important;'>$name</h2>
            <h4 class='fw-bold text-dark text-uppercase border-top border-bottom py-2 mt-2'>$heading</h4>
            " . ($member_info ? "<div class='small text-muted mt-1'>$member_info</div>" : "") . "
            <div class='small text-muted mt-1'>$print_date_label $print_date</div>
        </div>
    </div>";
}

/**
 * Generates the standardized Print Footer
 */
function getPrintFooter() {
    $username = htmlspecialchars($_SESSION['username'] ?? 'User');
    $user_role = htmlspecialchars($_SESSION['role_name'] ?? 'Member');
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $printed_by_label = $is_sw ? 'Nyaraka hii imechapishwa na' : 'This document was printed by';
    $on_label = $is_sw ? 'mnamo' : 'on';
    $at_label = $is_sw ? 'saa' : 'at';
    $current_date = date('d m, Y');
    $current_time = date('H:i:s');
    $current_year = date('Y');
    
    return "
    <div class='d-none d-print-block print-footer' style='font-size: 10px; text-align: center; width: 100%; padding-top: 15px; border-top: 1px solid #dee2e6;'>
        <div class='row'>
            <div class='col-12'>
                <p class='mb-1 text-dark' style='font-size: 10px;'>
                    $printed_by_label <strong>$username</strong> - <strong>$user_role</strong> $on_label <strong>$current_date</strong> $at_label <strong>$current_time</strong>
                </p>
                <h6 class='mb-0 fw-bold' style='color: #0d6efd !important; font-size: 10px;'>
                    Powered By BJP Technologies &copy; $current_year, All Rights Reserved
                </h6>
            </div>
        </div>
    </div>
    <style>
        @media print {
            .print-footer { position: fixed; bottom: 0.8cm; left: 0; right: 0; display: block !important; }
            body { margin-bottom: 2cm !important; }
        }
    </style>";
}
?>