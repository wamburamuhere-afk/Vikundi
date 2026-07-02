<?php
// api/export_contributions_statement.php — CSV (Excel) export of the date-range
// contribution statement. Same filters as the printable statement.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/contribution_statement.php';
global $pdo;

// Group-wide financial report — leadership only.
if (!isAdmin() && !canView('manage_contributions')) {
    http_response_code(403);
    exit('Not authorized.');
}

$f = vk_statement_filters($_GET);
$params = [];
$where = vk_statement_where($f, $params);

$stmt = $pdo->prepare("
    SELECT con.contribution_date, con.receipt_number, con.account, con.contribution_type, con.amount, con.status,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name, c.phone
      FROM contributions con
      LEFT JOIN customers c ON con.member_id = c.customer_id
     WHERE $where
     ORDER BY con.contribution_date DESC, con.contribution_id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fname = 'contributions_' . ($f['from'] ?: 'all') . '_' . ($f['to'] ?: 'all') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
fputcsv($out, ['Date', 'Member', 'Phone', 'Receipt', 'Account', 'Type', 'Amount', 'Status'], ',', '"', '');

$total = 0.0;
foreach ($rows as $r) {
    $total += (float) $r['amount'];
    fputcsv($out, [
        $r['contribution_date'],
        $r['member_name'] ?: '',
        $r['phone'] ?: '',
        $r['receipt_number'] ?: '',
        $r['account'] ?: '',
        ucfirst((string) $r['contribution_type']),
        number_format((float) $r['amount'], 0, '.', ''),
        $r['status'],
    ], ',', '"', '');
}
fputcsv($out, [], ',', '"', '');
fputcsv($out, ['', '', '', '', '', 'TOTAL', number_format($total, 0, '.', ''), count($rows) . ' rows'], ',', '"', '');
fclose($out);
