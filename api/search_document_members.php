<?php
// api/search_document_members.php — Select2 AJAX search over ACTIVE members for
// the Document Writer's "generate for member" and "add signatory" pickers.
//
// Only ever returns a small page of matches (LIMIT 20), so the editor never loads
// the whole membership. Members here are `users` rows (a member is a user in this
// system), unlike the customers-based search endpoints elsewhere.
require_once __DIR__ . '/../roots.php';

global $pdo;
header('Content-Type: application/json');

requirePermissionJson('view', 'manage_documents');

$q           = trim((string) ($_GET['q'] ?? ''));
$exclude_doc = isset($_GET['exclude_doc']) && ctype_digit((string) $_GET['exclude_doc']) ? (int) $_GET['exclude_doc'] : 0;

$where  = "status = 'active'";
$params = [];

if ($q !== '') {
    $where .= " AND (first_name LIKE :q OR last_name LIKE :q OR username LIKE :q OR phone LIKE :q
                     OR TRIM(CONCAT_WS(' ', first_name, last_name)) LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($exclude_doc > 0) {
    // Used by the signatory picker: hide people already assigned to this document.
    $where .= " AND user_id NOT IN (SELECT user_id FROM document_signatories WHERE document_id = :doc)";
    $params[':doc'] = $exclude_doc;
}

$stmt = $pdo->prepare(
    "SELECT user_id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, username, phone
       FROM users
      WHERE $where
      ORDER BY first_name, last_name
      LIMIT 20"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(function ($r) {
    $label = $r['name'] !== '' ? $r['name'] : ('Member #' . $r['user_id']);
    if (!empty($r['username'])) { $label .= ' (' . $r['username'] . ')'; }
    return ['id' => (int) $r['user_id'], 'text' => $label];
}, $rows);

echo json_encode(['results' => $results]);
