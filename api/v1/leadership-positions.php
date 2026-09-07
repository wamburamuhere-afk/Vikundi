<?php
/**
 * GET /api/v1/leadership-positions — the group's configured leadership
 * positions, one per line in group_settings.leadership_positions, via the
 * same vk_leadership_positions() the application form itself validates
 * against — never a client-supplied or hard-coded list.
 *
 * OVERLAPS with GET /api/v1/group-settings, which already returns this exact
 * array (same helper, same parsing). Kept as its own endpoint anyway: a
 * client building the "apply for a position" dropdown shouldn't have to fetch
 * the whole group-settings object just for one field, and this is what
 * todo.md's Module 14 plan asks for by name.
 *
 * Any signed-in user may call this — same reasoning as group-settings' own
 * gate: a member choosing from their own group's configured positions is not
 * a disclosure, and everyone eligible to apply needs this list before they
 * can even build a valid POST /api/v1/leadership-applications body.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/leadership_helpers.php';

vk_api_cors();
vk_api_require_method(['GET']);

vk_api_require_auth();

vk_api_ok(['positions' => vk_leadership_positions($pdo)]);
