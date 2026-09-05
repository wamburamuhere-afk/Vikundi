<?php
/**
 * GET  /api/v1/payouts — the group's member payouts, paginated
 * POST /api/v1/payouts — record one
 *
 * Mirrors app/bms/customer/record_payout.php, including its "10 most recent"
 * list shown on the same page — that becomes real pagination here rather
 * than a fixed-10 inline table.
 *
 * Gated on `member_payouts` — Admin/Chairperson/Secretary only (see
 * includes/api_payouts.php's own note on why Treasurer is deliberately
 * excluded, unlike every other financial module).
 *
 * NO WORKFLOW: every payout is created `'paid'` directly, matching the web
 * exactly. No fund-balance gate either — the web has never had one.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_payouts.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vk_api_require_permission($auth, 'create', 'member_payouts');

    $body = vk_api_body();

    $memberId = (int) ($body['member_id'] ?? 0);
    if ($memberId <= 0) {
        vk_api_error(422, 'member_required', 'member_id is required.');
    }
    $member = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND status <> 'deleted'");
    $member->execute([$memberId]);
    if (!$member->fetchColumn()) {
        vk_api_error(404, 'member_not_found', 'No member was found with that id.');
    }

    $amount = vk_api_payouts_amount($body['amount'] ?? null);

    $description = trim((string) ($body['description'] ?? ''));
    if ($description === '') {
        vk_api_error(422, 'description_required', 'description is required.');
    }

    $date = trim((string) ($body['payout_date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            vk_api_error(422, 'invalid_date', 'payout_date must be in YYYY-MM-DD format.');
        }
    }

    $st = $pdo->prepare(
        "INSERT INTO member_payouts (member_id, amount, description, payout_date, status)
         VALUES (?, ?, ?, ?, 'paid')"
    );
    $st->execute([$memberId, $amount, $description, $date]);
    $newId = (int) $pdo->lastInsertId();

    $_SESSION['user_id'] = (int) $auth['user_id']; // logCreate() reads the session
    logCreate('Member Payouts', $description . ' — TZS ' . number_format($amount, 0), 'PAYOUT#' . $newId, (int) $auth['user_id']);

    $row = $pdo->prepare(
        'SELECT p.*, c.first_name, c.last_name FROM member_payouts p
           JOIN customers c ON c.customer_id = p.member_id
          WHERE p.payout_id = ?'
    );
    $row->execute([$newId]);

    vk_api_ok([
        'payout'  => vk_api_payouts_row($row->fetch(PDO::FETCH_ASSOC)),
        'message' => 'Payout recorded.',
    ], 201);
}

vk_api_require_permission($auth, 'view', 'member_payouts');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

$memberId = (int) ($_GET['member_id'] ?? 0);
$where  = $memberId > 0 ? 'WHERE p.member_id = ?' : '';
$params = $memberId > 0 ? [$memberId] : [];

$st = $pdo->prepare("SELECT COUNT(*) FROM member_payouts p {$where}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM member_payouts p {$where}");
$st->execute($params);
$filteredSum = (float) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT p.*, c.first_name, c.last_name
      FROM member_payouts p
      JOIN customers c ON c.customer_id = p.member_id
      {$where}
     ORDER BY p.payout_date DESC, p.payout_id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'payouts' => array_map('vk_api_payouts_row', $rows),
    'totals' => [
        'filtered_amount' => $filteredSum,
        'filtered_count'  => $total,
    ],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
