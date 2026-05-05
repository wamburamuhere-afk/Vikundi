<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$active_only = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : true;

try {
    global $pdo;
    
    $query = "
        SELECT 
            p.product_id,
            p.product_name,
            p.sku,
            p.barcode,
            p.description,
            p.unit,
            p.selling_price,
            p.cost_price,
            p.purchase_price,
            p.tax_rate,
            p.current_stock,
            c.category_name
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.category_id
        WHERE 1=1
    ";
    
    if ($active_only) {
        $query .= " AND p.status = 'active'";
    }
    
    $params = [];
    if (!empty($search)) {
        $query .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        $searchTerm = "%$search%";
        $params = [$searchTerm, $searchTerm, $searchTerm];
    }
    
    $query .= " ORDER BY p.product_name ASC LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $products]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
