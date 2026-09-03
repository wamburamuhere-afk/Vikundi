<?php
/**
 * includes/api_expenses.php — the shared rules for the General Expenses module.
 *
 * Deliberately requires only config-free files, so it is testable in CI.
 *
 * PERMISSION KEY: `expenses`. Unlike Condolences, this is not a case of a
 * Member's own `view` grant being misread as group-wide access — the group's
 * ONLY general-expenses screen (app/constant/accounts/general_expenses.php)
 * and its already-audited API (api/get_general_expenses.php, hardened under
 * SEC-003/004/005) both gate the WHOLE list on `view`, and Member does hold
 * that grant live on demo/production today, verified directly against the web
 * page. This mirrors that faithfully rather than inventing a stricter,
 * `edit`-based test the web has never had for this module.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/expense_helpers.php';    // vk_expense_member_id()
require_once __DIR__ . '/../core/workflow.php';   // assertReviewable(), assertApprovable(), signatures
require_once __DIR__ . '/finance.php';            // getGroupFundBalance()

if (!function_exists('vk_api_expenses_statuses')) {
    /** The status enum, as the column declares it. */
    function vk_api_expenses_statuses(): array
    {
        return ['pending', 'reviewed', 'approved', 'rejected', 'paid'];
    }
}

if (!function_exists('vk_api_expenses_str')) {
    /** Trimmed string, or null when there is nothing there. */
    function vk_api_expenses_str($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s !== '' ? $s : null;
    }
}

if (!function_exists('vk_api_expenses_amount')) {
    /** Validate a submitted expense amount, returning it rounded. */
    function vk_api_expenses_amount($raw): float
    {
        $clean = str_replace([',', ' '], '', (string) $raw);
        if ($clean === '' || !is_numeric($clean)) {
            vk_api_error(422, 'invalid_amount', 'amount is required and must be a number.');
        }
        $amount = round((float) $clean, 2);
        if ($amount <= 0) {
            vk_api_error(422, 'invalid_amount', 'amount must be greater than zero.');
        }
        if ($amount > 9999999999999.99) {
            vk_api_error(422, 'invalid_amount', 'amount is too large.');
        }
        return $amount;
    }
}

if (!function_exists('vk_api_expenses_row')) {
    /** One general expense, as the app renders it. */
    function vk_api_expenses_row(array $r): array
    {
        $memberId = $r['member_id'] !== null ? (int) $r['member_id'] : null;

        return [
            'id'          => (int) $r['id'],
            'expense_date'=> (string) $r['expense_date'],
            'description' => (string) $r['description'],
            'amount'      => (float) $r['amount'],
            'status'      => (string) ($r['status'] ?? 'pending'),
            'member'      => $memberId !== null ? [
                'id'   => $memberId,
                'name' => trim((string) ($r['member_name'] ?? '')),
            ] : null,
            'created_at'  => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'reviewed_at' => !empty($r['reviewed_at'])
                ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            'approved_at' => !empty($r['approved_at'])
                ? date(DATE_ATOM, strtotime((string) $r['approved_at'])) : null,
            'paid_at'     => !empty($r['paid_at'])
                ? date(DATE_ATOM, strtotime((string) $r['paid_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_expenses_actions')) {
    /** What THIS caller may do to an expense in this status. */
    function vk_api_expenses_actions(array $auth, string $status): array
    {
        $canReview   = vk_api_can($auth, 'review', 'expenses') && vk_api_can($auth, 'view', 'expenses');
        $canApprove  = vk_api_can($auth, 'approve', 'expenses') && vk_api_can($auth, 'view', 'expenses');
        $canEdit     = vk_api_can($auth, 'edit', 'expenses');
        $canMarkPaid = vk_api_is_admin((int) ($auth['role_id'] ?? 0)) || vk_api_expenses_may_mark_paid($auth);

        return [
            'edit'      => $canEdit && !in_array($status, ['approved', 'paid'], true),
            'review'    => $canReview && $status === 'pending',
            'approve'   => $canApprove && $status === 'reviewed',
            'mark_paid' => $canMarkPaid && $status === 'approved',
        ];
    }
}

if (!function_exists('vk_api_expenses_may_mark_paid')) {
    /**
     * Delegates to the web's own canMarkPaid() rule (core/permissions.php) —
     * reserved for the Treasurer and full admins, the people who actually
     * release the group's money. Deliberately role-based, not a
     * `role_permissions` grant: marking something paid is not a page-level
     * permission, it is "may this account confirm a disbursement happened."
     */
    function vk_api_expenses_may_mark_paid(array $auth): bool
    {
        if (vk_api_is_admin((int) ($auth['role_id'] ?? 0))) {
            return true;
        }
        if ((int) ($auth['role_id'] ?? 0) === 4) {
            return true;
        }
        $treasurerNames = ['treasurer', 'mweka hazina', 'mweka-hazina', 'mhasibu'];
        $roleName = strtolower((string) ($auth['user']['user_role'] ?? ($auth['user']['role_name'] ?? '')));
        return $roleName !== '' && in_array($roleName, $treasurerNames, true);
    }
}

if (!function_exists('vk_api_expenses_load')) {
    /** One expense by id, with its member's name, or a 404. */
    function vk_api_expenses_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'An expense id is required.');
        }
        $st = $pdo->prepare(
            "SELECT ge.*,
                    TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
               FROM general_expenses ge
               LEFT JOIN customers c ON c.customer_id = ge.member_id
              WHERE ge.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No expense was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_expenses_filters')) {
    /**
     * The query filters the list endpoint accepts, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_expenses_filters(array $q, string $alias = 'ge'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_expenses_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_api_expenses_statuses()) . '.');
            }
            $where[]  = "{$a}status = ?";
            $params[] = $status;
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            $raw = trim((string) ($q[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw) {
                vk_api_error(422, 'invalid_date', $key . ' must be a date in YYYY-MM-DD format.');
            }
            $where[]  = "{$a}expense_date {$op} ?";
            $params[] = $raw;
        }

        // 'general' = whole-org only, 'member' = charged to a member, else all.
        $scope = trim((string) ($q['scope'] ?? ''));
        if ($scope === 'general') {
            $where[] = "{$a}member_id IS NULL";
        } elseif ($scope === 'member') {
            $where[] = "{$a}member_id IS NOT NULL";
        }

        $memberId = (int) ($q['member_id'] ?? 0);
        if ($memberId > 0) {
            $where[]  = "{$a}member_id = ?";
            $params[] = $memberId;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR {$a}description LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_expenses_can_transition')) {
    /**
     * The workflow's FROM-status guard, as pure logic — no PDO required.
     * Extracted so it is directly unit-testable, same reason as
     * includes/api_death_expenses.php's vk_api_death_can_transition().
     */
    function vk_api_expenses_can_transition(string $from, array $allowedFrom): bool
    {
        return in_array($from, $allowedFrom, true);
    }
}

if (!function_exists('vk_api_expenses_fund_sufficient')) {
    /** The approve-only fund-balance guard, as pure logic. */
    function vk_api_expenses_fund_sufficient(float $available, float $amount): bool
    {
        return $available >= $amount;
    }
}

if (!function_exists('vk_api_expenses_transition')) {
    /**
     * Run one workflow transition (review or approve) and return the fresh row.
     * Mirrors vk_api_death_transition() / api/review_general_expense.php /
     * api/approve_general_expense.php.
     *
     * @return array{row:array, from:string}
     */
    function vk_api_expenses_transition(
        PDO $pdo,
        array $auth,
        int $id,
        string $to,
        array $allowedFrom,
        string $signatureAction,
        string $logVerb
    ): array {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'An expense id is required.');
        }

        // workflowActorSnapshot() and logActivity() both read $_SESSION['user_id'];
        // the API has no session, so the token's identity is placed there for
        // the duration of the request.
        $_SESSION['user_id'] = (int) $auth['user_id'];

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT * FROM general_expenses WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No expense was found with that id.');
            }

            $from = (string) $row['status'];
            if (!vk_api_expenses_can_transition($from, $allowedFrom)) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    sprintf(
                        'An expense that is %s cannot be %s. Expected: %s.',
                        $from,
                        $logVerb,
                        implode(' or ', $allowedFrom)
                    )
                );
            }

            // Approve-only: the group's real, computed fund. An expense is
            // authorised here but only actually paid once marked so — the
            // balance itself only drops at that point (see
            // includes/finance.php) — but approving must not authorise more
            // than the group could ever pay.
            if ($to === 'approved') {
                $available = getGroupFundBalance($pdo);
                if (!vk_api_expenses_fund_sufficient($available, (float) $row['amount'])) {
                    $pdo->rollBack();
                    vk_api_error(
                        409,
                        'insufficient_funds',
                        sprintf(
                            'The group fund balance (TZS %s) is not enough to approve this expense (TZS %s).',
                            number_format($available, 2),
                            number_format((float) $row['amount'], 2)
                        )
                    );
                }
            }

            $actor = workflowActorSnapshot();

            if ($to === 'reviewed') {
                $pdo->prepare(
                    'UPDATE general_expenses SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            } elseif ($to === 'approved') {
                $pdo->prepare(
                    'UPDATE general_expenses SET status = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            }

            workflowCaptureSignature(
                $pdo,
                'general_expense',
                $id,
                $signatureAction,
                (int) $auth['user_id'],
                $actor['name'],
                $actor['role']
            );

            logActivity(
                'Updated',
                'Other Expenses',
                $actor['name'] . ' ' . $logVerb . ' General Expense #' . $id,
                'GE#' . $id
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['row' => vk_api_expenses_load($pdo, $id), 'from' => $from];
    }
}

if (!function_exists('vk_api_expenses_mark_paid')) {
    /**
     * approved -> paid. Mirrors actions/mark_expense_paid.php exactly,
     * including its one asymmetry with review/approve: no e-signature is
     * captured for this step (the web action never has either) — only
     * status, paid_at, paid_by and the activity log.
     *
     * @return array{row:array}
     */
    function vk_api_expenses_mark_paid(PDO $pdo, array $auth, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'An expense id is required.');
        }

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT status FROM general_expenses WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $status = $cur->fetchColumn();

            if ($status === false) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No expense was found with that id.');
            }
            if ($status === 'paid') {
                $pdo->rollBack();
                vk_api_error(409, 'already_paid', 'This expense is already marked as paid.');
            }
            if (!vk_api_expenses_can_transition((string) $status, ['approved'])) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    "Only an approved expense can be marked as paid (current: {$status})."
                );
            }

            $pdo->prepare('UPDATE general_expenses SET status = \'paid\', paid_at = NOW(), paid_by = ? WHERE id = ?')
                ->execute([(int) $auth['user_id'], $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        logActivity('Marked Paid', 'Expenses', 'Marked general expense #' . $id . ' as paid', 'GENERAL-EXP#' . $id);

        return ['row' => vk_api_expenses_load($pdo, $id)];
    }
}
