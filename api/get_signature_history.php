<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
// Server-side DataTable feed: the current user's completed signings (Signature History tab).
// Vikundi schema: signature_history(user_id, document_id, signature_id, signature_position, ip_address, signed_at).
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
        FROM signature_history sh
        JOIN documents d ON d.id = sh.document_id
        WHERE sh.user_id = ?
    ");
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $dataStmt = $pdo->prepare("
        SELECT sh.id, sh.document_id, sh.signature_position, sh.ip_address, sh.signed_at,
               d.document_name,
               d.file_type AS document_type,
               (SELECT c.customer_name
                  FROM signature_documents sd
                  JOIN customers c ON c.customer_id = sd.customer_id
                 WHERE sd.document_id = sh.document_id AND sd.signatory_id = sh.user_id
                 ORDER BY sd.signed_at DESC LIMIT 1) AS customer_name
        FROM signature_history sh
        JOIN documents d ON d.id = sh.document_id
        WHERE sh.user_id = ?
        ORDER BY sh.signed_at DESC
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
        'stats'           => ['signedDocuments' => $total],
        'data'            => $rows,
    ]);

} catch (Exception $e) {
    echo json_encode(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $e->getMessage()]);
}
