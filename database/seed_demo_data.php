<?php
/**
 * database/seed_demo_data.php
 * ---------------------------
 * Generates a coherent, presentation-ready VICOBA dataset: one savings group,
 * a roster of members, their monthly contributions (savings), a realistic mix
 * of loans (active / fully repaid / defaulted / pending) with disbursements,
 * repayment schedules and the accounting transactions behind them, plus a few
 * fines and welfare (death) claims.
 *
 * It is intended for DEMOS ONLY and is deliberately NOT registered in
 * database/migrate.php, so it never runs on deploy. Run it by hand on the
 * server from the project root.
 *
 * Usage (CLI only):
 *   php database/seed_demo_data.php                 # seed (aborts if members already exist)
 *   php database/seed_demo_data.php --fresh         # truncate the demo tables, then seed
 *   php database/seed_demo_data.php --append        # add on top of existing data
 *   php database/seed_demo_data.php --members=45    # override member count (default 30)
 *
 * Safe to re-run with --fresh to get an identical, clean demo every time
 * (the randomness is seeded, so the dataset is reproducible).
 *
 * Tables touched: customer_groups, customers, customer_group_customers,
 * contributions, loans, loan_disbursements, loan_repayments, transactions,
 * fines, death_expenses. Config/RBAC/lookups are read but never modified.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This seeder is CLI-only. Run it from the server shell, not the browser.\n");
}

require_once __DIR__ . '/../includes/config.php'; // provides $pdo

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------
$fresh   = in_array('--fresh', $argv, true);
$append  = in_array('--append', $argv, true);
$members = 30;
foreach ($argv as $a) {
    if (preg_match('/^--members=(\d+)$/', $a, $m)) {
        $members = max(1, (int) $m[1]);
    }
}

const GROUP_NAME = 'Umoja VICOBA Group';
mt_srand(20260627); // reproducible demo dataset

$now = new DateTimeImmutable('today');

// ---------------------------------------------------------------------------
// Guard: don't silently double-seed a populated database
// ---------------------------------------------------------------------------
$existingMembers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
if ($existingMembers > 0 && !$fresh && !$append) {
    fwrite(STDERR,
        "Refusing to seed: `customers` already has {$existingMembers} row(s).\n" .
        "  Re-run with --fresh to truncate the demo tables and reseed cleanly,\n" .
        "  or with --append to add demo data on top of what is already there.\n");
    exit(1);
}

$demoTables = [
    'contributions', 'loan_repayments', 'loan_disbursements', 'transactions',
    'fines', 'death_expenses', 'customer_group_customers', 'loans',
    'customers', 'customer_groups',
];

if ($fresh) {
    echo "Truncating demo tables...\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($demoTables as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ---------------------------------------------------------------------------
// Resolve real IDs from existing config (never invented)
// ---------------------------------------------------------------------------
$adminId = (int) ($pdo->query(
    "SELECT user_id FROM users WHERE is_admin = 1 OR role_id IN (1,12) ORDER BY user_id LIMIT 1"
)->fetchColumn() ?: 0);
if (!$adminId) {
    $adminId = (int) ($pdo->query('SELECT MIN(user_id) FROM users')->fetchColumn() ?: 0);
}

$accountIds  = $pdo->query('SELECT account_id FROM accounts ORDER BY account_id')->fetchAll(PDO::FETCH_COLUMN);
$cashAccount = (int) ($accountIds[0] ?? 0);
$contraAcct  = (int) ($accountIds[1] ?? $cashAccount);

$loanTypeIds = $pdo->query('SELECT id FROM loan_types ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [0];
$cycleIds    = $pdo->query('SELECT id FROM repayment_cycles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [0];
$cycleId     = (int) $cycleIds[0];

// ---------------------------------------------------------------------------
// Source name/place data (Tanzanian / Swahili context)
// ---------------------------------------------------------------------------
$maleFirst = ['Juma','Hamisi','Bakari','Rajabu','Salum','Said','Hassan','Mussa','Ally','Omari',
              'Ramadhani','Shabani','Idrisa','Yusuph','Athumani','Emmanuel','Baraka','Daudi','Frank','Godfrey'];
$femaleFirst = ['Asha','Fatuma','Zainabu','Mwajuma','Halima','Rehema','Neema','Upendo','Mariam','Salma',
                'Amina','Hawa','Joyce','Grace','Tatu','Zuhura','Furaha','Subira','Devota','Happiness'];
$surnames = ['Mwakyusa','Mushi','Kimaro','Massawe','Shirima','Mrema','Lyimo','Kessy','Macha','Mbwana',
             'Nyerere','Mwinyi','Komba','Sanga','Mwanga','Temba','Materu','Swai','Minja','Urassa',
             'Kileo','Mtui','Lema','Maro','Ngowi','Mollel','Kway','Mhando'];
$wards = ['Kinondoni','Magomeni','Sinza','Mbezi','Tegeta','Ubungo','Tabata','Ilala','Kariakoo',
          'Temeke','Mbagala','Kigamboni','Manzese','Mwananyamala'];
$relationships = ['Spouse','Son','Daughter','Brother','Sister','Parent'];
$loanPurposes = ['Mtaji wa biashara (business capital)','Ada ya shule (school fees)',
                 'Pembejeo za kilimo (farm inputs)','Ujenzi wa nyumba (home construction)',
                 'Matibabu (medical)','Ununuzi wa bidhaa (stock purchase)','Biashara ndogo (petty trade)'];
$disbMethods = ['Bank Transfer','Cash','M-Pesa'];
$fineReasons = ['Kuchelewa mkutano (late to meeting)','Kutohudhuria (absence)',
                'Mchango wa kuchelewa (late contribution)','Kukiuka kanuni (rule breach)'];

$allFirst = array_merge($maleFirst, $femaleFirst);
$pick = static function (array $a) { return $a[mt_rand(0, count($a) - 1)]; };

// ---------------------------------------------------------------------------
// Prepared statements
// ---------------------------------------------------------------------------
$insGroup = $pdo->prepare(
    'INSERT INTO customer_groups (group_name, description, group_type, status, created_by, created_at)
     VALUES (?, ?, "static", "active", ?, ?)');

$insMember = $pdo->prepare(
    'INSERT INTO customers
        (first_name, last_name, customer_code, customer_name, customer_type, gender,
         marital_status, dob, nida_number, phone, mobile, email, address, city, district,
         ward, country, status, initial_savings, next_of_kin_name, next_of_kin_relationship,
         next_of_kin_phone, created_at, created_by, category_id)
     VALUES
        (?, ?, ?, ?, "individual", ?,
         ?, ?, ?, ?, ?, ?, ?, "Dar es Salaam", "Dar es Salaam",
         ?, "Tanzania", "active", ?, ?, ?,
         ?, ?, ?, 0)');

$insGroupLink = $pdo->prepare(
    'INSERT INTO customer_group_customers (group_id, customer_id, added_by, added_at) VALUES (?, ?, ?, ?)');

$insContribution = $pdo->prepare(
    'INSERT INTO contributions (member_id, amount, contribution_type, contribution_date, description, status, created_by, created_at)
     VALUES (?, ?, ?, ?, ?, "approved", ?, ?)');

$insLoan = $pdo->prepare(
    'INSERT INTO loans
        (disbursement_account_id, amount, interest_rate, loan_date, due_date, status,
         outstanding_amount, customer_id, reference_number, application_date, loan_start_date,
         loan_officer_id, repayment_cycle_id, purpose, term_length, total_interest,
         total_repayment, total_paid, balance, loan_end_date, loan_type_id, approval_date,
         disbursement_date, disbursement_amount, disbursement_method, disbursement_reference,
         next_payment_date, last_payment_date, created_by, product_id)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)');

$insDisb = $pdo->prepare(
    'INSERT INTO loan_disbursements (loan_id, disbursed_by, disbursement_date, amount, method, reference, notes, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

$insSchedule = $pdo->prepare(
    'INSERT INTO loan_repayments (loan_id, due_date, amount, amount_paid, payment_account_id, payment_date, status, cycle_type, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, "monthly", ?)');

$insTxn = $pdo->prepare(
    'INSERT INTO transactions (loan_id, transaction_date, amount, transaction_type, payment_method,
         reference_number, account_id, contra_account_id, disbursement_account_id, description, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

$insFine = $pdo->prepare(
    'INSERT INTO fines (customer_id, amount, reason, status, created_at) VALUES (?, ?, ?, ?, ?)');

$insDeath = $pdo->prepare(
    'INSERT INTO death_expenses (member_id, phone_number, deceased_type, deceased_name, deceased_relationship,
         amount, description, status, expense_date, created_at, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, "approved", ?, ?, ?)');

// ---------------------------------------------------------------------------
// 1) The group
// ---------------------------------------------------------------------------
$insGroup->execute([GROUP_NAME, 'Demo savings group for client presentation.', $adminId, $now->format('Y-m-d H:i:s')]);
$groupId = (int) $pdo->lastInsertId();

// ---------------------------------------------------------------------------
// 2) Members + their monthly savings
// ---------------------------------------------------------------------------
$counts = ['members' => 0, 'contributions' => 0, 'loans' => 0,
           'disbursements' => 0, 'schedule' => 0, 'transactions' => 0,
           'fines' => 0, 'welfare' => 0];

$roster = []; // [customer_id, name, gender, joinDate, phone]

for ($i = 1; $i <= $members; $i++) {
    $isMale  = mt_rand(0, 1) === 1;
    $first   = $isMale ? $pick($maleFirst) : $pick($femaleFirst);
    $last    = $pick($surnames);
    $name    = "$first $last";
    $gender  = $isMale ? 'Male' : 'Female';
    $code    = 'VIK-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    $phone   = '+2557' . mt_rand(10, 89) . mt_rand(100000, 999999);
    $dob     = sprintf('%04d-%02d-%02d', mt_rand(1965, 2000), mt_rand(1, 12), mt_rand(1, 28));
    // Tanzanian NIDA is 20 digits (birthdate YYYYMMDD + 12 more). Column is varchar(20).
    $nida    = str_replace('-', '', $dob) . sprintf('%06d%06d', mt_rand(0, 999999), mt_rand(0, 999999));
    $email   = strtolower($first . '.' . $last . $i) . '@example.co.tz';
    $ward    = $pick($wards);
    $marital = $pick(['Single', 'Married', 'Married', 'Widowed']);

    $monthsAgo = mt_rand(6, 16);
    $join      = $now->modify("-{$monthsAgo} months")->modify('+' . mt_rand(0, 20) . ' days');
    $share     = $pick([30000, 50000, 50000, 50000, 100000]); // monthly savings amount
    $entrance  = 20000;

    $nokName = $pick($allFirst) . ' ' . $pick($surnames);

    $insMember->execute([
        $first, $last, $code, $name, $gender,
        $marital, $dob, $nida, $phone, $phone, $email,
        $ward . ', Dar es Salaam',
        $ward, (float) $entrance, $nokName, $pick($relationships),
        '+2557' . mt_rand(10, 89) . mt_rand(100000, 999999),
        $join->format('Y-m-d H:i:s'), $adminId,
    ]);
    $cid = (int) $pdo->lastInsertId();
    $counts['members']++;

    $insGroupLink->execute([$groupId, $cid, $adminId, $join->format('Y-m-d H:i:s')]);

    // Entrance fee at join
    $insContribution->execute([$cid, $entrance, 'entrance', $join->format('Y-m-d'),
        'Ada ya kujiunga (entrance fee)', $adminId, $join->format('Y-m-d H:i:s')]);
    $counts['contributions']++;

    // Monthly savings from the month after joining up to now
    $m = $join->modify('first day of next month');
    while ($m <= $now) {
        $payDate = $m->modify('+' . mt_rand(2, 9) . ' days');
        $insContribution->execute([$cid, $share, 'monthly', $payDate->format('Y-m-d'),
            'Mchango wa mwezi (monthly savings)', $adminId, $payDate->format('Y-m-d H:i:s')]);
        $counts['contributions']++;
        $m = $m->modify('first day of next month');
    }

    $roster[] = ['id' => $cid, 'name' => $name, 'phone' => $phone, 'join' => $join];
}

// ---------------------------------------------------------------------------
// 3) Loans for ~40% of members, with disbursements / schedules / transactions
// ---------------------------------------------------------------------------
$indices = range(0, count($roster) - 1);
shuffle($indices);
$borrowerCount = (int) floor(count($roster) * 0.4);
$borrowers = array_slice($indices, 0, $borrowerCount);

$seq = 0;
foreach ($borrowers as $idx) {
    $member = $roster[$idx];
    $seq++;

    // Profile distribution: active / repaid / defaulted / pending
    $r = mt_rand(1, 100);
    if ($r <= 50)      $profile = 'active';
    elseif ($r <= 80)  $profile = 'repaid';
    elseif ($r <= 90)  $profile = 'defaulted';
    else               $profile = 'pending';

    $principal = mt_rand(2, 30) * 100000;     // 200,000 .. 3,000,000 TZS
    $rate      = (float) $pick([8, 10, 10, 12]);
    $term      = (int) $pick([6, 12, 12]);    // months
    $interest  = round($principal * $rate / 100, 2);
    $totalRepay = $principal + $interest;
    $installment = round($totalRepay / $term, 2);
    $ref = 'LN-' . $now->format('Y') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

    // Start date by profile (keep loan within the member's membership window)
    switch ($profile) {
        case 'repaid':    $startAgo = $term + mt_rand(1, 2); break;
        case 'defaulted': $startAgo = $term + mt_rand(2, 5); break;
        case 'active':    $startAgo = mt_rand(1, max(1, $term - 1)); break;
        default:          $startAgo = 0; break; // pending — not yet disbursed
    }
    $start = $now->modify("-{$startAgo} months");
    if ($start < $member['join']) {
        $start = $member['join']->modify('+' . mt_rand(15, 40) . ' days');
    }
    $end = $start->modify("+{$term} months");

    // How many installments are paid, by profile
    if ($profile === 'repaid') {
        $paidCount = $term;
    } elseif ($profile === 'active') {
        $elapsed   = max(1, (int) $start->diff($now)->m + (int) $start->diff($now)->y * 12);
        $paidCount = min($term - 1, $elapsed);
    } elseif ($profile === 'defaulted') {
        $paidCount = mt_rand(1, max(1, (int) floor($term / 3)));
    } else { // pending
        $paidCount = 0;
    }

    $totalPaid = round($paidCount * $installment, 2);
    if ($profile === 'repaid') { $totalPaid = $totalRepay; }
    $balance = round($totalRepay - $totalPaid, 2);

    $status = match ($profile) {
        'repaid'    => 'Repaid',
        'defaulted' => 'Defaulted',
        'active'    => 'Disbursed',
        default     => mt_rand(0, 1) ? 'Pending' : 'Approved',
    };

    $isDisbursed   = in_array($profile, ['active', 'repaid', 'defaulted'], true);
    $disbDate      = $isDisbursed ? $start->format('Y-m-d') : null;
    $approvalDate  = $isDisbursed ? $start->modify('-' . mt_rand(3, 10) . ' days')->format('Y-m-d')
                                  : $now->modify('-' . mt_rand(1, 10) . ' days')->format('Y-m-d');
    $appDate       = $start->modify('-' . mt_rand(12, 25) . ' days')->format('Y-m-d');
    $method        = $pick($disbMethods);
    $loanTypeId    = (int) $pick($loanTypeIds);

    // Build the schedule first so we can derive next/last payment dates
    $scheduleRows = [];
    $lastPaid = null;
    $nextDue  = null;
    for ($n = 1; $n <= $term && $isDisbursed; $n++) {
        $due = $start->modify("+{$n} months");
        if ($n <= $paidCount) {
            $payDate = $due->modify('-' . mt_rand(0, 4) . ' days');
            $scheduleRows[] = [$due, $installment, 'paid', $payDate];
            $lastPaid = $payDate;
        } else {
            $isLate = $due < $now;
            $scheduleRows[] = [$due, $installment, $isLate ? 'late' : 'pending', null];
            if ($nextDue === null) { $nextDue = $due; }
        }
    }

    $insLoan->execute([
        $cashAccount, $principal, $rate, ($disbDate ?: $appDate), $end->format('Y-m-d'), $status,
        $balance, $member['id'], $ref, $appDate, ($isDisbursed ? $start->format('Y-m-d') : null),
        $adminId, $cycleId, $pick($loanPurposes), $term, $interest,
        $totalRepay, $totalPaid, $balance, $end->format('Y-m-d'), $loanTypeId, $approvalDate,
        $disbDate, ($isDisbursed ? $principal : 0), ($isDisbursed ? $method : null),
        ($isDisbursed ? 'DISB-' . $ref : null),
        ($nextDue ? $nextDue->format('Y-m-d') : null),
        ($lastPaid ? $lastPaid->format('Y-m-d') : null),
        $adminId,
    ]);
    $loanId = (int) $pdo->lastInsertId();
    $counts['loans']++;

    if ($isDisbursed) {
        $insDisb->execute([$loanId, $adminId, $disbDate, $principal, $method,
            'DISB-' . $ref, 'Mkopo umetolewa (loan disbursed)', $start->format('Y-m-d H:i:s')]);
        $counts['disbursements']++;

        // Disbursement transaction (money out)
        $insTxn->execute([$loanId, $disbDate, $principal, 'disbursement', $method,
            'DISB-' . $ref, $cashAccount, $contraAcct, $cashAccount,
            'Disbursement of loan ' . $ref . ' to ' . $member['name'], $start->format('Y-m-d H:i:s')]);
        $counts['transactions']++;
    }

    foreach ($scheduleRows as $row) {
        [$due, $amount, $sStatus, $payDate] = $row;
        $amountPaid = $sStatus === 'paid' ? $amount : 0.00;
        $insSchedule->execute([
            $loanId, $due->format('Y-m-d'), $amount, $amountPaid, $cashAccount,
            $payDate ? $payDate->format('Y-m-d H:i:s') : null, $sStatus,
            $due->format('Y-m-d H:i:s'),
        ]);
        $counts['schedule']++;

        if ($sStatus === 'paid') {
            $insTxn->execute([$loanId, $payDate->format('Y-m-d'), $amount, 'repayment', $method,
                'RPY-' . $ref . '-' . $due->format('ym'), $cashAccount, $contraAcct, $cashAccount,
                'Repayment for loan ' . $ref . ' by ' . $member['name'], $payDate->format('Y-m-d H:i:s')]);
            $counts['transactions']++;
        }
    }
}

// ---------------------------------------------------------------------------
// 4) A handful of fines
// ---------------------------------------------------------------------------
$fineTargets = array_slice($indices, 0, min(8, count($roster)));
foreach ($fineTargets as $idx) {
    $member = $roster[$idx];
    $amount = mt_rand(1, 4) * 5000; // 5,000 .. 20,000
    $when   = $now->modify('-' . mt_rand(5, 120) . ' days');
    $insFine->execute([$member['id'], $amount, $pick($fineReasons),
        $pick(['paid', 'paid', 'pending']), $when->format('Y-m-d H:i:s')]);
    $counts['fines']++;
}

// ---------------------------------------------------------------------------
// 5) A couple of welfare (death) claims
// ---------------------------------------------------------------------------
$welfareTargets = array_slice(array_reverse($indices), 0, min(2, count($roster)));
foreach ($welfareTargets as $idx) {
    $member = $roster[$idx];
    $type   = $pick(['spouse', 'child', 'member']);
    $when   = $now->modify('-' . mt_rand(20, 150) . ' days');
    $amount = mt_rand(3, 10) * 100000; // 300,000 .. 1,000,000
    $deceasedName = $pick($allFirst) . ' ' . $pick($surnames);
    $insDeath->execute([
        $member['id'], $member['phone'], $type, $deceasedName, $pick($relationships),
        $amount, 'Msaada wa msiba (welfare/funeral support)', $when->format('Y-m-d'),
        $when->format('Y-m-d H:i:s'), $adminId,
    ]);
    $counts['welfare']++;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n== Demo data seeded for \"" . GROUP_NAME . "\" ==\n";
printf("  Members:        %d\n", $counts['members']);
printf("  Contributions:  %d\n", $counts['contributions']);
printf("  Loans:          %d\n", $counts['loans']);
printf("  Disbursements:  %d\n", $counts['disbursements']);
printf("  Schedule rows:  %d\n", $counts['schedule']);
printf("  Transactions:   %d\n", $counts['transactions']);
printf("  Fines:          %d\n", $counts['fines']);
printf("  Welfare claims: %d\n", $counts['welfare']);
echo "\nDone. Log into the app to review the populated dashboards.\n";
