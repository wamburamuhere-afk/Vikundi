<?php
/**
 * GET /api/v1/expenses/{id} — one expense, with its approval trail.
 * PUT /api/v1/expenses/{id} — edit amount / description / date.
 *
 * The trail has FOUR stages, not three — created, reviewed, approved, paid —
 * because this workflow (unlike Contributions/Condolences) has a real 'paid'
 * status. `paid` never gets an e-signature (actions/mark_expense_paid.php
 * never captures one), so it is always shown from the row's own
 * paid_by/paid_at columns rather than the workflow_signatures table.
 *
 * EDIT REFUSES 'approved' AND 'paid', not just 'approved'.
 * api/update_general_expense.php only checked `=== 'approved'`, so a paid
 * expense — money that has already left the account — could still be
 * silently edited. Fixed here as the more correct rule; flagged for the web
 * file too.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    vk_api_require_permission($auth, 'edit', 'expenses');

    $row = vk_api_expenses_load($pdo, $id);
    if (in_array($row['status'], ['approved', 'paid'], true)) {
        vk_api_error(
            409,
            'not_editable',
            "An expense that is {$row['status']} can no longer be edited."
        );
    }

    $body = vk_api_body();
    $sets = [];
    $vals = [];

    if (array_key_exists('description', $body)) {
        $description = trim((string) $body['description']);
        if ($description === '') {
            vk_api_error(422, 'description_required', 'description cannot be empty.');
        }
        $sets[] = 'description = ?';
        $vals[] = $description;
    }
    if (array_key_exists('amount', $body)) {
        $sets[] = 'amount = ?';
        $vals[] = vk_api_expenses_amount($body['amount']);
    }
    if (array_key_exists('expense_date', $body)) {
        $date = trim((string) $body['expense_date']);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            vk_api_error(422, 'invalid_date', 'expense_date must be in YYYY-MM-DD format.');
        }
        $sets[] = 'expense_date = ?';
        $vals[] = $date;
    }

    if (!$sets) {
        vk_api_error(422, 'no_fields', 'Nothing to update — send at least one of description, amount, expense_date.');
    }

    $vals[] = $id;
    $pdo->prepare('UPDATE general_expenses SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logUpdate('General Expenses', 'Expense #' . $id . ' edited', 'EXPENSE#' . $id, (int) $auth['user_id']);

    $updated = vk_api_expenses_row(vk_api_expenses_load($pdo, $id));
    $updated['actions'] = vk_api_expenses_actions($auth, $updated['status']);

    vk_api_ok(['expense' => $updated, 'message' => 'Expense updated.']);
}

vk_api_require_permission($auth, 'view', 'expenses');

$loaded = vk_api_expenses_load($pdo, $id);
$expense = vk_api_expenses_row($loaded);
$expense['actions'] = vk_api_expenses_actions($auth, $expense['status']);

$signatures = getWorkflowSignatures($pdo, 'general_expense', $id);
$trail = [];
foreach (['created', 'reviewed', 'approved'] as $stage) {
    $s = $signatures[$stage] ?? null;
    $trail[$stage] = [
        'by'        => $s['user_name'] ?? '',
        'role'      => $s['user_role'] ?? '',
        'at'        => !empty($s['signed_at']) ? date(DATE_ATOM, strtotime((string) $s['signed_at'])) : null,
        'signed'    => !empty($s['sig_path']),
        'completed' => !empty($s['signed_at']),
    ];
}

// The created stage predates the signature table on older rows.
if (!$trail['created']['completed'] && !empty($loaded['created_at'])) {
    $creator = $pdo->prepare(
        'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name, username
           FROM users WHERE user_id = ?'
    );
    $creator->execute([(int) ($loaded['created_by'] ?? 0)]);
    $c = $creator->fetch(PDO::FETCH_ASSOC) ?: [];
    $trail['created'] = [
        'by'        => trim((string) ($c['full_name'] ?? '')) ?: (string) ($c['username'] ?? ''),
        'role'      => '',
        'at'        => date(DATE_ATOM, strtotime((string) $loaded['created_at'])),
        'signed'    => false,
        'completed' => true,
    ];
}

// 'paid' never gets a signature — read it straight from the row.
$paidBy = null;
if (!empty($loaded['paid_by'])) {
    $p = $pdo->prepare(
        'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name, username
           FROM users WHERE user_id = ?'
    );
    $p->execute([(int) $loaded['paid_by']]);
    $pr = $p->fetch(PDO::FETCH_ASSOC) ?: [];
    $paidBy = trim((string) ($pr['full_name'] ?? '')) ?: (string) ($pr['username'] ?? '');
}
$trail['paid'] = [
    'by'        => $paidBy ?? '',
    'role'      => '',
    'at'        => !empty($loaded['paid_at']) ? date(DATE_ATOM, strtotime((string) $loaded['paid_at'])) : null,
    'signed'    => false,
    'completed' => !empty($loaded['paid_at']),
];

vk_api_ok([
    'expense' => $expense,
    'trail'   => $trail,
]);
