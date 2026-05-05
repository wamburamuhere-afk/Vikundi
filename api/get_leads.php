<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Check permission
if (!hasPermission('view_leads')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    // DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $order_column_index = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $order_dir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';

    // Filters
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $source = isset($_GET['source']) ? $_GET['source'] : '';

    // Columns mapping
    $columns = [
        0 => 'first_name',
        1 => 'email',
        2 => 'source',
        3 => 'score',
        4 => 'status',
        5 => 'created_at'
    ];
    
    $order_column = isset($columns[$order_column_index]) ? $columns[$order_column_index] : 'created_at';

    // Base Query
    $query = "SELECT * FROM leads WHERE 1=1";
    $params = [];

    // Filters
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }

    if (!empty($source)) {
        $query .= " AND source = ?";
        $params[] = $source;
    }

    // Search
    if (!empty($search)) {
        $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Total Records
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM leads");
    $recordsTotal = $count_stmt->fetchColumn();

    // Filtered Records
    $count_filtered_sql = "SELECT COUNT(*) FROM ($query) as filtered_table"; // Simple wrap for count
    // Optimizing count for filtered:
    $count_filtered_query = "SELECT COUNT(*) FROM leads WHERE 1=1";
    if (!empty($status)) $count_filtered_query .= " AND status = '$status'"; // Caution: sanitize if direct variable, but here we used params.
    // Let's use the prepared statement approach correctly for filtered count
    $stmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $recordsFiltered = $stmt->fetchColumn();

    // Order & Limit
    $query .= " ORDER BY $order_column $order_dir LIMIT $start, $length";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format Data
    $formatted_data = [];
    foreach ($data as $row) {
        $status_badge = '<span class="badge bg-' . ($row['status'] == 'converted' ? 'success' : 'secondary') . '">' . ucfirst($row['status']) . '</span>';
        
        $actions = '<button class="btn btn-sm btn-outline-primary" onclick="viewLead('.$row['lead_id'].')"><i class="bi bi-eye"></i></button>';
        
        $formatted_data[] = [
            'first_name' => htmlspecialchars($row['first_name'] . ' ' . $row['last_name']),
            'email' => htmlspecialchars($row['email']),
            'source' => htmlspecialchars($row['source']),
            'score' => $row['score'],
            'status' => $status_badge,
            'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            'actions' => $actions
        ];
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $formatted_data
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
