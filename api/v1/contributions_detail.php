<?php
/**
 * GET /api/v1/contributions/{id} — one contribution, with its approval trail.
 *
 * The trail is the point of this endpoint. A list row says a contribution is
 * approved; this says who reviewed it, who approved it, and when — which is what
 * a member disputing a figure actually asks for, and what the group's
 * three-approval rule exists to record.
 *
 * SCOPING IS RE-CHECKED HERE. Guessing an id is trivial (they are sequential),
 * so a member reading /contributions/1..n would walk the whole group's ledger if
 * this trusted the list endpoint to have filtered. The ownership test is applied
 * to the row that was actually loaded.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    vk_api_error(422, 'invalid_id', 'A contribution id is required.');
}

$st = $pdo->prepare(
    'SELECT co.*, c.customer_name, c.first_name, c.last_name, c.user_id AS member_user_id
       FROM contributions co
       LEFT JOIN customers c ON c.customer_id = co.member_id
      WHERE co.contribution_id = ?'
);
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    vk_api_error(404, 'not_found', 'No contribution was found with that id.');
}

$scope = vk_api_contrib_scope($auth);
if (!$scope['is_leader'] && (int) $row['member_id'] !== $scope['own_member_id']) {
    // Deliberately 404, not 403: a member must not be able to map the ledger by
    // reading which ids exist.
    vk_api_error(404, 'not_found', 'No contribution was found with that id.');
}

$contribution = vk_api_contrib_row($row);
$contribution['actions'] = vk_api_contrib_actions($auth, $contribution['status']);

// Who did what, from the shared workflow store. Signature IMAGES are not
// exposed: a stored signature is reusable as a signature, and the app needs the
// name and timestamp, not the image.
$signatures = getWorkflowSignatures($pdo, 'contribution', $id);
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

// The created stage predates the signature table on older rows, so fall back to
// the contribution's own columns rather than showing an empty first step.
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
    'contribution' => $contribution,
    'trail'        => $trail,
]);
