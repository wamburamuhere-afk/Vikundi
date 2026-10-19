<?php
/**
 * database/seed_wise_wallet_demo.php
 * ----------------------------------
 * Fills an EMPTY Vikundi database with a presentation-ready group for a sales
 * demo: 25 members, ten months of shares / Social Fund / deposits, loan types,
 * loans in every state, a few fines and expenses, and a matching M-Koba
 * statement file ready to import in front of the audience.
 *
 * EVERYTHING IS INVENTED. Names, phone numbers and amounts belong to nobody.
 * Phones sit in a made-up 25570000xxxx block and e-mails use the reserved
 * .example domain, so nothing here can reach a real person.
 *
 * DEMOS ONLY — deliberately NOT registered in database/migrate.php, so it never
 * runs on deploy. Run it by hand from the project root, after migrate.php:
 *
 *   php database/seed_wise_wallet_demo.php                    # seed an empty database
 *   php database/seed_wise_wallet_demo.php --fresh            # wipe THIS demo's data and reseed
 *   php database/seed_wise_wallet_demo.php --password=Secret  # choose the demo password
 *   (add --allow-nondemo-db when the database name has no demo/test marker)
 *
 * Safety:
 *   - demo_seed_guard.php refuses production databases and real groups outright
 *   - it will not seed a database that already has people in it
 *   - --fresh only works on a database this seeder itself created (it checks the
 *     group name), so it cannot be turned on somebody else's data
 *
 * Loans are created through the real workflow (includes/loans.php), so every
 * figure on screen is one the application itself worked out.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This seeder is CLI-only. Run it from the server shell, not the browser.\n");
}

require_once __DIR__ . '/../includes/config.php'; // provides $pdo
require_once __DIR__ . '/demo_seed_guard.php';
require_once __DIR__ . '/../includes/loans.php';
require_once __DIR__ . '/../includes/dividends.php';

const WW_GROUP_NAME = 'Wise Wallet M-KOBA';

vk_demo_seed_guard($pdo, $argv);

$fresh    = in_array('--fresh', $argv, true);
$password = '';
foreach ($argv as $a) {
    if (preg_match('/^--password=(.+)$/', $a, $m)) {
        $password = $m[1];
    }
}
if ($password === '') {
    // Not a fixed, guessable password: the demo site is on the public internet.
    $password = 'Wise-' . strtoupper(bin2hex(random_bytes(3))) . '-' . random_int(10, 99);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "The demo password must be at least 8 characters.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------
foreach (['vicoba_loan_types', 'vicoba_dividend_runs', 'mkoba_statement_rows'] as $needed) {
    try {
        $pdo->query("SELECT 1 FROM `$needed` LIMIT 1");
    } catch (Throwable $e) {
        fwrite(STDERR, "Table `$needed` is missing. Run `php database/migrate.php` first.\n");
        exit(1);
    }
}

$groupName = (string) ($pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_name'")->fetchColumn() ?: '');
$people    = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() + (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

if ($people > 0 && !$fresh) {
    fwrite(STDERR, "\nThis database already has users or members, so it is not seeded.\n"
        . "  Use --fresh to wipe and reseed a database this seeder created.\n\n");
    exit(1);
}
if ($fresh && $people > 0 && $groupName !== WW_GROUP_NAME) {
    fwrite(STDERR, "\nREFUSING --fresh: this database was not created by this seeder"
        . " (group_name = \"" . $groupName . "\", expected \"" . WW_GROUP_NAME . "\").\n\n");
    exit(1);
}

if ($fresh) {
    echo "Wiping the demo data...\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'vicoba_dividend_lines', 'vicoba_dividend_runs', 'vicoba_loan_payments', 'vicoba_loan_installments',
        'vicoba_loan_guarantors', 'vicoba_loans', 'vicoba_loan_types', 'mkoba_statement_rows', 'contributions',
        'fines', 'general_expenses', 'death_expenses', 'activity_logs', 'customers', 'users',
    ] as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

mt_srand(20261012); // the same people and amounts every time
$today = new DateTimeImmutable('today');
$ago   = static fn(string $spec): DateTimeImmutable => $today->modify('-' . $spec);

// ---------------------------------------------------------------------------
// 1) The group and its own rules — every figure here is editable on screen
// ---------------------------------------------------------------------------
$startMonth = $today->modify('first day of this month')->modify('-10 months');
$settings = [
    'group_name'                 => WW_GROUP_NAME,
    // Original artwork for this demo (source: assets/images/demo/wise_wallet_logo.svg).
    // Without it the group shows the app's built-in default logo.
    'group_logo'                 => 'wise_wallet_demo_logo.png',
    'group_registration_number'  => 'DEMO/2026/001',
    'group_founded_date'         => $startMonth->format('Y-m-d'),
    'contribution_start_date'    => $startMonth->format('Y-m-d'),
    'group_physical_address'     => 'Dar es Salaam, Tanzania',
    'currency'                   => 'TZS',
    'cycle_type'                 => 'monthly',
    'max_members'                => '30',
    'monthly_contribution'       => '20000',
    // Stated outright, not left unset: this group has no entrance fee, and a value
    // saved through the settings form before a --fresh must not survive the reset.
    'entrance_fee'               => '0',
    'deadline_day'               => '10',
    'auto_termination'           => 'off',
    'share_price'                => '5000',
    'shares_from_monthly'        => 'on',
    'social_fund_amount'         => '2000',
    'membership_deposit_amount'  => '10000',
    'financial_year_start_month' => '1',
    'dividend_distribute_pct'    => '100',
];
$setStmt = $pdo->prepare("REPLACE INTO group_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($settings as $k => $v) {
    $setStmt->execute([$k, $v]);
}

// ---------------------------------------------------------------------------
// 2) People: 25 members, three of them the group's officers
// ---------------------------------------------------------------------------
$roles = $pdo->query("SELECT role_name, role_id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['Chairperson', 'Secretary', 'Treasurer', 'Member'] as $r) {
    if (empty($roles[$r])) {
        fwrite(STDERR, "Role `$r` is missing. Run `php database/migrate.php` first.\n");
        exit(1);
    }
}

// [first, last, gender, role, months since joining, monthly share payment]
$roster = [
    ['Amina', 'Mollel', 'Female', 'Chairperson', 10, 40000],
    ['Daudi', 'Kimaro', 'Male', 'Secretary', 10, 20000],
    ['Grace', 'Mwakalinga', 'Female', 'Treasurer', 10, 30000],
    ['Salim', 'Juma', 'Male', 'Member', 10, 20000],
    ['Neema', 'Lyimo', 'Female', 'Member', 10, 40000],
    ['Baraka', 'Mushi', 'Male', 'Member', 10, 20000],
    ['Zawadi', 'Massawe', 'Female', 'Member', 10, 20000],
    ['Hassan', 'Mrisho', 'Male', 'Member', 10, 30000],
    ['Rehema', 'Shayo', 'Female', 'Member', 10, 20000],
    ['Emmanuel', 'Minja', 'Male', 'Member', 10, 20000],
    ['Halima', 'Rajabu', 'Female', 'Member', 10, 20000],
    ['Joseph', 'Swai', 'Male', 'Member', 10, 40000],
    ['Mwanaidi', 'Hamisi', 'Female', 'Member', 10, 20000],
    ['Peter', 'Urassa', 'Male', 'Member', 10, 20000],
    ['Asha', 'Mbwana', 'Female', 'Member', 10, 30000],
    ['Godfrey', 'Temba', 'Male', 'Member', 10, 20000],
    ['Mariam', 'Kileo', 'Female', 'Member', 10, 20000],
    ['Yusuph', 'Ally', 'Male', 'Member', 10, 20000],
    ['Witness', 'Mrema', 'Female', 'Member', 10, 20000],
    ['Elias', 'Sanga', 'Male', 'Member', 10, 30000],
    ['Fatuma', 'Seif', 'Female', 'Member', 10, 20000],
    ['Charles', 'Mbise', 'Male', 'Member', 10, 20000],
    ['Upendo', 'Laizer', 'Female', 'Member', 6, 20000],
    ['Rashidi', 'Mnyampala', 'Male', 'Member', 4, 20000],
    ['Anna', 'Kessy', 'Female', 'Member', 2, 20000],
];

$hash = password_hash($password, PASSWORD_DEFAULT);
$insUser = $pdo->prepare(
    "INSERT INTO users (username, password, email, phone, role, user_role, role_id, status, is_active, is_admin,
                        first_name, last_name, language, preferred_language, created_at, password_changed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, 0, ?, ?, 'en', 'en', ?, NOW())"
);
$insMember = $pdo->prepare(
    "INSERT INTO customers (user_id, first_name, last_name, customer_code, customer_name, customer_type, gender,
                            phone, mobile, email, address, city, district, country, status, created_at, created_by)
     VALUES (?, ?, ?, ?, ?, 'individual', ?, ?, ?, ?, 'Dar es Salaam', 'Dar es Salaam', 'Dar es Salaam', 'Tanzania', 'active', ?, ?)"
);

$members = [];   // username => row
$n = 0;
foreach ($roster as [$first, $last, $gender, $role, $monthsIn, $monthly]) {
    $n++;
    $username = strtolower($first[0] . $last);                       // the app's own convention
    $phone    = '2557000001' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $email    = strtolower("$first.$last") . '@wisewallet.example';
    $joined   = $today->modify('first day of this month')->modify("-$monthsIn months");

    $insUser->execute([$username, $hash, $email, $phone, $role, $role, (int) $roles[$role], $first, $last, $joined->format('Y-m-d H:i:s')]);
    $uid = (int) $pdo->lastInsertId();
    $insMember->execute([$uid, $first, $last, 'WW' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), "$first $last", $gender,
                         $phone, $phone, $email, $joined->format('Y-m-d H:i:s'), $uid]);
    $members[$username] = [
        'user_id' => $uid, 'customer_id' => (int) $pdo->lastInsertId(), 'name' => "$first $last", 'phone' => $phone,
        'role' => $role, 'joined' => $joined, 'monthly' => $monthly,
    ];
}
$chair     = $members['amollel'];
$secretary = $members['dkimaro'];
$treasurer = $members['gmwakalinga'];

// ---------------------------------------------------------------------------
// 3) Money in: deposit at joining, then shares and Social Fund every month
// ---------------------------------------------------------------------------
$insContribution = $pdo->prepare(
    "INSERT INTO contributions (member_id, amount, contribution_type, contribution_date, description, status,
                                account, receipt_number, created_by, created_at, approved_by, approved_at)
     VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?)"
);
$receipt = 1000;
$pay = static function (int $cid, float $amount, string $type, DateTimeImmutable $on, string $what) use ($insContribution, &$receipt, $treasurer): void {
    $stamp = $on->format('Y-m-d') . ' 10:00:00';
    $insContribution->execute([$cid, $amount, $type, $on->format('Y-m-d'), $what, mt_rand(0, 3) ? 'Mobile Money' : 'Cash',
                               'RC' . (++$receipt), $treasurer['user_id'], $stamp, $treasurer['user_id'], $stamp]);
};

// Members who have NOT yet paid this month — their payment arrives in the
// M-Koba statement file, so importing it visibly completes the month.
$thisMonth = $today->modify('first day of this month');
$awaitingStatement = [];
// Two members a month or two behind, so arrears show on the reports.
$skips = ['yally' => [3], 'hrajabu' => [2, 5]];

$counts = ['contributions' => 0];
foreach ($members as $username => $m) {
    $pay($m['customer_id'], 10000, 'deposit', $m['joined']->modify('+2 days'), 'Membership deposit');
    $counts['contributions']++;

    for ($month = $m['joined'], $i = 0; $month <= $thisMonth; $month = $month->modify('+1 month'), $i++) {
        $isThisMonth = $month == $thisMonth;
        if ($isThisMonth && !in_array($m['role'], ['Chairperson', 'Treasurer'], true)) {
            $awaitingStatement[] = $username;   // paid through M-Koba — see the statement file
            continue;
        }
        if (in_array($i, $skips[$username] ?? [], true)) {
            continue;
        }
        $on = $month->modify('+' . mt_rand(1, 8) . ' days');
        if ($on > $today) {
            $on = $today;
        }
        $pay($m['customer_id'], $m['monthly'], 'monthly', $on, 'Monthly contribution (shares)');
        $pay($m['customer_id'], 2000, 'social_fund', $on, 'Social Fund');
        $counts['contributions'] += 2;
    }
}

// ---------------------------------------------------------------------------
// 4) Fines, running costs and one welfare payment from the Social Fund
// ---------------------------------------------------------------------------
$insFine = $pdo->prepare("INSERT INTO fines (customer_id, amount, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
foreach ([
    ['bmushi', 5000, 'Late to the monthly meeting', 'paid', '5 months', '5 months'],
    ['hrajabu', 5000, 'Missed contribution deadline', 'paid', '4 months', '3 months'],
    ['yally', 5000, 'Missed contribution deadline', 'paid', '6 months', '6 months'],
    ['esanga', 3000, 'Late to the monthly meeting', 'paid', '2 months', '2 months'],
    ['purassa', 5000, 'Absent from the monthly meeting', 'pending', '20 days', '20 days'],
    ['hrajabu', 5000, 'Missed contribution deadline', 'pending', '25 days', '25 days'],
] as [$who, $amount, $reason, $status, $created, $updated]) {
    $insFine->execute([$members[$who]['customer_id'], $amount, $reason, $status,
                       $ago($created)->format('Y-m-d 18:00:00'), $ago($updated)->format('Y-m-d 18:30:00')]);
}

$insExpense = $pdo->prepare(
    "INSERT INTO general_expenses (expense_date, description, amount, status, created_at, created_by, approved_at, approved_by, paid_at, paid_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
foreach ([
    ['7 months', 'Receipt books and stationery', 8000, 'paid'],
    ['3 months', 'Meeting venue hire', 10000, 'paid'],
    ['1 month', 'Bank statement and transfer charges', 4500, 'paid'],
    ['4 days', 'Printing of year-end reports', 12000, 'pending'],
] as [$when, $what, $amount, $status]) {
    $d = $ago($when);
    $paid = $status === 'paid';
    $insExpense->execute([$d->format('Y-m-d'), $what, $amount, $status, $d->format('Y-m-d 11:00:00'), $secretary['user_id'],
                          $paid ? $d->format('Y-m-d 12:00:00') : null, $paid ? $chair['user_id'] : null,
                          $paid ? $d->format('Y-m-d 15:00:00') : null, $paid ? $treasurer['user_id'] : null]);
}

$d = $ago('2 months');
$pdo->prepare(
    "INSERT INTO death_expenses (member_id, phone_number, deceased_type, deceased_name, deceased_relationship, amount, description,
                                 status, expense_date, created_at, created_by, approved_by, approved_at, paid_at, paid_by)
     VALUES (?, ?, 'parent', 'Demo record — not a real person', 'Parent', 100000, 'Bereavement assistance from the Social Fund',
             'paid', ?, ?, ?, ?, ?, ?, ?)"
)->execute([$members['zmassawe']['customer_id'], $members['zmassawe']['phone'], $d->format('Y-m-d'), $d->format('Y-m-d 09:00:00'),
            $secretary['user_id'], $chair['user_id'], $d->format('Y-m-d 10:00:00'), $d->format('Y-m-d 14:00:00'), $treasurer['user_id']]);

// ---------------------------------------------------------------------------
// 5) Loan types — the group's own rules
// ---------------------------------------------------------------------------
$insType = $pdo->prepare(
    "INSERT INTO vicoba_loan_types (name, description, interest_rate, interest_type, max_term_months, max_share_multiple,
                                    max_amount, guarantors_required, penalty_type, penalty_value, grace_days, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$insType->execute(['Emergency Loan', 'Quick, small loan for urgent needs', 3, 'flat', 3, 0, 200000, 1, 'fixed', 2000, 3, $chair['user_id']]);
$emergency = (int) $pdo->lastInsertId();
$insType->execute(['Development Loan', 'Larger loan for business or school fees, up to three times your shares', 2, 'reducing', 6, 3, null, 2, 'percent', 5, 5, $chair['user_id']]);
$development = (int) $pdo->lastInsertId();

// ---------------------------------------------------------------------------
// 6) Loans in every state, through the real workflow
// ---------------------------------------------------------------------------
$must = static function (array $res, string $step): array {
    if (empty($res['ok'])) {
        fwrite(STDERR, "Seeding stopped at \"$step\": " . ($res['message'] ?? 'unknown error') . "\n");
        exit(1);
    }
    return $res;
};
$cid = static fn(string $u): int => $members[$u]['customer_id'];

/** Apply, have every guarantor accept, and approve. Returns the loan id. */
$approved = function (string $who, int $type, float $amount, int $months, string $purpose, array $guarantors) use ($pdo, $must, $cid, $members, $chair): int {
    $loan = $must(vl_apply($pdo, $cid($who), $type, $amount, $months, $purpose, array_map($cid, $guarantors), $members[$who]['user_id']), "apply ($who)");
    foreach ($guarantors as $g) {
        $must(vl_respond_guarantee($pdo, $loan['loan_id'], $cid($g), true), "guarantee ($g)");
    }
    $must(vl_decide($pdo, $loan['loan_id'], true, $chair['user_id']), "approve ($who)");
    return (int) $loan['loan_id'];
};
/** Pay a loan out on a past date and receive the first $paid instalments on their due dates. */
$running = function (int $loanId, DateTimeImmutable $paidOut, int $paid) use ($pdo, $must, $treasurer): void {
    $must(vl_disburse($pdo, $loanId, $treasurer['user_id'], 'M-Koba', 'MK-OUT-' . $loanId, $paidOut->format('Y-m-d')), "pay out (#$loanId)");
    // The application and approval happened before the money went out.
    $pdo->prepare("UPDATE vicoba_loans SET applied_at = ?, approved_at = ? WHERE loan_id = ?")
        ->execute([$paidOut->modify('-6 days')->format('Y-m-d 09:00:00'), $paidOut->modify('-2 days')->format('Y-m-d 16:00:00'), $loanId]);
    foreach (array_slice(vl_installments($pdo, $loanId), 0, $paid) as $i) {
        $must(vl_record_payment($pdo, $loanId, (float) $i['principal_due'] + (float) $i['interest_due'], $i['due_date'],
                                'M-Koba', 'MK-IN-' . $loanId . '-' . $i['seq'], '', $treasurer['user_id']), "repayment (#$loanId)");
    }
};

// Fully repaid.
$running($approved('nlyimo', $development, 400000, 4, 'Tailoring business stock', ['jswai', 'ambwana']), $ago('7 months'), 4);
// Being repaid on time: two instalments in, the next one due in about two weeks.
$running($approved('hmrisho', $development, 600000, 6, 'School fees', ['esanga', 'rshayo']), $ago('2 months')->modify('-16 days'), 2);
// Overdue: first instalment paid, the second is weeks late and carries a penalty.
$running($approved('sjuma', $emergency, 150000, 3, 'Hospital bill', ['bmushi']), $ago('2 months')->modify('-18 days'), 1);
// Paid out recently, nothing due yet.
$running($approved('jswai', $development, 900000, 6, 'Shop expansion', ['nlyimo', 'ambwana']), $ago('12 days'), 0);
// Approved, waiting for the Treasurer to pay it out.
$approved('eminja', $emergency, 100000, 2, 'Motorcycle repair', ['gtemba']);

// Waiting for leadership to approve (both guarantors have accepted).
$waiting = $must(vl_apply($pdo, $cid('ambwana'), $development, 500000, 5, 'Poultry project', [$cid('wmrema'), $cid('mkileo')], $members['ambwana']['user_id']), 'apply (ambwana)');
foreach (['wmrema', 'mkileo'] as $g) {
    $must(vl_respond_guarantee($pdo, $waiting['loan_id'], $cid($g), true), "guarantee ($g)");
}

// Waiting for a guarantor: one has accepted, the other is the demo member login.
$guaranteeDemo = $must(vl_apply($pdo, $cid('gtemba'), $development, 300000, 3, 'Farm inputs', [$cid('fseif'), $cid('cmbise')], $members['gtemba']['user_id']), 'apply (gtemba)');
$must(vl_respond_guarantee($pdo, $guaranteeDemo['loan_id'], $cid('fseif'), true), 'guarantee (fseif)');

// Rejected, with the reason the member sees.
$rejected = $must(vl_apply($pdo, $cid('mhamisi'), $emergency, 200000, 3, 'Household items', [$cid('wmrema')], $members['mhamisi']['user_id']), 'apply (mhamisi)');
$must(vl_respond_guarantee($pdo, $rejected['loan_id'], $cid('wmrema'), true), 'guarantee (wmrema)');
$must(vl_decide($pdo, $rejected['loan_id'], false, $chair['user_id'], 'An emergency loan is for urgent needs; please apply for a Development Loan instead.'), 'reject (mhamisi)');

// ---------------------------------------------------------------------------
// 7) The M-Koba statement to import during the demo
// ---------------------------------------------------------------------------
// Same ten columns, in the same order, as a real M-Koba statement export.
require_once __DIR__ . '/../includes/tenant.php';
$statementPath = vk_storage_dir('downloads') . 'WiseWallet_MKoba_Statement_DEMO.csv';
$ref = static function (): string {
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $pick = static fn(string $set, int $len): string => implode('', array_map(static fn() => $set[mt_rand(0, strlen($set) - 1)], range(1, $len)));
    return $pick($letters, 3) . mt_rand(1, 9) . $pick($letters, 1) . mt_rand(1, 9) . $pick($letters, 5);
};
$groupAccount = '70000000999';
$fh = fopen($statementPath, 'w');
fputcsv($fh, ['NO', 'TRANS_ID', 'RECEIPT', 'DATE', 'MEMBER NAME', 'MEMBER ID', 'SOURCE', 'DESTINATION', 'AMOUNT', 'TRANS TYPE'], ',', '"', '');
$no = 0;
$row = static function (string $name, string $memberId, string $source, string $dest, $amount, string $type, DateTimeImmutable $at) use ($fh, &$no, $ref): void {
    $r = $ref();
    fputcsv($fh, [++$no, $r, $r, $at->format('d/m/Y H:i'), $name, $memberId, $source, $dest, $amount === '' ? '' : number_format((float) $amount, 2, '.', ','), $type], ',', '"', '');
};
$statementTotal = 0;
$statementDay = $today->format('j') > 3 ? $today->modify('-2 days') : $today;
$row(WW_GROUP_NAME, '', '', $groupAccount, '', 'Opening an account on cbs', $startMonth->setTime(9, 0));
foreach ($awaitingStatement as $k => $username) {
    $m = $members[$username];
    $row(strtoupper($m['name']), $m['phone'], $m['phone'], $groupAccount, $m['monthly'], $k % 4 === 0 ? 'App contribution' : 'Member Contribution',
         $statementDay->setTime(8 + ($k % 10), ($k * 7) % 60));
    $statementTotal += $m['monthly'];
}
// A payment from a number that is not a member yet — the import flags it instead of guessing.
$row('MUSSA OMARI', '255700000199', '255700000199', $groupAccount, 20000, 'Member Contribution', $statementDay->setTime(19, 5));
// Money the group moved between its own accounts — not a member's contribution.
$row(WW_GROUP_NAME, '', $groupAccount, '70000000888', 250000, 'Group Transfer', $statementDay->setTime(20, 0));
fclose($fh);

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------
$fund    = getGroupFundBalance($pdo);
$funds   = fb_group_balances($pdo);
$social  = fb_social_fund_position($pdo);
$preview = dv_preview($pdo, $today->format('Y') . '-01-01', $today->format('Y-m-d'));
$loans   = $pdo->query("SELECT status, COUNT(*) FROM vicoba_loans GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

echo "\n== " . WW_GROUP_NAME . " — demo data ready ==\n";
echo "Members ............ " . count($members) . " (Chairperson, Secretary, Treasurer + 22 members)\n";
echo "Contributions ...... " . $pdo->query("SELECT COUNT(*) FROM contributions")->fetchColumn() . " rows\n";
echo "Share capital ...... TZS " . number_format($funds['shares']) . "\n";
echo "Social Fund ........ TZS " . number_format($social['balance']) . " (paid in " . number_format($social['paid_in']) . ", welfare out " . number_format($social['paid_out']) . ")\n";
echo "Deposits ........... TZS " . number_format($funds['deposit']) . "\n";
echo "Loans .............. " . implode(', ', array_map(static fn($s, $c) => "$c $s", array_keys($loans), $loans)) . "\n";
echo "Group fund ......... TZS " . number_format($fund) . "\n";
echo "Surplus this year .. TZS " . number_format($preview['figures']['surplus']) . " (ready for a dividend run)\n";
echo "M-Koba statement ... " . realpath($statementPath) . "\n";
echo "                     " . count($awaitingStatement) . " member payments (TZS " . number_format($statementTotal) . "), 1 unknown number, 1 group transfer, 1 account opening\n";
echo "\nLOGINS — password for every account:  $password\n";
echo "  Chairperson ..... {$chair['name']}  /  amollel\n";
echo "  Secretary ....... {$secretary['name']}  /  dkimaro\n";
echo "  Treasurer ....... {$treasurer['name']}  /  gmwakalinga\n";
echo "  Member (has a guarantee request waiting) ... {$members['cmbise']['name']}  /  cmbise\n";
echo "  Member (repaying a loan on time) ........... {$members['hmrisho']['name']}  /  hmrisho\n";
echo "  Member (loan overdue, with penalty) ........ {$members['sjuma']['name']}  /  sjuma\n";
echo "\nThe password is shown only here and is not stored anywhere readable. Note it down.\n";
