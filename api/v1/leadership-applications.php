<?php
/**
 * GET  /api/v1/leadership-applications — the Committee's review queue.
 * POST /api/v1/leadership-applications — a member applies (delegates to
 *      leadership-applications_create.php).
 *
 * GET mirrors app/constant/voting/manage_leadership_applications.php, with one
 * deliberate difference: the web scopes to ONE election at a time (a picker
 * dropdown); this returns every application across every election by
 * default, since a mobile client has no equivalent picker step and "what
 * needs my attention" is a more natural queue than "pick an election first."
 * `election_id` narrows to one, `status` narrows further, matching the web's
 * own filter intent without forcing the single-election shape.
 *
 * Includes the same contribution-standing badge the web computes, batched via
 * cs_group_schedules() exactly as manage_leadership_applications.php does —
 * informational only, never a blocker (see includes/contribution_standing.php).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';
require_once __DIR__ . '/../../includes/contribution_standing.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/leadership-applications_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'manage_leadership_applications');

$where  = [];
$params = [];

$electionId = (int) ($_GET['election_id'] ?? 0);
if ($electionId > 0) {
    $where[]  = 'a.vote_id = ?';
    $params[] = $electionId;
}

$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') {
    if (!in_array($status, ['pending', 'approved', 'rejected', 'withdrawn'], true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: pending, approved, rejected, withdrawn.');
    }
    $where[]  = 'a.status = ?';
    $params[] = $status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("
    SELECT a.*, v.status AS election_status, v.title AS election_title,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
           TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS proposer_name,
           TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS reviewer_name
      FROM leadership_applications a
      JOIN votes v ON v.id = a.vote_id
      LEFT JOIN customers c ON c.customer_id = a.member_id
      LEFT JOIN customers p ON p.customer_id = a.proposer_member_id
      LEFT JOIN users     u ON u.user_id     = a.reviewed_by
      {$whereSql}
     ORDER BY (v.status = 'draft') DESC, v.created_at DESC, a.position ASC, a.created_at ASC
");
$st->execute($params);
$apps = $st->fetchAll(PDO::FETCH_ASSOC);

$schedules = $apps ? cs_group_schedules($pdo) : [];

vk_api_ok([
    'applications' => array_map(static function (array $a) use ($auth, $schedules): array {
        $row = vk_api_application_row($a);
        $row['actions'] = vk_api_application_actions($auth, false, $a, (string) $a['election_status']);
        $mid = (int) $a['member_id'];
        $row['contribution_standing'] = isset($schedules[$mid])
            ? cs_arrears_from_grid(cs_calendar_grid($schedules[$mid]['schedule']))
            : null;
        return $row;
    }, $apps),
]);
