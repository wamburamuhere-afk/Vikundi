<?php
/**
 * includes/api_petty_cash.php — the shared rules for the Petty Cash module.
 *
 * Deliberately requires only config-free files, so it is testable in CI.
 *
 * PERMISSION KEY: `petty_cash` — a NEW catalog key (see
 * database/add_petty_cash_permission.php), mirroring `expenses`' grants.
 * Found while building this module: the web's own permission gates for
 * petty cash are inconsistent across files (create checks `expenses`,
 * review/approve/delete check `petty_cash`, the list pages use a hardcoded
 * role-name array, and the list's own AJAX endpoint,
 * actions/fetch_petty_cash.php, had NO permission check at all beyond being
 * logged in — any authenticated Member could pull the whole group's voucher
 * list). This API normalizes on ONE key throughout, per todo.md's own
 * judgment call #3, and the web hole is closed alongside it.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/../core/workflow.php';   // assertReviewable(), assertApprovable(), signatures

if (!function_exists('vk_api_petty_statuses')) {
    /** The status enum, as the column declares it. */
    function vk_api_petty_statuses(): array
    {
        return ['pending', 'reviewed', 'approved', 'rejected', 'paid'];
    }
}

if (!function_exists('vk_api_petty_str')) {
    /** Trimmed string, or null when there is nothing there. */
    function vk_api_petty_str($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s !== '' ? $s : null;
    }
}

if (!function_exists('vk_api_petty_amount')) {
    /** Validate a submitted voucher amount, returning it rounded. */
    function vk_api_petty_amount($raw): float
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

if (!function_exists('vk_api_petty_row')) {
    /** One petty-cash voucher, as the app renders it. */
    function vk_api_petty_row(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'voucher_no'       => (string) $r['voucher_no'],
            'transaction_date' => (string) $r['transaction_date'],
            'payee_name'       => (string) $r['payee_name'],
            'category'         => vk_api_petty_str($r['category'] ?? null),
            'description'      => vk_api_petty_str($r['description'] ?? null),
            'amount'           => (float) $r['amount'],
            'status'           => (string) ($r['status'] ?? 'pending'),
            'prepared_by_name' => vk_api_petty_str($r['prepared_by_name'] ?? null),
            'created_at'       => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'reviewed_at'      => !empty($r['reviewed_at'])
                ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            // Column is named `approval_date`, not `approved_at` — kept as
            // `approved_at` in the JSON so the two expense modules read alike.
            'approved_at'      => !empty($r['approval_date'])
                ? date(DATE_ATOM, strtotime((string) $r['approval_date'])) : null,
            'paid_at'          => !empty($r['paid_at'])
                ? date(DATE_ATOM, strtotime((string) $r['paid_at'])) : null,
        ];
    }
}

if (!function_exists('vk_api_petty_actions')) {
    /** What THIS caller may do to a voucher in this status. */
    function vk_api_petty_actions(array $auth, string $status): array
    {
        $canReview   = vk_api_can($auth, 'review', 'petty_cash') && vk_api_can($auth, 'view', 'petty_cash');
        $canApprove  = vk_api_can($auth, 'approve', 'petty_cash') && vk_api_can($auth, 'view', 'petty_cash');
        $canEdit     = vk_api_can($auth, 'edit', 'petty_cash');
        $canMarkPaid = vk_api_petty_may_mark_paid($auth);

        return [
            // Mirrors actions/save_petty_cash.php: only a still-pending voucher
            // may be edited.
            'edit'      => $canEdit && $status === 'pending',
            'review'    => $canReview && $status === 'pending',
            'approve'   => $canApprove && $status === 'reviewed',
            'mark_paid' => $canMarkPaid && $status === 'approved',
        ];
    }
}

if (!function_exists('vk_api_petty_may_mark_paid')) {
    /** Delegates to the same rule as Expenses — core/permissions.php's canMarkPaid(). */
    function vk_api_petty_may_mark_paid(array $auth): bool
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

if (!function_exists('vk_api_petty_load')) {
    /** One voucher by id, with who prepared it, or a 404. */
    function vk_api_petty_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A voucher id is required.');
        }
        $st = $pdo->prepare(
            'SELECT v.*, u.username AS prepared_by_name
               FROM petty_cash_vouchers v
               LEFT JOIN users u ON u.user_id = v.prepared_by
              WHERE v.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No voucher was found with that id.');
        }
        return $row;
    }
}

if (!function_exists('vk_api_petty_filters')) {
    /**
     * The query filters the list endpoint accepts, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_petty_filters(array $q, string $alias = 'v'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_petty_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_api_petty_statuses()) . '.');
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
            $where[]  = "{$a}transaction_date {$op} ?";
            $params[] = $raw;
        }

        $category = trim((string) ($q['category'] ?? ''));
        if ($category !== '') {
            $where[]  = "{$a}category = ?";
            $params[] = $category;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[] = "({$a}voucher_no LIKE ? OR {$a}payee_name LIKE ? OR {$a}description LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_petty_can_transition')) {
    /** The workflow's FROM-status guard, as pure logic — no PDO required. */
    function vk_api_petty_can_transition(string $from, array $allowedFrom): bool
    {
        return in_array($from, $allowedFrom, true);
    }
}

if (!function_exists('vk_api_petty_transition')) {
    /**
     * Run one workflow transition (review or approve) and return the fresh row.
     * Mirrors api/review_petty_cash.php / actions/approve_petty_cash.php.
     *
     * Unlike Expenses/Condolences, approve here has NO fund-balance gate — the
     * web's own approve_petty_cash.php never had one, so this does not add one
     * the web has never enforced.
     *
     * @return array{row:array, from:string}
     */
    function vk_api_petty_transition(
        PDO $pdo,
        array $auth,
        int $id,
        string $to,
        array $allowedFrom,
        string $signatureAction,
        string $logVerb
    ): array {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A voucher id is required.');
        }

        $_SESSION['user_id'] = (int) $auth['user_id'];

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT * FROM petty_cash_vouchers WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No voucher was found with that id.');
            }

            $from = (string) $row['status'];
            if (!vk_api_petty_can_transition($from, $allowedFrom)) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    sprintf(
                        'A voucher that is %s cannot be %s. Expected: %s.',
                        $from,
                        $logVerb,
                        implode(' or ', $allowedFrom)
                    )
                );
            }

            $actor = workflowActorSnapshot();

            if ($to === 'reviewed') {
                $pdo->prepare(
                    'UPDATE petty_cash_vouchers SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            } elseif ($to === 'approved') {
                $pdo->prepare(
                    'UPDATE petty_cash_vouchers SET status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            }

            workflowCaptureSignature(
                $pdo,
                'petty_cash',
                $id,
                $signatureAction,
                (int) $auth['user_id'],
                $actor['name'],
                $actor['role']
            );

            logActivity(
                'Updated',
                'Petty Cash',
                $actor['name'] . ' ' . $logVerb . ' Petty Cash Voucher #' . $row['voucher_no'],
                'PCV#' . $row['voucher_no']
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['row' => vk_api_petty_load($pdo, $id), 'from' => $from];
    }
}

if (!function_exists('vk_api_petty_mark_paid')) {
    /**
     * approved -> paid. Mirrors actions/mark_expense_paid.php (type=petty)
     * exactly — no e-signature captured, only status/paid_at/paid_by and the
     * activity log.
     *
     * @return array{row:array}
     */
    function vk_api_petty_mark_paid(PDO $pdo, array $auth, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A voucher id is required.');
        }

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT status FROM petty_cash_vouchers WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $status = $cur->fetchColumn();

            if ($status === false) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No voucher was found with that id.');
            }
            if ($status === 'paid') {
                $pdo->rollBack();
                vk_api_error(409, 'already_paid', 'This voucher is already marked as paid.');
            }
            if (!vk_api_petty_can_transition((string) $status, ['approved'])) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    "Only an approved voucher can be marked as paid (current: {$status})."
                );
            }

            $pdo->prepare('UPDATE petty_cash_vouchers SET status = \'paid\', paid_at = NOW(), paid_by = ? WHERE id = ?')
                ->execute([(int) $auth['user_id'], $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        logActivity('Marked Paid', 'Expenses', 'Marked petty cash voucher #' . $id . ' as paid', 'PETTY-EXP#' . $id);

        return ['row' => vk_api_petty_load($pdo, $id)];
    }
}
