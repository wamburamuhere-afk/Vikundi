<?php
/**
 * includes/demo_data.php — sample data for a demo group, and taking it out again.
 *
 * A group created with the panel's "Demo group" tick gets this right after its
 * first Chairperson (bin/provision_tenant.php --create --demo). When the prospect
 * signs up, "Clear demo data" (--clear-demo) removes every sample and the group
 * becomes a real one: the Chairperson's login, the group's name and any setting
 * they changed stay.
 *
 * Everything added is invented. Sample people have e-mails on the reserved
 * demo.example domain (that is how the clear finds them) and phones in the
 * made-up 2557000003xx block, so nothing can reach a real person. They have no
 * usable password: only the real Chairperson signs in.
 *
 * Loans go through the real workflow (includes/loans.php), so every figure on
 * screen is one the application worked out. Loan limits are fixed amounts, not
 * a multiple of shares, so the samples work whether or not Shares is switched on.
 */

require_once __DIR__ . '/loans.php';

const VK_DEMO_EMAIL_DOMAIN = 'demo.example';
const VK_DEMO_MARKER_KEY   = 'demo_seed'; // group_settings: what the seed added, for the clear

if (!function_exists('vk_demo_roster')) {
    /**
     * The sample people around the real Chairperson.
     * [first, last, gender, role, months since joining]
     */
    function vk_demo_roster(): array
    {
        return [
            ['Daudi', 'Kimaro', 'Male', 'Secretary', 6],
            ['Grace', 'Mwakalinga', 'Female', 'Treasurer', 6],
            ['Salim', 'Juma', 'Male', 'Member', 6],
            ['Neema', 'Lyimo', 'Female', 'Member', 6],
            ['Baraka', 'Mushi', 'Male', 'Member', 6],
            ['Zawadi', 'Massawe', 'Female', 'Member', 6],
            ['Hassan', 'Mrisho', 'Male', 'Member', 6],
            ['Rehema', 'Shayo', 'Female', 'Member', 6],
            ['Emmanuel', 'Minja', 'Male', 'Member', 6],
            ['Halima', 'Rajabu', 'Female', 'Member', 6],
            ['Joseph', 'Swai', 'Male', 'Member', 6],
            ['Mwanaidi', 'Hamisi', 'Female', 'Member', 6],
            ['Peter', 'Urassa', 'Male', 'Member', 6],
            ['Asha', 'Mbwana', 'Female', 'Member', 6],
            ['Godfrey', 'Temba', 'Male', 'Member', 6],
            ['Mariam', 'Kileo', 'Female', 'Member', 6],
            ['Elias', 'Sanga', 'Male', 'Member', 5],
            ['Upendo', 'Laizer', 'Female', 'Member', 3],
            ['Anna', 'Kessy', 'Female', 'Member', 1],
        ];
    }
}

if (!function_exists('vk_demo_person')) {
    /** The n-th sample person's login, phone, e-mail and member number (n from 1). */
    function vk_demo_person(int $n, string $first, string $last): array
    {
        $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        return [
            'username' => strtolower($first[0] . $last), // the app's own convention
            'phone'    => '2557000003' . $nn,
            'email'    => strtolower("$first.$last") . '@' . VK_DEMO_EMAIL_DOMAIN,
            'code'     => 'DEMO-' . $nn,
        ];
    }
}

if (!function_exists('vk_demo_settings')) {
    /** Group settings the seed fills in when the group has not set them. */
    function vk_demo_settings(DateTimeImmutable $start): array
    {
        return [
            'currency'                => 'TZS',
            'cycle_type'              => 'monthly',
            'monthly_contribution'    => '20000',
            'social_fund_amount'      => '5000',
            'deadline_day'            => '10',
            'contribution_start_date' => $start->format('Y-m-d'),
            'group_founded_date'      => $start->format('Y-m-d'),
        ];
    }
}

if (!function_exists('vk_demo_clear_plan')) {
    /**
     * What "Clear demo data" removes.
     *  wipe:      the group's money records, emptied completely (in a demo group they are all samples);
     *  by_member: other records, removed only for the sample people.
     */
    function vk_demo_clear_plan(): array
    {
        return [
            'wipe' => [
                'vicoba_dividend_lines', 'vicoba_dividend_runs',
                'vicoba_loan_payments', 'vicoba_loan_installments', 'vicoba_loan_guarantors', 'vicoba_loans',
                'contributions', 'fines', 'general_expenses', 'death_expenses', 'member_payouts', 'mkoba_statement_rows',
            ],
            'by_member' => [
                'meeting_attendance' => 'member_id', 'leadership_applications' => 'member_id',
                'vote_eligibility' => 'member_id', 'vote_participation' => 'member_id', 'vote_options' => 'member_id',
                'notifications' => 'customer_id', 'customer_contacts' => 'customer_id',
                'customer_attachments' => 'customer_id', 'customer_additional_attachments' => 'customer_id',
            ],
        ];
    }
}

if (!function_exists('vk_demo_table_exists')) {
    function vk_demo_table_exists(PDO $pdo, string $table): bool
    {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$table]);
        return (int) $s->fetchColumn() > 0;
    }
}

if (!function_exists('vk_demo_seed')) {
    /**
     * Fill a just-created group with sample data. Only a group that has nothing but
     * its first Chairperson is accepted. Throws RuntimeException with the reason.
     *
     * @return array<string,int> what was added
     */
    function vk_demo_seed(PDO $pdo, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $count = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();

        if ($count('SELECT COUNT(*) FROM users') !== 1 || $count('SELECT COUNT(*) FROM customers') !== 1) {
            throw new RuntimeException('demo data goes only into a new group that has just its first Chairperson.');
        }
        if ($count('SELECT COUNT(*) FROM contributions') + $count('SELECT COUNT(*) FROM vicoba_loans') > 0) {
            throw new RuntimeException('this group already has contributions or loans.');
        }
        $chair = $pdo->query('SELECT u.user_id, c.customer_id, u.username FROM users u JOIN customers c ON c.user_id = u.user_id')->fetch(PDO::FETCH_ASSOC);
        if (!$chair) {
            throw new RuntimeException('the first Chairperson has no member record.');
        }
        $roles = $pdo->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['Secretary', 'Treasurer', 'Member'] as $r) {
            if (empty($roles[$r])) {
                throw new RuntimeException("role $r is missing; run the migrations first.");
            }
        }

        mt_srand(20261029); // the same amounts every time
        $thisMonth = $today->modify('first day of this month');
        $start = $thisMonth->modify('-6 months');
        $marker = ['settings' => [], 'loan_types' => []];

        // 1) Settings the group has not set yet. The clear removes them again unless changed.
        $has = $pdo->prepare('SELECT COUNT(*) FROM group_settings WHERE setting_key = ?');
        $set = $pdo->prepare('INSERT INTO group_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach (vk_demo_settings($start) as $k => $v) {
            $has->execute([$k]);
            if ((int) $has->fetchColumn() === 0) {
                $set->execute([$k, $v]);
                $marker['settings'][$k] = $v;
            }
        }

        // 2) Sample people. No usable password: a random one nobody ever sees.
        $taken = $pdo->query('SELECT username FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $insUser = $pdo->prepare(
            "INSERT INTO users (username, password, email, phone, role, user_role, role_id, status, is_active, is_admin,
                                first_name, last_name, language, preferred_language, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, 0, ?, ?, 'en', 'en', ?)"
        );
        $insMember = $pdo->prepare(
            "INSERT INTO customers (user_id, first_name, last_name, customer_code, customer_name, customer_type, gender,
                                    phone, mobile, email, status, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, 'individual', ?, ?, ?, ?, 'active', ?, ?)"
        );
        $people = [];
        foreach (vk_demo_roster() as $i => [$first, $last, $gender, $role, $monthsIn]) {
            $p = vk_demo_person($i + 1, $first, $last);
            if (in_array($p['username'], $taken, true)) {
                $p['username'] .= ($i + 1);
            }
            $joined = $thisMonth->modify("-$monthsIn months")->format('Y-m-d H:i:s');
            $insUser->execute([$p['username'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $p['email'], $p['phone'],
                               $role, $role, (int) $roles[$role], $first, $last, $joined]);
            $uid = (int) $pdo->lastInsertId();
            $insMember->execute([$uid, $first, $last, $p['code'], "$first $last", $gender, $p['phone'], $p['phone'], $p['email'], $joined, $uid]);
            $people[$p['username']] = ['user_id' => $uid, 'customer_id' => (int) $pdo->lastInsertId(), 'role' => $role,
                                       'joined' => $thisMonth->modify("-$monthsIn months"), 'phone' => $p['phone']];
        }
        $treasurer = $people['gmwakalinga']['user_id'];
        $secretary = $people['dkimaro']['user_id'];
        $cid = static fn(string $u): int => $people[$u]['customer_id'];

        // 3) Monthly contribution and Social Fund each month. Two members are behind,
        //    and about half have not paid this month yet.
        $insC = $pdo->prepare(
            "INSERT INTO contributions (member_id, amount, contribution_type, contribution_date, description, status,
                                        account, receipt_number, created_by, created_at, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?)"
        );
        $receipt = 5000;
        $pay = static function (int $customerId, float $amount, string $type, DateTimeImmutable $on, string $what) use ($insC, &$receipt, $treasurer): void {
            $stamp = $on->format('Y-m-d') . ' 10:00:00';
            $insC->execute([$customerId, $amount, $type, $on->format('Y-m-d'), $what, mt_rand(0, 3) ? 'Mobile Money' : 'Cash',
                            'DEMO-RC' . (++$receipt), $treasurer, $stamp, $treasurer, $stamp]);
        };
        $behind = ['hrajabu' => [2], 'purassa' => [1, 3]];
        $contributions = 0;
        $n = 0;
        foreach ($people as $u => $m) {
            $n++;
            for ($month = $m['joined'], $i = 0; $month <= $thisMonth; $month = $month->modify('+1 month'), $i++) {
                if (in_array($i, $behind[$u] ?? [], true) || ($month == $thisMonth && $n % 2 === 0)) {
                    continue;
                }
                $on = min($month->modify('+' . mt_rand(1, 8) . ' days'), $today);
                $pay($m['customer_id'], 20000, 'monthly', $on, 'Monthly contribution');
                $pay($m['customer_id'], 5000, 'social_fund', $on, 'Social Fund');
                $contributions += 2;
            }
        }
        // The real Chairperson joined today: this month only.
        $pay((int) $chair['customer_id'], 20000, 'monthly', $today, 'Monthly contribution');
        $pay((int) $chair['customer_id'], 5000, 'social_fund', $today, 'Social Fund');
        $contributions += 2;

        // 4) Fines, running costs, and the Social Fund at work: a funeral and a wedding.
        $ago = static fn(string $spec): DateTimeImmutable => $today->modify('-' . $spec);
        $insFine = $pdo->prepare('INSERT INTO fines (customer_id, amount, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([
            ['bmushi', 5000, 'Late to the monthly meeting', 'paid', '4 months'],
            ['hrajabu', 5000, 'Missed contribution deadline', 'paid', '3 months'],
            ['esanga', 3000, 'Late to the monthly meeting', 'paid', '2 months'],
            ['purassa', 5000, 'Missed contribution deadline', 'pending', '20 days'],
        ] as [$who, $amount, $reason, $status, $when]) {
            $at = $ago($when)->format('Y-m-d 18:00:00');
            $insFine->execute([$cid($who), $amount, $reason, $status, $at, $at]);
        }
        $insExp = $pdo->prepare(
            "INSERT INTO general_expenses (expense_date, description, amount, status, created_at, created_by, approved_at, approved_by, paid_at, paid_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ([['5 months', 'Receipt books and stationery', 8000, 'paid'], ['2 months', 'Meeting venue hire', 10000, 'paid'],
                  ['3 days', 'Printing of the half-year report', 12000, 'pending']] as [$when, $what, $amount, $status]) {
            $d = $ago($when);
            $paid = $status === 'paid';
            $insExp->execute([$d->format('Y-m-d'), $what, $amount, $status, $d->format('Y-m-d 11:00:00'), $secretary,
                              $paid ? $d->format('Y-m-d 12:00:00') : null, $paid ? (int) $chair['user_id'] : null,
                              $paid ? $d->format('Y-m-d 15:00:00') : null, $paid ? $treasurer : null]);
        }
        $d = $ago('3 months');
        $pdo->prepare(
            "INSERT INTO death_expenses (member_id, phone_number, deceased_type, deceased_name, deceased_relationship, amount, description,
                                         status, expense_date, created_at, created_by, approved_by, approved_at, paid_at, paid_by)
             VALUES (?, ?, 'parent', 'Sample record, not a real person', 'Parent', 150000, 'Funeral support from the Social Fund',
                     'paid', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$cid('zmassawe'), $people['zmassawe']['phone'], $d->format('Y-m-d'), $d->format('Y-m-d 09:00:00'),
                    $secretary, (int) $chair['user_id'], $d->format('Y-m-d 10:00:00'), $d->format('Y-m-d 14:00:00'), $treasurer]);
        $pdo->prepare("INSERT INTO member_payouts (member_id, amount, description, payout_date, status, created_at) VALUES (?, ?, ?, ?, 'paid', ?)")
            ->execute([$cid('nlyimo'), 100000, 'Wedding contribution from the Social Fund', $ago('5 weeks')->format('Y-m-d'), $ago('5 weeks')->format('Y-m-d 12:00:00')]);

        // 5) Two loan types with fixed limits.
        $insType = $pdo->prepare(
            "INSERT INTO vicoba_loan_types (name, description, interest_rate, interest_type, max_term_months, max_share_multiple,
                                            max_amount, guarantors_required, penalty_type, penalty_value, grace_days, created_by)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)"
        );
        $insType->execute(['Emergency Loan', 'Small loan for urgent needs (sample)', 3, 'flat', 3, 300000, 1, 'fixed', 2000, 3, (int) $chair['user_id']]);
        $emergency = (int) $pdo->lastInsertId();
        $insType->execute(['Development Loan', 'Business, farming or school fees (sample)', 2, 'reducing', 6, 1000000, 2, 'percent', 5, 5, (int) $chair['user_id']]);
        $development = (int) $pdo->lastInsertId();
        $marker['loan_types'] = [$emergency, $development];

        // 6) Loans in each state, through the real workflow.
        $must = static function (array $res, string $step): array {
            if (empty($res['ok'])) {
                throw new RuntimeException("demo loans stopped at \"$step\": " . ($res['message'] ?? 'unknown error'));
            }
            return $res;
        };
        $approver = (int) $chair['user_id'];
        $apply = static function (string $who, int $type, float $amount, int $months, string $purpose, array $guarantors) use ($pdo, $must, $cid, $people): int {
            $loan = $must(vl_apply($pdo, $cid($who), $type, $amount, $months, $purpose, array_map($cid, $guarantors), $people[$who]['user_id']), "apply ($who)");
            foreach ($guarantors as $g) {
                $must(vl_respond_guarantee($pdo, (int) $loan['loan_id'], $cid($g), true), "guarantee ($g)");
            }
            return (int) $loan['loan_id'];
        };
        $running = static function (int $loanId, DateTimeImmutable $paidOut, int $paid) use ($pdo, $must, $treasurer, $approver): void {
            $must(vl_decide($pdo, $loanId, true, $approver), "approve (#$loanId)");
            $must(vl_disburse($pdo, $loanId, $treasurer, 'Mobile Money', 'DEMO-OUT-' . $loanId, $paidOut->format('Y-m-d')), "pay out (#$loanId)");
            $pdo->prepare('UPDATE vicoba_loans SET applied_at = ?, approved_at = ? WHERE loan_id = ?')
                ->execute([$paidOut->modify('-5 days')->format('Y-m-d 09:00:00'), $paidOut->modify('-2 days')->format('Y-m-d 16:00:00'), $loanId]);
            foreach (array_slice(vl_installments($pdo, $loanId), 0, $paid) as $i) {
                $must(vl_record_payment($pdo, $loanId, (float) $i['principal_due'] + (float) $i['interest_due'], $i['due_date'],
                                        'Mobile Money', 'DEMO-IN-' . $loanId . '-' . $i['seq'], '', $treasurer), "repayment (#$loanId)");
            }
        };
        // Repaid in full; being repaid on time; overdue; waiting for approval.
        $running($apply('nlyimo', $development, 300000, 3, 'Tailoring business stock', ['jswai', 'ambwana']), $ago('5 months'), 3);
        $running($apply('hmrisho', $development, 500000, 5, 'School fees', ['esanga', 'rshayo']), $ago('2 months')->modify('-10 days'), 2);
        $running($apply('sjuma', $emergency, 150000, 3, 'Hospital bill', ['bmushi']), $ago('2 months')->modify('-20 days'), 1);
        $apply('ambwana', $development, 400000, 4, 'Poultry project', ['mkileo', 'gtemba']);

        $pdo->prepare('INSERT INTO group_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute([VK_DEMO_MARKER_KEY, json_encode($marker)]);

        return [
            'members'       => count($people),
            'contributions' => $contributions,
            'loans'         => (int) $pdo->query('SELECT COUNT(*) FROM vicoba_loans')->fetchColumn(),
        ];
    }
}

if (!function_exists('vk_demo_clear')) {
    /**
     * Remove every sample: the money records, the sample people, the sample loan
     * types and the settings the seed filled in (unless the group changed them).
     * The real Chairperson, other real logins and the group's own settings stay.
     * All or nothing.
     *
     * @return array<string,int> rows removed per table
     */
    function vk_demo_clear(PDO $pdo): array
    {
        $plan = vk_demo_clear_plan();
        $removed = [];
        $pdo->beginTransaction();
        try {
            foreach ($plan['wipe'] as $t) {
                if (vk_demo_table_exists($pdo, $t)) {
                    $removed[$t] = $pdo->exec("DELETE FROM `$t`");
                }
            }

            $like = '%@' . VK_DEMO_EMAIL_DOMAIN;
            $s = $pdo->prepare('SELECT user_id FROM users WHERE email LIKE ?');
            $s->execute([$like]);
            $userIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            $s = $pdo->prepare('SELECT customer_id FROM customers WHERE email LIKE ?');
            $s->execute([$like]);
            $customerIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

            if ($customerIds) {
                $in = implode(',', $customerIds); // integers only
                foreach ($plan['by_member'] as $t => $col) {
                    if (vk_demo_table_exists($pdo, $t)) {
                        $removed[$t] = $pdo->exec("DELETE FROM `$t` WHERE `$col` IN ($in)");
                    }
                }
                $removed['customers'] = $pdo->exec("DELETE FROM customers WHERE customer_id IN ($in)");
            }
            if ($userIds) {
                $removed['users'] = $pdo->exec('DELETE FROM users WHERE user_id IN (' . implode(',', $userIds) . ')');
            }

            $raw = $pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = '" . VK_DEMO_MARKER_KEY . "'")->fetchColumn();
            $marker = json_decode((string) $raw, true);
            $marker = is_array($marker) ? $marker : [];
            $ids = array_map('intval', (array) ($marker['loan_types'] ?? []));
            if ($ids) {
                $removed['vicoba_loan_types'] = $pdo->exec('DELETE FROM vicoba_loan_types WHERE id IN (' . implode(',', $ids) . ')');
            }
            $del = $pdo->prepare('DELETE FROM group_settings WHERE setting_key = ? AND setting_value = ?');
            $removed['group_settings'] = 0;
            foreach ((array) ($marker['settings'] ?? []) as $k => $v) {
                $del->execute([(string) $k, (string) $v]);
                $removed['group_settings'] += $del->rowCount();
            }
            $pdo->prepare('DELETE FROM group_settings WHERE setting_key = ?')->execute([VK_DEMO_MARKER_KEY]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $removed;
    }
}
