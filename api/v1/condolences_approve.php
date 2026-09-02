<?php
/**
 * POST /api/v1/condolences/{id}/approve — reviewed -> approved.
 *
 * Mirrors actions/approve_death_expense.php, including its side effects — this
 * is the transition that authorises money leaving the group AND updates the
 * bereaved member's own record, so both must happen together or not at all.
 *
 * vk_api_death_transition() enforces the workflow (reviewed-only, row-locked)
 * and the fund-balance gate (the group must actually have the money). What it
 * deliberately leaves to this file is what happens next: marking the
 * deceased. That is a customers-table concern, not a workflow concern, and
 * folding it into the shared transition helper would make that function
 * answer to two different callers' needs.
 *
 * sig_warning, not an error, when the approver has no e-signature on file —
 * matching api/approve_contribution.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';
require_once __DIR__ . '/../../helpers.php'; // markChildDeceasedJson()

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'death_expenses');
vk_api_require_permission($auth, 'approve', 'death_expenses');

$id = (int) ($_GET['id'] ?? 0);

// Load before the transition so the deceased/member fields are on hand for
// the side effects below — the transition itself re-reads and re-locks the
// row, so this is not what decides whether the approval is valid.
$before = vk_api_death_load($pdo, $id);

$result = vk_api_death_transition(
    $pdo,
    $auth,
    $id,
    'approved',
    ['reviewed'],
    'approved',
    'approved'
);

// -----------------------------------------------------------------------------
// Deceased-marking side effects — exactly actions/approve_death_expense.php's
// step 5, run after the transition commits. A failure here must not be able to
// roll back a genuine approval: the money side of this is already settled, and
// an approved case with an un-updated family field is a data-entry follow-up,
// not a reason to pretend the approval never happened.
// -----------------------------------------------------------------------------
$memberId     = (int) $before['member_id'];
$deceasedType = strtolower((string) ($before['deceased_type'] ?? ''));
$deceasedId   = (string) ($before['deceased_id'] ?? '');

if ($deceasedId === 'member' || $deceasedType === 'mwanachama') {
    $email = $pdo->prepare('SELECT email FROM customers WHERE customer_id = ?');
    $email->execute([$memberId]);
    $email = $email->fetchColumn();

    $pdo->prepare(
        "UPDATE customers SET is_active = 0, is_deceased = 1, status = 'dormant' WHERE customer_id = ?"
    )->execute([$memberId]);

    if ($email) {
        $pdo->prepare("UPDATE users SET status = 'dormant' WHERE email = ?")->execute([$email]);
    }
} elseif ($deceasedId === 'spouse') {
    $pdo->prepare(
        'UPDATE customers SET spouse_first_name = NULL, spouse_last_name = NULL WHERE customer_id = ?'
    )->execute([$memberId]);
} elseif ($deceasedId === 'father') {
    $pdo->prepare('UPDATE customers SET father_name = NULL WHERE customer_id = ?')->execute([$memberId]);
} elseif ($deceasedId === 'mother') {
    $pdo->prepare('UPDATE customers SET mother_name = NULL WHERE customer_id = ?')->execute([$memberId]);
} elseif (str_starts_with($deceasedId, 'child_')) {
    // The child is marked deceased rather than deleted, so the record is
    // retained and still shown (flagged) on the member's profile.
    $json = $pdo->prepare('SELECT children_data FROM customers WHERE customer_id = ?');
    $json->execute([$memberId]);
    $json = $json->fetchColumn();
    $idx  = (int) substr($deceasedId, 6);
    $newJson = markChildDeceasedJson($json !== false ? $json : null, $idx, date('Y-m-d'));
    if ($newJson !== null && $newJson !== $json) {
        $pdo->prepare('UPDATE customers SET children_data = ? WHERE customer_id = ?')
            ->execute([$newJson, $memberId]);
    }
}

$row = vk_api_death_row($result['row'], vk_api_member_id((int) $auth['user_id']));
$row['actions'] = vk_api_death_actions($auth, $row['status']);

$payload = [
    'condolence' => $row,
    'message'    => 'Condolence case approved.',
];

$sig = getWorkflowSignatures($pdo, 'death_expense', $id)['approved'] ?? null;
if (empty($sig['sig_path'])) {
    $payload['sig_warning'] =
        'No e-signature on file — the approval was recorded without a signature image.';
}

vk_api_ok($payload);
