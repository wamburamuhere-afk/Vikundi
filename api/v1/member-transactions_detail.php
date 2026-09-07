<?php
/**
 * GET /api/v1/member-transactions/{id}?as_of=YYYY-MM — a member's transactions
 * statement, mirrors app/constant/reports/member_transactions.php exactly.
 *
 * Same gate (none) and ownership rule as member-statement — see that file's
 * header and includes/api_reports.php's.
 *
 * Different from member-statement: this buckets money by the month it
 * ARRIVED, not the month it covers, and merges contributions + fines +
 * condolences into one chronological ledger. Grand totals reconcile between
 * the two statements by design (both ultimately sum the same underlying rows);
 * the per-month figures legitimately differ.
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
$monthlyAmt = (float) ($settings['monthly_contribution'] ?? 0);

$sched = cs_member_schedule($pdo, $memberId, $asOf);
$receipts = cs_member_transactions($pdo, $memberId);

$openingBf = (float) ($member['initial_savings'] ?? 0);

$grid = cs_transaction_grid(
    array_map(static fn(array $r): array => ['date' => $r['date'], 'amount' => (float) $r['amount']], $receipts),
    $monthlyAmt,
    $sched['anchor_ym'],
    $asOf
);
$summary = cs_year_summary($grid);

$finesSt = $pdo->prepare("SELECT fine_id, amount, reason, created_at FROM fines WHERE customer_id = ? AND status = 'paid' ORDER BY created_at ASC");
$finesSt->execute([$memberId]);
$fines = $finesSt->fetchAll(PDO::FETCH_ASSOC);

$condolences = vk_api_reports_condolences($pdo, $memberId);

$ledger = [];
foreach ($receipts as $r) {
    $ledger[] = [
        'date'   => $r['date'],
        'type'   => 'contribution',
        'detail' => trim((string) ($r['description'] ?: ucfirst((string) $r['type']))),
        'ref'    => $r['receipt_number'] ?: $r['mkoba_trans_id'],
        'in'     => (float) $r['amount'],
        'out'    => 0.0,
    ];
}
foreach ($fines as $f) {
    $ledger[] = [
        'date'   => $f['created_at'],
        'type'   => 'fine',
        'detail' => (string) $f['reason'],
        'ref'    => 'F#' . $f['fine_id'],
        'in'     => (float) $f['amount'],
        'out'    => 0.0,
    ];
}
foreach ($condolences as $c) {
    $ledger[] = [
        'date'   => $c['expense_date'],
        'type'   => 'condolence',
        'detail' => 'For: ' . $c['deceased_name'],
        'ref'    => 'DE#' . $c['id'],
        'in'     => 0.0,
        'out'    => (float) $c['amount'],
    ];
}
usort($ledger, static fn(array $a, array $b): int => strtotime((string) $a['date']) <=> strtotime((string) $b['date']));

vk_api_ok([
    'as_of'  => $asOf->format('Y-m'),
    'member' => vk_api_reports_member_details($member),
    'opening_brought_forward' => $openingBf,
    'totals' => [
        'received'    => $summary['total']['actual'],
        'fines'       => array_sum(array_column($fines, 'amount')),
        'condolences' => array_sum(array_column($condolences, 'amount')),
    ],
    'calendar' => $grid,
    'summary'  => $summary,
    'ledger'   => $ledger,
]);
