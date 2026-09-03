<?php
/**
 * POST /api/v1/expenses — record a general expense.
 *
 * Reached through expenses.php, which has already authenticated. Also usable
 * directly. Mirrors api/add_general_expense.php's core fields.
 *
 * Attachments are deliberately NOT accepted here, same call as Condolences:
 * the web action files a receipt into the shared document library
 * (document_categories / documents), a subsystem this module does not
 * otherwise touch. A receipt can still be attached from the web.
 *
 * `create` on `expenses` — leadership only.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

vk_api_require_permission($auth, 'create', 'expenses');

$body = vk_api_body();

$description = trim((string) ($body['description'] ?? ''));
if ($description === '') {
    vk_api_error(422, 'description_required', 'description is required.');
}

$amount = vk_api_expenses_amount($body['amount'] ?? null);

$date = trim((string) ($body['expense_date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
} else {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        vk_api_error(422, 'invalid_date', 'expense_date must be in YYYY-MM-DD format.');
    }
}

// Optional: charge this expense to one particular member. An unknown id is
// silently treated as a whole-organization expense rather than storing a
// dangling reference — same rule as api/add_general_expense.php.
$memberId = vk_expense_member_id($body['member_id'] ?? null);
if ($memberId !== null) {
    $chk = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE customer_id = ?');
    $chk->execute([$memberId]);
    if ((int) $chk->fetchColumn() === 0) {
        $memberId = null;
    }
}

$st = $pdo->prepare(
    "INSERT INTO general_expenses (expense_date, description, amount, status, created_by, member_id)
     VALUES (?, ?, ?, 'pending', ?, ?)"
);
$st->execute([$date, $description, $amount, (int) $auth['user_id'], $memberId]);
$newId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session
logCreate('General Expenses', $description . ' — TSh ' . number_format($amount, 0), 'EXPENSE#' . $newId, (int) $auth['user_id']);

$row = vk_api_expenses_row(vk_api_expenses_load($pdo, $newId));
$row['actions'] = vk_api_expenses_actions($auth, $row['status']);

vk_api_ok([
    'expense' => $row,
    'message' => 'Expense recorded and awaiting review.',
], 201);
