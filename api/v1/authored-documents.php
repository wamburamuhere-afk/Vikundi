<?php
/**
 * GET  /api/v1/authored-documents — the caller's visible Document Writer
 *      documents, paginated.
 * POST /api/v1/authored-documents — author a new one (delegates to
 *      authored-documents_create.php).
 *
 * SEPARATE TOP-LEVEL RESOURCE from /documents (the plain file Library) — see
 * includes/api_documents.php's header for why: roots.php's router cannot
 * express a third static segment before an id, so /documents/authored/{id}
 * could never resolve.
 *
 * NOT hard-gated on `manage_documents` view. Mirrors
 * includes/authored_document_access.php exactly: leadership (manage_documents
 * view) sees every shared document plus their own and anything they must
 * sign; an ordinary member holds no view on manage_documents at all but must
 * still be able to list the document(s) they were assigned to sign — the
 * query itself (vk_signer_documents_join + vk_authored_visibility_where)
 * carries that scoping, not a permission gate.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/authored-documents_create.php';
    exit;
}

$uid          = (int) $auth['user_id'];
$isAdmin      = vk_api_is_admin((int) $auth['role_id']);
$isLeadership = $isAdmin || vk_api_can($auth, 'view', 'manage_documents');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));

[$filterWhere, $filterParams] = vk_api_authored_filters($_GET, 'd');

[$joinSql, $joinParams]   = vk_signer_documents_join($isLeadership, $uid);
[$visSql, $visParams]     = vk_authored_visibility_where($isAdmin, $isLeadership, $uid);

$whereSql = $visSql !== '' ? $visSql : 'WHERE 1=1';
if ($filterWhere) {
    $whereSql .= ' AND ' . implode(' AND ', $filterWhere);
}

// Bind order must match SQL param order: the JOIN's placeholder appears before
// the WHERE's, and the WHERE clause's own placeholders come before the filters
// appended after it.
$allParams = array_merge($joinParams, $visParams, $filterParams);

$countSt = $pdo->prepare("SELECT COUNT(*) FROM authored_documents d {$joinSql} {$whereSql}");
$countSt->execute($allParams);
$total = (int) $countSt->fetchColumn();

$offset = ($page - 1) * $perPage;

$dataSt = $pdo->prepare("
    SELECT d.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS creator_name
      FROM authored_documents d
      {$joinSql}
      LEFT JOIN users u ON u.user_id = d.created_by
      {$whereSql}
     ORDER BY d.updated_at DESC, d.id DESC
     LIMIT {$perPage} OFFSET {$offset}");
$dataSt->execute($allParams);
$rows = $dataSt->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'documents'  => array_map(static function (array $r) use ($auth, $uid): array {
        $row = vk_api_authored_row($r);
        $isAuthor = (int) ($r['created_by'] ?? 0) === $uid;
        $row['actions'] = vk_api_authored_actions($auth, $isAuthor, (string) $r['visibility']);
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
