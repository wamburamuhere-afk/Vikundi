<?php
/**
 * POST /api/v1/members/{id}/reactivate
 *
 * Sets the member's status to 'active'. See api/v1/members_status_change.php
 * for the shared implementation and the permission rules.
 */

$vkTargetStatus = 'active';
$vkAuditVerb    = 'Reactivated';

require __DIR__ . '/members_status_change.php';
