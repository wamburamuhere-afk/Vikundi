<?php
/**
 * POST /api/v1/budgets — record a budget with its line items.
 *
 * Reached through budgets.php, which has already authenticated. Also usable
 * directly. Mirrors api/account/add_budget.php's core fields, with one
 * deliberate shape change: `items` is a nested JSON array here rather than
 * the web form's parallel POST arrays (item_description[], item_qty[], ...)
 * — that quirk belongs to the HTML form, not the API contract.
 *
 * `create` on `budget` — leadership only. Every budget is created `pending`,
 * same as the web; there is no client-settable status field.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

vk_api_require_permission($auth, 'create', 'budget');

$body = vk_api_body();

$budgetName = trim((string) ($body['budget_name'] ?? ''));
if ($budgetName === '') {
    vk_api_error(422, 'budget_name_required', 'budget_name is required.');
}

$year = (int) ($body['budget_year'] ?? 0);
if ($year < 2000 || $year > 2100) {
    vk_api_error(422, 'invalid_year', 'budget_year must be a real 4-digit year.');
}

$month = (int) ($body['budget_month'] ?? 0);
if ($month < 1 || $month > 12) {
    vk_api_error(422, 'invalid_month', 'budget_month must be between 1 and 12.');
}

$notes = trim((string) ($body['notes'] ?? ''));
$items = vk_api_budgets_parse_items($body['items'] ?? []);

$allocatedAmount = 0.0;
foreach ($items as $item) {
    $allocatedAmount += $item['qty'] * $item['price_per_item'];
}
$allocatedAmount = round($allocatedAmount, 2);

$pdo->beginTransaction();
try {
    $st = $pdo->prepare(
        "INSERT INTO budgets
            (category_id, budget_year, budget_month, budget_name,
             allocated_amount, actual_amount, status, notes,
             created_by, variance, variance_percentage, created_at, updated_at)
         VALUES
            (NULL, ?, ?, ?, ?, 0, 'pending', ?, ?, ?, 100.00, NOW(), NOW())"
    );
    $st->execute([
        $year, $month, $budgetName, $allocatedAmount,
        $notes !== '' ? $notes : null, (int) $auth['user_id'], $allocatedAmount,
    ]);
    $newId = (int) $pdo->lastInsertId();

    vk_api_budgets_replace_items($pdo, $newId, $items);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

$_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session
logCreate('Budgets', $budgetName, 'BUDGET#' . $newId, (int) $auth['user_id']);

$loaded = vk_api_budgets_load($pdo, $newId);
$row = vk_api_budgets_row($loaded['row'], $loaded['items']);
$row['actions'] = vk_api_budgets_actions($auth, $row['status']);

vk_api_ok([
    'budget'  => $row,
    'message' => 'Budget recorded and awaiting review.',
], 201);
