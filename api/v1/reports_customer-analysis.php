<?php
/**
 * GET /api/v1/reports/customer-analysis — member demographics/growth
 * analytics, mirrors app/constant/reports/customer_analysis.php. Gated on
 * `vicoba_reports` view, matching the web exactly (same key that file itself
 * checks — not a separate `customer_analysis` key, despite one appearing in
 * core/permissions.php's dead page-mapping table; see
 * includes/api_reports.php's header).
 *
 * FIXES a member-counting bug found while porting this report: the web page
 * filters on the legacy string `users.user_role != 'Admin'`, which drifts
 * from the real `role_id` and miscounts at least one genuine Admin account on
 * live data. This endpoint (and the web file, fixed in the same change) use
 * `role_id NOT IN (1,2,12)` instead — the same set isAdmin() itself bypasses.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_reports.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

vk_api_ok(vk_api_reports_customer_analysis($pdo));
