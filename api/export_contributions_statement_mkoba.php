<?php
// api/export_contributions_statement_mkoba.php — CSV (Excel) export of the
// date-range contribution statement in M-Koba's column layout, for reconciling
// our records against an M-Koba extract row-by-row. Same filters and gate as the
// standard statement export; the difference is only the column shape. Rows that
// came from an M-Koba upload carry the original values (mkoba_* columns) and match
// fully; hand-recorded rows fall back to our own fields and leave the columns we
// never had (SOURCE / DESTINATION / TRANS_ID) blank.
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
    SELECT con.contribution_date, con.receipt_number, con.contribution_type, con.amount,
           con.mkoba_sno, con.mkoba_trans_id, con.mkoba_receipt, con.mkoba_member_name,
           con.mkoba_member_id_str, con.mkoba_source, con.mkoba_destination, con.mkoba_trans_type,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name, c.phone
      FROM contributions con
      LEFT JOIN customers c ON con.member_id = c.customer_id
     WHERE $where
     ORDER BY con.contribution_date DESC, con.contribution_id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fname = 'contributions_mkoba_' . ($f['from'] ?: 'all') . '_' . ($f['to'] ?: 'all') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
fputcsv($out, vk_mkoba_statement_columns(), ',', '"', '');

// No grand-total footer row — an M-Koba extract has none, and a trailing summary
// row would break a clean row-by-row diff against it.
$no = 0;
foreach ($rows as $r) {
    $no++;
    fputcsv($out, array_values(vk_mkoba_statement_row($r, $no)), ',', '"', '');
}
fclose($out);
