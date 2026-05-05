<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Suppress errors and clean buffer
error_reporting(0);
ini_set('display_errors', 0);
while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Unauthenticated']);
        exit;
    }

// Get parameters from DataTables
$draw = $_GET['draw'] ?? 1;
$start = $_GET['start'] ?? 0;
$length = $_GET['length'] ?? 10;
$searchValue = $_GET['search']['value'] ?? '';

// Custom filters
$category_id = $_GET['category_id'] ?? '';
$file_type = $_GET['file_type'] ?? '';
$access_level = $_GET['access_level'] ?? '';
$uploaded_by = $_GET['uploaded_by'] ?? '';


// Get order parameters
$orderColumnIndex = $_GET['order'][0]['column'] ?? 0;
$orderDirection = $_GET['order'][0]['dir'] ?? 'desc';

// Define column mapping
$columns = [
    'd.document_name',
    'c.category_name',
    'd.file_size',
    'd.download_count',
    'u.username',
    'd.uploaded_at',
    'd.access_level',
    ''
];

// Base query
$query = "SELECT d.*, 
                 u.username as uploaded_by_name,
                 c.category_name,
                 c.color as category_color
          FROM documents d
          LEFT JOIN users u ON d.uploaded_by = u.user_id
          LEFT JOIN document_categories c ON d.category_id = c.id
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) FROM documents d 
               LEFT JOIN document_categories c ON d.category_id = c.id
               WHERE 1=1";

$params = [];

// Apply custom filters
if (!empty($category_id)) {
    $query .= " AND d.category_id = :category_id";
    $countQuery .= " AND d.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

if (!empty($file_type)) {
    $query .= " AND d.file_type = :file_type";
    $countQuery .= " AND d.file_type = :file_type";
    $params[':file_type'] = $file_type;
}

if (!empty($access_level)) {
    $query .= " AND d.access_level = :access_level";
    $countQuery .= " AND d.access_level = :access_level";
    $params[':access_level'] = $access_level;
}

if (!empty($uploaded_by)) {
    $query .= " AND d.uploaded_by = :uploaded_by";
    $countQuery .= " AND d.uploaded_by = :uploaded_by";
    $params[':uploaded_by'] = $uploaded_by;
}


// Add search filter if specified
if (!empty($searchValue)) {
    $searchCond = " AND (d.document_name LIKE :search1 OR 
                    d.description LIKE :search2 OR 
                    d.tags LIKE :search3 OR
                    c.category_name LIKE :search4)";
    $query .= $searchCond;
    $countQuery .= $searchCond;
    $params[':search1'] = "%$searchValue%";
    $params[':search2'] = "%$searchValue%";
    $params[':search3'] = "%$searchValue%";
    $params[':search4'] = "%$searchValue%";
}

// Get total filtered records
$countStmt = $pdo->prepare($countQuery);
foreach ($params as $key => $value) {
    if ($key !== ':start' && $key !== ':length') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalFiltered = $countStmt->fetchColumn();
$countStmt->closeCursor();

// Add sorting
if (isset($columns[$orderColumnIndex]) && !empty($columns[$orderColumnIndex])) {
    $orderBy = $columns[$orderColumnIndex];
    $query .= " ORDER BY $orderBy $orderDirection";
} else {
    $query .= " ORDER BY d.uploaded_at DESC";
}

// Add pagination
$query .= " LIMIT ?, ?";
$params_list = [];
foreach ($params as $k => $v) $params_list[] = $v;
$params_list[] = (int)$start;
$params_list[] = (int)$length;

// Prepare and execute main query
$stmt = $pdo->prepare($query);
for ($i=0; $i<count($params_list); $i++) {
    $stmt->bindValue($i+1, $params_list[$i], is_int($params_list[$i]) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Get total records without filters
$totalRecords = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();

// Get Stats
$statsQuery = "SELECT 
                COUNT(*) as total_documents,
                SUM(file_size) as total_size,
                (SELECT COUNT(*) FROM document_categories) as categories_count,
                (SELECT COUNT(*) FROM documents WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_uploads,
                (SELECT COUNT(*) FROM document_downloads WHERE downloaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_downloads
               FROM documents";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Prepare response
    $response = [
        'draw' => (int)$draw,
        'recordsTotal' => (int)$totalRecords,
        'recordsFiltered' => (int)$totalFiltered,
        'data' => $documents,
        'stats' => [
            'totalDocuments' => (int)($stats['total_documents'] ?? 0),
            'totalSize' => (float)($stats['total_size'] ?? 0),
            'categoriesCount' => (int)($stats['categories_count'] ?? 0),
            'recentUploads' => (int)($stats['recent_uploads'] ?? 0),
            'recentDownloads' => (int)($stats['recent_downloads'] ?? 0)
        ]
    ];
    
    echo json_encode($response);
} catch (Throwable $e) {
    // Log error for debugging
    error_log("get_documents.php Error: " . $e->getMessage());
    
    // Clean buffer before sending error JSON
    if (ob_get_level()) ob_clean();
    
    // Return valid JSON error response
    echo json_encode([
        'draw' => (int)($_GET['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage(),
        'stats' => [
            'totalDocuments' => 0,
            'totalSize' => 0,
            'categoriesCount' => 0,
            'recentUploads' => 0,
            'recentDownloads' => 0
        ]
    ]);
}
