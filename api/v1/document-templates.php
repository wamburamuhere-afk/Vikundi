<?php
/**
 * GET /api/v1/document-templates — reusable starting points for the Document
 * Writer (authored_document_templates), as offered by the "start from
 * template" picker in app/constant/document/writer_templates.php +
 * edit_document.php's ?tpl=ID.
 *
 * NOT database/*_document_templates* / the legacy template_categories system —
 * see includes/api_documents.php's header. Gated on `manage_documents`, same
 * as the web page (a template is only useful to someone who can write
 * documents).
 *
 * Read-only: create/edit/delete of templates is not in this module's plan.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'manage_documents');

$docType = trim((string) ($_GET['doc_type'] ?? ''));
$where   = '';
$params  = [];
if ($docType !== '') {
    if (!in_array($docType, ['letter', 'contract', 'notice', 'other'], true)) {
        vk_api_error(422, 'invalid_doc_type', 'doc_type must be one of: letter, contract, notice, other.');
    }
    $where = 'WHERE doc_type = ?';
    $params[] = $docType;
}

$st = $pdo->prepare("SELECT * FROM authored_document_templates {$where} ORDER BY name ASC");
$st->execute($params);

vk_api_ok(['templates' => array_map('vk_api_template_row', $st->fetchAll(PDO::FETCH_ASSOC))]);
