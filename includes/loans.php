<?php
/**
 * includes/loans.php
 * ------------------
 * The loan workflow: apply -> guarantors accept -> leadership approves ->
 * disbursed. Every page and endpoint goes through these functions, so the rules
 * are enforced in one place and a screen cannot skip one.
 *
 *   pending_guarantors  waiting on the guarantors the loan type requires
 *   pending             ready for leadership to decide
 *   approved            agreed, money not yet paid out
 *   disbursed           paid out; the repayment schedule exists from here on
 *   repaid | rejected | cancelled   closed
 *
 * The arithmetic lives in includes/loan_rules.php (pure). This file is the part
 * that reads and writes — and it never trusts a rate, a limit or a status from
 * the caller: rules come from the loan type, and every status change is a
 * conditional UPDATE so the same step cannot be taken twice.
 *
 * Each mutating function returns ['ok' => bool, 'message' => string, ...].
 */

require_once __DIR__ . '/loan_rules.php';
require_once __DIR__ . '/fund_buckets.php';
require_once __DIR__ . '/finance.php';

if (!function_exists('vl_open_statuses')) {
    /** Statuses in which a loan still counts against the member's borrowing limit. */
    function vl_open_statuses(): array
    {
        return ['pending_guarantors', 'pending', 'approved', 'disbursed'];
    }
}

if (!function_exists('vl_status_labels')) {
    /** @return array<string,array{0:string,1:string}> status => [label, bootstrap colour] */
    function vl_status_labels(bool $isSw): array
    {
        return [
            'pending_guarantors' => [$isSw ? 'Inasubiri wadhamini' : 'Awaiting guarantors', 'warning'],
            'pending'            => [$isSw ? 'Inasubiri idhini' : 'Awaiting approval', 'info'],
            'approved'           => [$isSw ? 'Imeidhinishwa' : 'Approved', 'primary'],
            'disbursed'          => [$isSw ? 'Umetolewa' : 'Disbursed', 'success'],
            'repaid'             => [$isSw ? 'Umelipwa wote' : 'Repaid', 'secondary'],
            'rejected'           => [$isSw ? 'Umekataliwa' : 'Rejected', 'danger'],
            'cancelled'          => [$isSw ? 'Umeghairiwa' : 'Cancelled', 'secondary'],
        ];
    }
}

if (!function_exists('vl_can_manage')) {
    /** May this session see every member's loans and act on them? (leadership) */
    function vl_can_manage(): bool
    {
        return canView('approve_loan') || canView('disburse_loan');
    }
}

if (!function_exists('vl_active_types')) {
    function vl_active_types(PDO $pdo): array
    {
        return $pdo->query("SELECT * FROM vicoba_loan_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_member_committed')) {
    /**
     * Principal the member is already on the hook for: what is still owed on
     * disbursed loans, plus the full amount of any loan still in the pipeline.
     * Counting the pipeline stops two applications each fitting the limit alone.
     */
    function vl_member_committed(PDO $pdo, int $customerId, int $exceptLoanId = 0): float
    {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.status = 'disbursed'
                                     THEN (SELECT COALESCE(SUM(i.principal_due - i.principal_paid), 0)
                                             FROM vicoba_loan_installments i WHERE i.loan_id = l.loan_id)
                                     ELSE l.amount END), 0)
               FROM vicoba_loans l
              WHERE l.customer_id = ? AND l.loan_id <> ?
                AND l.status IN (" . fb_sql_list(vl_open_statuses()) . ")"
        );
        $st->execute([$customerId, $exceptLoanId]);
        return (float) $st->fetchColumn();
    }
}

if (!function_exists('vl_member_limit')) {
    /**
     * What the member may borrow on a type right now.
     *
     * @return array{share_capital:float,committed:float,max:?float}  max null = no limit
     */
    function vl_member_limit(PDO $pdo, int $customerId, array $type): array
    {
        $shares    = (float) fb_member_balances($pdo, $customerId)['shares'];
        $committed = vl_member_committed($pdo, $customerId);
        return ['share_capital' => $shares, 'committed' => $committed, 'max' => lr_max_loan($shares, $type, $committed)];
    }
}

if (!function_exists('vl_member_name_sql')) {
    function vl_member_name_sql(string $alias): string
    {
        return "TRIM(CONCAT_WS(' ', NULLIF(TRIM({$alias}.first_name), ''), NULLIF(TRIM({$alias}.middle_name), ''), NULLIF(TRIM({$alias}.last_name), '')))";
    }
}

if (!function_exists('vl_eligible_guarantors')) {
    /** Active members other than the borrower. */
    function vl_eligible_guarantors(PDO $pdo, int $borrowerCustomerId): array
    {
        $st = $pdo->prepare(
            "SELECT c.customer_id, " . vl_member_name_sql('c') . " AS name
               FROM customers c
              WHERE c.status = 'active' AND c.customer_id <> ?
              ORDER BY c.first_name, c.last_name"
        );
        $st->execute([$borrowerCustomerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_apply')) {
    /**
     * A member's loan application.
     *
     * @param int[] $guarantorIds customer ids
     */
    function vl_apply(PDO $pdo, int $customerId, int $typeId, float $amount, int $months, string $purpose, array $guarantorIds, int $appliedBy, bool $isSw = false): array
    {
        $no = static fn(string $en, string $sw): array => ['ok' => false, 'message' => $isSw ? $sw : $en];

        $m = $pdo->prepare("SELECT status FROM customers WHERE customer_id = ?");
        $m->execute([$customerId]);
        if ($m->fetchColumn() !== 'active') {
            return $no('Only an active member can apply for a loan.', 'Mwanachama hai pekee ndiye anaweza kuomba mkopo.');
        }

        $t = $pdo->prepare("SELECT * FROM vicoba_loan_types WHERE id = ? AND is_active = 1");
        $t->execute([$typeId]);
        $type = $t->fetch(PDO::FETCH_ASSOC);
        if (!$type) {
            return $no('Choose a loan type.', 'Chagua aina ya mkopo.');
        }

        $amount = lr_money($amount);
        if ($amount <= 0) {
            return $no('Enter the amount you want to borrow.', 'Weka kiasi unachotaka kukopa.');
        }
        if ($months < 1 || $months > (int) $type['max_term_months']) {
            return $no(
                "The repayment period for this loan is 1 to {$type['max_term_months']} months.",
                "Muda wa marejesho kwa mkopo huu ni mwezi 1 hadi {$type['max_term_months']}."
            );
        }

        $limit = vl_member_limit($pdo, $customerId, $type);
        if ($limit['max'] !== null && $amount > $limit['max']) {
            $max = number_format($limit['max']);
            return $no("The most you can borrow on this loan right now is TZS {$max}.", "Kiasi cha juu unachoweza kukopa kwa sasa ni TZS {$max}.");
        }

        // Guarantors: exactly as many as the type requires, each a different
        // active member, none of them the borrower.
        $guarantorIds = array_values(array_unique(array_filter(array_map('intval', $guarantorIds), static fn($g) => $g > 0)));
        $required = (int) $type['guarantors_required'];
        if (in_array($customerId, $guarantorIds, true)) {
            return $no('You cannot guarantee your own loan.', 'Huwezi kujidhamini mwenyewe.');
        }
        if (count($guarantorIds) !== $required) {
            return $no("This loan needs {$required} different guarantor(s).", "Mkopo huu unahitaji wadhamini {$required} tofauti.");
        }
        if ($required > 0) {
            $in = implode(',', array_fill(0, count($guarantorIds), '?'));
            $g = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE status = 'active' AND customer_id IN ($in)");
            $g->execute($guarantorIds);
            if ((int) $g->fetchColumn() !== $required) {
                return $no('Every guarantor must be an active member.', 'Kila mdhamini lazima awe mwanachama hai.');
            }
        }

        $snap   = lr_snapshot($type);
        $totals = lr_schedule_totals(lr_schedule($amount, $snap['interest_rate'], $snap['interest_type'], $months, date('Y-m-d')));
        $status = $required > 0 ? 'pending_guarantors' : 'pending';

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO vicoba_loans
                    (reference, customer_id, loan_type_id, type_name, interest_rate, interest_type, guarantors_required,
                     penalty_type, penalty_value, grace_days, amount, term_months, total_interest, total_repayable,
                     purpose, status, applied_by, applied_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                'TMP-' . bin2hex(random_bytes(8)), $customerId, $typeId, $snap['type_name'], $snap['interest_rate'],
                $snap['interest_type'], $snap['guarantors_required'], $snap['penalty_type'], $snap['penalty_value'],
                $snap['grace_days'], $amount, $months, $totals['interest'], $totals['total'],
                mb_substr(trim($purpose), 0, 255) ?: null, $status, $appliedBy,
            ]);
            $loanId = (int) $pdo->lastInsertId();
            $reference = 'LN-' . date('Y') . '-' . str_pad((string) $loanId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE vicoba_loans SET reference = ? WHERE loan_id = ?")->execute([$reference, $loanId]);

            $ins = $pdo->prepare("INSERT INTO vicoba_loan_guarantors (loan_id, guarantor_customer_id) VALUES (?, ?)");
            foreach ($guarantorIds as $gid) {
                $ins->execute([$loanId, $gid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'loan_id' => $loanId, 'reference' => $reference, 'status' => $status,
                'message' => $isSw ? "Ombi la mkopo {$reference} limepokelewa." : "Loan application {$reference} submitted."];
    }
}

if (!function_exists('vl_respond_guarantee')) {
    /**
     * A guarantor's answer. Accepting moves the loan on once every guarantor has
     * accepted; declining ends the application — a loan cannot go ahead short of
     * a guarantor, so the member applies again with someone else.
     */
    function vl_respond_guarantee(PDO $pdo, int $loanId, int $guarantorCustomerId, bool $accept, string $note = '', bool $isSw = false): array
    {
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare(
                "UPDATE vicoba_loan_guarantors g
                   JOIN vicoba_loans l ON l.loan_id = g.loan_id AND l.status = 'pending_guarantors'
                    SET g.status = ?, g.note = ?, g.responded_at = NOW()
                  WHERE g.loan_id = ? AND g.guarantor_customer_id = ? AND g.status = 'pending'"
            );
            $up->execute([$accept ? 'accepted' : 'declined', mb_substr(trim($note), 0, 255) ?: null, $loanId, $guarantorCustomerId]);
            if ($up->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $isSw ? 'Ombi hili la udhamini halipo au limeshajibiwa.' : 'This guarantee request is not open.'];
            }

            if (!$accept) {
                $pdo->prepare("UPDATE vicoba_loans SET status = 'cancelled', rejection_reason = ? WHERE loan_id = ? AND status = 'pending_guarantors'")
                    ->execute(['A guarantor declined', $loanId]);
                $status = 'cancelled';
            } else {
                $left = $pdo->prepare("SELECT COUNT(*) FROM vicoba_loan_guarantors WHERE loan_id = ? AND status <> 'accepted'");
                $left->execute([$loanId]);
                $status = 'pending_guarantors';
                if ((int) $left->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE vicoba_loans SET status = 'pending' WHERE loan_id = ? AND status = 'pending_guarantors'")->execute([$loanId]);
                    $status = 'pending';
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'status' => $status,
                'message' => $accept ? ($isSw ? 'Umekubali kumdhamini.' : 'You have accepted to guarantee this loan.')
                                     : ($isSw ? 'Umekataa kumdhamini.' : 'You have declined to guarantee this loan.')];
    }
}

if (!function_exists('vl_is_own_loan')) {
    /** Is this loan the acting user's own? Nobody decides or pays out their own loan. */
    function vl_is_own_loan(PDO $pdo, int $loanId, int $userId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM vicoba_loans l JOIN customers c ON c.customer_id = l.customer_id WHERE l.loan_id = ? AND c.user_id = ?");
        $st->execute([$loanId, $userId]);
        return (bool) $st->fetchColumn();
    }
}

if (!function_exists('vl_decide')) {
    /** Leadership approves or rejects a loan that is awaiting approval. */
    function vl_decide(PDO $pdo, int $loanId, bool $approve, int $userId, string $reason = '', bool $isSw = false): array
    {
        if (vl_is_own_loan($pdo, $loanId, $userId)) {
            return ['ok' => false, 'message' => $isSw ? 'Huwezi kuamua mkopo wako mwenyewe.' : 'You cannot decide your own loan.'];
        }
        $reason = mb_substr(trim($reason), 0, 255);
        if (!$approve && $reason === '') {
            return ['ok' => false, 'message' => $isSw ? 'Andika sababu ya kukataa.' : 'Give a reason for rejecting.'];
        }

        $up = $approve
            ? $pdo->prepare("UPDATE vicoba_loans SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE loan_id = ? AND status = 'pending'")
            : $pdo->prepare("UPDATE vicoba_loans SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE loan_id = ? AND status = 'pending'");
        $up->execute($approve ? [$userId, $loanId] : [$userId, $reason, $loanId]);
        if ($up->rowCount() !== 1) {
            return ['ok' => false, 'message' => $isSw ? 'Mkopo huu hausubiri idhini.' : 'This loan is not awaiting approval.'];
        }
        return ['ok' => true, 'status' => $approve ? 'approved' : 'rejected',
                'message' => $approve ? ($isSw ? 'Mkopo umeidhinishwa.' : 'Loan approved.') : ($isSw ? 'Mkopo umekataliwa.' : 'Loan rejected.')];
    }
}

if (!function_exists('vl_disburse')) {
    /**
     * Pay an approved loan out and fix its repayment schedule from the
     * disbursement date. The group cannot lend money it does not hold.
     */
    function vl_disburse(PDO $pdo, int $loanId, int $userId, string $account, string $reference, string $date, bool $isSw = false): array
    {
        $no = static fn(string $en, string $sw): array => ['ok' => false, 'message' => $isSw ? $sw : $en];

        if (vl_is_own_loan($pdo, $loanId, $userId)) {
            return $no('You cannot disburse your own loan.', 'Huwezi kutoa mkopo wako mwenyewe.');
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            return $no('Enter a valid disbursement date, not in the future.', 'Weka tarehe sahihi ya kutoa mkopo, isiyo ya baadaye.');
        }
        if (!in_array($account, ['M-Koba', 'Bank', 'Cash', 'Mobile Money'], true)) {
            return $no('Choose the account the money was paid from.', 'Chagua akaunti iliyotoa fedha.');
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM vicoba_loans WHERE loan_id = ? AND status = 'approved' FOR UPDATE");
            $st->execute([$loanId]);
            $loan = $st->fetch(PDO::FETCH_ASSOC);
            if (!$loan) {
                $pdo->rollBack();
                return $no('This loan is not approved and waiting to be paid out.', 'Mkopo huu haujaidhinishwa au umeshatolewa.');
            }

            $available = getGroupFundBalance($pdo);
            if ((float) $loan['amount'] > $available) {
                $pdo->rollBack();
                $have = number_format($available);
                return $no("The group fund holds TZS {$have}, which does not cover this loan.", "Mfuko wa kikundi una TZS {$have}, hautoshi kwa mkopo huu.");
            }

            $schedule = lr_schedule((float) $loan['amount'], (float) $loan['interest_rate'], $loan['interest_type'], (int) $loan['term_months'], $date);
            $totals   = lr_schedule_totals($schedule);
            $ins = $pdo->prepare("INSERT INTO vicoba_loan_installments (loan_id, seq, due_date, principal_due, interest_due) VALUES (?, ?, ?, ?, ?)");
            foreach ($schedule as $line) {
                $ins->execute([$loanId, $line['seq'], $line['due_date'], $line['principal'], $line['interest']]);
            }

            $pdo->prepare(
                "UPDATE vicoba_loans
                    SET status = 'disbursed', disbursed_by = ?, disbursed_at = ?, disbursement_account = ?,
                        disbursement_reference = ?, total_interest = ?, total_repayable = ?
                  WHERE loan_id = ? AND status = 'approved'"
            )->execute([$userId, $date . ' ' . date('H:i:s'), $account, mb_substr(trim($reference), 0, 100) ?: null, $totals['interest'], $totals['total'], $loanId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'status' => 'disbursed', 'message' => $isSw ? 'Mkopo umetolewa.' : 'Loan disbursed.'];
    }
}

if (!function_exists('vl_cancel')) {
    /** The borrower withdraws an application that has not been decided yet. */
    function vl_cancel(PDO $pdo, int $loanId, int $customerId, bool $isSw = false): array
    {
        $up = $pdo->prepare("UPDATE vicoba_loans SET status = 'cancelled', rejection_reason = 'Withdrawn by the member' WHERE loan_id = ? AND customer_id = ? AND status IN ('pending_guarantors', 'pending')");
        $up->execute([$loanId, $customerId]);
        if ($up->rowCount() !== 1) {
            return ['ok' => false, 'message' => $isSw ? 'Ombi hili haliwezi kughairiwa.' : 'This application can no longer be withdrawn.'];
        }
        return ['ok' => true, 'status' => 'cancelled', 'message' => $isSw ? 'Ombi limeghairiwa.' : 'Application withdrawn.'];
    }
}

// ── Reads ────────────────────────────────────────────────────────────────────

if (!function_exists('vl_loan')) {
    function vl_loan(PDO $pdo, int $loanId): ?array
    {
        $st = $pdo->prepare(
            "SELECT l.*, " . vl_member_name_sql('c') . " AS member_name, c.user_id AS member_user_id
               FROM vicoba_loans l JOIN customers c ON c.customer_id = l.customer_id
              WHERE l.loan_id = ?"
        );
        $st->execute([$loanId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('vl_loans')) {
    /** Loans, newest first — every member's, or one member's when $customerId is given. */
    function vl_loans(PDO $pdo, ?int $customerId = null, string $status = ''): array
    {
        $where = [];
        $args  = [];
        if ($customerId !== null) { $where[] = 'l.customer_id = ?'; $args[] = $customerId; }
        if ($status !== '' && isset(vl_status_labels(false)[$status])) { $where[] = 'l.status = ?'; $args[] = $status; }

        $st = $pdo->prepare(
            "SELECT l.*, " . vl_member_name_sql('c') . " AS member_name,
                    (SELECT COALESCE(SUM(i.principal_due + i.interest_due - i.principal_paid - i.interest_paid), 0)
                       FROM vicoba_loan_installments i WHERE i.loan_id = l.loan_id) AS balance
               FROM vicoba_loans l JOIN customers c ON c.customer_id = l.customer_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
              ORDER BY l.loan_id DESC"
        );
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_guarantors')) {
    function vl_guarantors(PDO $pdo, int $loanId): array
    {
        $st = $pdo->prepare(
            "SELECT g.*, " . vl_member_name_sql('c') . " AS name
               FROM vicoba_loan_guarantors g JOIN customers c ON c.customer_id = g.guarantor_customer_id
              WHERE g.loan_id = ? ORDER BY g.id"
        );
        $st->execute([$loanId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_installments')) {
    function vl_installments(PDO $pdo, int $loanId): array
    {
        $st = $pdo->prepare("SELECT * FROM vicoba_loan_installments WHERE loan_id = ? ORDER BY seq");
        $st->execute([$loanId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_guarantee_requests')) {
    /** Loans this member has been asked to guarantee, open ones first. */
    function vl_guarantee_requests(PDO $pdo, int $customerId): array
    {
        $st = $pdo->prepare(
            "SELECT g.status AS guarantee_status, g.responded_at, l.loan_id, l.reference, l.type_name, l.amount,
                    l.term_months, l.status AS loan_status, " . vl_member_name_sql('c') . " AS member_name
               FROM vicoba_loan_guarantors g
               JOIN vicoba_loans l ON l.loan_id = g.loan_id
               JOIN customers c ON c.customer_id = l.customer_id
              WHERE g.guarantor_customer_id = ?
              ORDER BY (g.status = 'pending' AND l.status = 'pending_guarantors') DESC, g.id DESC"
        );
        $st->execute([$customerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_may_view')) {
    /** A loan is visible to leadership, to its borrower and to its guarantors. */
    function vl_may_view(PDO $pdo, array $loan, int $userId, ?int $customerId): bool
    {
        if (vl_can_manage() || (int) $loan['member_user_id'] === $userId) {
            return true;
        }
        if ($customerId === null) {
            return false;
        }
        $st = $pdo->prepare("SELECT 1 FROM vicoba_loan_guarantors WHERE loan_id = ? AND guarantor_customer_id = ?");
        $st->execute([(int) $loan['loan_id'], $customerId]);
        return (bool) $st->fetchColumn();
    }
}

// ── Repayment ────────────────────────────────────────────────────────────────

if (!function_exists('vl_rules')) {
    /** The loan's own rules (its snapshot), in the shape loan_rules.php expects. */
    function vl_rules(array $loan): array
    {
        return [
            'interest_type' => (string) $loan['interest_type'],
            'penalty_type'  => (string) $loan['penalty_type'],
            'penalty_value' => (float) $loan['penalty_value'],
            'grace_days'    => (int) $loan['grace_days'],
        ];
    }
}

if (!function_exists('vl_payments')) {
    function vl_payments(PDO $pdo, int $loanId): array
    {
        $st = $pdo->prepare("SELECT * FROM vicoba_loan_payments WHERE loan_id = ? ORDER BY payment_date, id");
        $st->execute([$loanId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('vl_record_payment')) {
    /**
     * Record a repayment received against a disbursed loan and apply it to the
     * schedule (lr_allocate_payment() decides how). Clears the loan when the last
     * instalment is paid.
     */
    function vl_record_payment(PDO $pdo, int $loanId, float $amount, string $date, string $account, string $receipt, string $note, int $userId, bool $isSw = false): array
    {
        $no = static fn(string $en, string $sw): array => ['ok' => false, 'message' => $isSw ? $sw : $en];

        if (vl_is_own_loan($pdo, $loanId, $userId)) {
            return $no('You cannot record a repayment on your own loan.', 'Huwezi kurekodi rejesho la mkopo wako mwenyewe.');
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            return $no('Enter a valid payment date, not in the future.', 'Weka tarehe sahihi ya malipo, isiyo ya baadaye.');
        }
        if (!in_array($account, ['M-Koba', 'Bank', 'Cash', 'Mobile Money'], true)) {
            return $no('Choose the account the money was received into.', 'Chagua akaunti iliyopokea fedha.');
        }
        $amount = lr_money($amount);
        if ($amount <= 0) {
            return $no('Enter the amount received.', 'Weka kiasi kilichopokelewa.');
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT * FROM vicoba_loans WHERE loan_id = ? AND status = 'disbursed' FOR UPDATE");
            $st->execute([$loanId]);
            $loan = $st->fetch(PDO::FETCH_ASSOC);
            if (!$loan) {
                $pdo->rollBack();
                return $no('Repayments can only be recorded on a loan that has been paid out and is not yet cleared.', 'Marejesho hurekodiwa kwa mkopo uliotolewa na ambao haujaisha tu.');
            }
            if ($date < substr((string) $loan['disbursed_at'], 0, 10)) {
                $pdo->rollBack();
                return $no('The payment date cannot be before the loan was paid out.', 'Tarehe ya malipo haiwezi kuwa kabla ya mkopo kutolewa.');
            }

            $installments = vl_installments($pdo, $loanId);
            $alloc = lr_allocate_payment($installments, $amount, $date, vl_rules($loan));
            if (!$alloc['ok']) {
                $pdo->rollBack();
                $max = number_format((float) $alloc['max'], 2);
                return $no("The most that can be received on this loan today is TZS {$max}.", "Kiasi cha juu kinachoweza kupokelewa kwa mkopo huu leo ni TZS {$max}.");
            }

            $up = $pdo->prepare(
                "UPDATE vicoba_loan_installments
                    SET principal_paid = principal_paid + ?, interest_paid = interest_paid + ?, penalty_paid = penalty_paid + ?,
                        interest_due = interest_due - ?, status = ?, paid_at = IF(? = 'paid', COALESCE(paid_at, ?), paid_at)
                  WHERE loan_id = ? AND seq = ?"
            );
            foreach ($alloc['lines'] as $l) {
                $up->execute([$l['principal'], $l['interest'], $l['penalty'], $l['waive_interest'], $l['status'], $l['status'], $date . ' 00:00:00', $loanId, $l['seq']]);
            }

            $pdo->prepare(
                "INSERT INTO vicoba_loan_payments (loan_id, payment_date, amount, principal_part, interest_part, penalty_part, account, receipt_number, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $loanId, $date, $amount, $alloc['principal'], $alloc['interest'], $alloc['penalty'], $account,
                mb_substr(trim($receipt), 0, 100) ?: null, mb_substr(trim($note), 0, 255) ?: null, $userId,
            ]);

            if ($alloc['settled']) {
                $pdo->prepare("UPDATE vicoba_loans SET status = 'repaid', repaid_at = ?, total_interest = total_interest - ?, total_repayable = total_repayable - ? WHERE loan_id = ?")
                    ->execute([$date . ' ' . date('H:i:s'), $alloc['waived_interest'], $alloc['waived_interest'], $loanId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'status' => $alloc['settled'] ? 'repaid' : 'disbursed', 'allocation' => $alloc,
                'message' => $alloc['settled'] ? ($isSw ? 'Rejesho limepokelewa. Mkopo umelipwa wote.' : 'Repayment recorded. The loan is fully repaid.')
                                               : ($isSw ? 'Rejesho limepokelewa.' : 'Repayment recorded.')];
    }
}

if (!function_exists('vl_member_loan_summary')) {
    /**
     * What a member owes across their disbursed loans today, and the next
     * instalment due — for Member Home.
     *
     * @return array{loans:int,owed:float,next_due:?string,next_amount:float}
     */
    function vl_member_loan_summary(PDO $pdo, int $customerId, ?string $asOf = null): array
    {
        $asOf = $asOf ?? date('Y-m-d');
        $out  = ['loans' => 0, 'owed' => 0.0, 'next_due' => null, 'next_amount' => 0.0];
        $st = $pdo->prepare("SELECT * FROM vicoba_loans WHERE customer_id = ? AND status = 'disbursed'");
        $st->execute([$customerId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $loan) {
            $out['loans']++;
            $rules = vl_rules($loan);
            foreach (vl_installments($pdo, (int) $loan['loan_id']) as $i) {
                $unpaid = lr_installment_unpaid($i);
                if ($unpaid <= 0) {
                    continue;
                }
                $due = $unpaid + lr_installment_penalty_due($i, $asOf, $rules);
                $out['owed'] += $due;
                if ($out['next_due'] === null || $i['due_date'] < $out['next_due']) {
                    $out['next_due']    = $i['due_date'];
                    $out['next_amount'] = $due;
                }
            }
        }
        $out['owed'] = lr_money($out['owed']);
        $out['next_amount'] = lr_money($out['next_amount']);
        return $out;
    }
}
