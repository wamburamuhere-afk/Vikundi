<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$draw = $_GET['draw'] ?? 1;
$start = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 10);
if ($length <= 0) { $length = 10; }
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
// Scope: 'general' = whole-org only, 'member' = member-charged only, else all.
$scope = $_GET['scope'] ?? '';
// Optional: restrict to one particular member.
$member_id = ctype_digit((string)($_GET['member_id'] ?? '')) ? (int) $_GET['member_id'] : 0;

try {
    // Shared filter (everything except status) — applied to the list AND the
    // stat cards, so a member view shows that member's totals.
    $scopeWhere = "";
    $params = [];
    if ($member_id > 0) {
        $scopeWhere .= " AND ge.member_id = :mid";
        $params['mid'] = $member_id;
    } elseif ($scope === 'general') {
        $scopeWhere .= " AND ge.member_id IS NULL";
    } elseif ($scope === 'member') {
        $scopeWhere .= " AND ge.member_id IS NOT NULL";
    }
    if ($date_from) {
        $scopeWhere .= " AND ge.expense_date >= :df";
        $params['df'] = $date_from;
    }
    if ($date_to) {
        $scopeWhere .= " AND ge.expense_date <= :dt";
        $params['dt'] = $date_to;
    }

    // Full WHERE for the list = shared filter + status.
    $listWhere = "WHERE 1=1" . $scopeWhere;
    $listParams = $params;
    if ($status) {
        $listWhere .= " AND ge.status = :status";
        $listParams['status'] = $status;
    }

    $recordsTotal = (int) $pdo->query("SELECT COUNT(*) FROM general_expenses")->fetchColumn();

    // Filtered count (respects every active filter) — needed for correct paging.
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM general_expenses ge $listWhere");
    $cntStmt->execute($listParams);
    $recordsFiltered = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT ge.*,
               TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name
          FROM general_expenses ge
          LEFT JOIN customers c ON ge.member_id = c.customer_id
          $listWhere
         ORDER BY ge.created_at DESC
         LIMIT $start, $length
    ");
    $stmt->execute($listParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats = total authorised expenses (approved OR paid; 'paid' is a substate
    // of approved). The user-driven list filter above still narrows by exact status.
    $statSql = function (string $extra) use ($scopeWhere) {
        return "SELECT COALESCE(SUM(ge.amount),0) FROM general_expenses ge WHERE ge.status IN ('approved','paid')$scopeWhere$extra";
    };
    $stats = $pdo->prepare($statSql(""));
    $stats->execute($params);
    $totalExpenses = (float) $stats->fetchColumn();

    $monthStmt = $pdo->prepare($statSql(" AND MONTH(ge.expense_date) = MONTH(CURRENT_DATE) AND YEAR(ge.expense_date) = YEAR(CURRENT_DATE)"));
    $monthStmt->execute($params);
    $month = (float) $monthStmt->fetchColumn();

    $yearStmt = $pdo->prepare($statSql(" AND YEAR(ge.expense_date) = YEAR(CURRENT_DATE)"));
    $yearStmt->execute($params);
    $year = (float) $yearStmt->fetchColumn();

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
        'totalExpenses' => $totalExpenses,
        'monthTotal' => $month,
        'yearTotal' => $year
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
