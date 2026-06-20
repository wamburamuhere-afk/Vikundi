<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $draw      = intval($_GET['draw'] ?? 1);
    $start     = intval($_GET['start'] ?? 0);
    $length    = intval($_GET['length'] ?? 10);
    $search    = $_GET['search']['value'] ?? '';
    $categoryId = $_GET['category_id'] ?? '';
    $fileType   = $_GET['file_type'] ?? '';
    $status     = $_GET['status'] ?? '';

    $where  = 'WHERE 1=1';
    $params = [];

    if ($search !== '') {
        $where  .= ' AND (t.template_name LIKE ? OR c.category_name LIKE ? OR t.description LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($categoryId !== '') {
        $where  .= ' AND t.category_id = ?';
        $params[] = $categoryId;
    }
    if ($fileType !== '') {
        $where  .= ' AND t.file_type = ?';
        $params[] = $fileType;
    }
    if ($status !== '') {
        $where  .= ' AND t.is_active = ?';
        $params[] = $status;
    }

    $base = "FROM document_templates t
             LEFT JOIN template_categories c ON t.category_id = c.id
             LEFT JOIN users u ON t.created_by = u.user_id
             $where";

    $total    = intval($pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn());

    $cntStmt  = $pdo->prepare("SELECT COUNT(*) $base");
    $cntStmt->execute($params);
    $filtered = intval($cntStmt->fetchColumn());

    $dataStmt = $pdo->prepare("SELECT t.*, c.category_name,
                                      CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                               $base ORDER BY t.created_at DESC LIMIT $length OFFSET $start");
    $dataStmt->execute($params);
    $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'totalTemplates'  => $total,
        'activeTemplates' => intval($pdo->query("SELECT COUNT(*) FROM document_templates WHERE is_active = 1")->fetchColumn()),
        'totalUsage'      => intval($pdo->query("SELECT COALESCE(SUM(usage_count),0) FROM document_templates")->fetchColumn()),
        'categoriesCount' => intval($pdo->query("SELECT COUNT(*) FROM template_categories")->fetchColumn()),
    ];

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => $data,
        'stats'           => $stats,
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'draw' => 1]);
}
