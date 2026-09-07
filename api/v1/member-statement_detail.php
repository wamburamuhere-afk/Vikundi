<?php
/**
 * GET /api/v1/member-statement/{id}?as_of=YYYY-MM — a member's contributions
 * statement (the "NSSF layout" statement), mirrors
 * app/constant/reports/member_statement.php exactly.
 *
 * NO vk_api_require_permission() call — the web page itself has none, only
 * session-login plus an inline ownership check (see includes/api_reports.php's
 * header for why). `{id}` is honoured only for a caller who is admin or holds
 * `create` on `manage_contributions`; anyone else always gets their OWN
 * statement regardless of what id is in the URL.
 *
 * `as_of=YYYY-MM` freezes the statement as of the end of that month — omit it
 * for "as of today."
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_reports.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

$requestedId = (int) ($_GET['id'] ?? 0);
$memberId = vk_api_reports_resolve_member_id($pdo, $auth, $requestedId);
if ($memberId <= 0) {
    vk_api_error(404, 'not_found', 'No member was found for this account.');
}

$memberSt = $pdo->prepare('SELECT * FROM customers WHERE customer_id = ?');
$memberSt->execute([$memberId]);
$member = $memberSt->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    vk_api_error(404, 'not_found', 'No member was found with that id.');
}

$asOf = vk_api_reports_as_of($_GET['as_of'] ?? null);

$settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$monthlyAmt  = (float) ($settings['monthly_contribution'] ?? 0);
$entranceAmt = (float) ($settings['entrance_fee'] ?? 0);

$sched   = cs_member_schedule($pdo, $memberId, $asOf);
$grid    = cs_calendar_grid($sched, $asOf);
$summary = cs_year_summary($grid);
$expected = cs_expected_to_date($monthlyAmt, $sched['anchor_ym'], $asOf);
$standing = cs_standing($sched['opening'], $sched['new_money'], $expected);

$condolences = vk_api_reports_condolences($pdo, $memberId);

vk_api_ok([
    'as_of'  => $asOf->format('Y-m'),
    'member' => vk_api_reports_member_details($member),
    'contribution' => [
        'monthly_target'      => $monthlyAmt,
        'entrance_fee'        => $entranceAmt,
        'entrance_paid'       => $sched['entrance_paid'],
        'entrance_status'     => $sched['entrance_status'],
        'opening_mkoba'       => $sched['opening'],
        'new_contributions'   => $sched['new_money'],
        'total_contributed'   => $sched['total_paid'],
        'expected_to_date'    => $expected,
        'surplus_deficit'     => $standing['surplus_deficit'],
        'months_covered'      => $sched['total_months_covered'],
        'status'              => $standing['status'],
    ],
    'condolences' => [
        'total' => array_sum(array_column($condolences, 'amount')),
        'items' => array_map('vk_api_reports_condolence_row', $condolences),
    ],
    'calendar' => $grid,
    'summary'  => $summary,
]);
