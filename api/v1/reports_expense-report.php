<?php
/**
 * GET /api/v1/reports/expense-report — expense analysis & summary.
 *
 * Mirrors app/constant/reports/expense_report.php exactly: general expenses
 * combined with condolence (death) expenses — NOT petty cash, which that
 * report has never included — both restricted to status IN ('approved',
 * 'paid'). Leadership only (`vicoba_reports`), same as every other report.
 *
 * No pagination, matching the web: the report is a full-period summary, not
 * a browsable list.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

$rows = $pdo->query("
    (SELECT 'general' AS category, expense_date AS date, amount, description
       FROM general_expenses WHERE status IN ('approved','paid'))
    UNION ALL
    (SELECT 'condolences' AS category, expense_date AS date, amount, description
       FROM death_expenses WHERE status IN ('approved','paid'))
    ORDER BY date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$items = array_map(static function (array $r): array {
    return [
        'category'    => (string) $r['category'],
        'date'        => (string) $r['date'],
        'amount'      => (float) $r['amount'],
        'description' => (string) ($r['description'] ?? ''),
    ];
}, $rows);

$totalGeneral = (float) ($pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM general_expenses WHERE status IN ('approved','paid')"
)->fetchColumn());
$totalDeath = (float) ($pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM death_expenses WHERE status IN ('approved','paid')"
)->fetchColumn());
$totalOverall = $totalGeneral + $totalDeath;

$trendRows = $pdo->query("
    SELECT DATE_FORMAT(date, '%Y-%m') AS month, SUM(amount) AS total
      FROM (
          SELECT expense_date AS date, amount FROM general_expenses WHERE status IN ('approved','paid')
          UNION ALL
          SELECT expense_date AS date, amount FROM death_expenses WHERE status IN ('approved','paid')
      ) combined
     GROUP BY month
     ORDER BY month ASC
     LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$trend = array_map(static function (array $r): array {
    return [
        'month'  => (string) $r['month'],
        'label'  => date('M Y', strtotime($r['month'] . '-01')),
        'amount' => (float) $r['total'],
    ];
}, $trendRows);

vk_api_ok([
    'items' => $items,
    'totals' => [
        'general'  => $totalGeneral,
        'death'    => $totalDeath,
        'overall'  => $totalOverall,
        'records'  => count($items),
        'pct_general' => $totalOverall > 0 ? round(($totalGeneral / $totalOverall) * 100) : 0,
        'pct_death'   => $totalOverall > 0 ? round(($totalDeath / $totalOverall) * 100) : 0,
    ],
    'trend' => $trend,
]);
