<?php
/**
 * GET /api/v1/reports/vicoba — group summary, mirrors
 * app/constant/reports/vicoba_reports.php (also routed on the web as
 * `financial-ledger`, same file). Gated on `vicoba_reports` view, matching the
 * web exactly (also Member-visible today — see includes/api_reports.php's
 * header).
 *
 * `available_fund` deliberately does NOT match this web page's own inline
 * total_savings-minus-total_expenses arithmetic — it uses
 * includes/finance.php's getGroupFundBalance(), the same figure the Dashboard
 * and Financial Ledger already show, so this module doesn't introduce a third,
 * different "available fund" number. See includes/api_reports.php's
 * vk_api_reports_vicoba() for the full reasoning.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_reports.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'vicoba_reports');

vk_api_ok(vk_api_reports_vicoba($pdo));
