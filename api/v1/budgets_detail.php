<?php
/**
 * GET /api/v1/budgets/{id} — one budget, its line items, and its trail.
 * PUT /api/v1/budgets/{id} — edit (replaces the line items wholesale).
 *
 * The trail has three stages — created, reviewed, approved — one shorter than
 * Expenses/Petty Cash: a budget has no 'paid' state. A 'rejected' budget has
 * no dedicated trail entry either; the schema never recorded who rejected it
 * or when (api/account/update_budget_status.php only ever wrote `status` and
 * `updated_at`), so there is nothing truthful to show beyond the `status`
 * field itself.
 *
 * EDIT USES core/workflow.php's canEditDocument() — refused once `approved`.
 * The web's own edit endpoint (api/account/edit_budget.php) had no status
 * guard at all before this module; canEditDocument() already existed,
 * unused, and encodes exactly the right rule.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    vk_api_require_permission($auth, 'edit', 'budget');

    $loaded = vk_api_budgets_load($pdo, $id);
    $isAdmin = vk_api_is_admin((int) $auth['role_id']);
    if (!canEditDocument($loaded['row']['status'], $isAdmin)) {
        vk_api_error(
            409,
            'not_editable',
            "A budget that is {$loaded['row']['status']} can no longer be edited."
        );
    }

    $body = vk_api_body();
    $sets = [];
    $vals = [];

    if (array_key_exists('budget_name', $body)) {
        $name = trim((string) $body['budget_name']);
        if ($name === '') {
            vk_api_error(422, 'budget_name_required', 'budget_name cannot be empty.');
        }
        $sets[] = 'budget_name = ?';
        $vals[] = $name;
    }
    if (array_key_exists('budget_year', $body)) {
        $year = (int) $body['budget_year'];
        if ($year < 2000 || $year > 2100) {
            vk_api_error(422, 'invalid_year', 'budget_year must be a real 4-digit year.');
        }
        $sets[] = 'budget_year = ?';
        $vals[] = $year;
    }
    if (array_key_exists('budget_month', $body)) {
        $month = (int) $body['budget_month'];
        if ($month < 1 || $month > 12) {
            vk_api_error(422, 'invalid_month', 'budget_month must be between 1 and 12.');
        }
        $sets[] = 'budget_month = ?';
        $vals[] = $month;
    }
    if (array_key_exists('notes', $body)) {
        $sets[] = 'notes = ?';
        $vals[] = trim((string) $body['notes']) ?: null;
    }

    $pdo->beginTransaction();
    try {
        // Items replace wholesale, same as api/account/edit_budget.php — this
        // table has no per-item update path anywhere in the system.
        if (array_key_exists('items', $body)) {
            $items = vk_api_budgets_parse_items($body['items']);
            $allocated = vk_api_budgets_replace_items($pdo, $id, $items);
            $sets[] = 'allocated_amount = ?';
            $vals[] = $allocated;
            // Mirrors edit_budget.php's own comment: variance is reset to the
            // new allocation, not recomputed against real spending — there is
            // no live "actual spend" figure this system tracks per budget.
            $sets[] = 'variance = ?';
            $vals[] = $allocated;
        }

        if ($sets) {
            $sets[] = 'updated_at = NOW()';
            $vals[] = $id;
            $pdo->prepare('UPDATE budgets SET ' . implode(', ', $sets) . ' WHERE budget_id = ?')->execute($vals);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logUpdate('Budgets', $loaded['row']['budget_name'] . ' edited', 'BUDGET#' . $id, (int) $auth['user_id']);

    $updated = vk_api_budgets_load($pdo, $id);
    $row = vk_api_budgets_row($updated['row'], $updated['items']);
    $row['actions'] = vk_api_budgets_actions($auth, $row['status']);

    vk_api_ok(['budget' => $row, 'message' => 'Budget updated.']);
}

vk_api_require_permission($auth, 'view', 'budget');

$loaded = vk_api_budgets_load($pdo, $id);
$budget = vk_api_budgets_row($loaded['row'], $loaded['items']);
$budget['actions'] = vk_api_budgets_actions($auth, $budget['status']);

$signatures = getWorkflowSignatures($pdo, 'budget', $id);
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

if (!$trail['created']['completed'] && !empty($loaded['row']['created_at'])) {
    $creator = $pdo->prepare(
        'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name, username
           FROM users WHERE user_id = ?'
    );
    $creator->execute([(int) ($loaded['row']['created_by'] ?? 0)]);
    $c = $creator->fetch(PDO::FETCH_ASSOC) ?: [];
    $trail['created'] = [
        'by'        => trim((string) ($c['full_name'] ?? '')) ?: (string) ($c['username'] ?? ''),
        'role'      => '',
        'at'        => date(DATE_ATOM, strtotime((string) $loaded['row']['created_at'])),
        'signed'    => false,
        'completed' => true,
    ];
}

vk_api_ok([
    'budget' => $budget,
    'trail'  => $trail,
]);
