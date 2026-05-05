<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../header.php';
// Simplistic Excel Export for now
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="invoices_export.xls"');
header('Cache-Control: max-age=0');

// Filter logic (replicated from get_invoices or shared helper)
$where = "1=1";
$params = [];

if (!empty($_GET['status'])) {
    $where .= " AND status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['customer'])) {
    $where .= " AND customer_id = ?";
    $params[] = $_GET['customer'];
}

$sql = "SELECT * FROM invoices WHERE $where ORDER BY invoice_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Invoice #\tDate\tCustomer\tAmount\tStatus\n";
foreach ($invoices as $inv) {
    echo "{$inv['invoice_number']}\t{$inv['invoice_date']}\t{$inv['customer_id']}\t{$inv['grand_total']}\t{$inv['status']}\n";
}
exit();
