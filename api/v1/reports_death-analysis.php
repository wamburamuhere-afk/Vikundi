<?php
/**
 * GET /api/v1/reports/death-analysis — condolences sustainability analysis.
 *
 * Mirrors app/constant/reports/death_analysis.php exactly: for every member
 * who has received condolence assistance, their lifetime contributions
 * against what the group has paid out to them, and the net effect on the
 * fund. Leadership only (`vicoba_reports`), same as the web report.
 *
 * PAID CASES ONLY. status IN ('approved','paid') — a pending or reviewed case
 * has not moved money yet, and including it would count assistance the group
 * has not actually given as if it already had.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

$rows = $pdo->query("
    SELECT
        d.member_id,
        MAX(d.expense_date) AS latest_date,
        SUM(d.amount) AS benefit_paid,
        COUNT(d.id) AS cases_count,
        c.customer_name,
        TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS full_name,
        c.status AS member_status,
        c.is_deceased,
        (SELECT COALESCE(SUM(amount), 0) FROM contributions
          WHERE member_id = d.member_id AND status IN ('confirmed', 'approved', '')) AS total_contributed
      FROM death_expenses d
      LEFT JOIN customers c ON d.member_id = c.customer_id
     WHERE d.status IN ('approved', 'paid')
     GROUP BY d.member_id, c.customer_name, c.first_name, c.middle_name, c.last_name, c.status, c.is_deceased
     ORDER BY latest_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$recipients = array_map(static function (array $r): array {
    $paid       = (float) $r['benefit_paid'];
    $contrib    = (float) $r['total_contributed'];
    $isDeceased = (int) ($r['is_deceased'] ?? 0) === 1;
    $status     = strtolower((string) ($r['member_status'] ?? ''));

    $name = trim((string) ($r['full_name'] ?? '')) ?: (string) ($r['customer_name'] ?? '');

    return [
        'member_id'         => (int) $r['member_id'],
        'member_name'       => $name,
        'latest_date'       => (string) $r['latest_date'],
        'cases_count'       => (int) $r['cases_count'],
        'total_contributed' => $contrib,
        'benefit_paid'      => $paid,
        'variance'          => $contrib - $paid,
        // Mirrors the web's badge logic exactly: deceased outranks status,
        // which outranks the 'dormant' default.
        'member_status'     => $isDeceased ? 'deceased' : ($status === 'active' ? 'active' : 'dormant'),
    ];
}, $rows);

$totalPaid    = array_sum(array_column($recipients, 'benefit_paid'));
$totalInbound = array_sum(array_column($recipients, 'total_contributed'));

vk_api_ok([
    'summary' => [
        'total_condolences_paid' => $totalPaid,
        'total_contributed'      => $totalInbound,
        // Positive means the fund is net drained by condolence assistance;
        // negative means contributions from these members exceed what was
        // paid out to them.
        'net_fund_impact'        => $totalPaid - $totalInbound,
        'case_count'             => count($recipients),
    ],
    'recipients' => $recipients,
]);
