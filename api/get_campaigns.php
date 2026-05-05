<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!hasPermission('view_campaigns')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    $draw = $_GET['draw'] ?? 1;
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 10;
    $search = $_GET['search']['value'] ?? '';
    
    // Columns
    $columns = ['campaign_name', 'type', 'budget', 'spent', 'start_date', 'status'];
    $order_idx = $_GET['order'][0]['column'] ?? 0;
    $order_dir = $_GET['order'][0]['dir'] ?? 'asc';
    $order_col = $columns[$order_idx] ?? 'start_date';

    $query = "SELECT * FROM marketing_campaigns WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (campaign_name LIKE ? OR type LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Total
    $total = $pdo->query("SELECT COUNT(*) FROM marketing_campaigns")->fetchColumn();
    
    // Filtered
    $stmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $filtered = $stmt->fetchColumn();

    // Limit
    $query .= " ORDER BY $order_col $order_dir LIMIT $start, $length";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($data as $row) {
        $formatted[] = [
            'campaign_name' => htmlspecialchars($row['campaign_name']),
            'type' => ucfirst($row['type']),
            'budget' => number_format($row['budget'], 2),
            'spent' => number_format($row['spent'], 2),
            'start_date' => $row['start_date'],
            'status' => '<span class="badge bg-secondary">' . ucfirst($row['status']) . '</span>',
            'actions' => '<button class="btn btn-sm btn-dark"><i class="bi bi-gear"></i></button>'
        ];
    }

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered,
        'data' => $formatted
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
