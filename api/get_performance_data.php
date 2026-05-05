<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $period = $_GET['period'] ?? 'monthly';
    $data = [];

    // Date range logic
    $endDate = date('Y-m-d');
    if ($period === 'monthly') {
        $startDate = date('Y-m-d', strtotime('-6 months'));
        $groupBy = "DATE_FORMAT(invoice_date, '%Y-%m')";
        $labelFormat = "M Y";
    } elseif ($period === 'weekly') {
        $startDate = date('Y-m-d', strtotime('-8 weeks'));
        $groupBy = "YEARWEEK(invoice_date)";
        $labelFormat = "Wk W";
    } else {
        // Annual
        $startDate = date('Y-m-d', strtotime('-5 years'));
        $groupBy = "YEAR(invoice_date)";
        $labelFormat = "Y";
    }

    // Query for Sales Performance (Invoices)
    // Adjust table/column names if Microfinance uses 'loans'/'repayments'
    $company_type = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'")->fetchColumn();

    if ($company_type == 'microfinance') {
        // Microfinance: Use Repayments
        $query = "
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as period,
                SUM(amount) as revenue,
                COUNT(*) as count
            FROM loan_repayments
            WHERE status = 'completed'
            AND payment_date BETWEEN ? AND ?
            GROUP BY period
            ORDER BY period ASC
        ";
    } else {
        // Business: Use Invoices
        $query = "
            SELECT 
                DATE_FORMAT(invoice_date, '%Y-%m') as period,
                SUM(grand_total) as revenue,
                COUNT(*) as count
            FROM invoices
            WHERE status = 'paid'
            AND invoice_date BETWEEN ? AND ?
            GROUP BY period
            ORDER BY period ASC
        ";
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute([$startDate, $endDate]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process data for chart
    $prevRevenue = 0;
    foreach ($results as $row) {
        $revenue = floatval($row['revenue']);
        $growth = $prevRevenue > 0 ? (($revenue - $prevRevenue) / $prevRevenue) * 100 : 0;
        
        $data[] = [
            'period' => date('M Y', strtotime($row['period'] . '-01')), // Format period label
            'revenue' => $revenue,
            'sales' => intval($row['count']),
            'growth' => round($growth, 1)
        ];
        $prevRevenue = $revenue;
    }

    // Fill empty months if needed (optional improvement)
    
    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log("Performance API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
