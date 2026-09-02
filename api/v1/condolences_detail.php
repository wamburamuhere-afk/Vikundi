<?php
/**
 * GET /api/v1/condolences/{id} — one condolence case, with its approval trail.
 *
 * Mirrors app/constant/accounts/death_expense_view.php.
 *
 * SCOPING IS RE-CHECKED HERE. Guessing an id is trivial (they are sequential),
 * so a member reading /condolences/1..n would walk the whole group's cases if
 * this trusted the list endpoint to have filtered. The ownership test is
 * applied to the row that was actually loaded — the same discipline that
 * closed the leak this module was built alongside (see
 * includes/death_expense_access.php).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

$row = vk_api_death_load($pdo, (int) ($_GET['id'] ?? 0));
$own = vk_api_member_id((int) $auth['user_id']);

// Deliberately 404, not 403: a member must not be able to map who in the
// group has lost a family member by reading which ids exist.
vk_api_death_require_own_or_leader($auth, (int) $row['member_id'], $own);

$condolence = vk_api_death_row($row, $own);
$condolence['actions'] = vk_api_death_actions($auth, $condolence['status']);

// Who did what, from the shared workflow store — the same one contributions
// uses. Signature IMAGES are not exposed: a stored signature is reusable as a
// signature, and the app needs the name and timestamp, not the image.
$signatures = getWorkflowSignatures($pdo, 'death_expense', (int) $row['id']);
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

// The created stage predates the signature table on older rows, so fall back
// to the case's own columns rather than showing an empty first step.
if (!$trail['created']['completed'] && !empty($row['created_at'])) {
    $creator = $pdo->prepare(
        'SELECT TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name, username
           FROM users WHERE user_id = ?'
    );
    $creator->execute([(int) ($row['created_by'] ?? 0)]);
    $c = $creator->fetch(PDO::FETCH_ASSOC) ?: [];
    $trail['created'] = [
        'by'        => trim((string) ($c['full_name'] ?? '')) ?: (string) ($c['username'] ?? ''),
        'role'      => '',
        'at'        => date(DATE_ATOM, strtotime((string) $row['created_at'])),
        'signed'    => false,
        'completed' => true,
    ];
}

vk_api_ok([
    'condolence' => $condolence,
    'trail'      => $trail,
]);
