<?php
/**
 * GET /api/v1/group-statement/contributions?as_of=YYYY-MM — the group as a
 * whole, plus every member's own row. Mirrors includes/group_statement.php
 * (vk_statement_type='contributions') exactly, except this always returns
 * BOTH the combined group figures and the per-member table in one response —
 * see includes/api_reports.php's vk_api_group_statement() for why the web's
 * `view=combined`/`view=members` split doesn't carry over to a JSON client.
 *
 * Gated on `vicoba_reports` view — deliberately mirroring the web's actual
 * gate, not todo.md's "leadership only" annotation. See
 * includes/api_reports.php's header for the full reasoning: the web page's
 * own comment states group-wide visibility to Member is deliberate existing
 * policy here, not an oversight.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_reports.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

$asOf = vk_api_reports_as_of($_GET['as_of'] ?? null);

vk_api_ok(vk_api_group_statement($pdo, 'contributions', $asOf));
