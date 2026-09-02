<?php
/**
 * includes/api_death_expenses.php — the shared rules for the Condolences
 * (death_expenses) module.
 *
 * Deliberately requires only config-free files, so it is testable in CI:
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/death_expense_access.php'; // vk_death_leader_from() — one rule, both transports
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/../core/workflow.php';   // assertReviewable(), assertApprovable(), signatures
require_once __DIR__ . '/finance.php';            // getGroupFundBalance()

if (!function_exists('vk_api_death_statuses')) {
    /** The status enum, as the column declares it. */
    function vk_api_death_statuses(): array
    {
        return ['pending', 'reviewed', 'approved', 'rejected', 'inactive', 'paid'];
    }
}

if (!function_exists('vk_api_death_is_leader')) {
    /**
     * Delegates to the same rule the web uses (includes/death_expense_access.php),
     * so the two transports cannot answer this differently — which is exactly how
     * the group-wide leak this module was built alongside happened.
     */
    function vk_api_death_is_leader(array $auth): bool
    {
        return vk_death_leader_from(
            vk_api_is_admin((int) ($auth['role_id'] ?? 0)),
            vk_api_can($auth, 'edit', 'death_expenses')
        );
    }
}

if (!function_exists('vk_api_death_require_leader')) {
    /**
     * GET /condolences is the whole group's condolence cases, so it is
     * leadership only — a hard 403, not a narrowing to the caller's own rows.
     *
     * A member's own cases live at /my/condolences. Two endpoints rather than
     * one that quietly changes meaning: unlike /contributions, no web screen
     * here ever branched on a member's own `view` grant to show them just
     * their own rows, so there is no existing "scoped" behaviour to mirror —
     * only a hole, already closed in includes/death_expense_access.php.
     */
    function vk_api_death_require_leader(array $auth, string $what = 'view the group\'s condolence records'): void
    {
        if (!vk_api_death_is_leader($auth)) {
            vk_api_error(403, 'forbidden', "You do not have permission to {$what}. "
                . 'Your own condolence records are at /api/v1/my/condolences.');
        }
    }
}

if (!function_exists('vk_api_death_str')) {
    /** Trimmed string, or null when there is nothing there. */
    function vk_api_death_str($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s !== '' ? $s : null;
    }
}

if (!function_exists('vk_api_death_row')) {
    /**
     * One condolence case, as the app renders it.
     *
     * `deceased` is nested rather than four flat fields: the four columns
     * (type, id, name, relationship) only ever mean something together, and a
     * client reading `deceased_name` alone with no `deceased_type` cannot tell
     * a member's own death (which dormants their account on approval — see
     * vk_api_death_approve()) from a dependant's.
     */
    function vk_api_death_row(array $r, int $ownMemberId = 0): array
    {
        $status = (string) ($r['status'] ?? 'pending');

        $name = trim((string) ($r['member_name'] ?? ''));
        if ($name === '') {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }

        return [
            'id'          => (int) $r['id'],
            'member_id'   => (int) $r['member_id'],
            'member_name' => $name,
            'is_self'     => $ownMemberId > 0 && (int) $r['member_id'] === $ownMemberId,
            'deceased'    => [
                'type'         => vk_api_death_str($r['deceased_type'] ?? null),
                'id'           => vk_api_death_str($r['deceased_id'] ?? null),
                'name'         => (string) ($r['deceased_name'] ?? ''),
                'relationship' => vk_api_death_str($r['deceased_relationship'] ?? null),
            ],
            'amount'      => (float) $r['amount'],
            'description' => vk_api_death_str($r['description'] ?? null),
            'status'      => $status,
            'expense_date'=> (string) $r['expense_date'],
            'created_at'  => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'reviewed_at' => !empty($r['reviewed_at'])
                ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            'approved_at' => !empty($r['approved_at'])
                ? date(DATE_ATOM, strtotime((string) $r['approved_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_death_actions')) {
    /**
     * What THIS caller may do to a case in this status.
     *
     * Only review and approve are exposed: nothing in the web or the API ever
     * writes 'rejected', 'inactive' or 'paid' — those enum values exist on the
     * column but no code path reaches them, so offering a button for them here
     * would promise an action the server cannot perform.
     */
    function vk_api_death_actions(array $auth, string $status): array
    {
        $canReview  = vk_api_can($auth, 'review', 'death_expenses')
            && vk_api_can($auth, 'view', 'death_expenses');
        $canApprove = vk_api_can($auth, 'approve', 'death_expenses')
            && vk_api_can($auth, 'view', 'death_expenses');

        return [
            'review'  => $canReview  && $status === 'pending',
            'approve' => $canApprove && $status === 'reviewed',
        ];
    }
}

if (!function_exists('vk_api_death_load')) {
    /** One case by id, with its member's name, or a 404. */
    function vk_api_death_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A condolence record id is required.');
        }
        $st = $pdo->prepare(
            "SELECT de.*,
                    TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
               FROM death_expenses de
               LEFT JOIN customers c ON c.customer_id = de.member_id
              WHERE de.id = ?"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No condolence record was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_death_require_own_or_leader')) {
    /**
     * Gate for a single case, loaded by id.
     *
     * Refuses with 404 rather than 403: death_expenses ids are sequential, and
     * a 403 confirms which ids exist — on a table whose whole subject is who in
     * the group has lost a family member. Mirrors
     * vk_death_web_require_own_or_leader() so the web and the API refuse the
     * same way.
     */
    function vk_api_death_require_own_or_leader(array $auth, int $memberId, int $ownMemberId): void
    {
        if (vk_api_death_is_leader($auth)) {
            return;
        }
        if ($ownMemberId > 0 && $memberId === $ownMemberId) {
            return;
        }
        vk_api_error(404, 'not_found', 'No condolence record was found with that id.');
    }
}

if (!function_exists('vk_api_death_filters')) {
    /**
     * The query filters the list endpoints accept, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_death_filters(array $q, string $alias = 'de'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_death_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_api_death_statuses()) . '.');
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

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR {$a}deceased_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_death_amount')) {
    /** Validate a submitted assistance amount, returning it rounded. */
    function vk_api_death_amount($raw): float
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

if (!function_exists('vk_api_death_can_transition')) {
    /**
     * The workflow's FROM-status guard, as pure logic — no PDO required.
     *
     * Extracted rather than left inline so it is directly unit-testable: the
     * function it guards runs inside a real transaction (SELECT ... FOR
     * UPDATE, an UPDATE, a signature insert, an activity log insert), which
     * cannot be faithfully faked in a unit test without a large, fragile PDO
     * double. Mirrors includes/finance.php's fundBalanceFromTotals(), pulled
     * out of getGroupFundBalance() for exactly this reason.
     */
    function vk_api_death_can_transition(string $from, array $allowedFrom): bool
    {
        return in_array($from, $allowedFrom, true);
    }
}

if (!function_exists('vk_api_death_fund_sufficient')) {
    /**
     * The approve-only fund-balance guard, as pure logic. A condolence payout
     * is money LEAVING the group — unlike contributions, which only ever adds
     * — so approving must not authorise more than the group actually has.
     */
    function vk_api_death_fund_sufficient(float $available, float $amount): bool
    {
        return $available >= $amount;
    }
}

if (!function_exists('vk_api_death_transition')) {
    /**
     * Run one workflow transition (review or approve) and return the fresh row.
     *
     * Mirrors vk_api_contrib_transition() — SELECT ... FOR UPDATE inside the
     * transaction, a FROM-status guard so approve cannot skip review, and the
     * signature capture and activity log in the same transaction as the write.
     *
     * approve carries one extra rule contributions does not have: it is gated
     * on the group's actual available fund, computed from records rather than
     * a cached balance (core/workflow.php's sibling, includes/finance.php),
     * because condolence assistance is money leaving the group, not arriving.
     * Deceased-member side effects (marking the member or a dependant deceased,
     * clearing the corresponding family field) are handled by the caller after
     * this returns, exactly where actions/approve_death_expense.php does it —
     * they touch the customers table, not the workflow, and mixing the two
     * here would make this function respond to two different callers' needs.
     *
     * @return array{row:array, from:string}
     */
    function vk_api_death_transition(
        PDO $pdo,
        array $auth,
        int $id,
        string $to,
        array $allowedFrom,
        string $signatureAction,
        string $logVerb
    ): array {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A condolence record id is required.');
        }

        // workflowActorSnapshot() and logActivity() both read $_SESSION['user_id'];
        // the API has no session, so the token's identity is placed there for
        // the duration of the request.
        $_SESSION['user_id'] = (int) $auth['user_id'];

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT * FROM death_expenses WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No condolence record was found with that id.');
            }

            $from = (string) $row['status'];
            if (!vk_api_death_can_transition($from, $allowedFrom)) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    sprintf(
                        'A condolence record that is %s cannot be %s. Expected: %s.',
                        $from,
                        $logVerb,
                        implode(' or ', $allowedFrom)
                    )
                );
            }

            // The fund-balance gate, approve only. Contributions never needs
            // this: a contribution is money arriving. A condolence payout is
            // money leaving, and it must not authorise more than the group
            // actually has.
            if ($to === 'approved') {
                $available = getGroupFundBalance($pdo);
                if (!vk_api_death_fund_sufficient($available, (float) $row['amount'])) {
                    $pdo->rollBack();
                    vk_api_error(
                        409,
                        'insufficient_funds',
                        sprintf(
                            'The group fund balance (TZS %s) is not enough to approve this case (TZS %s).',
                            number_format($available, 2),
                            number_format((float) $row['amount'], 2)
                        )
                    );
                }
            }

            $actor = workflowActorSnapshot();

            if ($to === 'reviewed') {
                $pdo->prepare(
                    'UPDATE death_expenses SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            } elseif ($to === 'approved') {
                $pdo->prepare(
                    'UPDATE death_expenses SET status = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            }

            workflowCaptureSignature(
                $pdo,
                'death_expense',
                $id,
                $signatureAction,
                (int) $auth['user_id'],
                $actor['name'],
                $actor['role']
            );

            logActivity(
                'Updated',
                'Death Expenses',
                $actor['name'] . ' ' . $logVerb . ' Death Expense #' . $id,
                'DEATH#' . $id
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['row' => vk_api_death_load($pdo, $id), 'from' => $from];
    }
}
