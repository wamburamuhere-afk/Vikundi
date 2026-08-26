<?php
/**
 * GET /api/v1/contributions/standing — "am I up to date?"
 *
 * The member-facing screen. Answers, for one member: how much have they brought
 * in, how much was expected of them by now, are they ahead or behind, and which
 * months are short.
 *
 * EVERY FIGURE COMES FROM includes/contribution_standing.php. None of this
 * arithmetic is re-done here, and that is the whole point of the module: the
 * ledger, the printed statement, the dashboard and now the phone all call the
 * same functions. The same bug used to reappear on each screen that re-derived
 * it — savings reading 0, false year-long deficits, members wrongly marked
 * dormant — because four screens each had their own version of the sum.
 *
 * A MONTHLY AMOUNT OF 0 MEANS "NO FIXED TARGET". The group may not have set one.
 * Then nothing is expected, nobody is behind, and standing is simply what the
 * member has saved. `has_target` says which world the app is rendering, so it
 * does not draw a 0% progress bar for a group that has no target.
 *
 * ?member_id= is honoured for leadership only; anyone else is pinned to their
 * own record by vk_api_contrib_scope().
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth  = vk_api_require_auth();
$scope = vk_api_contrib_scope($auth, (int) ($_GET['member_id'] ?? 0));

// A leader asking for nobody in particular gets their own standing when they
// have a member record. An Admin account has none, so it must name a member.
$memberId = $scope['member_id'] > 0 ? $scope['member_id'] : $scope['own_member_id'];
if ($memberId <= 0) {
    vk_api_error(
        422,
        'member_required',
        'This account has no member record of its own — pass ?member_id= to view a member.'
    );
}

$member = $pdo->prepare(
    'SELECT customer_id,
            TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name,
            customer_name, created_at
       FROM customers WHERE customer_id = ?'
);
$member->execute([$memberId]);
$member = $member->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    vk_api_error(404, 'member_not_found', 'No member was found with that id.');
}

$settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly  = (float) ($settings['monthly_contribution'] ?? 0);
$currency = (string) ($settings['currency'] ?? 'TZS');

$schedule = cs_member_schedule($pdo, $memberId);
$grid     = cs_calendar_grid($schedule);
$arrears  = cs_arrears_from_grid($grid);
$summary  = cs_year_summary($grid);

// Derived exactly as app/constant/reports/member_statement.php derives it, from
// the member's OWN anchor rather than a group-wide start date — otherwise a
// member who joined last year is billed for months that predate their record.
$expected = cs_expected_to_date($schedule['monthly_amt'], $schedule['anchor_ym']);
$standing = cs_standing($schedule['opening'], $schedule['new_money'], $expected);

// Flattened for the phone: the calendar grid is a year => month => cell tree
// that is convenient in a table and awkward in a list view. Only months that
// were actually DUE are returned, newest first, which is the order the member
// scrolls in.
$months = [];
foreach (($grid['years'] ?? []) as $year => $cells) {
    foreach ($cells as $m => $cell) {
        if (empty($cell['due'])) {
            continue;
        }
        $target    = (float) $cell['target'];
        $allocated = (float) $cell['allocated'];
        $months[] = [
            'month'     => sprintf('%04d-%02d', $year, $m),
            'label'     => date('M Y', mktime(0, 0, 0, (int) $m, 1, (int) $year)),
            'target'    => $target,
            'paid'      => $allocated,
            'shortfall' => max(0.0, $target - $allocated),
            'status'    => $target <= 0
                ? 'no_target'
                : ($allocated >= $target ? 'paid' : ($allocated > 0 ? 'partial' : 'unpaid')),
        ];
    }
}
usort($months, static fn(array $a, array $b): int => strcmp($b['month'], $a['month']));

$name = trim((string) ($member['full_name'] ?? '')) ?: (string) ($member['customer_name'] ?? '');

vk_api_ok([
    'member' => [
        'member_id' => (int) $member['customer_id'],
        'full_name' => $name,
        'is_self'   => (int) $member['customer_id'] === $scope['own_member_id'],
    ],
    'group' => [
        'currency'            => $currency,
        'monthly_contribution'=> $monthly,
        // The switch the whole screen hangs on. False => no targets, no arrears,
        // no progress bar; the member is simply saving what they can.
        'has_target'          => $monthly > 0,
    ],
    'entrance' => [
        'amount' => (float) $schedule['entrance_amt'],
        'paid'   => (float) $schedule['entrance_paid'],
        'status' => (string) $schedule['entrance_status'], // paid | partial | unpaid
    ],
    'standing' => [
        'opening'         => (float) $standing['opening'],
        'new'             => (float) $standing['new'],
        'total_saved'     => (float) $standing['total'],
        'expected'        => (float) $standing['expected'],
        'surplus_deficit' => (float) $standing['surplus_deficit'],
        'status'          => (string) $standing['status'], // ahead | behind | ontrack
    ],
    'arrears' => [
        'behind'       => (bool) $arrears['behind'],
        'amount'       => (float) $arrears['amount'],
        'months'       => (int) $arrears['months'],
        'oldest_month' => $arrears['oldest'],
    ],
    'months'       => $months,
    'year_summary' => $summary,
]);
