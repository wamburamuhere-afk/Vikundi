<?php
/**
 * GET /api/v1/documents — the group's Document Library, paginated.
 *
 * Mirrors app/constant/document/document_library.php + api/get_documents.php.
 * Gated on the catalog view permission — checked under BOTH `library` and
 * `document_library` (see includes/api_documents.php's header: live
 * environments carry one or the other, not consistently) — then scoped per
 * row by access_level via includes/document_access.php, the same rule
 * api/get_documents.php applies (public -> everyone, restricted -> leadership +
 * uploader, private -> uploader + admin).
 *
 * No POST here: the web has no endpoint to upload a library file's metadata
 * without the file itself, and todo.md's Module 13 plan does not ask for one —
 * this module is read (+download) + delete for the Library, and the full
 * author/edit/sign flow for the separate Document Writer
 * (see api/v1/authored-documents.php).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
if (!vk_api_doc_library_can($auth, 'view')) {
    vk_api_error(403, 'forbidden', 'You do not have permission to do that.');
}

$uid      = (int) $auth['user_id'];
$isAdmin  = vk_api_is_admin((int) $auth['role_id']);
$isLeader = $isAdmin || vk_api_doc_library_can($auth, 'edit');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$where, $params] = vk_api_doc_filters($_GET, 'd');

$visParams = [];
$visCond   = vk_document_visibility_where('d', $uid, $isAdmin, $isLeader, $visParams);
$allParams = $visParams + $params;
$whereSql  = 'WHERE ' . $visCond . ($where ? (' AND ' . implode(' AND ', $where)) : '');

$countSt = $pdo->prepare("SELECT COUNT(*) FROM documents d {$whereSql}");
foreach ($allParams as $k => $v) {
    $countSt->bindValue($k, $v);
}
$countSt->execute();
$total = (int) $countSt->fetchColumn();

$offset = ($page - 1) * $perPage;

$dataSt = $pdo->prepare("
    SELECT d.*, c.category_name, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS uploaded_by_name
      FROM documents d
      LEFT JOIN document_categories c ON c.id = d.category_id
      LEFT JOIN users u ON u.user_id = d.uploaded_by
      {$whereSql}
     ORDER BY d.uploaded_at DESC, d.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
foreach ($allParams as $k => $v) {
    $dataSt->bindValue($k, $v);
}
$dataSt->execute();
$rows = $dataSt->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'documents'  => array_map(static function (array $r) use ($auth): array {
        $row = vk_api_doc_row($r);
        $row['actions'] = vk_api_doc_actions($auth, $r);
        return $row;
    }, $rows),
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
