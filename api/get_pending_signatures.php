<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Server-side DataTable feed: documents awaiting the current user's signature (Pending tab).
// Vikundi schema: signature_documents(signatory_id, requested_by, customer_id, status, due_date).
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        exit;
    }

    $draw   = intval($_GET['draw']   ?? 1);
    $start  = intval($_GET['start']  ?? 0);
    $length = intval($_GET['length'] ?? 10);
    $userId = (int) $_SESSION['user_id'];

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM signature_documents sd
        JOIN documents d ON d.id = sd.document_id
        WHERE sd.signatory_id = ? AND sd.status = 'pending'
    ");
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT sd.id, sd.document_id, sd.status, sd.due_date,
               d.document_name,
               d.file_type AS document_type,
               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS requested_by_name,
               c.customer_name
        FROM signature_documents sd
        JOIN documents d ON d.id = sd.document_id
        LEFT JOIN users u ON u.user_id = sd.requested_by
        LEFT JOIN customers c ON c.customer_id = sd.customer_id
        WHERE sd.signatory_id = ? AND sd.status = 'pending'
        ORDER BY sd.due_date ASC
        LIMIT ?, ?
    ");
    $dataStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $dataStmt->bindValue(2, $start,  PDO::PARAM_INT);
    $dataStmt->bindValue(3, $length, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'data'            => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $e->getMessage()]);
}
