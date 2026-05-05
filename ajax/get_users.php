<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin (using your logic from roles check)
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['data' => []]);
    exit();
}

try {
    // Basic search and ordering
    $search = $_GET['search']['value'] ?? '';
    $order_col_idx = $_GET['order'][0]['column'] ?? 0;
    $order_dir = $_GET['order'][0]['dir'] ?? 'ASC';
    
    $cols = ['user_id', 'user_id', 'username', 'full_name', 'email', 'role_name', 'status', 'last_login'];
    $order_col = $cols[$order_col_idx] ?? 'user_id';

    // Unified Query: All non-terminated customers + staff (admins not in customer list)
    $query = "
        SELECT 
            'member' as source,
            c.customer_id as id,
            u.user_id, 
            u.username, 
            CONCAT_WS(' ', c.first_name, c.last_name) as full_name,
            c.email, 
            COALESCE(r.role_name, 'Member') as role_name, 
            c.status, 
            u.last_login
        FROM customers c
        LEFT JOIN users u ON c.user_id = u.user_id
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE c.status NOT IN ('terminated', 'dormant') AND (u.status IS NULL OR u.status NOT IN ('dormant', 'deleted'))
    ";

    if (!empty($search)) {
        $query .= " AND (c.first_name LIKE :search OR c.last_name LIKE :search OR c.phone LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
    }

    $query .= " UNION 
        SELECT 
            'staff' as source,
            u.user_id as id,
            u.user_id, 
            u.username, 
            CONCAT_WS(' ', u.first_name, u.last_name) as full_name,
            u.email, 
            COALESCE(r.role_name, u.user_role) as role_name, 
            u.status, 
            u.last_login
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE u.status NOT IN ('deleted', 'dormant') 
        AND u.user_id NOT IN (SELECT user_id FROM customers WHERE user_id IS NOT NULL AND status NOT IN ('terminated', 'dormant'))
    ";

    if (!empty($search)) {
        $query .= " AND (u.username LIKE :search OR u.email LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    }

    $query .= " ORDER BY $order_col $order_dir";

    // Pagination
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    if ($length != -1) {
        $query .= " LIMIT $start, $length";
    }

    $stmt = $pdo->prepare($query);
    if (!empty($search)) {
        $stmt->bindValue(':search', "%$search%");
    }
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total (exclude dormant)
    $total_records = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM customers c LEFT JOIN users u ON c.user_id = u.user_id WHERE c.status NOT IN ('terminated','dormant') AND (u.status IS NULL OR u.status NOT IN ('dormant','deleted'))) + 
            (SELECT COUNT(*) FROM users u WHERE u.status NOT IN ('deleted','dormant') AND u.user_id NOT IN (SELECT user_id FROM customers WHERE user_id IS NOT NULL AND status NOT IN ('terminated','dormant')))
    ")->fetchColumn();
    $data = [];
    $s_no = $start + 1;
    foreach ($users as $u) {
        $status_badge = '<span class="badge bg-secondary">'.ucfirst($u['status']).'</span>';
        if($u['status'] == 'active') $status_badge = '<span class="badge bg-success">Active</span>';
        if($u['status'] == 'pending') $status_badge = '<span class="badge bg-warning text-dark">Pending</span>';

        $data[] = [
            "s_no" => $s_no++,
            "user_id" => $u['user_id'] ?: '', // Important: empty if no user record
            "customer_id" => ($u['source'] === 'member') ? $u['id'] : '',
            "username" => $u['username'] ?: '-',
            "full_name" => $u['full_name'] ?: 'N/A',
            "email" => $u['email'] ?: '-',
            "role_name" => $u['role_name'] ?: 'Member',
            "status" => $status_badge,
            "last_login" => $u['last_login'] ?: 'Never',
            "source" => $u['source'],
            "is_active" => ($u['status'] == 'active' ? 1 : 0)
        ];
    }

    echo json_encode([
        "draw" => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $total_records,
        "data" => $data
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage(), "data" => []]);
}
