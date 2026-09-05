<?php
/**
 * includes/api_budgets.php — the shared rules for the Budgets module.
 *
 * Deliberately requires only config-free files, so it is testable in CI.
 *
 * PERMISSION KEY: `budget` — a NEW catalog key (see
 * database/add_budget_permission.php), registered leadership-only from
 * scratch. Unlike Expenses/Petty Cash (Module 9), there is no live Member
 * grant to mirror or preserve here — todo.md's own plan says "leadership
 * only", and nothing on the web has ever shown a member a budget.
 *
 * WORKFLOW IS THREE STAGES, NOT FOUR: pending -> reviewed -> approved, or
 * pending|reviewed -> rejected. There is no 'paid' state for a budget — it is
 * a plan, not a disbursement — so there is no mark-paid step and no
 * fund-balance gate on approve (neither review_budget.php nor
 * approve_budget.php has ever checked the group's fund balance).
 *
 * `category_id` IS DELIBERATELY NOT EXPOSED. Every budget row has it hardcoded
 * NULL at creation (api/account/add_budget.php), and the web's own variance
 * calculation against it joins `expenses`/`accounts`/`expense_categories` —
 * the dead accounting-ledger tables this project's own todo.md already
 * documents as never having recorded a real transaction. Exposing a field
 * that is permanently null and a variance figure derived from tables with no
 * real data would mislead a client into building UI around numbers that can
 * never mean anything on this system.
 */
require_once __DIR__ . '/api_auth.php';           // vk_api_is_admin(), vk_api_can()
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/../core/workflow.php';   // assertReviewable(), assertApprovable(), canEditDocument(), signatures

if (!function_exists('vk_api_budgets_statuses')) {
    /** The status enum, as the column declares it. */
    function vk_api_budgets_statuses(): array
    {
        return ['draft', 'pending', 'reviewed', 'approved', 'rejected'];
    }
}

if (!function_exists('vk_api_budgets_str')) {
    /** Trimmed string, or null when there is nothing there. */
    function vk_api_budgets_str($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s !== '' ? $s : null;
    }
}

if (!function_exists('vk_api_budgets_amount')) {
    /** Validate a submitted quantity/price, returning it rounded. Zero is allowed
     * (a placeholder line item), unlike a payment amount elsewhere in this API. */
    function vk_api_budgets_amount($raw, string $field): float
    {
        $clean = str_replace([',', ' '], '', (string) $raw);
        if ($clean === '' || !is_numeric($clean)) {
            vk_api_error(422, 'invalid_amount', "{$field} is required and must be a number.");
        }
        $amount = round((float) $clean, 2);
        if ($amount < 0) {
            vk_api_error(422, 'invalid_amount', "{$field} must not be negative.");
        }
        if ($amount > 9999999999999.99) {
            vk_api_error(422, 'invalid_amount', "{$field} is too large.");
        }
        return $amount;
    }
}

if (!function_exists('vk_api_budgets_item_row')) {
    /** One budget line item, as the app renders it. */
    function vk_api_budgets_item_row(array $r): array
    {
        return [
            'id'             => (int) $r['item_id'],
            'description'    => (string) $r['description'],
            'units'          => vk_api_budgets_str($r['units'] ?? null),
            'qty'            => (float) $r['qty'],
            'price_per_item' => (float) $r['price_per_item'],
            'total_amount'   => (float) $r['total_amount'],
        ];
    }
}

if (!function_exists('vk_api_budgets_row')) {
    /**
     * One budget header, as the app renders it. `items` is included when the
     * caller already loaded them (detail view); omitted on the list, which
     * would otherwise mean N+1 queries for a page of budgets.
     */
    function vk_api_budgets_row(array $r, ?array $items = null): array
    {
        $row = [
            'id'                  => (int) $r['budget_id'],
            'budget_name'         => (string) $r['budget_name'],
            'budget_year'         => (int) $r['budget_year'],
            'budget_month'        => (int) $r['budget_month'],
            'allocated_amount'    => (float) $r['allocated_amount'],
            'actual_amount'       => (float) $r['actual_amount'],
            'variance'            => (float) $r['variance'],
            'variance_percentage' => (float) $r['variance_percentage'],
            'status'              => (string) ($r['status'] ?? 'pending'),
            'notes'               => vk_api_budgets_str($r['notes'] ?? null),
            'created_at'          => !empty($r['created_at'])
                ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
            'reviewed_at'         => !empty($r['reviewed_at'])
                ? date(DATE_ATOM, strtotime((string) $r['reviewed_at'])) : null,
            'approved_at'         => !empty($r['approved_at'])
                ? date(DATE_ATOM, strtotime((string) $r['approved_at'])) : null,
        ];
        if ($items !== null) {
            $row['items'] = array_map('vk_api_budgets_item_row', $items);
        }
        return $row;
    }
}

if (!function_exists('vk_api_budgets_actions')) {
    /** What THIS caller may do to a budget in this status. No mark-paid — budgets have no such step. */
    function vk_api_budgets_actions(array $auth, string $status): array
    {
        $isAdmin    = vk_api_is_admin((int) ($auth['role_id'] ?? 0));
        $canReview  = vk_api_can($auth, 'review', 'budget') && vk_api_can($auth, 'view', 'budget');
        $canApprove = vk_api_can($auth, 'approve', 'budget') && vk_api_can($auth, 'view', 'budget');
        $canEdit    = vk_api_can($auth, 'edit', 'budget');
        $canDelete  = vk_api_can($auth, 'delete', 'budget');

        return [
            'edit'    => $canEdit && canEditDocument($status, $isAdmin),
            'delete'  => $canDelete,
            'review'  => $canReview && $status === 'pending',
            'approve' => $canApprove && $status === 'reviewed',
            'reject'  => ($canReview || $canApprove) && in_array($status, ['pending', 'reviewed'], true),
        ];
    }
}

if (!function_exists('vk_api_budgets_load')) {
    /** One budget by id, with its line items, or a 404. */
    function vk_api_budgets_load(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A budget id is required.');
        }
        $st = $pdo->prepare('SELECT * FROM budgets WHERE budget_id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            vk_api_error(404, 'not_found', 'No budget was found with that id.');
        }
        $items = $pdo->prepare('SELECT * FROM budget_items WHERE budget_id = ? ORDER BY item_id ASC');
        $items->execute([$id]);
        return ['row' => $row, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
    }
}

if (!function_exists('vk_api_budgets_filters')) {
    /**
     * The query filters the list endpoint accepts, validated into [sql, params].
     *
     * @return array{0:string[],1:array}
     */
    function vk_api_budgets_filters(array $q, string $alias = 'b'): array
    {
        $a = rtrim($alias, '.') . '.';
        $where  = [];
        $params = [];

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, vk_api_budgets_statuses(), true)) {
                vk_api_error(422, 'invalid_status', 'status must be one of: '
                    . implode(', ', vk_api_budgets_statuses()) . '.');
            }
            $where[]  = "{$a}status = ?";
            $params[] = $status;
        }

        $year = (int) ($q['year'] ?? 0);
        if ($year > 0) {
            $where[]  = "{$a}budget_year = ?";
            $params[] = $year;
        }

        $month = (int) ($q['month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $where[]  = "{$a}budget_month = ?";
            $params[] = $month;
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $where[]  = "{$a}budget_name LIKE ?";
            $params[] = '%' . $search . '%';
        }

        return [$where, $params];
    }
}

if (!function_exists('vk_api_budgets_can_transition')) {
    /** The workflow's FROM-status guard, as pure logic — no PDO required. */
    function vk_api_budgets_can_transition(string $from, array $allowedFrom): bool
    {
        return in_array($from, $allowedFrom, true);
    }
}

if (!function_exists('vk_api_budgets_replace_items')) {
    /**
     * Replace a budget's line items wholesale and return the new allocated
     * total. Mirrors api/account/edit_budget.php's own delete-then-reinsert
     * approach exactly — this table has no per-item update path anywhere in
     * the system, so diffing would be new behavior, not a faithful port.
     *
     * @param array<int,array{description:string,units:?string,qty:float,price_per_item:float}> $items
     */
    function vk_api_budgets_replace_items(PDO $pdo, int $budgetId, array $items): float
    {
        $pdo->prepare('DELETE FROM budget_items WHERE budget_id = ?')->execute([$budgetId]);

        $insert = $pdo->prepare(
            'INSERT INTO budget_items (budget_id, description, units, qty, price_per_item, total_amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $total = 0.0;
        foreach ($items as $item) {
            $total_amount = $item['qty'] * $item['price_per_item'];
            $insert->execute([
                $budgetId, $item['description'], $item['units'],
                $item['qty'], $item['price_per_item'], $total_amount,
            ]);
            $total += $total_amount;
        }
        return round($total, 2);
    }
}

if (!function_exists('vk_api_budgets_parse_items')) {
    /**
     * Validate a submitted `items` array into the shape
     * vk_api_budgets_replace_items() expects. A budget with zero items is
     * allowed — api/account/add_budget.php has always permitted it.
     */
    function vk_api_budgets_parse_items($raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            vk_api_error(422, 'invalid_items', 'items must be an array.');
        }
        $parsed = [];
        foreach ($raw as $i => $item) {
            if (!is_array($item)) {
                vk_api_error(422, 'invalid_items', "items[{$i}] must be an object.");
            }
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue; // matches the web: a blank description line is silently skipped
            }
            $parsed[] = [
                'description'    => $description,
                'units'          => vk_api_budgets_str($item['units'] ?? null),
                'qty'            => vk_api_budgets_amount($item['qty'] ?? 1, "items[{$i}].qty"),
                'price_per_item' => vk_api_budgets_amount($item['price_per_item'] ?? 0, "items[{$i}].price_per_item"),
            ];
        }
        return $parsed;
    }
}

if (!function_exists('vk_api_budgets_transition')) {
    /**
     * Run one workflow transition (review, approve, or reject) and return the
     * fresh row + items. No fund-balance gate — a budget is a plan, not a
     * disbursement.
     *
     * @return array{row:array, items:array, from:string}
     */
    function vk_api_budgets_transition(
        PDO $pdo,
        array $auth,
        int $id,
        string $to,
        array $allowedFrom,
        ?string $signatureAction,
        string $logVerb
    ): array {
        if ($id <= 0) {
            vk_api_error(422, 'invalid_id', 'A budget id is required.');
        }

        // workflowActorSnapshot() and logActivity() both read $_SESSION['user_id'];
        // the API has no session, so the token's identity is placed there for
        // the duration of the request.
        $_SESSION['user_id'] = (int) $auth['user_id'];

        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT * FROM budgets WHERE budget_id = ? FOR UPDATE');
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->rollBack();
                vk_api_error(404, 'not_found', 'No budget was found with that id.');
            }

            $from = (string) $row['status'];
            if (!vk_api_budgets_can_transition($from, $allowedFrom)) {
                $pdo->rollBack();
                vk_api_error(
                    409,
                    'invalid_status_transition',
                    sprintf(
                        'A budget that is %s cannot be %s. Expected: %s.',
                        $from,
                        $logVerb,
                        implode(' or ', $allowedFrom)
                    )
                );
            }

            $actor = workflowActorSnapshot();

            if ($to === 'reviewed') {
                $pdo->prepare(
                    'UPDATE budgets SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE budget_id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            } elseif ($to === 'approved') {
                $pdo->prepare(
                    'UPDATE budgets SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE budget_id = ?'
                )->execute([$to, (int) $auth['user_id'], $id]);
            } elseif ($to === 'rejected') {
                $pdo->prepare(
                    'UPDATE budgets SET status = ?, updated_at = NOW() WHERE budget_id = ?'
                )->execute([$to, $id]);
            }

            // Rejection captures no e-signature — it is not one of the three
            // sign-off steps the workflow_signatures store was built for, and
            // the web's own reject action (api/account/update_budget_status.php)
            // has never captured one either.
            if ($signatureAction !== null) {
                workflowCaptureSignature(
                    $pdo,
                    'budget',
                    $id,
                    $signatureAction,
                    (int) $auth['user_id'],
                    $actor['name'],
                    $actor['role']
                );
            }

            logActivity(
                $to === 'approved' ? 'Approved' : 'Updated',
                'Budget',
                $actor['name'] . ' ' . $logVerb . ' Budget #' . $id . ' — ' . ($row['budget_name'] ?? ''),
                'BUDGET#' . $id
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $loaded = vk_api_budgets_load($pdo, $id);
        return ['row' => $loaded['row'], 'items' => $loaded['items'], 'from' => $from];
    }
}
