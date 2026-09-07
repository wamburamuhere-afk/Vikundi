<?php
/**
 * GET /api/v1/elections/{id}/results — turnout + (conditionally) the tally.
 *
 * Mirrors api/get_vote_results.php: no leadership gate at the top — ANY
 * authenticated user may call this for any election id, exactly like the web
 * endpoint it mirrors. What is actually secret is gated inside
 * vk_api_election_results(): the tally is withheld entirely while the
 * election is open, and once closed a plain member only sees it if the
 * election's own publish_results flag is set — leadership always sees it
 * after close, published or not. Turnout numbers (voted/eligible/percent) are
 * never secret.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

$election = vk_api_election_load($pdo, $id);
$isLeader = vk_api_is_admin((int) $auth['role_id']) || vk_api_can($auth, 'view', 'manage_voting');

vk_api_ok(vk_api_election_results($pdo, $election, $isLeader));
