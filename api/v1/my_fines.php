<?php
/**
 * GET /api/v1/my/fines — the signed-in member's own fines.
 * GET /api/v1/my/fines?view=all — every fine in the group.
 *
 * Mirrors app/bms/customer/my_fines.php, INCLUDING the ?view=all toggle.
 *
 * THAT TOGGLE IS NOT A LEAK. The group asked for it: it is the same disclosure
 * the Group Financial Ledger already makes, which shows any member every other
 * member's contributions and shortfall. Fines are an accountability mechanism
 * and the group decided they are visible to everyone. Making the API stricter
 * than the website would not protect anybody — the data is one browser tab away
 * — it would only mean the app cannot show what the group agreed to show.
 *
 * OWN FINES REMAIN THE DEFAULT. Anything other than an explicit ?view=all
 * resolves to the member's own, because this endpoint is reached from a screen
 * called "My Fines" and opening it on somebody else's debts would be a surprise
 * about other people's money.
 *
 * The group view is PAGINATED here where the web page is not: the web renders
 * one table for a group of 30, and the app must not try to hold 327 rows.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

$view = (($_GET['view'] ?? 'mine') === 'all') ? 'all' : 'mine';
$own  = vk_api_member_id((int) $auth['user_id']);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_fine_filters($_GET);

if ($view === 'mine') {
    if ($own <= 0) {
        vk_api_error(
            403,
            'no_member_record',
            'This account has no member record, so it has no fines of its own. '
            . 'Use ?view=all for the group\'s fines.'
        );
    }
    // Scoped by OVERWRITING, never by trusting a client-supplied id: there is no
    // member_id parameter here at all, so there is nothing to tamper with.
    $where[]  = 'f.customer_id = ?';
    $params[] = $own;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totals = vk_api_fine_totals($pdo, $whereSql, $params);
$total  = $totals['count'];
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("
    SELECT f.*,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
           m.title AS meeting_title
      FROM fines f
      LEFT JOIN customers c ON c.customer_id = f.customer_id
      LEFT JOIN meetings  m ON m.id = f.meeting_id
      {$whereSql}
     ORDER BY f.created_at DESC, f.fine_id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($auth, $own): array {
    $row = vk_api_fine_row($r, $own);
    $row['actions'] = vk_api_fine_actions($auth, $row['status']);
    return $row;
}, $rows);

// How many DIFFERENT members are fined — the figure the group view leads with,
// computed over the whole filtered set rather than the page.
$finedMembers = null;
if ($view === 'all') {
    $fm = $pdo->prepare("SELECT COUNT(DISTINCT f.customer_id)
                           FROM fines f
                           LEFT JOIN customers c ON c.customer_id = f.customer_id
                           {$whereSql}");
    $fm->execute($params);
    $finedMembers = (int) $fm->fetchColumn();
}

vk_api_ok([
    'fines' => $data,
    'view'  => $view,  // 'mine' | 'all' — echoed so the app can render its toggle
    'scope' => [
        'own_member_id' => $own > 0 ? $own : null,
        'is_leader'     => vk_api_fines_is_leader($auth),
    ],
    'totals' => $totals + ['fined_members' => $finedMembers],
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
