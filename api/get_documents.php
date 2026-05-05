<?php
// api/get_documents.php
ob_start(); 
error_reporting(0);
ini_set('display_errors', 0);

while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'stats' => [], 'error' => 'Unauthenticated']);
        exit;
    }

    require_once __DIR__ . '/../roots.php'; // Correct path to roots

    $draw    = isset($_GET['draw'])   ? (int)$_GET['draw']   : 1;
    $start   = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
    $length  = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    $search  = isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '';

    $category_id  = !empty($_GET['category_id'])  ? (int)$_GET['category_id'] : null;
    $file_type    = !empty($_GET['file_type'])     ? trim($_GET['file_type']) : null;
    $access_level = !empty($_GET['access_level'])  ? trim($_GET['access_level']) : null;
    
    $where  = "WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (d.document_name LIKE :search OR d.original_filename LIKE :search OR d.tags LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if ($category_id) { 
        $where .= " AND d.category_id = :cat_id"; 
        $params[':cat_id'] = $category_id; 
    }
    if ($file_type) { 
        $where .= " AND d.file_type = :f_type"; 
        $params[':f_type'] = $file_type; 
    }
    if ($access_level) { 
        $where .= " AND d.access_level = :a_level"; 
        $params[':a_level'] = $access_level; 
    }

    // Count filtered records
    $countSql  = "SELECT COUNT(*) FROM documents d $where";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val);
    }
    $countStmt->execute();
    $recordsFiltered = (int)$countStmt->fetchColumn();
    
    // Count total records
    $recordsTotal = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();

    // Sorting
    $colMap  = [
        1 => 'd.document_name', 
        2 => 'c.category_name', 
        3 => 'd.file_size', 
        4 => 'd.download_count', 
        5 => 'u.first_name', 
        6 => 'd.uploaded_at', 
        7 => 'd.access_level'
    ];
    $orderColIdx = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 6;
    $orderDir    = (isset($_GET['order'][0]['dir']) && $_GET['order'][0]['dir'] === 'asc') ? 'ASC' : 'DESC';
    $sortCol     = $colMap[$orderColIdx] ?? 'd.uploaded_at';

    // Limit/Paging
    $limit_clause = ($length === -1) ? "" : "LIMIT :limit_val OFFSET :offset_val";

    $dataSql  = "SELECT d.*, c.category_name, c.color AS category_color, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
                 FROM documents d
                 LEFT JOIN document_categories c ON d.category_id = c.id
                 LEFT JOIN users u ON d.uploaded_by = u.user_id
                 $where
                 ORDER BY $sortCol $orderDir
                 $limit_clause";

    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val);
    }
    
    if ($length !== -1) {
        $dataStmt->bindValue(':limit_val', (int)$length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_val', (int)$start, PDO::PARAM_INT);
    }
    
    $dataStmt->execute();
    $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Global stats
    $totalSize       = (float)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM documents")->fetchColumn();
    $recentUploads   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $recentDownloads = (int)$pdo->query("SELECT COALESCE(SUM(download_count),0) FROM documents")->fetchColumn();

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'draw' => (int)$draw,
        'recordsTotal' => (int)$recordsTotal,
        'recordsFiltered' => (int)$recordsFiltered,
        'data' => $data,
        'stats' => [
            'totalDocuments' => (int)$recordsTotal,
            'totalSize' => $totalSize,
            'recentUploads' => (int)$recentUploads,
            'recentDownloads' => (int)$recentDownloads
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    echo json_encode(['draw'=>(int)$draw, 'recordsTotal'=>0, 'recordsFiltered'=>0, 'data'=>[], 'error'=>$e->getMessage(), 'stats' => (object)[]]);
}
