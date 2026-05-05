<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canView('purchase_returns')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    // Fetch Header
    $stmt = $pdo->prepare("
        SELECT * FROM purchase_returns WHERE purchase_return_id = ?
    ");
    $stmt->execute([$id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return) {
        throw new Exception("Return not found");
    }

    // Fetch Items
    $itemStmt = $pdo->prepare("
        SELECT * FROM purchase_return_items WHERE purchase_return_id = ?
    ");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $return['items'] = $items;

    echo json_encode(['success' => true, 'data' => $return]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
