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

/**
 * Single source of truth for currency display (audit M1). The group's currency
 * is TZS, shown with the local 'TSh ' symbol. $decimals is configurable so
 * whole-shilling displays (dashboard cards) can pass 0 while accounting views
 * keep 2 — without forking the symbol logic.
 */
function format_currency($amount, $currency = 'TZS', $decimals = 2) {
    $symbols = [
        'TZS' => 'TSh ',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'KES' => 'KSh '
    ];
    $symbol = $symbols[$currency] ?? 'TSh ';
    return $symbol . number_format($amount, $decimals);
}

/**
 * Whole-years age from a date of birth (registration: children enter DOB, age is
 * derived server-side rather than trusting the client). Returns null for an
 * empty, unparseable, or future date.
 */
function vk_age_from_dob(?string $dob): ?int {
    $dob = trim((string) $dob);
    if ($dob === '') return null;
    $ts = strtotime($dob);
    if ($ts === false) return null;
    $birth = (new DateTime())->setTimestamp($ts);
    $now = new DateTime();
    if ($birth > $now) return null;
    return (int) $birth->diff($now)->y;
}

/**
 * Join first/middle/last into one display name, dropping blanks and collapsing
 * the gaps. Used to keep the legacy single-column *_name fields populated when
 * a record also stores the structured name parts (registration PR-B).
 */
function vk_full_name(?string $first, ?string $middle = '', ?string $last = ''): string {
    $parts = array_filter([
        trim((string) $first),
        trim((string) $middle),
        trim((string) $last),
    ], fn($p) => $p !== '');
    return implode(' ', $parts);
}

/**
 * Save one optional child passport photo from an array-style `$_FILES['child_photo']`
 * (the children table posts `child_photo[]`). Returns the stored filename, or ''
 * when no file was uploaded for that row (registration PR-D).
 *
 * @param mixed  $files The $_FILES['child_photo'] array, or null.
 * @param int    $i     The child row index.
 * @param string $dir   Destination directory (absolute).
 */
function vk_save_child_photo($files, int $i, string $dir): string {
    if (!is_array($files) || !isset($files['error'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) {
        return '';
    }
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $ext  = pathinfo($files['name'][$i] ?? '', PATHINFO_EXTENSION);
    $name = 'child_' . time() . '_' . uniqid() . ($ext !== '' ? '.' . $ext : '');
    return move_uploaded_file($files['tmp_name'][$i], rtrim($dir, '/\\') . '/' . $name) ? $name : '';
}

/**
 * Save one optional uploaded photo from a single `$_FILES[$field]`. Returns the
 * stored filename, or null when no file was uploaded — so callers can keep the
 * existing value (never wipe a photo on an empty upload). Used by the member
 * edit form to add/replace member/spouse/parent photos later.
 */
function vk_upload_photo(string $field, string $dir): ?string {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $ext  = pathinfo($_FILES[$field]['name'] ?? '', PATHINFO_EXTENSION);
    $name = $field . '_' . time() . '_' . uniqid() . ($ext !== '' ? '.' . $ext : '');
    return move_uploaded_file($_FILES[$field]['tmp_name'], rtrim($dir, '/\\') . '/' . $name) ? $name : null;
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

if (!function_exists('markChildDeceasedJson')) {
    /**
     * Mark a child (by index) inside a children_data JSON blob as deceased,
     * keeping the entry and all sibling indexes intact. Used when a death
     * expense for a child is approved, so the child is retained (and shown
     * flagged) on the member's profile instead of being deleted outright.
     *
     * @return string|null Updated JSON, or the original input unchanged when the
     *                     blob is empty/invalid or the index is absent.
     */
    function markChildDeceasedJson(?string $childrenJson, int $idx, string $deceasedDate): ?string
    {
        if ($childrenJson === null || $childrenJson === '') {
            return $childrenJson;
        }
        $children = json_decode($childrenJson, true);
        if (!is_array($children) || !isset($children[$idx]) || !is_array($children[$idx])) {
            return $childrenJson;
        }
        $children[$idx]['is_deceased']   = 1;
        $children[$idx]['deceased_date'] = $deceasedDate;
        return json_encode($children);
    }
}
?>