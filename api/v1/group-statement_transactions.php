<?php
/**
 * GET /api/v1/group-statement/transactions?as_of=YYYY-MM — same as
 * group-statement/contributions, bucketed by when money arrived rather than
 * which months it covers. See group-statement_contributions.php's header for
 * the shared reasoning (gate, and the combined-vs-per-member response shape).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_reports.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

$asOf = vk_api_reports_as_of($_GET['as_of'] ?? null);

vk_api_ok(vk_api_group_statement($pdo, 'transactions', $asOf));
