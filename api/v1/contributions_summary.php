<?php
/**
 * GET /api/v1/contributions/summary — the group's collection position.
 *
 * The leadership screen: what has come in, what is waiting for a decision, and
 * how many members are behind.
 *
 * LEADERSHIP ONLY. There is nothing here a member may scope to themselves —
 * every figure is about other people — so unlike the list this is gated rather
 * than narrowed. A member's own equivalent is /contributions/standing.
 *
 * THE GROUP TOTAL IS cs_group_savings_total(), NOT SUM(amount). The two are not
 * the same: fines are excluded, cancelled and unapproved money is excluded, and
 * contributions belonging to a deleted member are excluded. That function is
 * what the dashboard KPI and the Group Reports savings figure already use, so
 * taking it here guarantees the phone cannot disagree with either. A hand-rolled
 * SUM here would be a fourth answer to a question that already has one.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();

if (!vk_api_contrib_is_leader($auth)) {
    vk_api_error(
        403,
        'forbidden',
        'Only leadership can view the group collection summary. Use /contributions/standing for your own.'
    );
}

$settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$monthly  = (float) ($settings['monthly_contribution'] ?? 0);
$currency = (string) ($settings['currency'] ?? 'TZS');

// -----------------------------------------------------------------------------
// Totals
// -----------------------------------------------------------------------------
$groupTotal = cs_group_savings_total($pdo);

$month     = date('Y-m');
$monthFrom = $month . '-01';
$monthTo   = date('Y-m-t');

$st = $pdo->prepare("
    SELECT COALESCE(SUM(co.amount), 0) AS amount, COUNT(*) AS cnt
      FROM contributions co
      JOIN customers c ON c.customer_id = co.member_id AND c.status <> 'deleted'
     WHERE co.status IN ('confirmed','approved','')
       AND co.contribution_type <> 'fine'
       AND co.contribution_date BETWEEN ? AND ?");
$st->execute([$monthFrom, $monthTo]);
$thisMonth = $st->fetch(PDO::FETCH_ASSOC) ?: ['amount' => 0, 'cnt' => 0];

// -----------------------------------------------------------------------------
// The awaiting-action queue, split by which action it is waiting for. One query:
// the two counts always come from the same snapshot, so they cannot add up to a
// total that never existed.
// -----------------------------------------------------------------------------
$queue = $pdo->query("
    SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
      FROM contributions
     WHERE status IN ('pending','reviewed')
     GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

$awaiting = [
    'pending_review'   => ['count' => 0, 'amount' => 0.0],
    'pending_approval' => ['count' => 0, 'amount' => 0.0],
];
foreach ($queue as $q) {
    $key = $q['status'] === 'pending' ? 'pending_review' : 'pending_approval';
    $awaiting[$key] = ['count' => (int) $q['cnt'], 'amount' => (float) $q['amount']];
}

// -----------------------------------------------------------------------------
// Who is behind. cs_group_standing() does every member in one pass — calling
// cs_member_schedule() per member would be two queries each, which on a group of
// 300 is 600 round trips to draw one card.
// -----------------------------------------------------------------------------
$members  = 0;
$behind   = 0;
$ahead    = 0;
$deficit  = 0.0;

// Expected of the whole group by now is the sum of each member's OWN
// expectation, which is not members x months x monthly: members joined at
// different times, and a member who joined in June is not owed for January.
$expected = 0.0;

foreach (cs_group_standing($pdo) as $row) {
    $members++;
    $expected += (float) $row['expected'];
    if ($row['status'] === 'behind') {
        $behind++;
        $deficit += abs((float) $row['surplus_deficit']);
    } elseif ($row['status'] === 'ahead') {
        $ahead++;
    }
}

vk_api_ok([
    'currency' => $currency,
    'group'    => [
        'monthly_contribution' => $monthly,
        'has_target'           => $monthly > 0,
    ],
    'totals' => [
        'all_time'        => $groupTotal,
        'expected_to_date'=> $expected,
        'this_month'      => [
            'month'  => $month,
            'amount' => (float) $thisMonth['amount'],
            'count'  => (int) $thisMonth['cnt'],
        ],
    ],
    'awaiting_action' => $awaiting,
    'members' => [
        'total'          => $members,
        'behind'         => $behind,
        'ahead'          => $ahead,
        'total_deficit'  => $deficit,
        // Rendered as a percentage by the app. Guarded here rather than there:
        // a group with no target has expected 0, and dividing by it on the
        // client is how a dashboard ends up showing NaN%.
        'collection_rate'=> $expected > 0
            ? round(min(100, ($groupTotal / $expected) * 100), 1)
            : null,
    ],
]);
