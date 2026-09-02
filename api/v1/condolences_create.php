<?php
/**
 * POST /api/v1/condolences — record condolence assistance for a bereaved member.
 *
 * Reached through condolences.php, which has already authenticated.
 *
 * Mirrors actions/process_death_expense.php's core fields. Attachments are
 * deliberately NOT accepted here: the web action files them into the shared
 * document library (document_categories / documents), a subsystem this module
 * does not otherwise touch. Building that integration for one upload field is
 * scope the mobile app has not asked for yet; a certificate can still be
 * attached from the web until it does.
 *
 * `create` on death_expenses — leadership only. Unlike contributions, a member
 * does not file their own condolence case; leadership records it, the same
 * shape as fines.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

vk_api_death_require_leader($auth, 'record a condolence case');

$body = vk_api_body();

$memberId = (int) ($body['member_id'] ?? 0);
if ($memberId <= 0) {
    vk_api_error(422, 'member_required', 'member_id is required.');
}

$deceasedName = trim((string) ($body['deceased_name'] ?? ''));
if ($deceasedName === '') {
    vk_api_error(422, 'deceased_name_required', 'deceased_name is required.');
}

// deceased_id is the stable signal for what the approval step must do: 'member'
// (or type 'mwanachama') marks the member's own account deceased and dormant;
// 'spouse' / 'father' / 'mother' / 'child_N' clear that one family field. Free
// text otherwise — the web's picker sends it and there is no fixed list to
// validate against.
$deceasedId   = trim((string) ($body['deceased_id'] ?? ''));
$deceasedType = trim((string) ($body['deceased_type'] ?? ''));
if ($deceasedId === 'member' && $deceasedType === '') {
    $deceasedType = 'mwanachama';
}
$deceasedRelationship = trim((string) ($body['deceased_relationship'] ?? '')) ?: 'Mtegemezi';

$amount      = vk_api_death_amount($body['amount'] ?? null);
$description = trim((string) ($body['description'] ?? ''));

$date = trim((string) ($body['expense_date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
} else {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        vk_api_error(422, 'invalid_date', 'expense_date must be in YYYY-MM-DD format.');
    }
}

$c = $pdo->prepare("SELECT phone FROM customers WHERE customer_id = ? AND (status IS NULL OR status <> 'deleted')");
$c->execute([$memberId]);
$row = $c->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    vk_api_error(404, 'member_not_found', 'No member was found with that id.');
}

$st = $pdo->prepare(
    'INSERT INTO death_expenses
        (member_id, phone_number, deceased_type, deceased_id, deceased_name, deceased_relationship,
         amount, description, expense_date, status, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
);
$st->execute([
    $memberId, $row['phone'] ?? null, $deceasedType, $deceasedId, $deceasedName, $deceasedRelationship,
    $amount, $description !== '' ? $description : null, $date, (int) $auth['user_id'],
]);
$newId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session
logCreate('Death Expenses', $deceasedName . ' — TSh ' . number_format($amount, 0), 'DEATH#' . $newId, (int) $auth['user_id']);

$created = vk_api_death_load($pdo, $newId);
$row     = vk_api_death_row($created, vk_api_member_id((int) $auth['user_id']));
$row['actions'] = vk_api_death_actions($auth, $row['status']);

vk_api_ok([
    'condolence' => $row,
    'message'    => 'Condolence case recorded and awaiting review.',
], 201);
