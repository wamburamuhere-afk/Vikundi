<?php
// api/get_contribution_ledger.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? null;
$user_role_lower = strtolower($_SESSION['user_role'] ?? '');

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// 1. Fetch Group Settings
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$start_date = $settings_raw['contribution_start_date'] ?? $settings_raw['group_founded_date'] ?? date('Y-m-01');
$cycle = $settings_raw['cycle_type'] ?? 'monthly';
$monthly_val = floatval($settings_raw['monthly_contribution'] ?? 10000);
$entrance_val = floatval($settings_raw['entrance_fee'] ?? 20000);

// 2. Navigation Logic (Block of 4)
$block = intval($_GET['block'] ?? 0);
$periods_per_block = 4;
$start_offset = $block * $periods_per_block;

// 3. DataTables AJAX Parameters
$start = intval($_GET['start'] ?? 0);
$length = intval($_GET['length'] ?? 10);
$draw = intval($_GET['draw'] ?? 1);
$search = $_GET['search']['value'] ?? '';

// 4. Base Query & Totals
$where = "status = 'active'";
$params = [];

if (str_contains($user_role_lower, 'member') || str_contains($user_role_lower, 'mwanachama') || str_contains($user_role_lower, 'mjumbe')) {
    $where .= " AND user_id = ?";
    $params[] = $user_id;
}

// Global Search Filter
if (!empty($search)) {
    $where .= " AND (customer_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?)";
    $search_val = "%$search%";
    $params = array_merge($params, [$search_val, $search_val, $search_val, $search_val]);
}

// Count Total Records (Unfiltered)
$total_count = $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();

// Count Filtered Records
$stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE $where");
$stmt->execute($params);
$filtered_count = $stmt->fetchColumn();

// Fetch Paginated Members
$limit_sql = ($length == -1) ? "" : " LIMIT $start, $length";
$query = "SELECT customer_id, customer_name, first_name, last_name, phone 
          FROM customers 
          WHERE $where 
          ORDER BY first_name ASC $limit_sql";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
$sno = $start + 1;

foreach ($members as $m) {
    $row = [
        'sno' => $sno++,
        'name' => htmlspecialchars($m['customer_name'] ?: ($m['first_name'] . ' ' . $m['last_name'])),
        'phone' => $m['phone'],
        'periods' => [],
        'block_total' => 0,
        'grand_total' => 0,
        'customer_id' => $m['customer_id']
    ];
    
    // Total Confirmed Pot
    $stmt_p = $pdo->prepare("SELECT SUM(amount) FROM contributions WHERE member_id = ? AND status = 'confirmed'");
    $stmt_p->execute([$m['customer_id']]);
    $row['grand_total'] = floatval($stmt_p->fetchColumn());

    // Intelligence: Subtract Entrance first
    $pot = $row['grand_total']; 
    $pot -= min($pot, $entrance_val); 
    
    // Calculate periods
    for ($i = 0; $i < ($start_offset + $periods_per_block); $i++) {
        $amt = min($pot, $monthly_val);
        $pot -= $amt;
        
        if ($i >= $start_offset) {
            $row['periods'][] = $amt;
            $row['block_total'] += $amt;
        }
    }
    
    $data[] = $row;
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => (int)$total_count,
    "recordsFiltered" => (int)$filtered_count,
    "data" => $data
]);
