<?php
/**
 * GET /api/v1/my/transactions — the signed-in member's own receipts, by the
 * date the money arrived.
 *
 * Mirrors the member's Transactions statement on the web.
 *
 * WHY THIS IS NOT /contributions?member_id=me. The two documents answer
 * different questions and legitimately disagree:
 *
 *   /contributions  — money by the month it COVERS. A single 100,000 payment in
 *                     January shows as five covered months.
 *   this            — money by the date it ARRIVED. That payment is one January
 *                     event of 100,000.
 *
 * Year totals therefore differ between them on purpose (money received in 2026
 * may cover months in 2027). The GRAND TOTAL is what must agree, and it does
 * because both sides sum the same rows: cs_member_transactions() uses exactly
 * cs_statement_filter_sql(), the filter cs_member_schedule() sums over. A query
 * written by hand here is how the two statements would start disagreeing, and
 * the first thing anyone does with two statements is check the totals match.
 *
 * WHAT IS IN IT, therefore: only money that COUNTS — approved/confirmed, and of
 * a savings type. A pending contribution the member submitted this morning is
 * not here yet; it is on /contributions with status 'pending'. That is the
 * statement's definition, not an oversight.
 *
 * ?member_id= is honoured for leadership only. Anyone else is pinned to their
 * own record by vk_api_contrib_scope(), by overwriting the value rather than
 * validating it.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_transactions.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth  = vk_api_require_auth();
$scope = vk_api_contrib_scope($auth, (int) ($_GET['member_id'] ?? 0));

// A leader asking for nobody in particular gets their own. An Admin account has
// no member record, so it must name one.
$memberId = $scope['member_id'] > 0 ? $scope['member_id'] : $scope['own_member_id'];
if ($memberId <= 0) {
    vk_api_error(
        422,
        'member_required',
        'This account has no member record, so it has no transactions of its own. '
        . 'Pass ?member_id= to read a member\'s statement.'
    );
}

$m = $pdo->prepare(
    'SELECT customer_id, customer_name, initial_savings,
            TRIM(CONCAT_WS(" ", first_name, middle_name, last_name)) AS full_name
       FROM customers WHERE customer_id = ?'
);
$m->execute([$memberId]);
$member = $m->fetch(PDO::FETCH_ASSOC);
if (!$member) {
    vk_api_error(404, 'member_not_found', 'No member was found with that id.');
}

// -----------------------------------------------------------------------------
// The statement. Every figure from includes/contribution_standing.php.
// -----------------------------------------------------------------------------
$schedule = cs_member_schedule($pdo, $memberId);
$monthly  = (float) $schedule['monthly_amt'];

$events = cs_member_transactions($pdo, $memberId);
$grid    = cs_transaction_grid($events, $monthly, $schedule['anchor_ym']);
$summary = cs_year_summary($grid);

$settings = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$currency = (string) ($settings['currency'] ?? 'TZS');

// Undated money carried in when the member was registered. It belongs in the
// total but in no month — see vk_api_txn_received_total().
$openingBf = (float) ($member['initial_savings'] ?? 0);
if ($currency === '') {
    $currency = 'TZS';
}

// The receipts themselves, newest first — the list the member scrolls. The grid
// above is the same money bucketed by month; this is the individual events.
$receipts = [];
foreach ($events as $e) {
    $receipts[] = [
        'date'           => (string) $e['date'],
        'amount'         => (float) $e['amount'],
        'type'           => (string) ($e['type'] ?? ''),
        'receipt_number' => vk_api_txn_str($e['receipt_number'] ?? null),
        'description'    => vk_api_txn_str($e['description'] ?? null),
        'account'        => vk_api_txn_str($e['account'] ?? null),
        // Money carried in from M-Koba is an OPENING balance, not a fresh
        // payment. The app should label it so, or a member reading their first
        // row sees a payment they do not remember making.
        'is_opening'     => cs_is_opening($e['mkoba_trans_id'] ?? null, $e['account'] ?? null),
        'mkoba_trans_id' => vk_api_txn_str($e['mkoba_trans_id'] ?? null),
    ];
}
usort($receipts, static function (array $a, array $b): int {
    return strcmp($b['date'], $a['date']);
});

// The month grid, flattened newest-first the way the app renders a list. Cells
// before the member joined and cells in the future are dropped: they are grid
// padding, not statement lines.
$months = [];
foreach (($grid['years'] ?? []) as $year => $cells) {
    foreach ($cells as $monthNo => $cell) {
        if (in_array($cell['status'], ['before_join', 'future'], true) && $cell['allocated'] <= 0) {
            continue;
        }
        $months[] = [
            'month'    => sprintf('%04d-%02d', $year, $monthNo),
            'target'   => (float) $cell['target'],
            'received' => (float) $cell['allocated'],
            'status'   => (string) $cell['status'], // received | none | before_join | future
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
        'currency'             => $currency,
        'monthly_contribution' => $monthly,
        // False => the group set no monthly rule, so there are no targets and
        // nobody is behind; the member is simply saving what they can.
        'has_target'           => $monthly > 0,
    ],
    'receipts' => $receipts,
    'months'   => $months,
    'totals'   => [
        // Carried-in savings have no date, so they sit in no month and appear in
        // no receipt. Shown as a brought-forward line, exactly as the web
        // statement does, so the app can print the same three figures.
        'opening_brought_forward' => $openingBf,
        'receipts_total'          => (float) ($summary['total']['actual'] ?? 0),
        // opening + receipts. MUST equal /contributions/standing's total_saved
        // for the same member — see vk_api_txn_received_total().
        'received_total'          => vk_api_txn_received_total($openingBf, $summary),
        'receipt_count'           => count($receipts),
    ],
    'year_summary' => $summary,
    'scope' => [
        'is_leader'     => $scope['is_leader'],
        'own_member_id' => $scope['own_member_id'] > 0 ? $scope['own_member_id'] : null,
    ],
]);
